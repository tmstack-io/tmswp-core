<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Log;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use LogicException;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use stdClass;
use Stringable;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Log\SimpleLogger;
use TMSWP\Testing\UnitTestCase;

/**
 * SimpleLoggerのファイルへの書き込み・ローテーション・失敗時の扱い(docs/07 §5.5)。旧iwf2bのSimpleLoggerTestの移植先
 * (docs/10 §8.1の20)。
 */
final class SimpleLoggerTest extends UnitTestCase
{
    public function testSimpleLoggerWritesOneLinePerRecordToUnguessableFileWithProtectionFiles(): void
    {
        $dir = $this->createTempDirectory('simple-logger');
        $this->stubLoggerFunctions();

        $logger = new SimpleLogger($dir, '{level}/{date}');
        $exception = new RuntimeException("outer\nforged", 0, new LogicException('inner'));
        $logger->info("created {id} {missing}\r\n2026-06-13 09:00:00 [ERROR] forged", ['id' => 10, 'object' => new stdClass(), 'note' => "a\nb", 'exception' => $exception]);

        $file = $dir . '/info/20260613-' . self::suffix('{level}/{date}') . '.log';
        self::assertFileExists($dir . '/.htaccess');
        self::assertFileExists($dir . '/index.php');
        self::assertFileExists($dir . '/info/index.php');
        self::assertFileExists($file);
        self::assertFileDoesNotExist($dir . '/info/20260613.log');

        $contents = file_get_contents($file);
        self::assertIsString($contents);
        self::assertSame(1, substr_count($contents, "\n"));
        self::assertStringEndsWith("\n", $contents);
        self::assertStringNotContainsString("\r", $contents);
        self::assertStringStartsWith('2026-06-13 09:00:00 [INFO] created 10 {missing}\r\n2026-06-13 09:00:00 [ERROR] forged {"id":10,"object":{},"note":"a\\nb"} ', $contents);
        self::assertStringContainsString(RuntimeException::class . ': outer\nforged in ' . __FILE__ . ':', $contents);
        self::assertStringContainsString('\nCaused by: ' . LogicException::class . ': inner in ' . __FILE__ . ':', $contents);
    }

    /**
     * docs/07の受け入れ基準「context['exception']のThrowableがpreviousのチェーンを含めて、クラス名・メッセージ・file:line・
     * スタックトレース付きで同じ行に追記される」を確かめる。外側とpreviousの両方のgetTraceAsString()が、§5.5の形のまま
     * (改行は\nの2文字にして)行の末尾に入る。
     */
    public function testSimpleLoggerAppendsStackTracesOfExceptionAndItsPreviousChainOnTheSameLine(): void
    {
        $dir = $this->createTempDirectory('simple-logger');
        $this->stubLoggerFunctions();
        $previous = self::createPreviousException();
        $exception = new RuntimeException('outer', 0, $previous);
        // previousは別のメソッドで作り、外側とトレースが異なるようにする(取り違えを見分ける)。
        self::assertStringStartsWith('#0 ', $previous->getTraceAsString());
        self::assertNotSame($exception->getTraceAsString(), $previous->getTraceAsString());

        (new SimpleLogger($dir))->error('failed', ['exception' => $exception]);

        $expected = RuntimeException::class . ': outer in ' . __FILE__ . ':' . $exception->getLine() . "\n"
            . $exception->getTraceAsString() . "\n"
            . 'Caused by: ' . LogicException::class . ': inner in ' . __FILE__ . ':' . $previous->getLine() . "\n"
            . $previous->getTraceAsString();
        self::assertSame(
            '2026-06-13 09:00:00 [ERROR] failed ' . str_replace("\n", '\n', $expected) . "\n",
            file_get_contents("{$dir}/20260613-" . self::suffix('{date}') . '.log'),
        );
    }

    /**
     * docs/07の受け入れ基準「{key}プレースホルダ補間がscalar/Stringable/BackedEnum/nullのcontext値でのみ行われる」を確かめる。
     * 補間する値はStr::stringify()の表記(boolはtrue/false、nullは空文字)になり、配列・Stringableでないobject・
     * UnitEnumの{key}は(Str::stringify()が文字列にできる値でも)そのまま残る。
     */
    public function testSimpleLoggerInterpolatesOnlyScalarStringableBackedEnumAndNullValues(): void
    {
        $dir = $this->createTempDirectory('simple-logger');
        $this->stubLoggerFunctions();

        (new SimpleLogger($dir))->info('{string}|{int}|{float}|{true}|{false}|{null}|{stringable}|{stringEnum}|{intEnum}|{array}|{object}|{unitEnum}', [
            'string' => 'text',
            'int' => 7,
            'float' => 1.5,
            'true' => true,
            'false' => false,
            'null' => null,
            'stringable' => new class implements Stringable {
                public function __toString(): string
                {
                    return 'stringable';
                }
            },
            'stringEnum' => InterpolationStringEnum::Active,
            'intEnum' => InterpolationIntEnum::High,
            'array' => ['a', 'b'],
            'object' => new DateTimeImmutable('2026-06-13T09:00:00+09:00'),
            'unitEnum' => InterpolationUnitEnum::Red,
        ]);

        $contents = file_get_contents("{$dir}/20260613-" . self::suffix('{date}') . '.log');
        self::assertIsString($contents);
        self::assertStringStartsWith('2026-06-13 09:00:00 [INFO] text|7|1.5|true|false||stringable|active|3|{array}|{object}|{unitEnum} {"string":"text",', $contents);
    }

    public function testSimpleLoggerRotatesOnlyItsOwnFiles(): void
    {
        $dir = $this->createTempDirectory('simple-logger');
        $this->stubLoggerFunctions();
        $own = self::suffix('{date}');

        $foreign = [
            'mail-20260601-' . self::suffix('mail-{date}') . '.log' => 100,
            'mail-20260602-' . self::suffix('mail-{date}') . '.log' => 200,
            '20260601.log' => 300,
            '20260601-' . self::suffix('{level}') . '.log' => 400,
        ];
        self::createFiles($dir, ["20260610-{$own}.log" => 1000, "20260611-{$own}.log" => 2000] + $foreign);

        (new SimpleLogger($dir, maxFiles: 2))->info('new day');

        self::assertFileDoesNotExist("{$dir}/20260610-{$own}.log");
        self::assertFileExists("{$dir}/20260611-{$own}.log");
        self::assertFileExists("{$dir}/20260613-{$own}.log");

        foreach (array_keys($foreign) as $name) {
            self::assertFileExists($dir . '/' . $name);
        }
    }

    public function testSimpleLoggerRotationKeepsFilesOfLoggerWithOverlappingFormat(): void
    {
        $dir = $this->createTempDirectory('simple-logger');
        $this->stubLoggerFunctions();
        $levelLogger = self::suffix('{level}-{date}');
        $errorLogger = self::suffix('error-{date}');

        // error-{date} のファイルは {level}-{date} の名前の形にも当てはまるが、接尾辞で区別する。
        self::createFiles($dir, [
            "error-20200101-{$errorLogger}.log" => 100,
            "error-20200102-{$errorLogger}.log" => 200,
            "error-20260610-{$levelLogger}.log" => 1000,
            "info-20260611-{$levelLogger}.log" => 2000,
        ]);

        (new SimpleLogger($dir, '{level}-{date}', maxFiles: 2))->info('level logger');
        (new SimpleLogger($dir, 'error-{date}', maxFiles: 3))->error('error logger');

        self::assertFileDoesNotExist("{$dir}/error-20260610-{$levelLogger}.log");
        self::assertFileExists("{$dir}/info-20260611-{$levelLogger}.log");
        self::assertFileExists("{$dir}/info-20260613-{$levelLogger}.log");
        self::assertFileExists("{$dir}/error-20200101-{$errorLogger}.log");
        self::assertFileExists("{$dir}/error-20200102-{$errorLogger}.log");
        self::assertFileExists("{$dir}/error-20260613-{$errorLogger}.log");
    }

    public function testSimpleLoggerRotationListsDirectoryWithGlobCharactersLiterally(): void
    {
        $base = $this->createTempDirectory('simple-logger');
        $this->stubLoggerFunctions();
        $own = self::suffix('{date}');

        // glob() なら logs[ab] は logsa にも一致する。
        mkdir("{$base}/logs[ab]");
        mkdir("{$base}/logsa");
        self::createFiles("{$base}/logs[ab]", ["20260611-{$own}.log" => 2000]);
        self::createFiles("{$base}/logsa", ["20260601-{$own}.log" => 100, "20260602-{$own}.log" => 200, "20260603-{$own}.log" => 300]);

        (new SimpleLogger("{$base}/logs[ab]", maxFiles: 2))->info('bracket');

        self::assertFileExists("{$base}/logs[ab]/20260611-{$own}.log");
        self::assertFileExists("{$base}/logs[ab]/20260613-{$own}.log");
        self::assertFileExists("{$base}/logsa/20260601-{$own}.log");
        self::assertFileExists("{$base}/logsa/20260602-{$own}.log");
        self::assertFileExists("{$base}/logsa/20260603-{$own}.log");
    }

    /** @return iterable<string, array{int, list<string>}> */
    public static function nonRegularEntryProvider(): iterable
    {
        yield 'maxFiles 1' => [1, ['20260613']];
        yield 'maxFiles 2' => [2, ['20260611', '20260613']];
    }

    /** @param list<string> $kept 残る通常のログの日付 */
    #[DataProvider('nonRegularEntryProvider')]
    public function testSimpleLoggerRotationCountsOnlyRegularFiles(int $maxFiles, array $kept): void
    {
        $dir = $this->createTempDirectory('simple-logger');
        $this->stubLoggerFunctions();
        $own = self::suffix('{date}');

        self::createFiles($dir, ["20260611-{$own}.log" => 2000, 'target.txt' => 100]);
        // 名前が自分のファイルと一致するディレクトリ(更新日時は未来)と、実在するファイルを指すシンボリックリンク。
        mkdir("{$dir}/20990101-{$own}.log");
        touch("{$dir}/20990101-{$own}.log", 4102444800);
        symlink("{$dir}/target.txt", "{$dir}/20200101-{$own}.log");

        (new SimpleLogger($dir, maxFiles: $maxFiles))->info('new day');

        foreach (['20260611', '20260613'] as $date) {
            self::assertSame(\in_array($date, $kept, true), is_file("{$dir}/{$date}-{$own}.log"), $date);
        }

        self::assertDirectoryExists("{$dir}/20990101-{$own}.log");
        self::assertTrue(is_link("{$dir}/20200101-{$own}.log"));
        self::assertFileExists("{$dir}/target.txt");
    }

    /**
     * docs/07の受け入れ基準「maxFiles超過時に…mtimeの古いものから削除される」を確かめる。ファイル名の日付の順と
     * 更新日時の順が逆のとき、名前ではなく更新日時の古いものが削除される。
     */
    public function testSimpleLoggerRotationDeletesOldestByModificationTimeRatherThanFileName(): void
    {
        $dir = $this->createTempDirectory('simple-logger');
        $this->stubLoggerFunctions();
        $own = self::suffix('{date}');

        self::createFiles($dir, ["20260601-{$own}.log" => 2000, "20260602-{$own}.log" => 1000]);

        (new SimpleLogger($dir, maxFiles: 2))->info('new day');

        self::assertFileExists("{$dir}/20260601-{$own}.log");
        self::assertFileDoesNotExist("{$dir}/20260602-{$own}.log");
        self::assertFileExists("{$dir}/20260613-{$own}.log");
    }

    /**
     * docs/07の受け入れ基準「maxFiles: 0で削除されない」を確かめる。
     */
    public function testSimpleLoggerWithZeroMaxFilesDeletesNoFiles(): void
    {
        $dir = $this->createTempDirectory('simple-logger');
        $this->stubLoggerFunctions();
        $own = self::suffix('{date}');

        self::createFiles($dir, ["20260601-{$own}.log" => 100, "20260602-{$own}.log" => 200]);

        (new SimpleLogger($dir, maxFiles: 0))->info('new day');

        self::assertFileExists("{$dir}/20260601-{$own}.log");
        self::assertFileExists("{$dir}/20260602-{$own}.log");
        self::assertFileExists("{$dir}/20260613-{$own}.log");
    }

    public function testSimpleLoggerReportsRotationFailureToErrorLogOnlyOnce(): void
    {
        $own = self::suffix('{date}');
        // ファイルの作成と追記はできるが、ファイルを削除できないディレクトリ。
        $logs = (new UndeletableDirectory('logs'))->at(vfsStream::setup('root'));
        vfsStream::newFile("20260611-{$own}.log")->withContent('old')->lastModified(2000)->at($logs);
        $this->stubLoggerFunctions();
        $reports = $this->recordTriggeredErrors();
        $logger = new SimpleLogger($logs->url(), maxFiles: 1);

        $this->withCapturedErrorLog(function (string $errorLog) use ($logger, $logs, $reports, $own): void {
            self::captureWarnings(static fn() => $logger->info('first'));

            $message = 'ログのローテーションに失敗しました。古いログファイルを削除できません: ' . $logs->url() . "/20260611-{$own}.log";
            self::assertSame([$message], $reports->messages);
            self::assertSame([SimpleLogger::class . ': ' . $message], self::errorLogMessages($errorLog));

            // 次の日のファイルを作るときも削除に失敗するが、可視化は初回だけ。
            Functions\when('wp_date')->alias(static fn(string $format): string => $format === 'Ymd' ? '20260614' : '2026-06-14 09:00:00');
            self::captureWarnings(static fn() => $logger->info('second'));

            self::assertSame([$message], $reports->messages);
            self::assertCount(2, self::errorLogMessages($errorLog));
            self::assertStringContainsString("/20260613-{$own}.log", self::errorLogMessages($errorLog)[1]);
        });

        $contents = file_get_contents($logs->url() . "/20260613-{$own}.log");
        self::assertIsString($contents);
        self::assertStringContainsString('[INFO] first', $contents);
    }

    public function testSimpleLoggerReportsRotationFailureWhenDirectoryCannotBeListed(): void
    {
        // 他のユーザーが所有する 0733 のディレクトリは、書き込めるが列挙できない。
        $unlistable = vfsStream::newDirectory('logs', 0733)->chown(vfsStream::OWNER_USER_1)->at(vfsStream::setup('root'));
        $this->stubLoggerFunctions();
        $reports = $this->recordTriggeredErrors();

        $this->withCapturedErrorLog(function (string $errorLog) use ($unlistable, $reports): void {
            self::captureWarnings(static fn() => (new SimpleLogger($unlistable->url()))->info('unlistable'));

            $message = 'ログのローテーションに失敗しました。ログディレクトリを列挙できません: ' . $unlistable->url();
            self::assertSame([$message], $reports->messages);
            self::assertSame([SimpleLogger::class . ': ' . $message], self::errorLogMessages($errorLog));
        });

        self::assertTrue($unlistable->hasChild('20260613-' . self::suffix('{date}') . '.log'));
    }

    /**
     * docs/07の受け入れ基準「ローテーションの…更新日時の取得…に失敗したとき、理由がPHPのerror_logへ出て、wp_trigger_errorは
     * 初回の失敗の1回だけ呼ばれる」を確かめる。更新日時を取れないときは何も削除しない(§5.5のローテーション)。
     *
     * PHPのstatのキャッシュにより、is_file()が成功した直後のfilemtime()は同じキャッシュを読むため、ファイルの操作では
     * 失敗させられない。SimpleLoggerは名前空間の中からfilemtime()を修飾なしで呼ぶので、同じ名前空間の関数を定義して
     * 失敗を作る。関数の解決は呼び出し箇所ごとに初回にキャッシュされるため、別のプロセスで実行する。
     */
    #[RunInSeparateProcess]
    public function testSimpleLoggerReportsRotationFailureWhenModificationTimeCannotBeReadOnlyOnce(): void
    {
        $dir = $this->createTempDirectory('simple-logger');
        $own = self::suffix('{date}');
        self::createFiles($dir, ["20260611-{$own}.log" => 2000]);
        $this->stubLoggerFunctions();
        Functions\when('TMSWP\\Core\\Log\\filemtime')->justReturn(false);
        $reports = $this->recordTriggeredErrors();
        $logger = new SimpleLogger($dir, maxFiles: 1);

        $this->withCapturedErrorLog(function (string $errorLog) use ($logger, $dir, $reports, $own): void {
            $logger->info('first');

            $message = 'ログのローテーションに失敗しました。ログファイルの更新日時を取得できません: ' . $dir . "/20260611-{$own}.log";
            self::assertSame([$message], $reports->messages);
            self::assertSame([SimpleLogger::class . ': ' . $message], self::errorLogMessages($errorLog));

            // 次の日のファイルを作るときも更新日時を取れないが、可視化は初回だけ。
            Functions\when('wp_date')->alias(static fn(string $format): string => $format === 'Ymd' ? '20260614' : '2026-06-14 09:00:00');
            $logger->info('second');

            self::assertSame([$message], $reports->messages);
            self::assertSame([SimpleLogger::class . ': ' . $message, SimpleLogger::class . ': ' . $message], self::errorLogMessages($errorLog));
        });

        self::assertFileExists("{$dir}/20260611-{$own}.log");
        self::assertFileExists("{$dir}/20260613-{$own}.log");
        self::assertFileExists("{$dir}/20260614-{$own}.log");
    }

    public function testSimpleLoggerFallsBackToErrorLogForUnwritableDirectoryAndReportsOnlyFirstFailure(): void
    {
        // 他のユーザーが所有する 0755 のディレクトリは、テストを実行するユーザーから書き込めない。
        $readOnly = vfsStream::newDirectory('logs', 0755)->chown(vfsStream::OWNER_USER_1)->at(vfsStream::setup('root'));
        $this->stubLoggerFunctions();
        $reports = $this->recordTriggeredErrors();

        $this->withCapturedErrorLog(function (string $errorLog) use ($readOnly, $reports): void {
            $logger = new SimpleLogger($readOnly->url());

            $logger->error('first failure');
            self::assertCount(1, $reports->messages);
            self::assertStringContainsString('ログディレクトリに書き込めません: ' . $readOnly->url(), $reports->messages[0]);
            self::assertSame(['2026-06-13 09:00:00 [ERROR] first failure'], self::errorLogMessages($errorLog));

            $logger->error('second failure');
            self::assertCount(1, $reports->messages);
            self::assertSame(['2026-06-13 09:00:00 [ERROR] first failure', '2026-06-13 09:00:00 [ERROR] second failure'], self::errorLogMessages($errorLog));
        });

        self::assertSame([], $readOnly->getChildren());
    }

    public function testSimpleLoggerFallsBackToErrorLogWhenDirectoryCannotBeCreated(): void
    {
        $dir = $this->createTempDirectory('simple-logger') . '/missing';
        $this->stubLoggerFunctions(mkdirSucceeds: false);
        $reports = $this->recordTriggeredErrors();

        $this->withCapturedErrorLog(function (string $errorLog) use ($dir, $reports): void {
            (new SimpleLogger($dir))->warning("line\nbreak");

            self::assertSame(['2026-06-13 09:00:00 [WARNING] line\nbreak'], self::errorLogMessages($errorLog));
            self::assertCount(1, $reports->messages);
            self::assertStringContainsString('ログディレクトリを作成できません: ' . $dir, $reports->messages[0]);
        });

        self::assertDirectoryDoesNotExist($dir);
    }

    #[RunInSeparateProcess]
    public function testSimpleLoggerFallsBackToErrorLogBeforePluggableFunctionsAreLoaded(): void
    {
        $dir = $this->createTempDirectory('simple-logger');
        Functions\when('wp_date')->justReturn('2026-06-13 09:00:00');
        $reports = $this->recordTriggeredErrors();

        $this->withCapturedErrorLog(function (string $errorLog) use ($dir, $reports): void {
            self::assertFalse(\function_exists('wp_hash'));
            (new SimpleLogger($dir))->notice('early');

            self::assertSame(['2026-06-13 09:00:00 [NOTICE] early'], self::errorLogMessages($errorLog));
            self::assertCount(1, $reports->messages);
            self::assertStringContainsString('wp_hash() が未定義', $reports->messages[0]);
        });

        self::assertSame(['.', '..'], scandir($dir));
    }

    public function testSimpleLoggerRejectsParentSegmentsInFileNameFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SimpleLogger('/tmp/logs', '../{date}');
    }

    private function stubLoggerFunctions(bool $mkdirSucceeds = true): void
    {
        Functions\when('wp_mkdir_p')->alias(static function (string $path) use ($mkdirSucceeds): bool {
            return $mkdirSucceeds && (is_dir($path) || mkdir($path, 0775, true));
        });
        Functions\when('wp_date')->alias(static fn(string $format): string => $format === 'Ymd' ? '20260613' : '2026-06-13 09:00:00');
        Functions\when('wp_hash')->alias(static fn(string $data): string => md5('salt|' . $data));
    }

    /** 呼び出したメソッドがトレースの#0に入る、previousにする例外 */
    private static function createPreviousException(): LogicException
    {
        return new LogicException('inner');
    }

    /** stubLoggerFunctions() の wp_hash() による、fileNameFormat ごとのファイル名の接尾辞 */
    private static function suffix(string $fileNameFormat): string
    {
        return md5('salt|tmswp-simple-logger:' . $fileNameFormat);
    }

    /** @param array<string, int> $files ファイル名 => 更新日時 */
    private static function createFiles(string $dir, array $files): void
    {
        foreach ($files as $name => $mtime) {
            file_put_contents("{$dir}/{$name}", 'old');
            touch("{$dir}/{$name}", $mtime);
        }
    }

    /**
     * 呼び出し中の PHP の警告を受け取って返す(unlink() などの失敗が出す警告をテストの失敗にしない)。
     *
     * @return list<string>
     */
    private static function captureWarnings(callable $call): array
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_WARNING | E_USER_WARNING);

        try {
            $call();
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }

    private function recordTriggeredErrors(): TriggeredErrors
    {
        $reports = new TriggeredErrors();
        Functions\when('wp_trigger_error')->alias(static function (string $function, string $message, int $level) use ($reports): void {
            self::assertSame(SimpleLogger::class . '::log', $function);
            self::assertSame(E_USER_WARNING, $level);
            $reports->messages[] = $message;
        });

        return $reports;
    }

    /** @param callable(string): void $test */
    private function withCapturedErrorLog(callable $test): void
    {
        $errorLog = $this->createTempDirectory('error-log') . '/php-error.log';
        $previous = ini_set('error_log', $errorLog);

        try {
            $test($errorLog);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
    }

    /** @return list<string> PHP の error_log() が各行の先頭に付ける日時を除いたメッセージ */
    private static function errorLogMessages(string $errorLog): array
    {
        $lines = file($errorLog, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        return array_map(static fn(string $line): string => preg_replace('/\A\[[^\]]+\] /', '', $line) ?? $line, $lines);
    }
}

/** ファイルを削除できない vfsStream のディレクトリ(unlink() の失敗を作る) */
final class UndeletableDirectory extends vfsStreamDirectory
{
    public function removeChild($name): bool
    {
        return false;
    }
}

final class TriggeredErrors
{
    /** @var list<string> */
    public array $messages = [];
}

/** プレースホルダの補間で使う、文字列のBackedEnum */
enum InterpolationStringEnum: string
{
    case Active = 'active';
}

/** プレースホルダの補間で使う、整数のBackedEnum */
enum InterpolationIntEnum: int
{
    case High = 3;
}

/** プレースホルダの補間で使う、BackedEnumでないenum */
enum InterpolationUnitEnum
{
    case Red;
}
