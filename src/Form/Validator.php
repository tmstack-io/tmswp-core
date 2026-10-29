<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use Stringable;
use TMSWP\Core\Form\Composite\CompositeField;
use TMSWP\Core\Form\Rule\Choice;

final class Validator
{
    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, list<string>>
     */
    public function validate(FormDefinition $definition, array $values): array
    {
        $errors = [];

        foreach ($definition->fields() as $name => $field) {
            $context = new ValidationContext($definition, $values, $name);
            $value = $values[$name] ?? null;

            if ($field instanceof CompositeField) {
                $message = $this->validateComposite($field, $value, $context);

                if ($message !== null) {
                    $errors[$name] = [$message];
                }

                continue;
            }

            $message = $this->validateField($field, $value, $context);

            if ($message !== null) {
                $errors[$name] = [$message];
            }
        }

        return $errors;
    }

    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    public static function stringValue(mixed $value): ?string
    {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value) || \is_bool($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        return null;
    }

    /**
     * 選べる値の暗黙の検証の後に、項目の rules を検証する。複合フィールドのパーツも同じ手順で検証する。
     */
    private function validateField(Field $field, mixed $value, ValidationContext $context): ?string
    {
        if ($field->multiple && !\is_array($value)) {
            // 取り込みと同じく、複数選択の配列でない値は1つの要素として包む(空値は [])。暗黙の検証と明示した規則の
            // どちらも、配列で渡したときと同じ結果にする
            $value = self::isEmpty($value) ? [] : [$value];
        }

        $choice = self::implicitChoice($field);

        if ($choice !== null && !self::isEmpty($value)) {
            // 複数選択は要素ごとに選べる値かを確かめる
            $elements = $field->multiple && \is_array($value) ? $value : [$value];

            foreach ($elements as $element) {
                $message = $choice->validate($element, $context);

                if ($message !== null) {
                    return $message;
                }
            }
        }

        return $this->validateRules($field->rules, $value, $context, $field->multiple);
    }

    /**
     * 選べる値が決まっている項目(choices を持つ項目と単一のチェックボックス)に暗黙に適用する Choice。
     * メッセージは明示した Choice と同じく Messages で解決する(フィルタには、この Choice が渡る)。
     */
    private static function implicitChoice(Field $field): ?Choice
    {
        if ($field->type === FieldType::Checkbox) {
            return new Choice([$field->checkedValue]);
        }

        return $field->isChoiceField() ? new Choice(array_keys($field->choices)) : null;
    }

    /** @param list<Rule\RuleInterface> $rules */
    private function validateRules(array $rules, mixed $value, ValidationContext $context, bool $multiple): ?string
    {
        foreach ($rules as $rule) {
            if ($multiple && $rule->eachElement()) {
                $elements = \is_array($value) ? $value : [];

                foreach ($elements as $element) {
                    if ($rule->skipIfEmpty() && self::isEmpty($element)) {
                        continue;
                    }

                    $message = $rule->validate($element, $context);

                    if ($message !== null) {
                        return $message;
                    }
                }

                continue;
            }

            if ($rule->skipIfEmpty() && self::isEmpty($value)) {
                continue;
            }

            $message = $rule->validate($value, $context);

            if ($message !== null) {
                return $message;
            }
        }

        return null;
    }

    private function validateComposite(CompositeField $field, mixed $value, ValidationContext $context): ?string
    {
        $partValues = \is_array($value) ? $this->normalizePartValues($field, $value) : $this->emptyPartValues($field);

        if ($this->allPartsEmpty($partValues)) {
            $canonical = '';
        } elseif (!$this->allPartsFilled($partValues)) {
            return strtr($field->incompleteMessage(), ['{label}' => $field->label]);
        } else {
            foreach ($field->parts() as $partName => $part) {
                $message = $this->validateField($part, $partValues[$partName] ?? '', $context);

                if ($message !== null) {
                    return $message;
                }
            }

            if (!$field->validateParts($partValues)) {
                return strtr($field->invalidPartsMessage(), ['{label}' => $field->label]);
            }

            $canonical = $field->compose($partValues);
        }

        return $this->validateRules($field->rules, $canonical, $context, false);
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return array<string, string>
     */
    private function normalizePartValues(CompositeField $field, array $raw): array
    {
        $values = [];

        foreach ($field->parts() as $name => $part) {
            $value = self::stringValue($raw[$name] ?? '');
            $value ??= '';

            foreach ($part->filters as $filter) {
                $value = $filter($value);
            }

            $values[$name] = $value;
        }

        return $values;
    }

    /** @return array<string, string> */
    private function emptyPartValues(CompositeField $field): array
    {
        $values = [];

        foreach ($field->parts() as $name => $_part) {
            $values[$name] = '';
        }

        return $values;
    }

    /** @param array<string, string> $values */
    private function allPartsEmpty(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, string> $values */
    private function allPartsFilled(array $values): bool
    {
        foreach ($values as $value) {
            if ($value === '') {
                return false;
            }
        }

        return true;
    }
}
