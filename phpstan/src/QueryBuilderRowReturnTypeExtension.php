<?php

declare(strict_types=1);

namespace TMSWP\Core\PHPStan;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use TMSWP\Core\Model\QueryBuilder;

/**
 * QueryBuilder の `get()` / `first()` の戻り値型を、同じメソッドチェーンの `as()` の引数か、チェーンの起点の
 * `Model::query()` の `rowClass()` から絞り込む(`list<Row>` / `Row|null`)。
 */
final class QueryBuilderRowReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    public function __construct(
        private readonly ModelRowTypeResolver $resolver,
    ) {}

    public function getClass(): string
    {
        return QueryBuilder::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return \in_array($methodReflection->getName(), ['get', 'first'], true);
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        $rowType = $this->resolver->rowTypeOfBuilder($methodCall->var, $scope);

        if ($rowType === null) {
            return null;
        }

        if ($methodReflection->getName() === 'first') {
            return TypeCombinator::addNull($rowType);
        }

        return TypeCombinator::intersect(new ArrayType(IntegerRangeType::createAllGreaterThanOrEqualTo(0), $rowType), new AccessoryArrayListType());
    }
}
