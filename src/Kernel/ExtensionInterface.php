<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

/**
 * Kernelごとに登録する拡張(tmswp/settings-page・tmswp/thumbnail等の拡張のパッケージ)の共通の入口。
 *
 * 拡張は `KernelConfig::theme()` / `plugin()` の `extensions` に渡したインスタンスだけが有効になる。
 * テーマとプラグインがそれぞれ同じ拡張を登録した場合は、Kernel ごとに別々に呼ばれる。
 */
interface ExtensionInterface
{
    /**
     * 登録した Kernel の boot 中に1回呼ばれる。
     *
     * debug の判定は `$kernel->config()->debug`、ログは `$kernel->logger()`、サービスは `$kernel->container()` から取る。
     * ここで投げた例外は boot の例外としてそのまま伝わる(設定の誤り。debug に関係なく起動を止める)。
     */
    public function register(Kernel $kernel): void;
}
