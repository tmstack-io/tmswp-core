<?php

declare(strict_types=1);

namespace TMSWP\Core\Exception;

/** 実行時のDBエラーのうち、デッドロック(エラー番号1213)。DBはトランザクションの全体を取り消している。 */
final class DeadlockException extends QueryException
{
}
