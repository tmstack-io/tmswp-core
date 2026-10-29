<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

use Closure;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\LogicException;

/**
 * @template TEntity of object
 */
final class MetaBag
{
    /**
     * @param class-string<TEntity> $entityClass
     * @param Closure(): bool $registeredWithWordPress スキーマを WordPress に登録済み(MetaSchemaRegistry::registerAll()
     *                                                 の後)なら true を返す。sanitize を宣言したキーの書き込みの可否に使う
     */
    public function __construct(
        private readonly string $entityClass,
        private readonly ObjectType $objectType,
        private readonly int $objectId,
        private readonly MetaStorageInterface $storage,
        private readonly MetaSchemaSet $schemas,
        private readonly Closure $registeredWithWordPress,
    ) {}

    /** @return class-string<TEntity> */
    public function entityClass(): string
    {
        return $this->entityClass;
    }

    public function objectType(): ObjectType
    {
        return $this->objectType;
    }

    public function objectId(): int
    {
        return $this->objectId;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $schema = $this->schemas->get($key);

        if ($schema instanceof MetaSchemaDefinition && !$schema->single) {
            if ($this->storage->has($this->objectType, $this->objectId, $key)) {
                return $this->storage->getAll($this->objectType, $this->objectId, $key);
            }

            return $this->defaultValue($schema, $default);
        }

        return $this->singleValue($key, $schema, $default);
    }

    public function int(string $key, ?int $default = null): int
    {
        return ValueCaster::toInt($this->singleValue($key, $this->schemas->get($key), $default));
    }

    public function float(string $key, ?float $default = null): float
    {
        return ValueCaster::toFloat($this->singleValue($key, $this->schemas->get($key), $default));
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        return ValueCaster::toBool($this->singleValue($key, $this->schemas->get($key), $default));
    }

    public function string(string $key, ?string $default = null): string
    {
        return ValueCaster::toString($this->singleValue($key, $this->schemas->get($key), $default));
    }

    /**
     * @param list<mixed>|null $default
     *
     * @return list<mixed>
     */
    public function list(string $key, ?array $default = null): array
    {
        $schema = $this->schemas->get($key);

        if ($schema instanceof MetaSchemaDefinition && !$schema->single) {
            if ($this->storage->has($this->objectType, $this->objectId, $key)) {
                return $this->storage->getAll($this->objectType, $this->objectId, $key);
            }

            if ($default !== null) {
                return array_values($default);
            }

            return [];
        }

        return ValueCaster::toList($this->singleValue($key, $schema, $default));
    }

    /**
     * @param array<array-key, mixed>|null $default
     *
     * @return array<array-key, mixed>
     */
    public function json(string $key, ?array $default = null): array
    {
        return ValueCaster::toJsonArray($this->singleValue($key, $this->schemas->get($key), $default));
    }

    public function has(string $key): bool
    {
        return $this->storage->has($this->objectType, $this->objectId, $key);
    }

    /**
     * 宣言済みの全キーの値を返す。各キーの値は get() と同じ(single=false のキーは全行の list)。
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $singleKeys = array_keys(array_filter($this->schemas->all(), static fn(MetaSchemaDefinition $schema): bool => $schema->single));
        $values = $this->storage->getMultiple($this->objectType, $this->objectId, $singleKeys);
        $all = [];

        foreach ($this->schemas->all() as $key => $schema) {
            if (!$schema->single) {
                $all[$key] = $this->get($key);
                continue;
            }

            $all[$key] = \array_key_exists($key, $values) ? $values[$key] : $this->defaultValue($schema, null);
        }

        return $all;
    }

    /**
     * 値を保存する。宣言済みのキーはスキーマの型に合う値だけを受け付ける。
     *
     * sanitize を宣言したキーは、WordPress がその sanitize を適用するのが register_*_meta(init priority 20 の
     * MetaSchemaRegistry::registerAll())の後なので、それより前の書き込みを例外にする(sanitize を通らない保存をしない)。
     *
     * @throws LogicException sanitize を宣言したキーを、スキーマを WordPress に登録する前に書こうとした場合
     * @throws InvalidArgumentException null、スキーマの型に合わない値、single=false のキーへの list 以外
     * @throws \TMSWP\Core\Exception\RuntimeException データベースのエラー
     *
     * @return bool 書き込んだら true、書き込まなかったら false(保存値は変わっていない)
     */
    public function set(string $key, mixed $value): bool
    {
        $schema = $this->schemas->get($key);

        if ($schema instanceof MetaSchemaDefinition && $schema->sanitize !== null && !($this->registeredWithWordPress)()) {
            throw new LogicException("meta '{$key}' of {$this->entityClass} declares sanitize and cannot be written before its schema is registered with WordPress; WordPress applies sanitize only after the registration at init priority 20, so write it after that");
        }

        if ($schema instanceof MetaSchemaDefinition && !$schema->single) {
            if (!\is_array($value) || !array_is_list($value)) {
                throw new InvalidArgumentException("single=false meta '{$key}' must be set with a list");
            }

            foreach ($value as $item) {
                $this->assertWritable($key, $schema, $item);
            }

            return $this->storage->setAll($this->objectType, $this->objectId, $key, $value);
        }

        $this->assertWritable($key, $schema, $value);

        return $this->storage->set($this->objectType, $this->objectId, $key, $value);
    }

    /**
     * @return bool 行を削除したら true、元々行が無かった・削除しなかったら false
     */
    public function delete(string $key): bool
    {
        return $this->storage->delete($this->objectType, $this->objectId, $key);
    }

    private function assertWritable(string $key, ?MetaSchemaDefinition $schema, mixed $value): void
    {
        if ($value === null) {
            throw new InvalidArgumentException("meta '{$key}' cannot be set to null; use delete() to remove it");
        }

        if ($schema instanceof MetaSchemaDefinition && !ValueCaster::accepts($schema->type, $value)) {
            throw new InvalidArgumentException("meta '{$key}' expects {$schema->type->value}, got " . get_debug_type($value));
        }
    }

    private function singleValue(string $key, ?MetaSchemaDefinition $schema, mixed $default = null): mixed
    {
        if ($this->storage->has($this->objectType, $this->objectId, $key)) {
            return $this->storage->get($this->objectType, $this->objectId, $key);
        }

        return $this->defaultValue($schema, $default);
    }

    private function defaultValue(?MetaSchemaDefinition $schema, mixed $default): mixed
    {
        if ($default !== null) {
            return $default;
        }

        if ($schema instanceof MetaSchemaDefinition && $schema->hasDefault()) {
            return $schema->default;
        }

        return null;
    }
}
