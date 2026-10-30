<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use RuntimeException;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Tests\Support\AccountsBaseModel;
use TMSWP\Core\Tests\Support\AccountsChangedExpressionModel;
use TMSWP\Core\Tests\Support\AccountsModel;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\WpCliPharDeprecations;

/**
 * `wp tmswp migrate`を、実物のWP-CLIのプロセスでwp-envの開発用サイトに対して実行して確かめる
 * (docs/acceptance/v0.5.0.md 5.4の手作業の確認を書き起こしたもの)。
 *
 * サブプロセスは`--require=tests/fixtures/_wp-cli.php`で、そのプロセスの中だけfixtureのテーマとプラグインを有効にする
 * (`composer discover:check`と同じ方法)。対象はfixtureの2つのModel(プラグインの`SpiedModel`のテーブル
 * `tmswp_fixture_spied`、テーマの`Contact`のテーブル`tmswp_fixture_contacts`)で、Kernelのboot順(プラグイン →
 * テーマ)に処理される。
 *
 * サブプロセスは統合テストのテーブル(wptests_)ではなく、開発用サイトのテーブル(wp_)を使う。開始の時点で
 * 2つのテーブルと`tmswp_schema_hashes`のoptionが無いことを確かめ、テストの後に両方を消して、その状態に戻す。
 * 生成カラムのテストがサブプロセスでプラグインのKernelに足すModelのテーブル(`tmswp_it_accounts`)も同じく扱う。
 */
final class MigrateCommandIntegrationTest extends IntegrationTestCase
{
    private const array TABLES = ['tmswp_fixture_spied', 'tmswp_fixture_contacts'];

    /** set_upで開発用サイトが初期の状態だと確かめたか(確かめていなければ、tear_downで何も消さない) */
    private bool $cleanState = false;

    public function set_up(): void
    {
        parent::set_up();

        $state = self::devSiteState();
        self::assertSame(
            ['tables' => [], 'hashes' => null],
            $state,
            'the development site already has the fixture tables or the tmswp_schema_hashes option; remove them before running this test',
        );
        $this->cleanState = true;
    }

    public function tear_down(): void
    {
        try {
            if ($this->cleanState) {
                $this->cleanState = false;
                $result = self::wp(['eval', <<<'PHP'
                    global $wpdb;
                    foreach (['tmswp_fixture_spied', 'tmswp_fixture_contacts', 'tmswp_it_accounts'] as $table) {
                        $wpdb->query('DROP TABLE IF EXISTS `' . $wpdb->prefix . $table . '`');
                    }
                    delete_option('tmswp_schema_hashes');
                    PHP], fixtures: false);
                self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
                self::assertSame(['tables' => [], 'hashes' => null], self::devSiteState());
            }
        } finally {
            parent::tear_down();
        }
    }

    /**
     * docs/06の受け入れ基準「`wp tmswp migrate` / `--force` / `--dry-run`が§6の出力どおり動作する(ユニットテストと
     * wp-envでの実行)」と、docs/12のv0.5.0の受け入れ条件「マイグレーションは`wp tmswp migrate`だけで適用され」を確かめる。
     * `--dry-run`は食い違うテーブルを`changed`と出してテーブルを作らず、実行で両方が作られてハッシュが1つのoption
     * (autoloadしない)に保存され、2回目はskipし、`--force`はハッシュが同じでも適用し直す。否定のフラグは指定なしと同じ。
     */
    public function testDryRunMigrateRerunAndForcePrintTheDocumentedOutput(): void
    {
        $dryRun = self::wp(['tmswp', 'migrate', '--dry-run']);

        self::assertSame(0, $dryRun['code'], $dryRun['stdout'] . $dryRun['stderr']);
        self::assertSame("tmswp_fixture_spied: changed\ntmswp_fixture_contacts: changed\n", $dryRun['stdout']);
        self::assertSame([], self::devSiteState()['tables']);

        $migrate = self::wp(['tmswp', 'migrate']);

        self::assertSame(0, $migrate['code'], $migrate['stdout'] . $migrate['stderr']);
        self::assertSame(
            "Migrated: tmswp_fixture_spied\nMigrated: tmswp_fixture_contacts\nSuccess: 2 table(s) migrated, 0 skipped.\n",
            $migrate['stdout'],
        );
        $state = self::devSiteState();
        self::assertSame(self::TABLES, $state['tables']);
        self::assertIsArray($state['hashes']);
        self::assertSame(self::TABLES, array_keys($state['hashes']));
        self::assertSame('off', self::hashOptionAutoload());

        $rerun = self::wp(['tmswp', 'migrate']);

        self::assertSame(0, $rerun['code'], $rerun['stdout'] . $rerun['stderr']);
        self::assertSame(
            "Skipped: tmswp_fixture_spied (up to date)\nSkipped: tmswp_fixture_contacts (up to date)\nSuccess: 0 table(s) migrated, 2 skipped.\n",
            $rerun['stdout'],
        );

        $upToDate = self::wp(['tmswp', 'migrate', '--dry-run']);

        self::assertSame(0, $upToDate['code'], $upToDate['stdout'] . $upToDate['stderr']);
        self::assertSame("tmswp_fixture_spied: up-to-date\ntmswp_fixture_contacts: up-to-date\n", $upToDate['stdout']);

        $force = self::wp(['tmswp', 'migrate', '--force']);

        self::assertSame(0, $force['code'], $force['stdout'] . $force['stderr']);
        self::assertSame(
            "Migrated: tmswp_fixture_spied\nMigrated: tmswp_fixture_contacts\nSuccess: 2 table(s) migrated, 0 skipped.\n",
            $force['stdout'],
        );

        foreach (['--no-force', '--no-dry-run'] as $negated) {
            $result = self::wp(['tmswp', 'migrate', $negated]);

            self::assertSame(0, $result['code'], $negated . "\n" . $result['stdout'] . $result['stderr']);
            self::assertSame(
                "Skipped: tmswp_fixture_spied (up to date)\nSkipped: tmswp_fixture_contacts (up to date)\nSuccess: 0 table(s) migrated, 2 skipped.\n",
                $result['stdout'],
                $negated,
            );
        }

        self::assertSame($state['hashes'], self::devSiteState()['hashes']);
    }

    /**
     * docs/06 §6の、フラグは値を取らず、`--force`と`--dry-run`は併用できないことを確かめる。値付きのフラグと併用は、
     * エラーで終了コード1になり、テーブルもハッシュも作らない(余分な引数は、コマンドより先にWP-CLIがdocblockの
     * 書式で拒否するため、ここでは扱わない)。
     */
    public function testFlagValuesForceWithDryRunAndExtraArgumentsExitWithOneWithoutMigrating(): void
    {
        $cases = [
            'Error: --force does not take a value' => [['--force=0'], ['--force=1']],
            'Error: --dry-run does not take a value' => [['--dry-run=yes']],
            'Error: --force and --dry-run cannot be used together' => [['--force', '--dry-run'], ['--dry-run', '--force']],
        ];

        foreach ($cases as $message => $argumentLists) {
            foreach ($argumentLists as $arguments) {
                $result = self::wp(['tmswp', 'migrate', ...$arguments]);
                $label = implode(' ', $arguments);

                self::assertSame(1, $result['code'], $label . "\n" . $result['stdout'] . $result['stderr']);
                self::assertSame('', $result['stdout'], $label);
                self::assertSame($message . "\n", $result['stderr'], $label);
            }
        }

        self::assertSame(['tables' => [], 'hashes' => null], self::devSiteState());
    }

    /**
     * docs/06の受け入れ基準「`MigrationException`は`WP_CLI::error()`で非0終了する」と、§5の、適用に失敗したテーブルの
     * ハッシュを更新しないことを確かめる。テーマのテーブルの`phone`(定義はNULLを許す)をNOT NULLに変えておくと、
     * dbDeltaはNULLの可否を変えないため、適用の後の構造の照合で`MigrationException`になる。先に処理したプラグインの
     * テーブルのハッシュだけが保存され、成功の行は出ない。
     */
    public function testMigrationExceptionExitsNonZeroAndKeepsTheHashOfTheFailedTableUnsaved(): void
    {
        $first = self::wp(['tmswp', 'migrate']);
        self::assertSame(0, $first['code'], $first['stdout'] . $first['stderr']);
        $alter = self::wp(['eval', <<<'PHP'
            global $wpdb;
            $table = $wpdb->prefix . 'tmswp_fixture_contacts';
            $column = $wpdb->get_row("SHOW FULL COLUMNS FROM `{$table}` LIKE 'phone'");
            if (!is_object($column) || $wpdb->query("ALTER TABLE `{$table}` MODIFY `phone` {$column->Type} NOT NULL") === false) {
                WP_CLI::error('cannot make phone NOT NULL: ' . $wpdb->last_error);
            }
            delete_option('tmswp_schema_hashes');
            PHP], fixtures: false);
        self::assertSame(0, $alter['code'], $alter['stdout'] . $alter['stderr']);

        $result = self::wp(['tmswp', 'migrate']);

        self::assertSame(1, $result['code'], $result['stdout'] . $result['stderr']);
        self::assertSame("Migrated: tmswp_fixture_spied\n", $result['stdout']);
        self::assertStringStartsWith(
            'Error: table tmswp_fixture_contacts: column phone is expected to be NULL but the database has NOT NULL; dbDelta does not apply this change',
            $result['stderr'],
        );
        $hashes = self::devSiteState()['hashes'];
        self::assertIsArray($hashes);
        self::assertSame(['tmswp_fixture_spied'], array_keys($hashes));
    }

    /**
     * docs/12のv0.5.0の受け入れ条件「食い違いは管理者に通知され、自動適用されない」を、実際のWordPressの起動で
     * 確かめる。定義と食い違うテーブルは`manage_options`を持つ管理者にだけ`admin_notices`で知らされ、その起動と
     * 通知ではテーブルが作られない。`wp tmswp migrate`で適用した後は、通知が消える。
     */
    public function testAdministratorIsNotifiedUntilMigrateAppliesAndNothingIsMigratedAutomatically(): void
    {
        $notices = self::adminNotices('admin');

        self::assertSame(0, $notices['code'], $notices['stdout'] . $notices['stderr']);
        self::assertSame(2, substr_count($notices['stdout'], 'Run wp tmswp migrate to apply the definitions.'), $notices['stdout']);
        self::assertStringContainsString('The database tables tmswp_fixture_spied do not match their TMSWP table definitions.', $notices['stdout']);
        self::assertStringContainsString('The database tables tmswp_fixture_contacts do not match their TMSWP table definitions.', $notices['stdout']);
        self::assertStringContainsString('notice-error', $notices['stdout']);

        $anonymous = self::adminNotices(null);

        self::assertSame(0, $anonymous['code'], $anonymous['stdout'] . $anonymous['stderr']);
        self::assertSame('', $anonymous['stdout']);
        self::assertSame(['tables' => [], 'hashes' => null], self::devSiteState());

        $migrate = self::wp(['tmswp', 'migrate']);
        self::assertSame(0, $migrate['code'], $migrate['stdout'] . $migrate['stderr']);

        $after = self::adminNotices('admin');

        self::assertSame(0, $after['code'], $after['stdout'] . $after['stderr']);
        self::assertSame('', $after['stdout']);
    }

    /**
     * fixtureを有効にしたWordPressで、指定した利用者として`admin_notices`を発火し、その出力を返す。
     *
     * @return array{code: int, stdout: string, stderr: string, removedDeprecations: int}
     */
    private static function adminNotices(?string $user): array
    {
        $arguments = $user === null ? [] : ['--user=' . $user];

        return self::wp([...$arguments, 'eval', 'do_action("admin_notices");']);
    }

    /**
     * 生成カラムを持つ要望の定義のテーブルを、`wp tmswp migrate`のコマンドとして適用する(Model層の設計書の「生成カラムと
     * ON UPDATEの適用と照合」)。対象のModelは、サブプロセスでプラグインのKernelのMigratorに足す。生成カラムの無い定義で
     * 作ったテーブルに、要望の定義の生成カラムを足すと`--dry-run`が`changed`を出し、適用で列と索引が足されて、次の
     * `--dry-run`が`up-to-date`になる。式を変えると、もう一度`changed`になり、適用でDBの式が変わる。
     */
    public function testGeneratedColumnsAreReportedAndAppliedByTheCommand(): void
    {
        $base = self::wp(['tmswp', 'migrate'], model: AccountsBaseModel::class);

        self::assertSame(0, $base['code'], $base['stdout'] . $base['stderr']);
        self::assertSame(
            "Migrated: tmswp_fixture_spied\nMigrated: tmswp_it_accounts\nMigrated: tmswp_fixture_contacts\nSuccess: 3 table(s) migrated, 0 skipped.\n",
            $base['stdout'],
        );
        self::assertSame([], self::accountsGeneratedColumns());

        foreach ([AccountsModel::class => "'expired','deleted')", AccountsChangedExpressionModel::class => "'expired','deleted','suspended')"] as $model => $inactiveStatuses) {
            $changed = self::wp(['tmswp', 'migrate', '--dry-run'], model: $model);

            self::assertSame(0, $changed['code'], $changed['stdout'] . $changed['stderr']);
            self::assertSame("tmswp_fixture_spied: up-to-date\ntmswp_it_accounts: changed\ntmswp_fixture_contacts: up-to-date\n", $changed['stdout'], $model);

            $migrate = self::wp(['tmswp', 'migrate'], model: $model);

            self::assertSame(0, $migrate['code'], $migrate['stdout'] . $migrate['stderr']);
            self::assertSame(
                "Skipped: tmswp_fixture_spied (up to date)\nMigrated: tmswp_it_accounts\nSkipped: tmswp_fixture_contacts (up to date)\nSuccess: 1 table(s) migrated, 2 skipped.\n",
                $migrate['stdout'],
                $model,
            );

            $upToDate = self::wp(['tmswp', 'migrate', '--dry-run'], model: $model);

            self::assertSame(0, $upToDate['code'], $upToDate['stdout'] . $upToDate['stderr']);
            self::assertSame("tmswp_fixture_spied: up-to-date\ntmswp_it_accounts: up-to-date\ntmswp_fixture_contacts: up-to-date\n", $upToDate['stdout'], $model);

            $columns = self::accountsGeneratedColumns();
            self::assertSame(['corp_admin_active_key', 'site_role_active_key'], array_keys($columns), $model);
            self::assertSame(['STORED GENERATED', 'VIRTUAL GENERATED'], array_column($columns, 'extra'), $model);

            // DBは式を正規化して保存する(MariaDBとMySQLで表記が違う)ため、状態の並びの部分だけを照らし合わせる
            foreach ($columns as $column => $attributes) {
                self::assertStringContainsString($inactiveStatuses, str_replace(['_utf8mb4', '\\', ' '], '', $attributes['expression']), $column);
            }
        }

        self::assertSame(['uq_corp_admin', 'uq_site_role'], self::accountsUniqueIndexes());
    }

    /**
     * 開発用サイトの`tmswp_it_accounts`の生成カラムの、Extraと式(information_schemaのGENERATION_EXPRESSION)。
     *
     * @return array<string, array{extra: string, expression: string}>
     */
    private static function accountsGeneratedColumns(): array
    {
        $result = self::wp(['eval', <<<'PHP'
            global $wpdb;
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT COLUMN_NAME AS name, EXTRA AS extra, GENERATION_EXPRESSION AS expression FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND EXTRA LIKE %s ORDER BY ORDINAL_POSITION",
                $wpdb->prefix . 'tmswp_it_accounts',
                '%' . $wpdb->esc_like(' GENERATED'),
            ), ARRAY_A);
            echo json_encode($rows, JSON_THROW_ON_ERROR);
            PHP], fixtures: false);
        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        $rows = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($rows);
        $columns = [];

        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['name'] ?? null);
            self::assertIsString($row['extra'] ?? null);
            self::assertIsString($row['expression'] ?? null);
            $columns[$row['name']] = ['extra' => $row['extra'], 'expression' => $row['expression']];
        }

        return $columns;
    }

    /**
     * 開発用サイトの`tmswp_it_accounts`のUNIQUEの索引の名前(主キーを除く)。
     *
     * @return list<string>
     */
    private static function accountsUniqueIndexes(): array
    {
        $result = self::wp(['eval', <<<'PHP'
            global $wpdb;
            $names = [];
            foreach ($wpdb->get_results('SHOW INDEX FROM `' . $wpdb->prefix . 'tmswp_it_accounts`', ARRAY_A) as $row) {
                if ($row['Non_unique'] === '0' && $row['Key_name'] !== 'PRIMARY') {
                    $names[] = $row['Key_name'];
                }
            }
            sort($names);
            echo json_encode($names, JSON_THROW_ON_ERROR);
            PHP], fixtures: false);
        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        $names = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($names);
        self::assertTrue(array_is_list($names));

        return array_map(static fn(mixed $name): string => \is_string($name) ? $name : self::fail('index name is not a string'), $names);
    }

    /**
     * 開発用サイトにあるfixtureのテーブル(接頭辞を除いた名前。作られた順ではなくTABLESの順)と、
     * `tmswp_schema_hashes`のoptionの値(無ければnull)。
     *
     * @return array{tables: list<string>, hashes: mixed}
     */
    private static function devSiteState(): array
    {
        $result = self::wp(['eval', <<<'PHP'
            global $wpdb;
            $tables = [];
            foreach (['tmswp_fixture_spied', 'tmswp_fixture_contacts', 'tmswp_it_accounts'] as $table) {
                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . $table))) !== null) {
                    $tables[] = $table;
                }
            }
            echo json_encode(['tables' => $tables, 'hashes' => get_option('tmswp_schema_hashes', null)], JSON_THROW_ON_ERROR);
            PHP], fixtures: false);
        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        $state = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        $tables = $state['tables'] ?? null;
        self::assertIsArray($tables);
        self::assertTrue(array_is_list($tables));
        $names = [];

        foreach ($tables as $table) {
            self::assertIsString($table);
            $names[] = $table;
        }

        return ['tables' => $names, 'hashes' => $state['hashes'] ?? null];
    }

    /**
     * 開発用サイトの`tmswp_schema_hashes`のoptionのautoloadの値。
     */
    private static function hashOptionAutoload(): string
    {
        $result = self::wp(['eval', <<<'PHP'
            global $wpdb;
            echo (string) $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'tmswp_schema_hashes'));
            PHP], fixtures: false);
        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);

        return $result['stdout'];
    }

    /**
     * 開発用サイト(ABSPATH)に対してWP-CLIを実行する。$fixturesならfixtureのテーマとプラグインをそのプロセスの中だけ有効にする。
     * $modelを渡すと、そのプロセスの中だけ、WordPressの読み込みの後(wp_loaded)にプラグインのKernelのMigratorへ登録する。
     *
     * @param list<string> $arguments
     * @param class-string<Model>|null $model
     *
     * @return array{code: int, stdout: string, stderr: string, removedDeprecations: int}
     */
    private static function wp(array $arguments, bool $fixtures = true, ?string $model = null): array
    {
        $root = \dirname(__DIR__, 4);
        $require = $fixtures ? ['--require=' . $root . '/tests/fixtures/_wp-cli.php'] : [];
        $exec = $model === null ? [] : [
            '--exec=WP_CLI::add_wp_hook("wp_loaded", static function (): void { \\TMSWP\\Core\\Kernel\\Kernel::get("' . self::PLUGIN_NAMESPACE . '")->container()->get(\\TMSWP\\Core\\Model\\Migrator::class)->register(\\' . $model . '::class); });',
        ];

        return self::runProcess(['wp', '--path=' . ABSPATH, ...$require, ...$exec, ...$arguments], $root);
    }

    /**
     * @param list<string> $command
     *
     * @return array{code: int, stdout: string, stderr: string, removedDeprecations: int}
     */
    private static function runProcess(array $command, string $cwd): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);

        if (!\is_resource($process)) {
            throw new RuntimeException('cannot start ' . implode(' ', $command));
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        // PHP 8.5でWP-CLI 2.12.0のphar自身が出す非推奨の行だけを除く(WpCliPharDeprecations。WP-CLIが対応したら外す)
        $stripped = WpCliPharDeprecations::strip($stderr);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stripped['output'], 'removedDeprecations' => $stripped['removed']];
    }
}
