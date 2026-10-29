<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

use TMSWP\Core\Exception\DuplicateMetaKeyException;

final readonly class MetaSchemaSet
{
    /** @var array<string, MetaSchemaDefinition> */
    private array $definitions;

    /** @param array<string, MetaSchemaDefinition> $definitions */
    public function __construct(array $definitions)
    {
        foreach ($definitions as $key => $definition) {
            if ($key !== $definition->key) {
                throw new DuplicateMetaKeyException("meta schema key '{$key}' does not match definition key '{$definition->key}'");
            }
        }

        $this->definitions = $definitions;
    }

    /** @param list<MetaSchemaDefinition> $definitions */
    public static function fromList(array $definitions): self
    {
        $map = [];

        foreach ($definitions as $definition) {
            if (\array_key_exists($definition->key, $map)) {
                throw new DuplicateMetaKeyException("duplicate meta key '{$definition->key}' in {$definition->ownerClass}");
            }

            $map[$definition->key] = $definition;
        }

        return new self($map);
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->definitions);
    }

    public function get(string $key): ?MetaSchemaDefinition
    {
        if (!\array_key_exists($key, $this->definitions)) {
            return null;
        }

        return $this->definitions[$key];
    }

    /** @return array<string, MetaSchemaDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->definitions);
    }

    public static function empty(): self
    {
        return new self([]);
    }
}
