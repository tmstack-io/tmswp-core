<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use Psr\Log\LogLevel;
use TMSWP\Core\Exception\Form\TempFileException;
use TMSWP\Core\Kernel\Kernel;

/**
 * 既定の一時保管(TempFileStore::default())の gc() を WP-Cron で毎時実行する。
 *
 * - Kernel の boot が register() を呼び、フック HOOK に gc() を登録する。登録はプロセスで1回だけで、最初に boot した
 *   Kernel が gc() の失敗の扱い(debug の判定とログの記録先)を受け持つ。
 * - イベントは TempFileStore::store() が schedule() でスケジュールする(まだ無ければ)。ファイルを保管しないサイトには
 *   イベントを作らない。
 * - TMSWP の最後の Kernel の無効化(`tmswp/deactivated`)でイベントを解除する。無効化のリクエストで、無効になる Kernel の
 *   ほかに Kernel のレジストリ(Kernel::all())に登録済みの Kernel があれば(テーマとプラグインの両方が TMSWP を使う等)、
 *   その Kernel のフォームの一時ファイルを掃除し続けるため解除しない。
 *
 * 既定以外の保管場所(FormDefinition::tempFileStore())を使う場合は、その gc() を同じフック HOOK に登録する。
 */
final class TempFileGc
{
    /** gc() を実行する WP-Cron のフック */
    public const string HOOK = 'tmswp_form_temp_file_gc';

    /** 実行の間隔(wp_get_schedules() の名前) */
    public const string RECURRENCE = 'hourly';

    private static bool $registered = false;

    private function __construct() {}

    /**
     * @internal Kernel の boot だけが呼ぶ。
     */
    public static function register(Kernel $kernel): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;
        add_action(self::HOOK, static function () use ($kernel): void {
            self::run($kernel);
        }, 10, 0);
        add_action('tmswp/deactivated', static function (Kernel $deactivated): void {
            self::unschedule($deactivated);
        }, 10, 1);
    }

    /**
     * まだスケジュールされていなければ、毎時のイベントをスケジュールする。
     *
     * @internal TempFileStore::store() だけが呼ぶ。
     *
     * @throws TempFileException スケジュールの保存の失敗
     */
    public static function schedule(): void
    {
        if (wp_next_scheduled(self::HOOK) !== false) {
            return;
        }

        $result = wp_schedule_event(time(), self::RECURRENCE, self::HOOK, [], true);

        if (is_wp_error($result)) {
            throw new TempFileException("failed to schedule the garbage collection of temporary files: {$result->get_error_message()}");
        }
    }

    /**
     * 既定の一時保管の gc() を実行する。
     *
     * @internal WP-Cron のフックだけが呼ぶ。
     *
     * @throws TempFileException debug のとき、gc() の失敗
     */
    public static function run(Kernel $kernel): void
    {
        try {
            TempFileStore::default()->gc();
        } catch (TempFileException $exception) {
            self::fail($kernel, 'failed to collect expired temporary files', $exception);
        }
    }

    /**
     * @internal Kernel::reset() だけが呼ぶ。
     */
    public static function reset(): void
    {
        self::$registered = false;
    }

    /**
     * 無効になる Kernel が、レジストリに登録済みの最後の Kernel のときだけ、イベントを解除する。
     *
     * 無効化のリクエストでは、有効なテーマ・プラグインの Kernel はすべて boot 済みでレジストリにある(プラグインの
     * 無効化はプラグインの読み込みの後、テーマの切り替え(switch_theme)は切り替え元のテーマの読み込みの後に行われる)。
     *
     * @throws TempFileException debug のとき、イベントの解除の失敗
     */
    private static function unschedule(Kernel $kernel): void
    {
        foreach (Kernel::all() as $registered) {
            if ($registered !== $kernel) {
                return;
            }
        }

        $result = wp_clear_scheduled_hook(self::HOOK, [], true);

        if (is_wp_error($result)) {
            self::fail($kernel, 'failed to unschedule the garbage collection of temporary files', new TempFileException($result->get_error_message()));
        }
    }

    /**
     * 失敗は、debug では例外を投げ、本番では Kernel のロガーに error で記録する。文脈には例外のクラス・場所・
     * previous のクラスだけを入れ、パスを含むメッセージは入れない。
     *
     * @throws TempFileException debug のとき
     */
    private static function fail(Kernel $kernel, string $message, TempFileException $exception): void
    {
        if ($kernel->config()->debug) {
            throw $exception;
        }

        $previous = [];

        for ($current = $exception->getPrevious(); $current !== null; $current = $current->getPrevious()) {
            $previous[] = $current::class;
        }

        $kernel->logger()->log(LogLevel::ERROR, 'tmswp.form: ' . $message, [
            'exceptionClass' => $exception::class,
            'exceptionAt' => $exception->getFile() . ':' . $exception->getLine(),
            'previousClasses' => $previous,
        ]);
    }
}
