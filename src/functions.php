<?php

declare(strict_types=1);

use TMSWP\Core\Log\Log;
use TMSWP\Core\Log\LoggerRegistry;

/**
 * 呼び出したファイルの Kernel のログのレジストリを取得する。Log::registry() への委譲。
 *
 *   tms_log()->info('imported', ['count' => $n]);
 *   tms_log()->scope('mail')->error('send failed', ['exception' => $e]);
 *   tms_log()->channel('audit')->info('user updated');
 *
 *
 * @throws TMSWP\Core\Exception\KernelException 呼び出したファイルがどの Kernel のルートにも含まれない場合
 *
 * @return LoggerRegistry 呼び出したファイルを含むルートディレクトリを持つ Kernel のレジストリ(PSR-3 のロガー)
 */
function tms_log(): LoggerRegistry
{
    return Log::registry();
}
