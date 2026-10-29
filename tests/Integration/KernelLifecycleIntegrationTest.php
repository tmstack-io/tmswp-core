<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use Closure;
use Composer\Autoload\ClassLoader;
use RuntimeException;
use TMSWP\AcfBridge\AcfBridge;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\AsTaxonomy;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\ExtensionInterface;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use TMSWP\Core\Post\PostType;
use TMSWP\JaToolkit\JaToolkit;
use TMSWP\JaToolkit\Module\DisableTexturize;
use TMSWP\JaToolkit\Module\FullwidthSearchNormalizer;
use TMSWP\JaToolkit\Module\JapaneseSlug;
use TMSWP\SettingsPage\SettingsPage;
use TMSWP\SettingsPage\SettingsPages;
use TMSWP\Testing\BootsKernelBeforeInit;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UsesTempDirectories;
use TMSWP\Thumbnail\Thumbnail;
use WP_Hook;

/**
 * Kernelの起動の順序(遅延bootのキャッチアップ・defer()の予約)と、有効化・無効化のライフサイクルを、実物のWordPressの
 * $wp_actionsとフックで確かめる(docs/02-kernel.md §3.3・§3.4・§3.10、docs/01-architecture.mdのJIT翻訳)。
 *
 * 一時ディレクトリのapp/を持つKernelをbootする。順序は、app/に置く記録用のコンポーネント(発火済みのplugins_loadedと
 * 未発火の独自フックに初期化を予約する属性)、投稿タイプ・タクソノミーの登録(registered_post_type等のアクション)、
 * MetaSchemaのWordPressへの登録(register_meta_argsのフィルタ)、拡張のdefer()の予約が、1つの記録のアクション
 * (EVENT_HOOK)に書く文字列の並びで確かめる。
 *
 * プラグインの有効化のサンドボックス(WP_SANDBOX_SCRAPING)は、定義した定数がプロセスに残るため、WP-CLIの別の
 * プロセスで実際のactivate_plugin()を動かして確かめる。そのプロセスは開発用サイト(wp_のテーブル)を使うため、
 * 一時的に置くプラグイン・有効なプラグインの設定・rewrite_rulesは、テストの後で元に戻す。
 */
final class KernelLifecycleIntegrationTest extends IntegrationTestCase
{
    use BootsKernelBeforeInit;
    use UsesTempDirectories;

    /** 記録用のコンポーネント・拡張の予約・テストのリスナーが、起きたことを文字列で書くアクション */
    private const EVENT_HOOK = 'tmswp_integration/lifecycle_event';

    /** WordPressが発火しないフック(未発火のフックのグループと予約の確認に使う) */
    private const UNFIRED_HOOK = 'tmswp_integration/lifecycle_unfired';

    private const META_KEY = 'tmswp_lc_note';

    private RecordingLogger $logger;

    /** @var list<string> EVENT_HOOKに書かれた文字列(起きた順) */
    private array $events = [];

    /** 一時的に開発用サイトのプラグインの置き場に置いたプラグインのディレクトリ(tear_downで消す) */
    private ?string $probePluginDir = null;

    public function set_up(): void
    {
        parent::set_up();
        $this->logger = new RecordingLogger();
        $this->events = [];
        add_action(self::EVENT_HOOK, function (string $event): void {
            $this->events[] = $event;
        }, 10, 1);
    }

    public function tear_down(): void
    {
        $this->unregisterAppAutoloaders();

        if ($this->probePluginDir !== null && is_dir($this->probePluginDir)) {
            $this->deleteDirectory($this->probePluginDir);
        }

        $this->probePluginDir = null;
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    /**
     * docs/01-architecture.mdの「FW内にinitフック前の__()等の呼び出しが存在しない(WP 6.7 JIT翻訳対応)」。
     *
     * 投稿タイプ・タクソノミー・設定ページを持つテーマのKernelを、4つの拡張(settings-page・thumbnail・ja-toolkitの
     * すべてのモジュール・acf-bridge)と一緒にinitより前(functions.phpの読み込み時)にbootし、initまで進める。
     * bootの間(拡張のregister()と、acf-bridgeが発火済みのplugins_loadedへ予約した処理のキャッチアップを含む)に
     * tmswpのドメインの翻訳関数が呼ばれず、tmswpのドメインの読み込み(load_textdomain)はinitの中だけで起き、
     * _load_textdomain_just_in_timeの_doing_it_wrongが出ないことを確かめる。
     */
    public function testKernelAndExtensionsNeitherTranslateNorLoadTheTmswpDomainBeforeInit(): void
    {
        $namespace = 'TmswpIntegrationJit' . uniqid();
        $root = $this->createTempDirectory('integration-jit');
        $suffix = substr(md5($namespace), 0, 8);
        $postType = 'tmswp_jp_' . $suffix;
        $taxonomy = 'tmswp_jt_' . $suffix;
        $this->writeFile($root . '/app/Entity/JitPost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Entity;

use TMSWP\\Core\\Attribute\\AsPostType;
use TMSWP\\Core\\Attribute\\MetaSchema;
use TMSWP\\Core\\Meta\\MetaValueType;
use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

#[AsPostType]
final class JitPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const META_NOTE = 'tmswp_jit_note';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$postType}')->label('Jit', 'Jits');
    }
}
PHP);
        $this->writeFile($root . '/app/Entity/JitTaxonomy.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Entity;

use TMSWP\\Core\\Attribute\\AsTaxonomy;
use TMSWP\\Core\\Tax\\Taxonomy;
use TMSWP\\Core\\Tax\\TaxonomyDefinition;

#[AsTaxonomy]
final class JitTaxonomy extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('{$taxonomy}')->label('Jit', 'Jits')->objectType(JitPost::class);
    }
}
PHP);
        $this->writeFile($root . '/app/Admin/JitSettingsPage.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Admin;

use TMSWP\\Core\\Form\\Form;
use TMSWP\\SettingsPage\\SettingsPage;
use TMSWP\\SettingsPage\\SettingsPageDefinition;

final class JitSettingsPage extends SettingsPage
{
    public static function definition(): SettingsPageDefinition
    {
        return new SettingsPageDefinition(slug: 'tmswp-jit-{$suffix}', pageTitle: 'Jit');
    }

    protected function render(?Form \$form): void
    {
    }
}
PHP);
        // acf-bridgeがinit 6で読む生成物(wp tmswp acf syncが作るもの)。ACFのフィールドグループを持たない
        $this->writeFile($root . '/app/Acf/AcfManifest.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Acf;

final class AcfManifest
{
    /** @return list<\\TMSWP\\AcfBridge\\Manifest\\ManagedKey> */
    public static function managedKeys(): array
    {
        return [];
    }
}
PHP);
        // コンストラクタに渡すページ(app/にあるが属性を持たない)は、ここで読み込んで型を確かめる
        require_once $root . '/app/Admin/JitSettingsPage.php';
        $pageClass = self::fixtureClass($namespace . '\\Admin\\JitSettingsPage', SettingsPage::class);
        $config = KernelConfig::theme(
            $root . '/app',
            $namespace,
            true,
            [
                new SettingsPages([$pageClass]),
                new Thumbnail(),
                new JaToolkit(new DisableTexturize(), new FullwidthSearchNormalizer(), new JapaneseSlug()),
                new AcfBridge(),
            ],
            $this->recordLogs(),
        );
        // acf-bridgeはapp/Acf/AcfManifest.phpの有無をclass_exists() で確かめるため、無いファイルをrequireしない
        // Composerのオートローダを使う
        $loader = new ClassLoader();
        $loader->addPsr4($namespace . '\\', $root . '/app');
        $loader->register();

        $phase = 'boot';
        /** @var list<array{string, string}> $translated */
        $translated = [];
        /** @var list<string> $loaded */
        $loaded = [];
        /** @var list<string> $doingItWrong */
        $doingItWrong = [];
        $recordTranslation = static function (mixed $translation, mixed $text) use (&$phase, &$translated): mixed {
            $translated[] = [$phase, \is_string($text) ? $text : get_debug_type($text)];

            return $translation;
        };

        foreach (['gettext_tmswp', 'gettext_with_context_tmswp', 'ngettext_tmswp', 'ngettext_with_context_tmswp'] as $filter) {
            add_filter($filter, $recordTranslation, 10, 2);
        }

        add_action('load_textdomain', static function (string $domain) use (&$phase, &$loaded): void {
            if ($domain === 'tmswp') {
                $loaded[] = $phase . (doing_action('init') ? ' in init' : '');
            }
        }, 10, 1);
        add_action('doing_it_wrong_run', static function (string $function) use (&$doingItWrong): void {
            $doingItWrong[] = $function;
        }, 10, 1);

        try {
            $initCallbacks = $this->bootBeforeInit($config);
            $phase = 'init';
            self::fireInit($initCallbacks);
            $phase = 'after init';

            // 空振りしていないことの確認: 登録はinitで済み、initの後の翻訳関数の呼び出しは記録される
            self::assertTrue(post_type_exists($postType));
            self::assertTrue(taxonomy_exists($taxonomy));
            __('Settings saved.', 'tmswp');
        } finally {
            $loader->unregister();
            unregister_taxonomy($taxonomy);
            unregister_post_type($postType);
        }

        self::assertSame([['after init', 'Settings saved.']], array_values(array_filter(
            $translated,
            static fn(array $call): bool => $call[0] !== 'init',
        )));
        // settings-page・thumbnail・ja-toolkitの訳のローダー(init 1)。Kernelのローダーはプロセスで1回だけ登録済み
        self::assertSame(['init in init', 'init in init', 'init in init'], $loaded);
        self::assertSame([], $doingItWrong);
    }

    /**
     * docs/02-kernel.md §3.10の「プラグインの有効化のサンドボックスでは(拡張が)呼ばれない」と、§3.4の有効化・無効化。
     *
     * WP-CLIの別のプロセスで、開発用サイトのプラグインの置き場に置いた本番(debug=false)のプラグインを、実際の
     * activate_plugin()で有効にする。WordPressはplugin_sandbox_scrape()でWP_SANDBOX_SCRAPINGを定義してから
     * プラグインの本体ファイルを読み込み(initの後)、そこでのbootは拡張のregister()を呼ばずにKernelを登録し、
     * 有効化のフック(activate_<plugin>)でrewrite_rulesを消してtmswp/activatedを1回発火する。
     * 空振りしていないことの確認として、有効にした後の別のプロセス(通常の読み込み。サンドボックスでない)では
     * 拡張が1回呼ばれ、deactivate_plugins()でtmswp/deactivatedが1回発火する。
     */
    public function testPluginActivationSandboxBootsWithoutRegisteringExtensions(): void
    {
        $suffix = substr(md5(uniqid('', true)), 0, 10);
        $namespace = 'TmswpSandboxProbe' . $suffix;
        $slug = 'tmswp-sandbox-probe-' . $suffix;
        $plugin = $slug . '/' . $slug . '.php';
        $this->probePluginDir = WP_PLUGIN_DIR . '/' . $slug;
        $this->writeSandboxProbePlugin($this->probePluginDir . '/' . $slug . '.php', $namespace);
        $scripts = $this->createTempDirectory('integration-sandbox-scripts');
        $this->writeFile($scripts . '/activate.php', self::ACTIVATE_SCRIPT);
        $this->writeFile($scripts . '/deactivate.php', self::DEACTIVATE_SCRIPT);

        try {
            $activation = self::wpProbe(['eval-file', $scripts . '/activate.php', $plugin, $namespace]);

            self::assertSame(
                [
                    'error' => null,
                    'probe' => ['sandbox' => true, 'registrations' => 0, 'activated' => 1, 'deactivated' => 0],
                    'booted' => true,
                    'rewriteRulesDeleted' => true,
                    'active' => true,
                ],
                $activation,
            );

            $deactivation = self::wpProbe(['eval-file', $scripts . '/deactivate.php', $plugin, $namespace]);

            self::assertSame(
                [
                    'loaded' => ['sandbox' => false, 'registrations' => 1, 'activated' => 0, 'deactivated' => 0],
                    'probe' => ['sandbox' => false, 'registrations' => 1, 'activated' => 0, 'deactivated' => 1],
                    'sandboxConstant' => false,
                    'rewriteRulesDeleted' => true,
                    'active' => false,
                ],
                $deactivation,
            );
        } finally {
            // 途中で失敗しても、開発用サイトの有効なプラグインに残さない(無効化のフックは発火させない)
            self::runProcess(['wp', '--path=' . ABSPATH, 'eval', "require_once ABSPATH . 'wp-admin/includes/plugin.php'; deactivate_plugins('{$plugin}', true);"]);
        }
    }

    /**
     * docs/02-kernel.md §3.3の「本番の遅延bootのキャッチアップが、$wp_actionsで最初に発火した順、同じフックの中では
     * priorityの昇順で実行される(plugins_loadedのグループがinitより先。MetaSchemaのWordPressへの登録(init 20)も
     * 同じ規則で、MetaSchemaRegistryへの登録(boot時の初期化)はキャッチアップより先)。未発火のフックのグループは
     * add_actionされる」。
     *
     * 統合テストのプロセスはWordPressの読み込みを終えており、実物の $wp_actionsではplugins_loadedがinitより先に
     * 発火済みである。plugins_loadedのグループのpriority(50)はinitのどのグループよりも大きいため、priorityだけで
     * 並べるとinitの後になる。
     */
    public function testProductionLateBootCatchesUpFiredGroupsInFiringOrderAndAddsUnfiredGroups(): void
    {
        $config = $this->productionPluginConfig('LateOrder', []);
        [$postType, $taxonomy] = $this->writeLifecycleApp($config);
        $schemaRegisteredAtFirstGroup = null;
        add_action(self::EVENT_HOOK, function (string $event) use ($config, &$schemaRegisteredAtFirstGroup): void {
            if ($event === 'component plugins_loaded 50') {
                $postClass = self::fixtureClass($config->appNamespace . '\\Entity\\LifecyclePost', PostType::class);
                $schemaRegisteredAtFirstGroup = Kernel::get($config->appNamespace)->container()->get(MetaSchemaRegistry::class)->schemasFor($postClass)->has(self::META_KEY);
            }
        }, 10, 1);
        self::assertTrue(did_action('init') > 0 && did_action('plugins_loaded') > 0);

        try {
            Kernel::boot($config);

            self::assertSame(
                ['component plugins_loaded 50', 'taxonomy init 9', 'post_type init 10', 'meta init 20'],
                $this->events,
            );
            self::assertTrue($schemaRegisteredAtFirstGroup);
            // 遅延bootの記録は、発火済みのグループごとに1回
            self::assertSame(
                [self::lateBootMessage('plugins_loaded'), self::lateBootMessage('init'), self::lateBootMessage('init'), self::lateBootMessage('init')],
                array_column($this->logger->records, 'message'),
            );

            // 未発火のフックのグループは実行せず、add_actionで登録している
            self::assertSame([7], self::priorities(self::UNFIRED_HOOK));
            do_action(self::UNFIRED_HOOK);
            self::assertSame(
                ['component plugins_loaded 50', 'taxonomy init 9', 'post_type init 10', 'meta init 20', 'component ' . self::UNFIRED_HOOK . ' 7'],
                $this->events,
            );
        } finally {
            unregister_taxonomy($taxonomy);
            unregister_post_type($postType);
        }
    }

    /**
     * docs/02-kernel.md §3.3の「拡張のregister()がdefer()で発火済みのフックへ予約した処理も、キャッチアップの中の
     * (最初に発火した順, priority)の位置で、遅延bootのログを記録せずに実行される(遅延bootの場合)。未発火のフックへの
     * 予約はadd_actionで登録される」。
     *
     * 拡張はplugins_loaded 5(plugins_loaded 50のグループより前)、init 15(init 10の投稿タイプの登録の後、init 20の
     * MetaSchemaのWordPressへの登録の前)、未発火のフックのpriority 3に予約する。
     */
    public function testExtensionDeferToFiredHooksRunsInsideProductionLateBootCatchUp(): void
    {
        $extension = self::deferringExtension([['plugins_loaded', 5], ['init', 15], [self::UNFIRED_HOOK, 3]]);
        $config = $this->productionPluginConfig('LateDefer', [$extension]);
        [$postType, $taxonomy] = $this->writeLifecycleApp($config);

        try {
            Kernel::boot($config);

            self::assertSame(
                [
                    'extension plugins_loaded 5',
                    'component plugins_loaded 50',
                    'taxonomy init 9',
                    'post_type init 10',
                    'extension init 15',
                    'meta init 20',
                ],
                $this->events,
            );
            // 予約したものには遅延bootのログを記録しない(記録は発火済みのグループの4件だけ)
            self::assertSame(
                [self::lateBootMessage('plugins_loaded'), self::lateBootMessage('init'), self::lateBootMessage('init'), self::lateBootMessage('init')],
                array_column($this->logger->records, 'message'),
            );

            self::assertSame([3, 7], self::priorities(self::UNFIRED_HOOK));
            do_action(self::UNFIRED_HOOK);
            self::assertSame(
                ['extension ' . self::UNFIRED_HOOK . ' 3', 'component ' . self::UNFIRED_HOOK . ' 7'],
                \array_slice($this->events, 6),
            );
        } finally {
            unregister_taxonomy($taxonomy);
            unregister_post_type($postType);
        }
    }

    /**
     * docs/02-kernel.md §3.3の「テーマのKernelの拡張が、テーマの読み込みの前に発火済みのplugins_loadedへ予約したものも、
     * 遅延bootでなくても同じくキャッチアップの中で実行される(debugでも実行する)」と「予約したものには遅延bootのログを
     * 記録しない」「未発火のフックへの予約はadd_action」。
     *
     * テーマのKernelを、正常な位置(functions.phpの読み込み時。plugins_loadedは発火済み、after_setup_theme・initは
     * 未発火)で、debugでbootする。
     */
    public function testThemeExtensionDeferToPluginsLoadedRunsDuringBootAtTheNormalPosition(): void
    {
        $root = $this->createTempDirectory('integration-theme-defer');
        self::assertTrue(mkdir($root . '/app'));
        $config = KernelConfig::theme(
            $root . '/app',
            'TmswpIntegrationThemeDefer' . uniqid(),
            true,
            [self::deferringExtension([['plugins_loaded', 5], ['after_setup_theme', 10], ['init', 15]])],
            $this->recordLogs(),
        );
        $before = self::registeredCallbacks();

        $initCallbacks = $this->bootBeforeInit($config);

        // plugins_loadedへの予約はbootの中で実行済み。after_setup_theme・initへの予約はadd_actionで登録している
        self::assertSame(['extension plugins_loaded 5'], $this->events);
        self::assertSame([], $this->logger->records);
        $added = array_diff_key(self::registeredCallbacks(), $before);
        self::assertSame([], array_values(array_filter($added, static fn(array $callback): bool => $callback['hook'] === 'plugins_loaded')));
        self::assertSame([10], array_column(array_values(array_filter($added, static fn(array $callback): bool => $callback['hook'] === 'after_setup_theme')), 'priority'));
        self::assertContains(15, array_column($initCallbacks, 'priority'));

        self::fireInit($initCallbacks);

        self::assertSame(['extension plugins_loaded 5', 'extension init 15'], $this->events);
    }

    /**
     * docs/02-kernel.md §3.4の「有効化・無効化のコールバックがdelete_option('rewrite_rules')とtmswp/activated・
     * tmswp/deactivatedの発火だけを行う(flush_rewrite_rules()・add_option()を呼ばない)」。
     *
     * 実物のWordPressで、プラグイン形態のactivate_<plugin>・deactivate_<plugin>と、テーマ形態のafter_switch_theme・
     * switch_themeを発火し、そのKernelのtmswp/activated・tmswp/deactivatedがそれぞれ1回だけ発火し、rewrite_rulesが
     * 消えることを確かめる(テーマ形態のフックではfixtureのテーマのKernelのコールバックも動くため、発火はKernelごとに
     * 数える)。WordPress自身もafter_switch_theme等にオプションを書くコールバックを持つため、オプションへの書き込みが
     * rewrite_rulesの削除だけであることは、そのKernelが登録したコールバックだけでフックを発火して確かめる。
     */
    public function testActivationAndDeactivationFireLifecycleActionsOnceAndOnlyDeleteRewriteRules(): void
    {
        $pluginConfig = $this->productionPluginConfig('LifecyclePlugin', []);
        (new DiscoveryFile($pluginConfig))->write(new DiscoveryResult([], []));
        $before = self::registeredCallbacks();
        $this->bootBeforeInit($pluginConfig);
        $pluginCallbacks = array_diff_key(self::registeredCallbacks(), $before);
        $pluginKernel = Kernel::get($pluginConfig->appNamespace);
        $basename = plugin_basename((string) $pluginConfig->pluginFile);

        $themeRoot = $this->createTempDirectory('integration-lifecycle-theme');
        self::assertTrue(mkdir($themeRoot . '/app'));
        $themeConfig = KernelConfig::theme($themeRoot . '/app', 'TmswpIntegrationLifecycleTheme' . uniqid(), false, logChannels: $this->recordLogs());
        (new DiscoveryFile($themeConfig))->write(new DiscoveryResult([], []));
        $before = self::registeredCallbacks();
        $this->bootBeforeInit($themeConfig);
        $themeCallbacks = array_diff_key(self::registeredCallbacks(), $before);
        $themeKernel = Kernel::get($themeConfig->appNamespace);

        /** @var array<string, array{Kernel, array<string, array{hook: string, priority: int, function: mixed, acceptedArgs: int}>, non-empty-string, list<mixed>, string}> $cases */
        $cases = [
            'activate plugin' => [$pluginKernel, $pluginCallbacks, "activate_{$basename}", [false], 'tmswp/activated'],
            'deactivate plugin' => [$pluginKernel, $pluginCallbacks, "deactivate_{$basename}", [false], 'tmswp/deactivated'],
            'after_switch_theme' => [$themeKernel, $themeCallbacks, 'after_switch_theme', ['Previous Theme', wp_get_theme()], 'tmswp/activated'],
            'switch_theme' => [$themeKernel, $themeCallbacks, 'switch_theme', ['Next Theme', wp_get_theme(), wp_get_theme()], 'tmswp/deactivated'],
        ];

        foreach ($cases as $case => [$kernel, $callbacks, $hook, $args, $expectedAction]) {
            $expected = ['tmswp/activated' => 0, 'tmswp/deactivated' => 0];
            $expected[$expectedAction] = 1;

            // そのKernelが登録したコールバックだけで発火する。WordPressのフック全体の発火より先に行う(add_option()は
            // 既にあるオプションでは何もしないため、後にすると、コールバックが足したオプションへの2回目の追加を見逃す)
            $own = array_values(array_filter($callbacks, static fn(array $callback): bool => $callback['hook'] === $hook));
            self::assertCount(1, $own, $case);
            update_option('rewrite_rules', ['tmswp-lifecycle/?$' => 'index.php?tmswp_lifecycle=1']);
            [$fired, $writes] = self::recordLifecycle($kernel, static fn() => self::fireOnly($hook, $own, $args));

            self::assertSame($expected, $fired, $case);
            self::assertSame([['delete_option', 'rewrite_rules']], $writes, $case);
            self::assertFalse(get_option('rewrite_rules'), $case);

            // WordPressと同じく、フックに登録されたすべてのコールバックで発火する
            update_option('rewrite_rules', ['tmswp-lifecycle/?$' => 'index.php?tmswp_lifecycle=1']);
            [$fired] = self::recordLifecycle($kernel, static fn() => do_action($hook, ...$args));

            self::assertSame($expected, $fired, $case);
            self::assertFalse(get_option('rewrite_rules'), $case);
        }
    }

    /**
     * activate_plugin()でプラグインを有効にし、その後の状態を出力するWP-CLIのeval-fileのスクリプト。eval-fileは
     * ファイルの中身をeval()するため、declare(strict_types=1)を置けない(DEACTIVATE_SCRIPTも同じ)。
     */
    private const ACTIVATE_SCRIPT = <<<'PHP'
<?php

require_once ABSPATH . 'wp-admin/includes/plugin.php';

[$plugin, $namespace] = $args;
$rules = get_option('rewrite_rules');
update_option('rewrite_rules', ['tmswp-sandbox/?$' => 'index.php?tmswp_sandbox=1']);
$result = activate_plugin($plugin);
$report = [
    'error' => is_wp_error($result) ? $result->get_error_message() : null,
    'probe' => $GLOBALS['tmswp_sandbox_probe'] ?? null,
    'booted' => class_exists(\TMSWP\Core\Kernel\Kernel::class, false) && array_key_exists($namespace, \TMSWP\Core\Kernel\Kernel::all()),
    'rewriteRulesDeleted' => get_option('rewrite_rules') === false,
    'active' => in_array($plugin, (array) get_option('active_plugins', []), true),
];
$rules === false ? delete_option('rewrite_rules') : update_option('rewrite_rules', $rules);
echo "\nTMSWP_PROBE " . json_encode($report) . "\n";
PHP;

    /** 有効なプラグインとして読み込まれた状態を記録し、deactivate_plugins()で無効にした後の状態を出力するスクリプト */
    private const DEACTIVATE_SCRIPT = <<<'PHP'
<?php

require_once ABSPATH . 'wp-admin/includes/plugin.php';

[$plugin, $namespace] = $args;
$loaded = $GLOBALS['tmswp_sandbox_probe'] ?? null;
$rules = get_option('rewrite_rules');
update_option('rewrite_rules', ['tmswp-sandbox/?$' => 'index.php?tmswp_sandbox=1']);
deactivate_plugins($plugin);
$report = [
    'loaded' => $loaded,
    'probe' => $GLOBALS['tmswp_sandbox_probe'] ?? null,
    'sandboxConstant' => defined('WP_SANDBOX_SCRAPING'),
    'rewriteRulesDeleted' => get_option('rewrite_rules') === false,
    'active' => in_array($plugin, (array) get_option('active_plugins', []), true),
];
$rules === false ? delete_option('rewrite_rules') : update_option('rewrite_rules', $rules);
echo "\nTMSWP_PROBE " . json_encode($report) . "\n";
PHP;

    /**
     * 本番(debug=false)のプラグインの本体ファイルを書く。拡張のregister()・tmswp/activated・tmswp/deactivatedの呼び出しの
     * 回数と、読み込まれた時点でWP_SANDBOX_SCRAPINGが定義されていたかを、$GLOBALS['tmswp_sandbox_probe']に記録する。
     * Kernelのログは捨てる(開発用サイトにログのファイルを作らない)。
     */
    private function writeSandboxProbePlugin(string $pluginFile, string $namespace): void
    {
        $autoload = \dirname(__DIR__, 4) . '/vendor/autoload.php';
        $this->writeFile($pluginFile, <<<PHP
<?php

/**
 * Plugin Name: TMSWP Sandbox Probe
 * Description: KernelLifecycleIntegrationTestが一時的に置くプラグイン。
 */

declare(strict_types=1);

use Psr\\Log\\NullLogger;
use TMSWP\\Core\\Kernel\\ExtensionInterface;
use TMSWP\\Core\\Kernel\\Kernel;
use TMSWP\\Core\\Kernel\\KernelConfig;
use TMSWP\\Core\\Log\\LogChannel;

require_once '{$autoload}';

\$GLOBALS['tmswp_sandbox_probe'] = [
    'sandbox' => \\defined('WP_SANDBOX_SCRAPING') && WP_SANDBOX_SCRAPING === true,
    'registrations' => 0,
    'activated' => 0,
    'deactivated' => 0,
];

foreach (['tmswp/activated' => 'activated', 'tmswp/deactivated' => 'deactivated'] as \$hook => \$key) {
    add_action(\$hook, static function (Kernel \$kernel) use (\$key): void {
        if (\$kernel->config()->appNamespace === '{$namespace}') {
            ++\$GLOBALS['tmswp_sandbox_probe'][\$key];
        }
    }, 10, 1);
}

Kernel::boot(KernelConfig::plugin(
    __FILE__,
    __DIR__ . '/app',
    '{$namespace}',
    false,
    [new class implements ExtensionInterface {
        public function register(Kernel \$kernel): void
        {
            ++\$GLOBALS['tmswp_sandbox_probe']['registrations'];
        }
    }],
    static fn(): array => ['null' => new LogChannel(new NullLogger())],
));
PHP);
        self::assertTrue(mkdir(\dirname($pluginFile) . '/app'));
        // 本番のbootは探索結果ファイルを読むため、空の探索結果を置く
        $config = KernelConfig::plugin($pluginFile, \dirname($pluginFile) . '/app', $namespace, false);
        (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
        // このプロセスではbootしないため、Kernelのレジストリにも残らない
        self::assertArrayNotHasKey($namespace, Kernel::all());
    }

    /**
     * WP-CLIを別のプロセスで動かし、出力のTMSWP_PROBEの行のJSONを返す。
     *
     * @param list<string> $arguments
     *
     * @return array<mixed>
     */
    private static function wpProbe(array $arguments): array
    {
        $result = self::runProcess(['wp', '--path=' . ABSPATH, ...$arguments]);
        $output = $result['stdout'] . $result['stderr'];
        self::assertSame(0, $result['code'], $output);
        self::assertSame(1, preg_match('/^TMSWP_PROBE (.+)$/m', $result['stdout'], $matches), $output);
        $report = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($report, $output);

        return $report;
    }

    /**
     * @param list<string> $command
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private static function runProcess(array $command): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, \dirname(__DIR__, 4), getenv());

        if (!\is_resource($process)) {
            throw new RuntimeException('cannot start ' . implode(' ', $command));
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * 記録用のコンポーネントと、投稿タイプ(メタスキーマを1つ持つ)・タクソノミーをapp/に書き、探索結果ファイルを作る。
     * コンポーネントは、発火済みのplugins_loaded(priority 50)と未発火のUNFIRED_HOOK(priority 7)に初期化を予約し、
     * 初期化でEVENT_HOOKに「component {フック} {priority}」を書く。投稿タイプ・タクソノミーの登録とメタの
     * WordPressへの登録は、テストのリスナーがEVENT_HOOKに書く。
     *
     * @return array{string, string} 投稿タイプ名とタクソノミー名
     */
    private function writeLifecycleApp(KernelConfig $config): array
    {
        $namespace = $config->appNamespace;
        $suffix = substr(md5($namespace), 0, 8);
        $postType = 'tmswp_lp_' . $suffix;
        $taxonomy = 'tmswp_lt_' . $suffix;
        $eventHook = self::EVENT_HOOK;
        $metaKey = self::META_KEY;

        foreach (['PluginsLoadedProbe' => ['plugins_loaded', 50], 'UnfiredProbe' => [self::UNFIRED_HOOK, 7]] as $class => [$hook, $priority]) {
            $this->writeFile($config->appDir . "/Probe/{$class}.php", <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Probe;

use Attribute;
use TMSWP\\Core\\Attribute\\ComponentAttributeInterface;
use TMSWP\\Core\\Kernel\\KernelContext;

#[Attribute(Attribute::TARGET_CLASS)]
final class {$class} implements ComponentAttributeInterface
{
    public function hookName(KernelContext \$context): string
    {
        return '{$hook}';
    }

    public function hookPriority(): int
    {
        return {$priority};
    }

    public function initializerClass(): string
    {
        return ProbeInitializer::class;
    }
}
PHP);
        }

        $this->writeFile($config->appDir . '/Probe/ProbeInitializer.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Probe;

use TMSWP\\Core\\Kernel\\ComponentInitializerInterface;
use TMSWP\\Core\\Kernel\\DiscoveredComponent;
use TMSWP\\Core\\Kernel\\Kernel;

final class ProbeInitializer implements ComponentInitializerInterface
{
    public function initialize(DiscoveredComponent \$component, Kernel \$kernel): void
    {
        \$attribute = \$component->attribute();
        do_action('{$eventHook}', 'component ' . \$attribute->hookName(\$kernel->config()->context) . ' ' . \$attribute->hookPriority());
    }
}
PHP);
        $this->writeFile($config->appDir . '/Entity/LifecyclePost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Entity;

use TMSWP\\Core\\Attribute\\AsPostType;
use TMSWP\\Core\\Attribute\\MetaSchema;
use TMSWP\\Core\\Meta\\MetaValueType;
use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

#[AsPostType]
final class LifecyclePost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const META_NOTE = '{$metaKey}';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$postType}')->label('Lifecycle', 'Lifecycles');
    }
}
PHP);
        $this->writeFile($config->appDir . '/Entity/LifecycleTaxonomy.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Entity;

use TMSWP\\Core\\Attribute\\AsTaxonomy;
use TMSWP\\Core\\Tax\\Taxonomy;
use TMSWP\\Core\\Tax\\TaxonomyDefinition;

#[AsTaxonomy]
final class LifecycleTaxonomy extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('{$taxonomy}')->label('Lifecycle', 'Lifecycles')->objectType(LifecyclePost::class);
    }
}
PHP);
        $this->registerAppAutoloader($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\\Entity\\LifecyclePost', AsPostType::class, []),
            new DiscoveredComponent($namespace . '\\Entity\\LifecycleTaxonomy', AsTaxonomy::class, []),
            new DiscoveredComponent($namespace . '\\Probe\\EarlyComponent', $namespace . '\\Probe\\PluginsLoadedProbe', []),
            new DiscoveredComponent($namespace . '\\Probe\\UnfiredComponent', $namespace . '\\Probe\\UnfiredProbe', []),
        ], []));

        add_action('registered_taxonomy', static function (string $registered) use ($taxonomy): void {
            if ($registered === $taxonomy) {
                do_action(self::EVENT_HOOK, 'taxonomy init 9');
            }
        }, 10, 1);
        add_action('registered_post_type', static function (string $registered) use ($postType): void {
            if ($registered === $postType) {
                do_action(self::EVENT_HOOK, 'post_type init 10');
            }
        }, 10, 1);
        add_filter('register_meta_args', static function (mixed $args, mixed $defaults, mixed $objectType, mixed $key): mixed {
            if ($key === self::META_KEY) {
                do_action(self::EVENT_HOOK, 'meta init 20');
            }

            return $args;
        }, 10, 4);

        return [$postType, $taxonomy];
    }

    /**
     * $fireを実行する間の、$kernelのtmswp/activated・tmswp/deactivatedの発火の回数と、オプションへの書き込み
     * (add_option・update_option・delete_optionのアクション。[アクション, オプション名])を返す。
     *
     * @param Closure(): void $fire
     *
     * @return array{array<string, int>, list<array{string, mixed}>}
     */
    private static function recordLifecycle(Kernel $kernel, Closure $fire): array
    {
        $fired = ['tmswp/activated' => 0, 'tmswp/deactivated' => 0];
        $writes = [];
        $countFired = static function (Kernel $firedKernel) use ($kernel, &$fired): void {
            $action = current_action();

            if ($firedKernel === $kernel && \is_string($action)) {
                $fired[$action] = ($fired[$action] ?? 0) + 1;
            }
        };
        $recordWrite = static function (mixed $option) use (&$writes): void {
            $writes[] = [(string) current_action(), $option];
        };
        $hooks = ['tmswp/activated' => $countFired, 'tmswp/deactivated' => $countFired, 'add_option' => $recordWrite, 'update_option' => $recordWrite, 'delete_option' => $recordWrite];

        foreach ($hooks as $hook => $callback) {
            add_action($hook, $callback, 10, 1);
        }

        try {
            $fire();
        } finally {
            foreach ($hooks as $hook => $callback) {
                remove_action($hook, $callback, 10);
            }
        }

        return [$fired, $writes];
    }

    /**
     * 渡したコールバックだけを登録したフック$hookを、do_action()で発火する。発火の後は元のフックに戻す。
     *
     * @param non-empty-string $hook
     * @param list<array{hook: string, priority: int, function: mixed, acceptedArgs: int}> $callbacks
     * @param list<mixed> $args
     */
    private static function fireOnly(string $hook, array $callbacks, array $args): void
    {
        $filters = $GLOBALS['wp_filter'];
        self::assertIsArray($filters);
        $original = $filters[$hook] ?? null;
        $wpHook = new WP_Hook();

        foreach ($callbacks as $callback) {
            self::assertIsCallable($callback['function']);
            $wpHook->add_filter($hook, $callback['function'], $callback['priority'], $callback['acceptedArgs']);
        }

        $filters[$hook] = $wpHook;
        $GLOBALS['wp_filter'] = $filters;

        try {
            do_action($hook, ...$args);
        } finally {
            $after = $GLOBALS['wp_filter'];
            self::assertIsArray($after);

            if ($original instanceof WP_Hook) {
                $after[$hook] = $original;
            } else {
                unset($after[$hook]);
            }

            $GLOBALS['wp_filter'] = $after;
        }
    }

    /**
     * register()でdefer()を使い、渡した(フック, priority)ごとに、EVENT_HOOKへ「extension {フック} {priority}」を書く
     * 処理を予約する拡張。
     *
     * @param list<array{string, int}> $reservations
     */
    private static function deferringExtension(array $reservations): ExtensionInterface
    {
        return new class ($reservations, self::EVENT_HOOK) implements ExtensionInterface {
            /**
             * @param list<array{string, int}> $reservations
             * @param non-empty-string $eventHook
             */
            public function __construct(
                private readonly array $reservations,
                private readonly string $eventHook,
            ) {}

            public function register(Kernel $kernel): void
            {
                foreach ($this->reservations as [$hook, $priority]) {
                    $eventHook = $this->eventHook;
                    $kernel->defer($hook, $priority, static function () use ($eventHook, $hook, $priority): void {
                        do_action($eventHook, "extension {$hook} {$priority}");
                    });
                }
            }
        };
    }

    /**
     * フックに登録されたコールバックのpriority(昇順)を返す。
     *
     * @return list<int>
     */
    private static function priorities(string $hook): array
    {
        $filters = $GLOBALS['wp_filter'];
        self::assertIsArray($filters);
        $wpHook = $filters[$hook] ?? null;
        self::assertInstanceOf(WP_Hook::class, $wpHook);
        $priorities = array_keys($wpHook->callbacks);
        sort($priorities);

        return $priorities;
    }

    private static function lateBootMessage(string $hook): string
    {
        return "Kernel::boot() was called after the '{$hook}' hook had fired. Boot from the top level of functions.php or the plugin main file.";
    }

    /**
     * 本番相当(debug=false)のプラグイン形態の構成を、一時ディレクトリのプラグインで作る。
     *
     * @param list<ExtensionInterface> $extensions
     */
    private function productionPluginConfig(string $name, array $extensions): KernelConfig
    {
        $root = $this->createTempDirectory('integration-' . strtolower($name));
        self::assertTrue(mkdir($root . '/app'));
        $this->writeFile($root . '/plugin.php', "<?php\n");

        return KernelConfig::plugin($root . '/plugin.php', $root . '/app', 'TmswpIntegration' . $name . uniqid(), false, $extensions, $this->recordLogs());
    }

    /**
     * Kernelのログを、このテストのRecordingLoggerへ送るlogChannelsを返す(ファイルに書かない)。
     *
     * @return Closure(): array<string, LogChannel>
     */
    private function recordLogs(): Closure
    {
        $logger = $this->logger;

        return static fn(): array => ['integration' => new LogChannel($logger)];
    }
}
