<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Rule;

use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;

final class Choice extends Rule
{
    /** @var list<string> */
    private readonly array $choices;

    /** @param array<array-key, scalar> $choices */
    public function __construct(array $choices, ?string $message = null)
    {
        if ($choices === []) {
            throw new FormConfigException('Choice values must not be empty');
        }

        $this->choices = array_values(array_map('strval', $choices));

        parent::__construct($message);
    }

    protected function passes(mixed $value, ValidationContext $context): bool
    {
        $candidate = Validator::stringValue($value);

        return $candidate !== null && \in_array($candidate, $this->choices, true);
    }

    public function defaultMessage(): string
    {
        return __('{label} has an invalid choice.', 'tmswp');
    }
}
