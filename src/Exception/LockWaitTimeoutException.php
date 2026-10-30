<?php

declare(strict_types=1);

namespace TMSWP\Core\Exception;

/**
 * 実行時のDBエラーのうち、ロック待ちの時間切れ(エラー番号1205)と、MySQLの`NOWAIT`でロックを得られないもの(3572)。
 * innodb_rollback_on_timeoutが既定のOFFのとき、DBはその文だけを取り消し、トランザクションは続いている。
 */
final class LockWaitTimeoutException extends QueryException
{
}
