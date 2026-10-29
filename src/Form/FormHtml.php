<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Support\Html;

/**
 * HTML の断片を作る純関数のレンダラ。引数で決める属性(input の type・name・value 等)を `$attrs` でも指定すると、
 * どちらを使うかが曖昧になるため FormConfigException にする(黙ってどちらかを捨てない)。
 */
final class FormHtml
{
    private function __construct() {}

    /**
     * @param array<array-key, mixed> $attrs type・name・value は指定できない
     *
     * @throws FormConfigException
     */
    public static function input(string $type, string $name, string $value, array $attrs = []): string
    {
        $attrs = self::normalizeAttributes($attrs);
        self::rejectAttributes($attrs, 'type', 'name', 'value');

        return '<input' . Html::attributes(['type' => $type, 'name' => $name, 'value' => $value] + $attrs) . '>';
    }

    /**
     * @param array<array-key, mixed> $attrs name は指定できない
     *
     * @throws FormConfigException
     */
    public static function textarea(string $name, string $value, array $attrs = []): string
    {
        $attrs = self::normalizeAttributes($attrs);
        self::rejectAttributes($attrs, 'name');

        return '<textarea' . Html::attributes(['name' => $name] + $attrs) . '>' . esc_html($value) . '</textarea>';
    }

    /**
     * @param array<array-key, string> $choices
     * @param string|list<string> $selected
     * @param array<array-key, mixed> $attrs name は指定できない
     *
     * @throws FormConfigException
     */
    public static function select(string $name, array $choices, string|array $selected, array $attrs = []): string
    {
        $attrs = self::normalizeAttributes($attrs);
        self::rejectAttributes($attrs, 'name');
        $selectedValues = \is_array($selected) ? array_values(array_map(static fn(string $value): string => $value, $selected)) : [$selected];
        $html = '<select' . Html::attributes(['name' => $name] + $attrs) . '>';

        foreach ($choices as $value => $label) {
            $html .= '<option' . Html::attributes([
                'value' => $value,
                'selected' => \in_array((string) $value, $selectedValues, true),
            ]) . '>' . esc_html($label) . '</option>';
        }

        return $html . '</select>';
    }

    /**
     * @param array<array-key, mixed> $attrs type・name・value・checked は指定できない
     *
     * @throws FormConfigException
     */
    public static function checkbox(string $name, string $value, bool $checked, array $attrs = []): string
    {
        return self::checkable('checkbox', $name, $value, $checked, $attrs);
    }

    /**
     * @param array<array-key, mixed> $attrs type・name・value・checked は指定できない
     *
     * @throws FormConfigException
     */
    public static function radio(string $name, string $value, bool $checked, array $attrs = []): string
    {
        return self::checkable('radio', $name, $value, $checked, $attrs);
    }

    public static function hidden(string $name, string $value): string
    {
        return self::input('hidden', $name, $value);
    }

    /**
     * @param array<array-key, mixed> $attrs for は指定できない
     *
     * @throws FormConfigException
     */
    public static function label(string $text, string $for, array $attrs = []): string
    {
        $attrs = self::normalizeAttributes($attrs);
        self::rejectAttributes($attrs, 'for');

        return '<label' . Html::attributes(['for' => $for] + $attrs) . '>' . esc_html($text) . '</label>';
    }

    /**
     * `<form>` の開始タグ。method は常に post。
     *
     * @param string $action 送信先。'' は自分のページ
     * @param bool $multipart true なら enctype="multipart/form-data" を付ける
     * @param array<array-key, mixed> $attrs method・action・enctype は指定できない
     *
     * @throws FormConfigException
     */
    public static function openTag(string $action, bool $multipart, array $attrs = []): string
    {
        $attrs = self::normalizeAttributes($attrs);
        self::rejectAttributes($attrs, 'method', 'action', 'enctype');

        return '<form' . Html::attributes([
            'method' => 'post',
            'action' => $action,
            'enctype' => $multipart ? 'multipart/form-data' : null,
        ] + $attrs) . '>';
    }

    /**
     * 属性の指定を、小文字の属性名をキーにした形にそろえる。HTML の属性名は大文字と小文字を区別しないため、
     * `['ID' => 'x']` も id の指定として扱う。名前だけの指定(`['required']`)は `['required' => true]` にし、
     * 名前だけの指定のうち空文字列と文字列でない値は、Support\Html::attributes() と同じく属性にしない
     * (`[$required ? 'required' : '']` の書き方のため)。
     *
     * @param array<array-key, mixed> $attrs
     *
     * @throws FormConfigException 同じ属性を2回指定した場合(大文字と小文字の違いを含む)
     *
     * @return array<string, mixed>
     */
    public static function normalizeAttributes(array $attrs): array
    {
        $normalized = [];

        foreach ($attrs as $key => $value) {
            if (\is_int($key)) {
                if (!\is_string($value) || $value === '') {
                    continue;
                }

                $key = $value;
                $value = true;
            }

            $name = strtolower($key);

            if (\array_key_exists($name, $normalized)) {
                throw new FormConfigException("attribute '{$name}' is specified more than once");
            }

            $normalized[$name] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<array-key, mixed> $attrs
     *
     * @throws FormConfigException
     */
    private static function checkable(string $type, string $name, string $value, bool $checked, array $attrs): string
    {
        $attrs = self::normalizeAttributes($attrs);
        self::rejectAttributes($attrs, 'checked');

        return self::input($type, $name, $value, ['checked' => $checked] + $attrs);
    }

    /**
     * @param array<string, mixed> $attrs 正規化済みの属性
     *
     * @throws FormConfigException
     */
    private static function rejectAttributes(array $attrs, string ...$names): void
    {
        foreach ($names as $name) {
            if (\array_key_exists($name, $attrs)) {
                throw new FormConfigException("attribute '{$name}' cannot be specified here; it is set by the form");
            }
        }
    }
}
