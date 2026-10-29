<?php

declare(strict_types=1);

namespace TMSWP\Core\PHPStan;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StaticType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use stdClass;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\QueryBuilder;

/**
 * Model と QueryBuilder の行の型(ハイドレーション先)を、`as()` の引数と Model の `rowClass()` の宣言型から求める。
 *
 * QueryBuilder は可変で、`as()` も自身を書き換えて返す。ほかの変数が参照しうるビルダは、チェーンの途中のコールバック等から
 * `as()` で書き換えられるため、行の型を式から追えない。そこで行の型は、新しく作られたビルダ(`Model::query()` か
 * `new QueryBuilder(...)`)を起点とする 1 つのメソッドチェーンの中でだけ求める。起点が変数・プロパティ・引数・
 * ほかのメソッドの戻り値なら求めない(null。呼び出し側は宣言型のままにする)。
 */
final class ModelRowTypeResolver
{
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    /**
     * ビルダの式(終端メソッドのレシーバ)が返す行の型。求められなければ null。
     */
    public function rowTypeOfBuilder(Expr $builder, Scope $scope): ?Type
    {
        $queryBuilder = new ObjectType(QueryBuilder::class);
        $asArgument = null;

        // チェーンを終端の側から起点へたどり、終端にいちばん近い as() の引数を覚える。
        // QueryBuilder のメソッドのうち QueryBuilder を返すものは、どれも自身を返す(QueryBuilder は final)
        while ($builder instanceof MethodCall) {
            if (!$builder->name instanceof Identifier || !$queryBuilder->isSuperTypeOf($scope->getType($builder->var))->yes() || !$queryBuilder->isSuperTypeOf($scope->getType($builder))->yes()) {
                return null;
            }

            if ($asArgument === null && $builder->name->toLowerString() === 'as') {
                $args = $builder->getArgs();

                if (!\array_key_exists(0, $args)) {
                    return null;
                }

                $asArgument = $args[0]->value;
            }

            $builder = $builder->var;
        }

        if ($builder instanceof New_) {
            if (!$builder->class instanceof Name || $scope->resolveName($builder->class) !== QueryBuilder::class) {
                return null;
            }

            return $asArgument === null ? null : $this->objectTypeOfClassString($scope->getType($asArgument));
        }

        if (!$builder instanceof StaticCall || !$builder->name instanceof Identifier || $builder->name->toLowerString() !== 'query') {
            return null;
        }

        if ($asArgument !== null) {
            return $this->isModelClass($builder, $scope) ? $this->objectTypeOfClassString($scope->getType($asArgument)) : null;
        }

        $model = $this->modelClassOf($builder, $scope);

        return $model === null ? null : $this->rowTypeOfModel($model, $scope);
    }

    /**
     * Model の静的呼び出し(`InquiryModel::find()` 等)の呼び出し先の Model クラス。定まらなければ null。
     *
     * `static::` と `self::`(静的束縛を引き継ぐ)は、クラスが final でなければサブクラスが rowClass() を上書きしうるため
     * 定まらないとする。
     */
    public function modelClassOf(StaticCall $call, Scope $scope): ?ClassReflection
    {
        if (!$this->isModelClass($call, $scope) || !$call->class instanceof Name) {
            return null;
        }

        $type = $scope->resolveTypeByName($call->class);
        $class = $type->getClassReflection();

        if ($class === null || ($type instanceof StaticType && !$class->isFinal())) {
            return null;
        }

        return $class;
    }

    /** 静的呼び出しの呼び出し先が Model のサブクラスか(`static::` 等で具体的なクラスが定まらなくてもよい)。 */
    private function isModelClass(StaticCall $call, Scope $scope): bool
    {
        if (!$call->class instanceof Name) {
            return false;
        }

        $class = $scope->resolveTypeByName($call->class)->getClassReflection();

        return $class !== null && $class->isSubclassOfClass($this->reflectionProvider->getClass(Model::class));
    }

    /**
     * Model の行の型。rowClass() を上書きしていなければ stdClass。上書きしていれば、その宣言型が
     * `class-string<Row>` なら Row(null を含めば Row|stdClass)。宣言型から行のクラスが定まらなければ null。
     */
    public function rowTypeOfModel(ClassReflection $model, Scope $scope): ?Type
    {
        $method = $model->getMethod('rowClass', $scope);

        if ($method->getDeclaringClass()->getName() === Model::class) {
            return new ObjectType(stdClass::class);
        }

        $returnType = ParametersAcceptorSelector::combineAcceptors($method->getVariants())->getReturnType();

        if ($returnType->isNull()->yes()) {
            return new ObjectType(stdClass::class);
        }

        $rowType = $this->objectTypeOfClassString(TypeCombinator::removeNull($returnType));

        if ($rowType === null) {
            return null;
        }

        return TypeCombinator::containsNull($returnType) ? TypeCombinator::union($rowType, new ObjectType(stdClass::class)) : $rowType;
    }

    private function objectTypeOfClassString(Type $type): ?Type
    {
        if (!$type->isClassString()->yes()) {
            return null;
        }

        $objectType = $type->getClassStringObjectType();

        // class-string<object>(クラスの定まらない class-string)からは絞り込めない
        return $objectType->getObjectClassNames() === [] ? null : $objectType;
    }
}
