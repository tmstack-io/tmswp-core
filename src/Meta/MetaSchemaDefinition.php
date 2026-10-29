<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Exception\InvalidSchemaException;

final readonly class MetaSchemaDefinition
{
    /**
     * @param class-string $ownerClass
     * @param bool|array<string, mixed> $showInRest
     * @param string|array{class-string, string}|null $sanitize
     */
    public function __construct(
        public string $ownerClass,
        public string $constantName,
        public string $key,
        public MetaValueType $type,
        public mixed $default,
        public bool $single,
        public bool|array $showInRest,
        public string $description,
        public string|array|null $sanitize,
    ) {}

    /**
     * @param class-string $ownerClass
     */
    public static function fromAttribute(
        string $ownerClass,
        string $constantName,
        string $key,
        MetaSchema $attribute,
    ): self {
        if ($key === '') {
            throw new InvalidSchemaException("meta key cannot be empty: {$ownerClass}::{$constantName}");
        }

        if (!$attribute->single && $attribute->default !== null) {
            throw new InvalidSchemaException("single=false meta cannot declare default: {$ownerClass}::{$constantName}");
        }

        if ($attribute->default !== null && !ValueCaster::accepts($attribute->type, $attribute->default)) {
            throw new InvalidSchemaException("meta default type mismatch: {$ownerClass}::{$constantName}");
        }

        self::assertSanitizeShape($attribute->sanitize, $ownerClass, $constantName);
        self::assertRestSchema($attribute, $ownerClass, $constantName);

        return new self(
            $ownerClass,
            $constantName,
            $key,
            $attribute->type,
            $attribute->default,
            $attribute->single,
            $attribute->showInRest,
            $attribute->description,
            $attribute->sanitize,
        );
    }

    public function hasDefault(): bool
    {
        return $this->default !== null;
    }

    /**
     * REST に出す array・object 型には、要素・プロパティのスキーマを求める。
     * WP の register_meta() は array 型で show_in_rest.schema.items が無いと登録を拒否し、
     * object 型でプロパティのスキーマが無いと REST の値が常に空のオブジェクトになるため、宣言の誤りとして扱う。
     */
    private static function assertRestSchema(MetaSchema $attribute, string $ownerClass, string $constantName): void
    {
        $requiredKeys = match ($attribute->type) {
            MetaValueType::Array => ['items'],
            MetaValueType::Object => ['properties', 'patternProperties', 'additionalProperties'],
            default => [],
        };

        if ($requiredKeys === [] || $attribute->showInRest === false) {
            return;
        }

        $schema = \is_array($attribute->showInRest) ? ($attribute->showInRest['schema'] ?? null) : null;

        foreach ($requiredKeys as $requiredKey) {
            if (\is_array($schema) && isset($schema[$requiredKey])) {
                return;
            }
        }

        $expected = implode(' or ', $requiredKeys);

        throw new InvalidSchemaException("{$attribute->type->value} meta shown in REST requires showInRest['schema'] with {$expected}: {$ownerClass}::{$constantName}");
    }

    private static function assertSanitizeShape(mixed $sanitize, string $ownerClass, string $constantName): void
    {
        self::normalizeSanitize($sanitize, $ownerClass, $constantName);
    }

    /** @return string|array{class-string, string}|null */
    private static function normalizeSanitize(mixed $sanitize, string $ownerClass, string $constantName): string|array|null
    {
        if ($sanitize === null) {
            return null;
        }

        if (\is_string($sanitize) && $sanitize !== '') {
            return $sanitize;
        }

        if (
            \is_array($sanitize)
            && \array_key_exists(0, $sanitize)
            && \array_key_exists(1, $sanitize)
            && \count($sanitize) === 2
            && \is_string($sanitize[0])
            && \is_string($sanitize[1])
            && $sanitize[0] !== ''
            && $sanitize[1] !== ''
        ) {
            if (!class_exists($sanitize[0])) {
                throw new InvalidSchemaException("sanitize callback class does not exist: {$ownerClass}::{$constantName}");
            }

            return [$sanitize[0], $sanitize[1]];
        }

        throw new InvalidSchemaException("invalid sanitize callback declaration: {$ownerClass}::{$constantName}");
    }
}
