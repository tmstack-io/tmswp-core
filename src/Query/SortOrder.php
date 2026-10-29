<?php

declare(strict_types=1);

namespace TMSWP\Core\Query;

enum SortOrder: string
{
    case Asc = 'ASC';
    case Desc = 'DESC';
}
