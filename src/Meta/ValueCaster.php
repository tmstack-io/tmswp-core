<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

use Stringable;

final class ValueCaster
{
    private function __construct() {}

    public static function toInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value) || \is_bool($value)) {
            return (int) $value;
        }

        if (\is_string($value) && is_numeric($value)) {
            return (int) (float) $value;
        }

        return 0;
    }

    public static function toFloat(mixed $value): float
    {
        if (\is_float($value)) {
            return $value;
        }

        if (\is_int($value) || \is_bool($value)) {
            return (float) $value;
        }

        if (\is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    public static function toBool(mixed $value): bool
    {
        if ($value === false || $value === null || $value === '' || $value === '0' || $value === 0 || $value === 0.0 || $value === []) {
            return false;
        }

        if (\is_string($value) && strtolower($value) === 'false') {
            return false;
        }

        return true;
    }

    public static function toString(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if (\is_bool($value)) {
            return $value ? '1' : '';
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return '';
    }

    /** @return list<mixed> */
    public static function toList(mixed $value): array
    {
        if (\is_array($value)) {
            return array_values($value);
        }

        if ($value === null) {
            return [];
        }

        return [$value];
    }

    /** @return array<array-key, mixed> */
    public static function toJsonArray(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }

        if (!\is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE || !\is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    public static function accepts(MetaValueType $type, mixed $value): bool
    {
        return match ($type) {
            MetaValueType::String => \is_scalar($value),
            MetaValueType::Boolean => \is_bool($value),
            MetaValueType::Integer => \is_int($value),
            MetaValueType::Number => \is_int($value) || \is_float($value),
            MetaValueType::Array, MetaValueType::Object => \is_array($value),
        };
    }
}
