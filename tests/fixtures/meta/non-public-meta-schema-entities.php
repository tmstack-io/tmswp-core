<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Fixtures\Meta;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

/*
 * 公開でない定数に付いた #[MetaSchema] の確認用。private 定数はリフレクションでしか読まれないため、
 * PHPStan の解析対象の外(tests/fixtures)に置く。
 */

#[AsPostType]
final class ProtectedMetaSchemaPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    protected const META_HIDDEN = 'hidden_key';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_hidden');
    }
}

#[AsPostType]
final class PrivateMetaSchemaPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    private const META_HIDDEN = 'hidden_key';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('registry_hidden');
    }
}
