<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Exception\MigrationException;
use TMSWP\Core\Kernel\Kernel;
use WP_CLI;

/**
 * `wp tmswp migrate [--force] [--dry-run]`。起動中の全 Kernel の Migrator について、Model のテーブル定義を適用する
 * (06-model §6)。独自テーブルの定義を適用する唯一の経路である。
 */
final class MigrateCommand
{
    private const string SYNOPSIS = 'wp tmswp migrate [--force] [--dry-run]';

    private static bool $registered = false;

    /**
     * WP-CLI の環境なら `wp tmswp migrate` を登録する(プロセスで1回)。Migrator::listen() が呼ぶ。
     */
    public static function register(): void
    {
        if (self::$registered || !\defined('WP_CLI') || WP_CLI !== true || !class_exists('WP_CLI')) {
            return;
        }

        WP_CLI::add_command('tmswp migrate', new self());
        self::$registered = true;
    }

    /**
     * @internal
     */
    public static function reset(): void
    {
        self::$registered = false;
    }

    /**
     * Applies the table definitions of the models of all booted TMSWP kernels.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Run dbDelta for every table, even if its definition hash matches the stored hash.
     *
     * [--dry-run]
     * : List the tables whose definition hash differs from the stored hash, without changing the database.
     *
     * ## EXAMPLES
     *
     *     wp tmswp migrate
     *     wp tmswp migrate --force
     *     wp tmswp migrate --dry-run
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        if (!class_exists('WP_CLI')) {
            throw new KernelException('WP_CLI is not available');
        }

        if ($args !== [] || array_diff(array_keys($assocArgs), ['force', 'dry-run']) !== []) {
            /* translators: %s: command synopsis */
            WP_CLI::error(\sprintf(__('Usage: %s', 'tmswp'), self::SYNOPSIS));
        }

        // フラグは値を取らない。WP-CLI は `--x` を true、`--no-x` を false として渡し、false は指定なしとして扱う。
        // それ以外の値(`--x=0` 等)は、利用者の意図が分からないためエラーにする
        foreach (['force', 'dry-run'] as $flag) {
            if (!\is_bool($assocArgs[$flag] ?? false)) {
                /* translators: %s: command-line flag such as force */
                WP_CLI::error(\sprintf(__('--%s does not take a value', 'tmswp'), $flag));
            }
        }

        $force = ($assocArgs['force'] ?? false) === true;
        $dryRun = ($assocArgs['dry-run'] ?? false) === true;

        if ($force && $dryRun) {
            WP_CLI::error(__('--force and --dry-run cannot be used together', 'tmswp'));
        }

        $migrators = [];

        foreach (Kernel::all() as $kernel) {
            $migrator = $kernel->container()->get(Migrator::class);

            if ($migrator->models() !== []) {
                $migrators[] = $migrator;
            }
        }

        if ($migrators === []) {
            WP_CLI::warning(__('No models registered.', 'tmswp'));

            return;
        }

        $migrated = 0;
        $skipped = 0;

        try {
            if ($dryRun) {
                foreach ($migrators as $migrator) {
                    foreach ($migrator->status() as $status) {
                        WP_CLI::log(\sprintf(
                            $status->current
                                /* translators: %s: database table name */
                                ? __('%s: up-to-date', 'tmswp')
                                /* translators: %s: database table name */
                                : __('%s: changed', 'tmswp'),
                            $status->table,
                        ));
                    }
                }

                return;
            }

            foreach ($migrators as $migrator) {
                foreach ($migrator->migrateAll($force) as $result) {
                    if ($result->action === MigrationAction::Migrated) {
                        ++$migrated;
                        /* translators: %s: database table name */
                        WP_CLI::log(\sprintf(__('Migrated: %s', 'tmswp'), $result->table));
                    } else {
                        ++$skipped;
                        /* translators: %s: database table name */
                        WP_CLI::log(\sprintf(__('Skipped: %s (up to date)', 'tmswp'), $result->table));
                    }
                }
            }
        } catch (MigrationException $exception) {
            // WP_CLI::error() は非 0 で終了するため、ここから先へは進まない
            WP_CLI::error($exception->getMessage());
        }

        /* translators: 1: number of migrated tables, 2: number of skipped tables */
        WP_CLI::success(\sprintf(__('%1$d table(s) migrated, %2$d skipped.', 'tmswp'), $migrated, $skipped));
    }
}
