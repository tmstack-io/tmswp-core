<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use RuntimeException;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\UsesTempDirectories;
use TMSWP\Testing\WpCliPharDeprecations;

/**
 * `wp tmswp discover`を、実物のWP-CLIのプロセスでwp-envの開発用サイトに対して実行して確かめる。
 *
 * サブプロセスは`--require=tests/fixtures/_wp-cli.php`で、そのプロセスの中だけfixtureのテーマとプラグインを有効にする
 * (`composer discover:check`と同じ方法)。Kernelはboot順(プラグイン → テーマ)に処理される。開発用サイトは
 * WP_DEBUGが真のため、fixtureのKernelはbootで`app/`と`build/blocks/`を走査し、探索結果ファイルを読まない。
 *
 * テストはfixtureの探索結果ファイル(fixtureのテーマとプラグインの直下のtmswp-discovery.php)を一時的に消し・書き換え、
 * テーマの`build/blocks/`に一時的にブロックを足す。どれもテストの中で必ず元に戻す(finally)。
 */
final class DiscoverCommandIntegrationTest extends IntegrationTestCase
{
    use UsesTempDirectories;

    private const string PLUGIN_FILE = '/tests/fixtures/plugins/tmswp-fixture-plugin/tmswp-discovery.php';

    private const string THEME_FILE = '/tests/fixtures/themes/tmswp-fixture-theme/tmswp-discovery.php';

    private const string DUPLICATE_BLOCK_DIR = '/tests/fixtures/themes/tmswp-fixture-theme/build/blocks/zz-duplicate-card';

    /** 古い内容にするために探索結果ファイルの末尾に足す行(ファイルとしては読めるが、生成する内容とバイト単位で違う) */
    private const string STALE_SUFFIX = "// stale\n";

    /** @var array<string, string> 探索結果ファイルのパス => テストの開始の時点の内容 */
    private array $originals = [];

    public function set_up(): void
    {
        parent::set_up();

        foreach ([self::PLUGIN_FILE, self::THEME_FILE] as $relative) {
            $path = self::root() . $relative;
            $content = file_get_contents($path);
            self::assertIsString($content, "cannot read {$path}");
            $this->originals[$path] = $content;
        }

        self::assertDirectoryDoesNotExist(self::root() . self::DUPLICATE_BLOCK_DIR);
    }

    public function tear_down(): void
    {
        try {
            self::removeBlockDirectory(self::root() . self::DUPLICATE_BLOCK_DIR);

            foreach ($this->originals as $path => $content) {
                if (!is_file($path) || file_get_contents($path) !== $content) {
                    file_put_contents($path, $content);
                }
            }

            $this->originals = [];
        } finally {
            // 前の後始末が失敗しても、一時ディレクトリを残さない
            try {
                $this->cleanupTempDirectories();
            } finally {
                parent::tear_down();
            }
        }
    }

    /**
     * docs/02の受け入れ基準「`--check`はファイルが無い・内容が違うKernelをappNamespaceとファイルパスで列挙して非0で
     * 終わり、すべて最新なら成功する」を確かめる。照合はファイルを書かない。
     */
    public function testCheckListsMissingAndStaleFilesByKernelAndExitsNonZeroWithoutWriting(): void
    {
        $upToDate = self::wp(['tmswp', 'discover', '--check', '--kernels=2']);

        self::assertSame(0, $upToDate['code'], $upToDate['stdout'] . $upToDate['stderr']);
        self::assertSame("Success: discovery files are up to date for 2 kernel(s)\n", $upToDate['stdout']);

        $plugin = self::root() . self::PLUGIN_FILE;
        $theme = self::root() . self::THEME_FILE;
        file_put_contents($plugin, $this->originals[$plugin] . self::STALE_SUFFIX);
        unlink($theme);

        $result = self::wp(['tmswp', 'discover', '--check', '--kernels=2']);

        self::assertSame(1, $result['code'], $result['stdout'] . $result['stderr']);
        self::assertSame('', $result['stdout']);
        self::assertSame(
            "Error: TmswpFixturePlugin: {$plugin} is out of date\nTmswpFixtureTheme: {$theme} is missing\nrun wp tmswp discover to regenerate the discovery files\n",
            $result['stderr'],
        );
        self::assertSame($this->originals[$plugin] . self::STALE_SUFFIX, file_get_contents($plugin));
        self::assertFileDoesNotExist($theme);
    }

    /**
     * docs/02の受け入れ基準「`wp tmswp discover`が全Kernelの探索結果ファイルを書き」を確かめる。無いファイルと古い
     * ファイルを、コミット済みのファイルと同じ内容に書き直す。
     */
    public function testDiscoverRewritesTheFilesOfAllKernels(): void
    {
        $plugin = self::root() . self::PLUGIN_FILE;
        $theme = self::root() . self::THEME_FILE;
        file_put_contents($plugin, $this->originals[$plugin] . self::STALE_SUFFIX);
        unlink($theme);

        $result = self::wp(['tmswp', 'discover', '--kernels=2']);

        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);
        self::assertSame(
            "TmswpFixturePlugin: wrote {$plugin}\nTmswpFixtureTheme: wrote {$theme}\nSuccess: discovery files are up to date for 2 kernel(s); wrote 2 file(s)\n",
            $result['stdout'],
        );
        self::assertSame($this->originals[$plugin], file_get_contents($plugin));
        self::assertSame($this->originals[$theme], file_get_contents($theme));
    }

    /**
     * docs/02の受け入れ基準「走査の失敗(Kernelの中のブロック名の重複を含む)ではどのファイルも書かずに非0で終わる」を
     * 確かめる。後に走査するテーマのKernelでブロック名が重複すると、先に走査したプラグインのKernelの古いファイルも
     * 書き直さない。
     *
     * debugのbootも同じ走査をするため、重複したブロックはbootの後、コマンドの実行の直前(WP-CLIの
     * `before_invoke:tmswp discover`)に置く。
     */
    public function testScanFailureInAnyKernelWritesNoFileAndExitsNonZero(): void
    {
        $plugin = self::root() . self::PLUGIN_FILE;
        $theme = self::root() . self::THEME_FILE;
        file_put_contents($plugin, $this->originals[$plugin] . self::STALE_SUFFIX);
        $duplicateDir = self::root() . self::DUPLICATE_BLOCK_DIR;
        $source = self::root() . '/tests/fixtures/themes/tmswp-fixture-theme/build/blocks/card/block.json';
        $require = $this->createTempDirectory('discover-cli') . '/before-discover.php';
        file_put_contents($require, \sprintf(
            <<<'PHP'
                <?php

                declare(strict_types=1);

                require %s;

                WP_CLI::add_hook('before_invoke:tmswp discover', static function (): void {
                    if (!mkdir(%s) || !copy(%s, %s)) {
                        WP_CLI::error('cannot place the duplicate block');
                    }
                });

                PHP,
            var_export(self::root() . '/tests/fixtures/_wp-cli.php', true),
            var_export($duplicateDir, true),
            var_export($source, true),
            var_export($duplicateDir . '/block.json', true),
        ));

        foreach ([[], ['--check']] as $check) {
            $result = self::wp(['tmswp', 'discover', ...$check, '--kernels=2'], $require);
            $label = implode(' ', $check);

            self::assertSame(1, $result['code'], $label . "\n" . $result['stdout'] . $result['stderr']);
            self::assertSame('', $result['stdout'], $label);
            self::assertStringStartsWith("Error: block name 'tmswp-fixture/card' is declared by both ", $result['stderr'], $label);
            self::assertStringContainsString('zz-duplicate-card/block.json; block names must be unique', $result['stderr'], $label);
            self::assertSame($this->originals[$plugin] . self::STALE_SUFFIX, file_get_contents($plugin), $label);
            self::assertSame($this->originals[$theme], file_get_contents($theme), $label);
            self::removeBlockDirectory($duplicateDir);
        }
    }

    /**
     * `--kernels`が起動中のKernelの数と違えば、走査も書き込みもせずに非0で終わる(起動しなかったKernelを比べないまま
     * 照合が成功しないようにする。docs/02 §`wp tmswp discover`)。
     */
    public function testKernelCountMismatchExitsNonZeroWithoutWriting(): void
    {
        $plugin = self::root() . self::PLUGIN_FILE;
        file_put_contents($plugin, $this->originals[$plugin] . self::STALE_SUFFIX);

        $result = self::wp(['tmswp', 'discover', '--kernels=3']);

        self::assertSame(1, $result['code'], $result['stdout'] . $result['stderr']);
        self::assertSame('', $result['stdout']);
        self::assertSame(
            "Error: expected 3 booted kernel(s) but found 2: TmswpFixturePlugin, TmswpFixtureTheme; check that the theme and plugins that boot the kernels are active\n",
            $result['stderr'],
        );
        self::assertSame($this->originals[$plugin] . self::STALE_SUFFIX, file_get_contents($plugin));
    }

    /**
     * リポジトリのルート(サブプロセスのWP-CLIも同じパスで見る)。
     */
    private static function root(): string
    {
        return \dirname(__DIR__, 4);
    }

    /**
     * テストが置いたブロックのディレクトリ(block.jsonだけを含む)を消す。無ければ何もしない。
     */
    private static function removeBlockDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*');

        if ($files === false) {
            throw new RuntimeException("cannot list {$dir}");
        }

        foreach ($files as $file) {
            unlink($file);
        }

        rmdir($dir);
    }

    /**
     * 開発用サイト(ABSPATH)に対してWP-CLIを実行する。$requireの既定は、fixtureのテーマとプラグインをそのプロセスの
     * 中だけ有効にするtests/fixtures/_wp-cli.php。
     *
     * @param list<string> $arguments
     *
     * @return array{code: int, stdout: string, stderr: string, removedDeprecations: int}
     */
    private static function wp(array $arguments, ?string $require = null): array
    {
        $require ??= self::root() . '/tests/fixtures/_wp-cli.php';
        $command = ['wp', '--path=' . ABSPATH, '--require=' . $require, ...$arguments];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::root());

        if (!\is_resource($process)) {
            throw new RuntimeException('cannot start ' . implode(' ', $command));
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        // PHP 8.5でWP-CLI 2.12.0のphar自身が出す非推奨の行だけを除く(WpCliPharDeprecations。WP-CLIが対応したら外す)
        $stripped = WpCliPharDeprecations::strip($stderr);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stripped['output'], 'removedDeprecations' => $stripped['removed']];
    }
}
