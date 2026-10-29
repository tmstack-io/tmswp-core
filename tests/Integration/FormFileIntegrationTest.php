<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use Closure;
use GdImage;
use TMSWP\Core\Exception\Form\TempFileException;
use TMSWP\Core\Form\ConfirmToken;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormRequest;
use TMSWP\Core\Form\FormStep;
use TMSWP\Core\Form\Rule\FileExtension;
use TMSWP\Core\Form\StoredFile;
use TMSWP\Core\Form\TempFileGc;
use TMSWP\Core\Form\TempFileStore;
use TMSWP\Core\Form\UploadedFile;
use TMSWP\Core\Form\Validator;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Testing\BootsKernelBeforeInit;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UsesTempDirectories;
use WP_Error;

/**
 * フォームのファイル(一時保管・添付ファイル化・完了の後の後始末・WP-Cron の gc)を、実物の WordPress で確かめる。
 *
 * 実際のアップロード(is_uploaded_file() が真になる $_FILES)とフロントのリクエストは PHPUnit の中では作れないため、
 * wp-env の開発用サイトのページ(tests/fixtures/http/form-file.php)へ HTTP でファイルを送って確かめる。開発用サイトの
 * DB はテストの後に戻らないため、ページが応答の後にそのリクエストの jti と新しい gc のイベントを消し、request() が
 * その結果を確かめる。
 */
final class FormFileIntegrationTest extends IntegrationTestCase
{
    use BootsKernelBeforeInit;
    use UsesTempDirectories;

    private const string PAGE = 'http://wordpress/wp-content/plugins/tmswp/tests/fixtures/http/form-file.php';

    private const string CONTENTS = "Quarterly report\n";

    /**
     * テストの前に、既定の一時保管の場所(uploads/tmswp-form-tmp。開発用サイトと同じディレクトリ)があったか。無かったら、
     * テストの後に場所ごと消す。
     */
    private bool $defaultBaseDirExisted = true;

    /** @var list<string> テストが既定の一時保管に作ったトークン */
    private array $defaultStoreTokens = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->defaultBaseDirExisted = is_dir(self::defaultBaseDir());
        $this->defaultStoreTokens = [];
    }

    public function tear_down(): void
    {
        $baseDir = self::defaultBaseDir();

        if (!$this->defaultBaseDirExisted && is_dir($baseDir)) {
            $this->deleteDirectory($baseDir);
        }

        foreach ($this->defaultStoreTokens as $token) {
            if (is_dir($baseDir . DIRECTORY_SEPARATOR . $token)) {
                $this->deleteDirectory($baseDir . DIRECTORY_SEPARATOR . $token);
            }
        }

        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    /**
     * 完了条件: フロントの onComplete から toAttachment() が成功する。確認画面なしは送信の1回のリクエストで保管・
     * 添付ファイル化し、確認画面ありは確認のリクエストで保管し、送信のリクエストで解決して添付ファイル化する。
     */
    public function testOnCompleteOfAFrontEndRequestTurnsTheUploadedFileIntoAnAttachment(): void
    {
        foreach (['without confirmation' => false, 'with confirmation' => true] as $case => $confirm) {
            $query = ['confirm' => $confirm ? '1' : '0'];
            $page = $this->request($query);
            $upload = ['document' => ['filename' => 'quarterly-report.txt', 'contents' => self::CONTENTS]];

            if ($confirm) {
                $page = $this->request($query, [...$this->hiddenValues($page['html']), Form::FIELD_STEP => 'confirm'], $upload);
                self::assertSame('confirm', $page['step'], $case);
                $upload = [];
            }

            $result = $this->request($query, [...$this->hiddenValues($page['html']), Form::FIELD_STEP => 'submit'], $upload);
            $report = $result['report'];

            self::assertSame('complete', $result['step'], $case);
            // フロントのリクエストでは media_handle_sideload() が読み込まれていない
            self::assertFalse($report['mediaFunctionsLoadedBeforeComplete'], $case);
            // 確認画面の有無に関係なく StoredFile が渡る
            self::assertSame(StoredFile::class, $report['receivedType'], $case);
            // 保存名にクライアントのファイル名を使わない
            self::assertSame(TempFileStore::FILE_NAME, $report['storedName'], $case);
            self::assertSame('quarterly-report.txt', $report['clientName'], $case);
            self::assertSame(self::CONTENTS, $report['storedContents'], $case);
            self::assertIsInt($report['attachmentId'], $case);
            self::assertGreaterThan(0, $report['attachmentId'], $case);
            self::assertSame('text/plain', $report['attachmentMime'], $case);
            self::assertSame(self::CONTENTS, $report['attachedContents'], $case);
            self::assertFalse($report['tokenDirectoryExistsAfterAttachment'], $case);
        }

        // 一時保管の場所は、Web から直接取得できない(.htaccess による拒否)
        $response = wp_remote_get('http://wordpress/wp-content/uploads/tmswp-form-tmp/index.html');

        $this->assertNotWPError($response);
        self::assertSame(403, wp_remote_retrieve_response_code($response));
    }

    /** 完了条件: onComplete が消費しなかった一時ファイルは、完了の後に消える(確認画面の有無の両方) */
    public function testTemporaryFileThatOnCompleteDidNotConsumeIsDeletedAfterCompletion(): void
    {
        foreach (['without confirmation' => false, 'with confirmation' => true] as $case => $confirm) {
            $query = ['confirm' => $confirm ? '1' : '0', 'consume' => '0'];
            $page = $this->request($query);
            $upload = ['document' => ['filename' => 'notes.txt', 'contents' => self::CONTENTS]];

            if ($confirm) {
                $page = $this->request($query, [...$this->hiddenValues($page['html']), Form::FIELD_STEP => 'confirm'], $upload);
                $upload = [];
            }

            $result = $this->request($query, [...$this->hiddenValues($page['html']), Form::FIELD_STEP => 'submit'], $upload);

            self::assertSame('complete', $result['step'], $case);
            self::assertSame(StoredFile::class, $result['report']['receivedType'], $case);
            // onComplete の中では保管されていた
            self::assertSame(self::CONTENTS, $result['report']['storedContents'], $case);
            self::assertFalse($result['report']['tokenDirectoryExistsAfterComplete'], $case);
        }
    }

    /** store → resolve → moveTo()/toAttachment() を、実際のファイルとメディアライブラリで確かめる */
    public function testResolvedFileCanBeMovedOrAttachedAndItsTokenDirectoryIsRemoved(): void
    {
        $baseDir = $this->createTempDirectory('integration-file-store');
        $store = new TempFileStore($baseDir);
        $moved = $store->resolve($this->createStoredFile($baseDir, 'contract.txt'));
        $target = $moved->moveTo($this->createTempDirectory('integration-file-target'));

        self::assertSame('contract.txt', basename($target));
        self::assertSame(self::CONTENTS, file_get_contents($target));
        self::assertFalse($store->exists($moved->token));
        self::assertDirectoryDoesNotExist(\dirname($moved->path));

        $attached = $store->resolve($this->createStoredFile($baseDir, 'invoice.txt'));
        $attachmentId = $attached->toAttachment();
        $attachedFile = get_attached_file($attachmentId);

        try {
            self::assertIsString($attachedFile);
            self::assertSame(self::CONTENTS, file_get_contents($attachedFile));
            self::assertSame('text/plain', get_post_mime_type($attachmentId));
            self::assertDirectoryDoesNotExist(\dirname($attached->path));
        } finally {
            wp_delete_attachment($attachmentId, true);
        }
    }

    /**
     * 完了の後(一時ファイルを消した後)の確認トークンの再送は、ファイルの消失ではなく再送として拒否する(実際の
     * データベースの jti で確かめる)。
     */
    public function testResubmissionAfterTheCompletionDeletedTheFileIsRejectedAsAResubmission(): void
    {
        $form = $this->formFactory();
        $baseDir = $this->createTempDirectory('integration-resubmit-store');
        $store = new TempFileStore($baseDir);
        $token = $this->createStoredFile($baseDir, 'resume.txt');
        $definition = FormDefinition::create('entry')
            ->file('resume', 'Resume')
            ->confirm()
            ->tempFileStore($store);
        $nonce = wp_create_nonce('tmswp_form_entry');
        $submit = new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => $nonce,
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => ConfirmToken::issue('entry', ['resume' => ['__tmsfile' => $token]]),
        ]);

        self::assertSame(FormStep::Complete, $form($definition)->handle($submit)->step);
        self::assertFalse($store->exists($token));

        $resubmitted = $form($definition)->handle($submit);

        self::assertSame(FormStep::Input, $resubmitted->step);
        self::assertSame(['This form has already been submitted.'], $resubmitted->formErrors);
    }

    /**
     * 完了条件: WP-Cron で gc() が定期的に実行される(保管でスケジュールする)。fixture のテーマとプラグインの2つの Kernel が
     * 登録済みのため、片方の無効化ではイベントを解除しない(最後の1つの無効化での解除はユニットテストで確かめる)。
     */
    public function testGarbageCollectionIsScheduledHourlyRunsOnTheCronHookAndIsKeptWhileAnotherKernelIsRegistered(): void
    {
        wp_clear_scheduled_hook(TempFileGc::HOOK);
        TempFileGc::schedule();

        self::assertIsInt(wp_next_scheduled(TempFileGc::HOOK));
        self::assertSame(TempFileGc::RECURRENCE, wp_get_schedule(TempFileGc::HOOK));

        // スケジュール済みなら、2つ目のイベントを作らない
        TempFileGc::schedule();

        self::assertCount(1, array_filter(_get_cron_array(), static fn(array $hooks): bool => \array_key_exists(TempFileGc::HOOK, $hooks)));

        // WP-Cron のフックが、既定の一時保管の期限切れのディレクトリだけを消す
        $default = TempFileStore::default();
        $defaultBaseDir = self::defaultBaseDir();

        if (!is_dir($defaultBaseDir)) {
            self::assertTrue(mkdir($defaultBaseDir, 0700, true));
        }

        $expired = $this->createStoredFile($defaultBaseDir, 'expired.txt', time() - 86401);
        $fresh = $this->createStoredFile($defaultBaseDir, 'fresh.txt', time() - 60);
        $this->defaultStoreTokens = [$expired, $fresh];

        do_action(TempFileGc::HOOK);

        self::assertFalse($default->exists($expired));
        self::assertTrue($default->exists($fresh));

        // プラグインを無効にしても、テーマのフォームの一時ファイルの掃除を続ける(逆も同じ)
        do_action('tmswp/deactivated', self::pluginKernel());

        self::assertIsInt(wp_next_scheduled(TempFileGc::HOOK));

        do_action('tmswp/deactivated', self::themeKernel());

        self::assertIsInt(wp_next_scheduled(TempFileGc::HOOK));
    }

    /** イベントのスケジュールの失敗は TempFileException になる(TempFileStore::store() は保管の前にこれを呼ぶ) */
    public function testFailureToScheduleTheGarbageCollectionIsAnException(): void
    {
        wp_clear_scheduled_hook(TempFileGc::HOOK);
        $reject = static fn(): WP_Error => new WP_Error('rejected', 'scheduling is rejected');
        add_filter('pre_schedule_event', $reject);

        try {
            TempFileGc::schedule();
            self::fail('TempFileException was not thrown');
        } catch (TempFileException $exception) {
            self::assertStringContainsString('scheduling is rejected', $exception->getMessage());
        } finally {
            remove_filter('pre_schedule_event', $reject);
        }

        self::assertFalse(wp_next_scheduled(TempFileGc::HOOK));
    }

    /**
     * FileExtensionは、アップロードしたファイルの実体を、WordPressのwp_check_filetype_and_ext()で確かめる。拡張子を.jpgに
     * 偽装したPHPやテキストは、クライアントが画像のMIMEを申告しても違反になり、実際のJPEGは、クライアントがPHPのMIMEを
     * 申告しても合格する。WordPressには一時ファイルのパスとクライアントのファイル名を渡す。
     *
     * @dataProvider uploadedContents
     */
    public function testFileExtensionChecksTheContentOfAnUploadedFileWithWordPress(string $kind, string $clientMime, bool $valid): void
    {
        $path = $this->createTempDirectory('file-extension') . '/upload';

        if ($kind === 'jpeg') {
            $image = imagecreatetruecolor(4, 4);
            self::assertInstanceOf(GdImage::class, $image);
            self::assertTrue(imagejpeg($image, $path));
        } else {
            self::assertNotFalse(file_put_contents($path, $kind === 'php' ? "<?php echo 'spoofed';\n" : self::CONTENTS));
        }

        $size = filesize($path);
        self::assertIsInt($size);
        $checks = [];
        $record = static function (array $check, string $file, string $filename) use (&$checks): array {
            $checks[] = ['file' => $file, 'filename' => $filename, 'ext' => $check['ext']];

            return $check;
        };
        add_filter('wp_check_filetype_and_ext', $record, 10, 3);

        try {
            $errors = (new Validator())->validate(
                FormDefinition::create('upload')->file('photo', 'Photo', [new FileExtension(['jpg'])]),
                ['photo' => new UploadedFile($path, 'photo.jpg', $clientMime, $size, UPLOAD_ERR_OK)],
            );
        } finally {
            remove_filter('wp_check_filetype_and_ext', $record, 10);
        }

        self::assertSame($valid ? [] : ['photo' => ['Please upload Photo as one of these file types: jpg.']], $errors);
        self::assertSame([['file' => $path, 'filename' => 'photo.jpg', 'ext' => $valid ? 'jpg' : false]], $checks);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function uploadedContents(): iterable
    {
        yield 'JPEG declared as PHP' => ['jpeg', 'text/x-php', true];
        yield 'PHP disguised as JPEG' => ['php', 'image/jpeg', false];
        yield 'text disguised as JPEG' => ['text', 'image/jpeg', false];
    }

    /** 既定の一時保管の場所(TempFileStore::default() と同じ) */
    private static function defaultBaseDir(): string
    {
        return wp_upload_dir()['basedir'] . '/tmswp-form-tmp';
    }

    /**
     * 開発用サイトのフォームのページへ GET(POST 値が無いとき)か multipart の POST を送り、ページが返す JSON を返す。
     *
     * @param array<string, string> $query
     * @param array<string, string> $post
     * @param array<string, array{filename: string, contents: string}> $files
     *
     * @return array{step: string, html: string, report: array<mixed>}
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

        if (!\is_array($response) || !\is_string($response['step'] ?? null) || !\is_string($response['html'] ?? null) || !\is_array($response['report'] ?? null) || !\is_array($response['cleanup'] ?? null)) {
            self::fail("the form page did not return the expected JSON: {$body}");
        }

        // 開発用サイトの DB に、このリクエストが作った jti と gc のイベントを残さない(ページがその場で消した結果)
        $cleanup = $response['cleanup'];
        self::assertSame(['jtiClaimedBeforeCleanup', 'jtiClaimedAfterCleanup', 'gcEventCreated', 'gcEventLeft'], array_keys($cleanup));
        $completed = $response['step'] === 'complete';
        self::assertSame($completed ? true : null, $cleanup['jtiClaimedBeforeCleanup'], 'the jti claimed on the development site');
        self::assertSame($completed ? false : null, $cleanup['jtiClaimedAfterCleanup'], 'the jti left on the development site');
        self::assertFalse($cleanup['gcEventLeft'], 'the gc event left on the development site');

        return ['step' => $response['step'], 'html' => $response['html'], 'report' => $response['report']];
    }

    /** @return array<string, string> open() が出力した hidden の名前と値 */
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
     * 保管済みのファイルを、TempFileStore::store() と同じ形(トークンのディレクトリに FILE_NAME とメタデータ)で作る。
     * store() は実際のアップロードのファイルしか受け付けないため、PHPUnit の中では直接作る。
     *
     * @return string トークン
     */
    private function createStoredFile(string $baseDir, string $name, ?int $created = null): string
    {
        $token = bin2hex(random_bytes(16));
        $dir = $baseDir . DIRECTORY_SEPARATOR . $token;
        self::assertTrue(mkdir($dir, 0700));
        self::assertNotFalse(file_put_contents($dir . DIRECTORY_SEPARATOR . TempFileStore::FILE_NAME, self::CONTENTS));
        self::assertNotFalse(file_put_contents($dir . DIRECTORY_SEPARATOR . TempFileStore::META_NAME, json_encode([
            'name' => $name,
            'mime' => 'text/plain',
            'size' => \strlen(self::CONTENTS),
            'created' => $created ?? time(),
        ], JSON_THROW_ON_ERROR)));

        return $token;
    }

    /**
     * 一時ディレクトリをルートとするプラグインの Kernel を boot し、そのルートの中のファイルで `new Form()` を書いた
     * フォームを作る関数を返す(Form は作ったファイルで Kernel を決める)。
     *
     * @return Closure(FormDefinition): Form
     */
    private function formFactory(): Closure
    {
        $root = $this->createTempDirectory('integration-file-form');
        self::assertTrue(mkdir($root . '/app'));
        self::assertNotFalse(file_put_contents($root . '/plugin.php', "<?php\n"));
        $logger = new RecordingLogger();
        $config = KernelConfig::plugin(
            $root . '/plugin.php',
            $root . '/app',
            'TmswpIntegrationFileForm' . uniqid(),
            false,
            logChannels: static fn(): array => ['integration' => new LogChannel($logger)],
        );
        (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
        $this->bootBeforeInit($config);
        self::assertNotFalse(file_put_contents($root . '/form-factory.php', <<<'PHP'
<?php

declare(strict_types=1);

return static fn(\TMSWP\Core\Form\FormDefinition $definition): \TMSWP\Core\Form\Form => new \TMSWP\Core\Form\Form($definition);
PHP));
        $factory = require $root . '/form-factory.php';
        self::assertInstanceOf(Closure::class, $factory);

        return static function (FormDefinition $definition) use ($factory): Form {
            $form = $factory($definition);
            self::assertInstanceOf(Form::class, $form);

            return $form;
        };
    }
}
