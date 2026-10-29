<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Testing\BootsKernelBeforeInit;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UsesTempDirectories;

/**
 * ルートが入れ子の2つのKernelで、Logの静的な呼び出しと`tms_log()`が、呼び出したファイルを含む最も長く一致する
 * ルートのKernelに記録されることを、実物のWordPressで確かめる(docs/07-support.mdのLogの受け入れ基準)。
 *
 * 外側のプラグインのルートの中に、別のKernelのプラグインのルート(inner/)を置く。どちらも本番相当(debug=false)で、
 * initより前にbootする。登録の順(先に登録したKernelを採る・後に登録したKernelを採る誤り)に依らないことを
 * 確かめるため、外側を先にbootする場合と、内側を先にbootする場合の両方を確かめる。
 */
final class NestedRootLogIntegrationTest extends IntegrationTestCase
{
    use BootsKernelBeforeInit;
    use UsesTempDirectories;

    public function tear_down(): void
    {
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    /**
     * docs/07-support.mdの「ルートが入れ子なら最も長く一致するルートのKernelに記録される(統合テスト)」を、外側の
     * Kernelを先にbootして確かめる。
     */
    public function testNestedRootLogsGoToTheLongestMatchingRootWhenOuterKernelBootsFirst(): void
    {
        $this->assertNestedRootLogs(outerFirst: true);
    }

    /**
     * docs/07-support.mdの「ルートが入れ子なら最も長く一致するルートのKernelに記録される(統合テスト)」を、内側の
     * Kernelを先にbootして確かめる。
     */
    public function testNestedRootLogsGoToTheLongestMatchingRootWhenInnerKernelBootsFirst(): void
    {
        $this->assertNestedRootLogs(outerFirst: false);
    }

    /**
     * 外側・内側のルートのファイル(内側はさらに下のディレクトリにも置く)から、Log::error()とtms_log()の
     * warning・errorを呼び、各Kernelのロガーに、そのKernelが最も長く一致するファイルの記録だけが、
     * 呼んだレベルで入ることを確かめる。
     */
    private function assertNestedRootLogs(bool $outerFirst): void
    {
        $outerRoot = (string) realpath($this->createTempDirectory('integration-nested-outer'));
        $innerRoot = $outerRoot . '/inner';
        $outerLogger = new RecordingLogger();
        $innerLogger = new RecordingLogger();
        $outer = $this->pluginConfig($outerRoot, 'NestedOuter', $outerLogger);
        $inner = $this->pluginConfig($innerRoot, 'NestedInner', $innerLogger);
        self::assertSame($outerRoot, $outer->rootDir());
        self::assertSame($innerRoot, $inner->rootDir());

        foreach ($outerFirst ? [$outer, $inner] : [$inner, $outer] as $config) {
            (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
            $this->bootBeforeInit($config);
        }

        $outerCaller = $this->writeCaller($outerRoot . '/templates/outer-caller.php', 'outer');
        $innerCaller = $this->writeCaller($innerRoot . '/templates/inner-caller.php', 'inner');
        $deepInnerCaller = $this->writeCaller($innerRoot . '/templates/deep/inner-deep-caller.php', 'inner deep');

        require $outerCaller;
        require $innerCaller;
        require $deepInnerCaller;

        self::assertSame([
            ['error', 'outer static'],
            ['warning', 'outer tms_log'],
            ['error', 'outer tms_log error'],
        ], self::levelsAndMessages($outerLogger));
        self::assertSame([
            ['error', 'inner static'],
            ['warning', 'inner tms_log'],
            ['error', 'inner tms_log error'],
            ['error', 'inner deep static'],
            ['warning', 'inner deep tms_log'],
            ['error', 'inner deep tms_log error'],
        ], self::levelsAndMessages($innerLogger));
    }

    /** Log::error()とtms_log()のwarning・errorを呼ぶ、クラスの無いファイルを書き、そのパスを返す。*/
    private function writeCaller(string $file, string $label): string
    {
        $this->writeFile($file, <<<PHP
<?php

declare(strict_types=1);

\\TMSWP\\Core\\Log\\Log::error('{$label} static');
tms_log()->warning('{$label} tms_log');
tms_log()->error('{$label} tms_log error');
PHP);

        return $file;
    }

    /**
     * $rootをルートとする本番相当(debug=false)のプラグイン形態の構成を作る。ログは$loggerに記録する。
     */
    private function pluginConfig(string $root, string $name, RecordingLogger $logger): KernelConfig
    {
        self::assertTrue(mkdir($root . '/app', 0775, true));
        $this->writeFile($root . '/plugin.php', "<?php\n");

        return KernelConfig::plugin(
            $root . '/plugin.php',
            $root . '/app',
            'TmswpIntegration' . $name . uniqid(),
            false,
            logChannels: static fn(): array => ['integration' => new LogChannel($logger)],
        );
    }

    /** @return list<array{mixed, string}> */
    private static function levelsAndMessages(RecordingLogger $logger): array
    {
        return array_map(static fn(array $record): array => [$record['level'], $record['message']], $logger->records);
    }
}
