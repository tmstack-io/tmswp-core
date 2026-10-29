<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;
use TMSWP\Core\Exception\MigrationException;
use TMSWP\Core\Form\TempFileGc;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\MigrationAction;
use TMSWP\Core\Model\MigrationResult;
use TMSWP\Core\Model\Migrator;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\QueryBuilder;
use TMSWP\Core\Model\TableDefinition;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\RealTables;
use WP_Error;
use WP_Hook;

/**
 * fixture テーマの Contact(#[AsModel])を、`wp tmswp migrate` が呼ぶ Migrator::migrateAll() で移行し、CRUD を
 * 確かめる。テーマの有効化・管理画面のリクエストではテーブルを変えず、テーブル定義のハッシュが DB と食い違えば
 * 権限のある管理者に通知すること、dbDelta の失敗でハッシュを更新しないことも、実際の DB で確かめる。
 */
final class ModelIntegrationTest extends IntegrationTestCase
{
    private const TABLE = 'tmswp_fixture_contacts';

    /** dbDelta の失敗を再現するテーブル(このファイルの NoteV1・NoteV2 が宣言する) */
    private const NOTES_TABLE = 'tmswp_it_notes';

    private const OPTION = 'tmswp_schema_hashes';

    /** dbDelta が適用しない変更を再現するテーブル(このファイルの Flag* が宣言する) */
    private const FLAGS_TABLE = 'tmswp_it_flags';

    /** 桁の多い数値の既定値の変更を再現するテーブル(このファイルの Big*・Dec* が宣言する) */
    private const BIG_TABLE = 'tmswp_it_big';

    private const DEC_TABLE = 'tmswp_it_dec';

    /** 行の保存と読み取りを確かめるテーブル(このファイルのRecord・TypedRecordが宣言する) */
    private const RECORDS_TABLE = 'tmswp_it_records';

    /** @var list<string> recordQueries() の後に発行された SQL */
    private array $queries = [];

    /** このテストの実際のテーブル(set_upで始める) */
    private RealTables $realTables;

    /**
     * テーブルは実際のテーブルで作る(MySQLのSHOW TABLESは一時テーブルを返さず、Migratorの適用の後の照合を確かめられない
     * ため。IntegrationTestCase::useRealTables())。このファイルのテストが作るテーブルを宣言し、tear_downで消す。DDLは暗黙にコミットするため、
     * Migratorが保存したハッシュのoptionは、ロールバックの後の後始末で消す。各テストは保存済みのハッシュが無い状態から始める。
     */
    public function set_up(): void
    {
        parent::set_up();
        delete_option(self::OPTION);
        $this->realTables = $this->useRealTables(
            self::TABLE,
            'tmswp_fixture_spied',
            self::NOTES_TABLE,
            self::FLAGS_TABLE,
            self::BIG_TABLE,
            self::DEC_TABLE,
            'tmswp_it_words',
            'tmswp_it_precise',
            'tmswp_it_exponent',
            'tmswp_it_rounded',
            self::RECORDS_TABLE,
            'tmswp_it_queue',
            'tmswp_it_translation_group',
            'tmswp_it_translation_relation',
        );
        $this->realTables->onTearDown(static function (): void {
            delete_option(self::OPTION);
        });
    }

    public function tear_down(): void
    {
        Model::useConnection(null);
        parent::tear_down();
    }

    /** テーマの有効化(tmswp/activated)と管理画面のリクエスト(admin_init)では、テーブルを作らない。 */
    public function testActivationAndAdminRequestDoNotMigrate(): void
    {
        $wpdb = self::wpdb();
        $connection = new Connection($wpdb);
        $migrator = $this->themeMigrator();

        // #[AsModel] は after_setup_theme で Migrator に登録済み。テーブルはまだ無い
        self::assertSame([$this->contactClass()], $migrator->models());

        $this->activateFixtureTheme();
        $this->fireAdminInit();

        self::assertNull($connection->selectVar('SHOW TABLES LIKE %s', [$wpdb->prefix . self::TABLE]));
        self::assertFalse($migrator->status()[0]->current);
        self::assertFalse(get_option(self::OPTION));
    }

    /**
     * bootしたKernelについて、MigratorがWordPressのフックに登録したコールバックは、Kernelごとのadmin_noticesの
     * 通知だけである。どのフックのコールバックも、テーブルを変えるMigratorのメソッド(migrateAll()・dropTable())を
     * 呼ばない。WP-CLIのコマンドの登録はフックではないため対象外。
     */
    public function testMigratorHooksOnlyAdminNoticesAndNoHookCallbackChangesTables(): void
    {
        $wpFilter = $GLOBALS['wp_filter'] ?? null;
        self::assertIsArray($wpFilter);
        $migratorCallbacks = [];
        $changingCallbacks = [];

        foreach ($wpFilter as $hook => $wpHook) {
            self::assertInstanceOf(WP_Hook::class, $wpHook);

            foreach ($wpHook->callbacks as $priority => $callbacks) {
                self::assertIsArray($callbacks);

                foreach ($callbacks as $callback) {
                    self::assertIsArray($callback);
                    $reflection = self::callbackReflection($callback['function'] ?? null);
                    $file = $reflection?->getFileName();

                    if ($reflection === null || !\is_string($file)) {
                        continue;
                    }

                    if (self::callbackScope($reflection) === Migrator::class) {
                        $migratorCallbacks[] = [$hook, $priority, $callback['accepted_args'] ?? null];
                    }

                    $source = implode('', \array_slice((array) file($file), (int) $reflection->getStartLine() - 1, (int) $reflection->getEndLine() - (int) $reflection->getStartLine() + 1));

                    if (preg_match('/\b(migrateAll|dropTable)\s*\(/', $source) === 1) {
                        $changingCallbacks[] = "{$hook}: {$file}:{$reflection->getStartLine()}";
                    }
                }
            }
        }

        self::assertCount(2, Kernel::all());
        self::assertSame([['admin_notices', 10, 0], ['admin_notices', 10, 0]], $migratorCallbacks);
        self::assertSame([], $changingCallbacks);
    }

    /**
     * プラグインの有効化(register_activation_hookのフック)とajaxのリクエストでは、テーブルを作らず、保存済みのハッシュの
     * 読み書きもDDLも発行しない。ajaxはadmin-ajax.phpと同じく、wp_doing_ajaxを真にしてadmin_initとwp_ajax_*を
     * 発火する。
     */
    public function testPluginActivationAndAjaxRequestsDoNotMigrate(): void
    {
        $pluginFile = self::pluginKernel()->config()->pluginFile;
        self::assertNotNull($pluginFile);
        $this->assertModelsAreRegisteredWithoutTables();
        $this->recordQueries();

        do_action('activate_' . plugin_basename($pluginFile));

        add_filter('wp_doing_ajax', '__return_true');

        try {
            $this->fireAdminInit();
            do_action('wp_ajax_tmswp_model_probe');
            do_action('wp_ajax_nopriv_tmswp_model_probe');
        } finally {
            remove_filter('wp_doing_ajax', '__return_true');
        }

        $this->assertNothingWasMigrated();
    }

    /**
     * cronのリクエストでは移行しない。wp-cron.phpと同じく、wp_doing_cronを真にして初期化(init・wp_loaded)を通し、
     * 予約されたTMSWPのイベント(一時ファイルの掃除)を実行する。HTTPを伴うWordPress本体のイベントは実行せず、
     * 初期化の中のHTTPの要求(cronの起動等)も止める。
     *
     * テストのプロセスでは、WordPressの読み込みでinit・wp_loadedが発火済みである。このリポジトリのコード(Kernelの
     * エンティティの登録・メタの登録・フックのメソッド等)がinit・wp_loadedに登録したコールバックは、2回目の実行で
     * 登録済みの例外になるため、発火の間だけ外す。Migratorのコールバックは外さない。
     */
    public function testCronRequestDoesNotMigrate(): void
    {
        $this->assertModelsAreRegisteredWithoutTables();
        // 一時ファイルを保管したリクエストと同じく、掃除のイベントを予約しておく
        TempFileGc::schedule();
        $this->recordQueries();
        $blockHttp = static fn(): WP_Error => new WP_Error('tmswp_test_http_blocked', 'HTTP requests are blocked in this test');
        add_filter('wp_doing_cron', '__return_true');
        add_filter('pre_http_request', $blockHttp);

        $removed = $this->removeRepositoryCallbacksExceptMigrator(['init', 'wp_loaded']);

        try {
            do_action('init');
            do_action('wp_loaded');
            $ran = 0;

            // wp-cron.phpと同じく、予約されたイベントを引数とともに実行する(TMSWPのイベントだけ)
            foreach (_get_cron_array() as $events) {
                self::assertIsArray($events);
                $instances = $events[TempFileGc::HOOK] ?? [];
                self::assertIsArray($instances);

                foreach ($instances as $event) {
                    self::assertIsArray($event);
                    self::assertIsArray($event['args'] ?? null);
                    do_action_ref_array(TempFileGc::HOOK, $event['args']);
                    ++$ran;
                }
            }

            self::assertSame(1, $ran);
        } finally {
            remove_filter('wp_doing_cron', '__return_true');
            remove_filter('pre_http_request', $blockHttp);

            foreach ($removed as [$hook, $callback, $priority, $acceptedArgs]) {
                add_action($hook, $callback, $priority, $acceptedArgs);
            }
        }

        $this->assertNothingWasMigrated();
    }

    /**
     * フロントエンドのリクエストでは移行しない。トップページのURLでメインクエリを実行し(parse_request・wp等)、
     * template_redirectまで発火する。リダイレクト(redirect_canonical等のwp_redirect())はwp_redirectのフィルタで
     * 止め、ヘッダーを送るWordPress本体のコールバックは外す。
     */
    public function testFrontendRequestDoesNotMigrate(): void
    {
        $this->assertModelsAreRegisteredWithoutTables();
        $this->recordQueries();
        add_filter('wp_redirect', '__return_false');
        $headerCallbacks = ['rest_output_link_header' => 11, 'wp_shortlink_header' => 11];

        foreach ($headerCallbacks as $callback => $priority) {
            remove_action('template_redirect', $callback, $priority);
        }

        try {
            $this->go_to(home_url('/'));
            do_action('template_redirect');
        } finally {
            remove_filter('wp_redirect', '__return_false');

            foreach ($headerCallbacks as $callback => $priority) {
                add_action('template_redirect', $callback, $priority, 0);
            }
        }

        $this->assertNothingWasMigrated();
    }

    public function testMigrateAllCreatesTableOnceAndModelRunsCrud(): void
    {
        $wpdb = self::wpdb();
        $connection = new Connection($wpdb);
        $contact = $this->contactClass();
        $migrator = $this->themeMigrator();

        self::assertSame([[self::TABLE, MigrationAction::Migrated]], self::actions($migrator->migrateAll()));
        self::assertSame($wpdb->prefix . self::TABLE, $connection->selectVar('SHOW TABLES LIKE %s', [$wpdb->prefix . self::TABLE]));
        self::assertTrue($migrator->status()[0]->current);
        // ハッシュは autoload しない1つのオプションに保存する
        self::assertSame('off', $connection->selectVar("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", [self::OPTION]));

        // 定義が同じなら dbDelta を実行しない(DESCRIBE も CREATE / ALTER も発行しない)
        $this->recordQueries();
        self::assertSame([[self::TABLE, MigrationAction::Skipped]], self::actions($migrator->migrateAll()));
        self::assertSame([], array_values(array_filter($this->queries, static fn(string $query): bool => preg_match('/^\s*(DESCRIBE|CREATE|ALTER)\b/i', $query) === 1)));

        // force は定義が同じでも dbDelta を通す。実際の DB でも、適用後に残る差分は無い
        self::assertSame([[self::TABLE, MigrationAction::Migrated]], self::actions($migrator->migrateAll(force: true)));

        $id = $contact::insert([
            'email' => 'first@example.test',
            'active' => true,
            'meta' => '{"tags":["news"]}',
            'created_at' => '2026-06-13 08:30:00',
            'status' => 'published',
        ]);

        self::assertGreaterThan(0, $id);
        self::assertSame(1, $contact::count());

        $found = $contact::find($id);
        self::assertIsObject($found);
        self::assertSame('first@example.test', $this->objectProperty($found, 'email'));
        // rowClass() を上書きしない Model の行は stdClass で、値は DB 由来の文字列のまま
        self::assertInstanceOf(stdClass::class, $found);
        self::assertSame('{"tags":["news"]}', $this->objectProperty($found, 'meta'));

        self::assertSame(1, $contact::update(['email' => 'updated@example.test'], $id));
        $updated = $contact::find($id);
        self::assertIsObject($updated);
        self::assertSame('updated@example.test', $this->objectProperty($updated, 'email'));

        self::assertSame(1, $contact::delete($id));
        self::assertSame(0, $contact::count());
    }

    /**
     * 前回のデプロイ後の DB と定義が食い違うと、権限のある管理者にだけ管理画面で通知し、管理画面のリクエストでは
     * 適用しない。migrateAll() が列と索引を加え、既存の行を残す。
     */
    public function testChangedDefinitionIsNotifiedToAdministratorAndAppliedByMigrateAll(): void
    {
        $wpdb = self::wpdb();
        $connection = new Connection($wpdb);
        $contact = $this->contactClass();
        $migrator = $this->themeMigrator();

        // 前回のデプロイ後の DB を用意する: 旧定義(phone 列なし)のテーブルと、そのハッシュが保存された状態
        $previous = new Migrator($connection);
        $previous->register(self::fixtureClass(self::THEME_NAMESPACE . '\\Model\\ContactV1', Model::class));
        $previous->migrateAll();
        $id = $contact::insert([
            'email' => 'kept@example.test',
            'active' => false,
            'meta' => '{}',
            'created_at' => '2026-06-13 08:30:00',
            'status' => 'draft',
        ]);

        // Kernel が登録した現在の定義は、保存済みのハッシュと食い違う
        self::assertFalse($migrator->status()[0]->current);

        // 2人の利用者と、管理者の通知の表示でWordPressが保存する更新の確認のサイトのtransientは、後のmigrateAll()の
        // ALTER(暗黙のコミット)で確定するため、テストの後に消す(transientは、テストの前に無かったものだけ)
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        self::assertIsInt($subscriber);
        $administrator = self::factory()->user->create(['role' => 'administrator']);
        self::assertIsInt($administrator);
        $newTransients = array_values(array_filter(
            ['update_core', 'update_plugins', 'update_themes'],
            static fn(string $transient): bool => !self::optionRowExists('_site_transient_' . $transient),
        ));
        $this->realTables->onTearDown(static function () use ($subscriber, $administrator, $newTransients): void {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            self::assertTrue(wp_delete_user($subscriber));
            self::assertTrue(wp_delete_user($administrator));

            foreach ($newTransients as $transient) {
                delete_site_transient($transient);
            }
        });

        wp_set_current_user($subscriber);
        self::assertStringNotContainsString(self::TABLE, $this->adminNotices());

        wp_set_current_user($administrator);
        $notices = $this->adminNotices();
        self::assertStringContainsString('The database tables ' . self::TABLE . ' do not match their TMSWP table definitions. Run wp tmswp migrate to apply the definitions.', $notices);

        $this->fireAdminInit();
        self::assertFalse($migrator->status()[0]->current);
        self::assertNull($connection->selectVar('SHOW COLUMNS FROM ' . $wpdb->prefix . self::TABLE . ' LIKE %s', ['phone']));

        self::assertSame([[self::TABLE, MigrationAction::Migrated]], self::actions($migrator->migrateAll()));
        self::assertTrue($migrator->status()[0]->current);
        self::assertSame('phone', $connection->selectVar('SHOW COLUMNS FROM ' . $wpdb->prefix . self::TABLE . ' LIKE %s', ['phone']));
        $phoneIndex = $connection->selectRow('SHOW INDEX FROM ' . $wpdb->prefix . self::TABLE . ' WHERE Key_name = %s', ['phone_idx']);
        self::assertIsArray($phoneIndex);
        self::assertSame('phone_idx', $phoneIndex['Key_name'] ?? null);
        self::assertStringNotContainsString(self::TABLE, $this->adminNotices());

        // 列の追加後も既存の行は残り、削除できる
        self::assertSame(1, $contact::count());
        self::assertSame(1, $contact::delete($id));
        self::assertSame(0, $contact::count());
    }

    /** dbDelta の ALTER がデータベースのエラーで失敗すると、例外にしてハッシュを更新しない。 */
    public function testDatabaseErrorDuringDbDeltaKeepsStoredHash(): void
    {
        $storedHash = $this->migrateNotesV1();
        // 型を変える ALTER を、存在しない列を指す ALTER に差し替えて失敗させる
        $notesTable = self::wpdb()->prefix . self::NOTES_TABLE;
        $this->rewriteNotesAlter("ALTER TABLE {$notesTable} CHANGE COLUMN `missing` `note` varchar(100) NOT NULL");

        $exception = $this->migrateNotesV2Failing();

        self::assertStringStartsWith('dbDelta failed on table ' . self::NOTES_TABLE . ': ', $exception->getMessage());
        self::assertStringContainsString('missing', $exception->getMessage());
        self::assertSame($storedHash, $this->storedHash(self::NOTES_TABLE));
    }

    /** dbDelta がエラーを残さずに変更を適用しなかった場合も、残った差分を検出してハッシュを更新しない。 */
    public function testRemainingDifferenceAfterDbDeltaKeepsStoredHash(): void
    {
        $storedHash = $this->migrateNotesV1();
        // 型を変える ALTER を、何も変えない文に差し替える(エラーは残らない)
        $this->rewriteNotesAlter('SELECT 1');

        $exception = $this->migrateNotesV2Failing();

        self::assertSame(
            'dbDelta left differences on table ' . self::NOTES_TABLE . ': Changed type of ' . self::wpdb()->prefix . self::NOTES_TABLE . '.note from varchar(50) to varchar(100)'
            . '; if the database records a column type in another notation, write the type in the table definition as the database records it',
            $exception->getMessage(),
        );
        self::assertSame($storedHash, $this->storedHash(self::NOTES_TABLE));
    }

    /**
     * dbDelta は数値の既定値の変更を適用せず、残った差分にも出さない。適用後の構造を定義と照らし合わせて食い違いを
     * 検出し、ハッシュを更新しない(手動の ALTER が要る)。
     */
    public function testDefaultChangeThatDbDeltaDoesNotApplyKeepsStoredHash(): void
    {
        $storedHash = $this->migrateModel(ModelIntegrationTestFlagV1::class, self::FLAGS_TABLE);

        $exception = $this->migrateFailing(ModelIntegrationTestFlagDefaultV2::class);

        self::assertSame(
            'table ' . self::FLAGS_TABLE . ': column enabled is expected to have DEFAULT 1 but the database has DEFAULT 0; dbDelta does not apply this change, so apply it with a manual ALTER TABLE'
            . ', or, if the database stored a rounded value, change the definition to the value the database stores',
            $exception->getMessage(),
        );
        self::assertSame($storedHash, $this->storedHash(self::FLAGS_TABLE));
        $column = self::wpdb()->get_row('SHOW COLUMNS FROM ' . self::wpdb()->prefix . self::FLAGS_TABLE . " LIKE 'enabled'", ARRAY_A);
        self::assertIsArray($column);
        self::assertSame('0', $column['Default']);
    }

    /** dbDelta は NULL の可否の変更も適用しない。食い違いを検出してハッシュを更新しない。 */
    public function testNullabilityChangeThatDbDeltaDoesNotApplyKeepsStoredHash(): void
    {
        $storedHash = $this->migrateModel(ModelIntegrationTestFlagV1::class, self::FLAGS_TABLE);

        $exception = $this->migrateFailing(ModelIntegrationTestFlagNotNullV2::class);

        self::assertSame(
            'table ' . self::FLAGS_TABLE . ': column label is expected to be NOT NULL but the database has NULL; dbDelta does not apply this change, so apply it with a manual ALTER TABLE',
            $exception->getMessage(),
        );
        self::assertSame($storedHash, $this->storedHash(self::FLAGS_TABLE));
    }

    /** 桁の多い整数の既定値の変更も、値を丸めずに比べて検出し、ハッシュを更新しない。 */
    public function testLargeIntegerDefaultChangeIsDetectedWithoutLosingPrecision(): void
    {
        $storedHash = $this->migrateModel(ModelIntegrationTestBigV1::class, self::BIG_TABLE);

        $exception = $this->migrateFailing(ModelIntegrationTestBigV2::class);

        self::assertSame(
            'table ' . self::BIG_TABLE . ': column val is expected to have DEFAULT 9007199254740993 but the database has DEFAULT 9007199254740992; dbDelta does not apply this change, so apply it with a manual ALTER TABLE'
            . ', or, if the database stored a rounded value, change the definition to the value the database stores',
            $exception->getMessage(),
        );
        self::assertSame($storedHash, $this->storedHash(self::BIG_TABLE));
    }

    /** 桁の多い DECIMAL の既定値の変更も、値を丸めずに比べて検出し、ハッシュを更新しない。 */
    public function testLargeDecimalDefaultChangeIsDetectedWithoutLosingPrecision(): void
    {
        $storedHash = $this->migrateModel(ModelIntegrationTestDecV1::class, self::DEC_TABLE);

        $exception = $this->migrateFailing(ModelIntegrationTestDecV2::class);

        self::assertSame(
            'table ' . self::DEC_TABLE . ': column val is expected to have DEFAULT 9007199254740993 but the database has DEFAULT 9007199254740992; dbDelta does not apply this change, so apply it with a manual ALTER TABLE'
            . ', or, if the database stored a rounded value, change the definition to the value the database stores',
            $exception->getMessage(),
        );
        self::assertSame($storedHash, $this->storedHash(self::DEC_TABLE));
    }

    /** 文字列の既定値の中の `NOT NULL` は、列の制約ではない。NULL を許す列として作られ、照合を通る。 */
    public function testStringDefaultContainingConstraintWordsIsMigrated(): void
    {
        $this->migrateModel(ModelIntegrationTestConstraintWords::class, 'tmswp_it_words');

        $column = self::wpdb()->get_row('SHOW FULL COLUMNS FROM ' . self::wpdb()->prefix . "tmswp_it_words LIKE 'label'", ARRAY_A);
        self::assertIsArray($column);
        self::assertSame(['YES', 'NOT NULL'], [$column['Null'], $column['Default']]);
    }

    /**
     * 小数秒の精度を付けた CURRENT_TIMESTAMP を、MariaDB は `current_timestamp(6)` と返す。精度を保って同じものとして
     * 扱い、照合を通る(wp-env の DB は MariaDB)。
     */
    public function testCurrentTimestampWithFractionalPrecisionIsMigrated(): void
    {
        $this->migrateModel(ModelIntegrationTestPreciseTimestamp::class, 'tmswp_it_precise');

        $column = self::wpdb()->get_row('SHOW FULL COLUMNS FROM ' . self::wpdb()->prefix . "tmswp_it_precise LIKE 'created'", ARRAY_A);
        self::assertIsArray($column);
        self::assertIsString($column['Default']);
        self::assertSame('current_timestamp(6)', strtolower($column['Default']));
    }

    /**
     * DB は double の大きな既定値を指数の表記(`1e20`)で返す。指数の無い定義の値と同じ値として扱い、適用してハッシュを
     * 保存する。
     */
    public function testLargeDoubleDefaultReturnedInExponentNotationIsMigrated(): void
    {
        $hash = $this->migrateModel(ModelIntegrationTestLargeDouble::class, 'tmswp_it_exponent');

        self::assertSame(ModelIntegrationTestLargeDouble::table()->hash(new Connection(self::wpdb())), $hash);
        $column = self::wpdb()->get_row('SHOW FULL COLUMNS FROM ' . self::wpdb()->prefix . "tmswp_it_exponent LIKE 'valuecol'", ARRAY_A);
        self::assertIsArray($column);
        self::assertSame('1e20', $column['Default']);
    }

    /** 定義の指数の表記(`1E-3`)を、DB が返す指数の無い表記(`0.001`)と同じ値として扱い、適用してハッシュを保存する。 */
    public function testExponentDefaultInDefinitionIsMigrated(): void
    {
        $hash = $this->migrateModel(ModelIntegrationTestSmallDouble::class, 'tmswp_it_exponent');

        self::assertSame(ModelIntegrationTestSmallDouble::table()->hash(new Connection(self::wpdb())), $hash);
        $column = self::wpdb()->get_row('SHOW FULL COLUMNS FROM ' . self::wpdb()->prefix . "tmswp_it_exponent LIKE 'valuecol'", ARRAY_A);
        self::assertIsArray($column);
        self::assertSame('0.001', $column['Default']);
    }

    /**
     * DB は double で表せない桁の既定値を丸めて記録する(`100000000000000000001` を `1e20`)。食い違いとして例外にして
     * ハッシュを保存せず、ALTER TABLE では直らないため、定義の既定値を DB が記録する値に合わせる案内を含める。
     */
    public function testRoundedDoubleDefaultFailsWithGuidanceToMatchTheDefinition(): void
    {
        $exception = $this->migrateFailing(ModelIntegrationTestRoundedDouble::class);

        self::assertSame(
            'table tmswp_it_rounded: column valuecol is expected to have DEFAULT 100000000000000000001 but the database has DEFAULT 1e20; dbDelta does not apply this change, so apply it with a manual ALTER TABLE'
            . ', or, if the database stored a rounded value, change the definition to the value the database stores',
            $exception->getMessage(),
        );
        $hashes = get_option(self::OPTION);
        self::assertFalse(\is_array($hashes) && \array_key_exists('tmswp_it_rounded', $hashes));
    }

    /**
     * 保存済みのハッシュの読み取りがデータベースのエラーになったら、「ハッシュが無い」として扱わずに例外にする。
     * 失敗した読み取りで WP が書いた「オプションが無い」のキャッシュも残さない。
     */
    public function testHashReadFailureIsAnExceptionAndLeavesNoNotOptionsCache(): void
    {
        $this->migrateNotesV1();
        $wpdb = self::wpdb();
        $migrator = new Migrator(new Connection($wpdb));
        $migrator->register(ModelIntegrationTestNoteV1::class);
        // autoload しないオプションは、オプションごとのキャッシュから消すと、次の読み取りで SELECT を発行する
        wp_cache_delete(self::OPTION, 'options');
        $missingTable = $wpdb->prefix . 'tmswp_no_such_table';
        $failRead = static fn(string $query): string => str_starts_with($query, 'SELECT') && str_contains($query, "'" . self::OPTION . "'")
            ? str_replace($wpdb->options, $missingTable, $query)
            : $query;
        add_filter('query', $failRead);
        $suppressed = $wpdb->suppress_errors();

        try {
            $migrator->status();
            self::fail('MigrationException was not thrown');
        } catch (MigrationException $exception) {
            self::assertStringStartsWith('failed to read the schema hashes: ', $exception->getMessage());
            self::assertStringContainsString('tmswp_no_such_table', $exception->getMessage());
        } finally {
            $wpdb->suppress_errors($suppressed);
            remove_filter('query', $failRead);
        }

        // 読み取りが直れば、保存済みのハッシュを読める
        self::assertTrue($migrator->status()[0]->current);
    }

    /**
     * v0.5.0の受け入れ条件1: 空でないテーブルでfind()・first()・get()が行を返し、DateTime・enum・NULLが正しく保存される。
     */
    public function testRowsAreReturnedFromNonEmptyTableAndDateTimeEnumAndNullAreStored(): void
    {
        $this->recordQueries();
        [$first, $second, $third] = $this->seedRecords();
        $connection = new Connection(self::wpdb());
        $table = self::wpdb()->prefix . self::RECORDS_TABLE;

        // DateTimeは'Y-m-d H:i:s'(タイムゾーンを変換しない)、enumはbacking value(intは%d)、nullはSQLのNULLで
        // 送る。DBは別の形の日時も受け入れて同じ値に直しうるため、送ったSQLと保存された値の両方を確かめる
        self::assertSame([
            "INSERT INTO `{$table}` (`title`, `status`, `priority`, `published_at`, `note`) VALUES ('first', 'draft', 1, NULL, NULL)",
            "INSERT INTO `{$table}` (`title`, `status`, `priority`, `published_at`, `note`) VALUES ('second', 'published', 2, '2026-06-13 09:10:11', 'memo')",
            "INSERT INTO `{$table}` (`title`, `status`, `priority`, `published_at`, `note`) VALUES ('third', 'published', 1, '2026-01-02 03:04:05', NULL)",
        ], array_values(array_filter($this->queries, static fn(string $query): bool => str_starts_with($query, "INSERT INTO `{$table}`"))));
        self::assertSame([
            ['title' => 'first', 'status' => 'draft', 'priority' => '1', 'published_at' => null, 'note' => null],
            ['title' => 'second', 'status' => 'published', 'priority' => '2', 'published_at' => '2026-06-13 09:10:11', 'note' => 'memo'],
            ['title' => 'third', 'status' => 'published', 'priority' => '1', 'published_at' => '2026-01-02 03:04:05', 'note' => null],
        ], $connection->select("SELECT title, status, priority, published_at, note FROM {$table} ORDER BY id"));

        $found = ModelIntegrationTestRecord::find($second);
        self::assertInstanceOf(stdClass::class, $found);
        self::assertSame('second', $this->objectProperty($found, 'title'));
        self::assertNull(ModelIntegrationTestRecord::find($third + 100));

        $firstPublished = ModelIntegrationTestRecord::query()->where('status', ModelIntegrationTestRecordStatus::Published)->orderBy('id')->first();
        self::assertIsObject($firstPublished);
        self::assertSame('second', $this->objectProperty($firstPublished, 'title'));

        $rows = ModelIntegrationTestRecord::query()->orderBy('id')->get();
        self::assertSame(['first', 'second', 'third'], array_map(fn(object $row): mixed => $this->objectProperty($row, 'title'), $rows));
        // 既定の行(stdClass)は、DBのNULLをnullのまま、ほかの値を文字列のまま持つ
        self::assertContainsOnlyInstancesOf(stdClass::class, $rows);
        self::assertSame((string) $first, $this->objectProperty($rows[0], 'id'));
        self::assertSame('1', $this->objectProperty($rows[0], 'priority'));
        self::assertNull($this->objectProperty($rows[0], 'published_at'));
        self::assertNull($this->objectProperty($rows[0], 'note'));
        self::assertSame('2026-06-13 09:10:11', $this->objectProperty($rows[1], 'published_at'));
        self::assertSame('memo', $this->objectProperty($rows[1], 'note'));
        self::assertNull($this->objectProperty($rows[2], 'note'));

        // NULLはcount()のnullとwhereNull()で、enumは値として絞れる
        self::assertSame(1, ModelIntegrationTestRecord::count(['published_at' => null, 'note' => null]));
        self::assertSame(2, ModelIntegrationTestRecord::count(['status' => ModelIntegrationTestRecordStatus::Published]));
        self::assertSame(2, ModelIntegrationTestRecord::query()->whereNull('note')->count());

        // rowClass()のDTOには、DateTimeImmutable・enum・nullに型を強制して返す
        $typed = ModelIntegrationTestTypedRecord::find($second);
        self::assertInstanceOf(ModelIntegrationTestRecordRow::class, $typed);
        self::assertSame($second, $typed->id);
        self::assertSame('2026-06-13 09:10:11', $typed->publishedAt?->format('Y-m-d H:i:s'));
        self::assertSame(ModelIntegrationTestRecordStatus::Published, $typed->status);
        self::assertSame(ModelIntegrationTestRecordPriority::High, $typed->priority);
        self::assertSame('memo', $typed->note);
        $typedFirst = ModelIntegrationTestTypedRecord::query()->orderBy('id')->first();
        self::assertInstanceOf(ModelIntegrationTestRecordRow::class, $typedFirst);
        self::assertNull($typedFirst->publishedAt);
        self::assertNull($typedFirst->note);
        self::assertCount(3, ModelIntegrationTestTypedRecord::query()->get());

        // 代入のnullはSQLのNULL、DateTimeとenumはR3の前処理をした値で更新する
        self::assertSame(1, ModelIntegrationTestRecord::update(['published_at' => null, 'note' => null], $second));
        self::assertSame(1, ModelIntegrationTestRecord::query()->where('id', $first)->update([
            'status' => ModelIntegrationTestRecordStatus::Published,
            'priority' => ModelIntegrationTestRecordPriority::High,
            'published_at' => new DateTimeImmutable('2027-12-31 23:59:59', new DateTimeZone('Asia/Tokyo')),
        ]));
        self::assertSame([
            ['title' => 'first', 'status' => 'published', 'priority' => '2', 'published_at' => '2027-12-31 23:59:59', 'note' => null],
            ['title' => 'second', 'status' => 'published', 'priority' => '2', 'published_at' => null, 'note' => null],
        ], $connection->select("SELECT title, status, priority, published_at, note FROM {$table} WHERE id IN (%d, %d) ORDER BY id", [$first, $second]));
    }

    /**
     * raw断片の`%%`と値の中の`%`を、実際のDBで実行する。toSql()とprepare()は、wpdbのエスケープの文字列を
     * 含まない、実行するとおりのSQLを返す。
     */
    public function testLiteralPercentIsExecutedAndToSqlHasNoPlaceholderEscape(): void
    {
        $wpdb = self::wpdb();
        $this->migrateModel(ModelIntegrationTestRecord::class, self::RECORDS_TABLE);
        Model::useConnection(new Connection($wpdb));
        $pure = ModelIntegrationTestRecord::insert(['title' => '100% pure', 'status' => 'draft', 'priority' => 1]);
        ModelIntegrationTestRecord::insert(['title' => 'plain', 'status' => 'draft', 'priority' => 1]);
        ModelIntegrationTestRecord::insert(['title' => '50% off', 'status' => 'draft', 'priority' => 1]);
        $connection = ModelIntegrationTestRecord::connection();
        $table = $wpdb->prefix . self::RECORDS_TABLE;

        $builder = ModelIntegrationTestRecord::query()
            ->selectRaw('id %% 2 AS odd')
            ->whereLike('title', '%' . $connection->escLike('100%') . '%');
        // toSql()とprepare()はSQLを組み立てるだけで、DBにクエリを発行しない
        $this->recordQueries();
        $sql = $builder->toSql();

        self::assertSame("SELECT id % 2 AS odd FROM {$table} WHERE title LIKE '%100\\\\%%'", $sql);
        self::assertStringNotContainsString($wpdb->placeholder_escape(), $sql);
        self::assertSame("SELECT '100%'", $connection->prepare('SELECT %s', ['100%']));
        self::assertSame([], $this->queries);

        // 実行したSQLはtoSql()と同じ
        self::assertSame([(string) ($pure % 2)], $builder->pluck('odd'));
        self::assertSame([$sql], $this->queries);

        // %%で書いた剰余と、値の中の%
        self::assertSame(['100% pure', '50% off'], ModelIntegrationTestRecord::query()->whereRaw('id %% %d = %d', [2, $pure % 2])->orderBy('id')->pluck('title'));
        self::assertSame(['100% pure', '50% off'], ModelIntegrationTestRecord::query()->whereLike('title', '%' . $connection->escLike('%') . '%')->orderBy('id')->pluck('title'));
        self::assertSame('100% pure%', ModelIntegrationTestRecord::query()->selectRaw('CONCAT(title, %s) AS labeled', ['%'])->where('id', $pure)->value('labeled'));
    }

    /** grouped countとvalue・pluckを、実際のDBで確かめる。 */
    public function testGroupedCountAndValueAndPluckOnRealDatabase(): void
    {
        [$first, $second, $third] = $this->seedRecords();

        // grouped countはグループの数を返し、ORDER BY・LIMIT・OFFSETを捨てる
        self::assertSame(2, ModelIntegrationTestRecord::query()->groupBy('status')->orderByDesc('status')->limit(1)->offset(1)->count());
        self::assertSame(2, ModelIntegrationTestRecord::query()->where('priority', ModelIntegrationTestRecordPriority::Low)->groupBy('status')->count());
        self::assertSame(1, ModelIntegrationTestRecord::query()->where('status', ModelIntegrationTestRecordStatus::Published)->groupBy('status')->count());
        self::assertSame(3, ModelIntegrationTestRecord::query()->orderBy('id')->offset(1)->count());

        // value: 指定の列だけをSELECTし、結果が無ければnull。select()・selectRaw()のSELECT句は保つ
        self::assertSame('second', ModelIntegrationTestRecord::query()->where('id', $second)->value('title'));
        self::assertNull(ModelIntegrationTestRecord::query()->where('id', $third + 100)->value('title'));
        self::assertSame('2', ModelIntegrationTestRecord::query()->selectRaw('MAX(priority) AS top')->value('top'));
        self::assertSame('first', ModelIntegrationTestRecord::query()->alias('r')->select('r.title')->where('r.id', $first)->value('r.title'));

        // pluck: 値のリスト、キーの列を指定すれば連想配列。集計の別名もそのまま取り出せる
        self::assertSame(['first', 'second', 'third'], ModelIntegrationTestRecord::query()->orderBy('id')->pluck('title'));
        self::assertSame([$first => 'first', $second => 'second', $third => 'third'], ModelIntegrationTestRecord::query()->orderBy('id')->pluck('title', 'id'));
        self::assertSame(
            ['draft' => '1', 'published' => '2'],
            ModelIntegrationTestRecord::query()->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->orderBy('status')->pluck('total', 'status'),
        );
        self::assertTrue(ModelIntegrationTestRecord::query()->where('status', ModelIntegrationTestRecordStatus::Draft)->exists());
    }

    /**
     * 攻撃的な値は、すべての終端メソッドでwpdb::prepare()がエスケープした文字列リテラルとしてSQLに入る
     * (queryフィルタで、実行したSQLを確かめる)。
     */
    public function testHostileValueIsBoundAsLiteralInEveryTerminalQuery(): void
    {
        $this->seedRecords();
        $query = static fn(): QueryBuilder => ModelIntegrationTestRecord::query()->where('title', "x' OR 1=1 --");

        $this->recordQueries();
        self::assertSame([], $query()->get());
        self::assertNull($query()->first());
        self::assertNull($query()->value('title'));
        self::assertSame([], $query()->pluck('title'));
        self::assertSame(0, $query()->count());
        self::assertFalse($query()->exists());
        self::assertSame(0, $query()->update(['note' => 'changed']));
        self::assertSame(0, $query()->delete());

        self::assertCount(8, $this->queries);
        self::assertCount(8, array_filter($this->queries, static fn(string $sql): bool => str_contains($sql, "title = 'x\\' OR 1=1 --'")));
        self::assertSame(3, ModelIntegrationTestRecord::count());
        self::assertSame(2, ModelIntegrationTestRecord::count(['note' => null]));
    }

    /** 空のinsert()はINSERT INTO {テーブル} () VALUES ()を発行し、AUTO_INCREMENTで採番したinsert_idを返す。 */
    public function testEmptyInsertIssuesInsertWithEmptyColumnListAndReturnsInsertId(): void
    {
        $this->migrateModel(ModelIntegrationTestTranslationGroup::class, 'tmswp_it_translation_group');
        Model::useConnection(new Connection(self::wpdb()));
        $table = self::wpdb()->prefix . 'tmswp_it_translation_group';
        $this->recordQueries();

        $first = ModelIntegrationTestTranslationGroup::insert([]);
        self::assertSame(self::wpdb()->insert_id, $first);
        $second = ModelIntegrationTestTranslationGroup::insert([]);
        self::assertSame(self::wpdb()->insert_id, $second);

        self::assertGreaterThan(0, $first);
        self::assertGreaterThan($first, $second);
        self::assertSame(
            ["INSERT INTO {$table} () VALUES ()", "INSERT INTO {$table} () VALUES ()"],
            array_values(array_filter($this->queries, static fn(string $query): bool => str_starts_with($query, 'INSERT'))),
        );
        self::assertSame(2, ModelIntegrationTestTranslationGroup::count());
    }

    /**
     * docs/06の利用例2(キュー)と利用例3(空行のINSERT・自己結合・WPコアのテーブルとのLEFT JOIN・集計)が、実際のDBで
     * 動く。
     */
    public function testQueueAndTranslationExamplesOfTheDesignRunOnRealDatabase(): void
    {
        $this->migrateModel(ModelIntegrationTestQueue::class, 'tmswp_it_queue');
        $this->migrateModel(ModelIntegrationTestTranslationGroup::class, 'tmswp_it_translation_group');
        $this->migrateModel(ModelIntegrationTestTranslationRelation::class, 'tmswp_it_translation_relation');
        Model::useConnection(new Connection(self::wpdb()));

        // 例2: キュー
        $older = ModelIntegrationTestQueue::insert(['url' => 'https://example.test/a', 'handler' => 'export', 'queued_at' => '2026-06-13 08:00:00', 'completed' => false]);
        $newer = ModelIntegrationTestQueue::insert(['url' => 'https://example.test/b', 'handler' => 'export', 'queued_at' => '2026-06-13 09:00:00', 'completed' => false]);
        ModelIntegrationTestQueue::insert(['url' => 'https://example.test/c', 'handler' => 'import', 'queued_at' => '2026-06-13 07:00:00', 'completed' => false]);

        $next = ModelIntegrationTestQueue::nextPending('export');
        self::assertIsObject($next);
        self::assertSame((string) $older, $this->objectProperty($next, 'id'));
        self::assertSame(0, ModelIntegrationTestQueue::progressPercentage('export'));
        self::assertSame(1, ModelIntegrationTestQueue::markCompleted($older));
        $next = ModelIntegrationTestQueue::nextPending('export');
        self::assertIsObject($next);
        self::assertSame((string) $newer, $this->objectProperty($next, 'id'));
        self::assertSame(50, ModelIntegrationTestQueue::progressPercentage('export'));
        self::assertSame(100, ModelIntegrationTestQueue::progressPercentage('missing'));
        self::assertSame(2, ModelIntegrationTestQueue::clearHandler('export'));
        self::assertSame(1, ModelIntegrationTestQueue::count());

        // 例3: 空行のINSERTで採番し、翻訳の組を登録する
        $postGroup = ModelIntegrationTestTranslationGroup::create();
        $termGroup = ModelIntegrationTestTranslationGroup::create();
        self::assertGreaterThan($postGroup, $termGroup);
        $termJa = self::factory()->term->create(['taxonomy' => 'category', 'name' => 'ニュース']);
        $termEn = self::factory()->term->create(['taxonomy' => 'category', 'name' => 'News']);
        self::assertIsInt($termJa);
        self::assertIsInt($termEn);
        ModelIntegrationTestTranslationRelation::insert(['group_id' => $postGroup, 'object_id' => 10, 'object_type' => 'post', 'language_code' => 'ja']);
        ModelIntegrationTestTranslationRelation::insert(['group_id' => $postGroup, 'object_id' => 20, 'object_type' => 'post', 'language_code' => 'en']);
        ModelIntegrationTestTranslationRelation::insert(['group_id' => $termGroup, 'object_id' => $termJa, 'object_type' => 'term', 'language_code' => 'ja']);
        ModelIntegrationTestTranslationRelation::insert(['group_id' => $termGroup, 'object_id' => $termEn, 'object_type' => 'term', 'language_code' => 'en']);

        self::assertSame(20, ModelIntegrationTestTranslationRelation::translatedObjectId(10, 'post', 'en'));
        self::assertSame($termJa, ModelIntegrationTestTranslationRelation::translatedObjectId($termEn, 'term', 'ja'));
        self::assertNull(ModelIntegrationTestTranslationRelation::translatedObjectId(10, 'post', 'fr'));

        // 言語がjaの行のobject_id(順序は決まらないため、並べてから比べる)
        $jaObjectIds = ModelIntegrationTestTranslationRelation::termIdsForLanguage('ja');
        $expected = ['10', (string) $termJa];
        sort($jaObjectIds);
        sort($expected);
        self::assertSame($expected, $jaObjectIds);

        $counts = ModelIntegrationTestTranslationRelation::countsByLanguage();
        ksort($counts);
        self::assertSame(['en' => '2', 'ja' => '2'], $counts);
    }

    /**
     * Recordのテーブルを作り、3行を入れて、その主キーを返す。DateTimeはタイムゾーンの違う2つ、enumは文字列と整数の
     * 2種類、nullは2つの列に入れる。
     *
     * @return array{int, int, int}
     */
    private function seedRecords(): array
    {
        $this->migrateModel(ModelIntegrationTestRecord::class, self::RECORDS_TABLE);
        Model::useConnection(new Connection(self::wpdb()));

        return [
            ModelIntegrationTestRecord::insert([
                'title' => 'first',
                'status' => ModelIntegrationTestRecordStatus::Draft,
                'priority' => ModelIntegrationTestRecordPriority::Low,
                'published_at' => null,
                'note' => null,
            ]),
            ModelIntegrationTestRecord::insert([
                'title' => 'second',
                'status' => ModelIntegrationTestRecordStatus::Published,
                'priority' => ModelIntegrationTestRecordPriority::High,
                'published_at' => new DateTimeImmutable('2026-06-13 09:10:11', new DateTimeZone('Asia/Tokyo')),
                'note' => 'memo',
            ]),
            ModelIntegrationTestRecord::insert([
                'title' => 'third',
                'status' => ModelIntegrationTestRecordStatus::Published,
                'priority' => ModelIntegrationTestRecordPriority::Low,
                'published_at' => new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('UTC')),
                'note' => null,
            ]),
        ];
    }

    /** NoteV1 のテーブルを作り、保存されたハッシュを返す。 */
    private function migrateNotesV1(): string
    {
        return $this->migrateModel(ModelIntegrationTestNoteV1::class, self::NOTES_TABLE);
    }

    /** NoteV2(note 列を varchar(100) に広げた定義)の移行が MigrationException になることを確かめ、その例外を返す。 */
    private function migrateNotesV2Failing(): MigrationException
    {
        return $this->migrateFailing(ModelIntegrationTestNoteV2::class);
    }

    /**
     * $model のテーブルを作り(または定義を適用し)、保存されたハッシュを返す。
     *
     * @param class-string<Model> $model
     */
    private function migrateModel(string $model, string $table): string
    {
        $migrator = new Migrator(new Connection(self::wpdb()));
        $migrator->register($model);
        self::assertSame([[$table, MigrationAction::Migrated]], self::actions($migrator->migrateAll()));

        return $this->storedHash($table);
    }

    /**
     * $model の移行が MigrationException になり、$model の定義が保存済みのハッシュと食い違ったままであることを
     * 確かめ、その例外を返す。
     *
     * @param class-string<Model> $model
     */
    private function migrateFailing(string $model): MigrationException
    {
        $migrator = new Migrator(new Connection(self::wpdb()));
        $migrator->register($model);
        // 失敗させた ALTER のエラーを wpdb が画面に出さないようにする(last_error には残る)
        $suppressed = self::wpdb()->suppress_errors();

        try {
            $migrator->migrateAll();
        } catch (MigrationException $exception) {
            self::assertFalse($migrator->status()[0]->current);

            return $exception;
        } finally {
            self::wpdb()->suppress_errors($suppressed);
        }

        self::fail('MigrationException was not thrown');
    }

    /** NOTES_TABLE への ALTER を $replacement に差し替える(フィルタは tear_down で WP のテストライブラリが戻す)。 */
    private function rewriteNotesAlter(string $replacement): void
    {
        $notesTable = self::wpdb()->prefix . self::NOTES_TABLE;

        add_filter('query', static fn(string $query): string => str_starts_with($query, "ALTER TABLE {$notesTable} ") ? $replacement : $query);
    }

    private function storedHash(string $table): string
    {
        $hashes = get_option(self::OPTION);
        self::assertIsArray($hashes);
        $hash = $hashes[$table] ?? null;
        self::assertIsString($hash);

        return $hash;
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

    /** 両方のKernelにModelが登録済みで、テーブルとハッシュがまだ無いことを確かめる。 */
    private function assertModelsAreRegisteredWithoutTables(): void
    {
        $spied = self::fixtureClass(self::PLUGIN_NAMESPACE . '\\Model\\SpiedModel', Model::class);
        self::assertSame([$this->contactClass()], $this->themeMigrator()->models());
        self::assertSame([$spied], self::pluginKernel()->container()->get(Migrator::class)->models());
        $this->assertTablesAndHashesAreAbsent();
    }

    /** recordQueries()の後にDDLもハッシュのオプションの読み書きも発行されず、テーブルとハッシュが無いことを確かめる。 */
    private function assertNothingWasMigrated(): void
    {
        self::assertSame([], array_values(array_filter(
            $this->queries,
            static fn(string $query): bool => preg_match('/^\s*(DESCRIBE|CREATE|ALTER|SHOW)\b/i', $query) === 1 || str_contains($query, self::OPTION),
        )));
        $this->assertTablesAndHashesAreAbsent();
    }

    private function assertTablesAndHashesAreAbsent(): void
    {
        $wpdb = self::wpdb();
        $connection = new Connection($wpdb);
        self::assertNull($connection->selectVar('SHOW TABLES LIKE %s', [$wpdb->prefix . self::TABLE]));
        self::assertNull($connection->selectVar('SHOW TABLES LIKE %s', [$wpdb->prefix . 'tmswp_fixture_spied']));
        self::assertFalse(get_option(self::OPTION));
    }

    /**
     * $hooksから、このリポジトリのファイルで定義したコールバックをMigratorのものを除いて外し、外したものを
     * [フック, コールバック, priority, 引数の数]の形で返す。
     *
     * @param list<string> $hooks
     *
     * @return list<array{string, callable, int, int}>
     */
    private function removeRepositoryCallbacksExceptMigrator(array $hooks): array
    {
        $root = \dirname(__DIR__, 4) . DIRECTORY_SEPARATOR;
        $removed = [];

        $wpFilter = $GLOBALS['wp_filter'] ?? null;
        self::assertIsArray($wpFilter);

        foreach ($hooks as $hook) {
            $wpHook = $wpFilter[$hook] ?? null;
            self::assertInstanceOf(WP_Hook::class, $wpHook);

            foreach ($wpHook->callbacks as $priority => $callbacks) {
                self::assertIsArray($callbacks);

                foreach ($callbacks as $callback) {
                    self::assertIsArray($callback);
                    $function = $callback['function'] ?? null;
                    $reflection = self::callbackReflection($function);
                    $file = $reflection?->getFileName();

                    if ($reflection === null || !\is_string($file) || !str_starts_with($file, $root) || self::callbackScope($reflection) === Migrator::class) {
                        continue;
                    }

                    $acceptedArgs = $callback['accepted_args'] ?? null;
                    self::assertIsCallable($function);
                    self::assertIsInt($acceptedArgs);
                    $removed[] = [$hook, $function, (int) $priority, $acceptedArgs];
                }
            }
        }

        self::assertNotSame([], $removed);

        foreach ($removed as [$hook, $function, $priority]) {
            remove_action($hook, $function, $priority);
        }

        return $removed;
    }

    /** コールバックが属するクラス(メソッドの宣言のクラス・クロージャのスコープ)。無ければnull。 */
    private static function callbackScope(ReflectionFunction|ReflectionMethod $reflection): ?string
    {
        $scope = $reflection instanceof ReflectionMethod ? $reflection->getDeclaringClass() : $reflection->getClosureScopeClass();

        return $scope?->getName();
    }

    /** フックのコールバックの関数・メソッドのリフレクション。呼び出せる形でなければnull。 */
    private static function callbackReflection(mixed $callback): ReflectionFunction|ReflectionMethod|null
    {
        if ($callback instanceof Closure || (\is_string($callback) && \function_exists($callback))) {
            return new ReflectionFunction($callback);
        }

        if (\is_string($callback) && str_contains($callback, '::') && class_exists(explode('::', $callback)[0])) {
            return ReflectionMethod::createFromMethodName($callback);
        }

        // 読み込まれていないWordPress本体のクラスのメソッド(例: Language_Pack_Upgrader)は、TMSWPのコードではないため除く
        if (\is_array($callback) && \count($callback) === 2 && (\is_object($callback[0]) || (\is_string($callback[0]) && class_exists($callback[0]))) && \is_string($callback[1])) {
            return new ReflectionMethod($callback[0], $callback[1]);
        }

        if (\is_object($callback) && method_exists($callback, '__invoke')) {
            return new ReflectionMethod($callback, '__invoke');
        }

        return null;
    }

    /** これから発行される SQL を $this->queries に控える。 */
    private function recordQueries(): void
    {
        add_filter('query', function (string $query): string {
            $this->queries[] = $query;

            return $query;
        });
    }

    /** @return class-string<Model> */
    private function contactClass(): string
    {
        return self::fixtureClass(self::THEME_NAMESPACE . '\\Model\\Contact', Model::class);
    }

    /** 管理画面の admin_notices を発火し、出力を返す。 */
    private function adminNotices(): string
    {
        ob_start();
        do_action('admin_notices');

        return (string) ob_get_clean();
    }

    /**
     * optionの行があるか(オブジェクトキャッシュを通さずに読む)。読み取りの失敗を、行が無いことと取り違えないよう、
     * SQLのエラーはテストの失敗にする。
     */
    private static function optionRowExists(string $name): bool
    {
        $wpdb = self::wpdb();
        $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE option_name = %s', $wpdb->options, $name));
        self::assertSame('', $wpdb->last_error, "cannot read the option {$name}");

        return $count !== '0';
    }

    /**
     * テーマを有効化した直後のリクエストを再現する。switch_theme() が前のリクエストで残す theme_switched を置き、
     * WordPress がそのリクエストの init で呼ぶ check_theme_switched() から after_switch_theme を発火させる。
     */
    private function activateFixtureTheme(): void
    {
        update_option('theme_switched', 'twentytwentyfive');
        check_theme_switched();
    }

    /**
     * 管理画面のリクエストを再現して admin_init を発火する。wp-admin/admin.php と同じく管理画面用の関数を読み込み、
     * 現在の画面を管理画面にする(is_admin() が真になる)。管理画面用の HTTP ヘッダーを送る WP コアの
     * コールバック2つは、出力を始めた後の CLI ではヘッダーを送れないため外す(外したフックは tear_down で元に戻る)。
     */
    private function fireAdminInit(): void
    {
        require_once ABSPATH . 'wp-admin/includes/admin.php';
        set_current_screen('dashboard');
        remove_action('admin_init', 'wp_admin_headers');
        remove_action('admin_init', 'send_frame_options_header', 10);

        do_action('admin_init');
    }

    private function themeMigrator(): Migrator
    {
        return self::themeKernel()->container()->get(Migrator::class);
    }

    private function objectProperty(object $object, string $name): mixed
    {
        if (!property_exists($object, $name)) {
            self::fail("object property was not found: {$name}");
        }

        return (new ReflectionProperty($object, $name))->getValue($object);
    }
}

/** dbDelta の失敗の再現に使う、前回のデプロイ時点の定義。 */
final class ModelIntegrationTestNoteV1 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_notes')->id()->string('note', 50);
    }
}

/** NoteV1 の note 列を広げた定義。dbDelta は列の型を変える ALTER を発行する。 */
final class ModelIntegrationTestNoteV2 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_notes')->id()->string('note', 100);
    }
}

/** dbDelta が適用しない変更の再現に使う、前回のデプロイ時点の定義。 */
final class ModelIntegrationTestFlagV1 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_flags')->id()->bool('enabled')->string('label', 20, nullable: true);
    }
}

/** FlagV1 の enabled 列の既定値だけを変えた定義。 */
final class ModelIntegrationTestFlagDefaultV2 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_flags')->id()->bool('enabled', true)->string('label', 20, nullable: true);
    }
}

/** FlagV1 の label 列を NOT NULL にした定義。 */
final class ModelIntegrationTestFlagNotNullV2 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_flags')->id()->bool('enabled')->string('label', 20);
    }
}

/** 桁の多い整数の既定値の変更の再現に使う、前回のデプロイ時点の定義。 */
final class ModelIntegrationTestBigV1 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_big')->id()->bigint('val', default: 9007199254740992);
    }
}

/** BigV1 の既定値を1だけ増やした定義(float では区別できない)。 */
final class ModelIntegrationTestBigV2 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_big')->id()->bigint('val', default: 9007199254740993);
    }
}

/** 桁の多い DECIMAL の既定値の変更の再現に使う、前回のデプロイ時点の定義。 */
final class ModelIntegrationTestDecV1 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_dec')->id()->decimal('val', 30, 0, default: '9007199254740992');
    }
}

/** DecV1 の既定値を1だけ増やした定義(float では区別できない)。 */
final class ModelIntegrationTestDecV2 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_dec')->id()->decimal('val', 30, 0, default: '9007199254740993');
    }
}

/** 既定値の文字列に列の制約の語を含む定義。 */
final class ModelIntegrationTestConstraintWords extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_words')->id()->string('label', 20, nullable: true, default: 'NOT NULL');
    }
}

/** 小数秒の精度を付けた CURRENT_TIMESTAMP を既定値に持つ定義。 */
final class ModelIntegrationTestPreciseTimestamp extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_precise')->id()->rawColumn('created', 'timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)');
    }
}

/** 大きな既定値を指数の無い表記で書いた double の列。DB は `1e20` と返す。 */
final class ModelIntegrationTestLargeDouble extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_exponent')->id()->rawColumn('valuecol', 'double NOT NULL DEFAULT 100000000000000000000');
    }
}

/** 既定値を指数の表記で書いた double の列。DB は `0.001` と返す。 */
final class ModelIntegrationTestSmallDouble extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_exponent')->id()->rawColumn('valuecol', 'double DEFAULT 1E-3');
    }
}

/** double で表せない桁の既定値を書いた列。DB は丸めて `1e20` と記録する。 */
final class ModelIntegrationTestRoundedDouble extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_rounded')->id()->rawColumn('valuecol', 'double NOT NULL DEFAULT 100000000000000000001');
    }
}

/** 行の保存と読み取りに使う定義。published_atとnoteはNULLを許す。 */
final class ModelIntegrationTestRecord extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_records')
            ->id()
            ->string('title', 100)
            ->string('status', 20)
            ->int('priority')
            ->datetime('published_at', nullable: true)
            ->text('note');
    }
}

/** Recordと同じテーブルの行を、rowClass()のDTOで受け取る定義。 */
final class ModelIntegrationTestTypedRecord extends Model
{
    public static function table(): TableDefinition
    {
        return ModelIntegrationTestRecord::table();
    }

    /** @return class-string<ModelIntegrationTestRecordRow> */
    public static function rowClass(): string
    {
        return ModelIntegrationTestRecordRow::class;
    }
}

enum ModelIntegrationTestRecordStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

enum ModelIntegrationTestRecordPriority: int
{
    case Low = 1;
    case High = 2;
}

/** Recordの行のDTO。 */
final readonly class ModelIntegrationTestRecordRow
{
    public function __construct(
        public int $id,
        public string $title,
        public ModelIntegrationTestRecordStatus $status,
        public ModelIntegrationTestRecordPriority $priority,
        public ?DateTimeImmutable $publishedAt,
        public ?string $note,
    ) {}
}

/** docs/06の利用例2のキュー(テーブル名だけを変えた)。 */
final class ModelIntegrationTestQueue extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_queue')
            ->id()
            ->string('object_type', 40, nullable: true)
            ->int('object_id', nullable: true, unsigned: true)
            ->text('url')
            ->text('output_path', nullable: true)
            ->string('handler', 100)
            ->datetime('queued_at')
            ->bool('completed')
            ->index(['handler', 'completed']);
    }

    /** 次に処理すべきキューを1件取得 */
    public static function nextPending(string $handler): ?object
    {
        return self::query()
            ->where('handler', $handler)
            ->where('completed', false)
            ->orderBy('queued_at')
            ->first();
    }

    /** 処理済みフラグの更新 */
    public static function markCompleted(int $id): int
    {
        return self::update(['completed' => true], $id);
    }

    /** ハンドラ単位の全消去 */
    public static function clearHandler(string $handler): int
    {
        return self::query()->where('handler', $handler)->delete();
    }

    /** 進捗率 */
    public static function progressPercentage(string $handler): int
    {
        $total = self::count(['handler' => $handler]);
        $done = self::count(['handler' => $handler, 'completed' => 1]);

        return $total > 0 ? (int) (round($done / $total, 2) * 100) : 100;
    }
}

/** docs/06の利用例3の翻訳の組(テーブル名だけを変えた)。 */
final class ModelIntegrationTestTranslationGroup extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_translation_group')->id();
    }

    /** AUTO_INCREMENTの採番だけの空行のINSERT */
    public static function create(): int
    {
        return self::insert([]);
    }
}

/** docs/06の利用例3の翻訳の関係(テーブル名だけを変えた)。 */
final class ModelIntegrationTestTranslationRelation extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_translation_relation')
            ->id()
            ->foreignId('group_id')
            ->bigint('object_id', unsigned: true)
            ->string('object_type', 20)
            ->string('language_code', 10)
            ->index(['object_id', 'object_type', 'language_code']);
    }

    /** ある投稿の翻訳先のobject_id(自己結合) */
    public static function translatedObjectId(int $objectId, string $objectType, string $language): ?int
    {
        $value = self::query()
            ->alias('src')
            ->select('dst.object_id')
            ->innerJoinRaw(
                'tmswp_it_translation_relation AS dst',
                'dst.group_id = src.group_id AND dst.language_code = %s',
                [$language],
            )
            ->where('src.object_id', $objectId)
            ->where('src.object_type', $objectType)
            ->value('object_id');

        // wpdbは値を文字列で返し、行が無ければnull
        return \is_string($value) ? (int) $value : null;
    }

    /**
     * WPコアのテーブルとのLEFT JOIN。ON句への条件の追加をleftJoinRawで書く。
     *
     * @return array<int|string, mixed>
     */
    public static function termIdsForLanguage(string $language): array
    {
        return self::query()
            ->alias('trl')
            ->leftJoinRaw(
                'terms AS t',
                't.term_id = trl.object_id AND trl.object_type = %s',
                ['term'],
            )
            ->where(static function (QueryBuilder $q) use ($language): void {
                $q->where('trl.language_code', $language)
                    ->orWhere('trl.language_code', null);
            })
            ->pluck('object_id');
    }

    /**
     * 言語別の登録件数(集計)
     *
     * @return array<int|string, mixed>
     */
    public static function countsByLanguage(): array
    {
        return self::query()
            ->selectRaw('language_code, COUNT(*) AS total')
            ->groupBy('language_code')
            ->pluck('total', 'language_code');
    }
}
