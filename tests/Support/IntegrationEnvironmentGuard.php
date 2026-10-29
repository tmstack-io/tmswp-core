<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Support;

use RuntimeException;

/**
 * 統合テストを始めてよい状態かを、WordPress とテストライブラリを読み込む前に検査する。
 *
 * テストライブラリは起動時にテーブルを作り直し、投稿・ユーザー等の行を削除する。統合テストは開発用サイトと
 * 同じデータベースを、テーブル接頭辞 wptests_(tests/wp-tests-config.php)で分けて使う。接頭辞や
 * テーブル名が開発用サイト(wp_)に向いたまま起動すると、開発用サイトのデータを消す。そうなりうる状態を
 * 見つけたら例外で止める。入力はすべて引数で受け取り、データベースにも WordPress にも触れない。
 */
final class IntegrationEnvironmentGuard
{
    /**
     * tests/wp-tests-config.php が定義する定数。先に定義されていると、設定ファイルの define() が効かず
     * 先の値(別のデータベース・別の WordPress)が使われる。
     */
    private const CONFIG_CONSTANTS = [
        'ABSPATH',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'DB_HOST',
        'DB_CHARSET',
        'DB_COLLATE',
        'WP_TESTS_DOMAIN',
        'WP_TESTS_EMAIL',
        'WP_TESTS_TITLE',
        'WP_PHP_BINARY',
        'WP_DEBUG',
    ];

    /**
     * 使う設定ファイルやテーブル名を変える定数。
     * - WP_TESTS_CONFIG_FILE_PATH: 別の設定ファイル(wp-env が WP_TESTS_DIR に置く wp_ 接頭辞のもの等)を使わせる
     * - WP_TESTS_TABLE_PREFIX: テストライブラリが設定ファイルの接頭辞から定義する
     * - CUSTOM_USER_TABLE / CUSTOM_USER_META_TABLE: 接頭辞に関係なくユーザー表を差し替える(wpdb::tables())
     */
    private const TABLE_CONSTANTS = [
        'WP_TESTS_CONFIG_FILE_PATH',
        'WP_TESTS_TABLE_PREFIX',
        'CUSTOM_USER_TABLE',
        'CUSTOM_USER_META_TABLE',
    ];

    /**
     * WordPress が既存の値をそのまま使い続けるグローバル変数。
     * - table_prefix: PHPUnit は bootstrap をメソッド内で読み込むため、設定ファイルの $table_prefix は
     *   ローカル変数になる。wp-settings.php はグローバルが既にあればそれを使い、ローカルの値を捨てる
     * - wpdb: require_wp_db() は既存の $wpdb を使い続ける
     */
    private const WORDPRESS_GLOBALS = ['table_prefix', 'wpdb'];

    /**
     * @param string|false $wpTestsDir getenv('WP_TESTS_DIR') の値
     * @param array<string, mixed> $constants 定義済みのユーザー定数(get_defined_constants(true)['user'])
     * @param array<mixed> $globals $GLOBALS
     *
     * @throws RuntimeException 統合テストを始めてはいけない状態のとき
     *
     * @return string 検査済みの WP_TESTS_DIR
     */
    public static function assertSafe(string|false $wpTestsDir, array $constants, array $globals): string
    {
        if ($wpTestsDir === false || $wpTestsDir === '' || !is_file($wpTestsDir . '/includes/bootstrap.php')) {
            throw new RuntimeException('WP_TESTS_DIR must point to the WordPress test library provided by wp-env; run the integration tests with "composer test:integration"');
        }

        $problems = [];

        foreach ([...self::CONFIG_CONSTANTS, ...self::TABLE_CONSTANTS] as $name) {
            if (\array_key_exists($name, $constants)) {
                $problems[] = "constant {$name} is already defined";
            }
        }

        foreach (self::WORDPRESS_GLOBALS as $name) {
            if (\array_key_exists($name, $globals)) {
                $problems[] = "global \${$name} is already set";
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                'refusing to start the integration tests, because the test tables might not be the wptests_ tables of tests/wp-tests-config.php: '
                . implode('; ', $problems),
            );
        }

        return $wpTestsDir;
    }
}
