<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

/**
 * @internal
 */
final readonly class SqlQuery
{
    /** @param list<mixed> $bindings */
    public function __construct(
        public string $sql,
        public array $bindings,
    ) {}
}
