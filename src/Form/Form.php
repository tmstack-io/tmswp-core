<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use Psr\Log\LogLevel;
use Throwable;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Exception\Form\TempFileException;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Core\Form\Composite\CompositeField;
use TMSWP\Core\Kernel\Kernel;
use WP;

final class Form
{
    public const FIELD_FORM_ID = '_form_id';
    public const FIELD_STEP = '_step';
    public const FIELD_NONCE = '_nonce';
    public const FIELD_CONFIRM_TOKEN = '_confirm_token';
    public const FIELD_SUBMIT_TOKEN = '_submit_token';

    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, string|list<string>> */
    private array $defaultOverrides = [];

    /** @var array<string, list<string>> */
    private array $fieldErrors = [];

    /** @var list<string> */
    private array $formErrors = [];

    /**
     * 取り込み(bind())の段階で見つけた、利用者の入力の不正(不正な UTF-8・不正な形のファイルのエントリ)。
     * 項目名 => エラーメッセージ。validate() が、その項目の検証の結果に代えて項目のエラーにする。
     *
     * @var array<string, string>
     */
    private array $inputErrors = [];

    private FormStep $step = FormStep::Input;

    private ?FormResult $result = null;

    private string $confirmToken = '';

    /**
     * `new Form()` を書いたファイル。ログの記録先と debug の判定に使う Kernel を、このファイルを含むルートを持つ
     * Kernel に決める(Kernel::forFile()。Log の静的な呼び出しと同じ規則)。
     */
    private readonly string $creatorFile;

    private ?Kernel $kernel = null;

    public function __construct(private readonly FormDefinition $definition)
    {
        $this->creatorFile = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? '';
        $this->values = $this->defaultValues();
    }

    /**
     * バインド・CSRF の検証・送信トークンの検証・スパム対策・値の検証・状態遷移・完了処理を行う。
     * 2回目以降の呼び出しは、初回の FormResult をそのまま返す。
     *
     * @throws FormConfigException フォームがPOSTで送る名前が、WordPressの公開のクエリ変数とぶつかる場合
     *                             (assertNoQueryVarConflicts())
     * @throws Throwable debug のとき、onComplete が投げた例外と、JtiStore のデータベースのエラー
     */
    public function handle(?FormRequest $request = null): FormResult
    {
        if ($this->result instanceof FormResult) {
            return $this->result;
        }

        $this->assertNoQueryVarConflicts();

        $this->excludeFromPageCache();
        $request ??= FormRequest::fromGlobals();

        if (!$request->isPost() || $this->requestString($request, self::FIELD_FORM_ID) !== $this->definition->id) {
            $this->values = $this->defaultValues();

            return $this->finish(FormStep::Input);
        }

        if (!$this->verifyNonce($request)) {
            $this->bind($request);
            $this->formErrors[] = __('Your session has expired. Please submit the form again.', 'tmswp');
            $this->log(LogLevel::WARNING, 'the CSRF check (nonce) of form {form} failed');

            return $this->finish(FormStep::Input);
        }

        $action = FormAction::tryFrom($this->requestString($request, self::FIELD_STEP));

        if (!$action instanceof FormAction) {
            $this->bind($request);

            return $this->finish(FormStep::Input);
        }

        if ($action === FormAction::Confirm && !$this->definition->isConfirmEnabled()) {
            $action = FormAction::Submit;
        }

        if ($action === FormAction::Back && !$this->definition->isConfirmEnabled()) {
            // 確認画面の無いフォームに戻る操作は無い。不明な _step と同じく、検証せずに入力画面を再表示する
            $this->bind($request);

            return $this->finish(FormStep::Input);
        }

        return match ($action) {
            FormAction::Confirm => $this->handleConfirm($request),
            FormAction::Submit => $this->definition->isConfirmEnabled() ? $this->handleConfirmedSubmit($request) : $this->handleDirectSubmit($request),
            FormAction::Back => $this->handleBack($request),
        };
    }

    public function field(string $name): FieldView
    {
        $field = $this->definition->field($name);

        return new FieldView($field, $this->values[$name] ?? '', $this->fieldErrors[$name] ?? [], $this->definition->id, $this->definition->errorTemplate());
    }

    /** @return array<string, FieldView> */
    public function fields(): array
    {
        $views = [];

        foreach ($this->definition->fields() as $name => $_field) {
            $views[$name] = $this->field($name);
        }

        return $views;
    }

    /**
     * 確認画面に出す項目の FieldView(定義の順)。hidden とパスワードの項目(パスワードの部品を含む複合フィールドを
     * 含む)と、FormDefinition::hideOnConfirm() で指定した項目を除く。
     *
     * @return array<string, FieldView>
     */
    public function confirmFields(): array
    {
        $views = [];

        foreach ($this->definition->confirmFields() as $name => $_field) {
            $views[$name] = $this->field($name);
        }

        return $views;
    }

    /**
     * `<form>` の開始タグと、必須の hidden(_form_id・_nonce、確認画面の無いフォームでは _submit_token、確認画面では
     * _confirm_token)を返す。入力画面では、登録したスパム対策の HTML も続けて返す。
     *
     * @param array<array-key, mixed> $attrs action は送信先を指定できる(既定は '' で、自分のページ)。method は常に
     *                                       post、enctype はファイルの項目の有無で決まるため、どちらも指定できない
     *
     * @throws FormConfigException method・enctypeを指定した場合と、actionが文字列にできない値の場合と、フォームがPOST
     *                             で送る名前がWordPressの公開のクエリ変数とぶつかる場合(assertNoQueryVarConflicts())
     */
    public function open(array $attrs = []): string
    {
        $this->assertNoQueryVarConflicts();
        $this->excludeFromPageCache();
        $attrs = FormHtml::normalizeAttributes($attrs);
        $action = '';

        if (\array_key_exists('action', $attrs)) {
            $action = Validator::stringValue($attrs['action']) ?? throw new FormConfigException("attribute 'action' must be scalar or Stringable");
            unset($attrs['action']);
        }

        $html = FormHtml::openTag($action, $this->definition->hasFileField(), $attrs);
        $html .= FormHtml::hidden(self::FIELD_FORM_ID, $this->definition->id);
        $html .= FormHtml::hidden(self::FIELD_NONCE, $this->createNonce());

        if (!$this->definition->isConfirmEnabled()) {
            $html .= FormHtml::hidden(self::FIELD_SUBMIT_TOKEN, SubmitToken::issue($this->definition->id));
        }

        if ($this->step === FormStep::Confirm) {
            $html .= FormHtml::hidden(self::FIELD_CONFIRM_TOKEN, $this->confirmToken);
        }

        if ($this->step === FormStep::Input) {
            foreach ($this->definition->spamGuards() as $guard) {
                $html .= $guard->render($this->definition->id);
            }
        }

        return $html;
    }

    public function close(): string
    {
        return '</form>';
    }

    public function step(): FormStep
    {
        return $this->step;
    }

    public function id(): string
    {
        return $this->definition->id;
    }

    /**
     * 確認画面が有効か(FormDefinition::confirm()の設定)。フォームを使う側が、確認画面の有無に合った送信ボタンを
     * 出すときや、確認画面に対応しない用途(設定ページ等)で確認画面の有効なフォームを拒否するときに使う。
     */
    public function isConfirmEnabled(): bool
    {
        return $this->definition->isConfirmEnabled();
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * 項目の既定値を差し替える(設定画面で保存済みの値を初期表示する等)。handle() より前に呼ぶ。
     *
     * @param array<string, mixed> $values 項目名 => 値。単一の値の項目と複合フィールド(正準値)は string、
     *                                     複数選択(checkboxes)は list<string>
     *
     * @throws LogicException handle() の後に呼んだ場合
     * @throws \TMSWP\Core\Exception\Form\UnknownFieldException フォームに無い項目を指定した場合
     * @throws FormConfigException 値の型が項目の受け付ける形と違う場合と、ファイルの項目を指定した場合
     */
    public function applyDefaults(array $values): void
    {
        if ($this->result instanceof FormResult) {
            throw new LogicException("applyDefaults() of form '{$this->definition->id}' must be called before handle()");
        }

        foreach ($values as $name => $value) {
            $this->defaultOverrides[$name] = $this->defaultOverride($this->definition->field($name), $value);
        }

        $this->values = $this->defaultValues();
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->fieldErrors;
    }

    /** @return list<string> */
    public function formErrors(): array
    {
        return $this->formErrors;
    }

    public function hasErrors(): bool
    {
        return $this->fieldErrors !== [] || $this->formErrors !== [];
    }

    private function handleConfirm(FormRequest $request): FormResult
    {
        $this->bind($request);

        if (!$this->passesSpamGuards($request)) {
            return $this->finish(FormStep::Input);
        }

        $this->validate();

        try {
            if ($this->fieldErrors !== []) {
                // 他の項目のエラーで入力画面に戻るときも、検証を通ったファイルは保管してトークンで引き回す(選び直させない)
                $this->storeValidUploadedFiles();

                return $this->finish(FormStep::Input);
            }

            $this->storeUploadedFiles();
        } catch (TempFileException $exception) {
            return $this->failSubmission('failed to store the uploaded files of form {form}', $exception);
        }

        $this->confirmToken = ConfirmToken::issue($this->definition->id, $this->tokenValues());

        return $this->finish(FormStep::Confirm);
    }

    /**
     * 確認画面からの送信。トークンの検証 → 全項目の復元とファイルの存在の確認 → 今の規則での再検証 → jti の claim
     * → onComplete の順。復元できない(ファイルの消失・項目の食い違い)ときと再検証に失敗したときは、jti を claim
     * せずに入力画面へ戻す。ただし、ファイルが消えていて jti が claim 済みなら、完了の後の再送として拒否する。
     */
    private function handleConfirmedSubmit(FormRequest $request): FormResult
    {
        $token = ConfirmToken::verify($this->definition->id, $this->requestString($request, self::FIELD_CONFIRM_TOKEN), $this->definition->ttl());

        if ($token->status === TokenStatus::Invalid) {
            $this->values = $this->defaultValues();
            $this->formErrors[] = __('The form could not be resubmitted. Please start again.', 'tmswp');
            $this->log(LogLevel::WARNING, 'the confirm token of form {form} was rejected as invalid (malformed, tampered or issued for another form)');

            return $this->finish(FormStep::Input);
        }

        $restored = $this->restoreValues($token->values);

        if ($token->status === TokenStatus::Expired) {
            return $this->returnToInput(__('The confirmation screen has expired. Please review your entries and continue again.', 'tmswp'));
        }

        if ($restored['changed']) {
            $this->log(LogLevel::NOTICE, 'the confirm token of form {form} does not match the current fields of the form');

            return $this->returnToInput(__('The confirmation screen has expired. Please review your entries and continue again.', 'tmswp'));
        }

        if ($restored['missingFiles'] !== []) {
            return $this->rejectMissingFiles($token->jti, $restored['missingFiles']);
        }

        // 確認の後のデプロイで規則や選択肢が変わっていることがあるため、署名済みの値も今の規則で検証し直す
        $errors = (new Validator())->validate($this->definition, $this->valuesForValidation());

        if ($errors !== []) {
            $this->fieldErrors = $errors;
            $this->decomposeCompositeValues();
            $this->log(LogLevel::NOTICE, 'the values in the confirm token of form {form} do not pass the current rules: {fields}', ['fields' => implode(', ', array_keys($errors))]);

            return $this->finish(FormStep::Input);
        }

        return $this->complete($token->jti, $this->definition->ttl() + 60);
    }

    /**
     * 確認画面の無いフォームの送信。送信トークンの検証 → スパム対策 → 値の検証 → jti の claim → ファイルの保管 →
     * onComplete の順(ファイルの保管は complete() が行う)。
     */
    private function handleDirectSubmit(FormRequest $request): FormResult
    {
        $token = SubmitToken::verify($this->definition->id, $this->requestString($request, self::FIELD_SUBMIT_TOKEN));
        $this->bind($request);

        if ($token->status !== TokenStatus::Valid) {
            $this->formErrors[] = __('Your session has expired. Please submit the form again.', 'tmswp');

            if ($token->status === TokenStatus::Invalid) {
                $this->log(LogLevel::WARNING, 'the submit token of form {form} was rejected as invalid (missing, malformed, tampered or issued for another form)');
            }

            return $this->finish(FormStep::Input);
        }

        if (!$this->passesSpamGuards($request)) {
            return $this->finish(FormStep::Input);
        }

        $this->validate();

        if ($this->fieldErrors !== []) {
            return $this->finish(FormStep::Input);
        }

        return $this->complete($token->jti, SubmitToken::TTL + 60);
    }

    /**
     * jti を claim し、ファイルを保管してから onComplete を呼び、onComplete が消費しなかった一時ファイルを消す。
     *
     * claim を先にするのは、並行した二重の POST で onComplete を1回だけ実行するため。ファイルの保管を claim の後に
     * するのは、再送として拒否する POST のファイルを保管しないため(確認画面ありのフォームは確認の時点で保管済みで、
     * ここでは何もしない)。保管と onComplete が例外で終わったら jti を手放し、同じトークンで再送できるようにする。
     * このとき一時ファイルは消さない(再送で使う)。
     */
    private function complete(string $jti, int $ttl): FormResult
    {
        $store = new JtiStore();

        try {
            $claim = $store->claim($jti, $ttl);
        } catch (RuntimeException $exception) {
            return $this->failSubmission('failed to claim the submission ID of form {form}', $exception);
        }

        if ($claim === JtiClaim::Duplicate) {
            return $this->rejectResubmission();
        }

        try {
            $this->storeUploadedFiles();
        } catch (TempFileException $exception) {
            $this->release($store, $jti, $exception);

            return $this->failSubmission('failed to store the uploaded files of form {form}', $exception);
        }

        $handler = $this->definition->completeHandler();

        if ($handler !== null) {
            try {
                $handler($this->values, $this);
            } catch (Throwable $exception) {
                $this->release($store, $jti, $exception);

                return $this->failSubmission('onComplete of form {form} threw an exception', $exception);
            }
        }

        $this->deleteUnconsumedFiles();

        return $this->finish(FormStep::Complete);
    }

    private function rejectResubmission(): FormResult
    {
        $this->values = $this->defaultValues();
        $this->formErrors[] = __('This form has already been submitted.', 'tmswp');
        $this->log(LogLevel::NOTICE, 'a resubmission of form {form} was rejected');

        return $this->finish(FormStep::Input);
    }

    /**
     * 一時ファイルが消えていた確認画面からの送信。jti が claim 済みなら、送信の完了の後(完了の後始末でファイルを
     * 消した後)の再送として拒否する。claim されていなければ(gc による消失等)、jti を claim せずに入力画面に戻す。
     *
     * @param list<string> $fields ファイルが消えていた項目
     */
    private function rejectMissingFiles(string $jti, array $fields): FormResult
    {
        try {
            $claimed = (new JtiStore())->isClaimed($jti);
        } catch (RuntimeException $exception) {
            return $this->failSubmission('failed to look up the submission ID of form {form}', $exception);
        }

        if ($claimed) {
            return $this->rejectResubmission();
        }

        $this->log(LogLevel::WARNING, 'temporary files of form {form} were missing at submission: {fields}', ['fields' => implode(', ', $fields)]);

        return $this->returnToInput(__('The confirmation screen has expired. Please review your entries and continue again.', 'tmswp'));
    }

    /**
     * 送信の完了の後、onComplete が移動(moveTo())も添付化(toAttachment())もしなかった一時ファイルを消す。
     * 利用者の送信は成功しているため、削除の失敗は debug でも例外にせず、Kernel のロガーに error で記録して残りを続ける
     * (残ったファイルは gc() が消す)。
     */
    private function deleteUnconsumedFiles(): void
    {
        foreach ($this->values as $value) {
            if (!$value instanceof StoredFile) {
                continue;
            }

            try {
                $value->delete();
            } catch (TempFileException $exception) {
                $this->log(LogLevel::ERROR, 'failed to delete a temporary file of form {form} after completion', self::exceptionContext($exception));
            }
        }
    }

    /**
     * onComplete の例外・ファイルの保管の失敗の後に jti を手放す。手放しに失敗したら記録し、元の失敗の扱い(debug なら
     * 投げる)を優先する。この場合、同じトークンでの再送は再送として拒否される。
     *
     * @param Throwable $failure onComplete が投げた例外か、保管の失敗(Kernel を決められないとき、previous に入れて投げる)
     */
    private function release(JtiStore $store, string $jti, Throwable $failure): void
    {
        try {
            $store->release($jti);
        } catch (RuntimeException $exception) {
            $this->kernelKeeping($failure)->logger()->log(
                LogLevel::ERROR,
                'tmswp.form: failed to release the submission ID of form {form}; the same token cannot be resubmitted',
                ['form' => $this->definition->id] + self::exceptionContext($exception),
            );
        }
    }

    /**
     * 送信の失敗をエラー方針(00-overview.md §5.6)に従って扱う。debug では例外を投げ、本番では Kernel のロガーに
     * error で記録し、汎用の送信失敗のエラーを付けて入力画面に戻す。
     *
     * @throws Throwable debug のとき
     */
    private function failSubmission(string $message, Throwable $exception): FormResult
    {
        if ($this->kernelKeeping($exception)->config()->debug) {
            throw $exception;
        }

        $this->log(LogLevel::ERROR, $message, self::exceptionContext($exception));

        return $this->returnToInput(__('The form could not be sent. Please try again.', 'tmswp'));
    }

    /**
     * Form の Kernel を返す。Kernel を決められないときも、送信の失敗の元の例外を失わないよう、previous に入れて投げる。
     *
     * @throws KernelException `new Form()` を書いたファイルが、どの Kernel のルートにも含まれない場合
     */
    private function kernelKeeping(Throwable $failure): Kernel
    {
        try {
            return $this->kernel();
        } catch (KernelException $exception) {
            throw new KernelException($exception->getMessage(), previous: $failure);
        }
    }

    /**
     * ログの文脈に入れる例外の情報。例外のメッセージ・トレース・オブジェクトは入れない。onComplete が利用者の入力値を
     * メッセージに含めた例外を投げることがあり、トレースの引数には jti 等が入りうるため(本番のログに残さない)。
     * 開発者は debug で元の例外を見られる。
     *
     * @return array{exceptionClass: class-string<Throwable>, exceptionAt: string, previousClasses: list<class-string<Throwable>>}
     */
    private static function exceptionContext(Throwable $exception): array
    {
        $previous = [];

        for ($current = $exception->getPrevious(); $current !== null; $current = $current->getPrevious()) {
            $previous[] = $current::class;
        }

        return [
            'exceptionClass' => $exception::class,
            'exceptionAt' => $exception->getFile() . ':' . $exception->getLine(),
            'previousClasses' => $previous,
        ];
    }

    private function handleBack(FormRequest $request): FormResult
    {
        $token = ConfirmToken::verify($this->definition->id, $this->requestString($request, self::FIELD_CONFIRM_TOKEN), $this->definition->ttl());

        if ($token->status === TokenStatus::Invalid) {
            $this->values = $this->defaultValues();
            $this->formErrors[] = __('The form could not be resubmitted. Please start again.', 'tmswp');
            $this->log(LogLevel::WARNING, 'the confirm token of form {form} was rejected as invalid on going back (malformed, tampered or issued for another form)');

            return $this->finish(FormStep::Input);
        }

        // 入力画面へ戻り、確認からやり直すため、項目の食い違いやファイルの消失はここでは扱わない
        $this->restoreValues($token->values);
        $this->decomposeCompositeValues();

        return $this->finish(FormStep::Input);
    }

    private function passesSpamGuards(FormRequest $request): bool
    {
        foreach ($this->definition->spamGuards() as $guard) {
            if (!$guard->passes($request)) {
                $this->formErrors[] = __('Your submission could not be verified. Please try again.', 'tmswp');
                $this->log(LogLevel::NOTICE, 'spam guard {guard} rejected a submission of form {form}', ['guard' => $guard::class]);

                return false;
            }
        }

        return true;
    }

    private function returnToInput(string $formError): FormResult
    {
        $this->decomposeCompositeValues();
        $this->formErrors[] = $formError;

        return $this->finish(FormStep::Input);
    }

    /**
     * POST の値を項目に取り込む。不正な UTF-8 と不正な形のファイルのエントリは、例外にせず、その項目の値を空にして
     * $inputErrors に積む(validate() で項目のエラーになる)。ログには項目名だけを残し、入力値は残さない。
     */
    private function bind(FormRequest $request): void
    {
        $values = [];
        $this->inputErrors = [];

        foreach ($this->definition->fields() as $name => $field) {
            if ($field instanceof CompositeField) {
                $values[$name] = $this->bindComposite($field, $request);
                continue;
            }

            $values[$name] = $this->bindField($field, $request);
        }

        $this->values = $values;

        if ($this->inputErrors !== []) {
            $this->log(LogLevel::NOTICE, 'the request to form {form} has malformed input (invalid UTF-8 or a malformed file upload) in: {fields}', ['fields' => implode(', ', array_keys($this->inputErrors))]);
        }
    }

    /**
     * 不正な UTF-8 の部品があれば $inputErrors に積み、値を空にする。
     *
     * @return array<array-key, mixed> 部品の値(検証の時に正準値へ合成する)
     *
     * @phpstan-impure
     */
    private function bindComposite(CompositeField $field, FormRequest $request): array
    {
        $raw = $request->post($field->name);

        if (!\is_array($raw)) {
            return [];
        }

        foreach ($field->parts() as $partName => $_part) {
            $part = Validator::stringValue($raw[$partName] ?? '');

            if ($part !== null && !self::isValidUtf8($part)) {
                $this->inputErrors[$field->name] = self::invalidCharactersMessage($field->label);

                return [];
            }
        }

        return $raw;
    }

    /**
     * 入力の不正(不正な UTF-8・不正な形のファイルのエントリ)があれば $inputErrors に積み、値を空にする。
     *
     * @phpstan-impure
     */
    private function bindField(Field $field, FormRequest $request): mixed
    {
        if ($field->type === FieldType::File) {
            $file = $request->files[$field->name] ?? null;

            // error の無いエントリも、ファイルを選ばなかった送信ではなく不正な形として扱う
            if ($file !== null && !(\array_key_exists('error', $file) && $file['error'] === UPLOAD_ERR_NO_FILE)) {
                $uploaded = self::uploadedFile($file);

                if ($uploaded === null) {
                    $this->inputErrors[$field->name] = __('The file upload failed.', 'tmswp');

                    return '';
                }

                return $uploaded;
            }

            $token = $this->requestString($request, $field->name . '__token');

            if ($token !== '' && $this->definition->store()->exists($token)) {
                return $this->definition->store()->resolve($token);
            }

            return '';
        }

        $raw = $request->post($field->name);

        if ($field->multiple) {
            $items = \is_array($raw) ? $raw : ($raw === '' ? [] : [$raw]);
            $values = [];

            foreach ($items as $item) {
                $value = $this->normalizeScalar($item);

                if (!self::isValidUtf8($value)) {
                    $this->inputErrors[$field->name] = self::invalidCharactersMessage($field->label);

                    return [];
                }

                foreach ($field->filters as $filter) {
                    $value = $filter($value);
                }

                $values[] = $value;
            }

            return $values;
        }

        $value = $this->normalizeScalar($raw);

        if (!self::isValidUtf8($value)) {
            $this->inputErrors[$field->name] = self::invalidCharactersMessage($field->label);

            return '';
        }

        foreach ($field->filters as $filter) {
            $value = $filter($value);
        }

        return $value;
    }

    /**
     * $_FILES のエントリから UploadedFile を作る。1つのファイルの形(name・type・tmp_name が文字列、error・size が
     * 整数)でないエントリ(`resume[]` のような配列のファイル等)と、ファイル名が不正な UTF-8 のエントリは null。
     *
     * @param array<array-key, mixed> $file
     */
    private static function uploadedFile(array $file): ?UploadedFile
    {
        $name = $file['name'] ?? null;
        $type = $file['type'] ?? null;
        $tmpName = $file['tmp_name'] ?? null;
        $error = $file['error'] ?? null;
        $size = $file['size'] ?? null;

        if (!\is_string($name) || !\is_string($type) || !\is_string($tmpName) || !\is_int($error) || !\is_int($size) || !self::isValidUtf8($name)) {
            return null;
        }

        return UploadedFile::fromFilesEntry(['name' => $name, 'type' => $type, 'tmp_name' => $tmpName, 'error' => $error, 'size' => $size]);
    }

    private static function isValidUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    private static function invalidCharactersMessage(string $label): string
    {
        return strtr(__('{label} contains characters that cannot be used.', 'tmswp'), ['{label}' => $label]);
    }

    private function normalizeScalar(mixed $value): string
    {
        return Validator::stringValue($value) ?? '';
    }

    /**
     * 値を検証する。取り込みの段階で入力の不正があった項目は、検証の結果に代えてその不正をエラーにする。アップロードに
     * 失敗したファイル(サイズの超過等)は、項目の規則に関係なく、規則のエラーより先にエラーにする(検証を通ったファイルを
     * 必ず保管できるようにするため。失敗したアップロードに拡張子等の規則のエラーを出さないため)。
     */
    private function validate(): void
    {
        $errors = (new Validator())->validate($this->definition, $this->values);
        $this->fieldErrors = [];

        foreach ($this->definition->fields() as $name => $_field) {
            $value = $this->values[$name] ?? null;

            if (\array_key_exists($name, $this->inputErrors)) {
                $this->fieldErrors[$name] = [$this->inputErrors[$name]];
            } elseif ($value instanceof UploadedFile && !$value->isValid()) {
                $this->fieldErrors[$name] = [$value->errorMessage()];
            } elseif (\array_key_exists($name, $errors)) {
                $this->fieldErrors[$name] = $errors[$name];
            }
        }

        if ($this->fieldErrors === []) {
            $this->normalizeCompositeValues();
        }
    }

    /**
     * 値が UploadedFile の項目を保管して StoredFile に差し替える。validate() を通った後に呼ぶ(アップロードに失敗した
     * ファイルは validate() でエラーになっている)。
     *
     * @throws TempFileException 保管の失敗
     */
    private function storeUploadedFiles(): void
    {
        foreach ($this->values as $name => $value) {
            if ($value instanceof UploadedFile) {
                $this->values[$name] = $this->definition->store()->store($value);
            }
        }
    }

    /**
     * 自身の項目にエラーが無く、アップロードに成功したファイルだけを保管する(他の項目にエラーがあるとき)
     *
     * @throws TempFileException 保管の失敗
     */
    private function storeValidUploadedFiles(): void
    {
        foreach ($this->values as $name => $value) {
            if ($value instanceof UploadedFile && !\array_key_exists($name, $this->fieldErrors) && $value->isValid()) {
                $this->values[$name] = $this->definition->store()->store($value);
            }
        }
    }

    /** @return array<string, string|list<string>|array{__tmsfile: string}> */
    private function tokenValues(): array
    {
        $values = [];

        foreach ($this->values as $name => $value) {
            if ($value instanceof StoredFile) {
                $values[$name] = ['__tmsfile' => $value->token];
                continue;
            }

            if ($value instanceof UploadedFile) {
                throw new FormConfigException('UploadedFile must be stored before issuing confirm token');
            }

            if (\is_array($value)) {
                if (!array_is_list($value)) {
                    throw new FormConfigException("field '{$name}' must be normalized before issuing confirm token");
                }

                $items = [];

                foreach ($value as $item) {
                    $string = Validator::stringValue($item);

                    if ($string === null) {
                        throw new FormConfigException("field '{$name}' contains a non-stringable value");
                    }

                    $items[] = $string;
                }

                $values[$name] = $items;
                continue;
            }

            $string = Validator::stringValue($value);

            if ($string === null) {
                throw new FormConfigException("field '{$name}' contains a non-stringable value");
            }

            $values[$name] = $string;
        }

        return $values;
    }

    /**
     * 署名済みの値を全項目復元する。複合フィールドは正準値(文字列)のまま、ファイルは一時保管から解決する
     * (消えていたら '')。トークンに無い項目は既定値になる。
     *
     * @param array<string, string|list<string>|array{__tmsfile: string}> $tokenValues
     *
     * @return array{changed: bool, missingFiles: list<string>} changed はトークンの項目と値の形がフォームの現在の
     *                                                          項目と食い違うこと(デプロイをまたいだ送信等)
     */
    private function restoreValues(array $tokenValues): array
    {
        $this->values = $this->defaultValues();
        $fields = $this->definition->fields();
        $changed = array_diff_key($tokenValues, $fields) !== [] || array_diff_key($fields, $tokenValues) !== [];
        $missingFiles = [];

        foreach ($tokenValues as $name => $value) {
            if (!\array_key_exists($name, $fields)) {
                continue;
            }

            if (!$this->matchesTokenShape($fields[$name], $value)) {
                $changed = true;
                continue;
            }

            if (\is_array($value) && \array_key_exists('__tmsfile', $value)) {
                if ($this->definition->store()->exists($value['__tmsfile'])) {
                    $this->values[$name] = $this->definition->store()->resolve($value['__tmsfile']);
                } else {
                    $this->values[$name] = '';
                    $missingFiles[] = $name;
                }

                continue;
            }

            $this->values[$name] = $value;
        }

        return ['changed' => $changed, 'missingFiles' => $missingFiles];
    }

    /** @param string|list<string>|array{__tmsfile: string} $value */
    private function matchesTokenShape(Field|CompositeField $field, string|array $value): bool
    {
        if ($field instanceof CompositeField) {
            return \is_string($value);
        }

        if ($field->type === FieldType::File) {
            return $value === '' || (\is_array($value) && \array_key_exists('__tmsfile', $value));
        }

        if ($field->multiple) {
            return \is_array($value) && !\array_key_exists('__tmsfile', $value);
        }

        return \is_string($value);
    }

    /**
     * 復元した値を Validator に渡す形にする。Validator は複合フィールドを部品の値で受け取るため、正準値を分解する。
     *
     * @return array<string, mixed>
     */
    private function valuesForValidation(): array
    {
        $values = $this->values;

        foreach ($this->definition->fields() as $name => $field) {
            if ($field instanceof CompositeField && \is_string($values[$name] ?? null)) {
                $values[$name] = $field->decompose($values[$name]);
            }
        }

        return $values;
    }

    /** 入力画面に再表示するため、複合フィールドの正準値を部品の値に戻す */
    private function decomposeCompositeValues(): void
    {
        foreach ($this->definition->fields() as $name => $field) {
            if ($field instanceof CompositeField && \is_string($this->values[$name] ?? null)) {
                $this->values[$name] = $field->decompose($this->values[$name]);
            }
        }
    }

    /** @return string|list<string> */
    private function defaultOverride(Field|CompositeField $field, mixed $value): string|array
    {
        if ($field instanceof Field && $field->type === FieldType::File) {
            throw new FormConfigException("file field '{$field->name}' cannot have a default value");
        }

        if ($field instanceof Field && $field->multiple) {
            if (!\is_array($value) || !array_is_list($value)) {
                throw new FormConfigException("default value of field '{$field->name}' must be a list of strings, " . get_debug_type($value) . ' given');
            }

            $items = [];

            foreach ($value as $item) {
                if (!\is_string($item)) {
                    throw new FormConfigException("default value of field '{$field->name}' must be a list of strings, an item is " . get_debug_type($item));
                }

                $items[] = $item;
            }

            return $items;
        }

        if (!\is_string($value)) {
            throw new FormConfigException("default value of field '{$field->name}' must be a string, " . get_debug_type($value) . ' given');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function defaultValues(): array
    {
        $values = [];

        foreach ($this->definition->fields() as $name => $field) {
            $override = $this->defaultOverrides[$name] ?? null;

            if ($field instanceof CompositeField) {
                $values[$name] = $field->decompose(\is_string($override) ? $override : '');
                continue;
            }

            $values[$name] = $override ?? $field->default;
        }

        return $values;
    }

    private function finish(FormStep $step): FormResult
    {
        $this->step = $step;
        $this->result = new FormResult($step, $this->values, $this->fieldErrors, $this->formErrors);

        return $this->result;
    }

    /**
     * フォームのあるページをページキャッシュから外す。未ログインの利用者の nonce は全訪問者で共通かつ12〜24時間
     * 有効のため、キャッシュに載ると期限切れの nonce が配られて送信が失敗する。
     * nocache_headers() は出力の開始後には何もしない(WordPress の仕様)ため、出力の前に呼ぶ handle() でも付ける。
     */
    private function excludeFromPageCache(): void
    {
        if (!\defined('DONOTCACHEPAGE')) {
            \define('DONOTCACHEPAGE', true);
        }

        nocache_headers();
    }

    /**
     * フォームがPOSTで送る項目の名前(ファイルの項目は{name}__token)が、WordPressの公開のクエリ変数とぶつからないことを
     * 確かめる。WordPressはフロントの要求の解析(WP::parse_request())で、公開のクエリ変数と同じ名前のPOSTの値をクエリ
     * 変数として読む。ぶつかると、送信が別のページの表示や404になり、フォームの処理まで届かない。
     *
     * 公開のクエリ変数は、$wp->public_query_varsにquery_varsフィルタを通したもの(登録した投稿タイプ・タクソノミーの
     * query_varを含む)。投稿タイプの登録の後に決まるため、handle()・open()の時点で確かめる。ファイルの本体は$_FILESで
     * 送り、WordPressは読まないため、ファイルの項目は{name}__tokenだけを確かめる。管理画面(admin-ajax.phpを含む)は
     * 要求の解析をせず、POSTの値をクエリ変数として読まないため、確かめない。
     *
     * @throws FormConfigException ぶつかる名前がある場合と、WordPressの$wpが無く確かめられない場合
     */
    private function assertNoQueryVarConflicts(): void
    {
        if (is_admin()) {
            return;
        }

        $id = $this->definition->id;
        $wp = $GLOBALS['wp'] ?? null;

        if (!$wp instanceof WP) {
            throw new FormConfigException("cannot check the field names of form '{$id}' against the public query vars of WordPress: the global \$wp is not a WP instance");
        }

        $queryVars = apply_filters('query_vars', $wp->public_query_vars);

        if (!\is_array($queryVars)) {
            throw new FormConfigException("cannot check the field names of form '{$id}': the 'query_vars' filter did not return an array");
        }

        $conflicts = [];

        foreach ($this->definition->fields() as $name => $field) {
            $postName = $field instanceof Field && $field->type === FieldType::File ? $name . '__token' : $name;

            if (\in_array($postName, $queryVars, true)) {
                $conflicts[$name] = "'{$postName}'";
            }
        }

        if ($conflicts === []) {
            return;
        }

        $field = array_key_first($conflicts);
        $example = str_replace('-', '_', $id) . '_' . $field;

        throw new FormConfigException(\sprintf(
            "form '%s' posts %s, the same name as a public query var of WordPress. WordPress reads public query vars from POST values, so submitting the form shows another page or a 404; rename the field (for example, '%s' instead of '%s')",
            $id,
            implode(', ', $conflicts),
            $example,
            $field,
        ));
    }

    /**
     * Form の Kernel のロガーに記録する。利用者の入力値は記録しない。
     *
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $this->kernel()->logger()->log($level, 'tmswp.form: ' . $message, ['form' => $this->definition->id] + $context);
    }

    /**
     * @throws KernelException `new Form()` を書いたファイルが、どの Kernel のルートにも含まれない場合
     */
    private function kernel(): Kernel
    {
        if ($this->creatorFile === '') {
            throw new KernelException("cannot determine the file that created form '{$this->definition->id}' to find its TMSWP kernel");
        }

        return $this->kernel ??= Kernel::forFile($this->creatorFile);
    }

    private function verifyNonce(FormRequest $request): bool
    {
        if (!\function_exists('wp_verify_nonce')) {
            throw new FormConfigException('wp_verify_nonce is not available');
        }

        return wp_verify_nonce($this->requestString($request, self::FIELD_NONCE), 'tmswp_form_' . $this->definition->id) !== false;
    }

    private function createNonce(): string
    {
        if (!\function_exists('wp_create_nonce')) {
            throw new FormConfigException('wp_create_nonce is not available');
        }

        return wp_create_nonce('tmswp_form_' . $this->definition->id);
    }

    private function requestString(FormRequest $request, string $key): string
    {
        return Validator::stringValue($request->post($key)) ?? '';
    }

    private function normalizeCompositeValues(): void
    {
        foreach ($this->definition->fields() as $name => $field) {
            if (!$field instanceof CompositeField) {
                continue;
            }

            $value = $this->values[$name] ?? [];
            $parts = \is_array($value) ? $this->normalizeCompositeParts($field, $value) : $field->decompose(Validator::stringValue($value) ?? '');
            $this->values[$name] = $this->compositePartsAreEmpty($parts) ? '' : $field->compose($parts);
        }
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return array<string, string>
     */
    private function normalizeCompositeParts(CompositeField $field, array $raw): array
    {
        $values = [];

        foreach ($field->parts() as $partName => $part) {
            $value = Validator::stringValue($raw[$partName] ?? '') ?? '';

            foreach ($part->filters as $filter) {
                $value = $filter($value);
            }

            $values[$partName] = $value;
        }

        return $values;
    }

    /** @param array<string, string> $parts */
    private function compositePartsAreEmpty(array $parts): bool
    {
        foreach ($parts as $value) {
            if ($value !== '') {
                return false;
            }
        }

        return true;
    }
}
