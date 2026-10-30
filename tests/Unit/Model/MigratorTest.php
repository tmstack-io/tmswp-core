<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Model;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Closure;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TMSWP\Core\Exception\MigrationException;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\MigrationAction;
use TMSWP\Core\Model\Migrator;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;

/**
 * Migrator の管理画面の通知(06-model §5)。テーブル定義のハッシュが DB(保存済みのハッシュ)と食い違うと、権限のある
 * 管理者に admin_notices で知らせるだけで、テーブルを変えない。失敗の扱いは listen() に渡した Kernel の debug で決める。
 */
final class MigratorTest extends UnitTestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb'], $GLOBALS['wp_version'], $GLOBALS['wp_actions']);
        Kernel::reset();
        parent::tearDown();
    }

    public function testNoticeIsSkippedForUserWithoutCapability(): void
    {
        $notice = $this->bootWithMigrator(false, [MigratorTestNoteModel::class]);
        Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(false);
        Functions\expect('get_option')->never();
        Functions\expect('wp_admin_notice')->never();

        $notice();
    }

    public function testNoticeListsTablesWhoseDefinitionDiffersWithoutChangingTheDatabase(): void
    {
        $wpdb = WpdbTestDouble::create();
        $notice = $this->bootWithMigrator(false, [MigratorTestNoteModel::class, MigratorTestTagModel::class], $wpdb);
        Functions\when('current_user_can')->justReturn(true);
        Functions\expect('get_option')->once()->with('tmswp_schema_hashes')->andReturn([
            'tmswp_notes' => 'stale',
            'tmswp_tags' => MigratorTestTagModel::table()->hash(new Connection($wpdb)),
        ]);
        Functions\expect('update_option')->never();
        Functions\expect('add_option')->never();
        Functions\expect('wp_admin_notice')->once()->with(
            'The database tables tmswp_notes do not match their TMSWP table definitions. Run wp tmswp migrate to apply the definitions.',
            ['type' => 'error'],
        );

        $notice();

        // dbDelta(DESCRIBE 等)を含め、DB に何も問い合わせない
        self::assertSame([], $wpdb->queries());
    }

    public function testNoticeIsNotShownWhenAllDefinitionsMatch(): void
    {
        $wpdb = WpdbTestDouble::create();
        $notice = $this->bootWithMigrator(false, [MigratorTestTagModel::class], $wpdb);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->justReturn(['tmswp_tags' => MigratorTestTagModel::table()->hash(new Connection($wpdb))]);
        Functions\expect('wp_admin_notice')->never();

        $notice();
    }

    /** Model の無い Kernel は、保存済みのハッシュ(オプション)も読まない。 */
    public function testNoticeReadsNothingWithoutModels(): void
    {
        $notice = $this->bootWithMigrator(false, []);
        Functions\when('current_user_can')->justReturn(true);
        Functions\expect('get_option')->never();
        Functions\expect('wp_admin_notice')->never();

        $notice();
    }

    /**
     * 本番では、通知の確認の失敗を Kernel のログに記録して通知をやめる。文脈には例外のクラス名と投げられた場所だけを
     * 入れる。
     */
    public function testNoticeFailureIsLoggedWithoutExceptionDetailsInProduction(): void
    {
        $notice = $this->bootWithMigrator(false, [MigratorTestNoteModel::class]);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->justReturn('broken');
        Functions\expect('wp_admin_notice')->never();

        $notice();

        self::assertCount(1, $this->logger->records);
        self::assertSame('error', $this->logger->records[0]['level']);
        self::assertSame('tmswp.model: failed to compare the table definitions with the database', $this->logger->records[0]['message']);
        $context = $this->logger->records[0]['context'];
        self::assertSame(['exceptionClass', 'exceptionAt', 'previousClasses'], array_keys($context));
        self::assertSame(MigrationException::class, $context['exceptionClass']);
        self::assertIsString($context['exceptionAt']);
        self::assertMatchesRegularExpression('#/src/Model/Migrator\.php:\d+$#', $context['exceptionAt']);
        self::assertSame([], $context['previousClasses']);
    }

    /** debug の判定は WP_DEBUG ではなく、listen() に渡した Kernel の KernelConfig::$debug で行う。 */
    public function testNoticeFailureIsThrownWhenKernelIsInDebugWithoutWpDebug(): void
    {
        self::assertFalse(\defined('WP_DEBUG'));
        $notice = $this->bootWithMigrator(true, [MigratorTestNoteModel::class]);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->justReturn('broken');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('schema hash option must be an array');

        try {
            $notice();
        } finally {
            self::assertSame([], $this->logger->records);
        }
    }

    /**
     * dbDeltaの後にlast_errorが残ればMigrationExceptionになり、ハッシュを保存しない。後のテーブルは処理しない
     * (docs/06 R4・§5のエッジケース)。
     */
    #[RunInSeparateProcess]
    public function testDatabaseErrorAfterDbDeltaThrowsMigrationExceptionWithoutSavingHash(): void
    {
        $wpdb = WpdbTestDouble::create();
        $GLOBALS['wpdb'] = $wpdb;
        $migrator = new Migrator(new Connection($wpdb));
        $migrator->register(MigratorTestNoteModel::class);
        $migrator->register(MigratorTestTagModel::class);
        Functions\when('get_option')->justReturn([]);
        Functions\expect('dbDelta')->once()->andReturnUsing(static function () use ($wpdb): array {
            $wpdb->last_error = "Duplicate column name 'note'";

            return [];
        });
        Functions\expect('update_option')->never();
        Functions\expect('add_option')->never();

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage("dbDelta failed on table tmswp_notes: Duplicate column name 'note'");

        $migrator->migrateAll();
    }

    /** 保存済みのハッシュのオプションが配列でなければ、移行も状態の確認もMigrationExceptionになる。 */
    #[RunInSeparateProcess]
    public function testBrokenHashOptionThrowsMigrationException(): void
    {
        $wpdb = WpdbTestDouble::create();
        $GLOBALS['wpdb'] = $wpdb;
        $migrator = new Migrator(new Connection($wpdb));
        $migrator->register(MigratorTestNoteModel::class);
        Functions\when('get_option')->justReturn('broken');
        Functions\expect('dbDelta')->never();
        $thrown = [];

        foreach (['migrateAll' => static fn(): array => $migrator->migrateAll(), 'status' => static fn(): array => $migrator->status()] as $label => $call) {
            try {
                $call();
                $thrown[$label] = null;
            } catch (MigrationException $exception) {
                $thrown[$label] = $exception->getMessage();
            }
        }

        self::assertSame(['migrateAll' => 'schema hash option must be an array', 'status' => 'schema hash option must be an array'], $thrown);
    }

    /**
     * @return iterable<string, array{TableDefinition, string|null, bool}> val 列の定義、DB が SHOW FULL COLUMNS で返す
     *                                                                     既定値、同じとみなすか
     */
    public static function defaults(): iterable
    {
        $table = static fn(): TableDefinition => TableDefinition::create('tmswp_defaults')->id();

        yield '数値: 1 と 1.00 は同じ' => [$table()->decimal('val', default: '1'), '1.00', true];
        yield '数値: -0 と 0 は同じ' => [$table()->rawColumn('val', 'decimal(10,2) NOT NULL DEFAULT -0'), '0.00', true];
        yield '数値: 先頭の0は除く' => [$table()->rawColumn('val', 'int(11) NOT NULL DEFAULT 007'), '7', true];
        yield '数値: 負の小数' => [$table()->decimal('val', default: '-0.50'), '-0.5', true];
        yield '数値: 大きな整数の1の違いは別' => [$table()->bigint('val', default: 9007199254740993), '9007199254740992', false];
        yield '数値: 桁の多い decimal の1の違いは別' => [$table()->decimal('val', 30, 0, default: '9007199254740993'), '9007199254740992', false];
        yield '数値: 符号の違いは別' => [$table()->int('val', default: -1), '1', false];
        yield '指数: 1e20 と 100000000000000000000 は同じ' => [$table()->rawColumn('val', 'double NOT NULL DEFAULT 100000000000000000000'), '1e20', true];
        yield '指数: 1E-3 と 0.001 は同じ' => [$table()->rawColumn('val', 'double NOT NULL DEFAULT 1E-3'), '0.001', true];
        yield '指数: 1e-3 と 0.001 は同じ' => [$table()->rawColumn('val', 'double NOT NULL DEFAULT 1e-3'), '0.001', true];
        yield '指数: 1e+20 と 1e20 は同じ' => [$table()->rawColumn('val', 'double NOT NULL DEFAULT 1e+20'), '1e20', true];
        yield '指数: 1e0 と 1 は同じ' => [$table()->rawColumn('val', 'int(11) NOT NULL DEFAULT 1e0'), '1', true];
        yield '指数: 1e0 と 1.00 は同じ' => [$table()->rawColumn('val', 'decimal(10,2) NOT NULL DEFAULT 1e0'), '1.00', true];
        yield '指数: 1e3 と 1000 は同じ' => [$table()->rawColumn('val', 'float NOT NULL DEFAULT 1e3'), '1000', true];
        yield '指数: 0e5 と 0 は同じ' => [$table()->rawColumn('val', 'double NOT NULL DEFAULT 0e5'), '0', true];
        yield '指数: 係数の小数と負の指数' => [$table()->rawColumn('val', 'double NOT NULL DEFAULT -1.50e-2'), '-0.015', true];
        yield '指数: 1e20 と 1e21 は別' => [$table()->rawColumn('val', 'double NOT NULL DEFAULT 1e20'), '1e21', false];
        yield '指数: 1.5e1 と 16 は別' => [$table()->rawColumn('val', 'double NOT NULL DEFAULT 1.5e1'), '16', false];
        yield '指数: 大きな整数の1の違いは別' => [$table()->bigint('val', default: 9007199254740993), '9.007199254740992e15', false];
        yield '文字列: 001 と 1 は別' => [$table()->string('val', default: '001'), '1', false];
        yield '文字列: 0 と空は別' => [$table()->string('val', default: '0'), '', false];
        yield '文字列: 同じ値' => [$table()->string('val', nullable: true, default: 'NOT NULL'), 'NOT NULL', true];
        yield '文字列: 既定値なしと空は別' => [$table()->string('val'), '', false];
        yield '日時: CURRENT_TIMESTAMP と current_timestamp() は同じ' => [$table()->datetime('val', default: 'CURRENT_TIMESTAMP'), 'current_timestamp()', true];
        yield '日時: 精度を保って同じ' => [$table()->rawColumn('val', 'timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)'), 'current_timestamp(6)', true];
        yield '日時: 精度の違いは別' => [$table()->rawColumn('val', 'timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)'), 'current_timestamp()', false];
        yield '日時: 精度 0 は精度なしと同じ' => [$table()->rawColumn('val', 'timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP(0)'), 'current_timestamp()', true];
        yield '日時: NOW() と current_timestamp() は同じ' => [$table()->rawColumn('val', 'datetime NOT NULL DEFAULT NOW()'), 'current_timestamp()', true];
        yield '日時: LOCALTIMESTAMP と CURRENT_TIMESTAMP は同じ' => [$table()->rawColumn('val', 'datetime NOT NULL DEFAULT LOCALTIMESTAMP'), 'CURRENT_TIMESTAMP', true];
        yield '日時: NOW(6) と current_timestamp(6) は同じ' => [$table()->rawColumn('val', 'datetime(6) NOT NULL DEFAULT NOW(6)'), 'current_timestamp(6)', true];
        yield '日時: LOCALTIMESTAMP と MariaDB 12.3 の localtimestamp() は同じ' => [$table()->rawColumn('val', 'datetime NOT NULL DEFAULT LOCALTIMESTAMP'), 'localtimestamp()', true];
        yield '日時: LOCALTIME と MariaDB 12.3 の curtime() は別' => [$table()->rawColumn('val', 'datetime NOT NULL DEFAULT LOCALTIME'), 'curtime()', false];
        yield '日時: 括弧の無い now と current_timestamp() は別' => [$table()->rawColumn('val', 'datetime NOT NULL DEFAULT now'), 'current_timestamp()', false];
        yield '日時: nowx と current_timestamp() は別' => [$table()->rawColumn('val', 'datetime NOT NULL DEFAULT nowx'), 'current_timestamp()', false];
        yield '日時: NOW(6) と current_timestamp() は別' => [$table()->rawColumn('val', 'datetime(6) NOT NULL DEFAULT NOW(6)'), 'current_timestamp()', false];
        yield '日時: 日時の値はそのまま' => [$table()->datetime('val', default: '2026-01-01 00:00:00'), '2026-01-01 00:00:00', true];
    }

    /**
     * 適用後の既定値の照合は、列の種類ごとに DB の表記の違いを除いて比べる(06-model §5)。数値は丸めずに比べ、文字列は
     * そのまま比べ、日時は CURRENT_TIMESTAMP の表記をそろえる。食い違えばハッシュを保存せずに例外にする。
     */
    #[RunInSeparateProcess]
    #[DataProvider('defaults')]
    public function testAppliedDefaultIsComparedByColumnKind(TableDefinition $definition, ?string $actual, bool $same): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        $GLOBALS['wpdb'] = $wpdb;
        MigratorTestDefaultsModel::$definition = $definition;
        $migrator = new Migrator(new Connection($wpdb));
        $migrator->register(MigratorTestDefaultsModel::class);
        Functions\when('get_option')->justReturn([]);
        Functions\when('dbDelta')->justReturn([]);
        Functions\expect('update_option')->times($same ? 1 : 0)->andReturn(true);
        $wpdb->queueVar('wp_tmswp_defaults');
        $wpdb->queueResultSet([
            ['Field' => 'id', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'],
            ['Field' => 'val', 'Null' => $definition->columnNullable('val') ? 'YES' : 'NO', 'Default' => $actual, 'Extra' => ''],
        ]);
        $wpdb->queueResultSet([['Key_name' => 'PRIMARY', 'Column_name' => 'id', 'Seq_in_index' => '1']]);

        if (!$same) {
            $this->expectException(MigrationException::class);
            $this->expectExceptionMessageMatches('/\Atable tmswp_defaults: column val is expected to have .+ but the database has .+; dbDelta does not apply this change/');
        }

        self::assertSame(MigrationAction::Migrated, $migrator->migrateAll()[0]->action);
    }

    /**
     * Kernel を boot し、Migrator(WpdbTestDouble の Connection に Model を登録したもの)を Container に置いて、
     * Kernel が登録した admin_notices のコールバックを返す。
     *
     * @param list<class-string<Model>> $models
     *
     * @return Closure(): void
     */
    private function bootWithMigrator(bool $debug, array $models, ?WpdbTestDouble $wpdb = null): Closure
    {
        $notice = null;
        Actions\expectAdded('admin_notices')->once()->with(Mockery::type(Closure::class), 10, 0)
            ->whenHappen(static function (Closure $callback) use (&$notice): void {
                $notice = $callback;
            });
        $appDir = $this->createTempDirectory('migrator-notice') . '/app';
        self::assertTrue(mkdir($appDir));
        $logger = $this->logger;
        $config = KernelConfig::theme($appDir, 'Example\Tests\MigratorNotice' . uniqid(), $debug, logChannels: static fn(): array => ['test' => new LogChannel($logger)]);

        if (!$debug) {
            // 本番の boot は探索結果ファイルを読むため、空の結果を置く
            (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
        }

        $kernel = Kernel::boot($config);
        $wpdb ??= WpdbTestDouble::create();
        // 保存済みのハッシュの読み取りは、グローバルの $wpdb でデータベースのエラーを確かめる
        $GLOBALS['wpdb'] = $wpdb;
        $migrator = new Migrator(new Connection($wpdb));

        foreach ($models as $model) {
            $migrator->register($model);
        }

        $kernel->container()->set(Migrator::class, $migrator);
        self::assertInstanceOf(Closure::class, $notice);

        return $notice;
    }
}

final class MigratorTestNoteModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_notes')->id()->string('note');
    }
}

final class MigratorTestTagModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_tags')->id();
    }
}

/** 既定値の照合のテストが、データごとに定義を差し替える Model。 */
final class MigratorTestDefaultsModel extends Model
{
    public static TableDefinition $definition;

    public static function table(): TableDefinition
    {
        return self::$definition;
    }
}
