<?php

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

use Attribute;
use TMSWP\Core\Kernel\KernelContext;
use TMSWP\Core\Tax\TaxonomyInitializer;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsTaxonomy implements ComponentAttributeInterface
{
    public function hookName(KernelContext $context): string
    {
        return 'init';
    }

    public function hookPriority(): int
    {
        return 9;
    }

    public function initializerClass(): string
    {
        return TaxonomyInitializer::class;
    }
}
