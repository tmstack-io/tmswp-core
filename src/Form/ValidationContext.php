<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

final readonly class ValidationContext
{
    /** @param array<string, mixed> $values */
    public function __construct(
        private FormDefinition $definition,
        private array $values,
        public string $currentField,
    ) {}

    public function value(string $field): mixed
    {
        $this->definition->field($field);

        return $this->values[$field] ?? null;
    }

    public function filled(string $field): bool
    {
        return !Validator::isEmpty($this->value($field));
    }

    public function label(string $field): string
    {
        $definition = $this->definition->field($field);

        return $definition->label;
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values;
    }
}
