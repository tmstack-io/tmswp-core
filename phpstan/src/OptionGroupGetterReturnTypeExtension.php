<?php

declare(strict_types=1);

namespace TMSWP\Core\PHPStan;

use BackedEnum;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use TMSWP\Core\Option\OptionAccess;

/**
 * オプショングループ enum の `get()`(OptionAccess)の戻り値型を、レシーバの enum ケースの `#[OptionSchema]` から絞り込む。
 *
 * PHPStan は trait を拡張の対象クラスとして引けないため、OptionAccess を use できる string backed enum の
 * 共通の interface(BackedEnum)を対象にし、呼ばれる get() の実体が OptionAccess で宣言されたものである場合だけを扱う。
 */
final class OptionGroupGetterReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    public function __construct(
        private readonly MetaSchemaReflector $reflector,
    ) {}

    public function getClass(): string
    {
        return BackedEnum::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        if ($methodReflection->getName() !== 'get') {
            return false;
        }

        // enum 自身が定義した get() は trait の get() より優先されるため、実体が OptionAccess の get() である場合だけ扱う。
        // BetterReflection(静的な解析結果)の宣言元は、trait から取り込んだメソッドでは trait 自身を指す。
        $nativeClass = $methodReflection->getDeclaringClass()->getNativeReflection();

        if (!$nativeClass->hasMethod('get')) {
            return false;
        }

        return $nativeClass->getMethod('get')->getBetterReflection()->getDeclaringClass()->getName() === OptionAccess::class;
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        // レシーバの式の型が 1 つの enum ケースに定まる場合だけ絞り込む(変数・引数・直接のケース参照を問わない)
        $receiverType = $scope->getType($methodCall->var);
        $cases = $receiverType->getEnumCases();

        if (\count($cases) !== 1 || !$cases[0]->isSuperTypeOf($receiverType)->yes()) {
            return null;
        }

        $schema = $this->reflector->optionSchemaOf($cases[0]->getClassName(), $cases[0]->getEnumCaseName());

        if ($schema === null) {
            return null;
        }

        $type = SchemaTypeMapper::typeFor($schema['type']);

        if (\array_key_exists(0, $methodCall->getArgs())) {
            return TypeCombinator::union($type, $scope->getType($methodCall->getArgs()[0]->value));
        }

        if ($schema['hasDefault']) {
            return $type;
        }

        return TypeCombinator::addNull($type);
    }
}
