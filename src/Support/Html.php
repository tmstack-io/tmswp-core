<?php

declare(strict_types=1);

namespace TMSWP\Core\Support;

use TMSWP\Core\Exception\InvalidArgumentException;

final class Html
{
    /** 1つの URL を値に取る属性。値に esc_url() を適用する(名前は小文字で照合する) */
    private const array URL_ATTRIBUTES = [
        'href',
        'src',
        'action',
        'formaction',
        'poster',
        'cite',
        'data',
        'background',
        'longdesc',
        'manifest',
        'codebase',
        'icon',
        'xlink:href',
    ];

    /** 空白区切りの URL のリストを値に取る属性。URL ごとに esc_url() を適用する */
    private const array URL_LIST_ATTRIBUTES = [
        'ping',
    ];

    private function __construct() {}

    /**
     * @param array<int|string, mixed> $attributes
     *
     * @throws InvalidArgumentException 属性名が不正なとき、または URL 属性の値が許可されないプロトコルのとき
     */
    public static function attributes(array $attributes): string
    {
        $tokens = [];

        foreach ($attributes as $key => $value) {
            if (\is_int($key)) {
                if (!\is_string($value) || $value === '') {
                    continue;
                }

                $name = $value;
                $value = true;
            } else {
                $name = $key;
            }

            self::assertAttributeName($name);

            if ($name === 'class' && (\is_string($value) || \is_array($value))) {
                $value = self::classes($value);

                if ($value === '') {
                    continue;
                }
            }

            if ($value === null) {
                continue;
            }

            if (\is_bool($value)) {
                if (str_starts_with($name, 'aria-')) {
                    $tokens[] = $name . '="' . esc_attr($value ? 'true' : 'false') . '"';
                    continue;
                }

                if ($value) {
                    $tokens[] = $name;
                }

                continue;
            }

            if (\is_array($value)) {
                $value = implode(' ', array_map(static fn(mixed $item): string => Str::stringify($item), $value));
            } else {
                $value = Str::stringify($value);
            }

            $lowerName = strtolower($name);

            if (\in_array($lowerName, self::URL_ATTRIBUTES, true)) {
                $tokens[] = $name . '="' . self::escapeUrl($name, $value) . '"';
                continue;
            }

            if (\in_array($lowerName, self::URL_LIST_ATTRIBUTES, true)) {
                $urls = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);

                if ($urls === false) {
                    throw new InvalidArgumentException("HTML attribute {$name} has an unparsable URL list");
                }

                $tokens[] = $name . '="' . implode(' ', array_map(static fn(string $url): string => self::escapeUrl($name, $url), $urls)) . '"';
                continue;
            }

            $tokens[] = $name . '="' . esc_attr($value) . '"';
        }

        if ($tokens === []) {
            return '';
        }

        return ' ' . implode(' ', $tokens);
    }

    /** @param string|array<int|string, mixed> ...$values */
    public static function classes(string|array ...$values): string
    {
        $classes = [];

        foreach ($values as $value) {
            if (\is_string($value)) {
                $parts = preg_split('/\s+/', trim($value));

                if ($parts === false) {
                    $parts = [];
                }

                foreach ($parts as $class) {
                    if ($class !== '') {
                        $classes[$class] = true;
                    }
                }

                continue;
            }

            foreach ($value as $class => $enabled) {
                if (\is_int($class)) {
                    $className = \is_string($enabled) ? trim($enabled) : '';
                    $isEnabled = $className !== '';
                } else {
                    $className = trim($class);
                    $isEnabled = $enabled === true;
                }

                if ($isEnabled && $className !== '') {
                    $parts = preg_split('/\s+/', $className);

                    if ($parts === false) {
                        $parts = [];
                    }

                    foreach ($parts as $part) {
                        if ($part !== '') {
                            $classes[$part] = true;
                        }
                    }
                }
            }
        }

        return implode(' ', array_keys($classes));
    }

    /**
     * URL を esc_url() でエスケープする。許可されないプロトコルで空になる URL は空の属性にせず例外にする。
     *
     * @throws InvalidArgumentException
     */
    private static function escapeUrl(string $name, string $url): string
    {
        if ($url === '') {
            return '';
        }

        $escaped = esc_url($url);

        if ($escaped === '') {
            throw new InvalidArgumentException("HTML attribute {$name} has a URL with a disallowed protocol: {$url}");
        }

        return $escaped;
    }

    private static function assertAttributeName(string $name): void
    {
        if (preg_match('/\A[a-zA-Z][a-zA-Z0-9:_.-]*\z/', $name) === 1) {
            return;
        }

        throw new InvalidArgumentException("invalid HTML attribute name: {$name}");
    }
}
