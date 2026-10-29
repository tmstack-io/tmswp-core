<?php

declare(strict_types=1);

namespace TMSWP\Core\Support;

final class Arr
{
    private function __construct() {}

    /**
     * @param array<array-key, mixed> $array
     */
    public static function get(array $array, string|int $key, mixed $default = null): mixed
    {
        if (\is_int($key)) {
            return \array_key_exists($key, $array) ? $array[$key] : $default;
        }

        $current = $array;

        foreach (explode('.', $key) as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param array<array-key, mixed> $array
     */
    public static function has(array $array, string|int $key): bool
    {
        if (\is_int($key)) {
            return \array_key_exists($key, $array);
        }

        $current = $array;

        foreach (explode('.', $key) as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return false;
            }

            $current = $current[$segment];
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $array
     */
    public static function set(array &$array, string|int $key, mixed $value): void
    {
        if (\is_int($key)) {
            $array[$key] = $value;

            return;
        }

        $current = &$array;

        foreach (explode('.', $key) as $segment) {
            if (!\array_key_exists($segment, $current) || !\is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }

        $current = $value;
    }

    /**
     * @param array<array-key, mixed> $array
     * @param list<string|int> $keys
     *
     * @return array<array-key, mixed>
     */
    public static function only(array $array, array $keys): array
    {
        $wanted = array_flip($keys);
        $result = [];

        foreach ($array as $key => $value) {
            if (\array_key_exists($key, $wanted)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $array
     * @param null|callable(mixed $value, string|int $key): bool $predicate
     */
    public static function first(array $array, ?callable $predicate = null, mixed $default = null): mixed
    {
        foreach ($array as $key => $value) {
            if ($predicate === null || $predicate($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    /** @return array<array-key, mixed> */
    public static function wrap(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (\is_array($value)) {
            return $value;
        }

        return [$value];
    }
}
