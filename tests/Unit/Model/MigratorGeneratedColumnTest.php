<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Model;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TMSWP\Core\Exception\MigrationException;
use TMSWP\Core\Exception\QueryException;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\GeneratedColumnStorage;
use TMSWP\Core\Model\MigrationAction;
use TMSWP\Core\Model\Migrator;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;

/**
 * Migratorの生成カラムの適用(dbDeltaの後のMODIFY)と、適用後の照合の生成カラム・ON UPDATE(Model層の設計書の「生成カラムと
 * ON UPDATEの適用と照合」)を、wpdbの代役で確かめる。実物のDBでの確認は統合テスト(GeneratedColumnIntegrationTest)。
 */
final class MigratorGeneratedColumnTest extends UnitTestCase
{
    private const string TABLE = 'wp_tmswp_generated';

    private WpdbTestDouble $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        WpdbTestDouble::defineOutputConstants();
        $this->wpdb = WpdbTestDouble::create();
        // 保存済みのハッシュの読み書きは、グローバルの $wpdb でデータベースのエラーを確かめる
        $GLOBALS['wpdb'] = $this->wpdb;
        Functions\when('get_option')->justReturn([]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /**
     * dbDeltaの前にあった生成カラムだけを、dbDeltaの後にMODIFYで定義の列の行にする。式の`%`は、Connection::execute()を
     * 通っても`%`のまま届く。dbDeltaが今回ADDした生成カラムにはMODIFYを出さない。
     */
    #[RunInSeparateProcess]
    public function testExistingGeneratedColumnsAreModifiedAfterDbDelta(): void
    {
        $definition = self::parity();
        MigratorGeneratedColumnTestModel::$definition = $definition;
        Functions\expect('dbDelta')->twice()->andReturn([]);
        Functions\expect('update_option')->once()->andReturn(true);
        // dbDeltaの前: テーブルがあり、odd はあるが added は無い
        $this->wpdb->queueVar(self::TABLE);
        $this->wpdb->queueResultSet([self::row('id', 'NO', null, 'auto_increment'), self::row('val', 'NO', null), self::row('odd', 'YES', null, 'STORED GENERATED')]);
        $this->queueVerification($definition, []);

        self::assertSame(MigrationAction::Migrated, $this->migrator()->migrateAll()[0]->action);
        self::assertSame(['ALTER TABLE wp_tmswp_generated MODIFY COLUMN odd tinyint(1) AS (val % 2) STORED'], $this->alters());
    }

    /** テーブルが無ければ、dbDeltaの前に列を読まず、MODIFYを出さない(dbDeltaがCREATE TABLEで生成カラムごと作る)。 */
    #[RunInSeparateProcess]
    public function testNewTableIsCreatedByDbDeltaWithoutModify(): void
    {
        $definition = self::parity();
        MigratorGeneratedColumnTestModel::$definition = $definition;
        Functions\expect('dbDelta')->twice()->andReturn([]);
        Functions\expect('update_option')->once()->andReturn(true);
        $this->wpdb->queueVar(null);
        $this->queueVerification($definition, []);

        self::assertSame(MigrationAction::Migrated, $this->migrator()->migrateAll()[0]->action);
        self::assertSame([], $this->alters());
    }

    /**
     * MODIFYのDBのエラーは、テーブル名・列名・DBのエラーと、STOREDとVIRTUALの入れ替え・通常の列からVIRTUALへの変更・
     * UNIQUEの重複の案内を含むMigrationExceptionになり(元の例外をpreviousにする)、ハッシュを保存しない。
     */
    #[RunInSeparateProcess]
    public function testModifyFailureThrowsMigrationExceptionWithGuidanceAndKeepsTheHash(): void
    {
        MigratorGeneratedColumnTestModel::$definition = self::parity();
        Functions\expect('dbDelta')->once()->andReturn([]);
        Functions\expect('update_option')->never();
        Functions\expect('add_option')->never();
        $this->wpdb->queueVar(self::TABLE);
        $this->wpdb->queueResultSet([self::row('id', 'NO', null, 'auto_increment'), self::row('val', 'NO', null), self::row('odd', 'YES', null)]);
        $this->wpdb->failNextWrite('This is not yet supported for generated columns');

        try {
            $this->migrator()->migrateAll();
            self::fail('expected MigrationException');
        } catch (MigrationException $exception) {
            self::assertStringStartsWith('failed to apply the generated column tmswp_generated.odd: ', $exception->getMessage());
            self::assertStringContainsString('This is not yet supported for generated columns', $exception->getMessage());
            self::assertStringContainsString('switching between STORED and VIRTUAL and changing a regular column to VIRTUAL are not possible with MODIFY, so drop the column and add it again with a manual ALTER TABLE', $exception->getMessage());
            self::assertStringContainsString('a Duplicate entry error means that rows have the same value of the new expression in a UNIQUE index', $exception->getMessage());
            self::assertInstanceOf(QueryException::class, $exception->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{TableDefinition, array<string, string>, string|null}> 定義、列ごとの Extra、
     *                                                                                      食い違いのメッセージ(一致ならnull)
     */
    public static function verifications(): iterable
    {
        $touched = static fn(): TableDefinition => TableDefinition::create('tmswp_generated')
            ->id()
            ->datetime('created_at', default: 'CURRENT_TIMESTAMP')
            ->datetime('updated_at', default: 'CURRENT_TIMESTAMP', onUpdateCurrentTimestamp: true)
            ->rawColumn('touched_at', 'timestamp(6) NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(6)');

        yield 'MySQLのExtra' => [
            $touched(),
            ['created_at' => 'DEFAULT_GENERATED', 'updated_at' => 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP', 'touched_at' => 'on update CURRENT_TIMESTAMP(6)'],
            null,
        ];
        yield 'MariaDBのExtra' => [
            $touched(),
            ['created_at' => '', 'updated_at' => 'on update current_timestamp()', 'touched_at' => 'on update current_timestamp(6)'],
            null,
        ];
        yield 'ON UPDATEの同義語(NOW()・LOCALTIMESTAMP)' => [
            TableDefinition::create('tmswp_generated')
                ->id()
                ->rawColumn('touched_at', 'datetime NULL DEFAULT NULL ON UPDATE NOW()')
                ->rawColumn('seen_at', 'datetime(6) NOT NULL DEFAULT NOW(6) ON UPDATE LOCALTIMESTAMP(6)'),
            ['touched_at' => 'on update current_timestamp()', 'seen_at' => 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP(6)'],
            null,
        ];
        yield 'DBにON UPDATEが無い' => [
            $touched(),
            ['created_at' => 'DEFAULT_GENERATED', 'updated_at' => 'DEFAULT_GENERATED', 'touched_at' => 'on update CURRENT_TIMESTAMP(6)'],
            'table tmswp_generated: column updated_at is expected to have ON UPDATE CURRENT_TIMESTAMP but the database has no ON UPDATE; dbDelta does not apply this change, so apply it with a manual ALTER TABLE, or change the definition to match the database (datetime(onUpdateCurrentTimestamp: true) for ON UPDATE CURRENT_TIMESTAMP)',
        ];
        yield '宣言にON UPDATEが無い' => [
            $touched(),
            ['created_at' => 'on update current_timestamp()', 'updated_at' => 'on update current_timestamp()', 'touched_at' => 'on update current_timestamp(6)'],
            'table tmswp_generated: column created_at is expected to have no ON UPDATE but the database has ON UPDATE CURRENT_TIMESTAMP; dbDelta does not apply this change',
        ];
        yield 'ON UPDATEの精度の違い' => [
            $touched(),
            ['created_at' => '', 'updated_at' => 'on update current_timestamp()', 'touched_at' => 'on update current_timestamp()'],
            'table tmswp_generated: column touched_at is expected to have ON UPDATE CURRENT_TIMESTAMP(6) but the database has ON UPDATE CURRENT_TIMESTAMP; dbDelta does not apply this change',
        ];

        $generated = static fn(GeneratedColumnStorage $storage): TableDefinition => TableDefinition::create('tmswp_generated')
            ->id()
            ->int('val')
            ->generated('derived', 'int(11)', 'val + 1', $storage);

        yield '生成カラムのSTORED' => [$generated(GeneratedColumnStorage::Stored), ['derived' => 'STORED GENERATED'], null];
        yield '生成カラムのVIRTUAL' => [$generated(GeneratedColumnStorage::Virtual), ['derived' => 'VIRTUAL GENERATED'], null];
        yield 'STOREDの宣言とDBのVIRTUAL' => [
            $generated(GeneratedColumnStorage::Stored),
            ['derived' => 'VIRTUAL GENERATED'],
            'table tmswp_generated: column derived is expected to be a STORED generated column but the database has a VIRTUAL generated column; dbDelta does not apply this change, so apply it with a manual ALTER TABLE, or change the definition to match the database (generated() for a generated column)',
        ];
        yield '生成カラムの宣言とDBの通常の列' => [
            $generated(GeneratedColumnStorage::Virtual),
            ['derived' => ''],
            'table tmswp_generated: column derived is expected to be a VIRTUAL generated column but the database has a regular column; dbDelta does not apply this change',
        ];
        yield '通常の列の宣言とDBの生成カラム' => [
            TableDefinition::create('tmswp_generated')->id()->int('val', nullable: true),
            ['val' => 'STORED GENERATED'],
            'table tmswp_generated: column val is expected to be a regular column but the database has a STORED generated column; dbDelta does not apply this change',
        ];
    }

    /**
     * 適用後の照合は、SHOW FULL COLUMNSのExtraから生成カラムの保存の方式とON UPDATEを読み、宣言と比べる。MySQLの
     * DEFAULT_GENERATEDを生成カラムと取り違えず、ON UPDATEはCURRENT_TIMESTAMPの表記をそろえて精度を保って比べる。
     * 食い違えば、手動のALTERと定義の直し方を案内するMigrationExceptionになり、ハッシュを保存しない。
     *
     * @param array<string, string> $extras
     */
    #[RunInSeparateProcess]
    #[DataProvider('verifications')]
    public function testVerificationComparesGenerationAndOnUpdateFromExtra(TableDefinition $definition, array $extras, ?string $message): void
    {
        MigratorGeneratedColumnTestModel::$definition = $definition;
        Functions\when('dbDelta')->justReturn([]);
        Functions\expect('update_option')->times($message === null ? 1 : 0)->andReturn(true);

        if ($definition->generatedColumns() !== []) {
            // dbDeltaの前: テーブルが無い
            $this->wpdb->queueVar(null);
        }

        $this->queueVerification($definition, $extras);

        if ($message !== null) {
            $this->expectException(MigrationException::class);
            $this->expectExceptionMessage($message);
        }

        self::assertSame(MigrationAction::Migrated, $this->migrator()->migrateAll()[0]->action);
    }

    private function migrator(): Migrator
    {
        $migrator = new Migrator(new Connection($this->wpdb));
        $migrator->register(MigratorGeneratedColumnTestModel::class);

        return $migrator;
    }

    /**
     * 適用後の照合(SHOW TABLES・SHOW FULL COLUMNS・SHOW INDEX)の結果を、定義どおりの構造として入れる。Extraは
     * 生成カラムなら保存の方式に合わせ、$extrasで列ごとに上書きする。
     *
     * @param array<string, string> $extras
     */
    private function queueVerification(TableDefinition $definition, array $extras): void
    {
        $rows = [];

        foreach ($definition->columns() as $column) {
            $generation = $definition->columnGeneration($column);
            $extra = $generation === null ? '' : "{$generation->value} GENERATED";
            $rows[] = self::row($column, $definition->columnNullable($column) ? 'YES' : 'NO', $definition->columnDefault($column), $extras[$column] ?? $extra);
        }

        $this->wpdb->queueVar(self::TABLE);
        $this->wpdb->queueResultSet($rows);
        $this->wpdb->queueResultSet([['Key_name' => 'PRIMARY', 'Column_name' => 'id', 'Seq_in_index' => '1']]);
    }

    /** @return list<string> 実行したALTER TABLE */
    private function alters(): array
    {
        return array_values(array_filter($this->wpdb->queries(), static fn(string $sql): bool => str_starts_with($sql, 'ALTER TABLE')));
    }

    /** @return array{Field: string, Null: string, Default: string|null, Extra: string} SHOW FULL COLUMNSの1行 */
    private static function row(string $field, string $null, ?string $default, string $extra = ''): array
    {
        return ['Field' => $field, 'Null' => $null, 'Default' => $default, 'Extra' => $extra];
    }

    private static function parity(): TableDefinition
    {
        return TableDefinition::create('tmswp_generated')
            ->id()
            ->int('val')
            ->generated('odd', 'tinyint(1)', 'val % 2', GeneratedColumnStorage::Stored)
            ->generated('added', 'int(11)', 'val + 1', GeneratedColumnStorage::Virtual);
    }
}

/** 照合のテストが、データごとに定義を差し替える Model。 */
final class MigratorGeneratedColumnTestModel extends Model
{
    public static TableDefinition $definition;

    public static function table(): TableDefinition
    {
        return self::$definition;
    }
}
