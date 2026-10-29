<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Rule;

use TMSWP\Core\Form\ValidationContext;

interface RuleInterface
{
    public function skipIfEmpty(): bool;

    public function eachElement(): bool;

    public function validate(mixed $value, ValidationContext $context): ?string;
}
