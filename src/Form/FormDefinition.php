<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use Closure;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Exception\Form\UnknownFieldException;
use TMSWP\Core\Form\Composite\CompositeField;
use TMSWP\Core\Form\Rule\RuleInterface;

final class FormDefinition
{
    /** @var array<string, Field|CompositeField> */
    private array $fields = [];

    private bool $confirm = false;

    private int $ttl = 1800;

    /** @var null|Closure(array<string, mixed>, Form): void */
    private ?Closure $onComplete = null;

    private string $errorHtml = '<p class="form-error">%s</p>';

    private ?TempFileStore $store = null;

    /** @var list<SpamGuardInterface> */
    private array $spamGuards = [];

    /** @var array<string, true> 確認画面に出さない項目(hideOnConfirm()) */
    private array $hiddenOnConfirm = [];

    private function __construct(public readonly string $id)
    {
        if (preg_match('/\A[a-z][a-z0-9_-]*\z/', $id) !== 1) {
            throw new FormConfigException("invalid form id: {$id}");
        }
    }

    public static function create(string $id): self
    {
        return new self($id);
    }

    /**
     * @param list<RuleInterface> $rules
     * @param list<Closure(string): string> $filters
     */
    public function text(string $name, string $label, array $rules = [], string $default = '', array $filters = []): self
    {
        return $this->add(Field::text($name, $label, $rules, $default, $filters));
    }

    /**
     * @param list<RuleInterface> $rules
     * @param list<Closure(string): string> $filters
     */
    public function textarea(string $name, string $label, array $rules = [], string $default = '', array $filters = []): self
    {
        return $this->add(Field::textarea($name, $label, $rules, $default, $filters));
    }

    /**
     * パスワードの項目。確認画面のあるフォームには置けない(§add())。
     *
     * @param list<RuleInterface> $rules
     */
    public function password(string $name, string $label, array $rules = []): self
    {
        return $this->add(Field::password($name, $label, $rules));
    }

    /** @param list<RuleInterface> $rules */
    public function hidden(string $name, string $label = '', array $rules = [], string $default = ''): self
    {
        return $this->add(Field::hidden($name, $label, $rules, $default));
    }

    /**
     * @param array<array-key, string> $choices
     * @param list<RuleInterface> $rules
     */
    public function select(string $name, string $label, array $choices, array $rules = [], string $default = ''): self
    {
        return $this->add(Field::select($name, $label, $choices, $rules, $default));
    }

    /**
     * @param array<array-key, string> $choices
     * @param list<RuleInterface> $rules
     */
    public function radios(string $name, string $label, array $choices, array $rules = [], string $default = ''): self
    {
        return $this->add(Field::radios($name, $label, $choices, $rules, $default));
    }

    /**
     * @param array<array-key, string> $choices
     * @param list<RuleInterface> $rules
     * @param list<string> $default
     */
    public function checkboxes(string $name, string $label, array $choices, array $rules = [], array $default = []): self
    {
        return $this->add(Field::checkboxes($name, $label, $choices, $rules, $default));
    }

    /**
     * @param list<RuleInterface> $rules
     *
     * @throws FormConfigException $checkedValue が空文字列の場合(Field::checkbox())
     */
    public function checkbox(string $name, string $label, string $checkedValue = '1', array $rules = [], ?string $checkedLabel = null): self
    {
        return $this->add(Field::checkbox($name, $label, $checkedValue, $rules, $checkedLabel));
    }

    /** @param list<RuleInterface> $rules */
    public function file(string $name, string $label, array $rules = []): self
    {
        return $this->add(Field::file($name, $label, $rules));
    }

    /**
     * @throws FormConfigException 同じ名前の項目がある場合と、確認画面のあるフォームにパスワードの項目を足す場合
     */
    public function add(Field|CompositeField ...$fields): self
    {
        foreach ($fields as $field) {
            if (\array_key_exists($field->name, $this->fields)) {
                throw new FormConfigException("duplicate field name: {$field->name}");
            }

            if ($this->confirm && self::hasPassword($field)) {
                throw self::passwordOnConfirmException($this->id, $field->name);
            }

            $this->fields[$field->name] = $field;
        }

        return $this;
    }

    /**
     * 確認画面を有効にする。パスワードの項目があるフォームでは有効にできない。確認画面の値は署名付きのトークン
     * (ConfirmToken。中身は base64 で読める)で運ぶため、パスワードを onComplete まで届けるにはトークンに平文で
     * 入れるしかないから。
     *
     * @throws FormConfigException パスワードの項目がある場合
     */
    public function confirm(bool $enabled = true): self
    {
        if ($enabled) {
            foreach ($this->fields as $field) {
                if (self::hasPassword($field)) {
                    throw self::passwordOnConfirmException($this->id, $field->name);
                }
            }
        }

        $this->confirm = $enabled;

        return $this;
    }

    /**
     * 確認画面(Form::confirmFields())に出さない項目を指定する。確認用のメールアドレスの欄等に使う。hidden と
     * パスワードの項目は、指定しなくても出さない。
     *
     * @throws UnknownFieldException まだ足していない項目を指定した場合
     */
    public function hideOnConfirm(string ...$names): self
    {
        foreach ($names as $name) {
            $this->field($name);
            $this->hiddenOnConfirm[$name] = true;
        }

        return $this;
    }

    public function confirmTtl(int $seconds): self
    {
        if ($seconds < 1) {
            throw new FormConfigException('confirm ttl must be greater than 0');
        }

        $this->ttl = $seconds;

        return $this;
    }

    /** @param Closure(array<string, mixed>, Form): void $handler */
    public function onComplete(Closure $handler): self
    {
        $this->onComplete = $handler;

        return $this;
    }

    /**
     * FieldView::errors() の既定のテンプレート。`%s` をエスケープ済みのメッセージに置き換える。`%s` 以外の `%` は
     * そのまま出す(sprintf の書式としては扱わない)。
     *
     * @throws FormConfigException `%s` を含まない場合
     */
    public function errorHtml(string $template): self
    {
        if (!str_contains($template, '%s')) {
            throw new FormConfigException('error html template must contain %s');
        }

        $this->errorHtml = $template;

        return $this;
    }

    public function tempFileStore(TempFileStore $store): self
    {
        $this->store = $store;

        return $this;
    }

    /**
     * スパム対策を登録する。複数を登録でき、登録の順に検査する(どれか1つが拒否したら送信を受け付けない)。
     */
    public function spamGuard(SpamGuardInterface $guard): self
    {
        $this->spamGuards[] = $guard;

        return $this;
    }

    /** @return array<string, Field|CompositeField> */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * 確認画面に出す項目(定義の順)。hidden とパスワードの項目(パスワードの部品を含む複合フィールドを含む)と、
     * hideOnConfirm() で指定した項目を除く。
     *
     * @return array<string, Field|CompositeField>
     */
    public function confirmFields(): array
    {
        $fields = [];

        foreach ($this->fields as $name => $field) {
            if (isset($this->hiddenOnConfirm[$name])) {
                continue;
            }

            if ($field instanceof Field && $field->type === FieldType::Hidden) {
                continue;
            }

            if (self::hasPassword($field)) {
                continue;
            }

            $fields[$name] = $field;
        }

        return $fields;
    }

    public function field(string $name): Field|CompositeField
    {
        if (!\array_key_exists($name, $this->fields)) {
            throw new UnknownFieldException("unknown form field: {$name}");
        }

        return $this->fields[$name];
    }

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->fields);
    }

    public function isConfirmEnabled(): bool
    {
        return $this->confirm;
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    /** @return null|Closure(array<string, mixed>, Form): void */
    public function completeHandler(): ?Closure
    {
        return $this->onComplete;
    }

    public function errorTemplate(): string
    {
        return $this->errorHtml;
    }

    public function store(): TempFileStore
    {
        if (!$this->store instanceof TempFileStore) {
            $this->store = TempFileStore::default();
        }

        return $this->store;
    }

    /** @return list<SpamGuardInterface> */
    public function spamGuards(): array
    {
        return $this->spamGuards;
    }

    public function hasFileField(): bool
    {
        foreach ($this->fields as $field) {
            if ($field instanceof Field && $field->type === FieldType::File) {
                return true;
            }
        }

        return false;
    }

    /** 複合フィールドは、部品にパスワードの項目があるかを見る(正準値にパスワードが入るため) */
    private static function hasPassword(Field|CompositeField $field): bool
    {
        if ($field instanceof Field) {
            return $field->type === FieldType::Password;
        }

        foreach ($field->parts() as $part) {
            if ($part->type === FieldType::Password) {
                return true;
            }
        }

        return false;
    }

    private static function passwordOnConfirmException(string $formId, string $fieldName): FormConfigException
    {
        return new FormConfigException(
            "form '{$formId}' cannot have the password field '{$fieldName}' with the confirmation screen: "
            . 'the confirm token would carry the password in plain text',
        );
    }
}
