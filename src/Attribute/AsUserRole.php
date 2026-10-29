<?php

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

use Attribute;
use TMSWP\Core\Kernel\KernelContext;
use TMSWP\Core\User\UserRoleInitializer;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsUserRole implements ComponentAttributeInterface
{
    public function hookName(KernelContext $context): string
    {
        return 'init';
    }

    /** 投稿タイプ(#[AsPostType] の init 10)の後に登録するため、priority を分ける */
    public function hookPriority(): int
    {
        return 11;
    }

    public function initializerClass(): string
    {
        return UserRoleInitializer::class;
    }
}
