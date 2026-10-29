<?php

declare(strict_types=1);

/*
 * KernelStorageTest が boot する app のクラス(名前空間 TmswpStorageSwap)。エンティティとオプショングループの
 * 読み書きを、アプリのコードと同じ書き方で StorageSwapProbe が実行する。
 */

namespace TmswpStorageSwap;

use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Attribute\UseMetaStorage;
use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Option\OptionAccess;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use TMSWP\Testing\MemoryMetaStorage;
use WP_Post;

#[AsPostType]
final class SwapPost extends PostType
{
    #[MetaSchema(type: MetaValueType::Integer, default: 0)]
    public const META_PRICE = 'price';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('tmswp_swap');
    }
}

#[AsPostType]
#[UseMetaStorage(ExplicitMetaStorage::class)]
final class ExplicitPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String, default: '')]
    public const META_NOTE = 'note';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('tmswp_explicit');
    }
}

#[AsOptionGroup(prefix: 'swap_')]
enum SwapOption: string
{
    use OptionAccess;

    #[OptionSchema(type: MetaValueType::String, default: 'none')]
    case Mode = 'mode';
}

/** #[UseMetaStorage] で明示するストレージ。中身はメモリに保存する。 */
final class ExplicitMetaStorage implements MetaStorageInterface
{
    private MemoryMetaStorage $memory;

    public function __construct()
    {
        $this->memory = new MemoryMetaStorage();
    }

    public function has(ObjectType $type, int $objectId, string $key): bool
    {
        return $this->memory->has($type, $objectId, $key);
    }

    public function get(ObjectType $type, int $objectId, string $key): mixed
    {
        return $this->memory->get($type, $objectId, $key);
    }

    public function getAll(ObjectType $type, int $objectId, string $key): array
    {
        return $this->memory->getAll($type, $objectId, $key);
    }

    public function getMultiple(ObjectType $type, int $objectId, array $keys): array
    {
        return $this->memory->getMultiple($type, $objectId, $keys);
    }

    public function set(ObjectType $type, int $objectId, string $key, mixed $value): bool
    {
        return $this->memory->set($type, $objectId, $key, $value);
    }

    public function setAll(ObjectType $type, int $objectId, string $key, array $values): bool
    {
        return $this->memory->setAll($type, $objectId, $key, $values);
    }

    public function delete(ObjectType $type, int $objectId, string $key): bool
    {
        return $this->memory->delete($type, $objectId, $key);
    }
}

final class StorageSwapProbe
{
    /**
     * 価格を読んで1足して書き、メモの書き込みも行う。
     *
     * @return array{price: int, note: bool}
     */
    public static function exerciseMeta(WP_Post $swapPost, WP_Post $explicitPost): array
    {
        $price = SwapPost::meta($swapPost)->int(SwapPost::META_PRICE);
        SwapPost::meta($swapPost)->set(SwapPost::META_PRICE, $price + 1);

        return [
            'price' => $price,
            'note' => ExplicitPost::meta($explicitPost)->set(ExplicitPost::META_NOTE, 'explicit'),
        ];
    }

    /**
     * モードを読んで書き換える。
     *
     * @return array{mode: string, has: bool}
     */
    public static function exerciseOption(): array
    {
        $mode = SwapOption::Mode->string();
        SwapOption::Mode->set('after');

        return ['mode' => $mode, 'has' => SwapOption::Mode->has()];
    }
}
