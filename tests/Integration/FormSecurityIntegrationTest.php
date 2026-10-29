<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use Closure;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\ConfirmToken;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormRequest;
use TMSWP\Core\Form\FormStep;
use TMSWP\Core\Form\Rule\Callback;
use TMSWP\Core\Form\Rule\Choice;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\Form\Rule\MinLength;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\Form\Rule\Retype;
use TMSWP\Core\Form\Rule\RuleInterface;
use TMSWP\Core\Form\SubmitToken;
use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Testing\BootsKernelBeforeInit;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UsesTempDirectories;

/**
 * フォームの安全性(署名付きトークンの改ざん・期限切れ・用途の取り違え、公開のクエリ変数との衝突、出力のエスケープ、
 * メッセージのフィルタ)を、実物のWordPress(wp_salt('auth')・wp_verify_nonce()・esc_html()・esc_attr()・
 * apply_filters()・wp_optionsのjti)で確かめる。
 */
final class FormSecurityIntegrationTest extends IntegrationTestCase
{
    use BootsKernelBeforeInit;
    use UsesTempDirectories;

    private const string CONFIRM_INVALID = 'The form could not be resubmitted. Please start again.';

    private const string CONFIRM_EXPIRED = 'The confirmation screen has expired. Please review your entries and continue again.';

    private const string SESSION_EXPIRED = 'Your session has expired. Please submit the form again.';

    private const string SCRIPT = '"><script>alert(1)</script>';

    private const string SCRIPT_ESCAPED = '&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;';

    private RecordingLogger $logger;

    /** @var list<array<string, mixed>> */
    private array $completed = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->logger = new RecordingLogger();
        $this->completed = [];
    }

    public function tear_down(): void
    {
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    /**
     * docs/05の受け入れ基準「ConfirmTokenの改ざん(値1文字変更・署名差し替え・formId差し替え)がすべてInvalidになる」
     * 「`wp_salt('auth')`をそのまま鍵にした署名と、別の用途のトークン(送信トークン⇔確認トークン)がInvalidになる」
     * 「handle()の確認ありSubmit時のInvalid」「トークンの改ざんが§ログのとおり記録される」を、実物のwp_salt('auth')で
     * 署名した確認トークンと実DBのjtiで確かめる。どの改ざんも入力画面(既定値)に戻し、onCompleteを実行せず、jtiを
     * 消費せずにwarningを記録する。改ざんの前の確認トークンは、その後もそのまま送信できる。
     */
    public function testTamperedConfirmTokensSignedWithTheRealAuthSaltAreRejectedWithoutConsumingTheJti(): void
    {
        $form = $this->formFactory();
        $definition = $this->entryDefinition();
        $hidden = $this->confirmScreenHidden($form, $definition, ['full_name' => 'Taro']);
        $genuine = $hidden[Form::FIELD_CONFIRM_TOKEN];
        [$body, $signature] = explode('.', $genuine, 2);
        $json = self::base64UrlDecode($body);
        self::assertStringContainsString('"f":"entry"', $json);
        self::assertStringContainsString('"full_name":"Taro"', $json);
        // 実物のwp_salt('auth')から用途ごとに派生させた鍵で署名されている(この鍵の作り方で、下の改ざんを作る)
        self::assertSame($signature, hash_hmac('sha256', $body, self::derivedKey('confirm-token')));
        $otherHidden = $this->confirmScreenHidden($form, $definition, ['full_name' => 'Jiro']);
        [, $otherSignature] = explode('.', $otherHidden[Form::FIELD_CONFIRM_TOKEN], 2);

        $tokens = [
            'one character of a value' => self::base64UrlEncode(str_replace('"Taro"', '"Tara"', $json)) . '.' . $signature,
            'signature of another token' => $body . '.' . $otherSignature,
            'form ID in the payload' => self::base64UrlEncode(str_replace('"f":"entry"', '"f":"other"', $json)) . '.' . $signature,
            'token issued for another form' => ConfirmToken::issue('other', ['full_name' => 'Taro']),
            'signed with the raw auth salt' => $body . '.' . hash_hmac('sha256', $body, wp_salt('auth')),
            'signed with the key of submit tokens' => $body . '.' . hash_hmac('sha256', $body, self::derivedKey('submit-token')),
            'submit token' => SubmitToken::issue('entry'),
        ];

        foreach ($tokens as $case => $token) {
            $this->logger->records = [];
            $result = $form($definition)->handle(new FormRequest('POST', [...$hidden, Form::FIELD_STEP => 'submit', Form::FIELD_CONFIRM_TOKEN => $token]));

            self::assertSame(FormStep::Input, $result->step, $case);
            self::assertSame(['full_name' => ''], $result->values, $case);
            self::assertSame([self::CONFIRM_INVALID], $result->formErrors, $case);
            self::assertSame([[
                'level' => 'warning',
                'message' => 'tmswp.form: the confirm token of form {form} was rejected as invalid (malformed, tampered or issued for another form)',
                'context' => ['form' => 'entry'],
            ]], $this->logger->records, $case);
            self::assertSame(0, $this->claimedJtiCount(), $case);
        }

        self::assertSame([], $this->completed);
        self::assertSame(FormStep::Complete, $form($definition)->handle(new FormRequest('POST', [...$hidden, Form::FIELD_STEP => 'submit']))->step);
        self::assertSame([['full_name' => 'Taro']], $this->completed);
        self::assertSame(1, $this->claimedJtiCount());
    }

    /**
     * docs/05の受け入れ基準「handle()の確認ありSubmit時のExpired」を、実物のwp_salt('auth')で署名した期限切れの
     * 確認トークンで確かめる。署名済みの値を戻して確認からやり直させ、jtiを消費せず、記録しない(§ログの表に無い)。
     */
    public function testExpiredConfirmTokenRestoresTheSignedValuesWithoutConsumingTheJti(): void
    {
        $form = $this->formFactory();
        $definition = $this->entryDefinition()->confirmTtl(60);
        $hidden = $this->confirmScreenHidden($form, $definition, ['full_name' => 'Taro']);
        $expired = ConfirmToken::issue('entry', ['full_name' => 'Taro'], now: time() - 61);

        $result = $form($definition)->handle(new FormRequest('POST', [...$hidden, Form::FIELD_STEP => 'submit', Form::FIELD_CONFIRM_TOKEN => $expired]));

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame(['full_name' => 'Taro'], $result->values);
        self::assertSame([self::CONFIRM_EXPIRED], $result->formErrors);
        self::assertSame([], $this->logger->records);
        self::assertSame([], $this->completed);
        self::assertSame(0, $this->claimedJtiCount());
    }

    /**
     * docs/05の受け入れ基準「handle()の確認なしSubmit時の送信トークンのExpired・Invalid」「`wp_salt('auth')`をそのまま
     * 鍵にした署名と、別の用途のトークンがInvalidになる」「トークンの改ざんが§ログのとおり記録される」を、open()が
     * 実物のwp_salt('auth')で署名した送信トークンと実DBのjtiで確かめる。Invalidは入力値を残して入力画面に戻し、warningを
     * 記録する。Expiredは記録しない。どれもonCompleteを実行せず、jtiを消費しない。
     */
    public function testTamperedAndExpiredSubmitTokensAreRejectedWithoutConsumingTheJti(): void
    {
        $form = $this->formFactory();
        $definition = $this->contactDefinition();
        $hidden = $this->hiddenValues($form($definition)->open());
        $genuine = $hidden[Form::FIELD_SUBMIT_TOKEN];
        [$body, $signature] = explode('.', $genuine, 2);
        $json = self::base64UrlDecode($body);
        self::assertSame($signature, hash_hmac('sha256', $body, self::derivedKey('submit-token')));
        self::assertSame(1, preg_match('/"j":"([a-f0-9])/', $json, $match));
        $flippedJti = preg_replace('/"j":"[a-f0-9]/', '"j":"' . ($match[1] === 'a' ? 'b' : 'a'), $json, 1);
        self::assertIsString($flippedJti);
        [, $otherSignature] = explode('.', SubmitToken::issue('contact'), 2);

        $invalidTokens = [
            'one character of the jti' => self::base64UrlEncode($flippedJti) . '.' . $signature,
            'signature of another token' => $body . '.' . $otherSignature,
            'form ID in the payload' => self::base64UrlEncode(str_replace('"f":"contact"', '"f":"other"', $json)) . '.' . $signature,
            'token issued for another form' => SubmitToken::issue('other'),
            'signed with the raw auth salt' => $body . '.' . hash_hmac('sha256', $body, wp_salt('auth')),
            'signed with the key of confirm tokens' => $body . '.' . hash_hmac('sha256', $body, self::derivedKey('confirm-token')),
            'confirm token' => ConfirmToken::issue('contact', ['full_name' => 'Taro']),
        ];
        $cases = [...$invalidTokens, 'expired' => SubmitToken::issue('contact', now: time() - SubmitToken::TTL - 1)];

        foreach ($cases as $case => $token) {
            $this->logger->records = [];
            $result = $form($definition)->handle(new FormRequest('POST', [
                ...$hidden,
                Form::FIELD_STEP => 'submit',
                Form::FIELD_SUBMIT_TOKEN => $token,
                'full_name' => 'Taro',
            ]));

            self::assertSame(FormStep::Input, $result->step, $case);
            self::assertSame(['full_name' => 'Taro'], $result->values, $case);
            self::assertSame([self::SESSION_EXPIRED], $result->formErrors, $case);
            self::assertSame($case === 'expired' ? [] : [[
                'level' => 'warning',
                'message' => 'tmswp.form: the submit token of form {form} was rejected as invalid (missing, malformed, tampered or issued for another form)',
                'context' => ['form' => 'contact'],
            ]], $this->logger->records, $case);
            self::assertSame(0, $this->claimedJtiCount(), $case);
        }

        self::assertSame([], $this->completed);
        self::assertSame(FormStep::Complete, $form($definition)->handle(new FormRequest('POST', [...$hidden, Form::FIELD_STEP => 'submit', 'full_name' => 'Taro']))->step);
        self::assertSame([['full_name' => 'Taro']], $this->completed);
    }

    /**
     * docs/05の受け入れ基準「handle()のBack時のValid・Invalid」「トークンの改ざんが§ログのとおり記録される」を、実物の
     * wp_salt('auth')で署名した確認トークンで確かめる。Validは署名済みの値を入力画面に戻し、jtiを消費しない(同じ
     * 確認トークンをその後も送信できる)。Invalidは既定値で入力画面に戻し、戻る操作のwarningを記録する。
     */
    public function testBackRestoresTheSignedValuesWithAValidTokenAndRejectsATamperedToken(): void
    {
        $form = $this->formFactory();
        $definition = $this->entryDefinition();
        $hidden = $this->confirmScreenHidden($form, $definition, ['full_name' => 'Taro']);
        [$body, $signature] = explode('.', $hidden[Form::FIELD_CONFIRM_TOKEN], 2);
        $tampered = self::base64UrlEncode(str_replace('"Taro"', '"Tara"', self::base64UrlDecode($body))) . '.' . $signature;

        $back = $form($definition)->handle(new FormRequest('POST', [...$hidden, Form::FIELD_STEP => 'back']));

        self::assertSame(FormStep::Input, $back->step);
        self::assertSame(['full_name' => 'Taro'], $back->values);
        self::assertSame([], $back->formErrors);
        self::assertSame([], $back->fieldErrors);
        self::assertSame([], $this->logger->records);
        self::assertSame(0, $this->claimedJtiCount());

        $invalid = $form($definition)->handle(new FormRequest('POST', [...$hidden, Form::FIELD_STEP => 'back', Form::FIELD_CONFIRM_TOKEN => $tampered]));

        self::assertSame(FormStep::Input, $invalid->step);
        self::assertSame(['full_name' => ''], $invalid->values);
        self::assertSame([self::CONFIRM_INVALID], $invalid->formErrors);
        self::assertSame([[
            'level' => 'warning',
            'message' => 'tmswp.form: the confirm token of form {form} was rejected as invalid on going back (malformed, tampered or issued for another form)',
            'context' => ['form' => 'entry'],
        ]], $this->logger->records);

        self::assertSame(FormStep::Complete, $form($definition)->handle(new FormRequest('POST', [...$hidden, Form::FIELD_STEP => 'submit']))->step);
        self::assertSame([['full_name' => 'Taro']], $this->completed);
    }

    /**
     * docs/05の受け入れ基準「POSTで送る項目の名前がWordPressの公開のクエリ変数とぶつかると、フロントの`handle()`が
     * `FormConfigException`になる」を、実物の$wpとquery_varsフィルタで、GETとPOSTの両方のhandle()について確かめる
     * (open()はFormIntegrationTestが確かめる)。POSTは、実物のnonceを付けた、そのフォーム宛ての送信でも先に例外にする。
     */
    public function testFrontHandleOfGetAndPostRejectsFieldsNamedLikePublicQueryVars(): void
    {
        $addCampaign = static fn(array $vars): array => [...$vars, 'campaign'];
        register_post_type('tmswp_qv_probe', ['public' => true, 'query_var' => 'tmswp_qv_probe']);
        add_filter('query_vars', $addCampaign);

        try {
            self::assertFalse(is_admin());

            foreach (['name', 's', 'tmswp_qv_probe', 'campaign'] as $name) {
                $definition = FormDefinition::create('contact')->text('full_name', 'Full name')->text($name, 'Field');
                $requests = [
                    'GET' => new FormRequest('GET'),
                    'POST' => new FormRequest('POST', [
                        Form::FIELD_FORM_ID => 'contact',
                        Form::FIELD_NONCE => wp_create_nonce('tmswp_form_contact'),
                        Form::FIELD_STEP => 'submit',
                        Form::FIELD_SUBMIT_TOKEN => SubmitToken::issue('contact'),
                        'full_name' => 'Taro',
                        $name => 'value',
                    ]),
                ];

                foreach ($requests as $method => $request) {
                    try {
                        (new Form($definition))->handle($request);
                        self::fail("{$method}のhandle()で、項目{$name}が例外になりません");
                    } catch (FormConfigException $exception) {
                        self::assertStringStartsWith("form 'contact' posts '{$name}', the same name as a public query var of WordPress.", $exception->getMessage(), "{$method} {$name}");
                    }
                }
            }
        } finally {
            remove_filter('query_vars', $addCampaign);
            unregister_post_type('tmswp_qv_probe');
        }

        self::assertSame(0, $this->claimedJtiCount());
    }

    /**
     * docs/05の受け入れ基準「FieldView/FormHtmlの出力に未エスケープのユーザ値が含まれない(`<script>`入力)」と
     * 「動的choices(ラベルに`<script>`を含む)を与えた`select()`の`<option>`のラベルが`esc_html()`、valueが
     * `esc_attr()`でエスケープされる」を、実物のesc_html()・esc_attr()と実際のhandle()の再描画で確かめる。
     */
    public function testScriptInUserInputAndDynamicChoicesIsEscapedByTheRealEscapingFunctions(): void
    {
        $form = $this->formFactory();
        $choiceValue = '"><script>alert("value")</script>';
        $definition = FormDefinition::create('profile')
            ->text('full_name', 'Name')
            ->textarea('bio', 'Bio')
            ->hidden('ref')
            ->select('category', 'Category', [$choiceValue => '</option><script>alert("label")</script>', 'plain' => 'Plain & simple'])
            ->checkboxes('topics', 'Topics', ['<b>' => '<script>alert("topic")</script>'])
            ->text('email', 'Email', [new Required()])
            ->confirm();
        $values = [
            'full_name' => self::SCRIPT,
            'bio' => self::SCRIPT . "\nsecond line",
            'ref' => self::SCRIPT,
            'category' => $choiceValue,
            'topics' => ['<b>'],
        ];
        $open = $form($definition)->open();

        // 他の項目のエラーで入力画面に戻り、利用者の値を再描画する
        $input = $form($definition);
        $result = $input->handle(new FormRequest('POST', [...$this->hiddenValues($open), Form::FIELD_STEP => 'confirm', ...$values, 'email' => '']));

        self::assertSame(FormStep::Input, $result->step);

        // select() の <option> のラベルはesc_html()、valueはesc_attr() を通る(実物の関数のフィルタで印を付ける)
        $markHtml = static fn(string $safe): string => 'H(' . $safe . ')';
        $markAttr = static fn(string $safe): string => 'A(' . $safe . ')';
        add_filter('esc_html', $markHtml);
        add_filter('attribute_escape', $markAttr);

        try {
            $select = $input->field('category')->select();
        } finally {
            remove_filter('esc_html', $markHtml);
            remove_filter('attribute_escape', $markAttr);
        }

        self::assertSame(
            '<select name="A(category)" id="A(profile-category)">'
            . '<option value="A(&quot;&gt;&lt;script&gt;alert(&quot;value&quot;)&lt;/script&gt;)" selected>H(&lt;/option&gt;&lt;script&gt;alert(&quot;label&quot;)&lt;/script&gt;)</option>'
            . '<option value="A(plain)">H(Plain &amp; simple)</option>'
            . '</select>',
            $select,
        );

        self::assertSame('<input type="text" name="full_name" value="' . self::SCRIPT_ESCAPED . '" id="profile-full_name">', $input->field('full_name')->input());
        self::assertSame('<textarea name="bio" id="profile-bio">' . self::SCRIPT_ESCAPED . "\nsecond line</textarea>", $input->field('bio')->textarea());
        self::assertSame('<input type="hidden" name="ref" value="' . self::SCRIPT_ESCAPED . '">', $input->field('ref')->hidden());
        self::assertSame(
            '<select name="category" id="profile-category">'
            . '<option value="&quot;&gt;&lt;script&gt;alert(&quot;value&quot;)&lt;/script&gt;" selected>&lt;/option&gt;&lt;script&gt;alert(&quot;label&quot;)&lt;/script&gt;</option>'
            . '<option value="plain">Plain &amp; simple</option>'
            . '</select>',
            $input->field('category')->select(),
        );
        self::assertSame(
            '<label for="profile-topics-0"><input type="checkbox" name="topics[]" value="&lt;b&gt;" checked id="profile-topics-0"> &lt;script&gt;alert(&quot;topic&quot;)&lt;/script&gt;</label>',
            $input->field('topics')->checkboxes(),
        );

        // 確認画面の表示
        $confirm = $form($definition);
        $result = $confirm->handle(new FormRequest('POST', [...$this->hiddenValues($open), Form::FIELD_STEP => 'confirm', ...$values, 'email' => 'taro@example.com']));

        self::assertSame(FormStep::Confirm, $result->step);
        self::assertSame(self::SCRIPT_ESCAPED, $confirm->field('full_name')->display());
        self::assertSame(self::SCRIPT_ESCAPED . "<br>\nsecond line", $confirm->field('bio')->display());
        self::assertSame('&lt;/option&gt;&lt;script&gt;alert(&quot;label&quot;)&lt;/script&gt;', $confirm->field('category')->display());
        self::assertSame('&lt;script&gt;alert(&quot;topic&quot;)&lt;/script&gt;', $confirm->field('topics')->display());
        self::assertStringNotContainsString('<script>', $confirm->open());
    }

    /**
     * docs/05の受け入れ基準「メッセージ解決の優先順(個別 → 英語ソース既定)と、`tmswp/form/rule_message`フィルタによる
     * サイト全体上書き(`instanceof`判定・フィルタ適用が置換の前段であること)」「`{label}` `{length}`置換」
     * 「`Callback`・`Retype`・選択肢整合検証のメッセージもフィルタを通る」を、実際のadd_filter()とhandle()の検証で
     * 確かめる。フィルタは置換の前の文言(個別のメッセージがあればそれ)を受け取り、フィルタが足したプレースホルダも置換される。
     */
    public function testRuleMessagesPassThroughTheRealFilterBeforePlaceholdersAreReplaced(): void
    {
        $form = $this->formFactory();
        /** @var list<array{string, string, string}> $received */
        $received = [];
        $filter = static function (string $message, RuleInterface $rule, ValidationContext $context) use (&$received): string {
            $received[] = [$rule::class, $message, $context->currentField];

            return match (true) {
                $rule instanceof Retype => 'Filtered: {label} differs from {otherLabel}.',
                $rule instanceof Callback => 'Filtered callback: ' . $message,
                $rule instanceof MinLength => $message . ' [{length}]',
                default => $message,
            };
        };
        add_filter('tmswp/form/rule_message', $filter, 10, 3);

        try {
            $definition = FormDefinition::create('account')
                ->text('email', 'Email')
                ->text('email_again', 'Email (again)', [new Retype('email')])
                ->text('nickname', 'Nickname', [new MinLength(5, 'Custom: {label} needs {length}+ characters.')])
                ->text('code', 'Code', [new MaxLength(3)])
                ->text('note', 'Note', [new Callback(static fn(mixed $value): bool => false)])
                ->text('remark', 'Remark', [new Callback(static fn(mixed $value): string => 'Remark callback: {label}')])
                ->select('topic', 'Topic', ['a' => 'A'])
                ->confirm();
            $result = $form($definition)->handle($this->confirmRequest($form($definition)->open(), [
                'email' => 'taro@example.com',
                'email_again' => 'jiro@example.com',
                'nickname' => 'Ta',
                'code' => 'ABCD',
                'note' => 'text',
                'remark' => 'text',
                'topic' => 'z',
            ]));
        } finally {
            remove_filter('tmswp/form/rule_message', $filter, 10);
        }

        self::assertSame(FormStep::Input, $result->step);
        self::assertSame([
            'email_again' => ['Filtered: Email (again) differs from Email.'],
            'nickname' => ['Custom: Nickname needs 5+ characters. [5]'],
            'code' => ['Code must be 3 characters or fewer.'],
            'note' => ['Filtered callback: Note is invalid.'],
            'remark' => ['Filtered callback: Remark callback: Remark'],
            'topic' => ['Topic has an invalid choice.'],
        ], $result->fieldErrors);
        self::assertSame([
            [Retype::class, '{label} must match {otherLabel}.', 'email_again'],
            [MinLength::class, 'Custom: {label} needs {length}+ characters.', 'nickname'],
            [MaxLength::class, '{label} must be {length} characters or fewer.', 'code'],
            [Callback::class, '{label} is invalid.', 'note'],
            [Callback::class, 'Remark callback: {label}', 'remark'],
            [Choice::class, '{label} has an invalid choice.', 'topic'],
        ], $received);
    }

    /**
     * docs/05の受け入れ基準「フィルタの文字列以外の戻り値と`Callback`の`bool`・`string`以外の戻り値が
     * `FormConfigException`になる」を、実際のadd_filter()とhandle()の検証で確かめる(黙って元のメッセージに戻さない)。
     */
    public function testNonStringFilterResultAndNonBoolOrStringCallbackResultAreConfigurationErrors(): void
    {
        $form = $this->formFactory();
        $filter = static fn(string $message): int => 404;
        $definition = FormDefinition::create('account')->text('code', 'Code', [new MaxLength(3)])->confirm();
        add_filter('tmswp/form/rule_message', $filter);

        try {
            $form($definition)->handle($this->confirmRequest($form($definition)->open(), ['code' => 'ABCD']));
            self::fail('フィルタの文字列以外の戻り値が例外になりません');
        } catch (FormConfigException $exception) {
            self::assertSame('the filter tmswp/form/rule_message must return a string, got int', $exception->getMessage());
        } finally {
            remove_filter('tmswp/form/rule_message', $filter);
        }

        // 戻り値の型を破るコールバックは、PHPStanの対象外のfixture(CallbackTestと共有)で作る
        $callback = require __DIR__ . '/../fixtures/form-validation/callback-returning-null.php';
        self::assertInstanceOf(Callback::class, $callback);
        $definition = FormDefinition::create('account')->text('code', 'Code', [$callback])->confirm();

        try {
            $form($definition)->handle($this->confirmRequest($form($definition)->open(), ['code' => 'ABCD']));
            self::fail('Callbackのboolとstring以外の戻り値が例外になりません');
        } catch (FormConfigException $exception) {
            self::assertSame('the Callback rule must return bool or string, got null', $exception->getMessage());
        }

        self::assertSame([], $this->completed);
    }

    private function entryDefinition(): FormDefinition
    {
        return FormDefinition::create('entry')
            ->text('full_name', 'Name', [new Required()])
            ->confirm()
            ->onComplete(function (array $values): void {
                $this->completed[] = $values;
            });
    }

    private function contactDefinition(): FormDefinition
    {
        return FormDefinition::create('contact')
            ->text('full_name', 'Name', [new Required()])
            ->onComplete(function (array $values): void {
                $this->completed[] = $values;
            });
    }

    /**
     * 入力画面から確認を通し、確認画面のopen() が出力したhidden(実物のnonceと確認トークン)を返す。
     *
     * @param Closure(FormDefinition): Form $form
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    private function confirmScreenHidden(Closure $form, FormDefinition $definition, array $values): array
    {
        $confirm = $form($definition);
        $result = $confirm->handle($this->confirmRequest($form($definition)->open(), $values));
        self::assertSame(FormStep::Confirm, $result->step);
        $hidden = $this->hiddenValues($confirm->open());
        self::assertArrayHasKey(Form::FIELD_CONFIRM_TOKEN, $hidden);

        return $hidden;
    }

    /** wp_optionsにclaimしたjti(tmswp_form_jti_*)の行数 */
    private function claimedJtiCount(): int
    {
        $wpdb = self::wpdb();
        $count = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like('tmswp_form_jti_') . '%'));
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /** 実物のwp_salt('auth') から、トークンの用途ごとに派生させた鍵(docs/05 §トークン形式と署名手順) */
    private static function derivedKey(string $purpose): string
    {
        return hash_hkdf('sha256', wp_salt('auth'), 32, 'tmswp/form/' . $purpose);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        self::assertIsString($decoded);

        return $decoded;
    }

    /**
     * 一時ディレクトリをルートとするプラグインのKernelをbootし、そのルートの中のファイルで`new Form()`を書いた
     * フォームを作る関数を返す(Formは作ったファイルでKernelを決める。本番の設定で、ログを $this->loggerに記録する)。
     *
     * @return Closure(FormDefinition): Form
     */
    private function formFactory(): Closure
    {
        $root = $this->createTempDirectory('integration-form-security');
        self::assertTrue(mkdir($root . '/app'));
        $this->writeFile($root . '/plugin.php', "<?php\n");
        $logger = $this->logger;
        $config = KernelConfig::plugin(
            $root . '/plugin.php',
            $root . '/app',
            'TmswpIntegrationFormSecurity' . uniqid(),
            false,
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

    /** @param array<string, string|list<string>> $values */
    private function confirmRequest(string $html, array $values): FormRequest
    {
        return new FormRequest('POST', [...$this->hiddenValues($html), Form::FIELD_STEP => 'confirm', ...$values]);
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

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            self::fail("ファイルを書き込めません: {$path}");
        }
    }
}
