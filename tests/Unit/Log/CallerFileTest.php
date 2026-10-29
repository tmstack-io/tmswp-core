<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Log\CallerFile;
use TMSWP\Core\Log\Log;
use TMSWP\Testing\UnitTestCase;

/**
 * Log・tms_log() の呼び出し元のファイルを、パスの文字列ではなくフレームの関数の種類で決めることを確かめる。
 * パスは Windows の形(区切り文字が `\`)にし、Log.php・functions.php の場所とも関係の無い値にする。
 */
final class CallerFileTest extends UnitTestCase
{
    private const string LOG_FILE = 'C:\site\wp-content\themes\shop\vendor\tmswp\core\src\Log\Log.php';

    private const string HELPER_FILE = 'C:\site\wp-content\themes\shop\vendor\tmswp\core\src\functions.php';

    private const string PLUGIN_FILE = 'C:\site\wp-content\plugins\shop-extra\templates\card.php';

    /** @return iterable<string, array{list<array<string, mixed>>, string}> */
    public static function callerFrames(): iterable
    {
        yield 'tms_log()' => [[
            ['file' => self::LOG_FILE, 'line' => 30, 'function' => 'callerFile', 'class' => Log::class, 'type' => '::'],
            ['file' => self::HELPER_FILE, 'line' => 41, 'function' => 'registry', 'class' => Log::class, 'type' => '::'],
            ['file' => self::PLUGIN_FILE, 'line' => 7, 'function' => 'tms_log'],
            ['file' => 'C:\site\wp-includes\template.php', 'line' => 812, 'function' => 'require'],
        ], self::PLUGIN_FILE];
        yield 'Log::error()' => [[
            ['file' => self::LOG_FILE, 'line' => 30, 'function' => 'callerFile', 'class' => Log::class, 'type' => '::'],
            ['file' => self::LOG_FILE, 'line' => 75, 'function' => 'registry', 'class' => Log::class, 'type' => '::'],
            ['file' => self::PLUGIN_FILE, 'line' => 9, 'function' => 'error', 'class' => Log::class, 'type' => '::'],
            ['file' => 'C:\site\wp-content\plugins\shop-extra\app\Hook\Shop.php', 'line' => 20, 'function' => 'render', 'class' => 'ShopExtra\Hook\Shop', 'type' => '->'],
        ], self::PLUGIN_FILE];
        yield 'eval したコード' => [[
            ['file' => self::LOG_FILE, 'line' => 30, 'function' => 'callerFile', 'class' => Log::class, 'type' => '::'],
            ['file' => self::LOG_FILE, 'line' => 75, 'function' => 'registry', 'class' => Log::class, 'type' => '::'],
            ['file' => self::PLUGIN_FILE . "(12) : eval()'d code", 'line' => 1, 'function' => 'error', 'class' => Log::class, 'type' => '::'],
        ], self::PLUGIN_FILE];
        yield '入れ子の eval' => [[
            ['file' => self::LOG_FILE, 'line' => 30, 'function' => 'callerFile', 'class' => Log::class, 'type' => '::'],
            ['file' => self::PLUGIN_FILE . "(12) : eval()'d code(3) : eval()'d code", 'line' => 1, 'function' => 'tms_log'],
        ], self::PLUGIN_FILE];
    }

    /** @param list<array<string, mixed>> $frames */
    #[DataProvider('callerFrames')]
    public function testCallerIsTheFileOfTheLastLogOrHelperFrame(array $frames, string $expected): void
    {
        self::assertSame($expected, CallerFile::fromBacktrace($frames));
    }

    public function testLastLogFrameWithoutFileIsRejected(): void
    {
        $this->expectException(KernelException::class);
        $this->expectExceptionMessage('cannot determine the file that called ' . Log::class . '::notice()');

        CallerFile::fromBacktrace([
            ['file' => self::LOG_FILE, 'line' => 30, 'function' => 'callerFile', 'class' => Log::class, 'type' => '::'],
            ['function' => 'notice', 'class' => Log::class, 'type' => '::'],
            ['file' => self::PLUGIN_FILE, 'line' => 3, 'function' => 'array_map'],
        ]);
    }

    public function testBacktraceThatDoesNotStartWithLogIsRejected(): void
    {
        $this->expectException(KernelException::class);
        $this->expectExceptionMessage('the backtrace does not start with a call of Log or tms_log()');

        CallerFile::fromBacktrace([
            ['file' => self::PLUGIN_FILE, 'line' => 3, 'function' => 'render', 'class' => 'ShopExtra\Hook\Shop', 'type' => '->'],
        ]);
    }
}
