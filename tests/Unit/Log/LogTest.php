<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Log;

use Brain\Monkey\Functions;
use ReflectionClass;
use ReflectionMethod;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\Log;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Log\LoggerRegistry;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UnitTestCase;

/**
 * Log の静的な呼び出しと tms_log() が、呼び出したファイルを含むルートの Kernel に記録することを確かめる。
 */
final class LogTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
        // テーマ形態の Kernel は子テーマのディレクトリも forFile() の判定に使う。既定は子テーマ無し(親と同じ)
        Functions\when('get_template_directory')->justReturn(sys_get_temp_dir());
        Functions\when('get_stylesheet_directory')->justReturn(sys_get_temp_dir());
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_version'], $GLOBALS['wp_actions']);
        Kernel::reset();
        parent::tearDown();
    }

    public function testStaticCallsAreRecordedInTheKernelWhoseRootContainsTheCallerFile(): void
    {
        $theme = $this->bootKernel($this->createTempDirectory('log-theme'), 'Theme');
        $plugin = $this->bootKernel($this->createTempDirectory('log-plugin'), 'Plugin');

        // クラスの無いテンプレートのファイルからの呼び出しも、ファイルの場所で Kernel を決める
        $this->includeFile($theme['kernel']->config()->rootDir() . '/parts/log-probe.php', "\\TMSWP\\Core\\Log\\Log::error('from theme', ['id' => 1]);");
        $this->includeFile($plugin['kernel']->config()->rootDir() . '/templates/log-probe.php', "tms_log()->warning('from plugin');\n\\TMSWP\\Core\\Log\\Log::scope('mail')->info('scoped');");

        self::assertSame([['level' => 'error', 'message' => 'from theme', 'context' => ['id' => 1]]], $theme['logger']->records);
        self::assertSame([['level' => 'warning', 'message' => 'from plugin', 'context' => []], ['level' => 'info', 'message' => 'scoped', 'context' => ['_scope' => 'mail']]], $plugin['logger']->records);
    }

    public function testNestedRootsUseTheLongestMatchingRoot(): void
    {
        $outerRoot = $this->createTempDirectory('log-outer');
        $outer = $this->bootKernel($outerRoot, 'Outer');
        $inner = $this->bootKernel($outerRoot . '/plugins/inner', 'Inner');

        $this->includeFile($inner['kernel']->config()->rootDir() . '/parts/inner.php', "\\TMSWP\\Core\\Log\\Log::info('inner');");
        $this->includeFile($outer['kernel']->config()->rootDir() . '/parts/outer.php', "\\TMSWP\\Core\\Log\\Log::info('outer');");

        self::assertSame([['level' => 'info', 'message' => 'outer', 'context' => []]], $outer['logger']->records);
        self::assertSame([['level' => 'info', 'message' => 'inner', 'context' => []]], $inner['logger']->records);
    }

    public function testChildThemeFilesAreRecordedInTheThemeKernel(): void
    {
        $theme = $this->bootKernel($this->createTempDirectory('log-parent-theme'), 'ParentTheme');
        $childTheme = (string) realpath($this->createTempDirectory('log-child-theme'));
        Functions\when('get_template_directory')->justReturn($theme['kernel']->config()->rootDir());
        Functions\when('get_stylesheet_directory')->justReturn($childTheme);

        // 子テーマのテンプレート(Template::part() が先に探す)からの呼び出しを、親テーマの Kernel に記録する
        $this->includeFile($childTheme . '/parts/child.php', "\\TMSWP\\Core\\Log\\Log::error('from child theme');");

        self::assertSame([['level' => 'error', 'message' => 'from child theme', 'context' => []]], $theme['logger']->records);
    }

    public function testChildThemeDirectoryIsNotARootOfPluginKernels(): void
    {
        $pluginFile = $this->createTempDirectory('log-plugin-only') . '/plugin.php';
        file_put_contents($pluginFile, "<?php\n");
        Functions\when('TMSWP\Core\Kernel\register_activation_hook')->justReturn(null);
        Functions\when('TMSWP\Core\Kernel\register_deactivation_hook')->justReturn(null);
        $appDir = \dirname($pluginFile) . '/app';
        mkdir($appDir);
        Kernel::boot(KernelConfig::plugin($pluginFile, $appDir, 'Example\Tests\LogFacadePluginOnly' . uniqid(), true));
        $childTheme = (string) realpath($this->createTempDirectory('log-unrelated-child-theme'));
        Functions\when('get_stylesheet_directory')->justReturn($childTheme);
        $file = $childTheme . '/parts/child.php';

        $this->expectException(KernelException::class);
        $this->expectExceptionMessage("file '{$file}' is not inside the root directory of any booted TMSWP kernel");

        $this->includeFile($file, "\\TMSWP\\Core\\Log\\Log::error('lost');");
    }

    public function testCallThroughInternalFunctionWithoutCallerFileThrows(): void
    {
        $theme = $this->bootKernel($this->createTempDirectory('log-callable'), 'Callable');

        // array_map() から呼ばれた Log のフレームはファイルを持たないため、呼び出し元を決められない
        try {
            $this->includeFile($theme['kernel']->config()->rootDir() . '/parts/callable.php', "array_map([\\TMSWP\\Core\\Log\\Log::class, 'notice'], ['via callable']);");
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertStringStartsWith('cannot determine the file that called TMSWP\Core\Log\Log::notice()', $exception->getMessage());
        }

        self::assertSame([], $theme['logger']->records);
    }

    public function testCallFromEvaluatedCodeUsesTheFileThatCalledEval(): void
    {
        $theme = $this->bootKernel($this->createTempDirectory('log-eval'), 'Eval');

        $this->includeFile($theme['kernel']->config()->rootDir() . '/parts/eval.php', "eval('\\\\TMSWP\\\\Core\\\\Log\\\\Log::error(\\'from eval\\');');");

        self::assertSame([['level' => 'error', 'message' => 'from eval', 'context' => []]], $theme['logger']->records);
    }

    public function testPluginCallsAreNotAttributedToTheThemeWhoseVendorContainsTheHelperFile(): void
    {
        // テーマの vendor の中の tmswp/core(functions.php と Log.php)を含むルートのテーマの Kernel。
        // packages/core をルートにする(appDir の languages/ には走査の対象になるクラスが無い)
        $themeLogger = new RecordingLogger();
        Kernel::boot(KernelConfig::theme(
            \dirname(__DIR__, 3) . '/languages',
            'Example\Tests\LogFacadeVendorTheme' . uniqid(),
            true,
            logChannels: static fn(): array => ['test' => new LogChannel($themeLogger)],
        ));
        $plugin = $this->bootKernel($this->createTempDirectory('log-vendor-plugin'), 'VendorPlugin');

        $this->includeFile($plugin['kernel']->config()->rootDir() . '/templates/probe.php', "tms_log()->error('helper');\n\\TMSWP\\Core\\Log\\Log::error('facade');");

        self::assertSame(['helper', 'facade'], array_column($plugin['logger']->records, 'message'));
        self::assertSame([], $themeLogger->records);
    }

    public function testForFileComparesTheRealPathOfTheFile(): void
    {
        $tree = (string) realpath($this->createTempDirectory('log-real-path'));
        $plugin = $this->bootKernel($tree . '/plugin', 'RealPath');
        $root = $plugin['kernel']->config()->rootDir();
        $this->writePhp($root . '/parts/inside.php');
        $this->writePhp($tree . '/outside/existing.php');
        symlink($root, $tree . '/linked-plugin');

        try {
            // 実体のパスと、シンボリックリンクを経由したパスは、同じ Kernel に解決される
            self::assertSame($plugin['kernel'], Kernel::forFile($root . '/parts/inside.php'));
            self::assertSame($plugin['kernel'], Kernel::forFile($tree . '/linked-plugin/parts/inside.php'));
        } finally {
            // 一時ディレクトリの削除がリンクの先をたどらないよう、リンクを先に消す
            unlink($tree . '/linked-plugin');
        }

        // `..` でルートの外へ出るパスは、文字列ではルートで始まっていても、実体の場所(ルートの外)で判定する
        try {
            Kernel::forFile($root . '/../outside/existing.php');
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertSame("file '{$root}/../outside/existing.php' (real path '{$tree}/outside/existing.php') is not inside the root directory of any booted TMSWP kernel", $exception->getMessage());
        }
    }

    public function testForFileRejectsPathThatDoesNotExist(): void
    {
        $plugin = $this->bootKernel($this->createTempDirectory('log-missing-path'), 'MissingPath');
        $missing = $plugin['kernel']->config()->rootDir() . '/parts/missing.php';

        $this->expectException(KernelException::class);
        $this->expectExceptionMessage("cannot resolve the real path of '{$missing}' to find its TMSWP kernel");

        Kernel::forFile($missing);
    }

    public function testCallFromFileOutsideEveryKernelRootThrows(): void
    {
        $this->bootKernel($this->createTempDirectory('log-booted'), 'Booted');
        $outside = $this->createTempDirectory('log-outside');
        $file = realpath($outside) . '/outside.php';

        try {
            $this->includeFile($file, "\\TMSWP\\Core\\Log\\Log::error('lost');");
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertSame("file '{$file}' is not inside the root directory of any booted TMSWP kernel", $exception->getMessage());
        }
    }

    public function testCallWithoutAnyBootedKernelThrows(): void
    {
        $this->expectException(KernelException::class);
        $this->expectExceptionMessage("file '" . __FILE__ . "' is not inside the root directory of any booted TMSWP kernel");

        tms_log();
    }

    public function testContainerResolvesTheKernelRegistry(): void
    {
        $theme = $this->bootKernel($this->createTempDirectory('log-container'), 'Container');

        self::assertSame($theme['kernel']->logger(), $theme['kernel']->container()->get(LoggerRegistry::class));
    }

    /**
     * docs/07の受け入れ基準「Logにレジストリの差し替え・チャンネル登録のAPI(set/setRegistry)が無く」を確かめる。
     * publicメソッドは、docs/07 §5.2のAPIの一覧(registry・channel・scope・レベルの8つ・log)と完全に一致する。
     */
    public function testLogHasNoApiToReplaceTheRegistryOrRegisterChannels(): void
    {
        $class = new ReflectionClass(Log::class);
        $methods = array_map(static fn(ReflectionMethod $method): string => $method->getName(), $class->getMethods(ReflectionMethod::IS_PUBLIC));
        sort($methods);

        self::assertSame(['alert', 'channel', 'critical', 'debug', 'emergency', 'error', 'info', 'log', 'notice', 'registry', 'scope', 'warning'], $methods);
        // privateを含めて、その名前のメソッドが無い
        self::assertFalse($class->hasMethod('set'));
        self::assertFalse($class->hasMethod('setRegistry'));
    }

    /**
     * $root/app を appDir とする Kernel を、記録を残すだけのロガーのチャネルで boot する。
     *
     * @return array{kernel: Kernel, logger: RecordingLogger}
     */
    private function bootKernel(string $root, string $name): array
    {
        if (!mkdir($root . '/app', 0775, true)) {
            self::fail("ディレクトリを作成できません: {$root}/app");
        }

        $logger = new RecordingLogger();
        $kernel = Kernel::boot(KernelConfig::theme(
            $root . '/app',
            'Example\Tests\LogFacade' . $name . uniqid(),
            true,
            logChannels: static fn(): array => ['test' => new LogChannel($logger)],
        ));

        return ['kernel' => $kernel, 'logger' => $logger];
    }

    private function writePhp(string $path): void
    {
        if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0775, true)) {
            self::fail('ディレクトリを作成できません: ' . \dirname($path));
        }

        file_put_contents($path, "<?php\n");
    }

    private function includeFile(string $path, string $body): void
    {
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            self::fail("ディレクトリを作成できません: {$dir}");
        }

        if (file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\n{$body}\n") === false) {
            self::fail("ファイルを書き込めません: {$path}");
        }

        (static function (string $file): void {
            include $file;
        })($path);
    }
}
