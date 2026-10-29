<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Exception\RuntimeException;
use wpdb;

/**
 * jti(使い捨ての送信 ID)のアトミックな獲得。
 *
 * wp_options の option_name の UNIQUE 制約に対する INSERT IGNORE で「既にあれば獲得できない」をデータベースで保証する。
 * add_option() は内部が読み取り→挿入の非アトミックな実装のため使わない。生の SQL はこのクラスの外に出さない。
 */
final class JtiStore
{
    private const OPTION_PREFIX = 'tmswp_form_jti_';

    /**
     * jti をアトミックに獲得する。並行した2つのリクエストのうち Claimed になるのは1つだけ。
     *
     * 確率的な掃除(gc())は挿入の前に行う。掃除が失敗して例外になったとき、jti を獲得したまま終わらないため。
     *
     * @param int $ttl 獲得を保持する秒数(トークンの有効期限より長くする)
     *
     * @throws RuntimeException データベースのエラー
     */
    public function claim(string $jti, int $ttl): JtiClaim
    {
        // INSERT IGNORE は列長の超過等のエラーも0行(Duplicate と区別できない)にするため、形式を先に確かめる
        if (preg_match('/\A[a-f0-9]{32}\z/', $jti) !== 1) {
            throw new FormConfigException("invalid form submission ID: {$jti}");
        }

        if (mt_rand(1, 100) === 1) {
            $this->gc();
        }

        $wpdb = $this->wpdb();
        $result = $wpdb->query($this->prepare(
            $wpdb,
            "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
            $wpdb->options,
            self::OPTION_PREFIX . $jti,
            (string) (time() + $ttl),
        ));

        if ($result === false) {
            throw new RuntimeException("failed to claim the form submission ID: {$wpdb->last_error}");
        }

        return match ($result) {
            1 => JtiClaim::Claimed,
            0 => JtiClaim::Duplicate,
            default => throw new RuntimeException('claiming the form submission ID returned an unexpected result: ' . var_export($result, true)),
        };
    }

    /**
     * 獲得した jti を手放す。onComplete が例外で終わったとき、同じトークンで再送できるようにするために使う。
     *
     * @throws RuntimeException データベースのエラー
     */
    public function release(string $jti): void
    {
        $wpdb = $this->wpdb();
        $result = $wpdb->query($this->prepare(
            $wpdb,
            'DELETE FROM %i WHERE option_name = %s',
            $wpdb->options,
            self::OPTION_PREFIX . $jti,
        ));

        if ($result === false) {
            throw new RuntimeException("failed to release the form submission ID: {$wpdb->last_error}");
        }
    }

    /**
     * jti が claim 済みか。確認画面からの送信で一時ファイルが消えていたとき、完了の後の再送かを見分けるために使う。
     *
     * @throws RuntimeException データベースのエラー
     */
    public function isClaimed(string $jti): bool
    {
        $wpdb = $this->wpdb();
        $value = $wpdb->get_var($this->prepare(
            $wpdb,
            'SELECT option_value FROM %i WHERE option_name = %s',
            $wpdb->options,
            self::OPTION_PREFIX . $jti,
        ));

        // wpdb::query() はクエリの前に last_error を空にするため、残っていればこのクエリのエラー
        if ($wpdb->last_error !== '') {
            throw new RuntimeException("failed to look up the form submission ID: {$wpdb->last_error}");
        }

        return $value !== null;
    }

    /**
     * 期限切れの jti を消す。
     *
     * @throws RuntimeException データベースのエラー
     *
     * @return int 消した行数
     */
    public function gc(): int
    {
        $wpdb = $this->wpdb();
        $result = $wpdb->query($this->prepare(
            $wpdb,
            'DELETE FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d',
            $wpdb->options,
            $wpdb->esc_like(self::OPTION_PREFIX) . '%',
            time(),
        ));

        if (!\is_int($result)) {
            throw new RuntimeException("failed to delete expired form submission IDs: {$wpdb->last_error}");
        }

        return $result;
    }

    /** @param literal-string $query */
    private function prepare(wpdb $wpdb, string $query, string|int ...$args): string
    {
        $sql = $wpdb->prepare($query, ...$args);

        if (!\is_string($sql)) {
            throw new FormConfigException("failed to prepare a query of the form submission IDs: {$query}");
        }

        return $sql;
    }

    private function wpdb(): wpdb
    {
        global $wpdb;

        if (!class_exists(wpdb::class) || !$wpdb instanceof wpdb) {
            throw new FormConfigException('wpdb is not available');
        }

        return $wpdb;
    }
}
