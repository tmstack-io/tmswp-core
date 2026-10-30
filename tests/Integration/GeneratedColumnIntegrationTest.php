<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use Closure;
use Throwable;
use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Exception\MigrationException;
use TMSWP\Core\Exception\QueryException;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\GeneratedColumnStorage;
use TMSWP\Core\Model\MigrationAction;
use TMSWP\Core\Model\MigrationResult;
use TMSWP\Core\Model\MigrationStatus;
use TMSWP\Core\Model\Migrator;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;
use TMSWP\Core\Model\UpsertUpdate;
use TMSWP\Core\Tests\Support\AccountsBaseModel;
use TMSWP\Core\Tests\Support\AccountsChangedExpressionModel;
use TMSWP\Core\Tests\Support\AccountsModel;
use TMSWP\Core\Tests\Support\AccountsTable;
use TMSWP\Testing\IntegrationTestCase;

/**
 * 生成カラムとON UPDATE CURRENT_TIMESTAMPを、実物のDBでMigratorのdry-run(status())と適用(migrateAll())を呼んで
 * 確かめる(Model層の設計書の「生成カラム」「生成カラムとON UPDATEの適用と照合」)。要望の定義(AccountsTable::requested())は、アクティブな行だけが値を持つ2つの生成カラム
 * (STOREDとVIRTUAL)と、それぞれのUNIQUEの索引を持つ。
 *
 * Migratorの検証3はSHOW TABLES・SHOW FULL COLUMNSで実際の構造を読むため、テーブルはuseRealTables()で実際のテーブルとして
 * 作る。Migratorは登録の時点の定義を持つため、定義の変更は、同じテーブルの別の定義のModelを新しいMigratorに登録して
 * 再現する。CIでは、wp-envのMariaDBと、MariaDB 10.11・MySQL 8.4・9.7のすべてで走る。
 */
final class GeneratedColumnIntegrationTest extends IntegrationTestCase
{
    private const string OPTION = 'tmswp_schema_hashes';

    private const string CONVERT_TABLE = 'tmswp_it_generated_convert';

    private Connection $connection;

    /** このテストの前のwpdbのsuppress_errorsの値。tear_downで戻す。 */
    private bool $suppressedErrors = false;

    /**
     * UNIQUEの重複・MODIFYの失敗等のDBのエラーはテストで起こすものなので、wpdbのエラーの表示を止める(例外の判定に使う
     * last_errorは変わらない)。保存済みのハッシュが無い状態から始め、DDLでコミットされたoptionは後始末で消す。
     */
    public function set_up(): void
    {
        parent::set_up();
        $this->suppressedErrors = self::wpdb()->suppress_errors();
        delete_option(self::OPTION);
        $this->useRealTables(AccountsTable::NAME, self::CONVERT_TABLE)->onTearDown(static function (): void {
            delete_option(self::OPTION);
        });
        $this->connection = new Connection(self::wpdb());
        Model::useConnection($this->connection);
    }

    public function tear_down(): void
    {
        try {
            Model::useConnection(null);
            self::wpdb()->suppress_errors($this->suppressedErrors);
        } finally {
            parent::tear_down();
        }
    }

    /**
     * dry-run → 適用 → もう一度dry-runの順に実行すると、最後が変更なしになる。適用の後のdbDeltaのdry-runも空で、
     * 生成カラムはExtraに保存の方式が出る。--force相当の再適用(既にある生成カラムへのMODIFY)も通る。
     */
    public function testDryRunApplyDryRunEndsUpToDate(): void
    {
        $migrator = self::migrator(AccountsModel::class);

        self::assertSame([false], self::currents($migrator->status()));
        self::assertSame([MigrationAction::Migrated], self::actions($migrator->migrateAll()));
        self::assertSame([true], self::currents($migrator->status()));
        self::assertSame([], $this->dbDeltaDryRun(AccountsTable::requested()));
        self::assertSame(['STORED GENERATED', 'VIRTUAL GENERATED'], [$this->extra('corp_admin_active_key'), $this->extra('site_role_active_key')]);
        self::assertSame([MigrationAction::Skipped], self::actions($migrator->migrateAll()));
        self::assertSame([MigrationAction::Migrated], self::actions($migrator->migrateAll(force: true)));
        self::assertSame([true], self::currents($migrator->status()));
    }

    /**
     * 既にあるテーブル(生成カラムの無い定義で作ったもの)に生成カラムを足すと、dry-runが変更ありを報告し、適用で列と
     * 索引が足される。既にある行の生成カラムの値も計算される。
     */
    public function testAddingGeneratedColumnsToExistingTableIsReportedAndApplied(): void
    {
        self::migrator(AccountsBaseModel::class)->migrateAll();
        $active = $this->insertRaw('corp_admin', 'active', 1, null);
        $office = $this->insertRaw('office', 'active', null, 5);
        $migrator = self::migrator(AccountsModel::class);

        self::assertSame([false], self::currents($migrator->status()));
        self::assertSame([MigrationAction::Migrated], self::actions($migrator->migrateAll()));
        self::assertSame([true], self::currents($migrator->status()));
        self::assertSame([], $this->dbDeltaDryRun(AccountsTable::requested()));
        self::assertSame(['corp_admin_active_key'], $this->indexColumns('uq_corp_admin'));
        self::assertSame(['site_role_active_key'], $this->indexColumns('uq_site_role'));
        self::assertSame(['1', null], $this->activeKeys($active));
        self::assertSame([null, 'office:5'], $this->activeKeys($office));
    }

    /**
     * アクティブな行どうしの重複はUNIQUEの索引で拒否され、非アクティブな行(生成カラムがNULL)は複数共存できる。
     * 非アクティブからアクティブに戻したとき、重複していれば拒否される。STOREDとVIRTUALの両方で確かめる。
     */
    public function testOnlyActiveRowsAreUniqueAndReactivationIsRejectedWhenDuplicated(): void
    {
        self::migrator(AccountsModel::class)->migrateAll();

        $active = AccountsModel::insert(['account_type' => 'corp_admin', 'status' => 'active', 'corp_id' => 1]);
        self::assertStringContainsString('Duplicate entry', self::queryError(static fn(): int => AccountsModel::insert(['account_type' => 'corp_admin', 'status' => 'active', 'corp_id' => 1])));
        $expired = AccountsModel::insert(['account_type' => 'corp_admin', 'status' => 'expired', 'corp_id' => 1]);
        $deleted = AccountsModel::insert(['account_type' => 'corp_admin', 'status' => 'deleted', 'corp_id' => 1]);
        // アクティブな行がほかの会社なら、同じ種類でも共存できる
        AccountsModel::insert(['account_type' => 'corp_admin', 'status' => 'active', 'corp_id' => 2]);
        self::assertSame([null, null], [$this->activeKeys($expired)[0], $this->activeKeys($deleted)[0]]);
        self::assertStringContainsString('Duplicate entry', self::queryError(static fn(): int => AccountsModel::update(['status' => 'active'], $expired)));
        // アクティブな行を非アクティブにすれば、ほかの行をアクティブに戻せる
        self::assertSame(1, AccountsModel::update(['status' => 'expired'], $active));
        self::assertSame(1, AccountsModel::update(['status' => 'active'], $deleted));
        self::assertSame(['1', null], $this->activeKeys($deleted));

        $office = AccountsModel::insert(['account_type' => 'office', 'status' => 'active', 'site_id' => 9]);
        self::assertStringContainsString('Duplicate entry', self::queryError(static fn(): int => AccountsModel::insert(['account_type' => 'office', 'status' => 'active', 'site_id' => 9])));
        $inactiveOffice = AccountsModel::insert(['account_type' => 'office', 'status' => 'deleted', 'site_id' => 9]);
        AccountsModel::insert(['account_type' => 'office', 'status' => 'expired', 'site_id' => 9]);
        AccountsModel::insert(['account_type' => 'office_admin', 'status' => 'active', 'site_id' => 9]);
        self::assertSame([null, 'office:9'], $this->activeKeys($office));
        self::assertStringContainsString('Duplicate entry', self::queryError(static fn(): int => AccountsModel::query()->where('id', $inactiveOffice)->update(['status' => 'active'])));
    }

    /** 式を変えると、dry-runが変更ありを報告し、適用(MODIFY)で既にある行の値にも反映される。 */
    public function testChangingExpressionIsReportedAndApplied(): void
    {
        self::migrator(AccountsModel::class)->migrateAll();
        $suspended = AccountsModel::insert(['account_type' => 'corp_admin', 'status' => 'suspended', 'corp_id' => 3]);
        $suspendedOffice = AccountsModel::insert(['account_type' => 'office', 'status' => 'suspended', 'site_id' => 3]);
        self::assertSame(['3', null], $this->activeKeys($suspended));
        self::assertSame([null, 'office:3'], $this->activeKeys($suspendedOffice));
        $migrator = self::migrator(AccountsChangedExpressionModel::class);

        self::assertSame([false], self::currents($migrator->status()));
        self::assertSame([MigrationAction::Migrated], self::actions($migrator->migrateAll()));
        self::assertSame([true], self::currents($migrator->status()));
        self::assertSame([null, null], $this->activeKeys($suspended));
        self::assertSame([null, null], $this->activeKeys($suspendedOffice));
        // 新しい式では、suspended の行どうしは重複しない
        AccountsModel::insert(['account_type' => 'corp_admin', 'status' => 'suspended', 'corp_id' => 3]);
    }

    /**
     * 生成カラムへの書き込み(Model::insert()・update()・upsert()・query()->update())は、DBに送る前にInvalidQueryExceptionに
     * なる(MariaDBはstrictでないsql_modeで値を黙って捨て、MySQLはエラー3105にするため、DBの違いが出ない)。列名の大小文字は
     * 区別しない。生成カラムを書き込みの対象にしないupsertは通り、更新した行の生成カラムをDBが計算し直す。
     */
    public function testWritesToGeneratedColumnsThrowBeforeTheDatabase(): void
    {
        self::migrator(AccountsModel::class)->migrateAll();
        $id = AccountsModel::insert(['account_type' => 'corp_admin', 'status' => 'active', 'corp_id' => 4]);
        $writes = [
            static fn(): int => AccountsModel::insert(['account_type' => 'corp_admin', 'status' => 'active', 'corp_id' => 5, 'corp_admin_active_key' => 5]),
            static fn(): int => AccountsModel::update(['corp_admin_active_key' => 9], $id),
            static fn(): int => AccountsModel::update(['site_role_active_key' => 'office:1'], ['corp_admin_active_key' => 4]),
            static fn(): int => AccountsModel::query()->where('id', $id)->update(['corp_admin_active_key' => null]),
            static fn(): int => AccountsModel::upsert(['id' => $id, 'corp_admin_active_key' => 4], ['corp_admin_active_key' => UpsertUpdate::Replace]),
            static fn(): int => AccountsModel::upsert(['id' => $id, 'status' => 'active', 'Site_Role_Active_Key' => 'office:1'], ['status' => UpsertUpdate::Replace]),
        ];

        foreach ($writes as $index => $write) {
            try {
                $write();
                self::fail("write {$index} did not throw");
            } catch (InvalidQueryException $exception) {
                self::assertStringContainsString('the database computes its value', $exception->getMessage());
            }
        }

        self::assertSame(1, AccountsModel::count());
        self::assertSame(['4', null], $this->activeKeys($id));
        // 条件の生成カラムは拒否しない
        self::assertSame(1, AccountsModel::update(['status' => 'expired'], ['corp_admin_active_key' => 4]));
        self::assertSame([null, null], $this->activeKeys($id));
        self::assertSame(2, AccountsModel::upsert(['id' => $id, 'account_type' => 'corp_admin', 'status' => 'active', 'corp_id' => 4], ['status' => UpsertUpdate::Replace]));
        self::assertSame(['4', null], $this->activeKeys($id));
    }

    /**
     * ON UPDATE CURRENT_TIMESTAMPの列は、適用の後のdry-runが変更なしになり、UPDATEで値が自動で更新される(行の値が
     * 変わらないUPDATEでは変わらない)。宣言から
     * 外すとdry-runが変更ありを報告し、dbDeltaは既にある列のON UPDATEを外さないため、適用は検証3で手動のALTERを案内して
     * 失敗し、ハッシュを更新しない。
     */
    public function testOnUpdateCurrentTimestampIsAppliedAndRemovalNeedsManualAlter(): void
    {
        $migrator = self::migrator(AccountsModel::class);
        $migrator->migrateAll();

        self::assertSame([true], self::currents($migrator->status()));
        self::assertMatchesRegularExpression('/\bon update current_timestamp\b/i', $this->extra('updated_at'));
        $id = AccountsModel::insert(['account_type' => 'corp_admin', 'status' => 'active', 'corp_id' => 6, 'updated_at' => '2001-02-03 04:05:06']);
        self::assertSame('2001-02-03 04:05:06', $this->updatedAt($id));
        // 行の値が変わらないUPDATEでは、DBは日時を変えない
        AccountsModel::update(['status' => 'active'], $id);
        self::assertSame('2001-02-03 04:05:06', $this->updatedAt($id));
        self::assertSame(1, AccountsModel::update(['status' => 'expired'], $id));
        self::assertNotSame('2001-02-03 04:05:06', $this->updatedAt($id));
        self::assertGreaterThan('2020-01-01 00:00:00', $this->updatedAt($id));

        $without = self::migrator(GeneratedColumnIntegrationTestAccountsWithoutOnUpdate::class);

        self::assertSame([false], self::currents($without->status()));
        self::assertSame(
            'table ' . AccountsTable::NAME . ': column updated_at is expected to have no ON UPDATE but the database has ON UPDATE CURRENT_TIMESTAMP; dbDelta does not apply this change, so apply it with a manual ALTER TABLE, or change the definition to match the database (datetime(onUpdateCurrentTimestamp: true) for ON UPDATE CURRENT_TIMESTAMP)',
            self::migrationError(static fn(): array => $without->migrateAll()),
        );
        self::assertSame([false], self::currents($without->status()));
    }

    /**
     * 既にある列にON UPDATEを足すと、dbDeltaは適用せず、検証3が手動のALTERを案内して失敗する。案内どおりに手動の
     * ALTER TABLEを実行してから適用し直すと、適用が通る。
     */
    public function testAddingOnUpdateToExistingColumnNeedsManualAlter(): void
    {
        self::migrator(GeneratedColumnIntegrationTestAccountsWithoutOnUpdate::class)->migrateAll();
        $migrator = self::migrator(AccountsModel::class);

        self::assertSame([false], self::currents($migrator->status()));
        self::assertSame(
            'table ' . AccountsTable::NAME . ': column updated_at is expected to have ON UPDATE CURRENT_TIMESTAMP but the database has no ON UPDATE; dbDelta does not apply this change, so apply it with a manual ALTER TABLE, or change the definition to match the database (datetime(onUpdateCurrentTimestamp: true) for ON UPDATE CURRENT_TIMESTAMP)',
            self::migrationError(static fn(): array => $migrator->migrateAll()),
        );

        $this->connection->execute('ALTER TABLE ' . AccountsModel::tableName() . ' MODIFY COLUMN ' . AccountsModel::table()->columnSql('updated_at'));

        self::assertSame([MigrationAction::Migrated], self::actions($migrator->migrateAll()));
        self::assertSame([true], self::currents($migrator->status()));
    }

    /**
     * DBの通常の列を定義で生成カラムにした場合のMODIFY(実測)。STOREDにはでき、既にある行の値も計算される。VIRTUALには
     * MariaDB(1907)・MySQL(3106)ともできず、MigrationExceptionになってハッシュを更新しない。
     */
    public function testRegularColumnBecomesStoredButNotVirtual(): void
    {
        self::migrator(GeneratedColumnIntegrationTestConvertRegular::class)->migrateAll();
        $this->connection->execute('INSERT INTO ' . GeneratedColumnIntegrationTestConvertRegular::tableName() . ' (val) VALUES (%d), (%d)', [1, 2]);

        $virtual = self::migrator(GeneratedColumnIntegrationTestConvertVirtual::class);
        $message = self::migrationError(static fn(): array => $virtual->migrateAll());

        self::assertStringStartsWith('failed to apply the generated column ' . self::CONVERT_TABLE . '.derived: ', $message);
        self::assertMatchesRegularExpression('/not yet supported for generated columns|Changing the STORED status/', $message);
        self::assertSame([false], self::currents($virtual->status()));

        $stored = self::migrator(GeneratedColumnIntegrationTestConvertStored::class);

        self::assertSame([MigrationAction::Migrated], self::actions($stored->migrateAll()));
        self::assertSame('STORED GENERATED', $this->extra('derived', self::CONVERT_TABLE));
        self::assertSame(['11', '12'], $this->connection->selectCol('SELECT derived FROM ' . GeneratedColumnIntegrationTestConvertStored::tableName() . ' ORDER BY val'));
    }

    /**
     * DEFAULT CURRENT_TIMESTAMPだけの列のExtraは、MariaDBは空、MySQLはDEFAULT_GENERATED(実測)。どちらも生成カラムとも
     * ON UPDATEとも読まず、適用の後のdry-runは変更なしになる(testDryRunApplyDryRunEndsUpToDate)。
     */
    public function testExtraOfDefaultCurrentTimestampColumn(): void
    {
        self::migrator(AccountsModel::class)->migrateAll();
        $version = (string) self::wpdb()->get_var('SELECT VERSION()');

        self::assertSame(str_contains($version, 'MariaDB') ? '' : 'DEFAULT_GENERATED', $this->extra('created_at'), $version);
    }

    /**
     * rawColumn()の既定値とON UPDATEに書いたCURRENT_TIMESTAMPの同義語(NOW()・LOCALTIMESTAMP)は、DBがCURRENT_TIMESTAMP
     * (MariaDB 12.3はDEFAULT LOCALTIMESTAMPをlocaltimestamp())と記録しても、適用の後の照合で食い違いにならない。
     * MariaDB 12.3はON UPDATE LOCALTIMESTAMPを構文の誤りにし、LOCALTIMEをcurtime()と記録するため、ここでは使わない。
     */
    public function testCurrentTimestampSynonymsInRawColumnPassVerification(): void
    {
        $migrator = self::migrator(GeneratedColumnIntegrationTestTimestampSynonyms::class);

        self::assertSame([MigrationAction::Migrated], self::actions($migrator->migrateAll()));
        self::assertSame([MigrationAction::Migrated], self::actions($migrator->migrateAll(force: true)));
        self::assertSame([true], self::currents($migrator->status()));
    }

    /** @param class-string<Model> $model */
    private static function migrator(string $model): Migrator
    {
        $migrator = new Migrator(new Connection(self::wpdb()));
        $migrator->register($model);

        return $migrator;
    }

    /**
     * 生成カラムの無いテーブルに、Modelを通さずに行を入れる(生成カラムを足す前の行)。
     */
    private function insertRaw(string $type, string $status, ?int $corpId, ?int $siteId): int
    {
        return $this->connection->insert(AccountsTable::NAME, ['account_type' => $type, 'status' => $status, 'corp_id' => $corpId, 'site_id' => $siteId]);
    }

    /** @return array{string|null, string|null} corp_admin_active_key と site_role_active_key の値 */
    private function activeKeys(int $id): array
    {
        $row = $this->connection->selectRow('SELECT corp_admin_active_key, site_role_active_key FROM ' . AccountsModel::tableName() . ' WHERE id = %d', [$id]);
        self::assertIsArray($row);
        $corp = $row['corp_admin_active_key'] ?? null;
        $site = $row['site_role_active_key'] ?? null;
        self::assertTrue(($corp === null || \is_string($corp)) && ($site === null || \is_string($site)));

        return [$corp, $site];
    }

    private function updatedAt(int $id): string
    {
        $value = $this->connection->selectVar('SELECT updated_at FROM ' . AccountsModel::tableName() . ' WHERE id = %d', [$id]);
        self::assertIsString($value);

        return $value;
    }

    private function extra(string $column, string $table = AccountsTable::NAME): string
    {
        $row = $this->connection->selectRow('SHOW FULL COLUMNS FROM ' . $this->connection->tableName($table) . ' LIKE %s', [$column]);
        self::assertIsArray($row);
        $extra = $row['Extra'] ?? null;
        self::assertIsString($extra);

        return $extra;
    }

    /** @return list<string> 索引の列(順に) */
    private function indexColumns(string $name): array
    {
        $columns = [];

        foreach ($this->connection->select('SHOW INDEX FROM ' . AccountsModel::tableName() . ' WHERE Key_name = %s', [$name]) as $row) {
            self::assertIsString($row['Column_name'] ?? null);
            $columns[] = $row['Column_name'];
        }

        return $columns;
    }

    /** @return list<string> dbDeltaのdry-runが返す残った差分 */
    private function dbDeltaDryRun(TableDefinition $definition): array
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        return array_values(dbDelta($definition->toSql($this->connection), false));
    }

    /** @param Closure(): mixed $call */
    private static function queryError(Closure $call): string
    {
        return self::thrownMessage($call, QueryException::class);
    }

    /** @param Closure(): mixed $call */
    private static function migrationError(Closure $call): string
    {
        return self::thrownMessage($call, MigrationException::class);
    }

    /**
     * @param Closure(): mixed $call
     * @param class-string<Throwable> $class
     */
    private static function thrownMessage(Closure $call, string $class): string
    {
        try {
            $call();
        } catch (Throwable $thrown) {
            self::assertInstanceOf($class, $thrown, $thrown->getMessage());

            return $thrown->getMessage();
        }

        self::fail("expected {$class}");
    }

    /**
     * @param list<MigrationResult> $results
     *
     * @return list<MigrationAction>
     */
    private static function actions(array $results): array
    {
        return array_map(static fn(MigrationResult $result): MigrationAction => $result->action, $results);
    }

    /**
     * @param list<MigrationStatus> $statuses
     *
     * @return list<bool>
     */
    private static function currents(array $statuses): array
    {
        return array_map(static fn(MigrationStatus $status): bool => $status->current, $statuses);
    }
}

/** 要望の定義から、updated_at の ON UPDATE CURRENT_TIMESTAMP を外したもの。 */
final class GeneratedColumnIntegrationTestAccountsWithoutOnUpdate extends Model
{
    public static function table(): TableDefinition
    {
        return AccountsTable::requested(onUpdate: false);
    }
}

/** 通常の列 derived を持つテーブル(生成カラムに変える前)。 */
final class GeneratedColumnIntegrationTestConvertRegular extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_generated_convert')->id()->int('val')->int('derived', nullable: true);
    }
}

/** derived を VIRTUAL の生成カラムにしたもの。 */
final class GeneratedColumnIntegrationTestConvertVirtual extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_generated_convert')->id()->int('val')->generated('derived', 'int(11)', 'val + 10', GeneratedColumnStorage::Virtual);
    }
}

/** derived を STORED の生成カラムにしたもの。 */
final class GeneratedColumnIntegrationTestConvertStored extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_generated_convert')->id()->int('val')->generated('derived', 'int(11)', 'val + 10', GeneratedColumnStorage::Stored);
    }
}

/** 既定値とON UPDATEに、CURRENT_TIMESTAMPの同義語を書いたもの。 */
final class GeneratedColumnIntegrationTestTimestampSynonyms extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_generated_convert')
            ->id()
            ->rawColumn('now_at', 'datetime NOT NULL DEFAULT NOW() ON UPDATE NOW()')
            ->rawColumn('local_at', 'datetime(6) NOT NULL DEFAULT LOCALTIMESTAMP(6) ON UPDATE NOW(6)')
            ->rawColumn('plain_local_at', 'datetime NULL DEFAULT LOCALTIMESTAMP');
    }
}
