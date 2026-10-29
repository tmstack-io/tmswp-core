<?php

declare(strict_types=1);

namespace TMSWP\Core\User;

use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Kernel\BootInitializerInterface;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use TMSWP\Core\Meta\ObjectType;

final class UserRoleInitializer implements BootInitializerInterface
{
    /**
     * メタスキーマを MetaSchemaRegistry に登録する(boot の中)。ユーザーのメタにサブタイプは無い。
     *
     * @throws DiscoveryException コンポーネントが UserRole を継承しない場合
     */
    public function initializeAtBoot(DiscoveredComponent $component, Kernel $kernel): void
    {
        $kernel->container()->get(MetaSchemaRegistry::class)->registerEntity(self::userRoleClass($component), ObjectType::User, '');
    }

    /**
     * ロールを WordPress に登録する(init 11)。
     *
     * @throws DiscoveryException コンポーネントが UserRole を継承しない場合
     */
    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        self::userRoleClass($component)::register($kernel);
    }

    /**
     * @throws DiscoveryException
     *
     * @return class-string<UserRole>
     */
    private static function userRoleClass(DiscoveredComponent $component): string
    {
        if (!is_subclass_of($component->class, UserRole::class)) {
            throw new DiscoveryException("user role component must extend UserRole: {$component->class}");
        }

        return $component->class;
    }
}
