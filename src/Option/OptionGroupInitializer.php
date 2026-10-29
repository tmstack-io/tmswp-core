<?php

declare(strict_types=1);

namespace TMSWP\Core\Option;

use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Kernel\ComponentInitializerInterface;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\Kernel;

final class OptionGroupInitializer implements ComponentInitializerInterface
{
    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        if (!enum_exists($component->class)) {
            throw new DiscoveryException("option group component must be an enum: {$component->class}");
        }

        $factory = $kernel->container()->get(OptionRepositoryFactory::class);

        $factory->preload($component->class);
    }
}
