<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\DiscoveredHookMethod;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\HookMethodKind;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Testing\BootsKernelBeforeInit;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UsesTempDirectories;

/**
 * Log(tms_log()・Logの静的な呼び出し・TMSWPの内部のログ)を、実物のWordPressで確かめる(docs/07-support.mdの
 * Logの受け入れ基準)。
 *
 * 設定ゼロ(logChannelsを宣言しない)のKernelは、既定のチャネル(SimpleLogger)でWP_CONTENT_DIR/tmswp-logs/に書く。
 * そのディレクトリは開発用のサイトと共有するため、各テストの前に中身を控え、後で元に戻す。
 */
final class LogIntegrationTest extends IntegrationTestCase
{
    use BootsKernelBeforeInit;
    use UsesTempDirectories;

    /** productionPluginConfig()にrecordLogs()を渡したKernelのログを記録するロガー*/
    private RecordingLogger $logger;

    /**
     * テストの前のWP_CONTENT_DIR/tmswp-logs/の状態(ディレクトリがあったか、ディレクトリの更新日時、直下のファイル名=>
     * 中身と更新日時)。SimpleLoggerはローテーションで消すファイルを更新日時の順で決めるため、中身だけでなく更新日時も戻す。
     *
     * @var array{existed: bool, mtime: int|null, files: array<string, array{contents: string, mtime: int}>}|null
     */
    private ?array $logDirectorySnapshot = null;

    public function set_up(): void
    {
        parent::set_up();
        $this->logger = new RecordingLogger();
        $this->snapshotLogDirectory();
    }

    public function tear_down(): void
    {
        // どれかが失敗しても、ほかの後始末を飛ばさない
        try {
            $this->restoreLogDirectory();
        } finally {
            try {
                $this->unregisterAppAutoloaders();
                $this->cleanupTempDirectories();
            } finally {
                parent::tear_down();
            }
        }
    }

    /**
     * docs/07-support.mdの「`tms_log()->info('msg')`が設定ゼロで`WP_CONTENT_DIR/tmswp-logs/{Ymd}-{wp_hash由来の接尾辞}.log`
     * に`{timestamp} [INFO] msg`形式で追記される(統合テスト)」を確かめる。
     */
    public function testTmsLogWithoutLogChannelsAppendsDefaultFormatLineToWpContentLogFile(): void
    {
        $config = $this->productionPluginConfig('ZeroConfigLog', null);
        (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
        $this->bootBeforeInit($config);
        $caller = $this->writeTmsLogCaller($config);
        $date = wp_date('Ymd');
        self::assertIsString($date);
        $logFile = self::expectedLogFile($date);
        // 追記を確かめるため、その日のファイルが無ければ既存の行を1行置く(後始末で消す)
        $before = self::fileContents($logFile);

        if ($before === '') {
            $before = "existing line\n";
            $this->writeFile($logFile, $before);
        }

        require $caller;

        $after = self::fileContents($logFile);
        self::assertSame($before, substr($after, 0, \strlen($before)), 'the existing lines must be kept (appended, not overwritten)');
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \[INFO\] msg\n\z/', substr($after, \strlen($before)));
    }

    /**
     * docs/07-support.mdの「タイムスタンプと`{date}`が`wp_date()`由来である(タイムゾーン`Asia/Tokyo`設定の統合テストで
     * UTCとずれることを確認)」を確かめる。行の時刻は常にUTCから9時間ずれる。ファイル名の日付は、その時刻の
     * Asia/Tokyoの日付になる(UTCの日付と異なるのはUTCの15時から24時の間だけで、常にずれることは次のテストが確かめる)。
     */
    public function testTimestampAndFileNameDateFollowAsiaTokyoSiteTimezone(): void
    {
        add_filter('pre_option_timezone_string', static fn(): string => 'Asia/Tokyo');
        $config = $this->productionPluginConfig('TokyoLog', null);
        (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
        $this->bootBeforeInit($config);
        $caller = $this->writeTmsLogCaller($config);
        $tokyo = new DateTimeZone('Asia/Tokyo');
        $start = time();
        $logFile = self::expectedLogFile((new DateTimeImmutable('@' . $start))->setTimezone($tokyo)->format('Ymd'));
        $before = self::fileContents($logFile);

        require $caller;

        $end = time();
        $appended = substr(self::fileContents($logFile), \strlen($before));
        self::assertSame(1, preg_match('/\A(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[INFO\] msg\n\z/', $appended, $matches), $appended);
        // 行の時刻をUTCとして読むと、記録した時刻(UTC)より9時間進んでいる
        $asUtc = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $matches[1], new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $asUtc);
        self::assertGreaterThanOrEqual($start + 9 * 3600, $asUtc->getTimestamp());
        self::assertLessThanOrEqual($end + 9 * 3600, $asUtc->getTimestamp());
    }

    /**
     * docs/07-support.mdの「タイムスタンプと`{date}`が`wp_date()`由来である」のうち、ファイル名の`{date}`を確かめる。
     * Asia/TokyoではUTCと日付が同じ時間帯があるため、今のUTCの日付と日付が異なるタイムゾーン(UTC+14かUTC-11)を選ぶ。
     */
    public function testFileNameDateFollowsSiteTimezoneWhoseDateDiffersFromUtc(): void
    {
        $start = time();
        $timezone = (int) gmdate('G', $start) >= 12 ? 'Pacific/Kiritimati' : 'Pacific/Pago_Pago';
        $siteDate = (new DateTimeImmutable('@' . $start))->setTimezone(new DateTimeZone($timezone))->format('Ymd');
        self::assertNotSame(gmdate('Ymd', $start), $siteDate, 'the chosen timezone must have a date different from UTC');
        add_filter('pre_option_timezone_string', static fn(): string => $timezone);
        $config = $this->productionPluginConfig('SiteDateLog', null);
        (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
        $this->bootBeforeInit($config);
        $caller = $this->writeTmsLogCaller($config);
        $logFile = self::expectedLogFile($siteDate);
        $before = self::fileContents($logFile);

        require $caller;

        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \[INFO\] msg\n\z/', substr(self::fileContents($logFile), \strlen($before)));
    }

    /**
     * docs/07-support.mdの「本番の遅延bootのキャッチアップで動くアプリのコードからの`Log::error()`が、そのKernelに
     * 記録される」を確かめる。initの後に本番相当のKernelをbootし、キャッチアップで初期化されるapp/のHookの
     * register()からLog::error()を呼ぶ。
     */
    public function testLogErrorFromAppCodeRunInProductionCatchUpIsRecordedInThatKernel(): void
    {
        $themeLogger = self::fixtureLogger(self::themeKernel());
        $pluginLogger = self::fixtureLogger(self::pluginKernel());
        $themeBefore = \count($themeLogger->records);
        $pluginBefore = \count($pluginLogger->records);
        $config = $this->productionPluginConfig('CatchUpAppLog', $this->recordLogs());
        $namespace = $config->appNamespace;
        $this->writeFile($config->appDir . '/Hook/LateLogHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hook;

use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;
use TMSWP\\Core\\Log\\Log;

#[AsHook]
final class LateLogHook extends Hook
{
    public function register(): void
    {
        Log::error('logged by app code during catch-up');
    }
}
PHP);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\\Hook\\LateLogHook', AsHook::class, []),
        ], []));
        $this->registerAppAutoloader($config);

        // plugins_loaded(プラグインのHookの初期化のフック)の発火の後のbootは、本番ではキャッチアップで初期化する
        Kernel::boot($config);

        $messages = array_column($this->logger->records, 'message');
        self::assertContains("Kernel::boot() was called after the 'plugins_loaded' hook had fired. Boot from the top level of functions.php or the plugin main file.", $messages);
        self::assertContains('logged by app code during catch-up', $messages);
        self::assertCount($themeBefore, $themeLogger->records);
        self::assertCount($pluginBefore, $pluginLogger->records);
    }

    /**
     * docs/07-support.mdの「TMSWPの内部のログ(フックメソッドの失敗・遅延bootのキャッチアップ)が、扱うKernelの
     * ロガーに記録される(統合テスト)」を確かめる。initの後にbootした本番相当のKernelの、キャッチアップの記録と、
     * WordPressのフックの発火で例外を投げたフックメソッドの記録が、そのKernelのロガーに入り、fixtureのテーマ・
     * プラグインのKernelには入らない。
     */
    public function testInternalLogsOfHookMethodFailureAndCatchUpAreRecordedInTheKernelTheyHandle(): void
    {
        $themeLogger = self::fixtureLogger(self::themeKernel());
        $pluginLogger = self::fixtureLogger(self::pluginKernel());
        $themeBefore = \count($themeLogger->records);
        $pluginBefore = \count($pluginLogger->records);
        $config = $this->productionPluginConfig('HookFailureLog', $this->recordLogs());
        $namespace = $config->appNamespace;
        $hook = 'tmswp_integration/failing_hook_method';
        $this->writeFile($config->appDir . '/Hook/FailingHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hook;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class FailingHook extends Hook
{
    #[Action('{$hook}')]
    public function fail(): void
    {
        throw new \\RuntimeException('hook method failed');
    }
}
PHP);
        (new DiscoveryFile($config))->write(new DiscoveryResult([], [
            new DiscoveredHookMethod($namespace . '\\Hook\\FailingHook', 'fail', $hook, 10, 0, HookMethodKind::Action),
        ]));
        $this->registerAppAutoloader($config);

        Kernel::boot($config);
        do_action($hook);

        $messages = array_column($this->logger->records, 'message');
        self::assertContains("Kernel::boot() was called after the 'init' hook had fired. Boot from the top level of functions.php or the plugin main file.", $messages);
        $failures = array_values(array_filter(
            $this->logger->records,
            static fn(array $record): bool => $record['message'] === 'tmswp.kernel: hook method {class}::{method} failed on {hook}',
        ));
        self::assertCount(1, $failures);
        self::assertSame('error', $failures[0]['level']);
        self::assertSame($namespace . '\\Hook\\FailingHook', $failures[0]['context']['class'] ?? null);
        self::assertSame('fail', $failures[0]['context']['method'] ?? null);
        self::assertSame($hook, $failures[0]['context']['hook'] ?? null);
        self::assertSame(RuntimeException::class, $failures[0]['context']['exceptionClass'] ?? null);
        self::assertCount($themeBefore, $themeLogger->records);
        self::assertCount($pluginBefore, $pluginLogger->records);
    }

    /** fixtureのテーマ・プラグインがtestチャネルに登録したRecordingLoggerを返す。*/
    private static function fixtureLogger(Kernel $kernel): RecordingLogger
    {
        $logger = $kernel->logger()->channel('test');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }

    /**
     * 本番相当(debug=false)のプラグイン形態の構成を、一時ディレクトリのプラグインで作る。テーマ形態にしないのは、
     * テーマ切替のフック(他のテストが発火させる)にこのKernelを登録しないためである。
     *
     * @param (Closure(): array<string, LogChannel>)|null $logChannels nullなら設定ゼロ(既定のチャネル)
     */
    private function productionPluginConfig(string $name, ?Closure $logChannels): KernelConfig
    {
        $root = $this->createTempDirectory('integration-' . strtolower($name));
        self::assertTrue(mkdir($root . '/app'));
        $this->writeFile($root . '/plugin.php', "<?php\n");

        return KernelConfig::plugin($root . '/plugin.php', $root . '/app', 'TmswpIntegration' . $name . uniqid(), false, logChannels: $logChannels);
    }

    /**
     * Kernelのログを、このテストのRecordingLoggerへ送るlogChannelsを返す(ファイルに書かない)。
     *
     * @return Closure(): array<string, LogChannel>
     */
    private function recordLogs(): Closure
    {
        $logger = $this->logger;

        return static fn(): array => ['integration' => new LogChannel($logger)];
    }

    /** Kernelのルートの中に、tms_log()->info('msg')を呼ぶクラスの無いファイルを書き、そのパスを返す。*/
    private function writeTmsLogCaller(KernelConfig $config): string
    {
        $file = $config->rootDir() . '/log-caller.php';
        $this->writeFile($file, "<?php\n\ndeclare(strict_types=1);\n\ntms_log()->info('msg');\n");

        return $file;
    }

    private static function logDirectory(): string
    {
        return WP_CONTENT_DIR . '/tmswp-logs';
    }

    /**
     * 設定ゼロのSimpleLoggerが書くファイルのパス。名前は`{date}-{wp_hash由来の接尾辞}.log`で、接尾辞は既定の
     * fileNameFormat(`{date}`)ごとのwp_hash()である。
     */
    private static function expectedLogFile(string $date): string
    {
        return self::logDirectory() . '/' . $date . '-' . wp_hash('tmswp-simple-logger:{date}') . '.log';
    }

    /**ファイルの中身を返す。無ければ空文字。*/
    private static function fileContents(string $file): string
    {
        if (!is_file($file)) {
            return '';
        }

        $contents = file_get_contents($file);
        self::assertIsString($contents);

        return $contents;
    }

    private function snapshotLogDirectory(): void
    {
        $dir = self::logDirectory();
        $files = [];
        clearstatcache();

        foreach (self::logDirectoryFiles() as $name) {
            $files[$name] = ['contents' => self::fileContents($dir . '/' . $name), 'mtime' => self::modificationTime($dir . '/' . $name)];
        }

        $this->logDirectorySnapshot = ['existed' => is_dir($dir), 'mtime' => is_dir($dir) ? self::modificationTime($dir) : null, 'files' => $files];
    }

    /**テストが足したファイルを消し、変えたファイルを元の中身に戻す。テストが作ったディレクトリは消す。*/
    private function restoreLogDirectory(): void
    {
        if ($this->logDirectorySnapshot === null) {
            return;
        }

        $dir = self::logDirectory();
        $snapshot = $this->logDirectorySnapshot;
        $this->logDirectorySnapshot = null;

        foreach (self::logDirectoryFiles() as $name) {
            if (!\array_key_exists($name, $snapshot['files'])) {
                self::assertTrue(unlink($dir . '/' . $name));
            }
        }

        foreach ($snapshot['files'] as $name => $file) {
            if (self::fileContents($dir . '/' . $name) !== $file['contents'] || !is_file($dir . '/' . $name)) {
                self::assertNotFalse(file_put_contents($dir . '/' . $name, $file['contents']));
            }

            self::assertTrue(touch($dir . '/' . $name, $file['mtime']));
        }

        if (!$snapshot['existed'] && is_dir($dir)) {
            self::assertTrue(rmdir($dir));
        }

        if ($snapshot['mtime'] !== null) {
            self::assertTrue(touch($dir, $snapshot['mtime']));
        }

        // 戻した結果が、テストの前の中身と更新日時に一致する
        clearstatcache();
        $restored = [];

        foreach (self::logDirectoryFiles() as $name) {
            $restored[$name] = ['contents' => self::fileContents($dir . '/' . $name), 'mtime' => self::modificationTime($dir . '/' . $name)];
        }

        self::assertSame($snapshot['files'], $restored);
        self::assertSame($snapshot['existed'], is_dir($dir));
        self::assertSame($snapshot['mtime'], is_dir($dir) ? self::modificationTime($dir) : null);
    }

    private static function modificationTime(string $path): int
    {
        $mtime = filemtime($path);
        self::assertIsInt($mtime, $path);

        return $mtime;
    }

    /**
     * WP_CONTENT_DIR/tmswp-logs/の直下のファイル名(設定ゼロのSimpleLoggerはサブディレクトリを作らない)。
     *
     * @return list<string>
     */
    private static function logDirectoryFiles(): array
    {
        $dir = self::logDirectory();

        if (!is_dir($dir)) {
            return [];
        }

        $entries = scandir($dir);
        self::assertIsArray($entries);

        return array_values(array_filter($entries, static fn(string $entry): bool => is_file($dir . '/' . $entry)));
    }
}
