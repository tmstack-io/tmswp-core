<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form;

use ArrayObject;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Closure;
use LogicException as PhpLogicException;
use Mockery;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException as PhpRuntimeException;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Exception\Form\TempFileException;
use TMSWP\Core\Exception\Form\UnknownFieldException;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Core\Form\Composite\CompositeField;
use TMSWP\Core\Form\ConfirmToken;
use TMSWP\Core\Form\Field;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormRequest;
use TMSWP\Core\Form\FormResult;
use TMSWP\Core\Form\FormStep;
use TMSWP\Core\Form\JtiClaim;
use TMSWP\Core\Form\JtiStore;
use TMSWP\Core\Form\Rule\FileExtension;
use TMSWP\Core\Form\Rule\MaxFileSize;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\Form\SpamGuardInterface;
use TMSWP\Core\Form\StoredFile;
use TMSWP\Core\Form\SubmitToken;
use TMSWP\Core\Form\TempFileGc;
use TMSWP\Core\Form\TempFileStore;
use TMSWP\Core\Form\UploadedFile;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;
use WP;

final class FormRuntimeTest extends UnitTestCase
{
    private const string EXPIRED_CONFIRMATION = 'The confirmation screen has expired. Please review your entries and continue again.';

    private const string SEND_FAILED = 'The form could not be sent. Please try again.';

    /** WordPress 7.0のWP::$public_query_varsの既定値(投稿タイプ等を登録する前) */
    private const array WORDPRESS_PUBLIC_QUERY_VARS = ['m', 'p', 'posts', 'w', 'cat', 'withcomments', 'withoutcomments', 's', 'search', 'exact', 'sentence', 'calendar', 'page', 'paged', 'more', 'tb', 'pb', 'author', 'order', 'orderby', 'year', 'monthnum', 'day', 'hour', 'minute', 'second', 'name', 'category_name', 'tag', 'feed', 'author_name', 'pagename', 'page_id', 'error', 'attachment', 'attachment_id', 'subpost', 'subpost_id', 'preview', 'robots', 'favicon', 'taxonomy', 'term', 'cpage', 'post_type', 'embed'];

    /** @var list<array<string, mixed>> */
    private array $completed = [];

    private WpdbTestDouble $wpdb;

    /** onComplete に例外を投げさせるか(一度失敗させてから再送を確かめるテストで切り替える) */
    private bool $failOnComplete = false;

    /** onComplete が投げた例外の場所(ファイル:行) */
    private string $thrownAt = '';

    private RecordingLogger $logger;

    /** @var null|Closure(FormDefinition): Form */
    private ?Closure $formFactory = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpdb = WpdbTestDouble::create();
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->completed = [];
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb'], $GLOBALS['wp_version'], $GLOBALS['wp_actions'], $GLOBALS['wp']);
        Kernel::reset();

        parent::tearDown();
    }

    public function testGetAndOtherFormPostReturnInputWithoutCsrf(): void
    {
        $this->stubRuntimeFunctions();
        $definition = FormDefinition::create('contact')->text('name', 'Name', default: 'Initial');

        self::assertSame(FormStep::Input, (new Form($definition))->handle(new FormRequest('GET'))->step);
        self::assertSame('Initial', (new Form($definition))->values()['name']);

        $post = new FormRequest('POST', [Form::FIELD_FORM_ID => 'other', 'name' => 'ignored']);
        $result = (new Form($definition))->handle($post);

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame('Initial', $result->values['name']);
    }

    public function testCsrfFailureBindsValuesReturnsFormErrorAndIsLogged(): void
    {
        $this->stubRuntimeFunctions();
        Functions\when('wp_verify_nonce')->justReturn(false);
        $this->bootKernel(debug: false);

        $result = $this->form(FormDefinition::create('contact')->text('name', 'Name'))->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'contact',
            Form::FIELD_NONCE => 'bad',
            Form::FIELD_STEP => 'submit',
            'name' => ' Taro ',
        ]));

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame('Taro', $result->values['name']);
        self::assertSame(['Your session has expired. Please submit the form again.'], $result->formErrors);
        self::assertSame([['warning', 'tmswp.form: the CSRF check (nonce) of form {form} failed', 'contact']], $this->logRecords());
    }

    public function testUnknownStepReturnsInputWithBoundValues(): void
    {
        $this->stubRuntimeFunctions();

        $result = (new Form(FormDefinition::create('contact')->text('name', 'Name')))
            ->handle(new FormRequest('POST', [
                Form::FIELD_FORM_ID => 'contact',
                Form::FIELD_NONCE => 'ok',
                Form::FIELD_STEP => 'unknown',
                'name' => ' Taro ',
            ]));

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame('Taro', $result->values['name']);
    }

    /**
     * チェックを外した checkbox・checkboxes は、POST にキーが無い。空値の hidden を置かなくても '' と [] に取り込む
     * (既定値に戻らない)。確認画面の有無の両方で確かめる。
     */
    public function testMissingKeysOfUncheckedCheckboxesAreBoundAsEmptyValues(): void
    {
        $this->stubRuntimeFunctions();

        foreach (['without confirmation' => false, 'with confirmation' => true] as $case => $confirm) {
            $this->completed = [];
            $optional = FormDefinition::create('consent')
                ->checkbox('agree', 'Agree')
                ->checkboxes('topics', 'Topics', ['news' => 'News', 'docs' => 'Docs'], default: ['news'])
                ->confirm($confirm)
                ->onComplete($this->recordCompletion(...));
            $required = FormDefinition::create('consent')
                ->checkbox('agree', 'Agree', rules: [new Required()])
                ->checkboxes('topics', 'Topics', ['news' => 'News', 'docs' => 'Docs'], [new Required()], ['news'])
                ->confirm($confirm);
            $request = $confirm
                ? new FormRequest('POST', [Form::FIELD_FORM_ID => 'consent', Form::FIELD_NONCE => 'ok', Form::FIELD_STEP => 'confirm'])
                : $this->directSubmit('consent', []);

            $result = (new Form($optional))->handle($request);

            self::assertSame($confirm ? FormStep::Confirm : FormStep::Complete, $result->step, $case);
            self::assertSame(['agree' => '', 'topics' => []], $result->values, $case);
            self::assertSame($confirm ? [] : [['agree' => '', 'topics' => []]], $this->completed, $case);

            $result = (new Form($required))->handle($request);

            self::assertSame(FormStep::Input, $result->step, $case);
            self::assertSame(['agree' => ['Agree is required.'], 'topics' => ['Topics is required.']], $result->fieldErrors, $case);
        }
    }

    /**
     * 同じページに置いた2つのフォームは、同じ POST を受けても宛先(_form_id)のフォームだけが処理する。宛先でない
     * フォームは nonce も確かめず、同じ名前の項目の値も取り込まず、エラーの無い入力画面のまま。
     */
    public function testOnlyTheFormThatThePostIsAddressedToProcessesIt(): void
    {
        self::stubFrontRequest([]);
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\when('wp_salt')->justReturn('secret');
        Functions\when('nocache_headers')->justReturn();
        Functions\expect('wp_verify_nonce')->once()->with('ok', 'tmswp_form_contact')->andReturn(1);
        $newsletterCompleted = [];
        $newsletter = new Form(FormDefinition::create('newsletter')
            ->text('email', 'Email', [new Required()])
            ->text('name', 'Name', default: 'Subscriber')
            ->onComplete(static function (array $values) use (&$newsletterCompleted): void {
                $newsletterCompleted[] = $values;
            }));
        $contact = new Form(FormDefinition::create('contact')
            ->text('name', 'Name', [new Required()])
            ->onComplete($this->recordCompletion(...)));
        $request = $this->directSubmit('contact', ['name' => 'Taro']);

        $newsletterResult = $newsletter->handle($request);
        $contactResult = $contact->handle($request);

        self::assertSame(FormStep::Input, $newsletterResult->step);
        self::assertSame(['email' => '', 'name' => 'Subscriber'], $newsletterResult->values);
        self::assertSame([], $newsletterResult->fieldErrors);
        self::assertSame([], $newsletterResult->formErrors);
        self::assertSame([], $newsletterCompleted);
        self::assertSame(FormStep::Complete, $contactResult->step);
        self::assertSame([['name' => 'Taro']], $this->completed);
    }

    /**
     * 項目のエラーの設定と消去(旧 iwf2b の FieldTest の仕様)。エラーは handle() の検証の結果で決まり、FieldView に
     * 出る。項目の定義はエラーを持たないため、次のリクエストの Form には前のリクエストのエラーが残らない。
     */
    public function testFieldErrorsComeOnlyFromTheValidationOfTheCurrentRequest(): void
    {
        $this->stubRuntimeFunctions();
        Functions\when('esc_html')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        $definition = FormDefinition::create('contact')->text('name', 'Name', [new Required(), new MaxLength(3)]);
        $invalid = new Form($definition);

        self::assertFalse($invalid->field('name')->hasError());

        $invalid->handle($this->directSubmit('contact', ['name' => 'Taro']));

        // 1つの項目に記録するエラーは最初の1件だけ
        self::assertSame(['Name must be 3 characters or fewer.'], $invalid->field('name')->errorList());
        self::assertSame('<p>Name must be 3 characters or fewer.</p>', $invalid->field('name')->errors('<p>%s</p>'));
        self::assertSame(['name' => ['Name must be 3 characters or fewer.']], $invalid->errors());
        self::assertTrue($invalid->hasErrors());

        $next = new Form($definition);
        $next->handle($this->directSubmit('contact', ['name' => 'Tom']));

        self::assertSame([], $next->field('name')->errorList());
        self::assertSame('', $next->field('name')->errors());
        self::assertFalse($next->hasErrors());
    }

    public function testConfirmDisabledTreatsConfirmActionAsSubmit(): void
    {
        $this->stubRuntimeFunctions();

        $definition = FormDefinition::create('contact')
            ->text('name', 'Name', [new Required()])
            ->onComplete($this->recordCompletion(...));

        $result = (new Form($definition))->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'contact',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
            Form::FIELD_SUBMIT_TOKEN => SubmitToken::issue('contact'),
            'name' => ' Taro ',
        ]));

        self::assertSame(FormStep::Complete, $result->step);
        self::assertSame([['name' => 'Taro']], $this->completed);
    }

    public function testFormWithoutConfirmationEmbedsSubmitTokenAndRejectsReloadAndDoubleClick(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $definition = FormDefinition::create('contact')
            ->text('name', 'Name', [new Required()])
            ->onComplete($this->recordCompletion(...));
        $html = $this->form($definition)->open();
        $submitToken = $this->hiddenValue($html, Form::FIELD_SUBMIT_TOKEN);
        $post = new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'contact',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_SUBMIT_TOKEN => $submitToken,
            'name' => 'Taro',
        ]);

        self::assertStringNotContainsString(Form::FIELD_CONFIRM_TOKEN, $html);
        self::assertSame(FormStep::Complete, $this->form($definition)->handle($post)->step);

        // 完了画面のリロードと二重クリックは、同じ送信トークンの再 POST になる
        $reload = $this->form($definition)->handle($post);
        $doubleClick = $this->form($definition)->handle($post);

        self::assertSame([['name' => 'Taro']], $this->completed);
        self::assertSame(FormStep::Input, $reload->step);
        self::assertSame(['This form has already been submitted.'], $reload->formErrors);
        self::assertSame(['This form has already been submitted.'], $doubleClick->formErrors);
        self::assertSame(
            [
                ['notice', 'tmswp.form: a resubmission of form {form} was rejected', 'contact'],
                ['notice', 'tmswp.form: a resubmission of form {form} was rejected', 'contact'],
            ],
            $this->logRecords(),
        );
    }

    public function testFormWithoutConfirmationRejectsMissingTamperedAndForeignSubmitTokens(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $definition = FormDefinition::create('contact')
            ->text('name', 'Name')
            ->onComplete($this->recordCompletion(...));
        $valid = SubmitToken::issue('contact');
        [$body, $signature] = explode('.', $valid, 2);
        $tokens = [
            '',
            $body . '.' . strrev($signature),
            SubmitToken::issue('other'),
            // 確認トークンは用途の違う鍵で署名されるため、送信トークンとして受け付けない
            ConfirmToken::issue('contact', ['name' => 'Taro']),
        ];

        foreach ($tokens as $token) {
            $result = $this->form($definition)->handle(new FormRequest('POST', [
                Form::FIELD_FORM_ID => 'contact',
                Form::FIELD_NONCE => 'ok',
                Form::FIELD_STEP => 'submit',
                Form::FIELD_SUBMIT_TOKEN => $token,
                'name' => 'Taro',
            ]));

            self::assertSame(FormStep::Input, $result->step);
            self::assertSame('Taro', $result->values['name']);
            self::assertSame(['Your session has expired. Please submit the form again.'], $result->formErrors);
        }

        self::assertSame([], $this->completed);
        self::assertSame(
            array_fill(0, 4, ['warning', 'tmswp.form: the submit token of form {form} was rejected as invalid (missing, malformed, tampered or issued for another form)', 'contact']),
            $this->logRecords(),
        );
    }

    public function testExpiredSubmitTokenAsksToSubmitAgainWithoutClaiming(): void
    {
        $this->stubRuntimeFunctions();
        $definition = FormDefinition::create('contact')
            ->text('name', 'Name')
            ->onComplete($this->recordCompletion(...));

        $result = (new Form($definition))->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'contact',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_SUBMIT_TOKEN => SubmitToken::issue('contact', now: time() - SubmitToken::TTL - 1),
            'name' => 'Taro',
        ]));

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame(['Your session has expired. Please submit the form again.'], $result->formErrors);
        self::assertSame([], $this->completed);
        self::assertSame([], $this->wpdb->queries());
    }

    public function testConfirmSubmitConsumesJtiAndHandleIsIdempotent(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);

        $definition = FormDefinition::create('entry')
            ->text('name', 'Name', [new Required()])
            ->add(new RuntimeTestDateField('birth', 'Birth date', minYear: 2000, maxYear: 2026))
            ->confirm()
            ->onComplete($this->recordCompletion(...));

        $confirm = $this->form($definition);
        $confirmResult = $confirm->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
            'name' => ' Taro ',
            'birth' => ['year' => '2026', 'month' => '6', 'day' => '13'],
        ]));
        $html = $confirm->open();
        $token = $this->hiddenValue($html, Form::FIELD_CONFIRM_TOKEN);

        self::assertSame(FormStep::Confirm, $confirmResult->step);
        self::assertSame('2026-06-13', $confirmResult->values['birth']);
        // 確認画面ありのフォームは ConfirmToken の jti に一本化し、送信トークンを埋め込まない
        self::assertStringNotContainsString(Form::FIELD_SUBMIT_TOKEN, $html);

        $submitRequest = new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => $token,
        ]);
        $submit = $this->form($definition);
        $submitResult = $submit->handle($submitRequest);
        $secondCall = $submit->handle($submitRequest);

        self::assertSame(FormStep::Complete, $submitResult->step);
        self::assertSame($submitResult, $secondCall);
        self::assertSame([['name' => 'Taro', 'birth' => '2026-06-13']], $this->completed);

        $duplicate = $this->form($definition)->handle($submitRequest);

        self::assertSame(FormStep::Input, $duplicate->step);
        self::assertSame(['This form has already been submitted.'], $duplicate->formErrors);
        self::assertSame([['notice', 'tmswp.form: a resubmission of form {form} was rejected', 'entry']], $this->logRecords());
    }

    /**
     * 同じ jti の並行した2本の POST(二重クリック)。1本目の onComplete の実行中に2本目が届いても、jti の claim を
     * onComplete より前に行うため、2本目の INSERT は rows_affected が 0 になり、再送として拒否される。確認画面の有無の
     * 両方で確かめる。
     */
    public function testConcurrentRequestWithTheSameJtiIsRejectedWhileTheFirstRunsOnComplete(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);

        foreach (['without confirmation' => false, 'with confirmation' => true] as $case => $confirm) {
            $this->completed = [];
            $definition = FormDefinition::create('contact')->text('name', 'Name')->confirm($confirm);
            $request = $confirm
                ? new FormRequest('POST', [
                    Form::FIELD_FORM_ID => 'contact',
                    Form::FIELD_NONCE => 'ok',
                    Form::FIELD_STEP => 'submit',
                    Form::FIELD_CONFIRM_TOKEN => ConfirmToken::issue('contact', ['name' => 'Taro']),
                ])
                : $this->directSubmit('contact', ['name' => 'Taro']);
            // 1本目の onComplete の中で処理した2本目の結果
            $concurrent = [];
            $definition->onComplete(function (array $values) use (&$concurrent, $definition, $request): void {
                $this->completed[] = $values;

                // 2本目が onComplete に進んでも、さらに次の POST は送らない
                if (\count($this->completed) === 1) {
                    $concurrent[] = $this->form($definition)->handle($request);
                }
            });

            $first = $this->form($definition)->handle($request);

            self::assertSame(FormStep::Complete, $first->step, $case);
            self::assertCount(1, $concurrent, $case);
            self::assertSame(FormStep::Input, $concurrent[0]->step, $case);
            self::assertSame(['This form has already been submitted.'], $concurrent[0]->formErrors, $case);
            // 2本目の INSERT IGNORE は0行(獲得の失敗)
            self::assertSame(0, $this->wpdb->rows_affected, $case);
            self::assertSame([['name' => 'Taro']], $this->completed, $case);
        }
    }

    public function testInvalidAndExpiredConfirmTokensReturnInputState(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);

        $definition = FormDefinition::create('entry')
            ->text('name', 'Name')
            ->add(new RuntimeTestDateField('birth', 'Birth date'))
            ->confirm()
            ->confirmTtl(1);
        $expired = ConfirmToken::issue('entry', ['birth' => '2026-06-13', 'name' => 'Taro'], now: time() - 10);

        $invalidResult = $this->form($definition)->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => 'invalid',
        ]));
        self::assertSame(FormStep::Input, $invalidResult->step);
        self::assertSame(['The form could not be resubmitted. Please start again.'], $invalidResult->formErrors);
        self::assertSame(
            [['warning', 'tmswp.form: the confirm token of form {form} was rejected as invalid (malformed, tampered or issued for another form)', 'entry']],
            $this->logRecords(),
        );

        $expiredResult = $this->form($definition)->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => $expired,
        ]));
        self::assertSame(FormStep::Input, $expiredResult->step);
        self::assertSame(['year' => '2026', 'month' => '6', 'day' => '13'], $expiredResult->values['birth']);
        self::assertSame([self::EXPIRED_CONFIRMATION], $expiredResult->formErrors);
    }

    /**
     * 改ざんした確認トークン(値の1文字の変更・別のトークンの署名への差し替え・formId の差し替え)は、どれも Invalid として
     * 入力画面に戻し、jti を claim せずに warning を記録する。
     */
    public function testTamperedConfirmTokensAreRejectedAsInvalidAndLogged(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $definition = FormDefinition::create('entry')
            ->text('name', 'Name')
            ->confirm()
            ->onComplete($this->recordCompletion(...));
        [$body, $signature] = explode('.', ConfirmToken::issue('entry', ['name' => 'Taro']), 2);
        $json = base64_decode(strtr($body, '-_', '+/'), true);
        self::assertIsString($json);
        $encode = static fn(string $json): string => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        [, $otherSignature] = explode('.', ConfirmToken::issue('entry', ['name' => 'Jiro']), 2);
        $tokens = [
            'one character of a value' => $encode(str_replace('"Taro"', '"Tara"', $json)) . '.' . $signature,
            'signature of another token' => $body . '.' . $otherSignature,
            'form ID in the payload' => $encode(str_replace('"f":"entry"', '"f":"other"', $json)) . '.' . $signature,
            'token issued for another form' => ConfirmToken::issue('other', ['name' => 'Taro']),
        ];
        self::assertStringContainsString('"f":"entry"', $json);

        foreach ($tokens as $case => $token) {
            $this->logger->records = [];
            $result = $this->form($definition)->handle(new FormRequest('POST', [
                Form::FIELD_FORM_ID => 'entry',
                Form::FIELD_NONCE => 'ok',
                Form::FIELD_STEP => 'submit',
                Form::FIELD_CONFIRM_TOKEN => $token,
            ]));

            self::assertSame(FormStep::Input, $result->step, $case);
            self::assertSame(['The form could not be resubmitted. Please start again.'], $result->formErrors, $case);
            self::assertSame(
                [['warning', 'tmswp.form: the confirm token of form {form} was rejected as invalid (malformed, tampered or issued for another form)', 'entry']],
                $this->logRecords(),
                $case,
            );
        }

        self::assertSame([], $this->completed);
        self::assertSame([], $this->wpdb->queries());
    }

    public function testMissingFileIsDetectedBeforeClaimAndAllValuesAreRestored(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $storeDir = $this->createTempDirectory('form-missing-file-store');
        $definition = FormDefinition::create('entry')
            ->text('name', 'Name')
            ->file('resume', 'Resume')
            ->add(new RuntimeTestDateField('birth', 'Birth date'))
            ->confirm()
            ->tempFileStore(new TempFileStore($storeDir))
            ->onComplete($this->recordCompletion(...));
        // 一時ファイルが gc 等で消えた後の送信
        $token = ConfirmToken::issue('entry', [
            'birth' => '2026-06-13',
            'name' => 'Taro',
            'resume' => ['__tmsfile' => '0123456789abcdef0123456789abcdef'],
        ]);
        $request = new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => $token,
        ]);

        $result = $this->form($definition)->handle($request);

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame([self::EXPIRED_CONFIRMATION], $result->formErrors);
        // ファイル以外の全項目を復元してから戻す
        self::assertSame(['name' => 'Taro', 'resume' => '', 'birth' => ['year' => '2026', 'month' => '6', 'day' => '13']], $result->values);
        self::assertSame([['warning', 'tmswp.form: temporary files of form {form} were missing at submission: {fields}', 'entry']], $this->logRecords());
        self::assertSame('resume', $this->logger->records[0]['context']['fields']);
        // jti を claim していない(claim 済みかを確かめただけ)ので、ファイルが戻れば同じトークンで送信できる
        self::assertCount(1, $this->wpdb->queries());
        self::assertStringStartsWith('SELECT option_value FROM', $this->wpdb->queries()[0]);
        $this->createStoredFile($storeDir, '0123456789abcdef0123456789abcdef', 'resume.pdf', 'application/pdf', 'body');

        self::assertSame(FormStep::Complete, $this->form($definition)->handle($request)->step);
        self::assertCount(1, $this->completed);
    }

    public function testSignedTokenWhoseFieldsDoNotMatchTheFormReturnsInputInsteadOfThrowing(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $definition = FormDefinition::create('entry')
            ->text('name', 'Name')
            ->checkboxes('topics', 'Topics', ['news' => 'News', 'docs' => 'Docs'])
            ->confirm()
            ->onComplete($this->recordCompletion(...));
        $tokens = [
            // デプロイの前のフォームにあった項目
            ConfirmToken::issue('entry', ['name' => 'Taro', 'topics' => ['news'], 'removed' => 'x']),
            // デプロイの後に足された項目が無い
            ConfirmToken::issue('entry', ['name' => 'Taro']),
            // 項目の種類が変わった(単一の値 → 複数選択)
            ConfirmToken::issue('entry', ['name' => 'Taro', 'topics' => 'news']),
        ];

        foreach ($tokens as $token) {
            $result = $this->form($definition)->handle(new FormRequest('POST', [
                Form::FIELD_FORM_ID => 'entry',
                Form::FIELD_NONCE => 'ok',
                Form::FIELD_STEP => 'submit',
                Form::FIELD_CONFIRM_TOKEN => $token,
            ]));

            self::assertSame(FormStep::Input, $result->step);
            self::assertSame('Taro', $result->values['name']);
            self::assertSame([self::EXPIRED_CONFIRMATION], $result->formErrors);
        }

        self::assertSame([], $this->completed);
        self::assertSame([], $this->wpdb->queries());
        self::assertCount(3, $this->logRecords());
        self::assertSame(['notice', 'tmswp.form: the confirm token of form {form} does not match the current fields of the form', 'entry'], $this->logRecords()[0]);

        // 戻るでは、今の項目にある値だけを復元する
        $back = $this->form($definition)->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'back',
            Form::FIELD_CONFIRM_TOKEN => $tokens[0],
        ]));

        self::assertSame(['name' => 'Taro', 'topics' => ['news']], $back->values);
    }

    public function testValuesInTheConfirmTokenAreValidatedAgainWithTheCurrentRulesBeforeClaiming(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $before = $this->revalidationDefinition(20, ['news' => 'News', 'docs' => 'Docs']);
        $confirm = $this->form($before);
        self::assertSame(FormStep::Confirm, $confirm->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
            'name' => 'Taro Yamada',
            'topics' => ['news', 'docs'],
            'birth' => ['year' => '2026', 'month' => '6', 'day' => '13'],
        ]))->step);
        $submit = new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => $this->hiddenValue($confirm->open(), Form::FIELD_CONFIRM_TOKEN),
        ]);
        // 確認の後のデプロイで、MaxLength を小さくし、選択肢を減らした
        $after = $this->revalidationDefinition(5, ['news' => 'News']);

        $result = $this->form($after)->handle($submit);

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame(['name', 'topics'], array_keys($result->fieldErrors));
        self::assertSame([], $result->formErrors);
        self::assertSame(['name' => 'Taro Yamada', 'topics' => ['news', 'docs'], 'birth' => ['year' => '2026', 'month' => '6', 'day' => '13']], $result->values);
        self::assertSame([], $this->completed);
        // jti を claim していない
        self::assertSame([], $this->wpdb->queries());
        self::assertSame([['notice', 'tmswp.form: the values in the confirm token of form {form} do not pass the current rules: {fields}', 'entry']], $this->logRecords());
        self::assertSame('name, topics', $this->logger->records[0]['context']['fields']);

        // 消費していないので、今の規則で通る定義なら同じトークンで送信できる
        self::assertSame(FormStep::Complete, $this->form($before)->handle($submit)->step);
        self::assertSame([['name' => 'Taro Yamada', 'topics' => ['news', 'docs'], 'birth' => '2026-06-13']], $this->completed);
    }

    public function testBackRestoresSignedValuesAndStoredFileToken(): void
    {
        $this->stubRuntimeFunctions();

        $storeDir = $this->createTempDirectory('form-back-store');
        $token = '0123456789abcdef0123456789abcdef';
        $this->createStoredFile($storeDir, $token, 'resume.pdf', 'application/pdf', 'body');
        $store = new TempFileStore($storeDir);
        $definition = FormDefinition::create('entry')
            ->text('name', 'Name')
            ->file('resume', 'Resume')
            ->confirm()
            ->tempFileStore($store);
        $confirmToken = ConfirmToken::issue('entry', [
            'name' => 'Taro',
            'resume' => ['__tmsfile' => $token],
        ], now: time() - 10);

        $result = (new Form($definition))->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'back',
            Form::FIELD_CONFIRM_TOKEN => $confirmToken,
        ]));

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame('Taro', $result->values['name']);
        self::assertInstanceOf(StoredFile::class, $result->values['resume']);
        self::assertSame('resume.pdf', $result->values['resume']->clientName);
    }

    public function testBackRestoresCompositeFieldsAsPartsForTheInputScreen(): void
    {
        $this->stubRuntimeFunctions();
        $definition = FormDefinition::create('entry')->add(new RuntimeTestDateField('birth', 'Birth date'))->confirm();

        $result = (new Form($definition))->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'back',
            Form::FIELD_CONFIRM_TOKEN => ConfirmToken::issue('entry', ['birth' => '2026-06-13']),
        ]));

        self::assertSame(['birth' => ['year' => '2026', 'month' => '6', 'day' => '13']], $result->values);
    }

    public function testBackOnFormWithoutConfirmationRedisplaysInputWithoutError(): void
    {
        $this->stubRuntimeFunctions();

        $result = (new Form(FormDefinition::create('contact')->text('name', 'Name')))->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'contact',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'back',
            'name' => ' Taro ',
        ]));

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame('Taro', $result->values['name']);
        self::assertSame([], $result->formErrors);
    }

    public function testBackWithInvalidTokenShowsErrorAndIsLogged(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $definition = FormDefinition::create('entry')->text('name', 'Name', default: 'Initial')->confirm();

        $result = $this->form($definition)->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'back',
            Form::FIELD_CONFIRM_TOKEN => 'tampered',
        ]));

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame('Initial', $result->values['name']);
        self::assertSame(['The form could not be resubmitted. Please start again.'], $result->formErrors);
        self::assertSame(
            [['warning', 'tmswp.form: the confirm token of form {form} was rejected as invalid on going back (malformed, tampered or issued for another form)', 'entry']],
            $this->logRecords(),
        );
    }

    public function testConfirmWithOtherFieldErrorsStoresFilesThatPassedTheirOwnRules(): void
    {
        $this->stubRuntimeFunctions();
        Functions\when('TMSWP\\Core\\Form\\is_uploaded_file')->alias(static fn(string $path): bool => is_file($path));
        Functions\when('TMSWP\\Core\\Form\\move_uploaded_file')->alias(static fn(string $from, string $to): bool => rename($from, $to));
        // 期限切れのファイルを消す WP-Cron のイベントはスケジュール済み
        Functions\when('wp_next_scheduled')->justReturn(1);
        Functions\when('sanitize_file_name')->returnArg();
        Functions\when('size_format')->alias(static fn(int $bytes): string => "{$bytes} B");
        $uploadDir = $this->createTempDirectory('form-upload');
        file_put_contents($uploadDir . '/resume.txt', 'resume');
        file_put_contents($uploadDir . '/photo.txt', 'a photo that is too large');
        $definition = FormDefinition::create('entry')
            ->text('name', 'Name', [new Required()])
            ->file('resume', 'Resume')
            ->file('photo', 'Photo', [new MaxFileSize(5)])
            ->confirm()
            ->tempFileStore(new TempFileStore($this->createTempDirectory('form-upload-store')));
        $form = new Form($definition);

        $result = $form->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
            'name' => '',
        ], [
            'resume' => ['name' => 'resume.txt', 'type' => 'text/plain', 'tmp_name' => $uploadDir . '/resume.txt', 'error' => UPLOAD_ERR_OK, 'size' => 6],
            'photo' => ['name' => 'photo.txt', 'type' => 'text/plain', 'tmp_name' => $uploadDir . '/photo.txt', 'error' => UPLOAD_ERR_OK, 'size' => 25],
        ]));

        self::assertSame(FormStep::Input, $result->step);
        self::assertArrayHasKey('name', $result->fieldErrors);
        self::assertSame(['Photo must be 5 B or smaller.'], $result->fieldErrors['photo']);
        // 自身の検証を通ったファイルは保管し、入力画面の hidden でトークンを引き回す
        self::assertInstanceOf(StoredFile::class, $result->values['resume']);
        self::assertSame('resume', file_get_contents($result->values['resume']->path));
        self::assertStringContainsString('name="resume__token" value="' . $result->values['resume']->token . '"', $form->field('resume')->hidden());
        // 自身の検証に失敗したファイルは保管しない
        self::assertInstanceOf(UploadedFile::class, $result->values['photo']);
        self::assertFileExists($uploadDir . '/photo.txt');
    }

    public function testFormWithoutConfirmationPassesAStoredFileToOnCompleteAndStoresNothingForAResubmission(): void
    {
        $this->stubRuntimeFunctions();
        $this->stubUploads();
        $this->bootKernel(debug: false);
        $storeDir = $this->createTempDirectory('direct-file-store');
        $received = [];
        $definition = FormDefinition::create('apply')
            ->file('resume', 'Resume')
            ->tempFileStore(new TempFileStore($storeDir))
            ->onComplete(static function (array $values) use (&$received): void {
                $file = $values['resume'];
                self::assertInstanceOf(StoredFile::class, $file);
                $received[] = [$file->clientName, file_get_contents($file->path)];
            });
        $token = SubmitToken::issue('apply');

        $result = $this->form($definition)->handle($this->fileSubmit('apply', $token, ['resume' => $this->upload('resume.txt', 'resume')]));

        self::assertSame(FormStep::Complete, $result->step);
        // 確認画面が無くても、保管した StoredFile が onComplete に渡る
        self::assertSame([['resume.txt', 'resume']], $received);
        // onComplete が消費しなかったファイルは、完了の後に消える
        self::assertSame([], $this->tokenDirectories($storeDir));

        // 再送(リロード)は jti の claim で拒否し、そのファイルを保管しない
        $reload = $this->upload('resume.txt', 'resume');
        $duplicate = $this->form($definition)->handle($this->fileSubmit('apply', $token, ['resume' => $reload]));

        self::assertSame(['This form has already been submitted.'], $duplicate->formErrors);
        self::assertFileExists($reload['tmp_name']);
        self::assertSame([], $this->tokenDirectories($storeDir));
        self::assertCount(1, $received);
    }

    public function testFilesThatOnCompleteDidNotConsumeAreDeletedAfterTheConfirmedSubmission(): void
    {
        $this->stubRuntimeFunctions();
        $this->stubUploads();
        Functions\when('wp_unique_filename')->alias(static fn(string $_directory, string $filename): string => $filename);
        $this->bootKernel(debug: false);
        $storeDir = $this->createTempDirectory('confirmed-file-store');
        $targetDir = $this->createTempDirectory('confirmed-file-target');
        $definition = FormDefinition::create('entry')
            ->file('resume', 'Resume')
            ->file('photo', 'Photo')
            ->confirm()
            ->tempFileStore(new TempFileStore($storeDir))
            ->onComplete(static function (array $values) use ($targetDir): void {
                self::assertInstanceOf(StoredFile::class, $values['resume']);
                self::assertInstanceOf(StoredFile::class, $values['photo']);
                // resume だけを移し、photo は使わない
                $values['resume']->moveTo($targetDir);
            });
        $confirm = $this->form($definition);
        $confirm->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
        ], ['resume' => $this->upload('resume.txt', 'resume'), 'photo' => $this->upload('photo.txt', 'photo')]));

        self::assertCount(2, $this->tokenDirectories($storeDir));

        $result = $this->form($definition)->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => $this->hiddenValue($confirm->open(), Form::FIELD_CONFIRM_TOKEN),
        ]));

        self::assertSame(FormStep::Complete, $result->step);
        self::assertSame('resume', file_get_contents($targetDir . '/resume.txt'));
        self::assertSame([], $this->tokenDirectories($storeDir));
        self::assertSame([], $this->logRecords());
    }

    public function testOnCompleteExceptionKeepsTheTemporaryFilesForTheResubmission(): void
    {
        $this->stubRuntimeFunctions();
        $this->stubUploads();
        $this->bootKernel(debug: false);
        $storeDir = $this->createTempDirectory('resubmit-file-store');

        foreach ([true, false] as $confirm) {
            $received = [];
            $this->failOnComplete = true;
            $definition = FormDefinition::create('entry')
                ->file('resume', 'Resume')
                ->confirm($confirm)
                ->tempFileStore(new TempFileStore($storeDir))
                ->onComplete(function (array $values) use (&$received): void {
                    if ($this->failOnComplete) {
                        throw new PhpRuntimeException('mail server is down');
                    }

                    self::assertInstanceOf(StoredFile::class, $values['resume']);
                    $received[] = $values['resume']->token;
                });

            if ($confirm) {
                $confirmForm = $this->form($definition);
                $confirmForm->handle(new FormRequest('POST', [
                    Form::FIELD_FORM_ID => 'entry',
                    Form::FIELD_NONCE => 'ok',
                    Form::FIELD_STEP => 'confirm',
                ], ['resume' => $this->upload('resume.txt', 'resume')]));
                $submit = new FormRequest('POST', [
                    Form::FIELD_FORM_ID => 'entry',
                    Form::FIELD_NONCE => 'ok',
                    Form::FIELD_STEP => 'submit',
                    Form::FIELD_CONFIRM_TOKEN => $this->hiddenValue($confirmForm->open(), Form::FIELD_CONFIRM_TOKEN),
                ]);
                $failed = $this->form($definition)->handle($submit);
                // 同じ確認トークンで再送する
                $resubmit = $submit;
            } else {
                $failedForm = $this->form($definition);
                $failed = $failedForm->handle($this->fileSubmit('entry', SubmitToken::issue('entry'), ['resume' => $this->upload('resume.txt', 'resume')]));
                // 入力画面の hidden で一時保管のトークンを引き回し、ファイルを選び直さずに送り直す
                $storedToken = $this->hiddenValue($failedForm->field('resume')->hidden(), 'resume__token');
                $resubmit = $this->fileSubmit('entry', SubmitToken::issue('entry'), [], ['resume__token' => $storedToken]);
            }

            self::assertSame([self::SEND_FAILED], $failed->formErrors);
            self::assertInstanceOf(StoredFile::class, $failed->values['resume']);
            // 再送で使うため、一時ファイルを消さない
            self::assertTrue($failed->values['resume']->exists());

            $this->failOnComplete = false;

            self::assertSame(FormStep::Complete, $this->form($definition)->handle($resubmit)->step);
            self::assertSame([$failed->values['resume']->token], $received);
            self::assertSame([], $this->tokenDirectories($storeDir));
        }
    }

    /** @return iterable<string, array{int}> 後始末の削除を失敗させるトークンのディレクトリの権限 */
    public static function undeletableDirectoryModes(): iterable
    {
        yield 'files cannot be unlinked' => [0500];
        yield 'directory cannot be listed' => [0300];
    }

    #[DataProvider('undeletableDirectoryModes')]
    public function testFailureToDeleteAFileAfterTheCompletionIsLoggedWithoutFailingTheSubmissionEvenInDebug(int $mode): void
    {
        $this->stubRuntimeFunctions();
        $this->stubUploads();
        $this->bootKernel(debug: true);
        $storeDir = $this->createTempDirectory('cleanup-failure-store');
        $locked = '';
        $definition = FormDefinition::create('apply')
            ->file('resume', 'Resume')
            ->tempFileStore(new TempFileStore($storeDir))
            ->onComplete(static function (array $values) use (&$locked, $mode): void {
                self::assertInstanceOf(StoredFile::class, $values['resume']);
                // 後始末の削除を失敗させる
                $locked = \dirname($values['resume']->path);
                self::assertTrue(chmod($locked, $mode));
            });
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            $result = $this->form($definition)->handle($this->fileSubmit('apply', SubmitToken::issue('apply'), ['resume' => $this->upload('resume.txt', 'resume')]));
        } finally {
            restore_error_handler();

            if ($locked !== '') {
                chmod($locked, 0700);
            }
        }

        self::assertSame(FormStep::Complete, $result->step);
        self::assertSame([], $result->formErrors);
        self::assertSame([['error', 'tmswp.form: failed to delete a temporary file of form {form} after completion', 'apply']], $this->logRecords());
        $context = $this->logger->records[0]['context'];
        // 文脈にはフォームの ID と例外のクラス・場所だけを入れ、パスを含むメッセージを入れない
        self::assertSame(['form', 'exceptionClass', 'exceptionAt', 'previousClasses'], array_keys($context));
        self::assertSame(TempFileException::class, $context['exceptionClass']);
        self::assertStringNotContainsString($storeDir, (string) json_encode($context));
    }

    public function testReloadAfterTheCompletionDeletedTheFileIsRejectedAsAResubmission(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $storeDir = $this->createTempDirectory('reload-file-store');
        $fileToken = '0123456789abcdef0123456789abcdef';
        $this->createStoredFile($storeDir, $fileToken, 'resume.pdf', 'application/pdf', 'body');
        $definition = FormDefinition::create('entry')
            ->file('resume', 'Resume')
            ->confirm()
            ->tempFileStore(new TempFileStore($storeDir))
            ->onComplete($this->recordCompletion(...));
        $request = new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => ConfirmToken::issue('entry', ['resume' => ['__tmsfile' => $fileToken]]),
        ]);

        self::assertSame(FormStep::Complete, $this->form($definition)->handle($request)->step);
        self::assertSame([], $this->tokenDirectories($storeDir));

        // 完了の後始末でファイルは消えたが、jtiはclaim済み(wpdbの代役が獲得済みの行を持つ)
        $reload = $this->form($definition)->handle($request);

        self::assertSame(FormStep::Input, $reload->step);
        self::assertSame(['This form has already been submitted.'], $reload->formErrors);
        self::assertSame([['notice', 'tmswp.form: a resubmission of form {form} was rejected', 'entry']], $this->logRecords());
        self::assertCount(1, $this->completed);
    }

    /** @return iterable<string, array{list<string>}> トークンのディレクトリにファイルを作る順(vfsStream は作った順に列挙する) */
    public static function temporaryFileCreationOrders(): iterable
    {
        yield 'payload listed first' => [[TempFileStore::FILE_NAME, TempFileStore::META_NAME]];
        yield 'metadata listed first' => [[TempFileStore::META_NAME, TempFileStore::FILE_NAME]];
    }

    /** @param list<string> $creationOrder */
    #[DataProvider('temporaryFileCreationOrders')]
    public function testTokenWhoseFileWasDeletedButMetadataWasNotIsTreatedAsMissing(array $creationOrder): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $fileToken = '0123456789abcdef0123456789abcdef';
        // メタデータだけを削除できないトークンのディレクトリ。列挙の順に関係なく、本体を先に消すことを確かめる
        $tokenDir = (new RuntimeTestMetadataUndeletableDirectory($fileToken, 0700))->at(vfsStream::newDirectory('store', 0700)->at(vfsStream::setup('root')));
        $contents = [
            TempFileStore::FILE_NAME => 'body',
            TempFileStore::META_NAME => json_encode(['name' => 'resume.pdf', 'mime' => 'application/pdf', 'size' => 4, 'created' => time()], JSON_THROW_ON_ERROR),
        ];

        foreach ($creationOrder as $name) {
            vfsStream::newFile($name, 0600)->withContent($contents[$name])->at($tokenDir);
        }

        $storeDir = vfsStream::url('root/store');
        $definition = FormDefinition::create('entry')
            ->file('resume', 'Resume')
            ->confirm()
            ->tempFileStore(new TempFileStore($storeDir))
            ->onComplete($this->recordCompletion(...));
        $request = $this->confirmedSubmit(ConfirmToken::issue('entry', ['resume' => ['__tmsfile' => $fileToken]]));
        set_error_handler(static fn(): bool => true, E_USER_WARNING | E_WARNING);

        try {
            $result = $this->form($definition)->handle($request);
        } finally {
            restore_error_handler();
        }

        // 後始末は本体のファイルを消してから、メタデータの削除で失敗した(送信は完了し、error を記録した)
        self::assertSame(FormStep::Complete, $result->step);
        self::assertFileDoesNotExist($storeDir . '/' . $fileToken . '/' . TempFileStore::FILE_NAME);
        self::assertFileExists($storeDir . '/' . $fileToken . '/' . TempFileStore::META_NAME);
        self::assertSame([['error', 'tmswp.form: failed to delete a temporary file of form {form} after completion', 'entry']], $this->logRecords());
        self::assertFalse((new TempFileStore($storeDir))->exists($fileToken));

        // claim済みの確認トークンの再送(完了画面のリロード)は、再送として拒否する(代役が獲得済みの行を持つ)
        $this->logger->records = [];
        $reload = $this->form($definition)->handle($request);

        self::assertSame(FormStep::Input, $reload->step);
        self::assertSame(['This form has already been submitted.'], $reload->formErrors);
        self::assertSame([['notice', 'tmswp.form: a resubmission of form {form} was rejected', 'entry']], $this->logRecords());

        // claim していない確認トークンは、ファイルの消失として入力画面に戻す
        $this->logger->records = [];
        $missing = $this->form($definition)->handle($this->confirmedSubmit(ConfirmToken::issue('entry', ['resume' => ['__tmsfile' => $fileToken]])));

        self::assertSame(FormStep::Input, $missing->step);
        self::assertSame([self::EXPIRED_CONFIRMATION], $missing->formErrors);
        self::assertSame([['warning', 'tmswp.form: temporary files of form {form} were missing at submission: {fields}', 'entry']], $this->logRecords());
        self::assertCount(1, $this->completed);
    }

    public function testFailedUploadIsAFieldErrorEvenWithoutFileRules(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: true);

        foreach ([true, false] as $confirm) {
            $definition = FormDefinition::create('entry')
                ->file('resume', 'Resume')
                ->confirm($confirm)
                ->tempFileStore(new TempFileStore($this->createTempDirectory('failed-upload-store')))
                ->onComplete($this->recordCompletion(...));

            $result = $this->form($definition)->handle(new FormRequest('POST', [
                Form::FIELD_FORM_ID => 'entry',
                Form::FIELD_NONCE => 'ok',
                Form::FIELD_STEP => $confirm ? 'confirm' : 'submit',
                Form::FIELD_SUBMIT_TOKEN => SubmitToken::issue('entry'),
            ], ['resume' => ['name' => 'large.pdf', 'type' => 'application/pdf', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]]));

            self::assertSame(FormStep::Input, $result->step);
            self::assertSame(['resume' => ['The uploaded file is too large.']], $result->fieldErrors);
        }

        self::assertSame([], $this->completed);
    }

    public function testFailedUploadErrorTakesPrecedenceOverTheErrorsOfFileRules(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: true);
        $definition = FormDefinition::create('entry')
            ->file('resume', 'Resume', [new Required(), new FileExtension(['pdf'])])
            ->confirm()
            ->tempFileStore(new TempFileStore($this->createTempDirectory('failed-upload-rules-store')));

        $result = $this->form($definition)->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
        ], ['resume' => ['name' => 'large.txt', 'type' => 'text/plain', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]]));

        self::assertSame(FormStep::Input, $result->step);
        // 拡張子の規則のエラーではなく、アップロードの失敗を出す
        self::assertSame(['resume' => ['The uploaded file is too large.']], $result->fieldErrors);
    }

    public function testFailureToStoreTheUploadedFileFollowsTheErrorPolicyAndReleasesTheJti(): void
    {
        $this->stubRuntimeFunctions();
        $this->stubUploads();
        $this->bootKernel(debug: false);
        $readOnly = $this->createTempDirectory('store-failure');
        $definition = FormDefinition::create('apply')
            ->file('resume', 'Resume')
            ->tempFileStore(new TempFileStore($readOnly . '/store'))
            ->onComplete($this->recordCompletion(...));
        $token = SubmitToken::issue('apply');
        self::assertTrue(chmod($readOnly, 0500));
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            $failed = $this->form($definition)->handle($this->fileSubmit('apply', $token, ['resume' => $this->upload('resume.txt', 'resume')]));
            $confirmFailed = $this->form((clone $definition)->confirm())->handle(new FormRequest('POST', [
                Form::FIELD_FORM_ID => 'apply',
                Form::FIELD_NONCE => 'ok',
                Form::FIELD_STEP => 'confirm',
            ], ['resume' => $this->upload('resume.txt', 'resume')]));
        } finally {
            restore_error_handler();
            chmod($readOnly, 0700);
        }

        self::assertSame([self::SEND_FAILED], $failed->formErrors);
        self::assertSame([self::SEND_FAILED], $confirmFailed->formErrors);
        self::assertSame(
            [
                ['error', 'tmswp.form: failed to store the uploaded files of form {form}', 'apply'],
                ['error', 'tmswp.form: failed to store the uploaded files of form {form}', 'apply'],
            ],
            $this->logRecords(),
        );
        self::assertSame(TempFileException::class, $this->logger->records[0]['context']['exceptionClass']);
        self::assertSame([], $this->completed);

        // jti を手放しているので、同じ送信トークンで送り直せる
        self::assertSame(FormStep::Complete, $this->form($definition)->handle($this->fileSubmit('apply', $token, ['resume' => $this->upload('resume.txt', 'resume')]))->step);
        self::assertCount(1, $this->completed);
    }

    public function testFailureToDetectTheMimeTypeFollowsTheErrorPolicyOnConfirmAndDirectSubmit(): void
    {
        $this->stubRuntimeFunctions();
        $this->stubUploads();
        $this->bootKernel(debug: false);
        $definition = FormDefinition::create('apply')
            ->file('resume', 'Resume')
            ->tempFileStore(new TempFileStore($this->createTempDirectory('mime-failure-store')))
            ->onComplete($this->recordCompletion(...));
        $token = SubmitToken::issue('apply');
        $confirm = new FormRequest('POST', [Form::FIELD_FORM_ID => 'apply', Form::FIELD_NONCE => 'ok', Form::FIELD_STEP => 'confirm'], ['resume' => $this->upload('resume.txt', 'resume')]);

        [$failed, $confirmFailed] = $this->withUnreadableMagicDatabase(fn(): array => [
            $this->form($definition)->handle($this->fileSubmit('apply', $token, ['resume' => $this->upload('resume.txt', 'resume')])),
            $this->form((clone $definition)->confirm())->handle($confirm),
        ]);

        self::assertSame([self::SEND_FAILED], $failed->formErrors);
        self::assertSame([self::SEND_FAILED], $confirmFailed->formErrors);
        self::assertSame(
            [
                ['error', 'tmswp.form: failed to store the uploaded files of form {form}', 'apply'],
                ['error', 'tmswp.form: failed to store the uploaded files of form {form}', 'apply'],
            ],
            $this->logRecords(),
        );
        self::assertSame(TempFileException::class, $this->logger->records[0]['context']['exceptionClass']);
        self::assertSame([], $this->completed);

        // jti を手放しているので、判定できるようになれば同じ送信トークンで送り直せる
        self::assertSame(FormStep::Complete, $this->form($definition)->handle($this->fileSubmit('apply', $token, ['resume' => $this->upload('resume.txt', 'resume')]))->step);
        self::assertCount(1, $this->completed);
    }

    public function testFailureToDetectTheMimeTypeIsThrownInDebug(): void
    {
        $this->stubRuntimeFunctions();
        $this->stubUploads();
        $this->bootKernel(debug: true);
        $definition = FormDefinition::create('apply')
            ->file('resume', 'Resume')
            ->confirm()
            ->tempFileStore(new TempFileStore($this->createTempDirectory('mime-failure-debug-store')));

        $this->expectException(TempFileException::class);
        $this->expectExceptionMessage('failed to initialize finfo to detect the MIME type of an uploaded file');

        $this->withUnreadableMagicDatabase(fn(): FormResult => $this->form($definition)->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'apply',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
        ], ['resume' => $this->upload('resume.txt', 'resume')])));
    }

    public function testFailureToStoreTheUploadedFileIsThrownInDebug(): void
    {
        $this->stubRuntimeFunctions();
        $this->stubUploads();
        $this->bootKernel(debug: true);
        $readOnly = $this->createTempDirectory('store-failure-debug');
        $definition = FormDefinition::create('apply')
            ->file('resume', 'Resume')
            ->tempFileStore(new TempFileStore($readOnly . '/store'));
        self::assertTrue(chmod($readOnly, 0500));
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            $this->form($definition)->handle($this->fileSubmit('apply', SubmitToken::issue('apply'), ['resume' => $this->upload('resume.txt', 'resume')]));
            self::fail('TempFileException was not thrown');
        } catch (TempFileException $exception) {
            self::assertStringStartsWith('failed to create temporary file base directory: ', $exception->getMessage());
        } finally {
            restore_error_handler();
            chmod($readOnly, 0700);
        }
    }

    public function testGarbageCollectionIsRegisteredOnceAndItsFailureIsLoggedInProduction(): void
    {
        $callbacks = $this->captureGarbageCollection(debug: false);
        $uploads = $this->createTempDirectory('gc-uploads');
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $uploads]);
        $tokenDir = $this->createExpiredTemporaryFile($uploads . '/tmswp-form-tmp');

        $this->withReadOnly($tokenDir, $callbacks[TempFileGc::HOOK]);

        self::assertSame([['error', 'tmswp.form: failed to collect expired temporary files', null]], $this->logRecords());
        self::assertSame(['exceptionClass', 'exceptionAt', 'previousClasses'], array_keys($this->logger->records[0]['context']));
        self::assertSame([TempFileException::class], $this->logger->records[0]['context']['previousClasses']);
        self::assertStringNotContainsString($uploads, (string) json_encode($this->logger->records[0]['context']));

        // 失敗したディレクトリは次の実行で消す
        $callbacks[TempFileGc::HOOK]();

        self::assertDirectoryDoesNotExist($tokenDir);
    }

    public function testGarbageCollectionFailureIsThrownInDebug(): void
    {
        $callbacks = $this->captureGarbageCollection(debug: true);
        $uploads = $this->createTempDirectory('gc-uploads-debug');
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $uploads]);
        $tokenDir = $this->createExpiredTemporaryFile($uploads . '/tmswp-form-tmp');

        $this->expectException(TempFileException::class);
        $this->expectExceptionMessage('1 expired temporary file directories could not be deleted');

        $this->withReadOnly($tokenDir, $callbacks[TempFileGc::HOOK]);
    }

    public function testDeactivationKeepsTheGarbageCollectionWhileAnotherKernelIsRegisteredAndUnschedulesItForTheLastOne(): void
    {
        // tmswp/deactivated に登録されたコールバック
        $deactivated = new ArrayObject();
        Actions\expectAdded('tmswp/deactivated')->twice()->whenHappen(static function (Closure $callback) use ($deactivated): void {
            $deactivated->append($callback);
        });
        $this->stubRuntimeFunctions();
        Functions\when('is_wp_error')->justReturn(false);
        // wp_clear_scheduled_hook() の呼び出し
        $cleared = new ArrayObject();
        Functions\when('wp_clear_scheduled_hook')->alias(static function (string $hook, array $args, bool $wpError) use ($cleared): int {
            $cleared->append([$hook, $args, $wpError]);

            return 1;
        });

        // テーマとプラグインのように、2つの Kernel が登録済み(登録はプロセスで1回)
        $this->bootKernel(debug: false);
        $this->bootKernel(debug: false);
        [$first, $second] = array_values(Kernel::all());

        self::assertCount(1, $deactivated);
        $unschedule = $deactivated->offsetGet(0);
        self::assertInstanceOf(Closure::class, $unschedule);

        // どちらを無効にしても、もう片方のフォームの一時ファイルの掃除を続けるため、イベントを解除しない
        $unschedule($first);
        $unschedule($second);

        self::assertSame([], $cleared->getArrayCopy());

        // 次のリクエストでは無効にした Kernel は読み込まれない。残った最後の1つの無効化でイベントを解除する
        Kernel::reset();
        $this->bootKernel(debug: false);

        self::assertCount(2, $deactivated);
        $unschedule = $deactivated->offsetGet(1);
        self::assertInstanceOf(Closure::class, $unschedule);

        $unschedule(array_values(Kernel::all())[0]);

        self::assertSame([[TempFileGc::HOOK, [], true]], $cleared->getArrayCopy());
        self::assertSame([], $this->logRecords());
    }

    public function testFromGlobalsDropsKeysThatAreNotStringsAndNeverThrows(): void
    {
        Functions\when('wp_unslash')->alias(static fn(mixed $value): mixed => \is_string($value) ? stripslashes($value) : $value);
        $globals = [$_SERVER, $_POST, $_FILES];

        try {
            $_SERVER['REQUEST_METHOD'] = 'post';
            $_POST = [0 => 'numeric', '1' => 'numeric string', 'name' => 'O\\\'Brien', 'topics' => ['a', 'b']];
            $_FILES = [0 => ['name' => 'x'], 'resume' => ['name' => ['a.pdf'], 'error' => [UPLOAD_ERR_OK]]];

            $request = FormRequest::fromGlobals();

            self::assertTrue($request->isPost());
            self::assertSame(['name' => "O'Brien", 'topics' => ['a', 'b']], $request->post);
            self::assertSame(['resume' => ['name' => ['a.pdf'], 'error' => [UPLOAD_ERR_OK]]], $request->files);

            unset($_SERVER['REQUEST_METHOD']);

            self::assertSame('GET', FormRequest::fromGlobals()->method);
        } finally {
            [$_SERVER, $_POST, $_FILES] = $globals;
        }
    }

    public function testNumericKeysAndArrayFilesInThePostBecomeFieldErrorsInsteadOfExceptions(): void
    {
        $this->stubRuntimeFunctions();
        Functions\when('wp_unslash')->returnArg();
        $this->bootKernel(debug: false);
        $definition = FormDefinition::create('entry')
            ->text('name', 'Name', [new Required()])
            ->file('resume', 'Resume')
            ->onComplete($this->recordCompletion(...));
        $globals = [$_SERVER, $_POST, $_FILES];

        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = [
                Form::FIELD_FORM_ID => 'entry',
                Form::FIELD_NONCE => 'ok',
                Form::FIELD_STEP => 'submit',
                Form::FIELD_SUBMIT_TOKEN => SubmitToken::issue('entry'),
                0 => 'numeric key',
                'name' => 'Taro',
            ];
            // <input type="file" name="resume[]" multiple> の形
            $_FILES = ['resume' => [
                'name' => ['a.pdf', 'b.pdf'],
                'type' => ['application/pdf', 'application/pdf'],
                'tmp_name' => ['/tmp/phpA', '/tmp/phpB'],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'size' => [1, 1],
            ]];

            $result = $this->form($definition)->handle();
        } finally {
            [$_SERVER, $_POST, $_FILES] = $globals;
        }

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame(['resume' => ['The file upload failed.']], $result->fieldErrors);
        self::assertSame('Taro', $result->values['name']);
        self::assertSame('', $result->values['resume']);
        self::assertSame([], $this->completed);
        self::assertSame(
            [['notice', 'tmswp.form: the request to form {form} has malformed input (invalid UTF-8 or a malformed file upload) in: {fields}', 'entry']],
            $this->logRecords(),
        );
        self::assertSame(['form' => 'entry', 'fields' => 'resume'], $this->logger->records[0]['context']);
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function malformedFileEntries(): iterable
    {
        $entry = ['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/phpA', 'error' => UPLOAD_ERR_OK, 'size' => 1];

        yield 'array of files' => [['name' => ['a.pdf'], 'type' => ['application/pdf'], 'tmp_name' => ['/tmp/phpA'], 'error' => [UPLOAD_ERR_OK], 'size' => [1]]];
        yield 'nested array of files' => [['name' => ['x' => ['a.pdf']], 'error' => ['x' => [UPLOAD_ERR_OK]]]];
        yield 'missing tmp_name' => [array_diff_key($entry, ['tmp_name' => true])];
        yield 'error as a string' => [['error' => '0'] + $entry];
        yield 'size as a string' => [['size' => '1'] + $entry];
        yield 'name of invalid UTF-8' => [['name' => "a\xff.pdf"] + $entry];
        yield 'numeric keys only' => [[0 => 'a.pdf', 1 => UPLOAD_ERR_OK]];
    }

    /** @param array<array-key, mixed> $entry */
    #[DataProvider('malformedFileEntries')]
    public function testMalformedFileEntriesBecomeFieldErrorsOnConfirmAndDirectSubmit(array $entry): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);

        foreach ([true, false] as $confirm) {
            $this->logger->records = [];
            $definition = FormDefinition::create('entry')
                ->file('resume', 'Resume')
                ->confirm($confirm)
                ->tempFileStore(new TempFileStore($this->createTempDirectory('malformed-file-store')))
                ->onComplete($this->recordCompletion(...));

            $result = $this->form($definition)->handle(new FormRequest('POST', [
                Form::FIELD_FORM_ID => 'entry',
                Form::FIELD_NONCE => 'ok',
                Form::FIELD_STEP => $confirm ? 'confirm' : 'submit',
                Form::FIELD_SUBMIT_TOKEN => SubmitToken::issue('entry'),
            ], ['resume' => $entry]));

            self::assertSame(FormStep::Input, $result->step);
            self::assertSame(['resume' => ['The file upload failed.']], $result->fieldErrors);
            self::assertSame('resume', $this->logger->records[0]['context']['fields'] ?? null);
        }

        self::assertSame([], $this->completed);
    }

    public function testInvalidUtf8BecomesFieldErrorsWithoutReachingTheConfirmToken(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $invalid = "Taro\xff";

        foreach (['confirm' => true, 'submit' => false] as $step => $confirm) {
            $this->logger->records = [];
            $definition = FormDefinition::create('entry')
                ->text('name', 'Name')
                ->text('email', 'Email', [new Required()])
                ->checkboxes('topics', 'Topics', ['news' => 'News'])
                ->add(new RuntimeTestDateField('birth', 'Birth date', minYear: 2000, maxYear: 2026))
                ->confirm($confirm)
                ->onComplete($this->recordCompletion(...));
            $form = $this->form($definition);

            $result = $form->handle(new FormRequest('POST', [
                Form::FIELD_FORM_ID => 'entry',
                Form::FIELD_NONCE => 'ok',
                Form::FIELD_STEP => $step,
                Form::FIELD_SUBMIT_TOKEN => SubmitToken::issue('entry'),
                'name' => $invalid,
                'email' => 'taro@example.com',
                'topics' => ['news', $invalid],
                'birth' => ['year' => '2026', 'month' => $invalid, 'day' => '1'],
            ]));

            self::assertSame(FormStep::Input, $result->step, $step);
            self::assertSame(
                [
                    'name' => ['Name contains characters that cannot be used.'],
                    'topics' => ['Topics contains characters that cannot be used.'],
                    'birth' => ['Birth date contains characters that cannot be used.'],
                ],
                $result->fieldErrors,
                $step,
            );
            self::assertSame('', $result->values['name']);
            self::assertSame([], $result->values['topics']);
            self::assertSame('taro@example.com', $result->values['email']);
            self::assertStringNotContainsString(Form::FIELD_CONFIRM_TOKEN, $form->open());
            self::assertSame(
                [['notice', 'tmswp.form: the request to form {form} has malformed input (invalid UTF-8 or a malformed file upload) in: {fields}', 'entry']],
                $this->logRecords(),
            );
            self::assertSame(['form' => 'entry', 'fields' => 'name, topics, birth'], $this->logger->records[0]['context']);
        }

        // ほかの項目がすべて検証を通っても、不正な UTF-8 の値を確認トークンの JSON に入れない(JsonException で 500 にしない)
        $definition = FormDefinition::create('entry')->textarea('message', 'Message')->confirm();
        $result = $this->form($definition)->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
            'message' => "\xC3\x28",
        ]));

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame(['message' => ['Message contains characters that cannot be used.']], $result->fieldErrors);
        self::assertSame([], $this->completed);
    }

    public function testOpenUsesTheGivenActionAndRejectsMethodAndEnctype(): void
    {
        $this->stubRuntimeFunctions();
        Functions\when('esc_url')->alias(static fn(string $url): string => htmlspecialchars($url, ENT_QUOTES, 'UTF-8'));
        $text = new Form(FormDefinition::create('contact')->text('name', 'Name'));
        $upload = new Form(FormDefinition::create('entry')->file('resume', 'Resume'));

        self::assertStringStartsWith('<form method="post" action="/contact/" class="contact-form">', $text->open(['action' => '/contact/', 'class' => 'contact-form']));
        self::assertStringStartsWith('<form method="post" action="">', $text->open());
        self::assertStringStartsWith('<form method="post" action="" enctype="multipart/form-data">', $upload->open());

        foreach (['method' => 'get', 'METHOD' => 'post', 'enctype' => 'multipart/form-data'] as $name => $value) {
            try {
                $upload->open([$name => $value]);
                self::fail("open() accepted {$name}");
            } catch (FormConfigException $exception) {
                self::assertStringContainsString(strtolower($name), $exception->getMessage());
            }
        }
    }

    public function testPasswordIsNotRedisplayedAfterAValidationError(): void
    {
        $this->stubRuntimeFunctions();
        Functions\when('esc_html')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        $definition = FormDefinition::create('login')
            ->text('user', 'User', [new Required()])
            ->password('pass', 'Password', [new Required()])
            ->onComplete($this->recordCompletion(...));
        $form = new Form($definition);

        $result = $form->handle($this->directSubmit('login', ['user' => '', 'pass' => 's3cret<']));

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame('s3cret<', $result->values['pass']);
        self::assertSame('<input type="password" name="pass" value="" id="login-pass">', $form->field('pass')->input());
        self::assertArrayNotHasKey('pass', $form->confirmFields());
    }

    public function testConfirmFieldsLeaveOutHiddenPasswordAndHiddenOnConfirmFields(): void
    {
        $this->stubRuntimeFunctions();
        $definition = FormDefinition::create('contact')
            ->hidden('source', default: 'top')
            ->text('email', 'Email')
            ->text('email_confirm', 'Email (again)')
            ->checkbox('agree', 'Privacy policy', checkedLabel: 'Agreed')
            ->add(new RuntimeTestDateField('birth', 'Birth date'))
            ->hideOnConfirm('email_confirm')
            ->confirm();
        $form = new Form($definition);

        self::assertSame(['email', 'agree', 'birth'], array_keys($form->confirmFields()));
        self::assertSame(['source', 'email', 'email_confirm', 'agree', 'birth'], array_keys($form->fields()));
        self::assertSame(['email', 'agree', 'birth'], array_keys($definition->confirmFields()));
        self::assertSame([], array_keys((new Form(FormDefinition::create('login')->password('pass', 'Password')))->confirmFields()));
    }

    /**
     * 確認画面は confirmFields() を回して label() と display() で出す(docs/05)。複合フィールドのビューも part() なしで
     * 両方を呼べる。
     */
    public function testConfirmFieldsWithACompositeFieldRenderLabelAndDisplay(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        Functions\when('esc_html')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        $definition = FormDefinition::create('entry')
            ->text('name', 'Name', [new Required()])
            ->add(new RuntimeTestDateField('birth', 'Birth <date>', minYear: 2000, maxYear: 2026))
            ->confirm()
            ->onComplete($this->recordCompletion(...));
        $form = $this->form($definition);

        $result = $form->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
            'name' => 'Taro',
            'birth' => ['year' => '2026', 'month' => '6', 'day' => '13'],
        ]));

        self::assertSame(FormStep::Confirm, $result->step);

        $rows = [];

        foreach ($form->confirmFields() as $name => $view) {
            $rows[$name] = [$view->label(), $view->display()];
        }

        self::assertSame(['name' => ['Name', 'Taro'], 'birth' => ['Birth &lt;date&gt;', '2026-06-13']], $rows);
    }

    /**
     * 複合フィールドの部品のパスワードも、部品の display()・親の display()・confirmFields() のどこにも出さない。部品の
     * ビューの内部の field は複合フィールドの親のままのため、部品の実際の型で判定する。
     */
    public function testPasswordPartOfACompositeFieldIsNeverDisplayed(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        Functions\when('esc_html')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        $definition = FormDefinition::create('login')
            ->text('note', 'Note')
            ->add(new RuntimeTestCredentialField('login', 'Login', [new Required()]))
            ->onComplete($this->recordCompletion(...));
        $form = $this->form($definition);

        $result = $form->handle($this->directSubmit('login', ['note' => 'hello', 'login' => ['user' => 'taro', 'pass' => 's3cret']]));

        self::assertSame(FormStep::Complete, $result->step);
        self::assertSame([['note' => 'hello', 'login' => 'taro:s3cret']], $this->completed);
        self::assertSame(['note'], array_keys($form->confirmFields()));
        self::assertSame(['note'], array_keys($definition->confirmFields()));
        self::assertSame('<input type="password" name="login[pass]" value="" id="login-login-pass">', $form->field('login')->part('pass')->input());
        self::assertSame('taro', $form->field('login')->part('user')->value());

        $renders = [
            'display() of the password part' => static fn(): string => $form->field('login')->part('pass')->display(),
            'display() of the composite field' => static fn(): string => $form->field('login')->display(),
            'hidden() of the password part' => static fn(): string => $form->field('login')->part('pass')->hidden(),
        ];

        foreach ($renders as $case => $render) {
            try {
                $html = $render();
                self::fail("{$case} rendered: {$html}");
            } catch (FormConfigException $exception) {
                self::assertStringContainsString('password', $exception->getMessage(), $case);
                self::assertStringNotContainsString('s3cret', $exception->getMessage(), $case);
            }
        }
    }

    public function testJtiStoreReportsClaimDuplicateAndDatabaseErrors(): void
    {
        $store = new JtiStore();

        self::assertSame(JtiClaim::Claimed, $store->claim('0123456789abcdef0123456789abcdef', 60));
        self::assertSame(JtiClaim::Duplicate, $store->claim('0123456789abcdef0123456789abcdef', 60));

        $store->release('0123456789abcdef0123456789abcdef');
        self::assertSame(JtiClaim::Claimed, $store->claim('0123456789abcdef0123456789abcdef', 60));

        foreach ([
            'claim' => static fn(): mixed => $store->claim('fedcba9876543210fedcba9876543210', 60),
            'release' => static function () use ($store): void {
                $store->release('0123456789abcdef0123456789abcdef');
            },
            'gc' => static fn(): mixed => $store->gc(),
        ] as $operation => $call) {
            $this->wpdb->failNextWrite('Deadlock found');

            try {
                $call();
                self::fail("{$operation} did not throw on a database error");
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('Deadlock found', $exception->getMessage(), $operation);
            }
        }

        // データベースのエラーで失敗した claim は、獲得したことにならない
        self::assertSame(JtiClaim::Claimed, $store->claim('fedcba9876543210fedcba9876543210', 60));
    }

    public function testJtiStoreCollectsGarbageBeforeClaimingSoAFailedCollectionClaimsNothing(): void
    {
        $store = new JtiStore();
        // mt_srand(111) の最初の mt_rand(1, 100) は 1 になり、claim の前の確率的な掃除が動く
        mt_srand(111);
        $this->wpdb->failNextWrite('Deadlock found');

        try {
            $store->claim('0123456789abcdef0123456789abcdef', 60);
            self::fail('the failure of gc() was not thrown');
        } catch (RuntimeException $exception) {
            self::assertSame('failed to delete expired form submission IDs: Deadlock found', $exception->getMessage());
        } finally {
            mt_srand();
        }

        self::assertSame(JtiClaim::Claimed, $store->claim('0123456789abcdef0123456789abcdef', 60));
    }

    public function testJtiStoreRejectsMalformedSubmissionIds(): void
    {
        $this->expectException(FormConfigException::class);

        (new JtiStore())->claim('../not-a-jti', 60);
    }

    public function testJtiStoreDatabaseErrorIsThrownInDebug(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: true);
        $definition = FormDefinition::create('contact')->text('name', 'Name')->onComplete($this->recordCompletion(...));
        $this->wpdb->failNextWrite('Lost connection');

        try {
            $this->form($definition)->handle($this->directSubmit('contact', ['name' => 'Taro']));
            self::fail('the database error was not thrown');
        } catch (RuntimeException $exception) {
            // claim の前の確率的な掃除(gc)が失敗を受けることもあるため、データベースのエラーの内容で確かめる
            self::assertStringContainsString('Lost connection', $exception->getMessage());
        }

        self::assertSame([], $this->completed);
    }

    public function testJtiStoreDatabaseErrorShowsGenericErrorAndIsLoggedInProduction(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $definition = FormDefinition::create('contact')->text('name', 'Name')->onComplete($this->recordCompletion(...));
        $request = $this->directSubmit('contact', ['name' => 'Taro']);
        $this->wpdb->failNextWrite('Lost connection');

        $result = $this->form($definition)->handle($request);

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame('Taro', $result->values['name']);
        self::assertSame([self::SEND_FAILED], $result->formErrors);
        self::assertSame([], $this->completed);
        self::assertSame([['error', 'tmswp.form: failed to claim the submission ID of form {form}', 'contact']], $this->logRecords());
        self::assertSame(RuntimeException::class, $this->logger->records[0]['context']['exceptionClass']);
        self::assertArrayNotHasKey('exception', $this->logger->records[0]['context']);
        // 再送とは扱わず、同じトークンで送り直せる
        self::assertSame(FormStep::Complete, $this->form($definition)->handle($request)->step);
    }

    /**
     * 確認画面からの送信で一時ファイルが消えていたときの、jti が claim 済みかの確認(JtiStore::isClaimed())の
     * データベースのエラー。本番は Kernel のロガーに error で記録して汎用の送信失敗エラーを出し、jti を claim しない
     * (ファイルが戻れば同じトークンで送信できる)。
     */
    public function testDatabaseErrorOnLookingUpTheJtiOfMissingFilesIsLoggedInProduction(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $storeDir = $this->createTempDirectory('lookup-error-store');
        [$definition, $request] = $this->submissionWithMissingFile($storeDir);
        $this->wpdb->failNextRead('Lost connection');

        $result = $this->form($definition)->handle($request);

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame([self::SEND_FAILED], $result->formErrors);
        self::assertSame('Taro', $result->values['name']);
        self::assertSame([], $this->completed);
        self::assertSame([['error', 'tmswp.form: failed to look up the submission ID of form {form}', 'entry']], $this->logRecords());
        self::assertSame(RuntimeException::class, $this->logger->records[0]['context']['exceptionClass']);
        self::assertArrayNotHasKey('exception', $this->logger->records[0]['context']);
        self::assertSame([], array_filter($this->wpdb->queries(), static fn(string $query): bool => str_starts_with($query, 'INSERT')));

        $this->createStoredFile($storeDir, '0123456789abcdef0123456789abcdef', 'resume.pdf', 'application/pdf', 'body');

        self::assertSame(FormStep::Complete, $this->form($definition)->handle($request)->step);
        self::assertCount(1, $this->completed);
    }

    public function testDatabaseErrorOnLookingUpTheJtiOfMissingFilesIsThrownInDebug(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: true);
        [$definition, $request] = $this->submissionWithMissingFile($this->createTempDirectory('lookup-error-debug-store'));
        $this->wpdb->failNextRead('Lost connection');

        try {
            $this->form($definition)->handle($request);
            self::fail('the database error was not thrown');
        } catch (RuntimeException $exception) {
            self::assertSame('failed to look up the form submission ID: Lost connection', $exception->getMessage());
        }

        self::assertSame([], $this->completed);
        self::assertSame([], $this->logRecords());
    }

    public function testOnCompleteExceptionIsThrownInDebugWithoutConsumingTheJti(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: true);
        $this->failOnComplete = true;
        $definition = FormDefinition::create('contact')
            ->text('name', 'Name')
            ->onComplete(function (array $values): void {
                if ($this->failOnComplete) {
                    throw new PhpRuntimeException('mail server is down');
                }

                $this->completed[] = $values;
            });
        $request = $this->directSubmit('contact', ['name' => 'Taro']);

        try {
            $this->form($definition)->handle($request);
            self::fail('the exception of onComplete was not thrown');
        } catch (PhpRuntimeException $exception) {
            self::assertSame('mail server is down', $exception->getMessage());
        }

        $this->failOnComplete = false;

        self::assertSame(FormStep::Complete, $this->form($definition)->handle($request)->step);
        self::assertSame([['name' => 'Taro']], $this->completed);
    }

    public function testOnCompleteExceptionIsLoggedAndCanBeResubmittedInProduction(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $this->failOnComplete = true;
        $definition = FormDefinition::create('entry')
            ->text('name', 'Name')
            ->add(new RuntimeTestDateField('birth', 'Birth date'))
            ->confirm()
            ->onComplete(function (array $values): void {
                if ($this->failOnComplete) {
                    throw new PhpLogicException('bug in onComplete');
                }

                $this->completed[] = $values;
            });
        $request = new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => ConfirmToken::issue('entry', ['birth' => '2026-06-13', 'name' => 'Taro']),
        ]);

        $failed = $this->form($definition)->handle($request);

        self::assertSame(FormStep::Input, $failed->step);
        self::assertSame([self::SEND_FAILED], $failed->formErrors);
        self::assertSame(['name' => 'Taro', 'birth' => ['year' => '2026', 'month' => '6', 'day' => '13']], $failed->values);
        self::assertSame([['error', 'tmswp.form: onComplete of form {form} threw an exception', 'entry']], $this->logRecords());
        self::assertSame(PhpLogicException::class, $this->logger->records[0]['context']['exceptionClass']);

        $this->failOnComplete = false;

        self::assertSame(FormStep::Complete, $this->form($definition)->handle($request)->step);
        self::assertSame([['name' => 'Taro', 'birth' => '2026-06-13']], $this->completed);
    }

    public function testFailedReleaseAfterOnCompleteExceptionIsLoggedAndTheTokenStaysConsumed(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $definition = FormDefinition::create('contact')
            ->text('name', 'Name')
            ->onComplete(function (): void {
                // claim の後の次の書き込み(jti の手放し)を失敗させる
                $this->wpdb->failNextWrite('Lost connection');

                throw new PhpRuntimeException('mail server is down');
            });
        $request = $this->directSubmit('contact', ['name' => 'Taro']);

        $result = $this->form($definition)->handle($request);

        self::assertSame([self::SEND_FAILED], $result->formErrors);
        self::assertSame(
            [
                ['error', 'tmswp.form: failed to release the submission ID of form {form}; the same token cannot be resubmitted', 'contact'],
                ['error', 'tmswp.form: onComplete of form {form} threw an exception', 'contact'],
            ],
            $this->logRecords(),
        );
        self::assertSame(RuntimeException::class, $this->logger->records[0]['context']['exceptionClass']);
        self::assertSame(PhpRuntimeException::class, $this->logger->records[1]['context']['exceptionClass']);
        self::assertSame(['This form has already been submitted.'], $this->form($definition)->handle($request)->formErrors);
    }

    public function testLogOfOnCompleteExceptionHasNeitherInputValuesNorTheExceptionItself(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $definition = FormDefinition::create('contact')
            ->text('email', 'Email')
            ->onComplete(function (array $values): void {
                $email = \is_string($values['email']) ? $values['email'] : '';
                $exception = new PhpRuntimeException("failed to send mail to {$email}", 0, new PhpLogicException("smtp rejected {$email}"));
                $this->thrownAt = $exception->getFile() . ':' . $exception->getLine();

                throw $exception;
            });

        $this->form($definition)->handle($this->directSubmit('contact', ['email' => 'secret.user@example.com']));

        self::assertCount(1, $this->logger->records);
        self::assertSame('tmswp.form: onComplete of form {form} threw an exception', $this->logger->records[0]['message']);
        // 例外のクラス名と、投げた場所だけを記録する(メッセージ・previous のメッセージ・トレース・例外のオブジェクトは無い)
        self::assertSame(
            [
                'form' => 'contact',
                'exceptionClass' => PhpRuntimeException::class,
                'exceptionAt' => $this->thrownAt,
                'previousClasses' => [PhpLogicException::class],
            ],
            $this->logger->records[0]['context'],
        );
        self::assertStringNotContainsString('secret.user@example.com', serialize($this->logger->records));
    }

    public function testReleaseFailureOfFormBelongingToNoKernelKeepsTheOriginalException(): void
    {
        $this->stubRuntimeFunctions();
        $definition = FormDefinition::create('contact')
            ->text('name', 'Name')
            ->onComplete(function (): void {
                // claim の後の次の書き込み(jti の手放し)を失敗させる
                $this->wpdb->failNextWrite('Lost connection');

                throw new PhpRuntimeException('mail server is down');
            });

        try {
            // テストのファイルはどの Kernel のルートにも含まれない
            (new Form($definition))->handle($this->directSubmit('contact', ['name' => 'Taro']));
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertInstanceOf(PhpRuntimeException::class, $exception->getPrevious());
            self::assertSame('mail server is down', $exception->getPrevious()->getMessage());
        }
    }

    public function testOnCompleteExceptionKeepsTheOriginalExceptionWhenTheFormBelongsToNoKernel(): void
    {
        $this->stubRuntimeFunctions();
        $definition = FormDefinition::create('contact')
            ->text('name', 'Name')
            ->onComplete(static function (): void {
                throw new PhpRuntimeException('mail server is down');
            });

        try {
            // テストのファイルはどの Kernel のルートにも含まれない
            (new Form($definition))->handle($this->directSubmit('contact', ['name' => 'Taro']));
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertInstanceOf(PhpRuntimeException::class, $exception->getPrevious());
            self::assertSame('mail server is down', $exception->getPrevious()->getMessage());
        }
    }

    /**
     * POSTで送る名前がWordPressの公開のクエリ変数とぶつかる項目は、handle()とopen()のどちらでも、要求の種類
     * (GET・POST)に関係なく設定の誤りの例外にする。WordPressは公開のクエリ変数のPOSTの値を読み、送信を別のページの
     * 表示や404にするため。メッセージには、ぶつかった名前と、別の名前の案内を入れる。
     *
     * @param Closure(Form): mixed $call
     */
    #[DataProvider('formEntryPoints')]
    public function testFieldNamedLikeAPublicQueryVarIsAConfigurationError(Closure $call): void
    {
        $this->stubRuntimeFunctions();
        self::stubFrontRequest(self::WORDPRESS_PUBLIC_QUERY_VARS);

        foreach (['name', 's'] as $name) {
            $form = new Form(FormDefinition::create('contact')->text('email', 'Email')->text($name, 'Field'));

            try {
                $call($form);
                self::fail("項目 {$name} が例外になりません");
            } catch (FormConfigException $exception) {
                self::assertSame(
                    "form 'contact' posts '{$name}', the same name as a public query var of WordPress. WordPress reads public query vars from POST values, so submitting the form shows another page or a 404; rename the field (for example, 'contact_{$name}' instead of '{$name}')",
                    $exception->getMessage(),
                );
            }
        }
    }

    /** @return iterable<string, array{Closure(Form): mixed}> フォームが公開のクエリ変数との照合を行う入口 */
    public static function formEntryPoints(): iterable
    {
        yield 'handle() of GET' => [static fn(Form $form): FormResult => $form->handle(new FormRequest('GET'))];
        yield 'handle() of POST' => [static fn(Form $form): FormResult => $form->handle(new FormRequest('POST', [Form::FIELD_FORM_ID => 'contact', 'email' => 'a@example.com']))];
        yield 'open()' => [static fn(Form $form): string => $form->open()];
    }

    /**
     * 投稿タイプ・タクソノミーのquery_var(登録で$wp->public_query_varsに入る)と、query_varsフィルタで足した名前も
     * 公開のクエリ変数として照らす。ぶつかる名前は、すべてメッセージに並べる。
     */
    public function testQueryVarsOfPostTypesAndTheQueryVarsFilterAreChecked(): void
    {
        $this->stubRuntimeFunctions();
        self::stubFrontRequest([...self::WORDPRESS_PUBLIC_QUERY_VARS, 'product']);
        Filters\expectApplied('query_vars')->andReturnUsing(static fn(array $vars): array => [...$vars, 'campaign']);
        $form = new Form(FormDefinition::create('order-form')
            ->text('product', 'Product')
            ->text('full_name', 'Full name')
            ->select('campaign', 'Campaign', ['spring' => 'Spring']));

        $this->expectException(FormConfigException::class);
        $this->expectExceptionMessage("form 'order-form' posts 'product', 'campaign', the same name as a public query var of WordPress. WordPress reads public query vars from POST values, so submitting the form shows another page or a 404; rename the field (for example, 'order_form_product' instead of 'product')");

        $form->open();
    }

    /**
     * ぶつからない名前(full_name等)のフォームは、そのまま処理する。ファイルの項目は、本体を$_FILESで送り、
     * WordPressは読まないため、POSTで送る{name}__tokenだけを照らす(attachmentは公開のクエリ変数でも使える)。
     */
    public function testFieldNamesThatDoNotPostAPublicQueryVarPass(): void
    {
        $this->stubRuntimeFunctions();
        self::stubFrontRequest(self::WORDPRESS_PUBLIC_QUERY_VARS);
        $definition = FormDefinition::create('contact')
            ->text('full_name', 'Full name', [new Required()])
            ->add(new RuntimeTestDateField('birth', 'Birth date'))
            ->file('attachment', 'Attachment');
        $form = new Form($definition);

        self::assertSame(FormStep::Input, $form->handle(new FormRequest('GET'))->step);
        self::assertStringStartsWith('<form method="post"', $form->open());

        Filters\expectApplied('query_vars')->andReturnUsing(static fn(array $vars): array => [...$vars, 'attachment__token']);
        $this->expectException(FormConfigException::class);
        $this->expectExceptionMessage("form 'contact' posts 'attachment__token', the same name as a public query var of WordPress");

        (new Form($definition))->open();
    }

    /**
     * 管理画面(admin-ajax.phpを含む)は要求の解析をせず、POSTの値をクエリ変数として読まないため、照らさない
     * ($wpが無くても例外にしない)。
     */
    public function testAdminRequestsDoNotCheckTheQueryVars(): void
    {
        $this->stubRuntimeFunctions();
        unset($GLOBALS['wp']);
        Functions\when('is_admin')->justReturn(true);
        $form = new Form(FormDefinition::create('contact')->text('name', 'Name'));

        self::assertSame(FormStep::Input, $form->handle(new FormRequest('GET'))->step);
        self::assertStringStartsWith('<form method="post"', $form->open());
    }

    /** フロントの要求でWordPressの$wpが無いと、照らせないため例外にする(照らさずに通さない) */
    public function testMissingWpIsAConfigurationErrorOnTheFront(): void
    {
        $this->stubRuntimeFunctions();
        unset($GLOBALS['wp']);

        $this->expectException(FormConfigException::class);
        $this->expectExceptionMessage("cannot check the field names of form 'contact' against the public query vars of WordPress: the global \$wp is not a WP instance");

        (new Form(FormDefinition::create('contact')->text('full_name', 'Full name')))->handle(new FormRequest('GET'));
    }

    public function testHandleAndOpenExcludeThePageFromPageCaches(): void
    {
        self::stubFrontRequest([]);
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\when('wp_salt')->justReturn('secret');
        Functions\when('esc_attr')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        Functions\expect('nocache_headers')->twice();
        $form = new Form(FormDefinition::create('contact')->text('name', 'Name'));

        $form->handle(new FormRequest('GET'));
        $form->open();

        self::assertTrue(\defined('DONOTCACHEPAGE'));
    }

    #[RunInSeparateProcess]
    public function testHandleDefinesDoNotCachePage(): void
    {
        self::stubFrontRequest([]);
        Functions\when('nocache_headers')->justReturn();

        self::assertFalse(\defined('DONOTCACHEPAGE'));

        (new Form(FormDefinition::create('contact')->text('name', 'Name')))->handle(new FormRequest('GET'));

        self::assertTrue(\constant('DONOTCACHEPAGE'));
    }

    #[RunInSeparateProcess]
    public function testOpenDefinesDoNotCachePageWithoutHandle(): void
    {
        $this->stubRuntimeFunctions();

        self::assertFalse(\defined('DONOTCACHEPAGE'));

        (new Form(FormDefinition::create('contact')->text('name', 'Name')))->open();

        self::assertTrue(\constant('DONOTCACHEPAGE'));
    }

    public function testSpamGuardsAreRenderedOnInputAndCheckedBeforeValidation(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $guard = new RuntimeTestSpamGuard();
        $definition = FormDefinition::create('contact')
            ->text('name', 'Name', [new Required()])
            ->spamGuard($guard)
            ->onComplete($this->recordCompletion(...));

        self::assertStringContainsString('<input type="text" name="contact_hp" value="">', $this->form($definition)->open());

        // CSRF と送信トークンの検証の後に検査する
        $invalidToken = $this->directSubmit('contact', ['name' => 'Taro', Form::FIELD_SUBMIT_TOKEN => 'tampered']);
        self::assertSame(['Your session has expired. Please submit the form again.'], $this->form($definition)->handle($invalidToken)->formErrors);
        Functions\when('wp_verify_nonce')->justReturn(false);
        self::assertSame(['Your session has expired. Please submit the form again.'], $this->form($definition)->handle($this->directSubmit('contact', ['name' => 'Taro']))->formErrors);
        Functions\when('wp_verify_nonce')->justReturn(1);
        self::assertSame([], $guard->checked);
        $this->logger->records = [];

        $guard->pass = false;
        $rejected = $this->form($definition)->handle($this->directSubmit('contact', ['name' => '']));

        self::assertSame(FormStep::Input, $rejected->step);
        self::assertSame(['Your submission could not be verified. Please try again.'], $rejected->formErrors);
        // 値の検証より前に拒否する
        self::assertSame([], $rejected->fieldErrors);
        self::assertSame([['notice', 'tmswp.form: spam guard {guard} rejected a submission of form {form}', 'contact']], $this->logRecords());
        self::assertSame(RuntimeTestSpamGuard::class, $this->logger->records[0]['context']['guard']);

        $guard->pass = true;

        self::assertSame(FormStep::Complete, $this->form($definition)->handle($this->directSubmit('contact', ['name' => 'Taro']))->step);
        self::assertSame([['name' => 'Taro']], $this->completed);
        self::assertCount(2, $guard->checked);
    }

    public function testSpamGuardsAreCheckedOnConfirmAndNotRenderedOnConfirmationScreen(): void
    {
        $this->stubRuntimeFunctions();
        $this->bootKernel(debug: false);
        $guard = new RuntimeTestSpamGuard();
        $definition = FormDefinition::create('entry')
            ->text('name', 'Name')
            ->spamGuard($guard)
            ->confirm()
            ->onComplete($this->recordCompletion(...));
        $guard->pass = false;
        $rejected = $this->form($definition)->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
            'name' => 'Taro',
        ]));

        self::assertSame(FormStep::Input, $rejected->step);
        self::assertSame(['Your submission could not be verified. Please try again.'], $rejected->formErrors);
        self::assertSame('Taro', $rejected->values['name']);

        $guard->pass = true;
        $confirm = $this->form($definition);

        self::assertSame(FormStep::Confirm, $confirm->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'confirm',
            'name' => 'Taro',
        ]))->step);
        $html = $confirm->open();

        self::assertStringNotContainsString('contact_hp', $html);

        // 確認画面からの送信では検査しない(確認の時点で通っている)
        $guard->pass = false;
        $submit = $this->form($definition)->handle(new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => $this->hiddenValue($html, Form::FIELD_CONFIRM_TOKEN),
        ]));

        self::assertSame(FormStep::Complete, $submit->step);
        self::assertCount(2, $guard->checked);
    }

    public function testApplyDefaultsReplacesDefaultsInTheShapeEachFieldAccepts(): void
    {
        $this->stubRuntimeFunctions();
        $form = new Form(FormDefinition::create('settings')
            ->text('title', 'Title', default: 'Default')
            ->checkboxes('topics', 'Topics', ['news' => 'News', 'docs' => 'Docs'])
            ->add(new RuntimeTestDateField('birth', 'Birth date')));

        $form->applyDefaults(['title' => 'Saved', 'topics' => ['docs'], 'birth' => '2026-06-13']);

        self::assertSame(
            ['title' => 'Saved', 'topics' => ['docs'], 'birth' => ['year' => '2026', 'month' => '6', 'day' => '13']],
            $form->handle(new FormRequest('GET'))->values,
        );
    }

    public function testApplyDefaultsAfterHandleIsRejected(): void
    {
        $this->stubRuntimeFunctions();
        $form = new Form(FormDefinition::create('settings')->text('title', 'Title'));
        $form->handle(new FormRequest('GET'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("applyDefaults() of form 'settings' must be called before handle()");

        $form->applyDefaults(['title' => 'Saved']);
    }

    public function testApplyDefaultsRejectsUnknownFields(): void
    {
        $form = new Form(FormDefinition::create('settings')->text('title', 'Title'));

        $this->expectException(UnknownFieldException::class);

        $form->applyDefaults(['missing' => 'x']);
    }

    public function testApplyDefaultsRejectsValuesOfTheWrongShapeAndFileFields(): void
    {
        $definition = FormDefinition::create('settings')
            ->text('title', 'Title')
            ->checkboxes('topics', 'Topics', ['news' => 'News'])
            ->file('resume', 'Resume');
        $cases = [
            "default value of field 'title' must be a string, int given" => ['title' => 5],
            "default value of field 'title' must be a string, array given" => ['title' => ['a']],
            "default value of field 'topics' must be a list of strings, string given" => ['topics' => 'news'],
            "default value of field 'topics' must be a list of strings, an item is int" => ['topics' => [1]],
            "file field 'resume' cannot have a default value" => ['resume' => ''],
        ];

        foreach ($cases as $message => $values) {
            try {
                (new Form($definition))->applyDefaults($values);
                self::fail("FormConfigException was not thrown: {$message}");
            } catch (FormConfigException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    /** 実際のアップロードの代わりに、一時ディレクトリのファイルを $_FILES のファイルとして扱う */
    /**
     * libmagic が読む環境変数 MAGIC を読めないデータベースに向けて、finfo の初期化を失敗させた状態で $run を実行する。
     *
     * @template T
     *
     * @param Closure(): T $run
     *
     * @return T
     */
    private function withUnreadableMagicDatabase(Closure $run): mixed
    {
        $magic = getenv('MAGIC');
        putenv('MAGIC=' . $this->createTempDirectory('missing-magic') . '/magic.mgc');

        try {
            return $run();
        } finally {
            putenv($magic === false ? 'MAGIC' : "MAGIC={$magic}");
        }
    }

    private function stubUploads(): void
    {
        Functions\when('TMSWP\\Core\\Form\\is_uploaded_file')->alias(static fn(string $path): bool => is_file($path));
        Functions\when('TMSWP\\Core\\Form\\move_uploaded_file')->alias(static fn(string $from, string $to): bool => rename($from, $to));
        Functions\when('sanitize_file_name')->returnArg();
        // 期限切れのファイルを消す WP-Cron のイベントはスケジュール済み
        Functions\when('wp_next_scheduled')->justReturn(1);
    }

    /** @return array{name: string, type: string, tmp_name: string, error: int, size: int} $_FILES の1つのエントリ */
    private function upload(string $name, string $contents): array
    {
        $path = $this->createTempDirectory('upload') . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $contents);

        return ['name' => $name, 'type' => 'text/plain', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => \strlen($contents)];
    }

    /**
     * 確認画面の無いフォームの送信の POST(ファイルつき)。
     *
     * @param array<string, array<string, mixed>> $files
     * @param array<string, string> $post
     */
    private function fileSubmit(string $formId, string $submitToken, array $files, array $post = []): FormRequest
    {
        return new FormRequest('POST', [
            Form::FIELD_FORM_ID => $formId,
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_SUBMIT_TOKEN => $submitToken,
            ...$post,
        ], $files);
    }

    private function confirmedSubmit(string $confirmToken): FormRequest
    {
        return new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => $confirmToken,
        ]);
    }

    /** @return list<string> 一時保管に残っているトークンのディレクトリ */
    private function tokenDirectories(string $storeDir): array
    {
        $dirs = glob($storeDir . DIRECTORY_SEPARATOR . str_repeat('[0-9a-f]', 32), GLOB_ONLYDIR);
        self::assertIsArray($dirs);

        return $dirs;
    }

    /**
     * Kernel を boot し、boot が TempFileGc で登録した WP-Cron のフックと Kernel の無効化のフックのコールバックを返す。
     * 2回目の register() では登録しないことも確かめる。
     *
     * @return array{tmswp_form_temp_file_gc: Closure, 'tmswp/deactivated': Closure}
     */
    private function captureGarbageCollection(bool $debug): array
    {
        $callbacks = [];

        foreach ([TempFileGc::HOOK, 'tmswp/deactivated'] as $hook) {
            Actions\expectAdded($hook)->once()->whenHappen(static function (Closure $callback) use (&$callbacks, $hook): void {
                $callbacks[$hook] = $callback;
            });
        }

        $this->stubRuntimeFunctions();
        $this->bootKernel($debug);
        // Kernel の boot が登録する
        $gc = $callbacks[TempFileGc::HOOK] ?? null;
        $deactivated = $callbacks['tmswp/deactivated'] ?? null;
        self::assertInstanceOf(Closure::class, $gc);
        self::assertInstanceOf(Closure::class, $deactivated);

        // 2回目の register()(別の Kernel の boot)では登録しない(expectAdded()->once())
        TempFileGc::register(array_values(Kernel::all())[0]);

        return [TempFileGc::HOOK => $gc, 'tmswp/deactivated' => $deactivated];
    }

    /** @return string 期限切れのトークンのディレクトリ */
    private function createExpiredTemporaryFile(string $storeDir): string
    {
        $token = 'abcdefabcdefabcdefabcdefabcdefab';
        $this->createStoredFile($storeDir, $token, 'old.txt', 'text/plain', 'old');
        $dir = $storeDir . DIRECTORY_SEPARATOR . $token;
        file_put_contents($dir . '/' . TempFileStore::META_NAME, json_encode(['name' => 'old.txt', 'mime' => 'text/plain', 'size' => 3, 'created' => time() - 86401], JSON_THROW_ON_ERROR));

        return $dir;
    }

    /**
     * ディレクトリを読み取り専用にして $callback を呼ぶ(中のファイルの削除が失敗する)。失敗の警告はテストの失敗にしない。
     */
    private function withReadOnly(string $dir, Closure $callback): void
    {
        self::assertTrue(chmod($dir, 0500));
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            $callback();
        } finally {
            restore_error_handler();
            chmod($dir, 0700);
        }
    }

    /**
     * 一時ファイルが消えた後の、確認画面からの送信(ファイルのトークンは 0123456789abcdef0123456789abcdef)。
     *
     * @return array{FormDefinition, FormRequest}
     */
    private function submissionWithMissingFile(string $storeDir): array
    {
        $definition = FormDefinition::create('entry')
            ->text('name', 'Name')
            ->file('resume', 'Resume')
            ->confirm()
            ->tempFileStore(new TempFileStore($storeDir))
            ->onComplete($this->recordCompletion(...));
        $request = new FormRequest('POST', [
            Form::FIELD_FORM_ID => 'entry',
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => ConfirmToken::issue('entry', ['name' => 'Taro', 'resume' => ['__tmsfile' => '0123456789abcdef0123456789abcdef']]),
        ]);

        return [$definition, $request];
    }

    /** @param array<string, string> $choices */
    private function revalidationDefinition(int $maxLength, array $choices): FormDefinition
    {
        return FormDefinition::create('entry')
            ->text('name', 'Name', [new Required(), new MaxLength($maxLength)])
            ->checkboxes('topics', 'Topics', $choices)
            ->add(new RuntimeTestDateField('birth', 'Birth date', [new Required()], minYear: 2000, maxYear: 2026))
            ->confirm()
            ->onComplete($this->recordCompletion(...));
    }

    private function stubRuntimeFunctions(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\when('wp_salt')->justReturn('secret');
        Functions\when('nocache_headers')->justReturn();
        Functions\when('esc_attr')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        self::stubFrontRequest([]);
    }

    /**
     * フロントの要求(is_admin()がfalse)のWordPressの$wpを、指定した公開のクエリ変数で用意する。Formはhandle()・
     * open()で、POSTで送る名前をこれと照らす。
     *
     * @param list<string> $publicQueryVars
     */
    private static function stubFrontRequest(array $publicQueryVars): void
    {
        Functions\when('is_admin')->justReturn(false);
        $wp = Mockery::mock(WP::class);
        self::assertInstanceOf(WP::class, $wp);
        $wp->public_query_vars = $publicQueryVars;
        $GLOBALS['wp'] = $wp;
    }

    /**
     * 一時ディレクトリをルートとするテーマの Kernel を、記録を残すロガーで boot する。form() は、そのルートの中の
     * ファイルで `new Form()` を書いたフォームを作る(Form は作ったファイルで Kernel を決める)。
     */
    private function bootKernel(bool $debug): void
    {
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
        Functions\when('get_template_directory')->justReturn(sys_get_temp_dir());
        Functions\when('get_stylesheet_directory')->justReturn(sys_get_temp_dir());
        $root = $this->createTempDirectory('form-kernel');

        if (!mkdir($root . '/app')) {
            self::fail("ディレクトリを作成できません: {$root}/app");
        }

        $logger = $this->logger;
        $config = KernelConfig::theme(
            $root . '/app',
            'Example\Tests\FormRuntime' . uniqid(),
            $debug,
            logChannels: static fn(): array => ['test' => new LogChannel($logger)],
        );
        // 本番の boot は探索結果ファイルだけを読む(コンポーネントの無い app)
        (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
        Kernel::boot($config);
        $factoryFile = $root . '/parts/form-factory.php';
        mkdir(\dirname($factoryFile));
        file_put_contents($factoryFile, <<<'PHP'
<?php

declare(strict_types=1);

return static fn(\TMSWP\Core\Form\FormDefinition $definition): \TMSWP\Core\Form\Form => new \TMSWP\Core\Form\Form($definition);
PHP);
        $factory = require $factoryFile;
        self::assertInstanceOf(Closure::class, $factory);
        $this->formFactory = static function (FormDefinition $definition) use ($factory): Form {
            $form = $factory($definition);
            self::assertInstanceOf(Form::class, $form);

            return $form;
        };
    }

    private function form(FormDefinition $definition): Form
    {
        if ($this->formFactory === null) {
            self::fail('bootKernel() must be called before form()');
        }

        return ($this->formFactory)($definition);
    }

    /** @param array<string, mixed> $values */
    private function directSubmit(string $formId, array $values): FormRequest
    {
        return new FormRequest('POST', [
            Form::FIELD_FORM_ID => $formId,
            Form::FIELD_NONCE => 'ok',
            Form::FIELD_STEP => 'submit',
            Form::FIELD_SUBMIT_TOKEN => SubmitToken::issue($formId),
            ...$values,
        ]);
    }

    /** @param array<string, mixed> $values */
    private function recordCompletion(array $values): void
    {
        $this->completed[] = $values;
    }

    /** @return list<array{mixed, string, mixed}> レベル・メッセージ・フォームの ID */
    private function logRecords(): array
    {
        return array_map(
            static fn(array $record): array => [$record['level'], $record['message'], $record['context']['form'] ?? null],
            $this->logger->records,
        );
    }

    private function hiddenValue(string $html, string $name): string
    {
        if (preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]+)"/', $html, $matches) !== 1) {
            self::fail("hidden フィールド {$name} が見つかりません。");
        }

        return $matches[1];
    }

    private function createStoredFile(string $storeDir, string $token, string $name, string $mime, string $contents): void
    {
        $dir = $storeDir . DIRECTORY_SEPARATOR . $token;

        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("一時ファイルディレクトリを作成できません: {$dir}");
        }

        file_put_contents($dir . DIRECTORY_SEPARATOR . TempFileStore::FILE_NAME, $contents);
        file_put_contents($dir . '/meta.json', json_encode([
            'created' => time(),
            'mime' => $mime,
            'name' => $name,
            'size' => \strlen($contents),
        ], JSON_THROW_ON_ERROR));
    }
}

final class RuntimeTestSpamGuard implements SpamGuardInterface
{
    public bool $pass = true;

    /** @var list<FormRequest> */
    public array $checked = [];

    public function render(string $formId): string
    {
        return '<input type="text" name="' . $formId . '_hp" value="">';
    }

    public function passes(FormRequest $request): bool
    {
        $this->checked[] = $request;

        return $this->pass;
    }
}

final class RuntimeTestDateField extends CompositeField
{
    /** @param list<\TMSWP\Core\Form\Rule\RuleInterface> $rules */
    public function __construct(
        string $name,
        string $label,
        array $rules = [],
        private readonly int $minYear = 1940,
        private readonly ?int $maxYear = null,
    ) {
        parent::__construct($name, $label, $rules);
    }

    public function parts(): array
    {
        return [
            'year' => Field::select($this->name . '_year', 'Year', $this->yearChoices()),
            'month' => Field::select($this->name . '_month', 'Month', $this->rangeChoices(1, 12)),
            'day' => Field::select($this->name . '_day', 'Day', $this->rangeChoices(1, 31)),
        ];
    }

    public function compose(array $partValues): string
    {
        return \sprintf('%04d-%02d-%02d', (int) $partValues['year'], (int) $partValues['month'], (int) $partValues['day']);
    }

    public function decompose(string $value): array
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $matches) !== 1) {
            return ['year' => '', 'month' => '', 'day' => ''];
        }

        return ['year' => (string) (int) $matches[1], 'month' => (string) (int) $matches[2], 'day' => (string) (int) $matches[3]];
    }

    public function validateParts(array $partValues): bool
    {
        return checkdate((int) $partValues['month'], (int) $partValues['day'], (int) $partValues['year']);
    }

    /** @return array<array-key, string> */
    private function yearChoices(): array
    {
        $choices = ['' => '----'];
        $maxYear = $this->maxYear ?? (int) date('Y');

        for ($year = $maxYear; $year >= $this->minYear; --$year) {
            $choices[(string) $year] = (string) $year;
        }

        return $choices;
    }

    /** @return array<array-key, string> */
    private function rangeChoices(int $min, int $max): array
    {
        $choices = ['' => '--'];

        for ($value = $min; $value <= $max; ++$value) {
            $choices[(string) $value] = (string) $value;
        }

        return $choices;
    }
}

final class RuntimeTestCredentialField extends CompositeField
{
    public function parts(): array
    {
        return [
            'user' => Field::text($this->name . '_user', 'User'),
            'pass' => Field::password($this->name . '_pass', 'Password'),
        ];
    }

    public function compose(array $partValues): string
    {
        return ($partValues['user'] ?? '') . ':' . ($partValues['pass'] ?? '');
    }

    public function decompose(string $value): array
    {
        [$user, $pass] = array_pad(explode(':', $value, 2), 2, '');

        return ['user' => $user, 'pass' => $pass];
    }
}

/** メタデータのファイルだけを削除できない vfsStream のディレクトリ(削除の途中の失敗を作る) */
final class RuntimeTestMetadataUndeletableDirectory extends vfsStreamDirectory
{
    public function removeChild($name): bool
    {
        if ($name === TempFileStore::META_NAME) {
            return false;
        }

        return parent::removeChild($name);
    }
}
