<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use ArrayObject;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\AsTaxonomy;
use TMSWP\Core\Attribute\AsUserRole;
use TMSWP\Core\Exception\EntityDataException;
use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\QueryException;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use TMSWP\Core\Post\Thumbnail;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tax\TaxonomyDefinition;
use TMSWP\Core\User\UserRole;
use TMSWP\Core\User\UserRoleDefinition;
use TMSWP\Testing\BootsKernelBeforeInit;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UsesTempDirectories;
use WP_Error;
use WP_Hook;

/**
 * エンティティの builtin のアダプタ・ロールの差分の通知・キャッチアップでの再試行・ファサードの実行時の扱い
 * (Thumbnail の属性、親の深さの超過、ブロックエディタのフィルタ)を、実物の WordPress で確かめる。
 */
final class EntityRuntimeIntegrationTest extends IntegrationTestCase
{
    use BootsKernelBeforeInit;
    use UsesTempDirectories;

    private const string LATE_BOOT_MESSAGE = "Kernel::boot() was called after the 'init' hook had fired. Boot from the top level of functions.php or the plugin main file.";

    /** productionPluginConfig() の Kernel のログを記録するロガー */
    private RecordingLogger $logger;

    public function set_up(): void
    {
        parent::set_up();
        $this->logger = new RecordingLogger();
    }

    public function tear_down(): void
    {
        $this->unregisterAppAutoloaders();
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    public function testBuiltinPostTypeAdapterToMissingPostTypeThrowsWithoutAddingSupports(): void
    {
        try {
            MissingBuiltinPost::register(self::themeKernel());
            self::fail('EntityDefinitionException was not thrown');
        } catch (EntityDefinitionException $exception) {
            self::assertSame('builtin post type does not exist: tmswp_no_such_type', $exception->getMessage());
        }

        // 例外の前に supports を足していない
        self::assertFalse(post_type_supports('tmswp_no_such_type', 'title'));
    }

    public function testBuiltinTaxonomyAdapterToMissingTaxonomyThrowsWithOrWithoutObjectType(): void
    {
        foreach ([MissingBuiltinTaxonomy::class, MissingBuiltinTaxonomyWithObjectType::class] as $class) {
            try {
                $class::register(self::themeKernel());
                self::fail("EntityDefinitionException was not thrown for {$class}");
            } catch (EntityDefinitionException $exception) {
                self::assertSame('builtin taxonomy does not exist: tmswp_no_such_tax', $exception->getMessage());
            }
        }

        self::assertNotContains('tmswp_no_such_tax', get_object_taxonomies('post'));
    }

    public function testBuiltinRoleAdapterToMissingRoleThrows(): void
    {
        try {
            MissingBuiltinRole::register(self::themeKernel());
            self::fail('EntityDefinitionException was not thrown');
        } catch (EntityDefinitionException $exception) {
            self::assertSame('builtin role does not exist: tmswp_no_such_role', $exception->getMessage());
        }

        self::assertNull(get_role('tmswp_no_such_role'));
    }

    /**
     * 他のプラグインが use_block_editor_for_post_type に bool 以外(null)を返しても落ちない。対象外の投稿タイプの値は
     * 受け取ったまま返し、対象の投稿タイプには宣言した値を返す。
     */
    public function testBlockEditorPreferencePassesValuesOfOtherPostTypesThroughUnchanged(): void
    {
        BlockEditorOffPage::register(self::themeKernel());

        self::assertNull(apply_filters('use_block_editor_for_post_type', null, 'post'));
        self::assertTrue(apply_filters('use_block_editor_for_post_type', true, 'post'));
        self::assertFalse(apply_filters('use_block_editor_for_post_type', null, 'page'));
        self::assertFalse(apply_filters('use_block_editor_for_post_type', true, 'page'));
    }

    /**
     * init より前に boot した Kernel では、builtin のタクソノミーの紐付けで init 9 の初回に失敗した投稿タイプだけを、
     * init 11 で1回だけ再試行する。同じ Kernel が init 10 で登録する投稿タイプには警告なしで紐付き、登録されない
     * 投稿タイプは、再試行の後に渡された Kernel のロガーに warning が1回記録される。初回に成功した投稿タイプは
     * 再試行しない。
     */
    public function testBuiltinTaxonomyRetriesOnlyTheFailedPostTypesAtInitEleven(): void
    {
        $config = $this->productionPluginConfig('BuiltinRetryBeforeInit');
        $namespace = $config->appNamespace;
        $suffix = substr(md5($namespace), 0, 8);
        $postType = 'tmswp_rb_' . $suffix;
        $missing = 'tmswp_missing_' . $suffix;
        $this->writeFile($config->appDir . '/Post/RetryPost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

final class RetryPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$postType}')->label('Retry', 'Retries');
    }
}
PHP);
        $this->writeFile($config->appDir . '/Tax/CategoryAdapter.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Tax;

use {$namespace}\\Post\\RetryPost;
use TMSWP\\Core\\Tax\\Taxonomy;
use TMSWP\\Core\\Tax\\TaxonomyDefinition;

final class CategoryAdapter extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::builtin('category')->objectType('page', RetryPost::class, '{$missing}');
    }
}
PHP);
        $this->registerAppAutoloader($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\\Post\\RetryPost', AsPostType::class, []),
            new DiscoveredComponent($namespace . '\\Tax\\CategoryAdapter', AsTaxonomy::class, []),
        ], []));
        // 紐付けに成功した投稿タイプと、そのときの init の priority を記録する
        $attached = [];
        add_action('registered_taxonomy_for_object_type', static function (string $taxonomy, string $objectType) use (&$attached): void {
            $filters = $GLOBALS['wp_filter'];
            $init = \is_array($filters) ? ($filters['init'] ?? null) : null;

            if ($taxonomy === 'category') {
                $attached[] = [$objectType, $init instanceof WP_Hook ? $init->current_priority() : false];
            }
        }, 10, 2);

        try {
            self::fireInit($this->bootBeforeInit($config));

            self::assertSame([['page', 9], [$postType, 11]], $attached);
            self::assertContains('category', get_object_taxonomies($postType));
            self::assertSame(
                [['warning', "failed to attach taxonomy 'category' to post type '{$missing}'"]],
                array_map(static fn(array $record): array => [$record['level'], $record['message']], $this->logger->records),
            );
        } finally {
            unregister_taxonomy_for_object_type('category', 'page');
            unregister_taxonomy_for_object_type('category', $postType);
            unregister_post_type($postType);
        }
    }

    /**
     * init の後の本番の boot(キャッチアップ)でも、builtin のタクソノミーの紐付けの再試行が、同じ Kernel の投稿
     * タイプの登録(init 10)の後、MetaSchema の登録(init 20)の前に実行される。紐付け先が登録された投稿タイプは
     * 警告なしで紐付き、登録されない投稿タイプは warning が1回記録される。
     */
    public function testCatchUpRetriesBuiltinTaxonomyAfterRegisteringPostTypes(): void
    {
        $config = $this->productionPluginConfig('BuiltinRetry');
        $namespace = $config->appNamespace;
        $suffix = substr(md5($namespace), 0, 8);
        $postType = 'tmswp_rt_' . $suffix;
        $missing = 'tmswp_missing_' . $suffix;
        $this->writeFile($config->appDir . '/Post/RetryPost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

final class RetryPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$postType}')->label('Retry', 'Retries');
    }
}
PHP);
        $this->writeFile($config->appDir . '/Tax/CategoryAdapter.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Tax;

use {$namespace}\\Post\\RetryPost;
use TMSWP\\Core\\Tax\\Taxonomy;
use TMSWP\\Core\\Tax\\TaxonomyDefinition;

final class CategoryAdapter extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::builtin('category')->objectType(RetryPost::class, '{$missing}');
    }
}
PHP);
        $this->registerAppAutoloader($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\\Post\\RetryPost', AsPostType::class, []),
            new DiscoveredComponent($namespace . '\\Tax\\CategoryAdapter', AsTaxonomy::class, []),
        ], []));

        try {
            Kernel::boot($config);

            self::assertContains('category', get_object_taxonomies($postType));
            self::assertSame(
                [
                    ['error', self::LATE_BOOT_MESSAGE],
                    ['error', self::LATE_BOOT_MESSAGE],
                    ['warning', "failed to attach taxonomy 'category' to post type '{$missing}'"],
                    ['error', self::LATE_BOOT_MESSAGE],
                ],
                array_map(static fn(array $record): array => [$record['level'], $record['message']], $this->logger->records),
            );
        } finally {
            unregister_taxonomy_for_object_type('category', $postType);
            unregister_post_type($postType);
        }
    }

    /**
     * supports('thumbnail') を持つ複数の投稿タイプを登録すると、post-thumbnails のテーマサポートは、既に宣言された
     * 投稿タイプとそれらの和集合になる。thumbnail を持たない投稿タイプは加わらない。
     */
    public function testThumbnailSupportOfPostTypesIsMergedIntoThemeSupport(): void
    {
        $config = $this->productionPluginConfig('ThumbnailSupport');
        $namespace = $config->appNamespace;
        $suffix = substr(md5($namespace), 0, 8);
        $postTypes = ['First' => "tmswp_tf_{$suffix}", 'Second' => "tmswp_ts_{$suffix}", 'Plain' => "tmswp_tp_{$suffix}"];
        $supports = ['First' => "'title', 'thumbnail'", 'Second' => "'thumbnail'", 'Plain' => "'title'"];
        $components = [];

        foreach ($postTypes as $name => $postType) {
            $this->writeFile($config->appDir . "/Post/{$name}Post.php", <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

final class {$name}Post extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$postType}')->label('{$name}', '{$name}s')->supports({$supports[$name]});
    }
}
PHP);
            $components[] = new DiscoveredComponent("{$namespace}\\Post\\{$name}Post", AsPostType::class, []);
        }

        $this->registerAppAutoloader($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult($components, []));
        $features = $GLOBALS['_wp_theme_features'];
        self::assertIsArray($features);
        // fixture のテーマの ProductPost(supports('thumbnail'))が、WordPress の読み込みで宣言している
        $declared = self::thumbnailPostTypes();
        self::assertContains('tmswp_product', $declared);

        try {
            self::fireInit($this->bootBeforeInit($config));

            self::assertEqualsCanonicalizing([...$declared, $postTypes['First'], $postTypes['Second']], self::thumbnailPostTypes());
        } finally {
            $GLOBALS['_wp_theme_features'] = $features;

            foreach ($postTypes as $postType) {
                unregister_post_type($postType);
            }
        }
    }

    /**
     * ロールの登録は、DB(ロールのオプション)に書くのを初回だけにする。2回目以降のリクエスト(DB から読み直した
     * ロール)の register() は、get_role()・has_cap() の確認により update_option() を呼ばない。
     */
    public function testUserRoleRegistrationWritesToTheDatabaseOnlyOnTheFirstRequest(): void
    {
        $config = $this->productionPluginConfig('RoleIdempotency');
        $namespace = $config->appNamespace;
        $role = 'tmswp_ri_' . substr(md5($namespace), 0, 8);
        $this->writeFile($config->appDir . '/User/IdempotentRole.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\User;

use TMSWP\\Core\\User\\UserRole;
use TMSWP\\Core\\User\\UserRoleDefinition;

final class IdempotentRole extends UserRole
{
    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::create('{$role}')->label('Idempotent')->capabilities('read', 'upload_files');
    }
}
PHP);
        $this->registerAppAutoloader($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\\User\\IdempotentRole', AsUserRole::class, []),
        ], []));
        $writes = new ArrayObject();
        add_filter('pre_update_option_' . wp_roles()->role_key, static function (mixed $value) use ($writes): mixed {
            $writes->append($value);

            return $value;
        });

        try {
            // 1回目のリクエスト: add_role() と add_cap() が DB に書く
            self::fireInit($this->bootBeforeInit($config));
            self::assertNotCount(0, $writes);

            // 2回目のリクエストと同じく、ロールを DB から読み直してから登録する
            wp_roles()->for_site();
            $writes->exchangeArray([]);
            self::fixtureClass($namespace . '\\User\\IdempotentRole', UserRole::class)::register(Kernel::get($namespace));

            self::assertCount(0, $writes);
            $registered = get_role($role);
            self::assertNotNull($registered);
            self::assertSame(['read' => true, 'upload_files' => true], $registered->capabilities);
        } finally {
            remove_role($role);
        }
    }

    public function testRoleDriftFromDeclarationIsReportedAsWarnings(): void
    {
        $logger = self::fixtureLogger();
        $before = \count($logger->records);
        // 宣言(表示名 New label・cap は read と upload_files)と違う状態のロールが DB にある
        add_role('tmswp_drift', 'Old label', ['read' => true, 'edit_posts' => true, 'tmswp_legacy' => false]);

        try {
            DriftRole::register(self::themeKernel());

            self::assertSame(
                [
                    [
                        'level' => 'warning',
                        'message' => 'tmswp.user_role: the label of role {role} in the database ({stored}) differs from the declared label ({declared}); add_role() runs only once, so remove the role to apply the declared label',
                        'context' => ['role' => 'tmswp_drift', 'stored' => 'Old label', 'declared' => 'New label'],
                    ],
                    [
                        'level' => 'warning',
                        'message' => 'tmswp.user_role: role {role} has capabilities that are not declared: {capabilities}; capabilities removed from the declaration stay in the database until removed explicitly',
                        'context' => ['role' => 'tmswp_drift', 'capabilities' => 'edit_posts, tmswp_legacy'],
                    ],
                ],
                \array_slice($logger->records, $before),
            );
            // 通知するだけで、DB の表示名と cap は消さない。宣言の cap は足す
            $role = get_role('tmswp_drift');
            self::assertNotNull($role);
            self::assertSame('Old label', wp_roles()->role_names['tmswp_drift']);
            self::assertTrue($role->has_cap('edit_posts'));
            self::assertTrue($role->has_cap('upload_files'));
        } finally {
            remove_role('tmswp_drift');
        }
    }

    /** 初回の add_role・宣言どおりの既存ロール・宣言に無い cap を持つ builtin のロールでは、差分を記録しない。 */
    public function testRoleWithoutDriftIsNotReported(): void
    {
        $logger = self::fixtureLogger();
        $before = \count($logger->records);
        add_role('tmswp_in_sync', 'In sync', ['read' => true, 'upload_files' => true]);

        try {
            NewRole::register(self::themeKernel());
            InSyncRole::register(self::themeKernel());
            BuiltinEditorRole::register(self::themeKernel());

            self::assertNotNull(get_role('tmswp_new_role'));
            self::assertSame([], \array_slice($logger->records, $before));
        } finally {
            remove_role('tmswp_new_role');
            remove_role('tmswp_in_sync');
        }
    }

    public function testThumbnailImgRendersAttributesWithHtmlRenderer(): void
    {
        $thumbnail = new Thumbnail('https://example.com/a.jpg?x=1&y=2', 300, null, 'A "quoted" alt');

        self::assertSame(
            '<img src="https://example.com/a.jpg?x=1&#038;y=2" alt="A &quot;quoted&quot; alt" class="hero wide" loading="lazy" hidden width="300">',
            $thumbnail->img([
                'class' => ['hero', 'wide'],
                'loading' => 'lazy',
                'decoding' => null,
                'hidden' => true,
                'inert' => false,
                // src・alt・width・height は大文字小文字に関係なく上書きできない
                'SRC' => 'https://example.com/other.jpg',
                'Width' => 1,
            ]),
        );
    }

    public function testThumbnailFromFallbackFilterRejectsDisallowedProtocolOnRender(): void
    {
        $productPost = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\ProductPost', PostType::class);
        $postId = self::createPost($productPost::slug());
        add_filter('tmswp/post/thumbnail_fallback', static fn(): Thumbnail => new Thumbnail('javascript:alert(1)', null, null, ''));

        $thumbnail = $productPost::thumbnail($postId);
        self::assertInstanceOf(Thumbnail::class, $thumbnail);

        try {
            $thumbnail->img();
            self::fail('InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('HTML attribute src has a URL with a disallowed protocol: javascript:alert(1)', $exception->getMessage());
        }
    }

    /** debug の Kernel(fixture のテーマ)では、親の循環による深さの超過は EntityDataException になる。 */
    public function testParentDepthExceededThrowsEntityDataExceptionInDebug(): void
    {
        $productPost = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\ProductPost', PostType::class);
        $category = self::fixtureClass(self::THEME_NAMESPACE . '\\Tax\\ProductCategoryTaxonomy', Taxonomy::class);
        $postId = $this->createPostCycle($productPost::slug());
        $termId = $this->createTermCycle($category::slug());

        try {
            $productPost::parents($postId);
            self::fail('EntityDataException was not thrown for the post');
        } catch (EntityDataException $exception) {
            self::assertSame("post parent depth of post {$postId} exceeded 100 ({$productPost})", $exception->getMessage());
        }

        try {
            $category::parents($termId);
            self::fail('EntityDataException was not thrown for the term');
        } catch (EntityDataException $exception) {
            self::assertSame("term parent depth of term {$termId} exceeded 100 ({$category})", $exception->getMessage());
        }
    }

    /** 本番の Kernel では、親の深さの超過は例外にせず、その Kernel のロガーに error で記録して空配列を返す。 */
    public function testParentDepthExceededIsLoggedAndReturnsEmptyListInProduction(): void
    {
        $config = $this->productionPluginConfig('ParentLoop');
        $namespace = $config->appNamespace;
        $suffix = substr(md5($namespace), 0, 8);
        $postType = 'tmswp_lp_' . $suffix;
        $taxonomy = 'tmswp_lt_' . $suffix;
        $this->writeFile($config->appDir . '/Post/LoopPost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

final class LoopPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('{$postType}')->label('Loop', 'Loops')->hierarchical();
    }
}
PHP);
        $this->writeFile($config->appDir . '/Tax/LoopTaxonomy.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Tax;

use {$namespace}\\Post\\LoopPost;
use TMSWP\\Core\\Tax\\Taxonomy;
use TMSWP\\Core\\Tax\\TaxonomyDefinition;

final class LoopTaxonomy extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('{$taxonomy}')->label('Loop', 'Loops')->objectType(LoopPost::class)->hierarchical();
    }
}
PHP);
        $this->registerAppAutoloader($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\\Post\\LoopPost', AsPostType::class, []),
            new DiscoveredComponent($namespace . '\\Tax\\LoopTaxonomy', AsTaxonomy::class, []),
        ], []));

        try {
            self::fireInit($this->bootBeforeInit($config));
            $loopPost = self::fixtureClass($namespace . '\\Post\\LoopPost', PostType::class);
            $loopTaxonomy = self::fixtureClass($namespace . '\\Tax\\LoopTaxonomy', Taxonomy::class);
            $postId = $this->createPostCycle($postType);
            $termId = $this->createTermCycle($taxonomy);
            $before = \count($this->logger->records);

            self::assertSame([], $loopPost::parents($postId));
            self::assertSame([], $loopTaxonomy::parents($termId, true));
            self::assertSame(
                [
                    [
                        'level' => 'error',
                        'message' => 'tmswp.post: post parent depth of post {post} exceeded {limit} ({class})',
                        'context' => ['post' => $postId, 'limit' => 100, 'class' => $loopPost],
                    ],
                    [
                        'level' => 'error',
                        'message' => 'tmswp.taxonomy: term parent depth of term {term} exceeded {limit} ({class})',
                        'context' => ['term' => $termId, 'limit' => 100, 'class' => $loopTaxonomy],
                    ],
                ],
                \array_slice($this->logger->records, $before),
            );
        } finally {
            unregister_taxonomy($taxonomy);
            unregister_post_type($postType);
        }
    }

    /**
     * debug の Kernel(fixture のテーマ)では、WordPress の関数が返した WP_Error は QueryException になる。「該当なし」
     * (タームが付いていない・条件に合うタームが無い)は例外にせず、空の結果を返す。
     */
    public function testWpErrorThrowsQueryExceptionInDebugWhileNoMatchIsEmpty(): void
    {
        $productPost = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\ProductPost', PostType::class);
        $category = self::fixtureClass(self::THEME_NAMESPACE . '\\Tax\\ProductCategoryTaxonomy', Taxonomy::class);
        $postId = self::createPost($productPost::slug());
        $termId = wp_insert_term('WP_Error link', $category::slug());
        self::assertIsArray($termId);

        self::assertSame([], $productPost::terms($postId, $category));
        self::assertSame([], $category::query()->search('tmswp-no-such-term')->get());
        self::assertNull($category::query()->search('tmswp-no-such-term')->url());

        $unregistered = $this->declareThemeTaxonomy('tmswp_unregistered');
        $invalidTaxonomy = 'returned WP_Error invalid_taxonomy: Invalid taxonomy.';
        $cases = [
            'PostType::terms' => [static fn(): mixed => $productPost::terms($postId, 'tmswp_unregistered'), "get_the_terms() {$invalidTaxonomy} ({$productPost})"],
            'TermQuery::get' => [static fn(): mixed => $unregistered::query()->get(), "get_terms() {$invalidTaxonomy} ({$unregistered})"],
            'TermQuery::first' => [static fn(): mixed => $unregistered::query()->first(), "get_terms() {$invalidTaxonomy} ({$unregistered})"],
            'TermQuery::ids' => [static fn(): mixed => $unregistered::query()->ids(), "get_terms() {$invalidTaxonomy} ({$unregistered})"],
            'TermQuery::count' => [static fn(): mixed => $unregistered::query()->count(), "get_terms() {$invalidTaxonomy} ({$unregistered})"],
            'Taxonomy::get' => [static fn(): mixed => $unregistered::get((int) $termId['term_id']), "get_term() {$invalidTaxonomy} ({$unregistered})"],
        ];

        foreach ($cases as $name => [$call, $message]) {
            try {
                $call();
                self::fail("QueryException was not thrown for {$name}");
            } catch (QueryException $exception) {
                self::assertSame($message, $exception->getMessage(), $name);
            }
        }
    }

    /**
     * debug の Kernel(fixture のテーマ)では、URL の取得で WordPress の関数が返した WP_Error(get_term_link())は
     * QueryException になる。
     */
    public function testUrlWpErrorThrowsQueryExceptionInDebug(): void
    {
        $category = self::fixtureClass(self::THEME_NAMESPACE . '\\Tax\\ProductCategoryTaxonomy', Taxonomy::class);
        $termId = wp_insert_term('WP_Error url', $category::slug());
        self::assertIsArray($termId);
        add_filter('term_link', static fn(): WP_Error => new WP_Error('tmswp_forced', 'Forced error.'));

        foreach (['Taxonomy::url' => static fn(): mixed => $category::url((int) $termId['term_id']), 'TermQuery::url' => static fn(): mixed => $category::query()->url()] as $name => $call) {
            try {
                $call();
                self::fail("QueryException was not thrown for {$name}");
            } catch (QueryException $exception) {
                self::assertSame("get_term_link() returned WP_Error tmswp_forced: Forced error. ({$category})", $exception->getMessage(), $name);
            }
        }
    }

    /** 本番の Kernel では、WP_Error は例外にせず、その Kernel のロガーに error で記録して既定値を返す。 */
    public function testWpErrorIsLoggedAndReturnsDefaultInProduction(): void
    {
        $config = $this->productionPluginConfig('WpError');
        $namespace = $config->appNamespace;
        $taxonomy = 'tmswp_lost_' . substr(md5($namespace), 0, 8);
        $this->writeFile($config->appDir . '/Post/LostPost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

final class LostPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::builtin('post');
    }
}
PHP);
        $this->writeFile($config->appDir . '/Tax/LostTaxonomy.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Tax;

use TMSWP\\Core\\Tax\\Taxonomy;
use TMSWP\\Core\\Tax\\TaxonomyDefinition;

final class LostTaxonomy extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('{$taxonomy}')->label('Lost', 'Losts');
    }
}
PHP);
        $this->registerAppAutoloader($config);
        // どちらのクラスも探索結果に含めない(タクソノミーは登録されない)
        (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
        self::fireInit($this->bootBeforeInit($config));
        $lostPost = self::fixtureClass($namespace . '\\Post\\LostPost', PostType::class);
        $lostTaxonomy = self::fixtureClass($namespace . '\\Tax\\LostTaxonomy', Taxonomy::class);
        $postId = self::createPost('post');
        $before = \count($this->logger->records);

        self::assertSame([], $lostPost::terms($postId, $taxonomy));
        self::assertSame([], $lostTaxonomy::query()->get());
        self::assertNull($lostTaxonomy::query()->first());
        self::assertSame([], $lostTaxonomy::query()->ids());
        self::assertSame(0, $lostTaxonomy::query()->count());
        self::assertNull($lostTaxonomy::get(1));

        $record = static fn(string $function, string $class): array => [
            'level' => 'error',
            'message' => 'tmswp.entity: {function}() returned WP_Error {code}: {message} ({class})',
            'context' => ['function' => $function, 'code' => 'invalid_taxonomy', 'message' => 'Invalid taxonomy.', 'class' => $class],
        ];
        self::assertSame(
            [
                $record('get_the_terms', $lostPost),
                $record('get_terms', $lostTaxonomy),
                $record('get_terms', $lostTaxonomy),
                $record('get_terms', $lostTaxonomy),
                $record('get_terms', $lostTaxonomy),
                $record('get_term', $lostTaxonomy),
            ],
            \array_slice($this->logger->records, $before),
        );
    }

    /**
     * meta() は、無い対象・スコープ外の対象を、定義の誤り(EntityDefinitionException)ではなく呼び出しの誤りとして
     * InvalidArgumentException にする。
     */
    public function testMetaForMissingOrMismatchedEntityIsInvalidArgument(): void
    {
        $productPost = self::fixtureClass(self::THEME_NAMESPACE . '\\Post\\ProductPost', PostType::class);
        $category = self::fixtureClass(self::THEME_NAMESPACE . '\\Tax\\ProductCategoryTaxonomy', Taxonomy::class);
        $member = self::fixtureClass(self::THEME_NAMESPACE . '\\User\\MemberRole', UserRole::class);
        $pageId = self::createPost('page');
        $cases = [
            'missing post' => [static fn(): mixed => $productPost::meta(PHP_INT_MAX), 'post'],
            'post of another type' => [static fn(): mixed => $productPost::meta($pageId), 'post'],
            'missing term' => [static fn(): mixed => $category::meta(PHP_INT_MAX), 'term'],
            'missing user' => [static fn(): mixed => $member::meta(PHP_INT_MAX), 'user'],
        ];

        foreach ($cases as $name => [$call, $kind]) {
            try {
                $call();
                self::fail("InvalidArgumentException was not thrown for {$name}");
            } catch (InvalidArgumentException $exception) {
                self::assertSame("cannot create MetaBag for missing or mismatched {$kind}", $exception->getMessage(), $name);
            }
        }
    }

    /**
     * 本番の Kernel では、URL の取得で WordPress の関数が返した WP_Error(get_term_link())は例外にせず、その Kernel の
     * ロガーに error で記録して null を返す。
     */
    public function testUrlWpErrorIsLoggedAndReturnsNullInProduction(): void
    {
        $config = $this->productionPluginConfig('UrlWpError');
        $namespace = $config->appNamespace;
        $taxonomy = 'tmswp_ue_' . substr(md5($namespace), 0, 8);
        $this->writeFile($config->appDir . '/Tax/LinkTaxonomy.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Tax;

use TMSWP\\Core\\Tax\\Taxonomy;
use TMSWP\\Core\\Tax\\TaxonomyDefinition;

final class LinkTaxonomy extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('{$taxonomy}')->label('Link', 'Links')->objectType('post');
    }
}
PHP);
        $this->registerAppAutoloader($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\\Tax\\LinkTaxonomy', AsTaxonomy::class, []),
        ], []));

        try {
            self::fireInit($this->bootBeforeInit($config));
            $linkTaxonomy = self::fixtureClass($namespace . '\\Tax\\LinkTaxonomy', Taxonomy::class);
            $termId = $linkTaxonomy::create('Linked');
            add_filter('term_link', static fn(): WP_Error => new WP_Error('tmswp_forced', 'Forced error.'));

            self::assertNull($linkTaxonomy::url($termId));
            self::assertNull($linkTaxonomy::query()->url());
            $record = [
                'level' => 'error',
                'message' => 'tmswp.entity: {function}() returned WP_Error {code}: {message} ({class})',
                'context' => ['function' => 'get_term_link', 'code' => 'tmswp_forced', 'message' => 'Forced error.', 'class' => $linkTaxonomy],
            ];
            self::assertSame([$record, $record], $this->logger->records);
        } finally {
            unregister_taxonomy($taxonomy);
        }
    }

    /** fixture のテーマが test チャネルに登録した RecordingLogger を返す。 */
    private static function fixtureLogger(): RecordingLogger
    {
        $logger = self::themeKernel()->logger()->channel('test');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }

    /**
     * post-thumbnails のテーマサポートを宣言している投稿タイプを返す(投稿タイプを限定した宣言であることも確かめる)。
     *
     * @return list<string>
     */
    private static function thumbnailPostTypes(): array
    {
        $support = get_theme_support('post-thumbnails');
        self::assertIsArray($support);
        self::assertIsArray($support[0] ?? null);
        $postTypes = [];

        foreach ($support[0] as $postType) {
            self::assertIsString($postType);
            $postTypes[] = $postType;
        }

        return $postTypes;
    }

    /** 互いを親にした2件の投稿を作り(post_parent の循環)、その1件の ID を返す。 */
    private function createPostCycle(string $postType): int
    {
        $first = self::createPost($postType);
        $second = self::createPost($postType);
        // wp_update_post() は循環を検出して直すため、DB を直接書き換える
        self::wpdb()->update(self::wpdb()->posts, ['post_parent' => $second], ['ID' => $first]);
        self::wpdb()->update(self::wpdb()->posts, ['post_parent' => $first], ['ID' => $second]);
        clean_post_cache($first);
        clean_post_cache($second);

        return $first;
    }

    /** 互いを親にした2件のタームを作り(parent の循環)、その1件の ID を返す。 */
    private function createTermCycle(string $taxonomy): int
    {
        $first = wp_insert_term('Loop first', $taxonomy);
        $second = wp_insert_term('Loop second', $taxonomy);
        self::assertIsArray($first);
        self::assertIsArray($second);
        $firstId = (int) $first['term_id'];
        $secondId = (int) $second['term_id'];
        // wp_update_term() は循環を検出して直すため、DB を直接書き換える
        self::wpdb()->update(self::wpdb()->term_taxonomy, ['parent' => $secondId], ['term_id' => $firstId, 'taxonomy' => $taxonomy]);
        self::wpdb()->update(self::wpdb()->term_taxonomy, ['parent' => $firstId], ['term_id' => $secondId, 'taxonomy' => $taxonomy]);
        clean_term_cache([$firstId, $secondId], $taxonomy);

        return $firstId;
    }

    /**
     * fixture のテーマの名前空間(debug の Kernel)に、WordPress に登録しないタクソノミーのクラスを宣言する。クラス名は
     * 呼び出しごとに変える(同じプロセスで同じクラスを二度宣言できないため)。
     *
     * @return class-string<Taxonomy>
     */
    private function declareThemeTaxonomy(string $slug): string
    {
        $shortName = 'UnregisteredTaxonomy' . bin2hex(random_bytes(4));
        $file = $this->createTempDirectory('theme-taxonomy') . '/' . $shortName . '.php';
        $namespace = self::THEME_NAMESPACE . '\\Probe';
        $this->writeFile($file, <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use TMSWP\\Core\\Tax\\Taxonomy;
use TMSWP\\Core\\Tax\\TaxonomyDefinition;

final class {$shortName} extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('{$slug}')->label('Unregistered', 'Unregistered');
    }
}
PHP);
        require $file;

        return self::fixtureClass($namespace . '\\' . $shortName, Taxonomy::class);
    }

    /**
     * 本番相当(debug=false)のプラグイン形態の構成を、一時ディレクトリのプラグインで作る。ログはこのテストの
     * RecordingLogger に記録する。
     */
    private function productionPluginConfig(string $name): KernelConfig
    {
        $root = $this->createTempDirectory('integration-' . strtolower($name));
        self::assertTrue(mkdir($root . '/app'));
        $this->writeFile($root . '/plugin.php', "<?php\n");
        $logger = $this->logger;

        return KernelConfig::plugin(
            $root . '/plugin.php',
            $root . '/app',
            'TmswpIntegration' . $name . uniqid(),
            false,
            logChannels: static fn(): array => ['integration' => new LogChannel($logger)],
        );
    }
}

final class MissingBuiltinPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::builtin('tmswp_no_such_type')->supports('title');
    }
}

final class BlockEditorOffPage extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::builtin('page')->blockEditor(false);
    }
}

final class MissingBuiltinTaxonomy extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::builtin('tmswp_no_such_tax');
    }
}

final class MissingBuiltinTaxonomyWithObjectType extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::builtin('tmswp_no_such_tax')->objectType('post');
    }
}

final class DriftRole extends UserRole
{
    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::create('tmswp_drift')->label('New label')->capabilities('read', 'upload_files');
    }
}

final class NewRole extends UserRole
{
    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::create('tmswp_new_role')->label('New role')->capabilities('read');
    }
}

final class InSyncRole extends UserRole
{
    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::create('tmswp_in_sync')->label('In sync')->capabilities('read', 'upload_files');
    }
}

final class MissingBuiltinRole extends UserRole
{
    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::builtin('tmswp_no_such_role')->capabilities('read');
    }
}

final class BuiltinEditorRole extends UserRole
{
    public static function definition(): UserRoleDefinition
    {
        return UserRoleDefinition::builtin('editor')->capabilities('read');
    }
}
