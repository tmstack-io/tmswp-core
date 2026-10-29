<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Meta\WpMetaStorage;
use TMSWP\Testing\MetaStorageContractTestCase;

/** WpMetaStorageがMetaStorageInterfaceの契約を満たすことを、投稿のメタで確かめる。 */
final class WpMetaStorageContractTest extends MetaStorageContractTestCase
{
    protected function storage(): MetaStorageInterface
    {
        return new WpMetaStorage(self::themeKernel());
    }

    protected function objectType(): ObjectType
    {
        return ObjectType::Post;
    }

    protected function createObject(): int
    {
        return self::createPost('tmswp_meta_post');
    }
}
