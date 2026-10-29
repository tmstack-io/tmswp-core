<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

final readonly class FormResult
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, list<string>> $fieldErrors
     * @param list<string> $formErrors
     */
    public function __construct(
        public FormStep $step,
        public array $values,
        public array $fieldErrors,
        public array $formErrors,
    ) {}

    public function isValid(): bool
    {
        return $this->fieldErrors === [] && $this->formErrors === [];
    }

    public function hasErrors(): bool
    {
        return !$this->isValid();
    }
}
