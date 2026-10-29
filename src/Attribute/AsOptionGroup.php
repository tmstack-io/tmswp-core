<?php

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

use Attribute;
use TMSWP\Core\Kernel\KernelContext;
use TMSWP\Core\Option\OptionGroupInitializer;
use TMSWP\Core\Option\OptionStorageInterface;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsOptionGroup implements ComponentAttributeInterface
{
    /**
     * @param class-string<OptionStorageInterface> $storage グループの保存先の Container の id。既定は Kernel が既定の
     *                                                      ストレージを登録した OptionStorageInterface
     */
    public function __construct(
        public string $prefix = '',
        public string $storage = OptionStorageInterface::class,
    ) {}

    public function hookName(KernelContext $context): string
    {
        return 'init';
    }

    public function hookPriority(): int
    {
        return 5;
    }

    public function initializerClass(): string
    {
        return OptionGroupInitializer::class;
    }
}
