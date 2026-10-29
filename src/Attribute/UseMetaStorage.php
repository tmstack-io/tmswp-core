<?php

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

use Attribute;
use TMSWP\Core\Meta\MetaStorageInterface;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class UseMetaStorage
{
    /** @param class-string<MetaStorageInterface> $storageClass */
    public function __construct(
        public string $storageClass,
    ) {}
}
