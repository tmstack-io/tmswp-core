<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

use Composer\InstalledVersions;
use TMSWP\Core\Exception\KernelException;

/**
 * 読み込まれたすべての vendor の installed.php にある tmswp/core の版を、動いている core の版(Kernel::VERSION)と照合する。
 *
 * テーマとプラグインがそれぞれ別の版の tmswp/core を同梱していても、PHP のクラスは先に読み込まれた側の1組だけが
 * 使われる。Composer の installed.php は vendor ごとに読まれるため、食い違いはこのデータで検出できる。
 *
 * @internal
 */
final class InstalledVersionCheck
{
    private const string PACKAGE = 'tmswp/core';

    private function __construct() {}

    /**
     * Composer が読み込んだすべての installed.php を照合する。
     *
     * @throws KernelException Composer 2 の InstalledVersions が無い場合、または版が食い違う場合(設定の誤り)
     */
    public static function assertInstalledVersions(string $version): void
    {
        if (!class_exists(InstalledVersions::class)) {
            throw new KernelException('cannot verify the installed tmswp/core versions: Composer\InstalledVersions is not available; load TMSWP through the autoloader of Composer 2');
        }

        self::assertConsistent(InstalledVersions::getAllRawData(), $version);
    }

    /**
     * installed.php のデータの並びを照合する。
     *
     * 照合するのは、`tmswp/core` を実際に入れた記載(`pretty_version` がある記載)のうち、版がリリース版の形
     * (`x.y.z`。タグの先頭の `v` は除く)のものに限る。dev 版(`dev-*` のブランチや path repository、
     * `x.y.x-dev` 等)とプレリリースは Kernel::VERSION と比べられないため、照合の対象外とする。replace・provide
     * だけの記載(monorepo のルート等)は tmswp/core を入れたものではないため、同じく対象外とする。
     *
     * @param array<mixed> $installed InstalledVersions::getAllRawData() の戻り値
     *
     * @throws KernelException 照合の対象の版が $version と食い違う場合(設定の誤り)
     */
    public static function assertConsistent(array $installed, string $version): void
    {
        $mismatches = [];

        foreach ($installed as $data) {
            $package = \is_array($data) && \is_array($data['versions'] ?? null) ? ($data['versions'][self::PACKAGE] ?? null) : null;

            if (!\is_array($package) || !\is_string($package['pretty_version'] ?? null)) {
                continue;
            }

            $prettyVersion = $package['pretty_version'];

            if (preg_match('/^v?(\d+\.\d+\.\d+)$/', $prettyVersion, $matches) !== 1 || $matches[1] === $version) {
                continue;
            }

            $installPath = \is_string($package['install_path'] ?? null) ? $package['install_path'] : '(unknown install path)';
            $mismatches[] = "{$prettyVersion} at {$installPath}";
        }

        if ($mismatches !== []) {
            throw new KernelException(
                'tmswp/core versions on this site do not match the loaded core ' . $version . ': ' . implode(', ', $mismatches)
                . '. PHP uses the classes of the tmswp/core that was loaded first, so every theme and plugin on the site must bundle the same tmswp/core version',
            );
        }
    }
}
