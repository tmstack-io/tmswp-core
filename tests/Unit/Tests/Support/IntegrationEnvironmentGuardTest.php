<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Tests\Support;

use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use TMSWP\Core\Tests\Support\IntegrationEnvironmentGuard;
use TMSWP\Testing\UnitTestCase;

final class IntegrationEnvironmentGuardTest extends UnitTestCase
{
    private string $testsDir;

    protected function setUp(): void
    {
        parent::setUp();

        vfsStream::setup('root', null, [
            'wordpress-phpunit' => ['includes' => ['bootstrap.php' => '<?php']],
            'not-a-test-library' => ['index.php' => '<?php'],
        ]);
        $this->testsDir = vfsStream::url('root/wordpress-phpunit');
    }

    public function testCleanStateReturnsTestsDir(): void
    {
        self::assertSame(
            $this->testsDir,
            IntegrationEnvironmentGuard::assertSafe(
                $this->testsDir,
                ['PHPUNIT_COMPOSER_INSTALL' => '/vendor/autoload.php'],
                ['argv' => [], '_SERVER' => []],
            ),
        );
    }

    /** @return iterable<string, array{string|false}> */
    public static function invalidTestsDirs(): iterable
    {
        yield 'unset' => [false];
        yield 'empty' => [''];
        yield 'not a test library' => [vfsStream::url('root/not-a-test-library')];
        yield 'missing' => [vfsStream::url('root/missing')];
    }

    #[DataProvider('invalidTestsDirs')]
    public function testRejectsInvalidTestsDir(string|false $wpTestsDir): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WP_TESTS_DIR must point to the WordPress test library');

        IntegrationEnvironmentGuard::assertSafe($wpTestsDir, [], []);
    }

    /** @return iterable<string, array{string}> */
    public static function predefinedConstants(): iterable
    {
        foreach ([
            'WP_TESTS_CONFIG_FILE_PATH',
            'WP_TESTS_TABLE_PREFIX',
            'CUSTOM_USER_TABLE',
            'CUSTOM_USER_META_TABLE',
            'ABSPATH',
            'DB_NAME',
            'DB_USER',
            'DB_PASSWORD',
            'DB_HOST',
            'DB_CHARSET',
            'DB_COLLATE',
            'WP_TESTS_DOMAIN',
            'WP_TESTS_EMAIL',
            'WP_TESTS_TITLE',
            'WP_PHP_BINARY',
            'WP_DEBUG',
        ] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('predefinedConstants')]
    public function testRejectsPredefinedConstant(string $name): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("constant {$name} is already defined");

        IntegrationEnvironmentGuard::assertSafe($this->testsDir, [$name => 'wp_users'], []);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function presetGlobals(): iterable
    {
        yield 'table_prefix of the development site' => ['table_prefix', 'wp_'];
        yield 'table_prefix even if it is wptests_' => ['table_prefix', 'wptests_'];
        yield 'wpdb' => ['wpdb', new stdClass()];
    }

    #[DataProvider('presetGlobals')]
    public function testRejectsPresetWordPressGlobal(string $name, mixed $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("global \${$name} is already set");

        IntegrationEnvironmentGuard::assertSafe($this->testsDir, [], [$name => $value]);
    }

    public function testReportsEveryProblemAtOnce(): void
    {
        try {
            IntegrationEnvironmentGuard::assertSafe(
                $this->testsDir,
                ['CUSTOM_USER_TABLE' => 'wp_users', 'CUSTOM_USER_META_TABLE' => 'wp_usermeta'],
                ['table_prefix' => 'wp_'],
            );
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('constant CUSTOM_USER_TABLE is already defined', $exception->getMessage());
            self::assertStringContainsString('constant CUSTOM_USER_META_TABLE is already defined', $exception->getMessage());
            self::assertStringContainsString('global $table_prefix is already set', $exception->getMessage());

            return;
        }

        self::fail('RuntimeException was not thrown');
    }
}
