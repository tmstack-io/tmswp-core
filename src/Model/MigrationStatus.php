<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

final readonly class MigrationStatus
{
    /**
     * @param class-string<Model> $model
     * @param string $table ベアテーブル名(prefix なし)
     * @param bool $current 定義のハッシュが保存済みのハッシュと同じか
     * @param string|null $storedHash 保存済みのハッシュ。一度も適用していなければ null
     */
    public function __construct(
        public string $model,
        public string $table,
        public bool $current,
        public string $schemaHash,
        public ?string $storedHash,
    ) {}
}
