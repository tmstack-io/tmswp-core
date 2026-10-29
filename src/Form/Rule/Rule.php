<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Rule;

use TMSWP\Core\Form\Messages;
use TMSWP\Core\Form\ValidationContext;

abstract class Rule implements RuleInterface
{
    public function __construct(private readonly ?string $message = null) {}

    public function skipIfEmpty(): bool
    {
        return true;
    }

    public function eachElement(): bool
    {
        return true;
    }

    final public function validate(mixed $value, ValidationContext $context): ?string
    {
        return $this->passes($value, $context) ? null : Messages::resolve($this, $this->customMessage() ?? $this->defaultMessage(), $context, $this->messageParams());
    }

    abstract protected function passes(mixed $value, ValidationContext $context): bool;

    abstract public function defaultMessage(): string;

    /** @return array<string, scalar|null> */
    public function messageParams(): array
    {
        return [];
    }

    final public function customMessage(): ?string
    {
        return $this->message;
    }
}
