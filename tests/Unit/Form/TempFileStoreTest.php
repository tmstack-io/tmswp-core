<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form;

use Brain\Monkey\Functions;
use Closure;
use Exception;
use FilesystemIterator;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use SplFileInfo;
use TMSWP\Core\Exception\Form\TempFileException;
use TMSWP\Core\Form\StoredFile;
use TMSWP\Core\Form\TempFileGc;
use TMSWP\Core\Form\TempFileStore;
use TMSWP\Core\Form\UploadedFile;
use TMSWP\Testing\UnitTestCase;
use WP;

/**
 * TempFileStore・UploadedFile・StoredFile・TempFileGcの一時ファイルの保管(docs/05 §UploadedFile / StoredFile / TempFileStore。
 * docs/10 §8.2のUploadedFile / TempFileStore)。
 */
final class TempFileStoreTest extends UnitTestCase
{
    /** 保管場所の外に置く、保管済みのファイルと同じ形のトークン */
    private const string OUTSIDE_TOKEN = '0123456789abcdef0123456789abcdef';

    public function testTempFileStoreResolvesMovesDeletesAndGarbageCollectsFiles(): void
    {
        Functions\when('wp_unique_filename')->alias(static fn(string $_directory, string $filename): string => $filename);

        $baseDir = $this->createTempDirectory('temp-file-store');
        $store = new TempFileStore($baseDir);
        $token = '0123456789abcdef0123456789abcdef';
        $this->createStoredFile($baseDir, $token, 'document.txt', 'text/plain', 'text', time() - 100);

        $stored = $store->resolve($token);

        self::assertSame('document.txt', $stored->clientName);
        self::assertTrue($store->exists($token));

        $targetDir = $this->createTempDirectory('temp-file-target');
        $target = $stored->moveTo($targetDir, 'moved.txt');

        self::assertSame($targetDir . DIRECTORY_SEPARATOR . 'moved.txt', $target);
        self::assertFileExists($target);
        self::assertFalse($store->exists($token));

        $oldToken = 'ffffffffffffffffffffffffffffffff';
        $this->createStoredFile($baseDir, $oldToken, 'old.txt', 'text/plain', 'old', time() - 86401);

        self::assertSame(1, $store->gc(86400));
        self::assertFalse($store->exists($oldToken));
    }

    public function testTempFileStoreRejectsInvalidTokensAndBrokenMetadata(): void
    {
        $baseDir = $this->createTempDirectory('temp-file-invalid');
        $store = new TempFileStore($baseDir);
        $token = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $dir = $baseDir . DIRECTORY_SEPARATOR . $token;
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/meta.json', '{broken');

        $this->expectException(TempFileException::class);
        $store->resolve($token);
    }

    /**
     * トークンの形(32桁の小文字の16進数)でない値。{root}は保管場所の親ディレクトリに置き換える。
     *
     * @return iterable<string, array{string}>
     */
    public static function tokensNotOfTheTokenForm(): iterable
    {
        yield 'parent and a sibling' => ['../sibling'];
        yield 'parent and a token' => ['../' . self::OUTSIDE_TOKEN];
        yield 'nested parents' => ['store/../../sibling'];
        yield 'absolute path' => ['{root}' . DIRECTORY_SEPARATOR . 'sibling'];
        yield 'parent only' => ['..'];
        yield 'empty' => [''];
        yield 'upper case' => [strtoupper(self::OUTSIDE_TOKEN)];
        yield '31 digits' => [substr(self::OUTSIDE_TOKEN, 0, 31)];
        yield 'trailing new line' => [self::OUTSIDE_TOKEN . "\n"];
    }

    /**
     * resolve() は、トークンの形(32桁の小文字の16進数)でない値を、パスにする前に TempFileException で拒否する。保管場所の
     * 外に保管済みのファイルと同じ形のファイルを置き、`../` 等でそれを指しても読めないことを確かめる。
     */
    #[DataProvider('tokensNotOfTheTokenForm')]
    public function testTempFileStoreResolveRejectsTokensThatAreNotOfTheTokenForm(string $token): void
    {
        $root = $this->createTempDirectory('temp-file-traversal');
        $baseDir = $root . DIRECTORY_SEPARATOR . 'store';
        self::assertTrue(mkdir($baseDir));
        $store = new TempFileStore($baseDir);
        // 保管場所の外(親ディレクトリ)の、保管済みのファイルと同じ形のファイル
        $this->createStoredFile($root, 'sibling', 'secret.txt', 'text/plain', 'secret', time());
        $this->createStoredFile($root, self::OUTSIDE_TOKEN, 'secret.txt', 'text/plain', 'secret', time());
        $token = str_replace('{root}', $root, $token);

        try {
            $store->resolve($token);
            self::fail('resolve() accepted ' . var_export($token, true));
        } catch (TempFileException $exception) {
            self::assertSame("invalid temporary file token: {$token}", $exception->getMessage());
        }

        self::assertFalse($store->exists($token), var_export($token, true));
    }

    public function testTempFileStoreCreatesProtectedBaseDirectoryOnFirstStore(): void
    {
        Functions\when('TMSWP\\Core\\Form\\is_uploaded_file')->alias(static fn(string $path): bool => is_file($path));
        Functions\when('sanitize_file_name')->alias(static fn(string $filename): string => $filename);
        Functions\when('TMSWP\\Core\\Form\\move_uploaded_file')->alias(static fn(string $from, string $to): bool => rename($from, $to));
        // 期限切れのファイルを消す WP-Cron のイベントはスケジュール済み
        Functions\when('wp_next_scheduled')->justReturn(1);

        $tmpPath = $this->createTempDirectory('temp-file-protection-upload') . '/upload.txt';
        file_put_contents($tmpPath, 'sample upload');
        $baseDir = $this->createTempDirectory('temp-file-protection') . '/store';
        $store = new TempFileStore($baseDir);

        self::assertDirectoryDoesNotExist($baseDir);

        $stored = $store->store(new UploadedFile($tmpPath, 'upload.txt', 'text/plain', 13, UPLOAD_ERR_OK));

        self::assertDirectoryExists($baseDir);
        // Apache 2.4(mod_authz_core)と 2.2 の両方の構文で拒否する
        $htaccess = (string) file_get_contents($baseDir . '/.htaccess');
        self::assertStringContainsString("<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>", $htaccess);
        self::assertStringContainsString("<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>", $htaccess);
        self::assertSame('', file_get_contents($baseDir . '/index.html'));
        self::assertStringStartsWith($baseDir . DIRECTORY_SEPARATOR, $stored->path);

        // 2回目以降の保管では書き直さない
        file_put_contents($baseDir . '/.htaccess', 'edited by the site');
        file_put_contents($baseDir . '/index.html', 'edited by the site');
        file_put_contents($tmpPath, 'second upload');
        $store->store(new UploadedFile($tmpPath, 'upload.txt', 'text/plain', 13, UPLOAD_ERR_OK));

        self::assertSame('edited by the site', file_get_contents($baseDir . '/.htaccess'));
        self::assertSame('edited by the site', file_get_contents($baseDir . '/index.html'));
    }

    public function testStoredFileIsSavedUnderAFixedNameSoAClientNameOfMetaJsonCannotOverwriteTheMetadata(): void
    {
        Functions\when('TMSWP\\Core\\Form\\is_uploaded_file')->alias(static fn(string $path): bool => is_file($path));
        Functions\when('sanitize_file_name')->alias(static fn(string $filename): string => $filename);
        Functions\when('TMSWP\\Core\\Form\\move_uploaded_file')->alias(static fn(string $from, string $to): bool => rename($from, $to));
        // 期限切れのファイルを消す WP-Cron のイベントはスケジュール済み
        Functions\when('wp_next_scheduled')->justReturn(1);
        $tmpPath = $this->createTempDirectory('meta-json-upload') . '/upload';
        file_put_contents($tmpPath, 'uploaded contents');
        $store = new TempFileStore($this->createTempDirectory('meta-json-store'));

        $stored = $store->store(new UploadedFile($tmpPath, 'meta.json', 'application/json', 17, UPLOAD_ERR_OK));

        // 保存名にクライアントのファイル名を使わない
        self::assertSame(TempFileStore::FILE_NAME, basename($stored->path));
        self::assertSame('meta.json', $stored->clientName);
        self::assertSame('uploaded contents', file_get_contents($stored->path));

        $resolved = $store->resolve($stored->token);

        self::assertSame($stored->path, $resolved->path);
        self::assertSame('meta.json', $resolved->clientName);
        self::assertSame('uploaded contents', file_get_contents($resolved->path));
    }

    public function testTempFileStoreDetectsMimeBeforeMovingUploadedFile(): void
    {
        Functions\when('TMSWP\\Core\\Form\\is_uploaded_file')->alias(static fn(string $path): bool => is_file($path));
        Functions\when('sanitize_file_name')->alias(static fn(string $filename): string => $filename);
        Functions\when('TMSWP\\Core\\Form\\move_uploaded_file')->alias(static fn(string $from, string $to): bool => rename($from, $to));
        // 期限切れのファイルを消す WP-Cron のイベントはスケジュール済み
        Functions\when('wp_next_scheduled')->justReturn(1);

        $tmpPath = $this->createTempDirectory('uploaded-mime') . '/upload.txt';
        file_put_contents($tmpPath, 'sample upload');
        $store = new TempFileStore($this->createTempDirectory('uploaded-mime-store'));

        $stored = $store->store(new UploadedFile($tmpPath, 'upload.txt', 'text/plain', 13, UPLOAD_ERR_OK));

        self::assertSame('text/plain', $stored->mime);
        self::assertFileExists($stored->path);
        self::assertFileDoesNotExist($tmpPath);
    }

    public function testUploadedFileMimeDetectionFailuresAreTempFileExceptionsInsteadOfADefaultMime(): void
    {
        $missing = new UploadedFile($this->createTempDirectory('mime-missing') . '/gone.txt', 'gone.txt', 'text/plain', 1, UPLOAD_ERR_OK);

        try {
            $missing->detectedMime();
            self::fail('TempFileException was not thrown for a file that cannot be read');
        } catch (TempFileException $exception) {
            self::assertStringStartsWith('failed to detect the MIME type of the uploaded file ', $exception->getMessage());
        }

        $path = $this->createTempDirectory('mime-magic') . '/upload.txt';
        file_put_contents($path, 'sample upload');
        $file = new UploadedFile($path, 'upload.txt', 'text/plain', 13, UPLOAD_ERR_OK);
        // libmagic は環境変数 MAGIC の magic のデータベースを読む。読めないデータベースで finfo の初期化を失敗させる
        $magic = getenv('MAGIC');
        putenv('MAGIC=' . $path . '.missing-magic');

        try {
            $file->detectedMime();
            self::fail('TempFileException was not thrown for finfo that cannot be initialized');
        } catch (TempFileException $exception) {
            self::assertSame('failed to initialize finfo to detect the MIME type of an uploaded file', $exception->getMessage());
            self::assertInstanceOf(Exception::class, $exception->getPrevious());
        } finally {
            putenv($magic === false ? 'MAGIC' : "MAGIC={$magic}");
        }

        self::assertSame('text/plain', $file->detectedMime());
    }

    public function testTempFileStoreDefaultRequiresWordPressUploadDir(): void
    {
        Functions\when('wp_upload_dir')->justReturn(['error' => 'failed']);

        $this->expectException(TempFileException::class);

        TempFileStore::default();
    }

    public function testUploadedFileRequiresRealUploadedFile(): void
    {
        Functions\when('TMSWP\\Core\\Form\\is_uploaded_file')->justReturn(false);

        $path = $this->createTempDirectory('uploaded-file') . '/local.txt';
        file_put_contents($path, 'local');
        $file = new UploadedFile($path, 'local.txt', 'text/plain', 5, UPLOAD_ERR_OK);

        self::assertFalse($file->isValid());

        $this->expectException(TempFileException::class);

        (new TempFileStore($this->createTempDirectory('uploaded-store')))->store($file);
    }

    public function testStoreSchedulesTheGarbageCollectionOnlyWhenItIsNotScheduledYet(): void
    {
        Functions\when('TMSWP\\Core\\Form\\is_uploaded_file')->alias(static fn(string $path): bool => is_file($path));
        Functions\when('sanitize_file_name')->returnArg();
        Functions\when('TMSWP\\Core\\Form\\move_uploaded_file')->alias(static fn(string $from, string $to): bool => rename($from, $to));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\expect('wp_next_scheduled')->twice()->with(TempFileGc::HOOK)->andReturn(false, 1_900_000_000);
        Functions\expect('wp_schedule_event')->once()->with(Mockery::type('int'), 'hourly', TempFileGc::HOOK, [], true)->andReturn(true);
        $uploadDir = $this->createTempDirectory('schedule-upload');
        $store = new TempFileStore($this->createTempDirectory('schedule-store'));

        foreach (['first.txt', 'second.txt'] as $name) {
            file_put_contents($uploadDir . '/' . $name, 'upload');
            $store->store(new UploadedFile($uploadDir . '/' . $name, $name, 'text/plain', 6, UPLOAD_ERR_OK));
        }
    }

    /** @return iterable<string, array{int, non-empty-string}> 削除を失敗させるディレクトリの権限と、例外のメッセージの先頭 */
    public static function undeletableDirectories(): iterable
    {
        yield 'files cannot be unlinked' => [0500, 'failed to delete temporary file: '];
        yield 'directory cannot be listed' => [0300, 'failed to list temporary file directory: '];
    }

    /** @param non-empty-string $message */
    #[DataProvider('undeletableDirectories')]
    public function testFailuresToDeleteTemporaryFilesAreExceptions(int $mode, string $message): void
    {
        $baseDir = $this->createTempDirectory('delete-failure');
        $store = new TempFileStore($baseDir);
        $token = '0123456789abcdef0123456789abcdef';
        $this->createStoredFile($baseDir, $token, 'document.txt', 'text/plain', 'text', time());
        $stored = $store->resolve($token);
        $failures = [
            'TempFileStore::delete()' => static function () use ($store, $token): void {
                $store->delete($token);
            },
            'StoredFile::delete()' => static function () use ($stored): void {
                $stored->delete();
            },
        ];

        foreach ($failures as $case => $delete) {
            $exception = $this->withReadOnlyDirectory($baseDir . DIRECTORY_SEPARATOR . $token, $mode, static function () use ($delete): ?TempFileException {
                try {
                    $delete();
                } catch (TempFileException $exception) {
                    return $exception;
                }

                return null;
            });

            self::assertInstanceOf(TempFileException::class, $exception, $case);
            self::assertStringStartsWith($message, $exception->getMessage(), $case);
        }

        $store->delete($token);

        self::assertFalse($store->exists($token));
        // 既に無いものの削除は何もしない
        $store->delete($token);
        $stored->delete();
    }

    /**
     * gc() が削除の失敗を最後に1つの例外で報告することを確かめる。ただし、削除に失敗するディレクトリが最初に列挙される
     * かどうかはファイルシステムの列挙の順しだいで、最初の失敗で止まる実装でも通る場合がある。失敗の後も続けることは、
     * 列挙の順に依存しないtestGarbageCollectionDeletesExpiredDirectoriesBeforeAndAfterAFailedOneAndThenReportsTheFailure
     * が確かめる。
     */
    #[DataProvider('undeletableDirectories')]
    public function testGarbageCollectionContinuesAfterAFailedDeletionAndReportsTheFailureAtTheEnd(int $mode, string $message): void
    {
        $baseDir = $this->createTempDirectory('gc-failure');
        $store = new TempFileStore($baseDir);
        $locked = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $expired = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $fresh = 'cccccccccccccccccccccccccccccccc';
        $this->createStoredFile($baseDir, $locked, 'locked.txt', 'text/plain', 'locked', time() - 86401);
        $this->createStoredFile($baseDir, $expired, 'expired.txt', 'text/plain', 'expired', time() - 86401);
        $this->createStoredFile($baseDir, $fresh, 'fresh.txt', 'text/plain', 'fresh', time() - 60);
        // トークンの形でない名前のディレクトリは、古くても対象にしない
        mkdir($baseDir . '/not-a-token');
        touch($baseDir . '/not-a-token', time() - 86401);
        file_put_contents($baseDir . '/.htaccess', 'deny');

        $exception = $this->withReadOnlyDirectory($baseDir . DIRECTORY_SEPARATOR . $locked, $mode, static function () use ($store): ?TempFileException {
            try {
                $store->gc(86400);
            } catch (TempFileException $exception) {
                return $exception;
            }

            return null;
        });

        self::assertInstanceOf(TempFileException::class, $exception);
        self::assertStringStartsWith('1 expired temporary file directories could not be deleted (1 deleted): ' . $message, $exception->getMessage());
        self::assertInstanceOf(TempFileException::class, $exception->getPrevious());
        // 削除に失敗したディレクトリは残る(0300 では、名前で消せる本体とメタデータは消えてから一覧の取得で失敗する)
        self::assertDirectoryExists($baseDir . DIRECTORY_SEPARATOR . $locked);
        self::assertFalse($store->exists($expired));
        self::assertTrue($store->exists($fresh));
        self::assertDirectoryExists($baseDir . '/not-a-token');
        self::assertFileExists($baseDir . '/.htaccess');

        // 次の gc で消える(メタデータが消えた場合はディレクトリの更新時刻で期限を判定するため、古い時刻にする)
        self::assertTrue(touch($baseDir . DIRECTORY_SEPARATOR . $locked, time() - 86401));
        self::assertSame(1, $store->gc(86400));
        self::assertDirectoryDoesNotExist($baseDir . DIRECTORY_SEPARATOR . $locked);
    }

    /**
     * gc() は、削除に失敗したディレクトリより前と後に列挙される期限切れのディレクトリをすべて消してから、失敗を最後に
     * 1つの例外で報告する(docs/05 §一時ファイルの削除の失敗。受け入れ基準「削除の失敗があっても残りを続ける」)。
     * 列挙の順はファイルシステムしだいのため、期限切れのディレクトリを5つ作り、名前の順でも実際の列挙の順でも先頭と末尾で
     * ないものを、削除に失敗させるディレクトリに選ぶ。
     *
     * @param non-empty-string $message
     */
    #[DataProvider('undeletableDirectories')]
    public function testGarbageCollectionDeletesExpiredDirectoriesBeforeAndAfterAFailedOneAndThenReportsTheFailure(int $mode, string $message): void
    {
        $baseDir = $this->createTempDirectory('gc-failure-order');
        $store = new TempFileStore($baseDir);
        $expired = [];

        foreach (['1', '4', '7', 'a', 'e'] as $digit) {
            $token = str_repeat($digit, 32);
            $this->createStoredFile($baseDir, $token, "{$digit}.txt", 'text/plain', $digit, time() - 86401);
            $expired[] = $token;
        }

        $fresh = str_repeat('c', 32);
        $this->createStoredFile($baseDir, $fresh, 'fresh.txt', 'text/plain', 'fresh', time() - 60);
        $listed = [];

        foreach (new FilesystemIterator($baseDir, FilesystemIterator::SKIP_DOTS) as $fileInfo) {
            self::assertInstanceOf(SplFileInfo::class, $fileInfo);

            if (\in_array($fileInfo->getFilename(), $expired, true)) {
                $listed[] = $fileInfo->getFilename();
            }
        }

        $edges = [$expired[0], $expired[4], $listed[0] ?? '', $listed[4] ?? ''];
        $candidates = array_values(array_filter($expired, static fn(string $token): bool => !\in_array($token, $edges, true)));
        self::assertCount(5, $listed);
        self::assertNotSame([], $candidates);
        $locked = $candidates[0];

        $exception = $this->withReadOnlyDirectory($baseDir . DIRECTORY_SEPARATOR . $locked, $mode, static function () use ($store): ?TempFileException {
            try {
                $store->gc(86400);
            } catch (TempFileException $exception) {
                return $exception;
            }

            return null;
        });

        self::assertInstanceOf(TempFileException::class, $exception);
        self::assertStringStartsWith('1 expired temporary file directories could not be deleted (4 deleted): ' . $message . $baseDir . DIRECTORY_SEPARATOR . $locked, $exception->getMessage());
        self::assertInstanceOf(TempFileException::class, $exception->getPrevious());
        self::assertDirectoryExists($baseDir . DIRECTORY_SEPARATOR . $locked);

        foreach ($expired as $token) {
            if ($token !== $locked) {
                self::assertDirectoryDoesNotExist($baseDir . DIRECTORY_SEPARATOR . $token, "expired directory {$token} was not deleted");
            }
        }

        self::assertTrue($store->exists($fresh));
    }

    /**
     * ディレクトリの権限を $mode にして $callback を呼び、戻り値を返す。0500 なら中のファイルの unlink() が、0300 なら一覧の取得が
     * 失敗する(0300 でも、名前で指定する本体とメタデータの unlink() は成功する)。
     * 失敗の警告はテストの失敗にしない。
     *
     * @template T
     *
     * @param int $mode chmod() の権限
     * @param Closure(): T $callback
     *
     * @return T
     */
    private function withReadOnlyDirectory(string $dir, int $mode, Closure $callback): mixed
    {
        self::assertTrue(chmod($dir, $mode));
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            return $callback();
        } finally {
            restore_error_handler();
            chmod($dir, 0700);
        }
    }

    private function createStoredFile(string $baseDir, string $token, string $name, string $mime, string $contents, int $created): void
    {
        $dir = $baseDir . DIRECTORY_SEPARATOR . $token;

        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("一時ファイルディレクトリを作成できません: {$dir}");
        }

        file_put_contents($dir . DIRECTORY_SEPARATOR . TempFileStore::FILE_NAME, $contents);
        file_put_contents($dir . '/meta.json', json_encode([
            'created' => $created,
            'mime' => $mime,
            'name' => $name,
            'size' => \strlen($contents),
        ], JSON_THROW_ON_ERROR));
    }
}
