<?php

declare(strict_types=1);

namespace TMSWP\Core\Log;

use TMSWP\Core\Exception\KernelException;

/**
 * debug_backtrace() のフレームから、Log と tms_log() を呼び出したファイルを決める。
 *
 * パスの文字列ではなく、フレームが表す関数の種類で判定する(区切り文字やパスの表記に左右されない)。
 *
 * @internal
 */
final class CallerFile
{
    /** eval したコードのファイル名に PHP が付ける接尾辞(`/path/x.php(12) : eval()'d code`。入れ子なら繰り返す) */
    private const string EVAL_SUFFIX = '/(?:\(\d+\) : eval\(\)\'d code)+\z/';

    private function __construct() {}

    /**
     * スタックの先頭から、Log のメソッドか tms_log() の呼び出しを表すフレームが続く間たどり、その最後のフレームの
     * file(その関数を呼んだファイル)を返す。eval したコードからの呼び出しは、eval を書いたファイルにする。
     *
     * @param array<int, array<string, mixed>> $frames debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) の戻り値
     *
     * @throws KernelException 続くフレームが無い、または最後のフレームに file が無い場合
     */
    public static function fromBacktrace(array $frames): string
    {
        $last = null;

        foreach ($frames as $frame) {
            if (!self::isLogCall($frame)) {
                break;
            }

            $last = $frame;
        }

        if ($last === null) {
            throw new KernelException('cannot determine the file that called ' . Log::class . ': the backtrace does not start with a call of Log or tms_log()');
        }

        $file = $last['file'] ?? null;

        if (!\is_string($file) || $file === '') {
            $function = (\is_string($last['class'] ?? null) ? $last['class'] . '::' : '') . (\is_string($last['function'] ?? null) ? $last['function'] : '');

            throw new KernelException("cannot determine the file that called {$function}(): the call has no file (e.g. it was called from an internal function such as array_map())");
        }

        return preg_replace(self::EVAL_SUFFIX, '', $file) ?? $file;
    }

    /** @param array<string, mixed> $frame */
    private static function isLogCall(array $frame): bool
    {
        $class = $frame['class'] ?? null;

        if ($class !== null) {
            return $class === Log::class;
        }

        return ($frame['function'] ?? null) === 'tms_log';
    }
}
