<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use TMSWP\Core\Block\BlockCategory;
use TMSWP\Core\Kernel\DiscoverCommand;
use TMSWP\Core\Kernel\Discovery;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Testing\UnitTestCase;

/**
 * `wp tmswp discover` の生成と照合。WP_CLI の代役クラスをテスト用の一時ファイルに書いて読み込むため、
 * 他のテストに WP_CLI クラスを残さないよう別プロセスで動かす。代役は呼び出しを
 * $GLOBALS['tmswp_test_wp_cli'] に記録し、WP_CLI::error() は終了の代わりに例外を投げる。
 */
final class DiscoverCommandTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
        $GLOBALS['tmswp_test_wp_cli'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_version'], $GLOBALS['wp_actions'], $GLOBALS['tmswp_test_wp_cli']);
        Kernel::reset();
        parent::tearDown();
    }

    #[RunInSeparateProcess]
    public function testItGeneratesAndChecksDiscoveryFilesOfAllKernels(): void
    {
        $this->loadWpCliDouble();
        $alpha = $this->bootKernel('Alpha');
        $beta = $this->bootKernel('Beta');
        $command = new DiscoverCommand();

        // ファイルが無い状態の照合は、両方の Kernel を列挙して失敗する
        $error = $this->runFailing($command, ['check' => true]);
        self::assertStringContainsString("{$alpha->appNamespace}: {$alpha->discoveryFile()} is missing", $error);
        self::assertStringContainsString("{$beta->appNamespace}: {$beta->discoveryFile()} is missing", $error);

        $command([], []);
        self::assertFileExists($alpha->discoveryFile());
        self::assertFileExists($beta->discoveryFile());
        self::assertSame('success', $this->lastCall()[0]);

        $command([], ['check' => true]);
        self::assertSame(['success', 'discovery files are up to date for 2 kernel(s)'], $this->lastCall());

        // 探索対象だけを変えてファイルを更新しないと、変えた Kernel だけが古いと報告される
        $this->writeHookClass($alpha, 'AddedHook', 'tmswp_added_hook');
        $error = $this->runFailing($command, ['check' => true]);
        self::assertStringContainsString("{$alpha->appNamespace}: {$alpha->discoveryFile()} is out of date", $error);
        self::assertStringNotContainsString($beta->appNamespace, $error);

        $command([], []);
        $command([], ['check' => true]);
        self::assertSame(['success', 'discovery files are up to date for 2 kernel(s)'], $this->lastCall());
    }

    /**
     * ブロックの探索結果も照合する。ビルド出力のblock.jsonだけを変えて探索結果ファイルを更新しないと、そのKernelが
     * 古いと報告され、作り直せば照合が通る。
     */
    #[RunInSeparateProcess]
    public function testCheckFailsWhenOnlyBlockJsonChanged(): void
    {
        $this->loadWpCliDouble();
        $config = $this->bootKernel('Blocks');
        $blockJson = $config->rootDir() . '/build/blocks/card/block.json';
        $this->writeFile($blockJson, '{"name": "example/card", "title": "Card"}');
        $command = new DiscoverCommand();
        $command([], []);
        $command([], ['check' => true]);
        self::assertSame(['success', 'discovery files are up to date for 1 kernel(s)'], $this->lastCall());

        $this->writeFile($blockJson, '{"name": "example/card", "title": "Renamed card"}');
        $error = $this->runFailing($command, ['check' => true]);
        self::assertStringContainsString("{$config->appNamespace}: {$config->discoveryFile()} is out of date", $error);

        $command([], []);
        $command([], ['check' => true]);
        self::assertSame(['success', 'discovery files are up to date for 1 kernel(s)'], $this->lastCall());
    }

    /** Kernelの中のブロック名の重複は、探索コマンドのエラーになり、ファイルを書かない。 */
    #[RunInSeparateProcess]
    public function testItReportsDuplicateBlockNamesWithoutWritingFiles(): void
    {
        $this->loadWpCliDouble();
        $config = $this->bootKernel('DuplicateBlocks');
        $this->writeFile($config->rootDir() . '/build/blocks/a/block.json', '{"name": "example/card"}');
        $this->writeFile($config->rootDir() . '/build/blocks/b/block.json', '{"name": "example/card"}');

        $error = $this->runFailing(new DiscoverCommand(), []);

        self::assertStringContainsString("block name 'example/card' is declared by both", $error);
        self::assertFileDoesNotExist($config->discoveryFile());
    }

    /**
     * 未登録のカテゴリのblock.jsonは、探索コマンドのエラー(WP_CLI::error()による非0の終了)になり、生成でも照合でも
     * どのKernelのファイルも書かない。カテゴリは、そのKernelのblockCategoriesだけで判定する(別のKernelが登録する
     * カテゴリも、そのKernelのブロックには使えない)。
     */
    #[RunInSeparateProcess]
    public function testItReportsUnregisteredBlockCategoryWithoutWritingFiles(): void
    {
        $this->loadWpCliDouble();
        $alpha = $this->bootKernel('Alpha', blockCategories: [new BlockCategory('alpha-blocks', 'Alpha blocks')]);
        $beta = $this->bootKernel('Beta');
        // 走査はKernelの登録の順に行うため、先に走査するKernel(Alpha)の正しいブロックだけでは書かないことも確かめる
        $this->writeFile($alpha->rootDir() . '/build/blocks/hero/block.json', '{"name": "example/hero", "category": "alpha-blocks"}');
        $blockJson = $beta->rootDir() . '/build/blocks/card/block.json';
        $this->writeFile($blockJson, '{"name": "example/card", "category": "alpha-blocks"}');
        $expected = "block 'example/card' in {$blockJson} uses the unregistered category 'alpha-blocks'; use one of WordPress's categories (text, media, design, widgets, theme, embed, reusable) or register it with the blockCategories of KernelConfig; fix the block source and rebuild it, then run 'wp tmswp discover'";

        self::assertSame($expected, $this->runFailing(new DiscoverCommand(), []));
        self::assertSame($expected, $this->runFailing(new DiscoverCommand(), ['check' => true]));
        // WP_CLI::error()の前に、成功や書き込みの行を出していない
        self::assertSame([['error', $expected], ['error', $expected]], $GLOBALS['tmswp_test_wp_cli']);
        self::assertFileDoesNotExist($alpha->discoveryFile());
        self::assertFileDoesNotExist($beta->discoveryFile());
    }

    #[RunInSeparateProcess]
    public function testItFailsWithoutBootedKernels(): void
    {
        $this->loadWpCliDouble();

        self::assertStringContainsString('no TMSWP kernel is booted', $this->runFailing(new DiscoverCommand(), ['check' => true]));
    }

    #[RunInSeparateProcess]
    public function testItRejectsUnknownArguments(): void
    {
        $this->loadWpCliDouble();

        self::assertSame('Usage: wp tmswp discover [--check] [--kernels=<count>]', $this->runFailing(new DiscoverCommand(), ['force' => true]));
    }

    /**
     * `--kernels`は起動中のKernelの数を確かめる。数が合えば生成・照合し、照合の結果に数を出す。
     */
    #[RunInSeparateProcess]
    public function testItGeneratesAndChecksWhenTheBootedKernelCountMatches(): void
    {
        $this->loadWpCliDouble();
        $alpha = $this->bootKernel('Alpha');
        $beta = $this->bootKernel('Beta');
        $command = new DiscoverCommand();

        $command([], ['kernels' => '2']);
        self::assertFileExists($alpha->discoveryFile());
        self::assertFileExists($beta->discoveryFile());

        $command([], ['check' => true, 'kernels' => '2']);
        self::assertSame(['success', 'discovery files are up to date for 2 kernel(s)'], $this->lastCall());
    }

    /**
     * 起動しなかったKernelがあると、起動したKernelのファイルが最新でも照合は失敗し、生成もファイルを書かない
     * (起動しなかったKernelを比べないまま成功しない)。
     */
    #[RunInSeparateProcess]
    public function testItFailsWhenTheBootedKernelCountDiffersFromExpected(): void
    {
        $this->loadWpCliDouble();
        $alpha = $this->bootKernel('Alpha');
        $command = new DiscoverCommand();
        $command([], []);
        $command([], ['check' => true]);
        self::assertSame(['success', 'discovery files are up to date for 1 kernel(s)'], $this->lastCall());
        $expected = "expected 2 booted kernel(s) but found 1: {$alpha->appNamespace}; check that the theme and plugins that boot the kernels are active";

        self::assertSame($expected, $this->runFailing($command, ['check' => true, 'kernels' => '2']));

        unlink($alpha->discoveryFile());
        self::assertSame($expected, $this->runFailing($command, ['kernels' => '2']));
        self::assertFileDoesNotExist($alpha->discoveryFile());
    }

    /**
     * `--kernels`の値は1以上の整数だけを受け付ける。値の無い`--kernels`(WP-CLIはtrueを渡す)もusageになる。
     */
    #[RunInSeparateProcess]
    public function testItRejectsInvalidKernelCounts(): void
    {
        $this->loadWpCliDouble();
        $this->bootKernel('Alpha');

        foreach (['0', '-1', '01', '1.5', 'two', '', true] as $value) {
            self::assertSame(
                'Usage: wp tmswp discover [--check] [--kernels=<count>]',
                $this->runFailing(new DiscoverCommand(), ['check' => true, 'kernels' => $value]),
                var_export($value, true),
            );
        }
    }

    #[RunInSeparateProcess]
    public function testItReportsScanFailureWithoutWritingFiles(): void
    {
        $this->loadWpCliDouble();
        $alpha = $this->bootKernel('Alpha');
        $beta = $this->bootKernel('Beta');
        $this->writeFile($beta->appDir . '/Broken.php', "<?php\n\nnamespace {$beta->appNamespace};\n\nfinal class Other\n{\n}\n");

        $error = $this->runFailing(new DiscoverCommand(), []);

        self::assertStringContainsString("is expected to define class {$beta->appNamespace}\\Broken", $error);
        self::assertFileDoesNotExist($alpha->discoveryFile());
        self::assertFileDoesNotExist($beta->discoveryFile());
    }

    /**
     * 探索コマンドは debug に関係なく走査するため、本番(debug=false)の Kernel でも走査の失敗で非0で終わり、ファイルを書かない。
     */
    #[RunInSeparateProcess]
    public function testItReportsScanFailureOfProductionKernel(): void
    {
        $this->loadWpCliDouble();
        $config = $this->bootKernel('Production', debug: false);
        $contents = file_get_contents($config->discoveryFile());
        // ファイルの場所から期待する名前空間と違う名前空間でクラスを定義する
        $this->writeFile($config->appDir . '/Broken.php', "<?php\n\nnamespace {$config->appNamespace}\\Other;\n\nfinal class Broken\n{\n}\n");

        $error = $this->runFailing(new DiscoverCommand(), []);

        self::assertStringContainsString("file {$config->appDir}/Broken.php is expected to define class {$config->appNamespace}\\Broken", $error);
        self::assertSame($contents, file_get_contents($config->discoveryFile()));
    }

    private function loadWpCliDouble(): void
    {
        $file = $this->createTempDirectory('wp-cli-double') . '/WP_CLI.php';
        $this->writeFile($file, <<<'PHP'
<?php

declare(strict_types=1);

final class WP_CLI
{
    public static function line(string $message): void
    {
        $GLOBALS['tmswp_test_wp_cli'][] = ['line', $message];
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
PHP);

        require $file;
    }

    /** @param list<BlockCategory> $blockCategories */
    private function bootKernel(string $suffix, bool $debug = true, array $blockCategories = []): KernelConfig
    {
        $root = $this->createTempDirectory('discover-command-' . strtolower($suffix));
        self::assertTrue(mkdir($root . '/app'));
        $namespace = 'Example\\Tests\\DiscoverCommand' . $suffix . uniqid();
        $config = KernelConfig::theme($root . '/app', $namespace, $debug, blockCategories: $blockCategories);
        $this->writeHookClass($config, 'ProbeHook', 'tmswp_probe_hook_' . strtolower($suffix));

        spl_autoload_register(static function (string $class) use ($namespace, $root): void {
            $prefix = $namespace . '\\';

            if (str_starts_with($class, $prefix) && is_file($file = $root . '/app/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php')) {
                require $file;
            }
        });

        if (!$debug) {
            // 本番の boot は探索結果ファイルを読むため、先に作っておく
            (new DiscoveryFile($config))->write((new Discovery($config, new DiscoveryFile($config)))->scan());
        }

        Kernel::boot($config);

        return $config;
    }

    private function writeHookClass(KernelConfig $config, string $class, string $hook): void
    {
        $this->writeFile($config->appDir . "/Hooks/{$class}.php", <<<PHP
<?php

declare(strict_types=1);

namespace {$config->appNamespace}\\Hooks;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class {$class} extends Hook
{
    #[Action('{$hook}')]
    public function handle(): void
    {
    }
}
PHP);
    }

    /** @param array<string, mixed> $assocArgs */
    private function runFailing(DiscoverCommand $command, array $assocArgs): string
    {
        try {
            $command([], $assocArgs);
        } catch (RuntimeException $exception) {
            self::assertSame(['error', $exception->getMessage()], $this->lastCall());

            return $exception->getMessage();
        }

        self::fail('WP_CLI::error() was not called');
    }

    /** @return array{string, string} */
    private function lastCall(): array
    {
        $calls = $GLOBALS['tmswp_test_wp_cli'];
        self::assertIsArray($calls);
        $last = end($calls);
        self::assertIsArray($last);
        self::assertCount(2, $last);
        [$type, $message] = $last;
        self::assertIsString($type);
        self::assertIsString($message);

        return [$type, $message];
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
