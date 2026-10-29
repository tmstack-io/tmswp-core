<?php

declare(strict_types=1);

namespace TMSWP\Core\PHPStan;

use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use TMSWP\Core\Meta\MetaBag;

final class MetaBagGetterReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    public function __construct(
        private readonly MetaSchemaReflector $reflector,
    ) {}

    public function getClass(): string
    {
        return MetaBag::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'get';
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        if (!\array_key_exists(0, $methodCall->getArgs())) {
            return null;
        }

        $keyArg = $methodCall->getArgs()[0]->value;

        if (!$keyArg instanceof ClassConstFetch || !$keyArg->class instanceof Name || !$keyArg->name instanceof Identifier) {
            return null;
        }

        $schema = $this->reflector->metaSchemaOf($scope->resolveName($keyArg->class), $keyArg->name->toString());

        if ($schema === null) {
            return null;
        }

        $type = SchemaTypeMapper::typeFor($schema['type']);

        if (!$schema['single']) {
            $type = TypeCombinator::intersect(new ArrayType(IntegerRangeType::createAllGreaterThanOrEqualTo(0), $type), new AccessoryArrayListType());
        }

        if (\array_key_exists(1, $methodCall->getArgs())) {
            return TypeCombinator::union($type, $scope->getType($methodCall->getArgs()[1]->value));
        }

        if ($schema['hasDefault']) {
            return $type;
        }

        return TypeCombinator::addNull($type);
    }
}
