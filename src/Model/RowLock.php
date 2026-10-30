<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

/**
 * 行ロックの句。backing valueがそのままSQLの句になる。
 *
 * 共有ロックは、MariaDBで構文の誤りになる`FOR SHARE`ではなく、MariaDB・MySQLの両方で使える`LOCK IN SHARE MODE`
 * にする。共有ロックに`NOWAIT`・`SKIP LOCKED`を付けた句は、MySQLで構文の誤りになるため持たない。
 */
enum RowLock: string
{
    case ForUpdate = 'FOR UPDATE';
    case ForUpdateNoWait = 'FOR UPDATE NOWAIT';
    case ForUpdateSkipLocked = 'FOR UPDATE SKIP LOCKED';
    case Shared = 'LOCK IN SHARE MODE';
}
