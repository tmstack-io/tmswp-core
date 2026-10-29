<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use TMSWP\Core\Container\Container;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LoggerRegistry;
use TMSWP\Testing\UnitTestCase;

/**
 * 他のプラグインや WP-CLI の phar が古い psr/container・psr/log を先に読み込んだ構成で、boot が Fatal error ではなく
 * KernelException で止まることを、別プロセスで確かめる(interface は1プロセスに1回しか宣言できない)。
 */
final class PsrInterfaceCheckTest extends UnitTestCase
{
    private const string FIXTURES = __DIR__ . '/../../fixtures/psr';

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

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootRejectsOlderPsrContainerLoadedFirst(): void
    {
        $file = realpath(self::FIXTURES . '/container-1.0.php');
        self::assertIsString($file);
        self::assertFalse(interface_exists(ContainerInterface::class, false));
        require $file;

        try {
            Kernel::boot($this->config('OldContainer'));
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertStringStartsWith("TMSWP requires psr/container ^2.0, but Psr\\Container\\ContainerInterface loaded from {$file} is incompatible (get((untyped)) must be get(string); has((untyped)) must be has(string): bool)", $exception->getMessage());
        }

        // 宣言の合わない Container は読み込まれていない(読み込むと Fatal error になる)
        self::assertFalse(class_exists(Container::class, false));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootRejectsOlderPsrLogLoadedFirst(): void
    {
        $file = realpath(self::FIXTURES . '/log-1.1.php');
        self::assertIsString($file);
        self::assertFalse(interface_exists(LoggerInterface::class, false));
        require $file;

        try {
            Kernel::boot($this->config('OldLog'));
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertStringStartsWith("TMSWP requires psr/log ^3.0, but Psr\\Log\\LoggerInterface loaded from {$file} is incompatible (emergency((untyped), array) must be emergency(Stringable|string, array): void;", $exception->getMessage());
            self::assertStringContainsString('log((untyped), (untyped), array) must be log((untyped), Stringable|string, array): void)', $exception->getMessage());
        }

        self::assertFalse(class_exists(LoggerRegistry::class, false));
    }

    private function config(string $name): KernelConfig
    {
        $appDir = $this->createTempDirectory('psr-' . strtolower($name)) . '/app';
        mkdir($appDir);

        return KernelConfig::theme($appDir, 'Example\Tests\Psr' . $name . uniqid(), true);
    }
}
