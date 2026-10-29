<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

/**
 * スパム対策(honeypot・Turnstile 等)をフォームに差し込む口。`FormDefinition::spamGuard()` で登録する。
 *
 * 検査は、利用者の入力を受け取る POST(確認画面ありのフォームは確認、無いフォームは送信)で、CSRF と送信トークンの
 * 検証の後、値の検証の前に行う。確認画面からの送信では行わない(確認の時点で通っているため)。
 * 手本の実装は packages/core/rules/form.md にある。
 */
interface SpamGuardInterface
{
    /**
     * 入力画面の `Form::open()` が、フォームの hidden の後に出力する HTML。エスケープ済みの文字列を返す。
     * 出力するものが無ければ ''。
     */
    public function render(string $formId): string;

    /**
     * POST を検査する。送信を受け付けてよければ true、スパムとして拒否するなら false。
     */
    public function passes(FormRequest $request): bool;
}
