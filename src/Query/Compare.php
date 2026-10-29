<?php

declare(strict_types=1);

namespace TMSWP\Core\Query;

enum Compare: string
{
    case Eq = '=';
    case NotEq = '!=';
    case Gt = '>';
    case Gte = '>=';
    case Lt = '<';
    case Lte = '<=';
    case Like = 'LIKE';
    case NotLike = 'NOT LIKE';
    case In = 'IN';
    case NotIn = 'NOT IN';
    case Between = 'BETWEEN';
    case NotBetween = 'NOT BETWEEN';
    case Regexp = 'REGEXP';
    case NotRegexp = 'NOT REGEXP';
    case Rlike = 'RLIKE';
}
