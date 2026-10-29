<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Exception\KernelException;
use WP_CLI;

/**
 * `wp tmswp discover [--check] [--kernels=<count>]`。起動中の全KernelのappDirをdebugに関係なく走査し、
 * 探索結果ファイルを生成する。`--check`は生成する内容とファイルをバイト単位で比べ、無い・古いKernelがあれば
 * 非0で終わる。`--kernels`は起動中のKernelの数を確かめ、違えば走査せずに非0で終わる(起動しなかったKernelを
 * 比べないまま照合が成功しないようにする)。
 */
final class DiscoverCommand
{
    private const USAGE = 'Usage: wp tmswp discover [--check] [--kernels=<count>]';

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        if (!class_exists('WP_CLI')) {
            throw new KernelException('WP_CLI is not available');
        }

        if ($args !== [] || array_diff(array_keys($assocArgs), ['check', 'kernels']) !== []) {
            WP_CLI::error(self::USAGE);
        }

        $expectedCount = null;

        if (\array_key_exists('kernels', $assocArgs)) {
            $expectedCount = self::kernelCount($assocArgs['kernels']);

            if ($expectedCount === null) {
                WP_CLI::error(self::USAGE);
            }
        }

        $kernels = Kernel::all();

        if ($kernels === []) {
            WP_CLI::error('no TMSWP kernel is booted; boot the kernels from the theme or plugins before running wp tmswp discover');
        }

        if ($expectedCount !== null && \count($kernels) !== $expectedCount) {
            WP_CLI::error(\sprintf(
                'expected %d booted kernel(s) but found %d: %s; check that the theme and plugins that boot the kernels are active',
                $expectedCount,
                \count($kernels),
                implode(', ', array_keys($kernels)),
            ));
        }

        try {
            // すべての Kernel を走査し終えてから書く(途中の Kernel の失敗で一部のファイルだけが更新されないようにする)
            $targets = [];

            foreach ($kernels as $namespace => $kernel) {
                $targets[$namespace] = [self::file($kernel), self::discovery($kernel)->scan()];
            }

            if (\array_key_exists('check', $assocArgs)) {
                $stale = $this->staleFiles($targets);

                if ($stale !== []) {
                    WP_CLI::error(implode("\n", $stale) . "\nrun wp tmswp discover to regenerate the discovery files");
                }

                WP_CLI::success('discovery files are up to date for ' . \count($targets) . ' kernel(s)');

                return;
            }

            $written = 0;

            foreach ($targets as $namespace => [$file, $result]) {
                if ($file->isUpToDate($result)) {
                    WP_CLI::line("{$namespace}: {$file->path()} is up to date");
                    continue;
                }

                $file->write($result);
                WP_CLI::line("{$namespace}: wrote {$file->path()}");
                ++$written;
            }
        } catch (DiscoveryException $exception) {
            // WP_CLI::error() は非 0 で終了するため、ここから先へは進まない
            WP_CLI::error($exception->getMessage());

            return;
        }

        WP_CLI::success('discovery files are up to date for ' . \count($targets) . " kernel(s); wrote {$written} file(s)");
    }

    /**
     * 探索結果ファイルが無い・内容が違う Kernel を「namespace: パス is missing / is out of date」で列挙する。
     *
     * @param array<string, array{DiscoveryFile, DiscoveryResult}> $targets
     *
     * @return list<string>
     */
    private function staleFiles(array $targets): array
    {
        $stale = [];

        foreach ($targets as $namespace => [$file, $result]) {
            if (!is_file($file->path())) {
                $stale[] = "{$namespace}: {$file->path()} is missing";
            } elseif (!$file->isUpToDate($result)) {
                $stale[] = "{$namespace}: {$file->path()} is out of date";
            }
        }

        return $stale;
    }

    /**
     * `--kernels=<count>`の値を、1以上の整数にする。1以上の整数の10進表記(先頭に0が付かない)でなければnull。
     */
    private static function kernelCount(mixed $value): ?int
    {
        if (!\is_string($value) || !ctype_digit($value)) {
            return null;
        }

        $count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $count === false ? null : $count;
    }

    private static function file(Kernel $kernel): DiscoveryFile
    {
        $file = $kernel->container()->get(DiscoveryFile::class);

        return $file;
    }

    private static function discovery(Kernel $kernel): Discovery
    {
        $discovery = $kernel->container()->get(Discovery::class);

        return $discovery;
    }
}
