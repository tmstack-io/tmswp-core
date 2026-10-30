<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Support;

use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;

/** 要望の定義のアカウントのテーブル(AccountsTable::requested())。 */
final class AccountsModel extends Model
{
    public static function table(): TableDefinition
    {
        return AccountsTable::requested();
    }
}
