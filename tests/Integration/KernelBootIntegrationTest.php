<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use Closure;
use Composer\Autoload\ClassLoader;
use ReflectionProperty;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\AsTaxonomy;
use TMSWP\Core\Attribute\AsUserRole;
use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Exception\DuplicateMetaKeyException;
use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\DiscoveredHookMethod;
use TMSWP\Core\Kernel\Discovery;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\HookMethodKind;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Kernel\KernelContext;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Meta\WpMetaStorage;
use TMSWP\Core\Model\Migrator;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Option\OptionRepositoryFactory;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Tests\Support\RecordingExtension;
use TMSWP\Core\Tests\Support\RecordingInitializer;
use TMSWP\Testing\BootsKernelBeforeInit;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\MemoryMetaStorage;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UsesTempDirectories;
use WP_Hook;
use WP_Rewrite;

final class KernelBootIntegrationTest extends IntegrationTestCase
{
    use BootsKernelBeforeInit;
    use UsesTempDirectories;

    /** productionThemeConfig()・productionPluginConfig() の Kernel のログを記録するロガー */
    private RecordingLogger $logger;

    public function set_up(): void
    {
        parent::set_up();
        $this->logger = new RecordingLogger();
    }

    public function tear_down(): void
    {
        $this->unregisterAppAutoloaders();

        // パーマリンクの設定は $wp_rewrite に残り、テストライブラリは戻さないため、変えたテストの後で既定(空)に戻す
        if (self::rewrite()->permalink_structure !== '') {
            $this->set_permalink_structure('');
        }

        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    public function testHookMethodReceivesNumericStringCoercedLikeWordPress(): void
    {
        // CoercionProbeHook::addOne(int $value)。strict_types のファイルから直接呼ぶと TypeError になる
        self::assertSame(42, apply_filters('tmswp_fixture_theme/int_filter', '41'));
    }

    /**
     * 前提の確認: rewrite_rules が空のとき、WordPress は wp_loaded より前のルールの作り直しを保存せず、wp_loaded で
     * 作り直して保存する(WP_Rewrite::refresh_rewrite_rules())。Kernel の有効化・無効化はこれに頼り、
     * rewrite_rules を消すだけにしている。
     */
    public function testWordPressDefersSavingRebuiltRewriteRulesUntilWpLoaded(): void
    {
        $rewrite = self::rewrite();
        $this->set_permalink_structure('/%postname%/');
        delete_option('rewrite_rules');
        $actions = $GLOBALS['wp_actions'];
        self::assertIsArray($actions);
        unset($actions['wp_loaded']);
        // wp_loaded の前の状態にする(書き換えた $wp_actions は tear_down でテストライブラリが戻す)
        $GLOBALS['wp_actions'] = $actions;

        self::assertNotEmpty($rewrite->wp_rewrite_rules());
        self::assertFalse(get_option('rewrite_rules'));
        self::assertNotFalse(has_action('wp_loaded', [$rewrite, 'flush_rules']));
    }

    public function testThemeActivationLetsNextRequestRebuildRewriteRulesWithPostTypes(): void
    {
        $this->set_permalink_structure('/%postname%/');
        $config = $this->productionThemeConfig('ThemeRewrite');
        $postType = $this->bootProductionKernelWithPostType($config);

        try {
            // 有効化の前に、CPT を含まないルールが保存されている
            update_option('rewrite_rules', ['stale/?$' => 'index.php?stale=1']);

            do_action('after_switch_theme', 'Previous Theme', wp_get_theme());

            self::assertFalse(get_option('rewrite_rules'));
            $rules = $this->nextRequestRewriteRules($postType);
            self::assertArrayHasKey("{$postType}/?\$", $rules);
            self::assertArrayNotHasKey('stale/?$', $rules);
            self::assertSame($rules, get_option('rewrite_rules'));
        } finally {
            unregister_post_type($postType);
        }
    }

    public function testPluginActivationAddsAndDeactivationRemovesPostTypeRewriteRules(): void
    {
        $this->set_permalink_structure('/%postname%/');
        $config = $this->productionPluginConfig('PluginRewrite');
        $postType = $this->bootProductionKernelWithPostType($config);
        $pluginBasename = plugin_basename((string) $config->pluginFile);

        try {
            // 有効化: その前に保存されていたルールを消し、次のリクエストで CPT を含めて作り直させる
            update_option('rewrite_rules', ['stale/?$' => 'index.php?stale=1']);
            do_action("activate_{$pluginBasename}");

            self::assertFalse(get_option('rewrite_rules'));
            self::assertArrayHasKey("{$postType}/?\$", $this->nextRequestRewriteRules($postType));

            // 無効化: そのリクエストではまだ CPT が登録されているが、そのルールを保存せずに消す
            do_action("deactivate_{$pluginBasename}");

            self::assertFalse(get_option('rewrite_rules'));
        } finally {
            // 次のリクエストでは、無効にしたプラグインは読み込まれず CPT も登録されない
            unregister_post_type($postType);
        }

        $rules = $this->nextRequestRewriteRules($postType);
        self::assertNotEmpty($rules);

        foreach (array_keys($rules) as $pattern) {
            self::assertStringNotContainsString($postType, (string) $pattern);
        }
    }

    public function testDebugLateBootRegistersNothingBeforeThrowing(): void
    {
        $root = $this->createTempDirectory('integration-debug-late-boot');
        self::assertTrue(mkdir($root . '/app'));
        $this->writeFile($root . '/plugin.php', "<?php\n");
        $config = KernelConfig::plugin($root . '/plugin.php', $root . '/app', 'TmswpIntegrationDebugLate' . uniqid(), true);
        $namespace = $config->appNamespace;
        $postType = 'tmswp_dl_' . substr(md5($namespace), 0, 8);
        $hook = 'tmswp_integration/debug_late_filter';
        // init の後に debug で boot する。init 10 の投稿タイプと、フックメソッドの両方を持つ
        $this->writePostTypeClass($config, $postType);
        $this->writeFile($config->appDir . '/Hook/LateHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hook;

use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Attribute\\Filter;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class LateHook extends Hook
{
    #[Filter('{$hook}')]
    public function filter(string \$value): string
    {
        return \$value . '-filtered';
    }
}
PHP);
        $this->registerAppAutoloader($config);
        $before = self::registeredCallbacks();

        try {
            Kernel::boot($config);
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertStringContainsString("after the 'init' hook had fired", $exception->getMessage());
        }

        self::assertSame(array_keys($before), array_keys(self::registeredCallbacks()));
        self::assertFalse(has_filter($hook));
        self::assertFalse(post_type_exists($postType));
        self::assertArrayNotHasKey($namespace, Kernel::all());
    }

    public function testKernelAddsNoDatabaseQueriesToNormalRequests(): void
    {
        $wpdb = self::wpdb();
        $config = $this->productionPluginConfig('QueryCount');
        (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
        $before = self::registeredCallbacks();

        // プラグインの読み込み時(plugins_loaded の前)の状態で boot する。書き換えた $wp_actions は boot の後に戻す
        $actions = $GLOBALS['wp_actions'];
        self::assertIsArray($actions);
        $position = array_search('plugins_loaded', array_keys($actions), true);
        self::assertIsInt($position);
        $GLOBALS['wp_actions'] = \array_slice($actions, 0, $position, true);
        $queries = $wpdb->num_queries;

        try {
            Kernel::boot($config);
        } finally {
            $GLOBALS['wp_actions'] = $actions;
        }

        // Kernel が足したコールバックのうち、通常のリクエストで発火するフックのものを、WordPress と同じく実行する
        $lifecycleHooks = [
            'activate_' . plugin_basename((string) $config->pluginFile),
            'deactivate_' . plugin_basename((string) $config->pluginFile),
            'tmswp/activated',
            'admin_init',
        ];
        $requestCallbacks = array_filter(
            array_diff_key(self::registeredCallbacks(), $before),
            static fn(array $callback): bool => !\in_array($callback['hook'], $lifecycleHooks, true),
        );
        self::assertNotSame([], $requestCallbacks);

        foreach ($requestCallbacks as $callback) {
            self::assertSame(0, $callback['acceptedArgs'], "{$callback['hook']} の引数");
            self::assertIsCallable($callback['function']);
            \call_user_func($callback['function']);
        }

        self::assertSame($queries, $wpdb->num_queries);
    }

    /**
     * init より前の boot は、投稿タイプ・タクソノミー・ロールをその場で登録せず(init 9 / 10 に予約するだけ)、
     * DB にも触れない(即時同期初期化の禁止)。
     */
    public function testBootBeforeInitRegistersNoEntitiesAndQueriesNothing(): void
    {
        $config = $this->productionThemeConfig('BeforeInit');
        $namespace = $config->appNamespace;
        $suffix = substr(md5($namespace), 0, 8);
        $postType = 'tmswp_bi_' . $suffix;
        $taxonomy = 'tmswp_bit_' . $suffix;
        $role = 'tmswp_bir_' . $suffix;
        $this->writeFile($config->appDir . '/Entity/BeforeInitPost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Entity;

use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

final class BeforeInitPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$postType}')->label('Before', 'Befores');
    }
}
PHP);
        $this->writeFile($config->appDir . '/Entity/BeforeInitTaxonomy.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Entity;

use TMSWP\\Core\\Tax\\Taxonomy;
use TMSWP\\Core\\Tax\\TaxonomyDefinition;

final class BeforeInitTaxonomy extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('{$taxonomy}')->label('Before', 'Befores')->objectType(BeforeInitPost::class);
    }
}
PHP);
        $this->writeFile($config->appDir . '/Entity/BeforeInitRole.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Entity;

use TMSWP\\Core\\User\\UserRole;
use TMSWP\\Core\\User\\UserRoleDefinition;

final class BeforeInitRole extends UserRole
{
    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::create('{$role}')->label('Before')->capabilities('read');
    }
}
PHP);
        $this->registerAppAutoloader($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\\Entity\\BeforeInitPost', AsPostType::class, []),
            new DiscoveredComponent($namespace . '\\Entity\\BeforeInitRole', AsUserRole::class, []),
            new DiscoveredComponent($namespace . '\\Entity\\BeforeInitTaxonomy', AsTaxonomy::class, []),
        ], []));
        $wpdb = self::wpdb();
        $before = self::registeredCallbacks();

        // テーマの functions.php の読み込み時(after_setup_theme の前)の状態で boot する。書き換えた $wp_actions は
        // 確かめた後で戻す(戻した後の $wp_actions は init 発火済みを示すため、did_action() はその前に読む)
        $actions = $GLOBALS['wp_actions'];
        self::assertIsArray($actions);
        $position = array_search('after_setup_theme', array_keys($actions), true);
        self::assertIsInt($position);
        $GLOBALS['wp_actions'] = \array_slice($actions, 0, $position, true);

        // 確かめる主張に反して登録された場合も、後のテストに残さないよう必ず取り消す
        try {
            try {
                $queries = $wpdb->num_queries;
                $postTypeRegistrations = did_action('registered_post_type');
                $taxonomyRegistrations = did_action('registered_taxonomy');

                Kernel::boot($config);

                self::assertSame($queries, $wpdb->num_queries);
                self::assertSame($postTypeRegistrations, did_action('registered_post_type'));
                self::assertSame($taxonomyRegistrations, did_action('registered_taxonomy'));
            } finally {
                $GLOBALS['wp_actions'] = $actions;
            }

            self::assertFalse(post_type_exists($postType));
            self::assertFalse(taxonomy_exists($taxonomy));
            self::assertNull(get_role($role));

            // 登録は init に予約されている(タクソノミー 9、投稿タイプ 10、ロール 11、MetaSchema の WordPress への登録 20)
            $initCallbacks = array_values(array_filter(
                array_diff_key(self::registeredCallbacks(), $before),
                static fn(array $callback): bool => $callback['hook'] === 'init',
            ));
            self::assertSame([9, 10, 11, 20], array_column($initCallbacks, 'priority'));

            // 空振りしていないことの確認: 予約されたコールバックで init を発火すると登録される
            self::fireInit($initCallbacks);

            self::assertTrue(post_type_exists($postType));
            self::assertContains($postType, get_taxonomy($taxonomy)->object_type ?? []);
            self::assertNotNull(get_role($role));
        } finally {
            unregister_taxonomy($taxonomy);
            unregister_post_type($postType);
            remove_role($role);
        }
    }

    /**
     * 探索結果ファイルから boot した本番の Kernel では、エンティティのメタスキーマを boot の中で登録するため、init の前
     * でも、app の #[Action('init')](投稿タイプの登録と同じ priority 10 で、それより先に動く)の中でも meta() が使える。
     * WordPress への登録(register_post_meta())は init 20 で行う。
     */
    public function testMetaIsUsableBeforeInitAndFromAppInitActionAfterProductionBoot(): void
    {
        $config = $this->productionThemeConfig('MetaBeforeInit');
        $namespace = $config->appNamespace;
        $postType = 'tmswp_mb_' . substr(md5($namespace), 0, 8);
        $this->writeNotePostClass($config, $postType);
        $this->writeFile($config->appDir . '/Hook/InitMetaHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hook;

use {$namespace}\\Post\\NotePost;
use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class InitMetaHook extends Hook
{
    #[Action('init')]
    public function readNote(): void
    {
        \$postId = apply_filters('tmswp_integration/note_post', 0);
        do_action('tmswp_integration/note_read', NotePost::meta(\$postId)->string(NotePost::META_NOTE), post_type_exists('{$postType}'));
    }
}
PHP);
        $this->registerAppAutoloader($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult(
            [new DiscoveredComponent($namespace . '\\Post\\NotePost', AsPostType::class, [])],
            [new DiscoveredHookMethod($namespace . '\\Hook\\InitMetaHook', 'readNote', 'init', 10, 0, HookMethodKind::Action)],
        ));
        $postId = self::createPost($postType);
        $reads = [];
        add_filter('tmswp_integration/note_post', static fn(): int => $postId);
        add_action('tmswp_integration/note_read', static function (string $note, bool $postTypeExists) use (&$reads): void {
            $reads[] = [$note, $postTypeExists];
        }, 10, 2);

        try {
            $initCallbacks = $this->bootBeforeInit($config);
            $postClass = self::fixtureClass($namespace . '\\Post\\NotePost', PostType::class);

            // init の前: 宣言の既定値を読め、書き込める。WordPress にはまだ登録していない
            self::assertSame('none', $postClass::meta($postId)->string('tmswp_note'));
            self::assertTrue($postClass::meta($postId)->set('tmswp_note', 'written before init'));
            self::assertArrayNotHasKey('tmswp_note', get_registered_meta_keys('post', $postType));

            self::fireInit($initCallbacks);

            // app の init のアクションは投稿タイプの登録より先に動き、そこでも meta() が使える
            self::assertSame([['written before init', false]], $reads);
            self::assertTrue(post_type_exists($postType));
            self::assertArrayHasKey('tmswp_note', get_registered_meta_keys('post', $postType));
        } finally {
            unregister_post_type($postType);
        }
    }

    /**
     * sanitize を宣言したキーは、init 20 でスキーマを WordPress に登録する前には書けず(sanitize を通らない保存を
     * させない)、登録の後は WordPress が sanitize を適用して保存する。sanitize の無いキーは登録の前でも書ける。
     */
    public function testSanitizedMetaCanOnlyBeWrittenAfterRegistrationWithWordPress(): void
    {
        $config = $this->productionThemeConfig('SanitizedMeta');
        $namespace = $config->appNamespace;
        $postType = 'tmswp_sm_' . substr(md5($namespace), 0, 8);
        $this->writeFile($config->appDir . '/Post/TrimmedPost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use TMSWP\\Core\\Attribute\\AsPostType;
use TMSWP\\Core\\Attribute\\MetaSchema;
use TMSWP\\Core\\Meta\\MetaValueType;
use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

#[AsPostType]
final class TrimmedPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String, sanitize: [self::class, 'trimNote'])]
    public const META_TRIMMED = 'tmswp_trimmed';

    #[MetaSchema(type: MetaValueType::String)]
    public const META_PLAIN = 'tmswp_plain';

    public static function trimNote(mixed \$value): string
    {
        return \\is_string(\$value) ? trim(\$value) : '';
    }

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$postType}')->label('Trimmed', 'Trimmed');
    }
}
PHP);
        $this->registerAppAutoloader($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\\Post\\TrimmedPost', AsPostType::class, []),
        ], []));
        $postId = self::createPost($postType);

        try {
            $initCallbacks = $this->bootBeforeInit($config);
            $postClass = self::fixtureClass($namespace . '\\Post\\TrimmedPost', PostType::class);

            try {
                $postClass::meta($postId)->set('tmswp_trimmed', '  before  ');
                self::fail('LogicException was not thrown');
            } catch (LogicException $exception) {
                self::assertStringContainsString("meta 'tmswp_trimmed' of {$postClass} declares sanitize", $exception->getMessage());
                self::assertStringContainsString('init priority 20', $exception->getMessage());
            }

            self::assertSame('', get_post_meta($postId, 'tmswp_trimmed', true));
            self::assertTrue($postClass::meta($postId)->set('tmswp_plain', '  plain  '));

            self::fireInit($initCallbacks);

            self::assertTrue($postClass::meta($postId)->set('tmswp_trimmed', '  after  '));
            self::assertSame('after', get_post_meta($postId, 'tmswp_trimmed', true));
            self::assertSame('  plain  ', get_post_meta($postId, 'tmswp_plain', true));
        } finally {
            unregister_post_type($postType);
        }
    }

    /**
     * #[UseMetaStorage] と #[WithMetaRevisions] は、本番の boot(探索結果ファイルの経路)でも保持される。保存先の
     * 差し替えは init の前の meta() から効き、リビジョンの対象は init 20 の register_post_meta() に渡る。
     */
    public function testUseMetaStorageAndWithMetaRevisionsAreKeptThroughTheDiscoveryFile(): void
    {
        $config = $this->productionThemeConfig('MetaAttributes');
        $namespace = $config->appNamespace;
        $suffix = substr(md5($namespace), 0, 8);
        $storedType = 'tmswp_ms_' . $suffix;
        $revisedType = 'tmswp_mr_' . $suffix;
        $storage = self::fixtureClass(self::THEME_NAMESPACE . '\\Storage\\ArrayMetaStorage', MetaStorageInterface::class);
        $this->writeFile($config->appDir . '/Post/StoredNotePost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use TMSWP\\Core\\Attribute\\AsPostType;
use TMSWP\\Core\\Attribute\\MetaSchema;
use TMSWP\\Core\\Attribute\\UseMetaStorage;
use TMSWP\\Core\\Meta\\MetaValueType;
use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

#[AsPostType]
#[UseMetaStorage(\\{$storage}::class)]
final class StoredNotePost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const META_STORED = 'tmswp_stored_note';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$storedType}')->label('Stored', 'Stored');
    }
}
PHP);
        $this->writeFile($config->appDir . '/Post/RevisedNotePost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use TMSWP\\Core\\Attribute\\AsPostType;
use TMSWP\\Core\\Attribute\\MetaSchema;
use TMSWP\\Core\\Attribute\\WithMetaRevisions;
use TMSWP\\Core\\Meta\\MetaValueType;
use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

#[AsPostType]
#[WithMetaRevisions]
final class RevisedNotePost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const META_REVISED = 'tmswp_revised_note';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$revisedType}')->label('Revised', 'Revised')->supports('title', 'revisions');
    }
}
PHP);
        $this->registerAppAutoloader($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\\Post\\RevisedNotePost', AsPostType::class, []),
            new DiscoveredComponent($namespace . '\\Post\\StoredNotePost', AsPostType::class, []),
        ], []));
        $postId = self::createPost($storedType);

        try {
            $initCallbacks = $this->bootBeforeInit($config);
            $storedClass = self::fixtureClass($namespace . '\\Post\\StoredNotePost', PostType::class);

            // 差し替え先にだけ保存され、WP のメタには書かれない
            self::assertTrue($storedClass::meta($postId)->set('tmswp_stored_note', 'kept'));
            self::assertSame('kept', (new $storage())->get(ObjectType::Post, $postId, 'tmswp_stored_note'));
            self::assertFalse((new WpMetaStorage(self::themeKernel()))->has(ObjectType::Post, $postId, 'tmswp_stored_note'));

            self::fireInit($initCallbacks);

            $revised = get_registered_meta_keys('post', $revisedType);
            self::assertArrayHasKey('tmswp_revised_note', $revised);
            self::assertTrue($revised['tmswp_revised_note']['revisions_enabled']);
            self::assertFalse(get_registered_meta_keys('post', $storedType)['tmswp_stored_note']['revisions_enabled']);
        } finally {
            unregister_post_type($storedType);
            unregister_post_type($revisedType);
        }
    }

    /**
     * ロール(init 11)は投稿タイプ(init 10)の後に登録される。FQCN の順(ここではロールが先)には依らない。
     */
    public function testUserRolesAreRegisteredAfterPostTypes(): void
    {
        $config = $this->productionThemeConfig('RoleOrder');
        $namespace = $config->appNamespace;
        $suffix = substr(md5($namespace), 0, 8);
        $postType = 'tmswp_op_' . $suffix;
        $role = 'tmswp_or_' . $suffix;
        $this->writeFile($config->appDir . '/Entity/AaRole.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Entity;

use TMSWP\\Core\\User\\UserRole;
use TMSWP\\Core\\User\\UserRoleDefinition;

final class AaRole extends UserRole
{
    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::create('{$role}')->label('Order')->capabilities('read');
    }
}
PHP);
        $this->writeFile($config->appDir . '/Entity/ZzPost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Entity;

use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

final class ZzPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$postType}')->label('Order', 'Orders');
    }
}
PHP);
        $this->registerAppAutoloader($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\\Entity\\AaRole', AsUserRole::class, []),
            new DiscoveredComponent($namespace . '\\Entity\\ZzPost', AsPostType::class, []),
        ], []));
        $roleAtPostTypeRegistration = [];
        add_action('registered_post_type', static function (string $registered) use ($postType, $role, &$roleAtPostTypeRegistration): void {
            if ($registered === $postType) {
                $roleAtPostTypeRegistration[] = get_role($role);
            }
        });

        try {
            self::fireInit($this->bootBeforeInit($config));

            self::assertSame([null], $roleAtPostTypeRegistration);
            self::assertNotNull(get_role($role));
        } finally {
            unregister_post_type($postType);
            remove_role($role);
        }
    }

    /**
     * 本番でエンティティの register() が例外(設定の誤り)を出しても、メタスキーマは boot の中で登録済みで、例外は
     * 本番でも記録だけで済ませずに、そのまま init から伝わる(エラー方針 00-overview.md §5.6)。
     */
    public function testRegisterFailureInProductionPropagatesWithMetaSchemaAlreadyRegistered(): void
    {
        $config = $this->productionThemeConfig('RegisterFailure');
        $namespace = $config->appNamespace;
        $missing = 'tmswp_missing_' . substr(md5($namespace), 0, 8);
        $this->writeNotePostClass($config, $missing, builtin: true);
        $this->registerAppAutoloader($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\\Post\\NotePost', AsPostType::class, []),
        ], []));

        $initCallbacks = $this->bootBeforeInit($config);
        $postClass = self::fixtureClass($namespace . '\\Post\\NotePost', PostType::class);
        $registry = Kernel::get($namespace)->container()->get(MetaSchemaRegistry::class);

        self::assertTrue($registry->schemasFor($postClass)->has('tmswp_note'));

        try {
            self::fireInit($initCallbacks);
            self::fail('EntityDefinitionException was not thrown');
        } catch (EntityDefinitionException $exception) {
            self::assertSame("builtin post type does not exist: {$missing}", $exception->getMessage());
        }

        self::assertTrue($registry->schemasFor($postClass)->has('tmswp_note'));
        self::assertSame([], $this->logger->records);
    }

    /**
     * acf-bridgeはinit 6で外部管理のキーを申告する。#[MetaSchema]はbootの中で登録済みのため、宣言と違うストレージでの
     * 同じキーの申告は、その時点でDuplicateMetaKeyExceptionになり、register_post_meta()(init 20)には進まない。
     */
    public function testExternallyManagedKeyMarkedAtInitSixConflictsWithMetaSchemaRegisteredAtBoot(): void
    {
        $config = $this->productionThemeConfig('ExternalKey');
        $namespace = $config->appNamespace;
        $postType = 'tmswp_ek_' . substr(md5($namespace), 0, 8);
        $this->writeNotePostClass($config, $postType);
        $this->registerAppAutoloader($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\\Post\\NotePost', AsPostType::class, []),
        ], []));

        $initCallbacks = $this->bootBeforeInit($config);
        $registry = Kernel::get($namespace)->container()->get(MetaSchemaRegistry::class);
        // acf-bridgeのAcfBridge::markManagedKeys()(init 6)の代わり。宣言側(既定のWpMetaStorage)と違うストレージで申告する
        $mark = ['hook' => 'init', 'priority' => 6, 'function' => static fn() => $registry->markExternallyManaged(ObjectType::Post, $postType, 'tmswp_note', MemoryMetaStorage::class, "field group 'group_note'"), 'acceptedArgs' => 0];

        try {
            self::fireInit([$mark, ...$initCallbacks]);
            self::fail('DuplicateMetaKeyException was not thrown');
        } catch (DuplicateMetaKeyException $exception) {
            self::assertStringStartsWith("externally managed meta key 'tmswp_note' for post:{$postType} is already declared by ", $exception->getMessage());
        }

        // init 6 の例外で init が止まり、投稿タイプの登録(init 10)にも register_post_meta()(init 20)にも進まない
        self::assertFalse(post_type_exists($postType));
        self::assertArrayNotHasKey('tmswp_note', get_registered_meta_keys('post', $postType));
    }

    /**
     * 本番相当(debug=false)の boot が、#[Action]/#[Filter] のメソッドを宣言どおりのフック・priority と、引数の数
     * (Action は宣言した引数の数、Filter は max(1, 宣言した引数の数))で登録し、フックが発火するまでそのクラスを
     * 読み込まない。debug の boot は走査ですべてのクラスを読み込むため、読み込まないことは本番だけで成り立つ。
     */
    public function testProductionBootRegistersHookMethodsAsDeclaredWithoutLoadingTheirClass(): void
    {
        $scanConfig = $this->productionPluginConfig('HookMethodScan');
        $config = $this->productionPluginConfig('HookMethodRegistration');
        $source = static fn(string $namespace): string => <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hook;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Attribute\\Filter;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class RegistrationHook extends Hook
{
    #[Action('tmswp_integration/action_none', 5)]
    public function actionNone(): void
    {
    }

    #[Action('tmswp_integration/action_three', 15)]
    public function actionThree(string \$first, string \$second, string \$third): void
    {
        do_action('tmswp_integration/action_three_handled', \$first . \$second . \$third);
    }

    #[Filter('tmswp_integration/filter_none', 25)]
    public function filterNone(): string
    {
        return 'replaced';
    }

    #[Filter('tmswp_integration/filter_two', 35)]
    public function filterTwo(string \$value, string \$suffix): string
    {
        return \$value . \$suffix;
    }
}
PHP;
        $this->writeFile($scanConfig->appDir . '/Hook/RegistrationHook.php', $source($scanConfig->appNamespace));
        $this->writeFile($config->appDir . '/Hook/RegistrationHook.php', $source($config->appNamespace));
        $this->registerAppAutoloader($scanConfig);
        $this->registerAppAutoloader($config);
        $class = $config->appNamespace . '\\Hook\\RegistrationHook';

        // 探索結果ファイルは、同じソースを別の名前空間に置いて走査した結果から作る。走査はクラスを読み込むため、
        // boot する名前空間のクラスは走査しない
        $scanned = (new Discovery($scanConfig, new DiscoveryFile($scanConfig)))->scan();
        (new DiscoveryFile($config))->write(new DiscoveryResult([], array_map(
            static fn(DiscoveredHookMethod $method): DiscoveredHookMethod => new DiscoveredHookMethod(
                $class,
                $method->method,
                $method->hook,
                $method->priority,
                $method->acceptedArgs,
                $method->kind,
            ),
            $scanned->hookMethods,
        )));
        self::assertFalse(class_exists($class, false));
        $declared = get_declared_classes();

        $this->bootBeforeInit($config);

        $loaded = array_filter(
            array_diff(get_declared_classes(), $declared),
            static fn(string $declaredClass): bool => str_starts_with($declaredClass, $config->appNamespace . '\\'),
        );
        self::assertSame([], array_values($loaded));
        self::assertFalse(class_exists($class, false));

        $filters = $GLOBALS['wp_filter'];
        self::assertIsArray($filters);
        $expected = [
            'tmswp_integration/action_none' => ['priority' => 5, 'acceptedArgs' => 0],
            'tmswp_integration/action_three' => ['priority' => 15, 'acceptedArgs' => 3],
            'tmswp_integration/filter_none' => ['priority' => 25, 'acceptedArgs' => 1],
            'tmswp_integration/filter_two' => ['priority' => 35, 'acceptedArgs' => 2],
        ];

        foreach ($expected as $hook => ['priority' => $priority, 'acceptedArgs' => $acceptedArgs]) {
            $wpHook = $filters[$hook] ?? null;
            self::assertInstanceOf(WP_Hook::class, $wpHook, $hook);
            self::assertSame([$priority], array_keys($wpHook->callbacks), $hook);
            $entries = $wpHook->callbacks[$priority];
            self::assertIsArray($entries, $hook);
            self::assertCount(1, $entries, $hook);
            $entry = array_values($entries)[0];
            self::assertIsArray($entry, $hook);
            self::assertSame($acceptedArgs, $entry['accepted_args'] ?? null, $hook);
        }

        // 最初の発火でクラスが読み込まれ、メソッドは宣言した数の引数で呼ばれる
        self::assertSame('a-b', apply_filters('tmswp_integration/filter_two', 'a', '-b', 'ignored'));
        self::assertTrue(class_exists($class, false));
        self::assertSame('replaced', apply_filters('tmswp_integration/filter_none', 'original'));
        do_action('tmswp_integration/action_three', 'x', 'y', 'z', 'ignored');
        self::assertSame(1, did_action('tmswp_integration/action_three_handled'));
    }

    /**
     * Kernel は各コンポーネントの Initializer を、1リクエスト(WordPress の読み込み)で1回だけ呼ぶ。fixture には、
     * fixture に現れるコンポーネントの属性の種類(AsHook・AsModel・AsOptionGroup・AsTaxonomy・AsPostType・
     * AsUserRole)ごとに、RecordedComponent でその属性を包んだコンポーネントを置いている。スパイの
     * RecordingInitializer が呼び出しを記録してから、本来の Initializer(エンティティ基底の register() を含む)に委ねる。
     */
    public function testKernelCallsEachInitializerExactlyOnceInARequest(): void
    {
        $plugin = self::PLUGIN_NAMESPACE;
        $theme = self::THEME_NAMESPACE;

        // WordPress が発火する順(plugins_loaded → init)、同じフックの中は priority の順に並ぶ
        self::assertSame(
            [
                ['kernel' => $plugin, 'component' => "{$plugin}\\Hook\\SpiedHook", 'hook' => 'plugins_loaded', 'priority' => 0],
                ['kernel' => $plugin, 'component' => "{$plugin}\\Model\\SpiedModel", 'hook' => 'plugins_loaded', 'priority' => 1],
                ['kernel' => $plugin, 'component' => "{$plugin}\\Option\\SpiedOption", 'hook' => 'init', 'priority' => 5],
                ['kernel' => $theme, 'component' => "{$theme}\\Spy\\SpiedTaxonomy", 'hook' => 'init', 'priority' => 9],
                ['kernel' => $theme, 'component' => "{$theme}\\Spy\\SpiedPost", 'hook' => 'init', 'priority' => 10],
                ['kernel' => $theme, 'component' => "{$theme}\\Spy\\SpiedRole", 'hook' => 'init', 'priority' => 11],
            ],
            RecordingInitializer::$calls,
        );

        // 委ねた先の Initializer が登録している
        self::assertNotFalse(has_filter('tmswp_fixture_plugin/spied_hook_registered'));
        self::assertSame(
            [self::fixtureClass("{$plugin}\\Model\\SpiedModel", Model::class)],
            self::pluginKernel()->container()->get(Migrator::class)->models(),
        );
        // OptionRepositoryFactory::preload() は列挙型ごとのリポジトリを作って控える。SpiedOption は #[AsOptionGroup] も
        // 持つため、控えがあることだけでは、どちらのコンポーネントの初期化で作られたかは区別できない
        $repositories = (new ReflectionProperty(OptionRepositoryFactory::class, 'repositories'))
            ->getValue(self::pluginKernel()->container()->get(OptionRepositoryFactory::class));
        self::assertIsArray($repositories);
        self::assertArrayHasKey("{$plugin}\\Option\\SpiedOption", $repositories);
        self::assertTrue(taxonomy_exists('tmswp_spied_tax'));
        self::assertTrue(post_type_exists('tmswp_spied'));
        self::assertContains('tmswp_spied', get_taxonomy('tmswp_spied_tax')->object_type ?? []);
        self::assertNotNull(get_role('tmswp_spied'));
    }

    /**
     * テーマとプラグインの2つの Kernel は、Container・探索結果ファイル・components を共有しない。統合テストは
     * Kernel のレジストリを戻さない(他のテストが boot した Kernel も残る)ため、Kernel::all() は件数ではなく
     * 2つの Kernel が含まれることで確かめる。
     */
    public function testThemeAndPluginKernelsAreIndependent(): void
    {
        $theme = self::themeKernel();
        $plugin = self::pluginKernel();
        $all = Kernel::all();

        self::assertSame($theme, $all[self::THEME_NAMESPACE] ?? null);
        self::assertSame($plugin, $all[self::PLUGIN_NAMESPACE] ?? null);

        self::assertNotSame($theme->container(), $plugin->container());
        self::assertSame($theme, $theme->container()->get(Kernel::class));
        self::assertSame($plugin, $plugin->container()->get(Kernel::class));

        self::assertSame(get_stylesheet_directory() . '/tmswp-discovery.php', $theme->container()->get(DiscoveryFile::class)->path());
        self::assertSame(\dirname((string) $plugin->config()->pluginFile) . '/tmswp-discovery.php', $plugin->container()->get(DiscoveryFile::class)->path());

        $themeClasses = array_map(static fn(DiscoveredComponent $component): string => $component->class, $theme->components());
        $pluginClasses = array_map(static fn(DiscoveredComponent $component): string => $component->class, $plugin->components());
        self::assertNotSame([], $themeClasses);
        self::assertNotSame([], $pluginClasses);
        self::assertSame([], array_values(array_intersect($themeClasses, $pluginClasses)));

        foreach ($themeClasses as $class) {
            self::assertStringStartsWith(self::THEME_NAMESPACE . '\\', $class);
        }

        foreach ($pluginClasses as $class) {
            self::assertStringStartsWith(self::PLUGIN_NAMESPACE . '\\', $class);
        }
    }

    /**
     * テーマ形態・プラグイン形態の Kernel は、template_include にコールバックを登録しない(テンプレート階層に介入しない)。
     */
    public function testKernelsDoNotHookTemplateInclude(): void
    {
        $before = self::templateIncludeCallbacks();

        foreach ([$this->productionThemeConfig('TemplateIncludeTheme'), $this->productionPluginConfig('TemplateIncludePlugin')] as $config) {
            (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
            $this->bootBeforeInit($config);

            self::assertSame($before, self::templateIncludeCallbacks(), $config->appNamespace);
        }
    }

    public function testFixtureKernelsAreBootedInDebugModeByWordPressLoading(): void
    {
        $theme = self::themeKernel()->config();
        $plugin = self::pluginKernel()->config();

        // debug 時の遅延 boot は例外になるため、debug=true で boot 済みであること自体が init 前の boot を示す
        self::assertSame(KernelContext::Theme, $theme->context);
        self::assertTrue($theme->debug);
        self::assertSame(get_stylesheet_directory() . '/app', $theme->appDir);

        self::assertSame(KernelContext::Plugin, $plugin->context);
        self::assertTrue($plugin->debug);
        self::assertNotNull($plugin->pluginFile);
        $activePlugins = get_option('active_plugins');
        self::assertIsArray($activePlugins);
        self::assertContains(plugin_basename($plugin->pluginFile), $activePlugins);
    }

    public function testCommittedFixtureDiscoveryFilesMatchTheScan(): void
    {
        foreach ([self::themeKernel(), self::pluginKernel()] as $kernel) {
            $file = $kernel->container()->get(DiscoveryFile::class);
            $discovery = $kernel->container()->get(Discovery::class);

            $scanned = $discovery->scan();

            self::assertSame(\dirname($kernel->config()->appDir) . '/tmswp-discovery.php', $file->path());
            self::assertEquals($scanned, $file->load());
            self::assertTrue($file->isUpToDate($scanned), "{$file->path()} is out of date; run composer discover:build");
        }

        $hookClass = self::PLUGIN_NAMESPACE . '\\Hook\\PluginProbeHook';
        self::assertEquals(
            [
                new DiscoveredHookMethod($hookClass, 'filter', 'tmswp_fixture_plugin/filter', 10, 1, HookMethodKind::Filter),
                new DiscoveredHookMethod($hookClass, 'handle', 'tmswp_fixture_plugin/action', 10, 0, HookMethodKind::Action),
            ],
            $this->pluginDiscoveryFile()->load()->hookMethods,
        );
    }

    public function testBootFailsWithoutDiscoveryFileWhenDebugIsDisabled(): void
    {
        $config = $this->productionPluginConfig('MissingDiscoveryFile');

        try {
            $this->bootBeforeInit($config);
            self::fail('DiscoveryException was not thrown');
        } catch (DiscoveryException $exception) {
            self::assertStringContainsString("discovery file not found: {$config->discoveryFile()}", $exception->getMessage());
            self::assertStringContainsString('wp tmswp discover', $exception->getMessage());
        }

        self::assertArrayNotHasKey($config->appNamespace, Kernel::all());
    }

    public function testBootUsesDiscoveryFileWithoutScanningWhenDebugIsDisabled(): void
    {
        $config = $this->productionPluginConfig('DiscoveryFileOnly');
        $scannedHook = 'tmswp_integration/scanned_only';
        $fileOnlyHook = 'tmswp_integration/discovery_file_only';

        // app/ には走査すれば見つかる Hook クラスを置き、探索結果ファイルには app/ に無いクラスのフックだけを書く
        $this->writeFile($config->appDir . '/Hook/ScannedHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$config->appNamespace}\\Hook;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class ScannedHook extends Hook
{
    #[Action('{$scannedHook}')]
    public function handle(): void
    {
    }
}
PHP);
        (new DiscoveryFile($config))->write(new DiscoveryResult([], [
            new DiscoveredHookMethod($config->appNamespace . '\\Hook\\FileOnlyHook', 'handle', $fileOnlyHook, 15, 0, HookMethodKind::Action),
        ]));

        $this->bootBeforeInit($config);

        $filters = $GLOBALS['wp_filter'];
        self::assertIsArray($filters);
        $registered = $filters[$fileOnlyHook] ?? null;
        self::assertInstanceOf(WP_Hook::class, $registered);
        self::assertSame([15], array_keys($registered->callbacks));
        self::assertFalse(has_filter($scannedHook));
        self::assertFalse(class_exists($config->appNamespace . '\\Hook\\ScannedHook', false));
        self::assertArrayHasKey($config->appNamespace, Kernel::all());

        // 探索結果ファイルを読むだけで走査しないため、探索の失敗を含めて何も記録しない
        self::assertSame([], $this->logger->records);
    }

    public function testThemeAndPluginKernelsEachRegisterTheSameExtensionClass(): void
    {
        $themeExtension = self::themeKernel()->config()->extensions[0] ?? null;
        $pluginExtension = self::pluginKernel()->config()->extensions[0] ?? null;

        self::assertInstanceOf(RecordingExtension::class, $themeExtension);
        self::assertInstanceOf(RecordingExtension::class, $pluginExtension);
        self::assertNotSame($themeExtension, $pluginExtension);
        // 拡張は自分を登録した Kernel の boot 中に1回だけ呼ばれ、その Kernel の debug を受け取る
        self::assertSame([['kernel' => self::themeKernel(), 'debug' => true]], $themeExtension->registrations);
        self::assertSame([['kernel' => self::pluginKernel(), 'debug' => true]], $pluginExtension->registrations);
    }

    public function testStaticLogCallsAreRecordedInTheKernelOfTheCallerFile(): void
    {
        $themeLogger = self::fixtureLogger(self::themeKernel());
        $pluginLogger = self::fixtureLogger(self::pluginKernel());
        $themeBefore = \count($themeLogger->records);
        $pluginBefore = \count($pluginLogger->records);

        // テーマ・プラグインそれぞれのルートの中にある、クラスの無いファイルから呼ぶ
        require get_stylesheet_directory() . '/parts/log-probe.php';
        require \dirname((string) self::pluginKernel()->config()->pluginFile) . '/templates/log-probe.php';

        self::assertSame(['tmswp fixture theme log probe'], array_column(\array_slice($themeLogger->records, $themeBefore), 'message'));
        self::assertSame(['tmswp fixture plugin log probe'], array_column(\array_slice($pluginLogger->records, $pluginBefore), 'message'));
    }

    public function testStaticLogCallFromFileOutsideEveryKernelRootThrows(): void
    {
        $file = realpath($this->createTempDirectory('integration-log-outside')) . '/outside.php';
        $this->writeFile($file, "<?php\n\ndeclare(strict_types=1);\n\n\\TMSWP\\Core\\Log\\Log::error('lost');\n");

        try {
            require $file;
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertSame("file '{$file}' is not inside the root directory of any booted TMSWP kernel", $exception->getMessage());
        }
    }

    public function testInternalLogsAreRecordedInTheKernelTheyHandle(): void
    {
        $themeLogger = self::fixtureLogger(self::themeKernel());
        $themeBefore = \count($themeLogger->records);
        $config = $this->productionPluginConfig('InternalLog');
        (new DiscoveryFile($config))->write(new DiscoveryResult([], []));

        // init の後の本番の boot は、遅延 boot のキャッチアップを、その Kernel のログに記録する
        Kernel::boot($config);

        self::assertContains(
            "Kernel::boot() was called after the 'init' hook had fired. Boot from the top level of functions.php or the plugin main file.",
            array_column($this->logger->records, 'message'),
        );
        self::assertCount($themeBefore, $themeLogger->records);
    }

    public function testBootDetectsAnotherVendorBundlingADifferentCoreVersion(): void
    {
        // 別のプラグインが vendor/ に別の版の tmswp/core を同梱し、その Composer のオートローダが登録された状態
        $vendor = $this->createTempDirectory('integration-other-vendor') . '/vendor';
        self::assertTrue(mkdir($vendor . '/composer', 0775, true));
        $this->writeFile($vendor . '/composer/installed.php', '<?php return ' . var_export([
            'root' => ['name' => 'example/other-plugin', 'pretty_version' => 'dev-main', 'version' => 'dev-main', 'reference' => null, 'type' => 'wordpress-plugin', 'install_path' => $vendor . '/composer/../../', 'aliases' => [], 'dev' => false],
            'versions' => [
                'tmswp/core' => ['pretty_version' => 'v0.1.0', 'version' => '0.1.0.0', 'reference' => null, 'type' => 'library', 'install_path' => $vendor . '/composer/../tmswp/core', 'aliases' => [], 'dev_requirement' => false],
            ],
        ], true) . ';');
        $loader = new ClassLoader($vendor);
        $loader->register();
        $config = $this->productionPluginConfig('VersionMismatch');
        (new DiscoveryFile($config))->write(new DiscoveryResult([], []));

        try {
            $this->bootBeforeInit($config);
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertStringContainsString('do not match the loaded core ' . Kernel::VERSION . ": v0.1.0 at {$vendor}/composer/../tmswp/core", $exception->getMessage());
        } finally {
            $loader->unregister();
        }

        self::assertArrayNotHasKey($config->appNamespace, Kernel::all());
    }

    public function testKernelLabelLanguageAppliesToDefinitionsWithoutLabelLanguage(): void
    {
        $config = $this->productionThemeConfig('JapaneseLabels', LabelLanguage::Ja);
        $namespace = $config->appNamespace;
        $postType = 'tmswp_ja_' . substr(md5($namespace), 0, 8);
        // labelLanguage() を呼ばず、複数形も渡さない(Kernel の既定の Ja で組み立てる)
        $this->writeFile($config->appDir . '/Post/JapanesePost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use TMSWP\\Core\\Attribute\\AsPostType;
use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

#[AsPostType]
final class JapanesePost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$postType}')->label('お知らせ');
    }
}
PHP);
        $this->registerAppAutoloader($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\\Post\\JapanesePost', AsPostType::class, []),
        ], []));

        try {
            self::fireInit($this->bootBeforeInit($config));
            $object = get_post_type_object($postType);

            self::assertNotNull($object);
            self::assertSame('お知らせ', $object->label);
            self::assertSame('新規お知らせを追加', $object->labels->add_new_item);
        } finally {
            unregister_post_type($postType);
        }
    }

    /** fixture のテーマ・プラグインが test チャネルに登録した RecordingLogger を返す。 */
    private static function fixtureLogger(Kernel $kernel): RecordingLogger
    {
        $logger = $kernel->logger()->channel('test');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }

    /**
     * template_include に登録されたコールバックを返す。
     *
     * @return list<mixed>
     */
    private static function templateIncludeCallbacks(): array
    {
        $functions = [];

        foreach (self::registeredCallbacks() as $callback) {
            if ($callback['hook'] === 'template_include') {
                $functions[] = $callback['function'];
            }
        }

        return $functions;
    }

    /**
     * 次のリクエストでルールが必要になったときの rewrite_rules を返す。
     *
     * 同じプロセスの WP_Rewrite は、ルールを作るたびに CPT のパーマリンク構造から作ったルールを extra_rules_top に
     * 足していき、unregister_post_type() でもその一部が残る。そのため次のリクエストと同じく新しい WP_Rewrite を作り、
     * 渡した投稿タイプのうち登録されているもののルールを登録し直してから、wp_loaded の後にルールを求める
     * (rewrite_rules が空なら作り直して保存する)。$wp_rewrite は呼び出しの後に元へ戻す。
     *
     * fixture の投稿タイプやコアのタクソノミーは、パーマリンクを設定していない状態で init に登録されており
     * (WordPress はそのとき rewrite の設定を解決しない)登録し直せないため、ここでは扱わない。
     *
     * @return array<string> 正規表現 => クエリ
     */
    private function nextRequestRewriteRules(string $postType): array
    {
        $current = self::rewrite();
        $next = new WP_Rewrite();
        $GLOBALS['wp_rewrite'] = $next;

        try {
            get_post_type_object($postType)?->add_rewrite_rules();
            $rules = $next->wp_rewrite_rules();
        } finally {
            $GLOBALS['wp_rewrite'] = $current;
        }

        self::assertIsArray($rules);

        return $rules;
    }

    private static function rewrite(): WP_Rewrite
    {
        $rewrite = $GLOBALS['wp_rewrite'];
        self::assertInstanceOf(WP_Rewrite::class, $rewrite);

        return $rewrite;
    }

    /**
     * CPT を1つ持つ一時ディレクトリのテーマ・プラグインを、本番相当(debug=false)で init より前に boot して init を発火し、
     * 投稿タイプ名を返す。
     */
    private function bootProductionKernelWithPostType(KernelConfig $config): string
    {
        $namespace = $config->appNamespace;
        $postType = 'tmswp_rw_' . substr(md5($namespace), 0, 8);
        $this->writePostTypeClass($config, $postType);
        $this->registerAppAutoloader($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\\Post\\RewritePost', AsPostType::class, []),
        ], []));

        self::fireInit($this->bootBeforeInit($config));
        self::assertTrue(post_type_exists($postType));

        return $postType;
    }

    /**
     * 文字列のメタ tmswp_note(既定値 'none')を宣言する投稿タイプのクラス(app/Post/NotePost.php)を書く。$builtin なら
     * $postType の builtin のアダプタにする。
     */
    private function writeNotePostClass(KernelConfig $config, string $postType, bool $builtin = false): void
    {
        $namespace = $config->appNamespace;
        $definition = $builtin ? "PostTypeDefinition::builtin('{$postType}')" : "PostTypeDefinition::create('{$postType}')->label('Note', 'Notes')";
        $this->writeFile($config->appDir . '/Post/NotePost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use TMSWP\\Core\\Attribute\\AsPostType;
use TMSWP\\Core\\Attribute\\MetaSchema;
use TMSWP\\Core\\Meta\\MetaValueType;
use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

#[AsPostType]
final class NotePost extends PostType
{
    #[MetaSchema(type: MetaValueType::String, default: 'none')]
    public const META_NOTE = 'tmswp_note';

    public static function definition(): PostTypeDefinition
    {
        return {$definition};
    }
}
PHP);
    }

    /** アーカイブを持つ投稿タイプのクラス(app/Post/RewritePost.php)を書く。 */
    private function writePostTypeClass(KernelConfig $config, string $postType): void
    {
        $namespace = $config->appNamespace;
        $this->writeFile($config->appDir . '/Post/RewritePost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use TMSWP\\Core\\Attribute\\AsPostType;
use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

#[AsPostType]
final class RewritePost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$postType}')
            ->label('Rewrite', 'Rewrites')
            ->hasArchive();
    }
}
PHP);
    }

    /**
     * 本番相当(debug=false)のテーマ形態の構成を、一時ディレクトリのテーマで作る。テーマ切替のフックに登録される
     * コールバックは、テストライブラリが tear_down でフックごと元に戻す。
     */
    private function productionThemeConfig(string $name, LabelLanguage $labelLanguage = LabelLanguage::En): KernelConfig
    {
        $root = $this->createTempDirectory('integration-' . strtolower($name));
        self::assertTrue(mkdir($root . '/app'));

        return KernelConfig::theme($root . '/app', 'TmswpIntegration' . $name . uniqid(), false, logChannels: $this->recordLogs(), labelLanguage: $labelLanguage);
    }

    private function pluginDiscoveryFile(): DiscoveryFile
    {
        return self::pluginKernel()->container()->get(DiscoveryFile::class);
    }

    /**
     * 本番相当(debug=false)のプラグイン形態の構成を、一時ディレクトリのプラグインで作る。テーマ形態にしないのは、
     * テーマ切替のフック(他のテストが発火させる)にこの Kernel を登録しないためである。
     */
    private function productionPluginConfig(string $name): KernelConfig
    {
        $root = $this->createTempDirectory('integration-' . strtolower($name));
        self::assertTrue(mkdir($root . '/app'));
        $this->writeFile($root . '/plugin.php', "<?php\n");

        return KernelConfig::plugin($root . '/plugin.php', $root . '/app', 'TmswpIntegration' . $name . uniqid(), false, logChannels: $this->recordLogs());
    }

    /**
     * Kernel のログを、このテストの RecordingLogger へ送る logChannels を返す(ファイルに書かない)。
     *
     * @return Closure(): array<string, LogChannel>
     */
    private function recordLogs(): Closure
    {
        $logger = $this->logger;

        return static fn(): array => ['integration' => new LogChannel($logger)];
    }
}
