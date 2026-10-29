<?php

declare(strict_types=1);

namespace TMSWP\Core\Attribute;

use Attribute;
use TMSWP\Core\Meta\MetaValueType;

/**
 * オプショングループ enum のケースに付ける。PHP には enum ケース専用の属性ターゲットが無く、enum ケースは
 * クラス定数として扱われるため、ターゲットはクラス定数とする。ケースでない定数への付与は
 * OptionRepositoryFactory が起動時に拒否する。
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class OptionSchema
{
    public function __construct(
        public MetaValueType $type = MetaValueType::String,
        public mixed $default = null,
        public bool $autoload = true,
    ) {}
}
