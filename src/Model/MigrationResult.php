<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

final readonly class MigrationResult
{
    /**
     * @param class-string<Model> $model
     * @param string $table ベアテーブル名(prefix なし)
     */
    public function __construct(
        public string $model,
        public string $table,
        public MigrationAction $action,
        public string $schemaHash,
    ) {}
}
