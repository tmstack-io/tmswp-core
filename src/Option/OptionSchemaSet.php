<?php

declare(strict_types=1);

namespace TMSWP\Core\Option;

final readonly class OptionSchemaSet
{
    /** @var array<string, OptionSchemaDefinition> */
    private array $definitions;

    /** @param array<string, OptionSchemaDefinition> $definitions */
    public function __construct(array $definitions)
    {
        $this->definitions = $definitions;
    }

    /** @param list<OptionSchemaDefinition> $definitions */
    public static function fromList(array $definitions): self
    {
        $map = [];

        foreach ($definitions as $definition) {
            $map[$definition->key] = $definition;
        }

        return new self($map);
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->definitions);
    }

    public function get(string $key): ?OptionSchemaDefinition
    {
        if (!\array_key_exists($key, $this->definitions)) {
            return null;
        }

        return $this->definitions[$key];
    }

    /** @return array<string, OptionSchemaDefinition> */
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
