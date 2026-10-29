<?php

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

use Attribute;
use TMSWP\Core\Kernel\KernelContext;
use TMSWP\Core\Model\ModelInitializer;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsModel implements ComponentAttributeInterface
{
    public function hookName(KernelContext $context): string
    {
        return $context->bootHook();
    }

    public function hookPriority(): int
    {
        return 1;
    }

    public function initializerClass(): string
    {
        return ModelInitializer::class;
    }
}
