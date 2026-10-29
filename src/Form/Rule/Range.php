<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Rule;

use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;

final class Range extends Rule
{
    public function __construct(
        private readonly int|float $min,
        private readonly int|float $max,
        ?string $message = null,
    ) {
        if ($min > $max) {
            throw new FormConfigException('Range min must be less than or equal to max');
        }

        parent::__construct($message);
    }

    protected function passes(mixed $value, ValidationContext $context): bool
    {
        $text = Validator::stringValue($value);

        if ($text === null || !is_numeric($text)) {
            return false;
        }

        $number = (float) $text;

        return $number >= $this->min && $number <= $this->max;
    }

    public function defaultMessage(): string
    {
        return __('{label} must be between {min} and {max}.', 'tmswp');
    }

    public function messageParams(): array
    {
        return ['min' => $this->min, 'max' => $this->max];
    }
}
