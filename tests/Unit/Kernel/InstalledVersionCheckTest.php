<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Kernel;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Kernel\InstalledVersionCheck;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Testing\UnitTestCase;

/**
 * Kernel::VERSION と、読み込まれた vendor の installed.php にある tmswp/core の版の照合を確かめる。
 */
final class InstalledVersionCheckTest extends UnitTestCase
{
    private ?ClassLoader $extraLoader = null;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
    }

    protected function tearDown(): void
    {
        $this->extraLoader?->unregister();
        unset($GLOBALS['wp_version'], $GLOBALS['wp_actions']);
        Kernel::reset();
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function comparableVersionsOfOtherReleases(): iterable
    {
        yield '古いリリース' => ['0.1.0'];
        yield '新しいリリース' => ['0.3.0'];
        yield 'タグの v 付き' => ['v1.0.0'];
    }

    #[DataProvider('comparableVersionsOfOtherReleases')]
    public function testMismatchedReleaseVersionIsRejectedWithItsInstallPath(string $prettyVersion): void
    {
        $installed = [
            self::installed('0.2.0', '/site/wp-content/themes/a/vendor/composer/../tmswp/core'),
            self::installed($prettyVersion, '/site/wp-content/plugins/b/vendor/composer/../tmswp/core'),
        ];

        $this->expectException(KernelException::class);
        $this->expectExceptionMessage("tmswp/core versions on this site do not match the loaded core 0.2.0: {$prettyVersion} at /site/wp-content/plugins/b/vendor/composer/../tmswp/core.");

        InstalledVersionCheck::assertConsistent($installed, '0.2.0');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function entriesOutsideTheComparison(): iterable
    {
        yield '同じ版' => [self::installed('0.2.0', '/a/vendor/composer/../tmswp/core')];
        yield 'タグの v 付きの同じ版' => [self::installed('v0.2.0', '/a/vendor/composer/../tmswp/core')];
        yield 'dev のブランチ' => [self::installed('dev-main', '/a/vendor/composer/../tmswp/core')];
        yield 'ブランチの別名' => [self::installed('0.2.x-dev', '/a/vendor/composer/../tmswp/core')];
        yield 'プレリリース' => [self::installed('0.3.0-beta1', '/a/vendor/composer/../tmswp/core')];
        yield 'replace だけの記載(monorepo のルート)' => [['root' => [], 'versions' => ['tmswp/core' => ['dev_requirement' => false, 'replaced' => ['0.1.0']]]]];
        yield 'tmswp/core を含まない vendor' => [['root' => [], 'versions' => ['psr/log' => ['pretty_version' => '3.0.2']]]];
    }

    /** @param array<string, mixed> $installed */
    #[DataProvider('entriesOutsideTheComparison')]
    public function testMatchingDevAndReplacedEntriesPass(array $installed): void
    {
        InstalledVersionCheck::assertConsistent([$installed], '0.2.0');

        $this->addToAssertionCount(1);
    }

    public function testBootPassesWithTheInstalledPackagesOfThisRepository(): void
    {
        $kernel = Kernel::boot(KernelConfig::theme($this->appDir('version-match'), 'Example\Tests\VersionMatch' . uniqid(), true));

        self::assertSame($kernel, Kernel::get($kernel->config()->appNamespace));
    }

    public function testBootDetectsAnotherVendorBundlingADifferentCoreVersion(): void
    {
        // 別のプラグインが vendor/ に古い tmswp/core を同梱し、その Composer のオートローダが登録された状態
        $vendor = $this->createTempDirectory('other-plugin') . '/vendor';
        mkdir($vendor . '/composer', 0775, true);
        file_put_contents($vendor . '/composer/installed.php', '<?php return ' . var_export(self::installed('0.1.0', $vendor . '/composer/../tmswp/core'), true) . ';');
        $this->extraLoader = new ClassLoader($vendor);
        $this->extraLoader->register();
        $namespace = 'Example\Tests\VersionMismatch' . uniqid();

        try {
            Kernel::boot(KernelConfig::theme($this->appDir('version-mismatch'), $namespace, false));
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertStringContainsString('do not match the loaded core ' . Kernel::VERSION . ": 0.1.0 at {$vendor}/composer/../tmswp/core", $exception->getMessage());
        }

        self::assertArrayNotHasKey($namespace, Kernel::all());
    }

    /** @return array{root: array<string, mixed>, versions: array<string, array<string, mixed>>} */
    private static function installed(string $prettyVersion, string $installPath): array
    {
        return [
            'root' => ['name' => 'example/site', 'pretty_version' => 'dev-main', 'version' => 'dev-main', 'reference' => null, 'type' => 'project', 'install_path' => '/', 'aliases' => [], 'dev' => false],
            'versions' => [
                'tmswp/core' => [
                    'pretty_version' => $prettyVersion,
                    'version' => $prettyVersion,
                    'reference' => null,
                    'type' => 'library',
                    'install_path' => $installPath,
                    'aliases' => [],
                    'dev_requirement' => false,
                ],
            ],
        ];
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
