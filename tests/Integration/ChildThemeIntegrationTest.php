<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use ReflectionMethod;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\View\Template;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UsesTempDirectories;

/**
 * fixtureのテーマ(tmswp-fixture-theme)を親とする子テーマを有効にした状態で、Kernelのルートの判定・Templateの解決・
 * Logの記録先を確かめる。
 *
 * 子テーマはfixtureに置かず、テストごとに一時ディレクトリのテーマの置き場に作る。fixtureのテーマは
 * tests/fixtures/_activate.phpがpre_option_stylesheet・pre_option_templateのフィルタで有効にしているため、子テーマも
 * 同じくフィルタで有効にする(switch_theme()が保存するstylesheet・stylesheet_rootのオプションを、フィルタで返す)。
 * フィルタはWPのテストライブラリが各テストの後に元に戻す。
 */
final class ChildThemeIntegrationTest extends IntegrationTestCase
{
    use UsesTempDirectories;

    private const string CHILD_THEME = 'tmswp-fixture-child';

    /**
     * テストの前の$wp_theme_directories(テーマの置き場)。
     *
     * @var array<mixed>|null
     */
    private ?array $themeDirectories = null;

    public function tear_down(): void
    {
        if ($this->themeDirectories !== null) {
            $GLOBALS['wp_theme_directories'] = $this->themeDirectories;
            $this->themeDirectories = null;
            // search_theme_directories()の静的なキャッシュとtheme_rootsのトランジェントから、子テーマの置き場を外す
            search_theme_directories(true);
        }

        // 子テーマのパスに解決した結果を、後のテストに残さない
        Template::locator()->flush();
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    /**
     * docs/02-kernel.mdの「子テーマが有効なとき、子テーマのファイルはテーマのKernelに解決され、プラグインのKernelの
     * ルートには含まれない」を確かめる。
     */
    public function testChildThemeFileResolvesToThemeKernelAndIsNotInPluginKernelRoots(): void
    {
        $childDir = $this->activateChildTheme();
        $childFile = $childDir . '/parts/child-file.php';
        $this->writeFile($childFile, "<?php\n");
        $realChildDir = realpath($childDir);
        self::assertIsString($realChildDir);

        self::assertSame(self::themeKernel(), Kernel::forFile($childFile));
        self::assertContains($realChildDir, self::rootDirs(self::themeKernel()));
        self::assertNotContains($realChildDir, self::rootDirs(self::pluginKernel()));
        self::assertSame([self::pluginKernel()->config()->rootDir()], self::rootDirs(self::pluginKernel()));
    }

    /**
     * docs/07-support.mdの「`Template::part('parts/card', ['post' => $post])`が子テーマ → 親テーマ → 登録順フォールバック
     * ディレクトリの順で`.php`を解決してincludeする」を確かめる。同じslug(plugin/override)を子テーマ・親テーマ
     * (fixture)・プラグインのtemplates/(fixture。フォールバック)に置き、子テーマのファイルがincludeされる。
     * 子テーマから消すと親テーマのファイルが、どちらのテーマにも無いslugはフォールバックのファイルがincludeされる。
     */
    public function testTemplatePartResolvesChildThemeThenParentThemeThenFallback(): void
    {
        $childDir = $this->activateChildTheme();
        $childFile = $childDir . DIRECTORY_SEPARATOR . 'plugin/override.php';
        $parentFile = get_template_directory() . DIRECTORY_SEPARATOR . 'plugin/override.php';
        $pluginFile = self::pluginKernel()->config()->pluginFile;
        self::assertNotNull($pluginFile);
        $fallbackFile = \dirname($pluginFile) . '/templates' . DIRECTORY_SEPARATOR . 'plugin/override.php';
        $this->writeFile($childFile, "<?php\n\ndeclare(strict_types=1);\n\necho 'child override ' . \$args['suffix'];\n");
        self::assertFileExists($parentFile);
        self::assertFileExists($fallbackFile);

        self::assertSame([$childFile, $parentFile, $fallbackFile], \array_slice(Template::locator()->candidatePaths('plugin/override'), 0, 3));
        self::assertSame('child override ok', Template::render('plugin/override', ['suffix' => 'ok']));

        self::assertTrue(unlink($childFile));
        Template::locator()->flush();
        self::assertSame('theme override', Template::render('plugin/override'));
        self::assertSame('plugin fallback', Template::render('plugin/card'));
    }

    /**
     * docs/07-support.mdの「子テーマのファイルからの`Log::error()`が、親テーマのKernelに記録される」を確かめる。
     * 記録先は親テーマ(fixture)のKernelのtestチャネルで、プラグインのKernelには記録されない。
     */
    public function testLogErrorFromChildThemeFileIsRecordedInParentThemeKernel(): void
    {
        $childDir = $this->activateChildTheme();
        $probe = $childDir . '/parts/log-probe.php';
        $this->writeFile($probe, "<?php\n\ndeclare(strict_types=1);\n\nTMSWP\\Core\\Log\\Log::error('tmswp fixture child theme log probe');\n");
        $themeLogger = self::fixtureLogger(self::themeKernel());
        $pluginLogger = self::fixtureLogger(self::pluginKernel());
        $themeBefore = \count($themeLogger->records);
        $pluginBefore = \count($pluginLogger->records);

        require $probe;

        self::assertSame(['tmswp fixture child theme log probe'], array_column(\array_slice($themeLogger->records, $themeBefore), 'message'));
        self::assertCount($pluginBefore, $pluginLogger->records);
    }

    /**
     * 一時ディレクトリのテーマの置き場に、fixtureのテーマを親とする子テーマを作って有効にし、そのディレクトリを返す。
     */
    private function activateChildTheme(): string
    {
        $themesRoot = $this->createTempDirectory('child-themes');
        $childDir = $themesRoot . DIRECTORY_SEPARATOR . self::CHILD_THEME;
        $parent = get_template();
        $this->writeFile($childDir . '/style.css', "/*\nTheme Name: TMSWP Fixture Child\nTemplate: {$parent}\n*/\n");
        $this->writeFile($childDir . '/index.php', "<?php\n");

        $directories = $GLOBALS['wp_theme_directories'] ?? [];
        self::assertIsArray($directories);
        $this->themeDirectories = $directories;
        self::assertTrue(register_theme_directory($themesRoot));
        search_theme_directories(true);
        add_filter('pre_option_stylesheet', static fn(): string => self::CHILD_THEME, 20);
        add_filter('pre_option_stylesheet_root', static fn(): string => $themesRoot, 20);
        Template::locator()->flush();

        // 子テーマが有効で、親はfixtureのテーマのまま
        self::assertSame(self::CHILD_THEME, get_stylesheet());
        self::assertSame($childDir, get_stylesheet_directory());
        self::assertSame($parent, get_template());
        self::assertNotSame(get_template_directory(), get_stylesheet_directory());
        self::assertTrue(wp_get_theme(self::CHILD_THEME, $themesRoot)->exists());
        self::assertSame($parent, wp_get_theme(self::CHILD_THEME, $themesRoot)->get_template());

        return $childDir;
    }

    /**
     * Kernel::forFile()が判定に使うルートディレクトリ(Kernel::rootDirs())を返す。rootDirs()はprivateのため、
     * 子テーマのディレクトリがどのKernelのルートに入るかを確かめるのに限り、リフレクションで呼ぶ。
     *
     * @return list<string>
     */
    private static function rootDirs(Kernel $kernel): array
    {
        $roots = (new ReflectionMethod(Kernel::class, 'rootDirs'))->invoke($kernel);
        self::assertIsArray($roots);

        return array_values(array_filter($roots, 'is_string'));
    }

    /** fixtureのテーマ・プラグインがtestチャネルに登録したRecordingLoggerを返す。*/
    private static function fixtureLogger(Kernel $kernel): RecordingLogger
    {
        $logger = $kernel->logger()->channel('test');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("ディレクトリを作成できません: {$dir}");
        }

        if (file_put_contents($path, $contents) === false) {
            self::fail("ファイルを書き込めません: {$path}");
        }
    }
}
