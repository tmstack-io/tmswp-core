<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\Rule\RuleInterface;

/**
 * ルールの違反のメッセージを解決する唯一の経路。同梱のルールと、RuleInterface を直接実装するルール(Callback 等)の
 * すべてがここを通る。
 */
final class Messages
{
    private function __construct() {}

    /**
     * `tmswp/form/rule_message` フィルタを適用してから、{label}(検証中の項目のラベル)と $params の {key} を置き換える。
     * フィルタは置き換えの前に効くため、上書きした文言の中のプレースホルダも展開される。
     *
     * @param string $message 置き換え前のメッセージ(ルールの個別のメッセージか、既定のメッセージ)
     * @param array<string, scalar|null> $params {label} 以外のプレースホルダの値。{label} も上書きできる
     *
     * @throws FormConfigException フィルタが文字列以外を返した場合
     */
    public static function resolve(RuleInterface $rule, string $message, ValidationContext $context, array $params = []): string
    {
        $filtered = apply_filters('tmswp/form/rule_message', $message, $rule, $context);

        if (!\is_string($filtered)) {
            throw new FormConfigException('the filter tmswp/form/rule_message must return a string, got ' . get_debug_type($filtered));
        }

        $replace = [];

        foreach (['label' => $context->label($context->currentField), ...$params] as $key => $value) {
            $replace['{' . $key . '}'] = (string) $value;
        }

        return strtr($filtered, $replace);
    }
}
