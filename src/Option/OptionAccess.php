<?php

declare(strict_types=1);

namespace TMSWP\Core\Option;

use BackedEnum;
use TMSWP\Core\Exception\InvalidSchemaException;
use TMSWP\Core\Kernel\Kernel;

/**
 * @phpstan-require-implements \BackedEnum
 */
trait OptionAccess
{
    final public static function repository(): OptionRepository
    {
        $factory = Kernel::forClass(static::class)->container()->get(OptionRepositoryFactory::class);

        return $factory->for(static::class);
    }

    final public function get(mixed $default = null): mixed
    {
        return static::repository()->get($this->optionKey(), $default);
    }

    final public function int(?int $default = null): int
    {
        return static::repository()->int($this->optionKey(), $default);
    }

    final public function float(?float $default = null): float
    {
        return static::repository()->float($this->optionKey(), $default);
    }

    final public function bool(?bool $default = null): bool
    {
        return static::repository()->bool($this->optionKey(), $default);
    }

    final public function string(?string $default = null): string
    {
        return static::repository()->string($this->optionKey(), $default);
    }

    /**
     * @param list<mixed>|null $default
     *
     * @return list<mixed>
     */
    final public function list(?array $default = null): array
    {
        return static::repository()->list($this->optionKey(), $default);
    }

    /**
     * @param array<array-key, mixed>|null $default
     *
     * @return array<array-key, mixed>
     */
    final public function json(?array $default = null): array
    {
        return static::repository()->json($this->optionKey(), $default);
    }

    final public function has(): bool
    {
        return static::repository()->has($this->optionKey());
    }

    final public function name(): string
    {
        return static::repository()->name($this->optionKey());
    }

    final public function set(mixed $value): bool
    {
        return static::repository()->set($this->optionKey(), $value);
    }

    final public function delete(): bool
    {
        return static::repository()->delete($this->optionKey());
    }

    /** @return array<string, mixed> */
    final public static function all(): array
    {
        return static::repository()->all();
    }

    /** @return list<string> */
    final public static function deleteAll(): array
    {
        return static::repository()->deleteAll();
    }

    private function optionKey(): string
    {
        if (!$this instanceof BackedEnum || !\is_string($this->value)) {
            throw new InvalidSchemaException('OptionAccess requires a string backed enum: ' . static::class);
        }

        return $this->value;
    }
}
