<?php

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

use Attribute;
use TMSWP\Core\Hook\HookInitializer;
use TMSWP\Core\Kernel\KernelContext;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsHook implements ComponentAttributeInterface
{
    public function hookName(KernelContext $context): string
    {
        return $context->bootHook();
    }

    public function hookPriority(): int
    {
        return 0;
    }

    public function initializerClass(): string
    {
        return HookInitializer::class;
    }
}
