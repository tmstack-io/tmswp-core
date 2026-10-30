<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Model;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\MigrateCommand;
use TMSWP\Core\Model\Migrator;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;

/**
 * `wp tmswp migrate [--force] [--dry-run]` の出力(06-model §6)。WP_CLI の代役クラスと wpdb の出力形式定数を
 * 読み込むため、他のテストに残さないよう別プロセスで動かす。代役は呼び出しを $GLOBALS['tmswp_test_wp_cli'] に
 * 記録し、WP_CLI::error() は終了の代わりに例外を投げる。
 */
final class MigrateCommandTest extends UnitTestCase
{
    private const string USAGE = 'Usage: wp tmswp migrate [--force] [--dry-run]';

    private WpdbTestDouble $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
        $GLOBALS['tmswp_test_wp_cli'] = [];
        $this->wpdb = WpdbTestDouble::create();
        // 保存済みのハッシュの読み書きは、グローバルの $wpdb でデータベースのエラーを確かめる
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb'], $GLOBALS['wp_version'], $GLOBALS['wp_actions'], $GLOBALS['tmswp_test_wp_cli']);
        Kernel::reset();
        parent::tearDown();
    }

    #[RunInSeparateProcess]
    public function testDryRunReportsChangedAndUpToDateTablesWithoutWriting(): void
    {
        $this->loadWpCliDouble();
        $this->bootKernelWithModels([MigrateCommandTestNoteModel::class, MigrateCommandTestTagModel::class]);
        Functions\when('get_option')->justReturn(['tmswp_tags' => $this->hashOf(MigrateCommandTestTagModel::class)]);
        Functions\expect('update_option')->never();
        Functions\expect('add_option')->never();

        (new MigrateCommand())([], ['dry-run' => true]);

        self::assertSame([
            ['log', 'tmswp_notes: changed'],
            ['log', 'tmswp_tags: up-to-date'],
        ], $GLOBALS['tmswp_test_wp_cli']);
        self::assertSame([], $this->wpdb->queries());
    }

    #[RunInSeparateProcess]
    public function testItMigratesChangedTablesAndSkipsUpToDateOnes(): void
    {
        $this->loadWpCliDouble();
        WpdbTestDouble::defineOutputConstants();
        $this->bootKernelWithModels([MigrateCommandTestNoteModel::class, MigrateCommandTestTagModel::class]);
        $tagHash = $this->hashOf(MigrateCommandTestTagModel::class);
        $noteSql = MigrateCommandTestNoteModel::table()->toSql(new Connection($this->wpdb));
        Functions\when('get_option')->justReturn(['tmswp_tags' => $tagHash]);
        // 適用(execute=true)の後に、同じ定義で dry-run(execute=false)を呼んで残った差分を確かめる
        Functions\expect('dbDelta')->twice()->andReturnUsing(static function (string $sql, bool $execute = true) use ($noteSql): array {
            self::assertSame($noteSql, $sql);

            return $execute ? ['wp_tmswp_notes' => 'Created table wp_tmswp_notes'] : [];
        });
        Functions\expect('update_option')->once()
            ->with('tmswp_schema_hashes', ['tmswp_tags' => $tagHash, 'tmswp_notes' => $this->hashOf(MigrateCommandTestNoteModel::class)], false)
            ->andReturn(true);
        // 適用後の構造の確認(SHOW TABLES・SHOW FULL COLUMNS・SHOW INDEX)の結果
        $this->wpdb->queueVar('wp_tmswp_notes');
        $this->wpdb->queueResultSet([
            ['Field' => 'id', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'],
            ['Field' => 'note', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
        ]);
        $this->wpdb->queueResultSet([['Key_name' => 'PRIMARY', 'Column_name' => 'id', 'Seq_in_index' => '1']]);

        (new MigrateCommand())([], []);

        self::assertSame([
            ['log', 'Migrated: tmswp_notes'],
            ['log', 'Skipped: tmswp_tags (up to date)'],
            ['success', '1 table(s) migrated, 1 skipped.'],
        ], $GLOBALS['tmswp_test_wp_cli']);
    }

    /** dbDelta の後に last_error が残れば、ハッシュを更新せずに非0で終わる。 */
    #[RunInSeparateProcess]
    public function testDatabaseErrorAfterDbDeltaFailsWithoutSavingHash(): void
    {
        $this->loadWpCliDouble();
        $this->bootKernelWithModels([MigrateCommandTestNoteModel::class]);
        $wpdb = $this->wpdb;
        Functions\when('get_option')->justReturn([]);
        Functions\expect('dbDelta')->once()->andReturnUsing(static function () use ($wpdb): array {
            $wpdb->last_error = "Duplicate column name 'note'";

            return ['wp_tmswp_notes.note' => 'Added column wp_tmswp_notes.note'];
        });
        Functions\expect('update_option')->never();
        Functions\expect('add_option')->never();

        self::assertSame("dbDelta failed on table tmswp_notes: Duplicate column name 'note'", $this->runFailing([], []));
    }

    /** dbDelta の後に残った差分があれば、ハッシュを更新せずに非0で終わる。 */
    #[RunInSeparateProcess]
    public function testRemainingDifferenceAfterDbDeltaFailsWithoutSavingHash(): void
    {
        $this->loadWpCliDouble();
        $this->bootKernelWithModels([MigrateCommandTestNoteModel::class]);
        Functions\when('get_option')->justReturn([]);
        // 適用しても型の変更が反映されず、dry-run が同じ差分を返し続ける
        Functions\expect('dbDelta')->twice()->andReturn(['wp_tmswp_notes.note' => 'Changed type of wp_tmswp_notes.note from varchar(50) to varchar(191)']);
        Functions\expect('update_option')->never();
        Functions\expect('add_option')->never();

        self::assertSame(
            'dbDelta left differences on table tmswp_notes: Changed type of wp_tmswp_notes.note from varchar(50) to varchar(191)'
            . '; if the database records a column type in another notation, write the type in the table definition as the database records it',
            $this->runFailing([], []),
        );
    }

    #[RunInSeparateProcess]
    public function testItWarnsWhenNoKernelHasModels(): void
    {
        $this->loadWpCliDouble();
        $this->bootKernelWithModels([]);
        Functions\expect('get_option')->never();

        (new MigrateCommand())([], []);

        self::assertSame([['warning', 'No models registered.']], $GLOBALS['tmswp_test_wp_cli']);
    }

    #[RunInSeparateProcess]
    public function testItRejectsUnknownArgumentsAndForceWithDryRun(): void
    {
        $this->loadWpCliDouble();

        self::assertSame(self::USAGE, $this->runFailing(['contacts'], []));
        self::assertSame(self::USAGE, $this->runFailing([], ['check' => true]));
        self::assertSame('--force and --dry-run cannot be used together', $this->runFailing([], ['force' => true, 'dry-run' => true]));
    }

    /**
     * WP-CLI は `--no-force` を force=false として渡す。false は指定なしとして扱い、適用済みのテーブルを強制的に
     * 適用しない。`--no-dry-run` も dry-run にしない。
     */
    #[RunInSeparateProcess]
    public function testNegatedFlagsAreTreatedAsNotGiven(): void
    {
        $this->loadWpCliDouble();
        $this->bootKernelWithModels([MigrateCommandTestTagModel::class]);
        Functions\when('get_option')->justReturn(['tmswp_tags' => $this->hashOf(MigrateCommandTestTagModel::class)]);
        Functions\expect('update_option')->never();

        (new MigrateCommand())([], ['force' => false, 'dry-run' => false]);

        self::assertSame([
            ['log', 'Skipped: tmswp_tags (up to date)'],
            ['success', '0 table(s) migrated, 1 skipped.'],
        ], $GLOBALS['tmswp_test_wp_cli']);
    }

    /** フラグは値を取らない。`--force=0` 等の値は、適用も dry-run もせずにエラーにする。 */
    #[RunInSeparateProcess]
    public function testFlagValuesAreRejected(): void
    {
        $this->loadWpCliDouble();

        self::assertSame('--force does not take a value', $this->runFailing([], ['force' => '0']));
        self::assertSame('--dry-run does not take a value', $this->runFailing([], ['dry-run' => '1']));
        self::assertSame('--force does not take a value', $this->runFailing([], ['force' => 'yes', 'dry-run' => true]));
    }

    /**
     * Kernel を boot し、Container の Migrator を、WpdbTestDouble の Connection に $models を登録したものにする。
     *
     * @param list<class-string<Model>> $models
     */
    private function bootKernelWithModels(array $models): void
    {
        $appDir = $this->createTempDirectory('migrate-command') . '/app';
        self::assertTrue(mkdir($appDir));
        $kernel = Kernel::boot(KernelConfig::theme($appDir, 'Example\Tests\MigrateCommand' . uniqid(), true));
        $migrator = new Migrator(new Connection($this->wpdb));

        foreach ($models as $model) {
            $migrator->register($model);
        }

        $kernel->container()->set(Migrator::class, $migrator);
    }

    /** @param class-string<Model> $model */
    private function hashOf(string $model): string
    {
        return $model::table()->hash(new Connection($this->wpdb));
    }

    /**
     * コマンドを実行し、WP_CLI::error() に渡されたメッセージを返す。
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    private function runFailing(array $args, array $assocArgs): string
    {
        try {
            (new MigrateCommand())($args, $assocArgs);
        } catch (RuntimeException $exception) {
            $calls = $GLOBALS['tmswp_test_wp_cli'];
            self::assertIsArray($calls);
            self::assertSame(['error', $exception->getMessage()], end($calls));

            return $exception->getMessage();
        }

        self::fail('WP_CLI::error() was not called');
    }

    private function loadWpCliDouble(): void
    {
        $file = $this->createTempDirectory('wp-cli-double') . '/WP_CLI.php';
        self::assertNotFalse(file_put_contents($file, <<<'PHP'
<?php

declare(strict_types=1);

final class WP_CLI
{
    public static function log(string $message): void
    {
        $GLOBALS['tmswp_test_wp_cli'][] = ['log', $message];
    }

    public static function warning(string $message): void
    {
        $GLOBALS['tmswp_test_wp_cli'][] = ['warning', $message];
    }

    public static function success(string $message): void
    {
        $GLOBALS['tmswp_test_wp_cli'][] = ['success', $message];
    }

    public static function error(string $message): never
    {
        $GLOBALS['tmswp_test_wp_cli'][] = ['error', $message];

        throw new \RuntimeException($message);
    }
}
PHP));

        require $file;
    }
}

final class MigrateCommandTestNoteModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_notes')->id()->string('note');
    }
}

final class MigrateCommandTestTagModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_tags')->id();
    }
}
