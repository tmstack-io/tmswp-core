<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Composite;

use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\Field;
use TMSWP\Core\Form\Rule\RuleInterface;

abstract class CompositeField
{
    /**
     * @param list<RuleInterface> $rules
     *
     * @throws FormConfigException 名前が Field と同じ検査に通らない場合と、rules に RuleInterface でない要素がある場合
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly array $rules = [],
    ) {
        Field::assertName($name);
        Field::assertRules($rules);
    }

    /** @return array<string, Field> */
    abstract public function parts(): array;

    /** @param array<string, string> $partValues */
    abstract public function compose(array $partValues): string;

    /** @return array<string, string> */
    abstract public function decompose(string $value): array;

    public function incompleteMessage(): string
    {
        return __('Please complete all parts of {label}.', 'tmswp');
    }

    /** @param array<string, string> $partValues */
    public function validateParts(array $partValues): bool
    {
        return true;
    }

    public function invalidPartsMessage(): string
    {
        return __('{label} is invalid.', 'tmswp');
    }

    public function display(string $value): string
    {
        return $value;
    }
}
