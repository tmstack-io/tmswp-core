<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

/**
 * メタ値の物理ストレージ。
 *
 * 書き込み(set・setAll・delete)は、書き込んだかどうかを bool で返し、データベースのエラーは例外で知らせる。
 * false は失敗ではなく「書き込まなかった」(同じ値、ほかのプラグインのフィルタによる中止、delete では行が無かった)
 * を意味し、保存されている値は変わっていない。
 * null は保存しない。削除は delete() で行う。
 */
interface MetaStorageInterface
{
    public function has(ObjectType $type, int $objectId, string $key): bool;

    public function get(ObjectType $type, int $objectId, string $key): mixed;

    /** @return list<mixed> */
    public function getAll(ObjectType $type, int $objectId, string $key): array;

    /**
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(ObjectType $type, int $objectId, array $keys): array;

    /**
     * 単一値を保存する(既存の行は置き換える)。
     *
     * @throws \TMSWP\Core\Exception\InvalidArgumentException 保存できない値(null 等)
     * @throws \TMSWP\Core\Exception\RuntimeException データベースのエラー
     *
     * @return bool 書き込んだら true、書き込まなかったら false(保存値は変わっていない)
     */
    public function set(ObjectType $type, int $objectId, string $key, mixed $value): bool;

    /**
     * 同じキーの全行を置き換える(single=false のスキーマ用)。
     *
     * @param list<mixed> $values
     *
     * @throws \TMSWP\Core\Exception\InvalidArgumentException 保存できない値(null 等)
     * @throws \TMSWP\Core\Exception\RuntimeException 削除・追加でのデータベースのエラー
     *
     * @return bool 保存された行を変えたら true、置き換え前と同じ行なら false
     */
    public function setAll(ObjectType $type, int $objectId, string $key, array $values): bool;

    /**
     * 指定キーの全行を削除する。
     *
     * @throws \TMSWP\Core\Exception\RuntimeException データベースのエラー
     *
     * @return bool 行を削除したら true、元々行が無かった・削除しなかったら false
     */
    public function delete(ObjectType $type, int $objectId, string $key): bool;
}
