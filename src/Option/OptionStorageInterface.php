<?php

declare(strict_types=1);

namespace TMSWP\Core\Option;

/**
 * オプションの物理ストレージ。$name はプレフィックス適用済みの実オプション名。
 *
 * 書き込み(set・delete)は、書き込んだかどうかを bool で返し、データベースのエラーは例外で知らせる。
 * false は失敗ではなく「書き込まなかった」(同じ値、ほかのプラグインのフィルタによる中止、delete ではオプションが
 * 無かった)を意味し、保存されている値は変わっていない。
 * null は保存しない。削除は delete() で行う。
 */
interface OptionStorageInterface
{
    public function has(string $name): bool;

    public function get(string $name): mixed;

    /**
     * @param bool|null $autoload null は WP に委ねる(既存の値を変えない)
     *
     * @throws \TMSWP\Core\Exception\InvalidArgumentException null
     * @throws \TMSWP\Core\Exception\RuntimeException データベースのエラー
     *
     * @return bool 書き込んだら true、書き込まなかったら false(保存値は変わっていない)
     */
    public function set(string $name, mixed $value, ?bool $autoload = null): bool;

    /**
     * @throws \TMSWP\Core\Exception\RuntimeException データベースのエラー
     *
     * @return bool 削除したら true、元々オプションが無かった・削除しなかったら false
     */
    public function delete(string $name): bool;
}
