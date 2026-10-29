<?php

declare(strict_types=1);

namespace TMSWP\Core\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;

final class ScopedLog implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(
        private readonly LoggerRegistry $registry,
        private readonly string $scope,
    ) {}

    /**
     * @param mixed $level
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->registry->dispatch($level, $message, $context, $this->scope);
    }
}
