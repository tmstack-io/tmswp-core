<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Support;

use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;

/** 要望の定義の生成カラムの式を変えたもの(アクティブでない状態に suspended を足す)。 */
final class AccountsChangedExpressionModel extends Model
{
    public static function table(): TableDefinition
    {
        return AccountsTable::requested(inactiveStatuses: "'expired','deleted','suspended'");
    }
}
