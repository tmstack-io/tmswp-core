<?php

declare(strict_types=1);

namespace TMSWP\Core\Exception;

/**
 * エンティティの実行時のデータの不整合(親の深さの超過等)。エラー方針(00-overview.md §5.6)の実行時のデータ取得の
 * 失敗に当たり、debug のときだけ投げる(本番は Kernel のロガーに error で記録して既定値を返す)。
 */
final class EntityDataException extends RuntimeException
{
}
