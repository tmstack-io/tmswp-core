<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Kernel;

use Brain\Monkey\Functions;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\ExtensionInterface;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Tests\Support\RecordingExtension;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UnitTestCase;

/**
 * KernelConfig で宣言する拡張・ログのチャネル・ラベル言語の既定を確かめる。
 */
final class KernelExtensionTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_version'], $GLOBALS['wp_actions']);
        Kernel::reset();
        parent::tearDown();
    }

    public function testExtensionsAreRegisteredOnceInOrderWithTheirKernel(): void
    {
        $first = new RecordingExtension();
        $second = new KernelExtensionTestOrderedExtension();

        $kernel = self::boot(KernelConfig::theme($this->appDir('extension-order'), 'Example\Tests\ExtensionOrder' . uniqid(), false, extensions: [$first, $second]));

        self::assertSame([['kernel' => $kernel, 'debug' => false]], $first->registrations);
        self::assertSame([$kernel], $second->kernels);
        // 拡張が呼ばれた時点で、先に渡した拡張の登録は終わっている
        self::assertSame(1, $second->registrationsOfFirstAtCall);
        self::assertSame($kernel, Kernel::get($kernel->config()->appNamespace));
    }

    public function testExtensionFollowsTheDebugOfTheKernelThatRegisteredIt(): void
    {
        $production = new RecordingExtension();
        $debug = new RecordingExtension();

        self::boot(KernelConfig::theme($this->appDir('extension-production'), 'Example\Tests\ExtensionProduction' . uniqid(), false, extensions: [$production]));
        self::boot(KernelConfig::theme($this->appDir('extension-debug'), 'Example\Tests\ExtensionDebug' . uniqid(), true, extensions: [$debug]));

        self::assertFalse($production->registrations[0]['debug']);
        self::assertTrue($debug->registrations[0]['debug']);
    }

    public function testSameExtensionClassMayBeRegisteredByDifferentKernels(): void
    {
        $theme = new RecordingExtension();
        $plugin = new RecordingExtension();
        $pluginFile = $this->createTempDirectory('extension-plugin-root') . '/plugin.php';
        file_put_contents($pluginFile, "<?php\n");
        Functions\when('TMSWP\Core\Kernel\register_activation_hook')->justReturn(null);
        Functions\when('TMSWP\Core\Kernel\register_deactivation_hook')->justReturn(null);

        $themeKernel = self::boot(KernelConfig::theme($this->appDir('extension-theme'), 'Example\Tests\ExtensionTheme' . uniqid(), false, extensions: [$theme]));
        $pluginKernel = self::boot(KernelConfig::plugin($pluginFile, $this->appDir('extension-plugin'), 'Example\Tests\ExtensionPlugin' . uniqid(), false, extensions: [$plugin]));

        self::assertSame([$themeKernel], array_column($theme->registrations, 'kernel'));
        self::assertSame([$pluginKernel], array_column($plugin->registrations, 'kernel'));
    }

    public function testSameExtensionClassTwiceInOneKernelIsRejectedRegardlessOfDebug(): void
    {
        foreach ([true, false] as $debug) {
            try {
                KernelConfig::theme($this->appDir('extension-duplicate'), 'Example\Tests\ExtensionDuplicate', $debug, extensions: [new RecordingExtension(), new RecordingExtension()]);
                self::fail('KernelException was not thrown');
            } catch (KernelException $exception) {
                self::assertSame('extension ' . RecordingExtension::class . ' is registered more than once in the same kernel', $exception->getMessage());
            }
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExtensionsAreNotRegisteredInPluginActivationSandbox(): void
    {
        \define('WP_SANDBOX_SCRAPING', true);
        Functions\when('TMSWP\Core\Kernel\register_activation_hook')->justReturn(null);
        Functions\when('TMSWP\Core\Kernel\register_deactivation_hook')->justReturn(null);
        $extension = new RecordingExtension();
        $pluginFile = $this->createTempDirectory('extension-sandbox-root') . '/plugin.php';
        file_put_contents($pluginFile, "<?php\n");

        self::boot(KernelConfig::plugin($pluginFile, $this->appDir('extension-sandbox'), 'Example\Tests\ExtensionSandbox' . uniqid(), true, extensions: [$extension]));

        self::assertSame([], $extension->registrations);
    }

    public function testDeclaredLogChannelsAreTheKernelRegistry(): void
    {
        $logger = new RecordingLogger();
        $kernel = self::boot(KernelConfig::theme($this->appDir('log-channels'), 'Example\Tests\LogChannels' . uniqid(), false, logChannels: static fn(): array => ['audit' => new LogChannel($logger)]));

        $kernel->logger()->info('recorded');

        self::assertSame($logger, $kernel->logger()->channel('audit'));
        self::assertSame([['level' => 'info', 'message' => 'recorded', 'context' => []]], $logger->records);
    }

    /** @return iterable<string, array{Closure, string}> */
    public static function invalidLogChannels(): iterable
    {
        yield '空の配列' => [static fn(): array => [], 'logChannels must return a non-empty array of channel name => ' . LogChannel::class];
        yield '配列でない' => [static fn(): string => 'default', 'logChannels must return a non-empty array of channel name => ' . LogChannel::class];
        yield 'LogChannel でない値' => [static fn(): array => ['default' => new RecordingLogger()], 'logChannels must return channel name (string) => ' . LogChannel::class . ', got string => ' . RecordingLogger::class];
        yield '名前の無いチャネル' => [static fn(): array => [new LogChannel(new RecordingLogger())], 'logChannels must return channel name (string) => ' . LogChannel::class . ', got int => ' . LogChannel::class];
    }

    #[DataProvider('invalidLogChannels')]
    public function testInvalidLogChannelsAreRejectedAtBoot(Closure $logChannels, string $message): void
    {
        // クロージャの戻り値の型は PHP では検査されないため、boot が戻り値を検証する
        $config = KernelConfig::theme($this->appDir('invalid-log-channels'), 'Example\Tests\InvalidLogChannels' . uniqid(), false, logChannels: $logChannels);

        (new DiscoveryFile($config))->write(new DiscoveryResult([], []));

        $this->expectException(KernelException::class);
        $this->expectExceptionMessage($message);

        Kernel::boot($config);
    }

    public function testLabelLanguageDefaultsToEnglishAndCanBeSetPerKernel(): void
    {
        $appDir = $this->appDir('label-language');

        self::assertSame(LabelLanguage::En, KernelConfig::theme($appDir)->labelLanguage);
        self::assertSame(LabelLanguage::Ja, KernelConfig::theme($appDir, labelLanguage: LabelLanguage::Ja)->labelLanguage);
    }

    /** 本番(debug=false)の構成には、空の探索結果ファイルを置いてから boot する。 */
    private static function boot(KernelConfig $config): Kernel
    {
        if (!$config->debug) {
            (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
        }

        return Kernel::boot($config);
    }

    private function appDir(string $prefix): string
    {
        $appDir = $this->createTempDirectory($prefix) . '/app';

        if (!mkdir($appDir)) {
            self::fail("ディレクトリを作成できません: {$appDir}");
        }

        return $appDir;
    }
}

/**
 * 呼ばれた Kernel と、その時点で同じ Kernel の先の拡張(RecordingExtension)が登録された回数を記録する。
 */
final class KernelExtensionTestOrderedExtension implements ExtensionInterface
{
    /** @var list<Kernel> */
    public array $kernels = [];

    public ?int $registrationsOfFirstAtCall = null;

    public function register(Kernel $kernel): void
    {
        $this->kernels[] = $kernel;
        $first = $kernel->config()->extensions[0];
        $this->registrationsOfFirstAtCall = $first instanceof RecordingExtension ? \count($first->registrations) : null;
    }
}
