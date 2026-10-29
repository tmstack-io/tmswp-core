<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Rule;

use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;

final class MaxLength extends Rule
{
    public function __construct(private readonly int $length, ?string $message = null)
    {
        if ($length < 1) {
            throw new FormConfigException('MaxLength length must be greater than 0');
        }

        parent::__construct($message);
    }

    protected function passes(mixed $value, ValidationContext $context): bool
    {
        $text = Validator::stringValue($value);

        return $text !== null && mb_strlen($text, 'UTF-8') <= $this->length;
    }

    public function defaultMessage(): string
    {
        return __('{label} must be {length} characters or fewer.', 'tmswp');
    }

    public function messageParams(): array
    {
        return ['length' => $this->length];
    }
}
