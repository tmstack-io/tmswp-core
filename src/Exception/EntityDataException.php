<?php

declare(strict_types=1);

namespace TMSWP\Core\Exception;

/**
 * エンティティの実行時のデータの不整合(親の深さの超過等)。実行時のデータ取得の失敗に当たり、
 * debug のときだけ投げる(本番は Kernel のロガーに error で記録して既定値を返す)。
 */
final class EntityDataException extends RuntimeException
{
}
