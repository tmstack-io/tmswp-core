<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

interface ComponentInitializerInterface
{
    public function initialize(DiscoveredComponent $component, Kernel $kernel): void;
}
