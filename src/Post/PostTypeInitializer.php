<?php

declare(strict_types=1);

namespace TMSWP\Core\Post;

use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Kernel\BootInitializerInterface;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use TMSWP\Core\Meta\ObjectType;

final class PostTypeInitializer implements BootInitializerInterface
{
    /**
     * メタスキーマを MetaSchemaRegistry に登録する(boot の中)。
     *
     * @throws DiscoveryException コンポーネントが PostType を継承しない場合
     */
    public function initializeAtBoot(DiscoveredComponent $component, Kernel $kernel): void
    {
        $class = self::postTypeClass($component);

        $kernel->container()->get(MetaSchemaRegistry::class)->registerEntity($class, ObjectType::Post, $class::slug());
    }

    /**
     * 投稿タイプを WordPress に登録する(init 10)。
     *
     * @throws DiscoveryException コンポーネントが PostType を継承しない場合
     */
    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        self::postTypeClass($component)::register($kernel);
    }

    /**
     * @throws DiscoveryException
     *
     * @return class-string<PostType>
     */
    private static function postTypeClass(DiscoveredComponent $component): string
    {
        if (!is_subclass_of($component->class, PostType::class)) {
            throw new DiscoveryException("post type component must extend PostType: {$component->class}");
        }

        return $component->class;
    }
}
