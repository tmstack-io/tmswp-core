<?php

declare(strict_types=1);

namespace TMSWP\Core\Query;

enum CastType: string
{
    case Numeric = 'NUMERIC';
    case Binary = 'BINARY';
    case Char = 'CHAR';
    case Date = 'DATE';
    case Datetime = 'DATETIME';
    case Decimal = 'DECIMAL';
    case Signed = 'SIGNED';
    case Time = 'TIME';
    case Unsigned = 'UNSIGNED';
}
