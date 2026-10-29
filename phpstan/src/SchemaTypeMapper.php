<?php

declare(strict_types=1);

namespace TMSWP\Core\PHPStan;

use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;

final class SchemaTypeMapper
{
    private function __construct() {}

    public static function typeFor(string $type): Type
    {
        return match ($type) {
            'string' => new StringType(),
            'integer' => new IntegerType(),
            'number' => new FloatType(),
            'boolean' => new BooleanType(),
            'array' => new ArrayType(new UnionType([new IntegerType(), new StringType()]), new MixedType()),
            'object' => new ArrayType(new StringType(), new MixedType()),
            default => new MixedType(),
        };
    }
}
