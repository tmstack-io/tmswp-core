<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Rule;

use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;

final class RequiredIf extends Rule
{
    private function __construct(
        private readonly string $field,
        private readonly ?string $equals,
        ?string $message = null,
    ) {
        parent::__construct($message);
    }

    public static function filled(string $field, ?string $message = null): self
    {
        return new self($field, null, $message);
    }

    public static function equals(string $field, string $value, ?string $message = null): self
    {
        return new self($field, $value, $message);
    }

    public function skipIfEmpty(): bool
    {
        return false;
    }

    public function eachElement(): bool
    {
        return false;
    }

    protected function passes(mixed $value, ValidationContext $context): bool
    {
        return !$this->conditionMet($context) || !Validator::isEmpty($value);
    }

    /** equals() の条件は、配列の値(checkboxes)ならその要素のどれかと、それ以外なら値と、文字列として厳密に比べる */
    private function conditionMet(ValidationContext $context): bool
    {
        if ($this->equals === null) {
            return $context->filled($this->field);
        }

        $actual = $context->value($this->field);
        $candidates = \is_array($actual) ? $actual : [$actual];

        foreach ($candidates as $candidate) {
            if (Validator::stringValue($candidate) === $this->equals) {
                return true;
            }
        }

        return false;
    }

    public function defaultMessage(): string
    {
        return __('{label} is required.', 'tmswp');
    }
}
