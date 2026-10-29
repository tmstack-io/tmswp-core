<?php

declare(strict_types=1);

namespace TMSWP\Core\PHPStan;

use LogicException;
use PHPStan\Reflection\AttributeReflection;
use PHPStan\Reflection\ClassConstantReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Type;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Attribute\OptionSchema;

/**
 * `#[MetaSchema]` / `#[OptionSchema]` の宣言を、PHPStan の ReflectionProvider だけで読み取る。
 *
 * 解析中に利用者のコードを実行しないため、クラスの読み込み(class_exists 等)、ネイティブの Reflection、
 * 属性のインスタンス化は使わない。属性の引数は AttributeReflection の型として受け取り、
 * 省略された引数は属性クラスのコンストラクタの既定値の型で補う。
 */
final class MetaSchemaReflector
{
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    /**
     * @return array{type: string, hasDefault: bool, single: bool}|null 属性が無い・引数が定数でない場合は null
     */
    public function metaSchemaOf(string $className, string $constantName): ?array
    {
        $constant = $this->metaConstant($className, $constantName);

        if ($constant === null) {
            return null;
        }

        $arguments = $this->schemaArguments($constant->getAttributes(), MetaSchema::class);

        if ($arguments === null) {
            return null;
        }

        $type = $this->valueTypeOf($arguments['type']);
        $single = $this->constantBoolOf($arguments['single']);

        if ($type === null || $single === null) {
            return null;
        }

        return [
            'type' => $type,
            'hasDefault' => $arguments['default']->isNull()->no(),
            'single' => $single,
        ];
    }

    /**
     * クラス自身が宣言した `#[MetaSchema]` 付き定数のうち、値が $key のものの定数名を返す。
     */
    public function metaConstantNameForKey(string $className, string $key): ?string
    {
        if (!$this->reflectionProvider->hasClass($className)) {
            return null;
        }

        $classReflection = $this->reflectionProvider->getClass($className);

        // BetterReflection のアダプタから定数名だけを取り出す(静的に解析した結果であり、クラスは読み込まれない)
        foreach ($classReflection->getNativeReflection()->getReflectionConstants() as $nativeConstant) {
            $constant = $classReflection->getConstant($nativeConstant->getName());

            if ($constant->getDeclaringClass()->getName() !== $classReflection->getName()) {
                continue;
            }

            if ($this->findAttribute($constant->getAttributes(), MetaSchema::class) === null) {
                continue;
            }

            $values = $constant->getValueType()->getConstantStrings();

            if (\count($values) === 1 && $values[0]->getValue() === $key) {
                return $constant->getName();
            }
        }

        return null;
    }

    /**
     * オプショングループ enum のケースの `#[OptionSchema]` を参照する。
     *
     * @return array{type: string, hasDefault: bool}|null
     */
    public function optionSchemaOf(string $enumClass, string $caseName): ?array
    {
        if (!$this->reflectionProvider->hasClass($enumClass)) {
            return null;
        }

        $enumReflection = $this->reflectionProvider->getClass($enumClass);

        if (!$enumReflection->isBackedEnum() || !$enumReflection->hasEnumCase($caseName)) {
            return null;
        }

        $arguments = $this->schemaArguments($enumReflection->getEnumCase($caseName)->getAttributes(), OptionSchema::class);

        if ($arguments === null) {
            return null;
        }

        $type = $this->valueTypeOf($arguments['type']);

        if ($type === null) {
            return null;
        }

        return [
            'type' => $type,
            'hasDefault' => $arguments['default']->isNull()->no(),
        ];
    }

    /**
     * `#[MetaSchema]` 付き定数を実際に宣言したクラスの名前を返す。属性の無い定数は null。
     */
    public function declaringClassOfMetaConstant(string $className, string $constantName): ?string
    {
        $constant = $this->metaConstant($className, $constantName);

        if ($constant === null || $this->findAttribute($constant->getAttributes(), MetaSchema::class) === null) {
            return null;
        }

        return $constant->getDeclaringClass()->getName();
    }

    private function metaConstant(string $className, string $constantName): ?ClassConstantReflection
    {
        if (!$this->reflectionProvider->hasClass($className)) {
            return null;
        }

        $classReflection = $this->reflectionProvider->getClass($className);

        if (!$classReflection->hasConstant($constantName)) {
            return null;
        }

        return $classReflection->getConstant($constantName);
    }

    /**
     * 属性の引数の型を、引数名をキーにして返す。省略された引数は属性クラスのコンストラクタの既定値の型で補う。
     *
     * @param list<AttributeReflection> $attributes
     * @param class-string $attributeClass
     *
     * @return array<string, Type>|null 属性が無ければ null
     */
    private function schemaArguments(array $attributes, string $attributeClass): ?array
    {
        $attribute = $this->findAttribute($attributes, $attributeClass);

        if ($attribute === null) {
            return null;
        }

        $arguments = $attribute->getArgumentTypes();
        $constructor = $this->reflectionProvider->getClass($attributeClass)->getConstructor();

        foreach ($constructor->getOnlyVariant()->getParameters() as $parameter) {
            if (\array_key_exists($parameter->getName(), $arguments)) {
                continue;
            }

            $default = $parameter->getDefaultValue();

            if ($default === null) {
                throw new LogicException("{$attributeClass}::__construct() parameter \${$parameter->getName()} has no default value");
            }

            $arguments[$parameter->getName()] = $default;
        }

        return $arguments;
    }

    /**
     * @param list<AttributeReflection> $attributes
     * @param class-string $attributeClass
     */
    private function findAttribute(array $attributes, string $attributeClass): ?AttributeReflection
    {
        foreach ($attributes as $attribute) {
            if ($attribute->getName() === $attributeClass) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * `MetaValueType::Integer` のような enum ケースの型から backing value を取り出す。1 つに定まらなければ null。
     */
    private function valueTypeOf(?Type $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $cases = $type->getEnumCases();

        if (\count($cases) !== 1) {
            return null;
        }

        $values = $cases[0]->getBackingValueType()?->getConstantStrings() ?? [];

        return \count($values) === 1 ? $values[0]->getValue() : null;
    }

    private function constantBoolOf(?Type $type): ?bool
    {
        if ($type === null) {
            return null;
        }

        if ($type->isTrue()->yes()) {
            return true;
        }

        if ($type->isFalse()->yes()) {
            return false;
        }

        return null;
    }
}
