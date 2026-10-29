<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Fixtures\Meta;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\UseMetaStorage;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

#[AsPostType]
#[UseMetaStorage(InvalidStorage::class)]
final class InvalidStoragePost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_invalid');
    }
}

final class InvalidStorage {}
