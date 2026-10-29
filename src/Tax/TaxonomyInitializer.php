<?php

declare(strict_types=1);

namespace TMSWP\Core\Tax;

use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Kernel\BootInitializerInterface;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use TMSWP\Core\Meta\ObjectType;

final class TaxonomyInitializer implements BootInitializerInterface
{
    /**
     * メタスキーマを MetaSchemaRegistry に登録する(boot の中)。
     *
     * @throws DiscoveryException コンポーネントが Taxonomy を継承しない場合
     */
    public function initializeAtBoot(DiscoveredComponent $component, Kernel $kernel): void
    {
        $class = self::taxonomyClass($component);

        $kernel->container()->get(MetaSchemaRegistry::class)->registerEntity($class, ObjectType::Term, $class::slug());
    }

    /**
     * タクソノミーを WordPress に登録する(init 9)。
     *
     * @throws DiscoveryException コンポーネントが Taxonomy を継承しない場合
     */
    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        self::taxonomyClass($component)::register($kernel);
    }

    /**
     * @throws DiscoveryException
     *
     * @return class-string<Taxonomy>
     */
    private static function taxonomyClass(DiscoveredComponent $component): string
    {
        if (!is_subclass_of($component->class, Taxonomy::class)) {
            throw new DiscoveryException("taxonomy component must extend Taxonomy: {$component->class}");
        }

        return $component->class;
    }
}
