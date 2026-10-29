<?php

declare(strict_types=1);

namespace TMSWP\Core\Hook;

use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Kernel\ComponentInitializerInterface;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\Kernel;

final class HookInitializer implements ComponentInitializerInterface
{
    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        $hook = $kernel->container()->get($component->class);

        if (!$hook instanceof Hook) {
            throw new DiscoveryException("hook component must extend Hook: {$component->class}");
        }

        $hook->register();
    }
}
