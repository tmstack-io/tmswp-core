<?php

declare(strict_types=1);

namespace TMSWP\Core\PHPStan;

use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use TMSWP\Core\Model\Model;

/**
 * `Model::find()` の戻り値型を、呼び出し先の Model クラスの `rowClass()` から絞り込む(`Row|null`)。
 */
final class ModelFindReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    public function __construct(
        private readonly ModelRowTypeResolver $resolver,
    ) {}

    public function getClass(): string
    {
        return Model::class;
    }

    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'find';
    }

    public function getTypeFromStaticMethodCall(MethodReflection $methodReflection, StaticCall $methodCall, Scope $scope): ?Type
    {
        $model = $this->resolver->modelClassOf($methodCall, $scope);
        $rowType = $model === null ? null : $this->resolver->rowTypeOfModel($model, $scope);

        return $rowType === null ? null : TypeCombinator::addNull($rowType);
    }
}
