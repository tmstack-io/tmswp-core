<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Fixtures\Model;

/*
 * QueryBuilderAcceptanceTestで、LateEnumRowを変換した後に読み込むenum(late-enum-row.phpを参照)。
 */
enum LateEnum: string
{
    case Open = 'open';
}
