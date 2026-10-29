<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Rule;

use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;

final class Email extends Rule
{
    protected function passes(mixed $value, ValidationContext $context): bool
    {
        $email = Validator::stringValue($value);

        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function defaultMessage(): string
    {
        return __('Please enter a valid email address for {label}.', 'tmswp');
    }
}
