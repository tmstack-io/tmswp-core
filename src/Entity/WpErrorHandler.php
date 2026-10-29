<?php

declare(strict_types=1);

namespace TMSWP\Core\Entity;

use TMSWP\Core\Exception\QueryException;
use TMSWP\Core\Kernel\Kernel;
use WP_Error;

/**
 * エンティティの層で WordPress の関数が返した WP_Error を、エラー方針(00-overview.md §5.6)の実行時のデータ取得の
 * 失敗として扱う。WP_Error の扱いはここだけに置く。
 *
 * 「該当なし」(WordPress の関数が返す false・空配列等)はここに渡さず、呼び出し側が正常な結果として返す。
 *
 * @internal
 */
final class WpErrorHandler
{
    private function __construct() {}

    /**
     * debug では QueryException を投げる。本番ではエンティティクラスの Kernel のロガーに error で記録して既定値を返す。
     *
     * @template T
     *
     * @param class-string $entityClass WP の関数を呼んだエンティティクラス(Kernel の解決とメッセージに使う)
     * @param string $function WP_Error を返した WP の関数名
     * @param T $default 本番で返す既定値
     *
     * @throws QueryException debug の場合
     *
     * @return T
     */
    public static function handle(WP_Error $error, string $entityClass, string $function, mixed $default): mixed
    {
        $kernel = Kernel::forClass($entityClass);
        $code = (string) $error->get_error_code();
        $message = $error->get_error_message();

        if ($kernel->config()->debug) {
            throw new QueryException("{$function}() returned WP_Error {$code}: {$message} ({$entityClass})");
        }

        $kernel->logger()->error('tmswp.entity: {function}() returned WP_Error {code}: {message} ({class})', [
            'function' => $function,
            'code' => $code,
            'message' => $message,
            'class' => $entityClass,
        ]);

        return $default;
    }
}
