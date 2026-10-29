<?php

declare(strict_types=1);

namespace TMSWP\Core\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Kernel\Kernel;

/**
 * アプリのコード(テンプレートを含む)から使う静的ファサード。
 *
 * 記録先は、呼び出したファイルを含むルートディレクトリ(appDir の親)を持つ Kernel のレジストリ(`Kernel::logger()`)。
 * ルートが入れ子なら最も長く一致するものを採る(`Kernel::forFile()`)。どの Kernel のルートにも含まれないファイルから
 * 呼ぶと KernelException になる。
 *
 * TMSWP の内部(`TMSWP\` のクラス)はこのファサードを使わず、扱う Kernel の `logger()` に記録する。テーマとプラグインで
 * core の版が別でも、クラスは先に読み込まれた側の1組だけが使われ、ファイルの場所では Kernel を決められないためである。
 */
final class Log
{
    private function __construct() {}

    /**
     * 呼び出したファイルの Kernel のレジストリを返す。
     *
     * @throws KernelException 呼び出したファイルがどの Kernel のルートにも含まれない場合
     */
    public static function registry(): LoggerRegistry
    {
        return Kernel::forFile(self::callerFile())->logger();
    }

    public static function channel(string $channel): LoggerInterface
    {
        return self::registry()->channel($channel);
    }

    public static function scope(string $scope): LoggerInterface
    {
        return self::registry()->scope($scope);
    }

    /** @param array<mixed> $context */
    public static function debug(string|Stringable $message, array $context = []): void
    {
        self::registry()->log(LogLevel::DEBUG, $message, $context);
    }

    /** @param array<mixed> $context */
    public static function info(string|Stringable $message, array $context = []): void
    {
        self::registry()->log(LogLevel::INFO, $message, $context);
    }

    /** @param array<mixed> $context */
    public static function notice(string|Stringable $message, array $context = []): void
    {
        self::registry()->log(LogLevel::NOTICE, $message, $context);
    }

    /** @param array<mixed> $context */
    public static function warning(string|Stringable $message, array $context = []): void
    {
        self::registry()->log(LogLevel::WARNING, $message, $context);
    }

    /** @param array<mixed> $context */
    public static function error(string|Stringable $message, array $context = []): void
    {
        self::registry()->log(LogLevel::ERROR, $message, $context);
    }

    /** @param array<mixed> $context */
    public static function critical(string|Stringable $message, array $context = []): void
    {
        self::registry()->log(LogLevel::CRITICAL, $message, $context);
    }

    /** @param array<mixed> $context */
    public static function alert(string|Stringable $message, array $context = []): void
    {
        self::registry()->log(LogLevel::ALERT, $message, $context);
    }

    /** @param array<mixed> $context */
    public static function emergency(string|Stringable $message, array $context = []): void
    {
        self::registry()->log(LogLevel::EMERGENCY, $message, $context);
    }

    /** @param array<mixed> $context */
    public static function log(string $level, string|Stringable $message, array $context = []): void
    {
        self::registry()->log($level, $message, $context);
    }

    /**
     * Log・tms_log() を呼び出したファイル(CallerFile::fromBacktrace())。
     *
     * debug_backtrace() の深さは制限しない。Log の中の呼び出しの連なりは短いが、制限すると、連なりが上限で
     * 途切れたのか呼び出し元に達したのかをフレームから区別できなくなるためである。
     *
     * @throws KernelException 呼び出したファイルが分からない場合
     */
    private static function callerFile(): string
    {
        return CallerFile::fromBacktrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
    }
}
