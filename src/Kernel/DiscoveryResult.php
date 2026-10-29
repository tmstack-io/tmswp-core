<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

use TMSWP\Core\Block\DiscoveredBlock;

final readonly class DiscoveryResult
{
    /**
     * @param list<DiscoveredComponent> $components
     * @param list<DiscoveredHookMethod> $hookMethods
     * @param list<DiscoveredBlock> $blocks ビルド出力(`build/blocks/`)の独自ブロック。ブロック名の昇順
     */
    public function __construct(
        public array $components,
        public array $hookMethods,
        public array $blocks = [],
    ) {}
}
