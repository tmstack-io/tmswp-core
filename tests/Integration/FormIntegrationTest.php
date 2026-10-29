<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use Closure;
use RuntimeException as PhpRuntimeException;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Core\Form\ConfirmToken;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormRequest;
use TMSWP\Core\Form\FormResult;
use TMSWP\Core\Form\FormStep;
use TMSWP\Core\Form\JtiClaim;
use TMSWP\Core\Form\JtiStore;
use TMSWP\Core\Form\Rule\Callback;
use TMSWP\Core\Form\Rule\Choice;
use TMSWP\Core\Form\Rule\MaxFileSize;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\Form\Rule\RuleInterface;
use TMSWP\Core\Form\StoredFile;
use TMSWP\Core\Form\TempFileStore;
use TMSWP\Core\Form\Validator;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Testing\BootsKernelBeforeInit;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UsesTempDirectories;

/**
 * フォームの二重送信の防止(実際の nonce と、wp_options への jti の claim)、onComplete の例外の扱い、
 * ページキャッシュからの除外を、実物の WordPress で確かめる。
 */
final class FormIntegrationTest extends IntegrationTestCase
{
    use BootsKernelBeforeInit;
    use UsesTempDirectories;

    private const string JTI = '0123456789abcdef0123456789abcdef';

    private RecordingLogger $logger;

    /** @var list<array<string, mixed>> */
    private array $completed = [];

    /** onComplete に例外を投げさせるか */
    private bool $failOnComplete = false;

    public function set_up(): void
    {
        parent::set_up();
        $this->logger = new RecordingLogger();
        $this->completed = [];
        $this->failOnComplete = false;
    }

    public function tear_down(): void
    {
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    /**
     * POSTで送る名前が、その要求の時点の公開のクエリ変数(WordPressの既定のname・s、登録した投稿タイプのquery_var、
     * query_varsフィルタで足した名前)とぶつかる項目は、実物の$wpで照らして設定の誤りの例外にする。WordPressは
     * 公開のクエリ変数のPOSTの値を読み、送信を別のページの表示や404にするため。ぶつからないfull_nameは通す。
     */
    public function testFieldNamedLikeAPublicQueryVarIsAConfigurationErrorOnTheFront(): void
    {
        $addCampaign = static fn(array $vars): array => [...$vars, 'campaign'];
        register_post_type('tmswp_qv_probe', ['public' => true, 'query_var' => 'tmswp_qv_probe']);
        add_filter('query_vars', $addCampaign);

        try {
            foreach (['name', 's', 'tmswp_qv_probe', 'campaign'] as $name) {
                $form = new Form(FormDefinition::create('contact')->text('full_name', 'Full name')->text($name, 'Field'));

                try {
                    $form->open();
                    self::fail("項目 {$name} が例外になりません");
                } catch (FormConfigException $exception) {
                    self::assertStringStartsWith("form 'contact' posts '{$name}', the same name as a public query var of WordPress.", $exception->getMessage());
                    self::assertStringEndsWith("rename the field (for example, 'contact_{$name}' instead of '{$name}')", $exception->getMessage());
                }
            }
        } finally {
            remove_filter('query_vars', $addCampaign);
            unregister_post_type('tmswp_qv_probe');
        }

        // 投稿タイプの登録の解除とフィルタの除去で公開のクエリ変数から外れた名前は、次の照合で通る
        $form = new Form(FormDefinition::create('contact')->text('full_name', 'Full name')->text('tmswp_qv_probe', 'Probe')->text('campaign', 'Campaign'));

        self::assertSame(FormStep::Input, $form->handle(new FormRequest('GET'))->step);
        self::assertStringContainsString('name="_form_id"', $form->open());
    }

    /**
     * 管理画面は要求の解析をせず、POSTの値をクエリ変数として読まないため、照らさない(設定ページのフォームは、
     * 公開のクエリ変数と同じ名前の項目を使える)。
     */
    public function testAdminScreenDoesNotCheckTheQueryVars(): void
    {
        set_current_screen('options-general');

        try {
            self::assertTrue(is_admin());
            $form = new Form(FormDefinition::create('settings')->text('name', 'Name')->text('year', 'Year'));

            self::assertSame(FormStep::Input, $form->handle(new FormRequest('GET'))->step);
            self::assertStringContainsString('name="name"', $form->open() . $form->field('name')->input());
        } finally {
            unset($GLOBALS['current_screen']);
        }

        self::assertFalse(is_admin());
    }

    public function testReloadAndDoubleClickOfFormWithoutConfirmationRunOnCompleteOnce(): void
    {
        $form = $this->formFactory(debug: false);
        $definition = $this->contactDefinition();
        $post = $this->submitRequest($form($definition)->open(), ['full_name' => 'Taro']);

        self::assertSame(FormStep::Complete, $form($definition)->handle($post)->step);

        foreach (['reload', 'double click'] as $case) {
            $result = $form($definition)->handle($post);

            self::assertSame(FormStep::Input, $result->step, $case);
            self::assertSame(['This form has already been submitted.'], $result->formErrors, $case);
        }

        self::assertSame([['full_name' => 'Taro']], $this->completed);
        self::assertSame(
            ['notice', 'notice'],
            array_map(static fn(array $record): mixed => $record['level'], $this->logger->records),
        );
    }

    /**
     * 実際の nonce の検証(wp_verify_nonce())。別のフォームの nonce・別の利用者の nonce・形の合わない値は CSRF の失敗として
     * 入力画面に戻し、onComplete を実行しない。open() が出力した nonce だけが通る。
     */
    public function testRealNonceCheckRejectsNoncesOfOtherFormsOtherUsersAndForgedValues(): void
    {
        $form = $this->formFactory(debug: false);
        $definition = $this->contactDefinition();
        $hidden = $this->hiddenValues($form($definition)->open());
        $otherUser = self::factory()->user->create();
        self::assertIsInt($otherUser);
        $previousUser = get_current_user_id();
        wp_set_current_user($otherUser);
        $otherUsersNonce = wp_create_nonce('tmswp_form_contact');
        wp_set_current_user($previousUser);

        foreach ([
            'nonce of another form' => wp_create_nonce('tmswp_form_other'),
            'nonce of another user' => $otherUsersNonce,
            'forged value' => 'abcdef1234',
            'missing' => '',
        ] as $case => $nonce) {
            $this->logger->records = [];
            $result = $form($definition)->handle(new FormRequest('POST', [...$hidden, Form::FIELD_NONCE => $nonce, Form::FIELD_STEP => 'submit', 'full_name' => 'Taro']));

            self::assertSame(FormStep::Input, $result->step, $case);
            self::assertSame(['Your session has expired. Please submit the form again.'], $result->formErrors, $case);
            self::assertSame('Taro', $result->values['full_name'], $case);
            self::assertSame(['warning'], array_column($this->logger->records, 'level'), $case);
            self::assertSame('tmswp.form: the CSRF check (nonce) of form {form} failed', $this->logger->records[0]['message'], $case);
        }

        self::assertSame([], $this->completed);
        self::assertSame(FormStep::Complete, $form($definition)->handle($this->submitRequest($form($definition)->open(), ['full_name' => 'Taro']))->step);
        self::assertSame([['full_name' => 'Taro']], $this->completed);
    }

    /**
     * 二重クリックの2本目が、1本目の onComplete の実行中に届く(並行した同じ jti の POST)。実際のデータベースの
     * INSERT IGNORE で2本目の claim が失敗し、再送として拒否される。確認画面の有無の両方で確かめる。
     */
    public function testConcurrentSecondRequestIsRejectedByTheRealDatabaseWhileTheFirstRunsOnComplete(): void
    {
        $form = $this->formFactory(debug: false);

        foreach (['without confirmation' => false, 'with confirmation' => true] as $case => $confirm) {
            $this->completed = [];
            $definition = FormDefinition::create('contact')->text('full_name', 'Name')->confirm($confirm);
            $submit = $this->submitRequestFor($form, $definition, ['full_name' => 'Taro']);
            // 1本目の onComplete の中で処理した2本目の結果
            $concurrent = [];
            $definition->onComplete(function (array $values) use (&$concurrent, $form, $definition, $submit): void {
                $this->completed[] = $values;

                // 2本目が onComplete に進んでも、さらに次の POST は送らない
                if (\count($this->completed) === 1) {
                    $concurrent[] = $form($definition)->handle($submit);
                }
            });

            self::assertSame(FormStep::Complete, $form($definition)->handle($submit)->step, $case);
            self::assertCount(1, $concurrent, $case);
            self::assertSame(FormStep::Input, $concurrent[0]->step, $case);
            self::assertSame(['This form has already been submitted.'], $concurrent[0]->formErrors, $case);
            self::assertSame([['full_name' => 'Taro']], $this->completed, $case);
        }
    }

    /**
     * チェックを外した checkbox・checkboxes は POST にキーが無い。スーパーグローバルからの取り込みで、空値の hidden を
     * 置かなくても '' と [] になる(既定値に戻らない)。確認画面の有無の両方で確かめる。
     */
    public function testUncheckedCheckboxesWithoutPostKeysAreBoundAsEmptyValues(): void
    {
        $form = $this->formFactory(debug: false);

        foreach (['without confirmation' => false, 'with confirmation' => true] as $case => $confirm) {
            $this->completed = [];
            $definition = FormDefinition::create('consent')
                ->checkbox('agree', 'Agree')
                ->checkboxes('topics', 'Topics', ['news' => 'News', 'docs' => 'Docs'], default: ['news'])
                ->confirm($confirm)
                ->onComplete(function (array $values): void {
                    $this->completed[] = $values;
                });

            $result = $this->handleGlobals($form($definition), [
                ...$this->hiddenValues($form($definition)->open()),
                Form::FIELD_STEP => $confirm ? 'confirm' : 'submit',
            ]);

            self::assertSame($confirm ? FormStep::Confirm : FormStep::Complete, $result->step, $case);
            self::assertSame(['agree' => '', 'topics' => []], $result->values, $case);
            self::assertSame($confirm ? [] : [['agree' => '', 'topics' => []]], $this->completed, $case);
        }
    }

    /**
     * 同じページの2つのフォーム。POST は宛先(_form_id)のフォームだけが処理し、もう一方は同じ名前の項目の値も
     * 取り込まず、エラーの無い入力画面のまま(実際の nonce で確かめる)。
     */
    public function testTwoFormsOnTheSamePageProcessOnlyTheFormThePostIsAddressedTo(): void
    {
        $form = $this->formFactory(debug: false);
        $newsletterCompleted = [];
        $newsletter = FormDefinition::create('newsletter')
            ->text('email', 'Email', [new Required()])
            ->text('full_name', 'Name', default: 'Subscriber')
            ->onComplete(static function (array $values) use (&$newsletterCompleted): void {
                $newsletterCompleted[] = $values;
            });
        $contact = $this->contactDefinition();
        // 同じページで2つのフォームを描画し、利用者は contact を送る
        $form($newsletter)->open();
        $post = $this->submitRequest($form($contact)->open(), ['full_name' => 'Taro', 'email' => 'taro@example.com']);

        $newsletterResult = $form($newsletter)->handle($post);
        $contactResult = $form($contact)->handle($post);

        self::assertSame(FormStep::Input, $newsletterResult->step);
        self::assertSame(['email' => '', 'full_name' => 'Subscriber'], $newsletterResult->values);
        self::assertSame([], $newsletterResult->fieldErrors);
        self::assertSame([], $newsletterResult->formErrors);
        self::assertSame([], $newsletterCompleted);
        self::assertSame(FormStep::Complete, $contactResult->step);
        self::assertSame([['full_name' => 'Taro']], $this->completed);
        self::assertSame([], $this->logger->records);
    }

    public function testResubmittingTheConfirmTokenRunsOnCompleteOnce(): void
    {
        $form = $this->formFactory(debug: false);
        $definition = $this->contactDefinition()->confirm();
        $confirm = $form($definition);
        $confirm->handle($this->confirmRequest($form($definition)->open(), ['full_name' => 'Taro']));
        $submit = $this->submitRequest($confirm->open());

        self::assertSame(FormStep::Complete, $form($definition)->handle($submit)->step);
        self::assertSame(['This form has already been submitted.'], $form($definition)->handle($submit)->formErrors);
        self::assertSame([['full_name' => 'Taro']], $this->completed);
    }

    public function testConfirmTokenValuesThatNoLongerPassTheRulesAreRejectedWithoutConsumingTheJti(): void
    {
        $form = $this->formFactory(debug: false);
        $before = $this->contactDefinition()->confirm();
        $submit = $this->submitRequestFor($form, $before, ['full_name' => 'Taro Yamada']);
        // 確認の後のデプロイで MaxLength を足した
        $after = FormDefinition::create('contact')
            ->text('full_name', 'Name', [new Required(), new MaxLength(5)])
            ->confirm()
            ->onComplete(function (array $values): void {
                $this->completed[] = $values;
            });

        $result = $form($after)->handle($submit);

        self::assertSame(FormStep::Input, $result->step);
        self::assertArrayHasKey('full_name', $result->fieldErrors);
        self::assertSame([], $this->completed);
        // jti を claim していない(wp_options に行が無い)
        $wpdb = self::wpdb();
        self::assertNull($wpdb->get_var($wpdb->prepare('SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like('tmswp_form_jti_') . '%')));

        self::assertSame(FormStep::Complete, $form($before)->handle($submit)->step);
        self::assertSame([['full_name' => 'Taro Yamada']], $this->completed);
    }

    public function testSingleCheckboxRejectsValuesOtherThanItsCheckedValueThroughTheRealRuleMessageFilter(): void
    {
        $form = $this->formFactory(debug: false);
        $filter = static fn(string $message, RuleInterface $rule): string => $rule instanceof Choice ? 'Please choose {label} properly.' : $message;
        add_filter('tmswp/form/rule_message', $filter, 10, 2);

        try {
            $definition = FormDefinition::create('consent')
                ->checkbox('privacy', 'Privacy policy', 'agree', [new Required()])
                ->text('note', 'Note', [new Callback(static fn(mixed $value): bool => false)])
                ->confirm()
                ->onComplete(function (array $values): void {
                    $this->completed[] = $values;
                });
            $result = $form($definition)->handle($this->confirmRequest($form($definition)->open(), ['privacy' => 'any string', 'note' => 'text']));
        } finally {
            remove_filter('tmswp/form/rule_message', $filter, 10);
        }

        // 任意の文字列は Required を通っても確認画面に進まない
        self::assertSame(FormStep::Input, $result->step);
        self::assertSame(['privacy' => ['Please choose Privacy policy properly.'], 'note' => ['Note is invalid.']], $result->fieldErrors);
        self::assertSame([], $this->completed);
    }

    public function testMaxFileSizeMessageShowsTheSizeFormattedByWordPress(): void
    {
        $definition = FormDefinition::create('upload')->file('photo', 'Photo', [MaxFileSize::mb(1)]);
        $file = new StoredFile(self::JTI, '/tmp/photo.jpg', 'photo.jpg', 'image/jpeg', 2 * 1024 * 1024);

        self::assertSame(['photo' => ['Photo must be 1 MB or smaller.']], (new Validator())->validate($definition, ['photo' => $file]));
    }

    public function testOnCompleteExceptionInProductionIsLoggedAndTheSameTokenCanBeResubmitted(): void
    {
        $form = $this->formFactory(debug: false);

        foreach ([false, true] as $confirmEnabled) {
            $this->logger->records = [];
            $definition = $this->contactDefinition()->confirm($confirmEnabled);
            $submit = $this->submitRequestFor($form, $definition, ['full_name' => 'Taro']);
            $this->failOnComplete = true;

            $failed = $form($definition)->handle($submit);

            self::assertSame(FormStep::Input, $failed->step);
            self::assertSame(['The form could not be sent. Please try again.'], $failed->formErrors);
            self::assertSame('Taro', $failed->values['full_name']);
            self::assertCount(1, $this->logger->records);
            self::assertSame('error', $this->logger->records[0]['level']);
            self::assertSame('tmswp.form: onComplete of form {form} threw an exception', $this->logger->records[0]['message']);
            self::assertSame(PhpRuntimeException::class, $this->logger->records[0]['context']['exceptionClass'] ?? null);
            self::assertArrayNotHasKey('exception', $this->logger->records[0]['context']);

            // jti は消費されていないので、同じトークンで再送できる
            $this->failOnComplete = false;

            self::assertSame(FormStep::Complete, $form($definition)->handle($submit)->step);
        }

        self::assertSame([['full_name' => 'Taro'], ['full_name' => 'Taro']], $this->completed);
    }

    public function testOnCompleteExceptionInDebugIsThrownAndTheSameTokenCanBeResubmitted(): void
    {
        $form = $this->formFactory(debug: true);
        $definition = $this->contactDefinition();
        $submit = $this->submitRequestFor($form, $definition, ['full_name' => 'Taro']);
        $this->failOnComplete = true;

        try {
            $form($definition)->handle($submit);
            self::fail('the exception of onComplete was not thrown');
        } catch (PhpRuntimeException $exception) {
            self::assertSame('mail server is down', $exception->getMessage());
        }

        $this->failOnComplete = false;

        self::assertSame(FormStep::Complete, $form($definition)->handle($submit)->step);
        self::assertSame([['full_name' => 'Taro']], $this->completed);
    }

    /**
     * 受け入れ条件3: 利用者の入力(数値のキー、配列のファイル、不正な UTF-8、未知のトークンの項目)で致命的エラーに
     * ならない。スーパーグローバルから FormRequest::fromGlobals() で取り込む実際の経路で、確認画面の有無の両方を確かめる。
     */
    public function testUserInputOfAnyShapeDoesNotCauseAFatalError(): void
    {
        $form = $this->formFactory(debug: false);

        foreach ([false, true] as $confirmEnabled) {
            $definition = $this->entryDefinition()->confirm($confirmEnabled);
            $step = $confirmEnabled ? 'confirm' : 'submit';

            // 数値のキーと、<input type="file" name="resume[]" multiple> の形のファイル
            $result = $this->handleGlobals($form($definition), [
                0 => 'numeric key',
                '7' => 'numeric string key',
                Form::FIELD_STEP => $step,
                'full_name' => 'Taro',
            ] + $this->hiddenValues($form($definition)->open()), ['resume' => [
                'name' => ['a.pdf', 'b.pdf'],
                'type' => ['application/pdf', 'application/pdf'],
                'tmp_name' => ['/tmp/phpA', '/tmp/phpB'],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'size' => [1, 1],
            ]]);

            self::assertSame(FormStep::Input, $result->step);
            self::assertSame(['resume' => ['The file upload failed.']], $result->fieldErrors);
            self::assertSame('Taro', $result->values['full_name']);

            // error の無い、不正な形のファイルのエントリ
            $result = $this->handleGlobals($form($definition), [
                ...$this->hiddenValues($form($definition)->open()),
                Form::FIELD_STEP => $step,
                'full_name' => 'Taro',
            ], ['resume' => ['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/phpA', 'size' => 1]]);

            self::assertSame(FormStep::Input, $result->step);
            self::assertSame(['resume' => ['The file upload failed.']], $result->fieldErrors);

            // 不正な UTF-8
            $result = $this->handleGlobals($form($definition), [
                ...$this->hiddenValues($form($definition)->open()),
                Form::FIELD_STEP => $step,
                'full_name' => "Taro\xC3\x28",
            ]);

            self::assertSame(FormStep::Input, $result->step);
            self::assertSame(['full_name' => ['Name contains characters that cannot be used.']], $result->fieldErrors);
            self::assertSame('', $result->values['full_name']);

            // 未知のトークンの項目と、一時保管のトークンの形に合わない値
            $result = $this->handleGlobals($form($definition), [
                ...$this->hiddenValues($form($definition)->open()),
                Form::FIELD_STEP => $step,
                'full_name' => 'Taro',
                'resume__token' => '../../wp-config',
                'unknown__token' => '0123456789abcdef0123456789abcdef',
            ]);

            self::assertSame($confirmEnabled ? FormStep::Confirm : FormStep::Complete, $result->step);
            self::assertSame('', $result->values['resume']);
        }

        // 署名済みの確認トークンに、フォームに無い項目がある(デプロイをまたいだ送信)
        $definition = $this->entryDefinition()->confirm();
        $result = $this->handleGlobals($form($definition), [
            ...$this->hiddenValues($form($definition)->open()),
            Form::FIELD_STEP => 'submit',
            Form::FIELD_CONFIRM_TOKEN => ConfirmToken::issue('entry', ['full_name' => 'Taro', 'resume' => '', 'unknown' => 'value']),
        ]);

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame(['The confirmation screen has expired. Please review your entries and continue again.'], $result->formErrors);
        self::assertSame([['full_name' => 'Taro', 'resume' => '']], $this->completed);
    }

    public function testJtiStoreClaimsReleasesAndCollectsOnTheRealDatabase(): void
    {
        $store = new JtiStore();
        $wpdb = self::wpdb();

        self::assertFalse($store->isClaimed(self::JTI));
        self::assertSame(JtiClaim::Claimed, $store->claim(self::JTI, 60));
        self::assertTrue($store->isClaimed(self::JTI));
        self::assertSame(JtiClaim::Duplicate, $store->claim(self::JTI, 60));
        // alloptions のキャッシュを汚さない
        self::assertSame('off', $wpdb->get_var($wpdb->prepare('SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, 'tmswp_form_jti_' . self::JTI)));

        $store->release(self::JTI);

        self::assertFalse($store->isClaimed(self::JTI));
        self::assertSame(JtiClaim::Claimed, $store->claim(self::JTI, 60));

        // 期限切れの jti だけを消す
        self::assertSame(JtiClaim::Claimed, $store->claim('fedcba9876543210fedcba9876543210', -10));
        self::assertGreaterThanOrEqual(1, $store->gc());
        self::assertSame(JtiClaim::Duplicate, $store->claim(self::JTI, 60));
        self::assertSame(JtiClaim::Claimed, $store->claim('fedcba9876543210fedcba9876543210', 60));
    }

    public function testJtiStoreDatabaseErrorIsAnException(): void
    {
        $wpdb = self::wpdb();
        $options = $wpdb->options;
        $suppress = $wpdb->suppress_errors(true);
        // 存在しないテーブルへのクエリで、データベースのエラーを起こす
        $wpdb->options = $wpdb->prefix . 'tmswp_no_such_table';

        try {
            // claim()はmt_rand(1, 100)が1のときだけ、挿入の前にgc()を走らせる。乱数の種を決めて、どちらの順になるかを
            // 決める(種1の最初の値は46、種111の最初の値は1)
            $calls = [
                ['failed to claim the form submission ID: ', static function (): JtiClaim {
                    mt_srand(1);

                    return (new JtiStore())->claim(self::JTI, 60);
                }],
                ['failed to delete expired form submission IDs: ', static function (): JtiClaim {
                    mt_srand(111);

                    return (new JtiStore())->claim(self::JTI, 60);
                }],
                ['failed to look up the form submission ID: ', static fn(): bool => (new JtiStore())->isClaimed(self::JTI)],
                ['failed to release the form submission ID: ', static function (): void {
                    (new JtiStore())->release(self::JTI);
                }],
                ['failed to delete expired form submission IDs: ', static fn(): int => (new JtiStore())->gc()],
            ];

            foreach ($calls as [$message, $call]) {
                try {
                    $call();
                    self::fail("RuntimeException was not thrown: {$message}");
                } catch (RuntimeException $exception) {
                    self::assertStringStartsWith($message, $exception->getMessage());
                    self::assertStringContainsString('tmswp_no_such_table', $exception->getMessage());
                }
            }
        } finally {
            $wpdb->options = $options;
            $wpdb->suppress_errors($suppress);
            // 決めた乱数の種を、ほかのテストに残さない
            mt_srand();
        }
    }

    /**
     * PHP の CLI はヘッダを送らないため、wp-env の開発用サイトへ、フォームのあるページ(tests/fixtures/http/form-page.php)を
     * HTTP で取得して、nocache のヘッダと DONOTCACHEPAGE を確かめる。
     */
    public function testResponseOfPageWithFormHasNoCacheHeadersAndDoNotCachePage(): void
    {
        $response = wp_remote_get('http://wordpress/wp-content/plugins/tmswp/tests/fixtures/http/form-page.php');

        $this->assertNotWPError($response);
        self::assertSame(200, wp_remote_retrieve_response_code($response));
        self::assertStringContainsString('name="' . Form::FIELD_SUBMIT_TOKEN . '"', wp_remote_retrieve_body($response));
        self::assertSame('no-cache, must-revalidate, max-age=0, no-store, private', wp_remote_retrieve_header($response, 'cache-control'));
        self::assertSame('Wed, 11 Jan 1984 05:00:00 GMT', wp_remote_retrieve_header($response, 'expires'));
        self::assertSame('true', wp_remote_retrieve_header($response, 'x-tmswp-donotcachepage'));
    }

    private function contactDefinition(): FormDefinition
    {
        return FormDefinition::create('contact')
            ->text('full_name', 'Name', [new Required()])
            ->onComplete(function (array $values): void {
                if ($this->failOnComplete) {
                    throw new PhpRuntimeException('mail server is down');
                }

                $this->completed[] = $values;
            });
    }

    private function entryDefinition(): FormDefinition
    {
        return FormDefinition::create('entry')
            ->text('full_name', 'Name', [new Required()])
            ->file('resume', 'Resume')
            ->tempFileStore(new TempFileStore($this->createTempDirectory('integration-form-store')))
            ->onComplete(function (array $values): void {
                $this->completed[] = $values;
            });
    }

    /**
     * スーパーグローバルに POST を置いて、引数なしの handle()(FormRequest::fromGlobals())で処理する。
     * WordPress と同じく、$_POST にはスラッシュを付けた値を置く。
     *
     * @param array<array-key, mixed> $post
     * @param array<array-key, mixed> $files
     */
    private function handleGlobals(Form $form, array $post, array $files = []): FormResult
    {
        $globals = [$_SERVER, $_POST, $_FILES];

        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = wp_slash($post);
            $_FILES = $files;

            return $form->handle();
        } finally {
            [$_SERVER, $_POST, $_FILES] = $globals;
        }
    }

    /**
     * 一時ディレクトリをルートとするプラグインの Kernel を boot し、そのルートの中のファイルで `new Form()` を書いた
     * フォームを作る関数を返す(Form は作ったファイルで Kernel を決める)。
     *
     * @return Closure(FormDefinition): Form
     */
    private function formFactory(bool $debug): Closure
    {
        $root = $this->createTempDirectory('integration-form');
        self::assertTrue(mkdir($root . '/app'));
        $this->writeFile($root . '/plugin.php', "<?php\n");
        $logger = $this->logger;
        $config = KernelConfig::plugin(
            $root . '/plugin.php',
            $root . '/app',
            'TmswpIntegrationForm' . uniqid(),
            $debug,
            logChannels: static fn(): array => ['integration' => new LogChannel($logger)],
        );
        (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
        $this->bootBeforeInit($config);
        $this->writeFile($root . '/form-factory.php', <<<'PHP'
<?php

declare(strict_types=1);

return static fn(\TMSWP\Core\Form\FormDefinition $definition): \TMSWP\Core\Form\Form => new \TMSWP\Core\Form\Form($definition);
PHP);
        $factory = require $root . '/form-factory.php';
        self::assertInstanceOf(Closure::class, $factory);

        return static function (FormDefinition $definition) use ($factory): Form {
            $form = $factory($definition);
            self::assertInstanceOf(Form::class, $form);

            return $form;
        };
    }

    /**
     * 入力画面(確認画面ありなら、確認を通した後の確認画面)の送信の POST を作る。
     *
     * @param Closure(FormDefinition): Form $form
     * @param array<string, string> $values
     */
    private function submitRequestFor(Closure $form, FormDefinition $definition, array $values): FormRequest
    {
        if (!$definition->isConfirmEnabled()) {
            return $this->submitRequest($form($definition)->open(), $values);
        }

        $confirm = $form($definition);
        self::assertSame(FormStep::Confirm, $confirm->handle($this->confirmRequest($form($definition)->open(), $values))->step);

        return $this->submitRequest($confirm->open());
    }

    /** @param array<string, string> $values */
    private function confirmRequest(string $html, array $values): FormRequest
    {
        return new FormRequest('POST', [...$this->hiddenValues($html), Form::FIELD_STEP => 'confirm', ...$values]);
    }

    /** @param array<string, string> $values */
    private function submitRequest(string $html, array $values = []): FormRequest
    {
        return new FormRequest('POST', [...$this->hiddenValues($html), Form::FIELD_STEP => 'submit', ...$values]);
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

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            self::fail("ファイルを書き込めません: {$path}");
        }
    }
}
