<?php

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

use TMSWP\Core\Kernel\ComponentInitializerInterface;
use TMSWP\Core\Kernel\KernelContext;

interface ComponentAttributeInterface
{
    public function hookName(KernelContext $context): string;

    public function hookPriority(): int;

    /** @return class-string<ComponentInitializerInterface> */
    public function initializerClass(): string;
}
