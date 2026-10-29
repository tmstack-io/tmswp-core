<?php

declare(strict_types=1);

namespace TMSWP\Core\Entity;

use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Tax\Taxonomy;

/**
 * スラッグまたはエンティティクラスの FQCN を、スラッグに解決する(03-entities.md §1.5)。
 *
 * `\` を含む文字列はクラスの参照とみなす(WordPress のスラッグは `\` を含まない)。存在しないクラスや、期待する基底を
 * 継承しないクラスは設定の誤りとして例外にする。`\` を含まない文字列はスラッグとしてそのまま返す。
 *
 * @internal
 */
final class EntityReference
{
    private function __construct() {}

    /**
     * @throws EntityDefinitionException クラスの参照が存在しない、または PostType を継承しない場合
     */
    public static function postTypeSlug(string $reference): string
    {
        if (!self::isClassReference($reference)) {
            return $reference;
        }

        if (!is_subclass_of($reference, PostType::class)) {
            throw new EntityDefinitionException("class '{$reference}' must extend " . PostType::class);
        }

        return $reference::slug();
    }

    /**
     * @throws EntityDefinitionException クラスの参照が存在しない、または Taxonomy を継承しない場合
     */
    public static function taxonomySlug(string $reference): string
    {
        if (!self::isClassReference($reference)) {
            return $reference;
        }

        if (!is_subclass_of($reference, Taxonomy::class)) {
            throw new EntityDefinitionException("class '{$reference}' must extend " . Taxonomy::class);
        }

        return $reference::slug();
    }

    /**
     * @throws EntityDefinitionException `\` を含むのにクラスが存在しない場合
     */
    private static function isClassReference(string $reference): bool
    {
        if (!str_contains($reference, '\\')) {
            return false;
        }

        if (!class_exists($reference)) {
            throw new EntityDefinitionException("class '{$reference}' does not exist");
        }

        return true;
    }
}
