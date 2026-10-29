<?php

declare(strict_types=1);

namespace TMSWP\Core\Option;

use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Exception\InvalidSchemaException;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Meta\ValueCaster;

final readonly class OptionSchemaDefinition
{
    /**
     * @param class-string $ownerClass
     */
    public function __construct(
        public string $ownerClass,
        public string $caseName,
        public string $key,
        public MetaValueType $type,
        public mixed $default,
        public bool $autoload,
    ) {}

    /**
     * @param class-string $ownerClass
     */
    public static function fromAttribute(
        string $ownerClass,
        string $caseName,
        string $key,
        OptionSchema $attribute,
    ): self {
        if ($key === '') {
            throw new InvalidSchemaException("option key cannot be empty: {$ownerClass}::{$caseName}");
        }

        if ($attribute->default !== null && !ValueCaster::accepts($attribute->type, $attribute->default)) {
            throw new InvalidSchemaException("option default type mismatch: {$ownerClass}::{$caseName}");
        }

        return new self($ownerClass, $caseName, $key, $attribute->type, $attribute->default, $attribute->autoload);
    }

    public function hasDefault(): bool
    {
        return $this->default !== null;
    }
}
