<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

enum MetaValueType: string
{
    case String = 'string';
    case Boolean = 'boolean';
    case Integer = 'integer';
    case Number = 'number';
    case Array = 'array';
    case Object = 'object';

    public function zeroValue(): mixed
    {
        return match ($this) {
            self::String => '',
            self::Boolean => false,
            self::Integer => 0,
            self::Number => 0.0,
            self::Array, self::Object => [],
        };
    }
}
