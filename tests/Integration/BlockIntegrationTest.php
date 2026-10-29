<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Core\Block\BlockCategory;
use TMSWP\Core\Block\BlockRegistrar;
use TMSWP\Core\Block\DiscoveredBlock;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Kernel\Discovery;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Testing\BootsKernelBeforeInit;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UsesTempDirectories;
use WP_Block_Editor_Context;
use WP_Block_Type;
use WP_Block_Type_Registry;
use WP_Post;

/**
 * 独自ブロックの自動登録(探索 → 探索結果ファイル → initでのregister_block_type())と、ブロックカテゴリ・
 * allowedBlocks()のWordPressへの反映。
 *
 * ブロックの登録はWordPressのテストライブラリが各テストの後に戻さないため、テストで登録したブロックは
 * tear_downで解除する。
 */
final class BlockIntegrationTest extends IntegrationTestCase
{
    use BootsKernelBeforeInit;
    use UsesTempDirectories;

    /** @var list<string> tear_downで解除するブロック名 */
    private array $registeredByTest = [];

    public function tear_down(): void
    {
        $registry = WP_Block_Type_Registry::get_instance();

        foreach ($this->registeredByTest as $name) {
            if ($registry->is_registered($name)) {
                $registry->unregister($name);
            }
        }

        $this->registeredByTest = [];
        $this->unregisterAppAutoloaders();
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    /**
     * fixtureのテーマのbuild/blocks/のブロック(入れ子のディレクトリを含む)が、WordPressの読み込み(debugのbootの
     * 走査)で登録され、render.phpで描画される。ソース(blocks/)は走査しない(走査すれば同じ名前の重複でbootが失敗する)。
     */
    public function testFixtureThemeBlocksAreRegisteredFromTheBuildOutput(): void
    {
        $theme = self::themeKernel();
        $root = $theme->config()->rootDir();

        self::assertEquals([
            new DiscoveredBlock('tmswp-fixture/card', 'build/blocks/card', self::metadataHash($root . '/build/blocks/card/block.json')),
            new DiscoveredBlock('tmswp-fixture/notice', 'build/blocks/layout/notice', self::metadataHash($root . '/build/blocks/layout/notice/block.json')),
        ], $theme->blocks());
        self::assertFileExists($root . '/blocks/card/block.json');
        self::assertSame([], self::pluginKernel()->blocks());

        $registry = WP_Block_Type_Registry::get_instance();
        $card = $registry->get_registered('tmswp-fixture/card');
        self::assertInstanceOf(WP_Block_Type::class, $card);
        self::assertSame('tmswp-fixture', $card->category);
        // editorScriptのファイル(index.jsとindex.asset.php)の扱いもWordPressに任せる
        self::assertSame(['tmswp-fixture-card-editor-script'], $card->editor_script_handles);
        self::assertTrue(wp_script_is('tmswp-fixture-card-editor-script', 'registered'));
        self::assertInstanceOf(WP_Block_Type::class, $registry->get_registered('tmswp-fixture/notice'));

        self::assertSame(
            '<div class="wp-block-tmswp-fixture-card">Hello &lt;b&gt;</div>',
            render_block(self::parsedBlock('tmswp-fixture/card', ['title' => 'Hello <b>'])),
        );
        self::assertSame('<p class="tmswp-fixture-notice">notice</p>', render_block(self::parsedBlock('tmswp-fixture/notice', [])));
    }

    /** initより前にbootしたKernelのブロックは、init(priority 10)で初めて登録される。 */
    public function testBlocksOfKernelBootedBeforeInitAreRegisteredOnInit(): void
    {
        $config = $this->themeConfig('BeforeInit', debug: true);
        $name = $this->writeBlock($config, 'build/blocks/probe', 'probe');

        $callbacks = $this->bootBeforeInit($config);

        self::assertFalse(WP_Block_Type_Registry::get_instance()->is_registered($name));
        self::assertContains(BlockRegistrar::INIT_PRIORITY, array_column($callbacks, 'priority'));

        self::fireInit($callbacks);

        self::assertTrue(WP_Block_Type_Registry::get_instance()->is_registered($name));
    }

    /** 本番の遅延boot(initの後)は、既存の規則どおりキャッチアップでその場で登録し、遅延bootを記録する。 */
    public function testProductionLateBootRegistersBlocksInCatchUp(): void
    {
        $config = $this->themeConfig('LateProduction', debug: false);
        $name = $this->writeBlock($config, 'build/blocks/late', 'late');
        $this->writeDiscoveryFile($config);

        Kernel::boot($config);

        self::assertTrue(WP_Block_Type_Registry::get_instance()->is_registered($name));
    }

    /** debugの遅延bootは、既存の規則どおりブロックを登録しないまま例外になる。 */
    public function testDebugLateBootRegistersNoBlocks(): void
    {
        $config = $this->themeConfig('LateDebug', debug: true);
        $name = $this->writeBlock($config, 'build/blocks/late', 'late');

        try {
            Kernel::boot($config);
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertStringContainsString("after the 'init' hook had fired", $exception->getMessage());
        }

        self::assertFalse(WP_Block_Type_Registry::get_instance()->is_registered($name));
    }

    /**
     * テーマとプラグインのKernelをまたぐブロック名の重複は、探索コマンド(Kernelごと)では検出できないため、登録の
     * 時点で例外にする。メッセージは、登録しようとしたブロックと登録済みのブロックの両方の出どころを示す。
     */
    public function testBlockNameDuplicatedAcrossKernelsThrowsWithBothOrigins(): void
    {
        $config = $this->themeConfig('Duplicate', debug: false);
        $this->writeBlockJson($config, 'build/blocks/card', 'tmswp-fixture/card');
        $this->writeDiscoveryFile($config);
        $themeRoot = self::themeKernel()->config()->rootDir();

        try {
            Kernel::boot($config);
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertSame(
                "cannot register the block type 'tmswp-fixture/card' of the TMSWP kernel '{$config->appNamespace}' ({$config->rootDir()}/build/blocks/card/block.json):"
                . " it is already registered by the TMSWP kernel 'TmswpFixtureTheme' ({$themeRoot}/build/blocks/card/block.json);"
                . ' block names must be unique across the theme and plugins',
                $exception->getMessage(),
            );
        }

        self::assertArrayNotHasKey($config->appNamespace, Kernel::all());
    }

    /** TMSWPのKernel以外(コア・ほかのプラグイン)が登録したブロックとの重複も、同じく例外にする。 */
    public function testBlockNameDuplicatedWithCoreBlockThrows(): void
    {
        $config = $this->themeConfig('CoreDuplicate', debug: false);
        $this->writeBlockJson($config, 'build/blocks/paragraph', 'core/paragraph');
        $this->writeDiscoveryFile($config);

        $this->expectException(KernelException::class);
        $this->expectExceptionMessage('it is already registered by WordPress core or another plugin or theme (not by a TMSWP kernel)');

        Kernel::boot($config);
    }

    /** 探索結果ファイルが古く、block.jsonのnameと食い違う場合は、黙って別の名前で動かさずに例外にする。 */
    public function testStaleDiscoveryFileWithRenamedBlockThrows(): void
    {
        $config = $this->themeConfig('Stale', debug: false);
        $oldName = $this->writeBlock($config, 'build/blocks/renamed', 'old');
        $this->writeDiscoveryFile($config);
        $newName = $this->writeBlock($config, 'build/blocks/renamed', 'new');

        try {
            Kernel::boot($config);
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertStringContainsString("the block type '{$oldName}' of the TMSWP kernel '{$config->appNamespace}'", $exception->getMessage());
            self::assertStringContainsString("was registered as '{$newName}'; the discovery file is out of date", $exception->getMessage());
        }
    }

    /** KernelConfigのblockCategoriesは、block_categories_allでWordPressの既定のカテゴリの後に1回だけ足される。 */
    public function testKernelConfigBlockCategoryIsAddedAfterCoreCategories(): void
    {
        $categories = get_block_categories(new WP_Block_Editor_Context(['post' => get_post(self::createPost('post'))]));
        $slugs = array_column($categories, 'slug');

        self::assertSame([...BlockCategory::CORE_SLUGS, 'tmswp-fixture'], $slugs);
        self::assertSame(['slug' => 'tmswp-fixture', 'title' => 'TMSWP Fixture', 'icon' => null], $categories[7]);
    }

    /** BlockCategory::CORE_SLUGS(探索でのcategoryの検査に使う)は、WordPressの既定のカテゴリと一致する。 */
    public function testCoreSlugsMatchWordPressDefaultBlockCategories(): void
    {
        self::assertSame(array_column(get_default_block_categories(), 'slug'), BlockCategory::CORE_SLUGS);
    }

    /** 別のKernelがすでに足したスラッグのカテゴリを足そうとすると、そのKernelを示して例外にする。 */
    public function testBlockCategoryDuplicatedAcrossKernelsThrows(): void
    {
        $config = $this->themeConfig('CategoryDuplicate', debug: false, blockCategories: [new BlockCategory('tmswp-fixture', 'Duplicate')]);
        $this->writeDiscoveryFile($config);
        Kernel::boot($config);

        $this->expectException(KernelException::class);
        $this->expectExceptionMessage("cannot add the block category 'tmswp-fixture' of the TMSWP kernel '{$config->appNamespace}': it is already added by the TMSWP kernel 'TmswpFixtureTheme'");

        get_block_categories(new WP_Block_Editor_Context(['name' => 'core/edit-post']));
    }

    /**
     * allowedBlocks()は、それを指定した投稿タイプの編集画面にだけ反映される。テーマとプラグインのKernelがそれぞれの
     * 投稿タイプに指定しても互いを上書きせず、指定していない投稿タイプはWordPressの既定(true)のままになる。
     */
    public function testAllowedBlocksOfEachKernelApplyOnlyToItsOwnPostType(): void
    {
        self::assertSame(['core/paragraph', 'tmswp-fixture/card'], self::allowedBlockTypes('tmswp_product'));
        self::assertSame(['core/heading'], self::allowedBlockTypes('tmswp_plugin_note'));
        self::assertTrue(self::allowedBlockTypes('tmswp_meta_post'));
        self::assertTrue(self::allowedBlockTypes('post'));
        self::assertTrue(get_allowed_block_types(new WP_Block_Editor_Context(['name' => 'core/edit-widgets'])));
    }

    /**
     * 先に動いたフィルタ(ほかのプラグイン)の値とallowedBlocks()の合成。制限を広げない: trueなら指定、falseなら
     * false、配列なら指定との共通部分(受け取った配列の順)になる。allowedBlocks()を指定していない投稿タイプは、
     * 受け取った値をそのまま返す。
     *
     * @dataProvider precedingFilterValues
     */
    public function testAllowedBlocksNeverWidenPrecedingFilterValue(string $postType, mixed $preceding, mixed $expected): void
    {
        add_filter('allowed_block_types_all', static fn(): mixed => $preceding, 5);

        self::assertSame($expected, self::allowedBlockTypes($postType));
    }

    /** @return iterable<string, array{string, mixed, mixed}> */
    public static function precedingFilterValues(): iterable
    {
        yield 'true gives the declared blocks' => ['tmswp_product', true, ['core/paragraph', 'tmswp-fixture/card']];
        yield 'false stays false' => ['tmswp_product', false, false];
        yield 'array keeps the common blocks in its order' => ['tmswp_product', ['tmswp-fixture/card', 'core/list', 'core/paragraph'], ['tmswp-fixture/card', 'core/paragraph']];
        yield 'array without common blocks becomes empty' => ['tmswp_product', ['core/list'], []];
        yield 'plugin kernel post type is narrowed by its own declaration' => ['tmswp_plugin_note', ['core/heading', 'core/paragraph'], ['core/heading']];
        yield 'plugin kernel post type keeps false' => ['tmswp_plugin_note', false, false];
        yield 'undeclared post type keeps array' => ['tmswp_meta_post', ['core/list'], ['core/list']];
        yield 'undeclared post type keeps false' => ['tmswp_meta_post', false, false];
    }

    /** 投稿タイプの投稿の編集画面で使えるブロック(get_allowed_block_types()の値)を返す。 */
    private static function allowedBlockTypes(string $postType): mixed
    {
        $post = get_post(self::createPost($postType));
        self::assertInstanceOf(WP_Post::class, $post);

        return get_allowed_block_types(new WP_Block_Editor_Context(['post' => $post]));
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array{blockName: string, attrs: array<string, mixed>, innerBlocks: array{}, innerHTML: string, innerContent: array{}}
     */
    private static function parsedBlock(string $name, array $attributes): array
    {
        return ['blockName' => $name, 'attrs' => $attributes, 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];
    }

    /**
     * block.jsonの内容から、探索が探索結果ファイルに書くハッシュを求める(JSONとして読み直して書き出した文字列のSHA-256)。
     *
     * @return non-empty-string
     */
    private static function metadataHash(string $file): string
    {
        $contents = file_get_contents($file);
        self::assertIsString($contents);

        return hash('sha256', json_encode(json_decode($contents, true, flags: JSON_THROW_ON_ERROR), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * 一時ディレクトリのテーマの構成を作る。テーマ切替のフックへの登録はテストライブラリがtear_downで戻す。ログ(遅延bootの
     * 記録)はファイルに書かず、RecordingLoggerに送る。
     *
     * @param list<BlockCategory> $blockCategories
     */
    private function themeConfig(string $name, bool $debug, array $blockCategories = []): KernelConfig
    {
        $root = $this->createTempDirectory('block-integration-' . strtolower($name));
        self::assertTrue(mkdir($root . '/app'));

        return KernelConfig::theme($root . '/app', 'TmswpBlock' . $name . uniqid(), $debug, logChannels: static fn(): array => ['test' => new LogChannel(new RecordingLogger())], blockCategories: $blockCategories);
    }

    /**
     * 名前の衝突しない動的描画のブロックを、ルートからの相対パス$pathに置き、その名前を返す。
     *
     * @param non-empty-string $path
     */
    private function writeBlock(KernelConfig $config, string $path, string $suffix): string
    {
        $name = 'tmswp-test/' . strtolower(str_replace('\\', '-', $config->appNamespace)) . '-' . $suffix;
        $this->writeBlockJson($config, $path, $name);
        $this->writeFile("{$config->rootDir()}/{$path}/render.php", "<?php\n\necho 'rendered';\n");
        $this->registeredByTest[] = $name;

        return $name;
    }

    private function writeBlockJson(KernelConfig $config, string $path, string $name): void
    {
        $this->writeFile(
            "{$config->rootDir()}/{$path}/block.json",
            json_encode(['apiVersion' => 3, 'name' => $name, 'title' => 'Test', 'render' => 'file:./render.php'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /** 本番のbootが読む探索結果ファイルを、走査の結果で作る。 */
    private function writeDiscoveryFile(KernelConfig $config): void
    {
        (new DiscoveryFile($config))->write((new Discovery($config, new DiscoveryFile($config)))->scan());
    }
}
