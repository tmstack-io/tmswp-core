<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\MigrationAction;
use TMSWP\Core\Model\MigrationResult;
use TMSWP\Core\Model\Migrator;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;
use TMSWP\Core\Tests\Support\RawColumnTypes;
use TMSWP\Testing\IntegrationTestCase;

/**
 * 組込の全ての列の型(既定値・NULLの可否・unsignedの組み合わせを含む)と、rawColumn()が受け付ける型(RawColumnTypes)の
 * テーブルを、接続しているDBで作成し、再適用しても差分が残らないことを確かめる(06-model §5)。
 *
 * Migratorは適用のたびに、dbDeltaのエラー(検証1)、dry-runの残った差分(検証2)、実際の構造と定義の照合(検証3)を
 * 確かめ、食い違えばMigrationExceptionを投げる。初回の作成と、force: trueでの再適用の両方がMigratedで終わることを
 * 確かめ、さらに再適用の後のdry-runが空であることを直接確かめる。CIでは、wp-envのMariaDBと、MySQL 8.4 LTS・
 * 9.7 LTSのジョブで走る(10-quality §5.1)。
 */
final class MigratorColumnTypeIntegrationTest extends IntegrationTestCase
{
    private const string OPTION = 'tmswp_schema_hashes';

    private const array MODELS = [
        MigratorColumnTypeIntegrationTestBuiltin::class,
        MigratorColumnTypeIntegrationTestRaw::class,
        MigratorColumnTypeIntegrationTestAutoIncrement::class,
    ];

    /**
     * テーブルは実際のテーブルで作る(MySQLのSHOW TABLES・SHOW TABLE STATUSは一時テーブルを返さず、Migratorの検証3を
     * 確かめられないため。IntegrationTestCase::useRealTables())。作るテーブルを宣言し、tear_downで消す。DDLでコミットされた
     * ハッシュのoptionは、ロールバックの後の後始末で消す。
     */
    public function set_up(): void
    {
        parent::set_up();
        delete_option(self::OPTION);
        $this->useRealTables('tmswp_it_coltype_builtin', 'tmswp_it_coltype_raw', 'tmswp_it_coltype_auto')->onTearDown(static function (): void {
            delete_option(self::OPTION);
        });
    }

    public function testEveryColumnTypeIsCreatedAndReappliedWithoutDifferences(): void
    {
        $wpdb = self::wpdb();
        $version = (string) $wpdb->get_var('SELECT VERSION()');
        $connection = new Connection($wpdb);
        $migrator = new Migrator($connection);

        foreach (self::MODELS as $model) {
            $migrator->register($model);
        }

        $expected = array_map(static fn(string $model): array => [$model::bareTableName(), MigrationAction::Migrated], self::MODELS);

        self::assertSame($expected, self::actions($migrator->migrateAll()), "creating on {$version}");
        self::assertSame($expected, self::actions($migrator->migrateAll(force: true)), "reapplying on {$version}");

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach (self::MODELS as $model) {
            self::assertSame([], dbDelta($model::table()->toSql($connection), false), "dbDelta dry-run of {$model::bareTableName()} on {$version}");
        }
    }

    /**
     * @param list<MigrationResult> $results
     *
     * @return list<array{string, MigrationAction}>
     */
    private static function actions(array $results): array
    {
        return array_map(static fn(MigrationResult $result): array => [$result->table, $result->action], $results);
    }
}

/** 組込の列の型のすべてを、既定値・NULLの可否・unsignedの組み合わせとともに宣言する。 */
final class MigratorColumnTypeIntegrationTestBuiltin extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_coltype_builtin')
            ->id()
            ->string('str_plain')
            ->string('str_short', 20, nullable: true)
            ->string('str_default', 50, default: 'draft')
            ->string('str_empty_default', 10, default: '')
            ->text('text_nullable')
            ->text('text_required', nullable: false)
            ->int('int_plain')
            ->int('int_nullable', nullable: true)
            ->int('int_default', default: -5)
            ->int('int_unsigned', default: 7, unsigned: true)
            ->bigint('bigint_plain')
            ->bigint('bigint_nullable', nullable: true, default: 9007199254740993)
            ->bigint('bigint_unsigned', unsigned: true)
            ->decimal('dec_plain')
            ->decimal('dec_default', 30, 4, default: '12.5')
            ->decimal('dec_nullable', 8, 0, nullable: true, unsigned: true)
            ->bool('flag_off')
            ->bool('flag_on', true)
            ->datetime('dt_plain')
            ->datetime('dt_nullable', nullable: true)
            ->datetime('dt_now', default: 'CURRENT_TIMESTAMP')
            ->datetime('dt_fixed', default: '2020-01-02 03:04:05')
            ->json('json_nullable')
            ->json('json_required', nullable: false)
            ->foreignId('owner_id')
            ->foreignId('parent_id', nullable: true)
            ->index('str_plain')
            ->index(['int_plain', 'bigint_plain'], 'int_pair')
            ->unique('str_short');
    }
}

/** rawColumn()が受け付ける型(AUTO_INCREMENTを除く)を、1つのテーブルにまとめて宣言する。 */
final class MigratorColumnTypeIntegrationTestRaw extends Model
{
    public static function table(): TableDefinition
    {
        $table = TableDefinition::create('tmswp_it_coltype_raw')->id();
        $index = 0;

        foreach (RawColumnTypes::ACCEPTED as $definition) {
            $table->rawColumn('raw_' . $index++, $definition);
        }

        return $table;
    }
}

/** rawColumn()のAUTO_INCREMENTの列を主キーにして宣言する。 */
final class MigratorColumnTypeIntegrationTestAutoIncrement extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_coltype_auto')
            ->rawColumn('row_no', RawColumnTypes::ACCEPTED_AUTO_INCREMENT)
            ->primary('row_no')
            ->string('label', 20);
    }
}
