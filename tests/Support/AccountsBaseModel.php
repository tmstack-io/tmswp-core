<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Support;

use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;

/** 生成カラムを足す前のアカウントのテーブル(AccountsTable::regular())。 */
final class AccountsBaseModel extends Model
{
    public static function table(): TableDefinition
    {
        return AccountsTable::regular();
    }
}
