<?php

declare(strict_types=1);

namespace TMSWP\Core\Meta;

use Closure;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Core\Kernel\Kernel;
use wpdb;

/**
 * WP のメタ・オプションの関数(書き込み関数と読み取り関数)の呼び出しの間に起きたデータベースのエラーを検出する。
 *
 * WP の書き込み関数は「同じ値」「ほかのプラグインのフィルタによる中止」「データベースのエラー」のどれでも
 * false を返し、読み取り関数はクエリが失敗すると「行が無い」と同じ結果を返す。値を比べて区別するのではなく、
 * 呼び出しの間に発行したクエリのエラーで判定する。
 *
 * - wpdb::query() は実行の前に flush() で last_error を空にし、実行後に mysqli_error() を last_error に入れる。
 *   クエリを実際に発行したときは wpdb::_do_query() が num_queries を1つ増やす(WP 7.0 の wp-includes/class-wpdb.php)。
 * - WP 7.0 の update_option()・add_option()・update_metadata()・add_metadata()・delete_metadata() は、書き込みの
 *   クエリが失敗すると、ほかのクエリを発行せずにすぐ false を返す(wp-includes/option.php・meta.php)。
 *   そのため、書き込みの失敗のときは、呼び出しの間の最後のクエリが書き込みのクエリであり、その last_error が残る。
 * - 呼び出しの前の無関係なクエリのエラーを拾わないために、呼び出しの間に num_queries が増えた場合だけ last_error を見る。
 *   キャッシュから読んでクエリを発行しなかった読み取りは、エラーにならない。
 * - WP の読み取り関数は、読み込みのクエリが失敗しても、結果を「無い」としてオブジェクトキャッシュに書く(メタは
 *   update_meta_cache() が空の配列を、オプションは get_option() が notoptions を書く)。そのままでは、後の読み取りが
 *   クエリを発行せずに黙って「無い」を返すため、読み取りでデータベースのエラーを検出したら、例外を投げる前・ログに
 *   記録する前に、呼び出し側が渡した $forgetCache でそのキャッシュを消す。
 *
 * 検出したエラーの扱いは呼び出しの種類で分ける。
 * - 書き込み(write())と、書き込み操作の中の読み取り(read()): debug に関係なく例外にする。読み取りの失敗を
 *   「行が無い」として扱うと、置き換えや削除を誤るため。
 * - 公開の読み取りを単独で呼んだとき(readOrDefault()): エラー方針(00-overview.md §5.6)に従い、debug では例外、
 *   本番では Kernel のロガーに error で記録して既定値を返す。
 *
 * 受け入れる限界(2 は書き込みだけ、ほかは読み取りにも当てはまる):
 * 1. 呼び出しの間にほかのフックが発行したクエリが失敗して最後のクエリとして残った場合は、書き込み関数・読み取り
 *    関数そのものが成功していてもエラーとして扱う(fail-fast)。
 * 2. delete_option() は削除のクエリが失敗した後もキャッシュを更新し、autoload のオプションで alloptions の
 *    キャッシュが無いときは読み直しのクエリを発行する。その場合は削除のエラーが last_error から消え、false になる。
 * 3. wpdb::query() が num_queries を増やさずに last_error だけを入れる経路(不正なデータを含むクエリの拒否)では、
 *    num_queries が増えないため、書き込みは false(書き込まなかった)、読み取りは成功として扱う。
 * 4. 接続が切れて再接続にも失敗した場合、wpdb::query() は last_error を入れる前に false を返すため、書き込みは
 *    false(書き込まなかった)、読み取りは成功として扱う。データベースのサーバーの障害の経路であり、wpdb の内部
 *    (接続ハンドル等)に依存して検出すると WP の版の変更で壊れやすいため、検出しない。
 *
 * @internal WpMetaStorage・WpOptionStorageと、Migrator(保存済みのハッシュの読み書き)と、拡張のパッケージのメタの
 *           ストレージ(外部のフィールドの書き込みの中で、参照のメタを読む)だけが使う。
 */
final class WpDatabaseGuard
{
    private function __construct() {}

    /**
     * @param callable(): mixed $write WP の書き込み関数の呼び出し
     * @param string $failure 例外のメッセージの先頭(データベースのエラーの内容を続ける)
     *
     * @throws RuntimeException 書き込まなかったと返し、呼び出しの間のクエリでデータベースのエラーが起きた
     *
     * @return bool WP が書き込んだと返したら true、書き込まなかったと返し、データベースのエラーが無ければ false
     */
    public static function write(callable $write, string $failure): bool
    {
        $wpdb = self::wpdb();
        $queries = $wpdb->num_queries;

        if ($write() !== false) {
            return true;
        }

        self::assertNoDatabaseError($wpdb, $queries, $failure);

        return false;
    }

    /**
     * 書き込み操作の中で行う読み取り(存在の確認、置き換えの前後の行の取得)を呼ぶ。
     *
     * @template T
     *
     * @param callable(): T $read 読み取りの呼び出し
     * @param string $failure 例外のメッセージの先頭(データベースのエラーの内容を続ける)
     * @param callable(): void $forgetCache 失敗した読み込みで WP が書いたキャッシュを消す
     *
     * @throws RuntimeException 呼び出しの間のクエリでデータベースのエラーが起きた
     *
     * @return T 読み取りの結果
     */
    public static function read(callable $read, string $failure, callable $forgetCache): mixed
    {
        $wpdb = self::wpdb();
        $queries = $wpdb->num_queries;
        $result = $read();
        $error = self::databaseError($wpdb, $queries);

        if ($error !== null) {
            $forgetCache();

            throw new RuntimeException("{$failure}: {$error}");
        }

        return $result;
    }

    /**
     * 公開の読み取り(has・get・getAll・getMultiple)を単独で呼ぶ。呼び出しの間のクエリでデータベースのエラーが
     * 起きたら、$kernel の debug では例外にし、本番では $kernel のロガーに error で記録して $default を返す。
     *
     * @template T
     *
     * @param Kernel $kernel debug とロガーを決める Kernel(ストレージを解決した Container の Kernel)
     * @param callable(): T $read 読み取りの呼び出し
     * @param T $default 本番でデータベースのエラーが起きたときに返す値(行が無いときと同じ結果)
     * @param string $failure 例外とログのメッセージの先頭(データベースのエラーの内容を続ける)
     * @param callable(): void $forgetCache 失敗した読み込みで WP が書いたキャッシュを消す
     *
     * @throws RuntimeException debug で、呼び出しの間のクエリでデータベースのエラーが起きた
     *
     * @return T 読み取りの結果。本番でデータベースのエラーが起きたときは $default
     */
    public static function readOrDefault(Kernel $kernel, callable $read, mixed $default, string $failure, callable $forgetCache): mixed
    {
        $wpdb = self::wpdb();
        $queries = $wpdb->num_queries;
        $result = $read();
        $error = self::databaseError($wpdb, $queries);

        if ($error === null) {
            return $result;
        }

        $forgetCache();

        if ($kernel->config()->debug) {
            throw new RuntimeException("{$failure}: {$error}");
        }

        $kernel->logger()->error('tmswp.storage: {failure}: {error}', ['failure' => $failure, 'error' => $error]);

        return $default;
    }

    /**
     * 読み取りの間に WP がメタのキャッシュへ読み込んだ対象を記録し、失敗した読み込みのキャッシュを消す後始末を
     * $guarded に渡して呼ぶ。
     *
     * WP 7.0 の update_meta_cache() は(wp-includes/meta.php)、update_{$meta_type}_metadata_cache フィルタが null を
     * 返したら、渡された ID のうち wp_cache_get_multiple() がキャッシュに無い(false)とした ID を1回の SELECT で読み、
     * SELECT が失敗しても、それらの ID を空の配列としてグループ {$meta_type}_meta に wp_cache_add_multiple() で書く。
     * 読み込む ID は、読み取りの対象だけとは限らない(WP_Metadata_Lazyloader は、キューにあるほかの対象もまとめて
     * 読む)。そのため、読み取りの間だけそのフィルタを最後の priority で付け、キャッシュに無かった ID を記録し、
     * 後始末はその ID だけを消す。フィルタは $check を変えずに返し、ほかのフィルタが短絡した($check が null でない)
     * 呼び出しは SELECT もキャッシュの書き込みもしないため記録しない。
     *
     * @template T
     *
     * @param Closure(callable(): void): T $guarded 受け取った後始末を WpDatabaseGuard に渡して読み取りを呼ぶ
     *
     * @return T
     */
    public static function forgettingFailedMetaLoads(ObjectType $type, Closure $guarded): mixed
    {
        $group = $type->metaType() . '_meta';
        $hook = "update_{$type->metaType()}_metadata_cache";
        // 読み取りの間に WP が読み込んだ(キャッシュに無かった)ID => true
        $loaded = [];
        $record = static function (mixed $check, mixed $objectIds) use ($group, &$loaded): mixed {
            if ($check === null && \is_array($objectIds)) {
                foreach (wp_cache_get_multiple($objectIds, $group) as $id => $cached) {
                    if ($cached === false) {
                        $loaded[(int) $id] = true;
                    }
                }
            }

            return $check;
        };
        $forgetCache = static function () use ($group, &$loaded): void {
            foreach (array_keys($loaded) as $id) {
                wp_cache_delete($id, $group);
            }
        };
        add_filter($hook, $record, PHP_INT_MAX, 2);

        try {
            return $guarded($forgetCache);
        } finally {
            remove_filter($hook, $record, PHP_INT_MAX);
        }
    }

    private static function assertNoDatabaseError(wpdb $wpdb, int $queriesBefore, string $failure): void
    {
        $error = self::databaseError($wpdb, $queriesBefore);

        if ($error !== null) {
            throw new RuntimeException("{$failure}: {$error}");
        }
    }

    /** 呼び出しの間にクエリを発行し、最後のクエリがデータベースのエラーになっていれば、その内容を返す。 */
    private static function databaseError(wpdb $wpdb, int $queriesBefore): ?string
    {
        return $wpdb->num_queries > $queriesBefore && $wpdb->last_error !== '' ? $wpdb->last_error : null;
    }

    private static function wpdb(): wpdb
    {
        global $wpdb;

        if (!$wpdb instanceof wpdb) {
            throw new RuntimeException('wpdb is not available');
        }

        return $wpdb;
    }
}
