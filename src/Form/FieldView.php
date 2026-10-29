<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\Composite\CompositeField;
use TMSWP\Core\Support\Html;

final class FieldView
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        private readonly Field|CompositeField $field,
        private readonly mixed $value,
        private readonly array $errors,
        private readonly string $formId,
        private readonly string $errorTemplate,
        private readonly ?string $partKey = null,
        private readonly ?Field $partField = null,
    ) {}

    /**
     * `<input>`。type は項目の種類から決め、`$attrs['type']` で変えられる(email・tel 等)。`$attrs` の id は既定の
     * id を、value は現在の値を上書きする。パスワードとファイルの項目は値を出さず、value を指定できない。
     * name は指定できない(POST の名前は項目で決まる)。
     *
     * @param array<array-key, mixed> $attrs
     *
     * @throws FormConfigException
     */
    public function input(array $attrs = []): string
    {
        $field = $this->baseField();

        if (!\in_array($field->type, [FieldType::Text, FieldType::Password, FieldType::Hidden, FieldType::File], true)) {
            throw new FormConfigException("field '{$field->name}' cannot be rendered as input");
        }

        $attrs = FormHtml::normalizeAttributes($attrs);
        $type = $field->type->value;

        if (\array_key_exists('type', $attrs)) {
            $type = $this->attributeString($attrs['type'], 'type');
            unset($attrs['type']);
        }

        // パスワードは再描画しない。ファイルの input は値を持てない
        $hasNoValue = \in_array($field->type, [FieldType::Password, FieldType::File], true);
        $value = $hasNoValue ? '' : $this->stringValue();

        if (\array_key_exists('value', $attrs)) {
            if ($hasNoValue) {
                throw new FormConfigException("the value of {$field->type->value} field '{$field->name}' cannot be specified");
            }

            $value = $this->attributeString($attrs['value'], 'value');
            unset($attrs['value']);
        }

        return FormHtml::input($type, $this->name(), $value, $this->withDefaultId($attrs));
    }

    /**
     * @param array<array-key, mixed> $attrs id は上書きできる。name は指定できない
     *
     * @throws FormConfigException
     */
    public function textarea(array $attrs = []): string
    {
        $field = $this->baseField();

        if ($field->type !== FieldType::Textarea) {
            throw new FormConfigException("field '{$field->name}' cannot be rendered as textarea");
        }

        return FormHtml::textarea($this->name(), $this->stringValue(), $this->withDefaultId(FormHtml::normalizeAttributes($attrs)));
    }

    /**
     * 単一選択の `<select>`。複数選択は checkboxes の項目で行うため、multiple は指定できない。
     *
     * @param array<array-key, mixed> $attrs id は上書きできる。name・multiple は指定できない
     *
     * @throws FormConfigException
     */
    public function select(array $attrs = []): string
    {
        $field = $this->baseField();

        if ($field->type !== FieldType::Select) {
            throw new FormConfigException("field '{$field->name}' cannot be rendered as select");
        }

        $attrs = FormHtml::normalizeAttributes($attrs);

        if (\array_key_exists('multiple', $attrs)) {
            throw new FormConfigException("select field '{$field->name}' cannot be multiple; use a checkboxes field");
        }

        return FormHtml::select($this->name(), $field->choices, $this->stringValue(), $this->withDefaultId($attrs));
    }

    /** @return list<OptionView> */
    public function options(): array
    {
        $field = $this->baseField();

        if (!\in_array($field->type, [FieldType::Radios, FieldType::Checkboxes], true)) {
            throw new FormConfigException("field '{$field->name}' does not have options");
        }

        $values = $field->multiple ? $this->listValue() : [$this->stringValue()];
        $options = [];
        $index = 0;

        foreach ($field->choices as $value => $label) {
            $options[] = new OptionView((string) $value, $label, \in_array((string) $value, $values, true), $this->id() . '-' . $index, $this->name(), $field->type);
            ++$index;
        }

        return $options;
    }

    /**
     * @param array<array-key, mixed> $attrs 各選択肢の input の属性。id は選択肢ごとに決まるため指定できない
     * @param array<array-key, mixed> $labelAttrs 各選択肢の label の属性。for は指定できない
     *
     * @throws FormConfigException
     */
    public function checkboxes(array $attrs = [], array $labelAttrs = []): string
    {
        return $this->optionLabels(FieldType::Checkboxes, $attrs, $labelAttrs);
    }

    /**
     * @param array<array-key, mixed> $attrs 各選択肢の input の属性。id は選択肢ごとに決まるため指定できない
     * @param array<array-key, mixed> $labelAttrs 各選択肢の label の属性。for は指定できない
     *
     * @throws FormConfigException
     */
    public function radios(array $attrs = [], array $labelAttrs = []): string
    {
        return $this->optionLabels(FieldType::Radios, $attrs, $labelAttrs);
    }

    /**
     * @param array<array-key, mixed> $attrs id は上書きできる。type・name・value・checked は指定できない
     *
     * @throws FormConfigException
     */
    public function checkbox(array $attrs = []): string
    {
        $field = $this->baseField();

        if ($field->type !== FieldType::Checkbox) {
            throw new FormConfigException("field '{$field->name}' cannot be rendered as checkbox");
        }

        return FormHtml::checkbox($this->name(), $field->checkedValue, $this->stringValue() === $field->checkedValue, $this->withDefaultId(FormHtml::normalizeAttributes($attrs)));
    }

    /**
     * 現在の値を持つ `<input type="hidden">`(ファイルの項目では {name}__token の hidden)。
     *
     * @throws FormConfigException パスワードの項目(値を HTML に出さない)
     */
    public function hidden(): string
    {
        $field = $this->baseField();

        if ($field->type === FieldType::Password) {
            throw new FormConfigException("password field '{$field->name}' cannot be rendered as hidden");
        }

        if ($field->type === FieldType::File) {
            $value = $this->value instanceof StoredFile ? $this->value->token : '';

            return FormHtml::hidden($field->name . '__token', $value);
        }

        return FormHtml::hidden($this->name(), $this->stringValue());
    }

    public function part(string $key): self
    {
        if (!$this->field instanceof CompositeField) {
            throw new FormConfigException('part() can be called only for composite fields');
        }

        $partFields = $this->field->parts();

        if (!\array_key_exists($key, $partFields)) {
            throw new FormConfigException("unknown composite part: {$key}");
        }

        if (\is_array($this->value)) {
            $value = $this->value[$key] ?? '';
        } else {
            $source = $this->stringValue();
            $partValues = $this->field->decompose($source);
            $value = $partValues[$key] ?? '';
        }

        return new self($this->field, $value, $this->errors, $this->formId, $this->errorTemplate, $key, $partFields[$key]);
    }

    /**
     * エラーの HTML。テンプレートの `%s` を、エスケープ済みのメッセージに置き換える(`%s` 以外の `%` はそのまま)。
     *
     * @param ?string $template null なら FormDefinition::errorHtml()
     *
     * @throws FormConfigException テンプレートが `%s` を含まない場合
     */
    public function errors(?string $template = null, bool $firstOnly = false): string
    {
        $template ??= $this->errorTemplate;

        if (!str_contains($template, '%s')) {
            throw new FormConfigException('error html template must contain %s');
        }

        $errors = $firstOnly ? \array_slice($this->errors, 0, 1) : $this->errors;
        $html = '';

        foreach ($errors as $error) {
            // strtr は置き換えた後の文字列を再び走査しないため、メッセージに %s があってもそのまま出る
            $html .= strtr($template, ['%s' => esc_html($error)]);
        }

        return $html;
    }

    public function hasError(): bool
    {
        return $this->errors !== [];
    }

    /** @return list<string> */
    public function errorList(): array
    {
        return $this->errors;
    }

    /** ラベル(エスケープ済み)。部品のビューは部品の、複合フィールドのビュー(part() なし)は複合フィールドのラベル */
    public function label(): string
    {
        return esc_html(($this->partField ?? $this->field)->label);
    }

    /**
     * `<label for="{id()}">`。input() 等で id を変えたときは、同じ値を `$attrs['for']` に指定する。
     *
     * @param array<array-key, mixed> $attrs
     *
     * @throws FormConfigException
     */
    public function labelTag(array $attrs = []): string
    {
        $attrs = FormHtml::normalizeAttributes($attrs);
        $for = $this->id();

        if (\array_key_exists('for', $attrs)) {
            $for = $this->attributeString($attrs['for'], 'for');
            unset($attrs['for']);
        }

        return FormHtml::label($this->baseField()->label, $for, $attrs);
    }

    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * 確認画面に出す表示(エスケープ済み)。選択肢はラベルに、単一のチェックボックスはチェックしたときの文言
     * (Field::checkbox() の checkedLabel)に変える。
     *
     * @throws FormConfigException パスワードの項目(パスワードの部品と、それを含む複合フィールドを含む)と、checkedLabel の
     *                             無い単一のチェックボックスの項目
     */
    public function display(?string $separator = null): string
    {
        $separator ??= __(', ', 'tmswp');

        // 部品のビューも、内部の field は複合フィールドの親のままのため、部品の実際の型で判定する
        if ($this->partField instanceof Field && $this->partField->type === FieldType::Password) {
            throw new FormConfigException("password field '{$this->partField->name}' cannot be displayed");
        }

        if ($this->field instanceof CompositeField) {
            if (self::hasPasswordPart($this->field)) {
                throw new FormConfigException("composite field '{$this->field->name}' has a password part and cannot be displayed");
            }

            return esc_html($this->field->display($this->stringValue()));
        }

        if ($this->field->type === FieldType::Password) {
            throw new FormConfigException("password field '{$this->field->name}' cannot be displayed");
        }

        if ($this->field->type === FieldType::Checkbox) {
            if ($this->field->checkedLabel === null) {
                throw new FormConfigException("checkbox field '{$this->field->name}' needs checkedLabel to be displayed");
            }

            return $this->stringValue() === $this->field->checkedValue ? esc_html($this->field->checkedLabel) : '';
        }

        if ($this->value instanceof UploadedFile || $this->value instanceof StoredFile) {
            return esc_html($this->value->clientName);
        }

        if (\is_array($this->value)) {
            $labels = [];

            foreach ($this->value as $value) {
                $string = Validator::stringValue($value);

                if ($string !== null) {
                    $labels[] = $this->choiceLabel($string);
                }
            }

            return esc_html(implode($separator, $labels));
        }

        $display = $this->choiceLabel($this->stringValue());

        return $this->field->type === FieldType::Textarea
            ? nl2br(esc_html($display), false)
            : esc_html($display);
    }

    /** 既定の id(`{formId}-{name}`。複合フィールドの部品は `{formId}-{name}-{part}`) */
    public function id(): string
    {
        return $this->partKey === null ? $this->formId . '-' . $this->baseField()->name : $this->formId . '-' . $this->field->name . '-' . $this->partKey;
    }

    public function name(): string
    {
        $field = $this->baseField();

        if ($this->partKey !== null && $this->field instanceof CompositeField) {
            return $this->field->name . '[' . $this->partKey . ']';
        }

        return $field->multiple ? $field->name . '[]' : $field->name;
    }

    private function baseField(): Field
    {
        if ($this->partField instanceof Field) {
            return $this->partField;
        }

        if (!$this->field instanceof Field) {
            throw new FormConfigException('composite field requires part() before rendering input controls');
        }

        return $this->field;
    }

    /** @return list<string> */
    private function listValue(): array
    {
        if (!\is_array($this->value)) {
            return [];
        }

        $values = [];

        foreach ($this->value as $value) {
            $string = Validator::stringValue($value);

            if ($string !== null) {
                $values[] = $string;
            }
        }

        return $values;
    }

    private function stringValue(): string
    {
        return Validator::stringValue($this->value) ?? '';
    }

    private function choiceLabel(string $value): string
    {
        if ($this->field instanceof Field && \array_key_exists($value, $this->field->choices)) {
            return $this->field->choices[$value];
        }

        return $value;
    }

    /** 正準値にパスワードが入る複合フィールドか */
    private static function hasPasswordPart(CompositeField $field): bool
    {
        foreach ($field->parts() as $part) {
            if ($part->type === FieldType::Password) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $attrs 正規化済みの属性
     *
     * @return array<string, mixed>
     */
    private function withDefaultId(array $attrs): array
    {
        return array_replace(['id' => $this->id()], $attrs);
    }

    /**
     * @param array<array-key, mixed> $attrs
     * @param array<array-key, mixed> $labelAttrs
     */
    private function optionLabels(FieldType $expected, array $attrs, array $labelAttrs): string
    {
        if ($this->baseField()->type !== $expected) {
            throw new FormConfigException('option label renderer does not match field type');
        }

        $attrs = FormHtml::normalizeAttributes($attrs);
        $labelAttrs = FormHtml::normalizeAttributes($labelAttrs);

        // 1つの属性を全選択肢に付けるため、選択肢ごとに決まる id・for は指定できない(重複した id になる)
        if (\array_key_exists('id', $attrs) || \array_key_exists('for', $labelAttrs)) {
            throw new FormConfigException('id and for cannot be specified for all options at once; render options() one by one');
        }

        $html = '';

        foreach ($this->options() as $option) {
            $html .= '<label' . Html::attributes(['for' => $option->id] + $labelAttrs) . '>' . $option->input($attrs) . ' ' . esc_html($option->label) . '</label>';
        }

        return $html;
    }

    private function attributeString(mixed $value, string $name): string
    {
        $string = Validator::stringValue($value);

        if ($string === null) {
            throw new FormConfigException("attribute '{$name}' must be scalar or Stringable");
        }

        return $string;
    }
}
