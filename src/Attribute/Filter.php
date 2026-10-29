<?php

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

use Attribute;
use TMSWP\Core\Exception\InvalidArgumentException;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Filter
{
    public function __construct(
        public string $hook,
        public int $priority = 10,
    ) {
        if ($hook === '') {
            throw new InvalidArgumentException('hook name must not be empty');
        }
    }
}
