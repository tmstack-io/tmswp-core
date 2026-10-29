<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Core\Exception\Form\TempFileException;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\StoredFile;
use TMSWP\Core\Form\TempFileGc;
use TMSWP\Core\Form\TempFileStore;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\UsesTempDirectories;

/**
 * フォームの一時ファイル(保管の順序・onCompleteの例外の後の再送・保管の失敗・gcのイベントのスケジュール・移動先の
 * パス)を、実物のWordPressで確かめる(docs/05-form.mdの受け入れ基準のファイルの項目)。
 *
 * 実際のアップロード(is_uploaded_file() が真になる $_FILES)はPHPUnitの中では作れないため、FormFileIntegrationTestと
 * 同じく、wp-envの開発用サイトのページ(tests/fixtures/http/form-file.php)へHTTPでファイルを送る。ログとdebugの
 * 判定が要るテストは、ページがそのリクエストの中だけのKernelをbootするモード(kernel=production|debug)を使う。
 * 開発用サイトのDBに残るもの(jti・gcのイベント)はページがその場で消し、その結果をrequest() が確かめる。
 */
final class FormTempFileIntegrationTest extends IntegrationTestCase
{
    use UsesTempDirectories;

    private const string PAGE = 'http://wordpress/wp-content/plugins/tmswp/tests/fixtures/http/form-file.php';

    private const string CONTENTS = "Quarterly report\n";

    private const string GENERIC_FAILURE = 'The form could not be sent. Please try again.';

    /**
     * テストの前に、既定の一時保管の場所(uploads/tmswp-form-tmp。開発用サイトと同じディレクトリ)があったか。無かったら、
     * テストの後に場所ごと消す。
     */
    private bool $defaultBaseDirExisted = true;

    /** @var list<string> ページへのリクエストが既定の一時保管に残したトークン(テストの後に消す) */
    private array $leftTokens = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->defaultBaseDirExisted = is_dir(self::defaultBaseDir());
        $this->leftTokens = [];
    }

    public function tear_down(): void
    {
        $baseDir = self::defaultBaseDir();

        foreach ($this->leftTokens as $token) {
            if (is_dir($baseDir . DIRECTORY_SEPARATOR . $token)) {
                TempFileStore::deleteDirectory($baseDir . DIRECTORY_SEPARATOR . $token);
            }
        }

        if (!$this->defaultBaseDirExisted && is_dir($baseDir)) {
            $this->deleteDirectory($baseDir);
        }

        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    /**
     * docs/05の「POST値からファイルパスを構築するコードが存在しない」を、実物のwp_unique_filename() で確かめる。
     * メタデータのクライアントのファイル名やmoveTo() に渡すファイル名が`../`を含んでも、移動先は指定のディレクトリの
     * 直下に収まり、その外にはファイルを作らない。
     */
    public function testMoveToKeepsTheTargetInsideTheGivenDirectoryEvenWhenTheNameContainsParentDirectorySegments(): void
    {
        $root = $this->createTempDirectory('integration-move-to');
        $baseDir = $root . '/store';
        $directory = $root . '/outer/inner/target';
        self::assertTrue(mkdir($baseDir));
        self::assertTrue(mkdir($directory, 0700, true));
        $store = new TempFileStore($baseDir);
        $cases = [
            'client name with one parent segment' => ['../escaped.txt', null],
            'client name with two parent segments' => ['../../escaped.txt', null],
            'filename argument with a parent segment' => ['report.txt', '../escaped.txt'],
        ];

        foreach ($cases as $case => [$clientName, $filename]) {
            $file = $store->resolve($this->createStoredFile($baseDir, $clientName));
            $target = $file->moveTo($directory, $filename);

            self::assertSame(realpath($directory), realpath(\dirname($target)), $case);
            self::assertStringNotContainsString('/', basename($target), $case);
            self::assertSame(self::CONTENTS, file_get_contents($target), $case);
            self::assertFileDoesNotExist($root . '/outer/inner/escaped.txt', $case);
            self::assertFileDoesNotExist($root . '/outer/escaped.txt', $case);
        }
    }

    /**
     * docs/05の「確認画面なしはjtiのclaimの後に保管し、再送のファイルは保管しない」を、実際のアップロードと
     * 実際のデータベースのjtiで確かめる。完了した送信と同じ送信トークンとファイルの再送は再送として拒否し、一時保管に
     * ファイルを増やさず、gcのイベントもスケジュールしない。
     */
    public function testResubmissionOfTheSameSubmitTokenWithAFileIsRejectedWithoutStoringTheFile(): void
    {
        $query = ['confirm' => '0', 'consume' => '0', 'kernel' => 'production'];
        $page = $this->request($query);
        $post = [...$this->hiddenValues($page['html']), Form::FIELD_STEP => 'submit'];
        $upload = ['document' => ['filename' => 'application.txt', 'contents' => self::CONTENTS]];

        $completed = $this->request([...$query, 'jti' => 'keep'], $post, $upload);

        self::assertSame('complete', $completed['step']);
        self::assertSame(StoredFile::class, $completed['report']['receivedType'] ?? null);
        // jti=keepのため、ページはclaimしたjtiを手放していない
        self::assertNull($completed['cleanup']['jtiClaimedBeforeCleanup']);

        $resubmitted = $this->request([...$query, 'jti' => 'release'], $post, $upload);

        self::assertSame('input', $resubmitted['step']);
        self::assertSame(['This form has already been submitted.'], $resubmitted['formErrors']);
        self::assertArrayNotHasKey('receivedType', $resubmitted['report']);
        // 再送のファイルは保管しない
        self::assertSame(self::tokens($resubmitted['report'], 'storedTokensBeforeHandle'), self::tokens($resubmitted['report'], 'storedTokensAfterHandle'));
        self::assertFalse($resubmitted['cleanup']['gcEventCreated']);
        self::assertContains(['notice', 'tmswp.form: a resubmission of form {form} was rejected', null], self::logs($resubmitted['report']));
        // 最初の送信がclaimしたjtiは、再送の時点でclaim済みだった(ページが手放した)
        self::assertTrue($resubmitted['cleanup']['jtiClaimedBeforeCleanup']);
        self::assertFalse($resubmitted['cleanup']['jtiClaimedAfterCleanup']);
    }

    /**
     * docs/05の「onCompleteの例外の後は消えずに再送で使える」を、実際のアップロードと実際のデータベースのjtiで
     * 確かめる。確認画面で保管したファイルは、onCompleteが例外を投げた後も残り(本番は汎用のエラーとerrorの記録)、
     * 同じ確認トークンの再送で、同じトークンのStoredFileがonCompleteに渡る。
     */
    public function testOnCompleteExceptionKeepsTheTemporaryFileAndTheResubmissionPassesItToOnComplete(): void
    {
        $query = ['confirm' => '1', 'kernel' => 'production'];
        $page = $this->request($query);
        $confirmed = $this->request(
            $query,
            [...$this->hiddenValues($page['html']), Form::FIELD_STEP => 'confirm'],
            ['document' => ['filename' => 'estimate.txt', 'contents' => self::CONTENTS]],
        );

        self::assertSame('confirm', $confirmed['step']);
        $stored = array_values(array_diff(self::tokens($confirmed['report'], 'storedTokensAfterHandle'), self::tokens($confirmed['report'], 'storedTokensBeforeHandle')));
        self::assertCount(1, $stored);
        $post = [...$this->hiddenValues($confirmed['html']), Form::FIELD_STEP => 'submit'];

        $failed = $this->request([...$query, 'fail' => 'complete'], $post);

        self::assertSame('input', $failed['step']);
        self::assertSame([self::GENERIC_FAILURE], $failed['formErrors']);
        self::assertSame(StoredFile::class, $failed['report']['receivedType'] ?? null);
        self::assertSame($stored[0], basename(self::reportString($failed['report'], 'tokenDirectory')));
        // 例外の後も一時ファイルを消さない
        self::assertTrue($failed['report']['tokenDirectoryExistsAfterComplete'] ?? null);
        self::assertContains($stored[0], self::tokens($failed['report'], 'storedTokensAfterHandle'));
        self::assertContains(['error', 'tmswp.form: onComplete of form {form} threw an exception', 'RuntimeException'], self::logs($failed['report']));

        // jti=release: 再送が拒否されても、開発用サイトにjtiを残さない
        $resubmitted = $this->request([...$query, 'jti' => 'release'], $post);

        self::assertSame('complete', $resubmitted['step']);
        self::assertSame(StoredFile::class, $resubmitted['report']['receivedType'] ?? null);
        self::assertSame($stored[0], basename(self::reportString($resubmitted['report'], 'tokenDirectory')));
        self::assertSame('estimate.txt', $resubmitted['report']['clientName'] ?? null);
        self::assertSame(self::CONTENTS, $resubmitted['report']['attachedContents'] ?? null);
        self::assertFalse($resubmitted['report']['tokenDirectoryExistsAfterAttachment'] ?? null);
        self::assertTrue($resubmitted['cleanup']['jtiClaimedBeforeCleanup']);
    }

    /**
     * docs/05の「一時ファイルの保管の失敗が §handle() のとおりに扱われる」を、実際のアップロードと実際のデータベースの
     * jtiで確かめる。確認画面なしのフォームで一時保管の場所を作れないと、本番は汎用のエラーで入力画面に戻してerrorで
     * 記録し、debugはTempFileExceptionを投げる。どちらもjtiを手放すため、同じ送信トークンで再送できる。
     */
    public function testFailureToStoreTheUploadedFileFollowsTheErrorPolicyAndReleasesTheJti(): void
    {
        foreach (['production', 'debug'] as $mode) {
            $query = ['confirm' => '0', 'consume' => '0', 'kernel' => $mode];
            $page = $this->request($query);
            $post = [...$this->hiddenValues($page['html']), Form::FIELD_STEP => 'submit'];
            $upload = ['document' => ['filename' => 'invoice.txt', 'contents' => self::CONTENTS]];

            $failed = $this->request([...$query, 'fail' => 'store'], $post, $upload);

            if ($mode === 'production') {
                self::assertSame('input', $failed['step'], $mode);
                self::assertSame([self::GENERIC_FAILURE], $failed['formErrors'], $mode);
                self::assertContains(['error', 'tmswp.form: failed to store the uploaded files of form {form}', TempFileException::class], self::logs($failed['report']), $mode);
            } else {
                self::assertSame('exception', $failed['step'], $mode);
                self::assertSame(TempFileException::class, self::reportString($failed['report'], 'thrownClass'), $mode);
                self::assertStringContainsString('failed to create temporary file base directory', self::reportString($failed['report'], 'thrownMessage'), $mode);
            }

            self::assertArrayNotHasKey('receivedType', $failed['report'], $mode);

            // 手放したjtiはclaimされていないため、同じ送信トークンの再送を受け付ける(jti=release: 拒否されても、開発用サイトにjtiを残さない)
            $resubmitted = $this->request([...$query, 'jti' => 'release'], $post, $upload);

            self::assertSame('complete', $resubmitted['step'], $mode);
            self::assertSame(StoredFile::class, $resubmitted['report']['receivedType'] ?? null, $mode);
            self::assertTrue($resubmitted['cleanup']['jtiClaimedBeforeCleanup'], $mode);
        }
    }

    /**
     * docs/05の「一時ファイルの保管と削除の失敗が、§一時ファイルの削除の失敗 と §handle() のとおりに扱われる(完了の後の
     * 削除の失敗はdebugでも送信を失敗にしない)」の後半を、実際のアップロードと実物のファイルシステムで確かめる。
     * onCompleteがファイルを消費せず、トークンのディレクトリにサブディレクトリを作るため、完了の後の削除が失敗する。
     * 本番でもdebugでも送信は完了し、例外にならず、Kernelのロガーにerrorで記録され、トークンのディレクトリは
     * (gc() が後で消すため)残る。
     */
    public function testFailureToDeleteTheUnconsumedFileAfterCompletionDoesNotFailTheSubmissionEvenInDebug(): void
    {
        foreach (['production', 'debug'] as $mode) {
            $query = ['confirm' => '0', 'consume' => '0', 'kernel' => $mode];
            $page = $this->request($query);
            $post = [...$this->hiddenValues($page['html']), Form::FIELD_STEP => 'submit'];
            $upload = ['document' => ['filename' => 'invoice.txt', 'contents' => self::CONTENTS]];

            $completed = $this->request([...$query, 'fail' => 'delete'], $post, $upload);

            self::assertSame('complete', $completed['step'], $mode);
            self::assertSame([], $completed['formErrors'], $mode);
            self::assertArrayNotHasKey('thrown', $completed['report'], $mode);
            self::assertTrue($completed['report']['blockerCreated'] ?? null, $mode);
            self::assertSame(self::CONTENTS, $completed['report']['storedContents'] ?? null, $mode);
            self::assertSame([['error', 'tmswp.form: failed to delete a temporary file of form {form} after completion', TempFileException::class]], self::logs($completed['report']), $mode);
            self::assertTrue($completed['report']['tokenDirectoryExistsAfterComplete'] ?? null, $mode);
            self::assertTrue($completed['cleanup']['jtiClaimedBeforeCleanup'], $mode);
        }
    }

    /**
     * docs/05の「WP-CronのTempFileGc::HOOKの毎時のイベントが保管でスケジュールされ」を、実際のアップロードの保管
     * (TempFileStore::store())で確かめる。イベントの無い状態からの保管で、毎時のイベントがスケジュールされる。
     */
    public function testStoringARealUploadSchedulesTheHourlyGarbageCollection(): void
    {
        $query = ['confirm' => '0', 'consume' => '0'];
        $page = $this->request($query);
        $result = $this->request(
            $query,
            [...$this->hiddenValues($page['html']), Form::FIELD_STEP => 'submit'],
            ['document' => ['filename' => 'schedule.txt', 'contents' => self::CONTENTS]],
        );

        self::assertSame('complete', $result['step']);
        // 前提: 開発用サイトにはイベントが無い(ページは自身が作ったイベントをその場で消す)
        self::assertFalse($result['report']['gcEventExistedBeforeHandle'] ?? null, 'the gc event already existed on the development site');
        self::assertSame(TempFileGc::RECURRENCE, $result['report']['gcScheduleAfterHandle'] ?? null);
        self::assertTrue($result['cleanup']['gcEventCreated']);
    }

    /** 既定の一時保管の場所(TempFileStore::default() と同じ) */
    private static function defaultBaseDir(): string
    {
        return wp_upload_dir()['basedir'] . '/tmswp-form-tmp';
    }

    /**
     * 開発用サイトのフォームのページへGET(POST値が無いとき)かmultipartのPOSTを送り、ページが返すJSONを返す。
     * 既定の一時保管にリクエストが残したトークンは、テストの後に消すよう控える。
     *
     * @param array<string, string> $query
     * @param array<string, string> $post
     * @param array<string, array{filename: string, contents: string}> $files
     *
     * @return array{step: string, html: string, formErrors: list<string>, report: array<mixed>, cleanup: array{jtiClaimedBeforeCleanup: ?bool, jtiClaimedAfterCleanup: ?bool, gcEventCreated: bool, gcEventLeft: bool}}
     */
    private function request(array $query, array $post = [], array $files = []): array
    {
        $url = self::PAGE . '?' . http_build_query($query);
        $context = null;

        if ($post !== []) {
            $boundary = bin2hex(random_bytes(12));
            $body = '';

            foreach ($post as $name => $value) {
                $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
            }

            foreach ($files as $name => $file) {
                $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"; filename=\"{$file['filename']}\"\r\nContent-Type: text/plain\r\n\r\n{$file['contents']}\r\n";
            }

            $body .= "--{$boundary}--\r\n";
            $context = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => "Content-Type: multipart/form-data; boundary={$boundary}",
                'content' => $body,
                'ignore_errors' => true,
            ]]);
        }

        $body = file_get_contents($url, false, $context);

        self::assertIsString($body);

        $response = json_decode($body, true);

        if (!\is_array($response) || !\is_string($response['step'] ?? null) || !\is_string($response['html'] ?? null) || !\is_array($response['report'] ?? null) || !\is_array($response['cleanup'] ?? null) || !\is_array($response['formErrors'] ?? null)) {
            self::fail("the form page did not return the expected JSON: {$body}");
        }

        $report = $response['report'];
        $this->leftTokens = [...$this->leftTokens, ...array_diff(self::tokens($report, 'storedTokensAfterHandle'), self::tokens($report, 'storedTokensBeforeHandle'))];

        $formErrors = [];

        foreach ($response['formErrors'] as $error) {
            self::assertIsString($error);
            $formErrors[] = $error;
        }

        // 開発用サイトのDBに、このリクエストが作ったjtiとgcのイベントを残さない(ページがその場で消した結果)
        $cleanup = $response['cleanup'];
        $jtiBefore = $cleanup['jtiClaimedBeforeCleanup'] ?? null;
        $jtiAfter = $cleanup['jtiClaimedAfterCleanup'] ?? null;
        $gcCreated = $cleanup['gcEventCreated'] ?? null;
        self::assertTrue($jtiBefore === null || \is_bool($jtiBefore));
        self::assertTrue($jtiAfter === null || $jtiAfter === false, 'the jti left on the development site');
        self::assertIsBool($gcCreated);
        self::assertFalse($cleanup['gcEventLeft'] ?? null, 'the gc event left on the development site');
        self::assertNotTrue($cleanup['tokenDirectoryLeft'] ?? null, 'the token directory left on the development site');

        if (isset($report['thrown']) && \is_array($report['thrown'])) {
            $report['thrownClass'] = $report['thrown']['class'] ?? null;
            $report['thrownMessage'] = $report['thrown']['message'] ?? null;
        }

        return [
            'step' => $response['step'],
            'html' => $response['html'],
            'formErrors' => $formErrors,
            'report' => $report,
            'cleanup' => ['jtiClaimedBeforeCleanup' => $jtiBefore, 'jtiClaimedAfterCleanup' => $jtiAfter, 'gcEventCreated' => $gcCreated, 'gcEventLeft' => false],
        ];
    }

    /**
     * reportの、既定の一時保管にあったトークンの一覧。
     *
     * @param array<mixed> $report
     *
     * @return list<string>
     */
    private static function tokens(array $report, string $key): array
    {
        $tokens = $report[$key] ?? null;
        self::assertIsArray($tokens, $key);
        $list = [];

        foreach ($tokens as $token) {
            self::assertIsString($token);
            $list[] = $token;
        }

        return $list;
    }

    /**
     * reportの、ページのKernelのロガーへの記録を、レベル・メッセージ・文脈の例外のクラスの組にして返す。
     *
     * @param array<mixed> $report
     *
     * @return list<array{string, string, ?string}>
     */
    private static function logs(array $report): array
    {
        $records = $report['logs'] ?? null;
        self::assertIsArray($records);
        $logs = [];

        foreach ($records as $record) {
            self::assertIsArray($record);
            self::assertIsString($record['level'] ?? null);
            self::assertIsString($record['message'] ?? null);
            $context = $record['context'] ?? null;
            self::assertIsArray($context);
            $exceptionClass = $context['exceptionClass'] ?? null;
            self::assertTrue($exceptionClass === null || \is_string($exceptionClass));
            $logs[] = [$record['level'], $record['message'], $exceptionClass];
        }

        return $logs;
    }

    /** @param array<mixed> $report */
    private static function reportString(array $report, string $key): string
    {
        $value = $report[$key] ?? null;
        self::assertIsString($value, $key);

        return $value;
    }

    /** @return array<string, string> open() が出力したhiddenの名前と値 */
    private function hiddenValues(string $html): array
    {
        preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $html, $matches, PREG_SET_ORDER);
        $values = [];

        foreach ($matches as $match) {
            $values[$match[1]] = html_entity_decode($match[2], ENT_QUOTES);
        }

        self::assertArrayHasKey(Form::FIELD_NONCE, $values);

        return $values;
    }

    /**
     * 保管済みのファイルを、TempFileStore::store() と同じ形(トークンのディレクトリにFILE_NAMEとメタデータ)で作る。
     * store() は実際のアップロードのファイルしか受け付けないため、PHPUnitの中では直接作る。
     *
     * @return string トークン
     */
    private function createStoredFile(string $baseDir, string $name): string
    {
        $token = bin2hex(random_bytes(16));
        $dir = $baseDir . DIRECTORY_SEPARATOR . $token;
        self::assertTrue(mkdir($dir, 0700));
        self::assertNotFalse(file_put_contents($dir . DIRECTORY_SEPARATOR . TempFileStore::FILE_NAME, self::CONTENTS));
        self::assertNotFalse(file_put_contents($dir . DIRECTORY_SEPARATOR . TempFileStore::META_NAME, json_encode([
            'name' => $name,
            'mime' => 'text/plain',
            'size' => \strlen(self::CONTENTS),
            'created' => time(),
        ], JSON_THROW_ON_ERROR)));

        return $token;
    }
}
