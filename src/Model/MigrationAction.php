<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

/**
 * Migrator::migrateAll() がテーブルごとに行ったこと。
 */
enum MigrationAction: string
{
    /** dbDelta で定義を適用し、ハッシュを保存した */
    case Migrated = 'migrated';

    /** 定義のハッシュが保存済みのハッシュと同じため、dbDelta を実行しなかった */
    case Skipped = 'skipped';
}
