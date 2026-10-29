<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use TMSWP\Core\Exception\Form\FormConfigException;

final readonly class OptionView
{
    public function __construct(
        public string $value,
        public string $label,
        public bool $checked,
        public string $id,
        public string $name,
        public FieldType $type,
    ) {}

    /**
     * @param array<array-key, mixed> $attrs id は上書きできる。type・name・value・checked は指定できない
     *
     * @throws FormConfigException
     */
    public function input(array $attrs = []): string
    {
        $attrs = array_replace(['id' => $this->id], FormHtml::normalizeAttributes($attrs));

        if ($this->type === FieldType::Radios) {
            return FormHtml::radio($this->name, $this->value, $this->checked, $attrs);
        }

        return FormHtml::checkbox($this->name, $this->value, $this->checked, $attrs);
    }

    /**
     * @param array<array-key, mixed> $attrs for は上書きできる(input() の id を変えたとき)
     *
     * @throws FormConfigException
     */
    public function labelTag(array $attrs = []): string
    {
        $attrs = FormHtml::normalizeAttributes($attrs);
        $for = $this->id;

        if (\array_key_exists('for', $attrs)) {
            $for = Validator::stringValue($attrs['for']) ?? throw new FormConfigException("attribute 'for' must be scalar or Stringable");
            unset($attrs['for']);
        }

        return FormHtml::label($this->label, $for, $attrs);
    }
}
