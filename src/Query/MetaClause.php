<?php

declare(strict_types=1);

namespace TMSWP\Core\Query;

/**
 * エンティティのクエリ3種が共有する meta_query の句の組み立て。
 *
 * @internal
 */
final class MetaClause
{
    private function __construct() {}

    /**
     * orderByMeta() の並び替えだけに使う句のグループを返す。
     *
     * 名前付きの NOT EXISTS の句と EXISTS の句を OR で結ぶため、絞り込みの条件にはならず、メタを持たないレコードも
     * 結果に残る。並び替えには名前付きの NOT EXISTS の句を使う。WP_Meta_Query は NOT EXISTS の句だけを、メタキーを
     * ON に含む LEFT JOIN にするため、その句の meta_value はメタを持つレコードではその値、持たないレコードでは NULL
     * になる(EXISTS の句の JOIN はメタキーを ON に含まず、ほかのメタの行と結び付くため並び替えに使えない)。MySQL・
     * MariaDB は NULL を ASC では先頭、DESC では末尾に並べる。
     *
     * @return array<int|string, string|array<string, string>>
     */
    public static function orderGroup(string $name, string $key, CastType $type): array
    {
        return [
            'relation' => 'OR',
            $name => ['key' => $key, 'compare' => 'NOT EXISTS', 'type' => $type->value],
            ['key' => $key, 'compare' => 'EXISTS'],
        ];
    }
}
