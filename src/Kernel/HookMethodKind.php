<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

/**
 * フックメソッドを宣言した属性の種類(`#[Action]` か `#[Filter]` か)。
 *
 * Filter は失敗時に元の値(第1引数)を返すため、引数を宣言しないメソッドでも WordPress から第1引数を受け取る。
 */
enum HookMethodKind: string
{
    case Action = 'action';
    case Filter = 'filter';

    /**
     * WordPress に登録するときの引数の数。Filter は第1引数を必ず受け取るため、最低 1 にする。
     */
    public function registeredArgs(int $acceptedArgs): int
    {
        return match ($this) {
            self::Action => $acceptedArgs,
            self::Filter => max(1, $acceptedArgs),
        };
    }
}
