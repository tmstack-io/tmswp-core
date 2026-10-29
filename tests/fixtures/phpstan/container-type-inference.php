<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Fixtures\PHPStan;

use Psr\Container\ContainerInterface;
use TMSWP\Core\Container\Container;
use TMSWP\Core\Meta\MetaSchemaRegistry;

use function PHPStan\Testing\assertType;

function container_get(Container $container, string $id): void
{
    // クラス名・interface 名の id は、その型として推論される
    assertType(MetaSchemaRegistry::class, $container->get(MetaSchemaRegistry::class));
    assertType(ContainerInterface::class, $container->get(ContainerInterface::class));

    // クラス名でない id は mixed のまま
    assertType('mixed', $container->get('service'));
    assertType('mixed', $container->get($id));
}

/** @param class-string<MetaSchemaRegistry> $class */
function container_get_class_string(Container $container, string $class): void
{
    assertType(MetaSchemaRegistry::class, $container->get($class));
}
