<?php

declare(strict_types=1);

namespace TMSWP\Core\Support;

use BackedEnum;
use DateTimeInterface;
use JsonSerializable;
use Stringable;
use TMSWP\Core\Exception\InvalidArgumentException;
use Traversable;
use UnitEnum;

final class Str
{
    private function __construct() {}

    /** @param array<string, mixed> $vars */
    public static function replace(string $text, array $vars): string
    {
        $map = [];

        foreach ($vars as $key => $value) {
            $map['{' . $key . '}'] = self::stringify($value);
        }

        return strtr($text, $map);
    }

    public static function truncate(string $text, int $length = 200, string $ellipsis = '…'): string
    {
        if ($length < 1) {
            throw new InvalidArgumentException('truncate length must be greater than zero');
        }

        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length, 'UTF-8') . $ellipsis;
    }

    public static function excerpt(string $text, int $length = 200, string $ellipsis = '…'): string
    {
        $stripped = strip_shortcodes($text);

        return self::truncate(wp_strip_all_tags($stripped, true), $length, $ellipsis);
    }

    public static function stringify(mixed $value, string $glue = ', '): string
    {
        if ($value === null) {
            return '';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_int($value) || \is_float($value) || \is_string($value)) {
            return (string) $value;
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof Traversable) {
            return self::stringify(iterator_to_array($value, false), $glue);
        }

        if (\is_array($value)) {
            return implode($glue, array_map(static fn(mixed $item): string => self::stringify($item, $glue), $value));
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if ($value instanceof JsonSerializable) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (\is_string($json)) {
                return $json;
            }
        }

        return '(' . get_debug_type($value) . ')';
    }

    public static function convertEol(string $text, string $to = "\n"): string
    {
        // strtr は最長一致の1回の走査で置換するため、CRLF を1つの改行として扱い、置換後の文字列を再び置換しない。
        return strtr($text, ["\r\n" => $to, "\r" => $to, "\n" => $to]);
    }

    public static function snake(string $text): string
    {
        $text = preg_replace('/(?<!^)[A-Z]/', '_$0', $text) ?? $text;
        $text = str_replace('-', '_', strtolower($text));
        $text = preg_replace('/_+/', '_', $text) ?? $text;

        return ltrim($text, '_');
    }

    public static function kebab(string $text): string
    {
        return str_replace('_', '-', self::snake($text));
    }

    public static function pascal(string $text): string
    {
        $text = str_replace(['_', '-'], ' ', $text);

        return str_replace(' ', '', ucwords($text));
    }

    public static function camel(string $text): string
    {
        return lcfirst(self::pascal($text));
    }
}
