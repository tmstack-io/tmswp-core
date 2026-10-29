<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Fixtures\Option;

use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Tests\Unit\Option\ArrayOptionStorage;

/*
 * enum ケース以外に付いた #[OptionSchema] の確認用。属性のターゲット(クラス定数)に反するため、
 * PHPStan の解析対象の外(tests/fixtures)に置く。
 */

#[AsOptionGroup(prefix: 'enum_level_', storage: ArrayOptionStorage::class)]
#[OptionSchema(type: MetaValueType::String)]
enum EnumLevelOptionSchema: string
{
    case ContactEmail = 'contact_email';
}

#[AsOptionGroup(prefix: 'method_', storage: ArrayOptionStorage::class)]
enum MethodOptionSchema: string
{
    case ContactEmail = 'contact_email';

    #[OptionSchema(type: MetaValueType::String)]
    public static function contactEmail(): string
    {
        return self::ContactEmail->value;
    }
}

#[AsOptionGroup(prefix: 'parameter_', storage: ArrayOptionStorage::class)]
enum ParameterOptionSchema: string
{
    case ContactEmail = 'contact_email';

    public static function withDefault(#[OptionSchema(type: MetaValueType::String)] string $value): string
    {
        return $value;
    }
}
