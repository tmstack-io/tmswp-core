<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Rule;

use TMSWP\Core\Form\Messages;
use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;

final class Retype implements RuleInterface
{
    public function __construct(
        private readonly string $field,
        private readonly ?string $message = null,
    ) {}

    public function skipIfEmpty(): bool
    {
        return true;
    }

    public function eachElement(): bool
    {
        return false;
    }

    public function validate(mixed $value, ValidationContext $context): ?string
    {
        $current = Validator::stringValue($value);
        $other = Validator::stringValue($context->value($this->field));

        if ($current !== null && $other !== null && $current === $other) {
            return null;
        }

        return Messages::resolve(
            $this,
            $this->message ?? __('{label} must match {otherLabel}.', 'tmswp'),
            $context,
            ['otherLabel' => $context->label($this->field)],
        );
    }
}
