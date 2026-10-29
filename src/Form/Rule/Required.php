<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Rule;

use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;

final class Required extends Rule
{
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
        return !Validator::isEmpty($value);
    }

    public function defaultMessage(): string
    {
        return __('{label} is required.', 'tmswp');
    }
}
