<?php

declare(strict_types=1);

namespace TMSWP\Core\Query;

/** tax_query の句の operator。EXISTS/NOT EXISTS は含まない */
enum TermOperator: string
{
    case In = 'IN';
    case NotIn = 'NOT IN';
    case And = 'AND';
}
