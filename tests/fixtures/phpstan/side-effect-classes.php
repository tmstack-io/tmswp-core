<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Option\OptionAccess;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

// PHPStan 拡張が解析中に利用者のコードを実行しないことの検証用。このファイルを読み込むと例外になる。
throw new \LogicException('side-effect-classes.php must not be loaded during static analysis');

#[AsPostType]
final class PHPStanSideEffectPost extends PostType
{
    #[MetaSchema(type: MetaValueType::Integer, default: 0)]
    public const META_COUNT = 'count';

    #[MetaSchema(type: MetaValueType::String, single: false)]
    public const META_TAGS = 'tags';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('phpstan_side_effect')->label('Side Effect', 'Side Effects');
    }
}

#[AsOptionGroup(prefix: 'phpstan_side_effect_')]
enum PHPStanSideEffectOption: string
{
    use OptionAccess;

    #[OptionSchema(type: MetaValueType::Integer)]
    case Counter = 'counter';
}
