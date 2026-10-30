<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

/**
 * 生成カラム(TableDefinition::generated())の保存の方式。backing valueがそのままSQLの語になる。
 */
enum GeneratedColumnStorage: string
{
    /** 値を行に保存する */
    case Stored = 'STORED';

    /** 値を保存せず、読むときに計算する */
    case Virtual = 'VIRTUAL';
}
