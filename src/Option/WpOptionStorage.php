<?php

declare(strict_types=1);

namespace TMSWP\Core\Option;

use stdClass;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Meta\WpDatabaseGuard;

/**
 * OptionStorageInterface の WP 標準の実装。
 *
 * 公開の読み取り(has・get)を単独で呼んだときのデータベースのエラーは、エラー方針に従い、$kernel の debug では
 * 例外、本番では $kernel のロガーに記録して「オプションが無い」と同じ結果を返す(WpDatabaseGuard)。
 * Container から解決したインスタンスは、その Container の Kernel を受け取る。
 */
final class WpOptionStorage implements OptionStorageInterface
{
    public function __construct(
        private readonly Kernel $kernel,
    ) {}

    public function has(string $name): bool
    {
        return WpDatabaseGuard::readOrDefault(
            $this->kernel,
            static fn(): bool => self::exists($name),
            false,
            "failed to read option '{$name}'",
            static fn() => self::forgetFailedRead($name),
        );
    }

    public function get(string $name): mixed
    {
        return WpDatabaseGuard::readOrDefault(
            $this->kernel,
            static fn(): mixed => self::value($name),
            null,
            "failed to read option '{$name}'",
            static fn() => self::forgetFailedRead($name),
        );
    }

    public function set(string $name, mixed $value, ?bool $autoload = null): bool
    {
        if ($value === null) {
            throw new InvalidArgumentException("option '{$name}' cannot store null; use delete() to remove it");
        }

        // false(同じ値・フィルタによる中止)とデータベースのエラーの区別は WpDatabaseGuard に従う
        $exists = WpDatabaseGuard::read(
            static fn(): bool => self::exists($name),
            "failed to read option '{$name}' before writing it",
            static fn() => self::forgetFailedRead($name),
        );

        if (!$exists) {
            return WpDatabaseGuard::write(
                static fn(): mixed => add_option($name, $value, '', $autoload),
                "failed to add option '{$name}'",
            );
        }

        return WpDatabaseGuard::write(
            static fn(): mixed => update_option($name, $value, $autoload),
            "failed to update option '{$name}'",
        );
    }

    public function delete(string $name): bool
    {
        $exists = WpDatabaseGuard::read(
            static fn(): bool => self::exists($name),
            "failed to read option '{$name}' before deleting it",
            static fn() => self::forgetFailedRead($name),
        );

        if (!$exists) {
            return false;
        }

        return WpDatabaseGuard::write(
            static fn(): mixed => delete_option($name),
            "failed to delete option '{$name}'",
        );
    }

    /** get_option() に毎回作るセンチネルを既定値として渡し、オプションの有無を判定する。 */
    private static function exists(string $name): bool
    {
        $sentinel = new stdClass();

        return get_option($name, $sentinel) !== $sentinel;
    }

    /**
     * 失敗した読み込みで WP が書いたオプションのキャッシュを消す。WP 7.0 の get_option() は(wp-includes/option.php):
     * - 名前を trim() してから読む。後始末も同じく trim() した名前を使う。
     * - 1件の SELECT が失敗すると、そのオプションを無いものとして notoptions(グループ options)の配列に
     *   trim() した名前 => true で書く。その名前の要素だけを取り除く(notoptions のほかの名前は消さない)。
     * - その前の wp_load_alloptions() は、autoload の SELECT と、その結果が空のときの全オプションの SELECT の両方が
     *   失敗すると、空の配列を alloptions(グループ options)に wp_cache_add() で書く。キャッシュの alloptions が
     *   空の配列のときだけ消す(WP は空の alloptions を毎回読み直すが、wp_cache_add() は既存のキーを上書きしないため、
     *   消さないと空の配列が残り続ける)。
     * その名前のキャッシュ(グループ options、キー $name)は、SELECT が成功したときだけ書かれるため消さない。
     *
     * @internal このクラスと Migrator(保存済みのハッシュの読み取り)だけが使う。
     */
    public static function forgetFailedRead(string $name): void
    {
        $name = trim($name);
        $notoptions = wp_cache_get('notoptions', 'options');

        if (\is_array($notoptions) && \array_key_exists($name, $notoptions)) {
            unset($notoptions[$name]);
            wp_cache_set('notoptions', $notoptions, 'options');
        }

        if (wp_cache_get('alloptions', 'options') === []) {
            wp_cache_delete('alloptions', 'options');
        }
    }

    /** オプションの値。無ければ null。 */
    private static function value(string $name): mixed
    {
        $sentinel = new stdClass();
        $value = get_option($name, $sentinel);

        return $value === $sentinel ? null : $value;
    }
}
