<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Support;

use TMSWP\Core\Model\GeneratedColumnStorage;
use TMSWP\Core\Model\TableDefinition;

/**
 * 生成カラムの統合テスト(GeneratedColumnIntegrationTest・MigrateCommandIntegrationTest)が使う、アカウントのテーブルの
 * 定義。要望の定義(Model層の設計書の利用例「アクティブな行だけで一意にする」)は、アカウントの種類と状態から、アクティブな行だけが値を持つ2つの生成カラムを作り、
 * それぞれにUNIQUEの索引を張る。`wp tmswp migrate`のサブプロセスからも読むため、tests/Support(autoload-dev)に置く。
 */
final class AccountsTable
{
    public const string NAME = 'tmswp_it_accounts';

    /** 生成カラムの無い定義(生成カラムを足す前のテーブル)。$onUpdateが偽ならupdated_atにON UPDATEを付けない。 */
    public static function regular(bool $onUpdate = true): TableDefinition
    {
        return TableDefinition::create(self::NAME)
            ->id()
            ->string('account_type', 20)
            ->string('status', 20)
            ->bigint('corp_id', nullable: true, unsigned: true)
            ->bigint('site_id', nullable: true, unsigned: true)
            ->datetime('created_at', default: 'CURRENT_TIMESTAMP')
            ->datetime('updated_at', default: 'CURRENT_TIMESTAMP', onUpdateCurrentTimestamp: $onUpdate);
    }

    /**
     * 要望の定義。$inactiveStatusesは、アクティブでない状態の並び(式に書くSQLのリストの中身)。
     *
     * @param literal-string $inactiveStatuses
     */
    public static function requested(bool $onUpdate = true, string $inactiveStatuses = "'expired','deleted'"): TableDefinition
    {
        return self::regular($onUpdate)
            ->generated(
                'corp_admin_active_key',
                'bigint(20) unsigned',
                "CASE WHEN account_type = 'corp_admin' AND status NOT IN (" . $inactiveStatuses . ') THEN corp_id ELSE NULL END',
                GeneratedColumnStorage::Stored,
            )
            ->generated(
                'site_role_active_key',
                'varchar(40)',
                "CASE WHEN account_type IN ('office_admin','office') AND status NOT IN (" . $inactiveStatuses . ") THEN CONCAT(account_type, ':', site_id) ELSE NULL END",
                GeneratedColumnStorage::Virtual,
            )
            ->unique('corp_admin_active_key', 'uq_corp_admin')
            ->unique('site_role_active_key', 'uq_site_role');
    }
}
