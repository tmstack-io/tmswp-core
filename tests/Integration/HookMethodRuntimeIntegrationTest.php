<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use Closure;
use RuntimeException;
use Throwable;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Exception\ContainerException;
use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Exception\KernelException;
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
use WP_Hook;

/**
 * `#[Action]`/`#[Filter]`のメソッドの実行時の扱い(失敗の隔離・引数の切り詰め・Container経由の1回だけの生成)と、
 * 本番の遅延bootのキャッチアップが失敗したときのKernelのレジストリを、実物のWordPressのフックの発火で確かめる
 * (docs/02-kernel.mdの受け入れ基準)。
 *
 * Kernelは一時ディレクトリのプラグイン形態で作る。本番相当(debug=false)のKernelは探索結果ファイルだけを読むため、
 * テストが探索結果ファイルを書く。
 */
final class HookMethodRuntimeIntegrationTest extends IntegrationTestCase
{
    use BootsKernelBeforeInit;
    use UsesTempDirectories;

    private const string FAILURE_MESSAGE = 'tmswp.kernel: hook method {class}::{method} failed on {hook}';

    /** このテストが作ったKernelのログを記録するロガー */
    private RecordingLogger $logger;

    public function set_up(): void
    {
        parent::set_up();
        $this->logger = new RecordingLogger();
    }

    public function tear_down(): void
    {
        $this->unregisterAppAutoloaders();
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    /**
     * docs/02-kernel.mdの「本番の遅延bootの…キャッチアップが失敗したbootの後、`Kernel::all()`にそのKernelが残らない」
     * を確かめる。initの後にbootした本番相当のKernelは、キャッチアップの間はレジストリに登録されている
     * (plugins_loadedのグループのHookのregister()から見える)。その後のinitのグループで、builtinのアダプタが
     * 存在しない投稿タイプを指す設定の誤りがEntityDefinitionExceptionを投げると、bootはその例外を投げ、
     * Kernel::all()・Kernel::get()・Kernel::forFile()のどれからもそのKernelは見えない。
     */
    public function testFailedProductionCatchUpLeavesNoKernelInTheRegistry(): void
    {
        $config = $this->pluginConfig('FailedCatchUp', false);
        $namespace = $config->appNamespace;
        $missing = 'tmswp_missing_' . substr(md5($namespace), 0, 8);
        $this->writeFile($config->appDir . '/Hook/RegistryProbeHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hook;

use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;
use TMSWP\\Core\\Kernel\\Kernel;

#[AsHook]
final class RegistryProbeHook extends Hook
{
    public function register(): void
    {
        do_action('tmswp_integration/catch_up_registry', array_keys(Kernel::all()));
    }
}
PHP);
        $this->writeFile($config->appDir . '/Post/BrokenPost.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Post;

use TMSWP\\Core\\Attribute\\AsPostType;
use TMSWP\\Core\\Post\\PostType;
use TMSWP\\Core\\Post\\PostTypeDefinition;

#[AsPostType]
final class BrokenPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::builtin('{$missing}');
    }
}
PHP);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\\Hook\\RegistryProbeHook', AsHook::class, []),
            new DiscoveredComponent($namespace . '\\Post\\BrokenPost', AsPostType::class, []),
        ], []));
        $this->registerAppAutoloader($config);
        /** @var list<list<string>> $registries */
        $registries = [];
        add_action('tmswp_integration/catch_up_registry', static function (array $namespaces) use (&$registries): void {
            /** @var list<string> $namespaces */
            $registries[] = $namespaces;
        });

        try {
            Kernel::boot($config);
            self::fail('EntityDefinitionException was not thrown');
        } catch (EntityDefinitionException $exception) {
            self::assertSame("builtin post type does not exist: {$missing}", $exception->getMessage());
        }

        // キャッチアップの間(例外の前)は、アプリのコードからこのKernelが見えていた
        self::assertCount(1, $registries);
        self::assertContains($namespace, $registries[0]);
        // キャッチアップが失敗したbootの後は、レジストリにこのKernelが残らない
        self::assertArrayNotHasKey($namespace, Kernel::all());

        try {
            Kernel::get($namespace);
            self::fail('KernelException was not thrown by Kernel::get()');
        } catch (KernelException $exception) {
            self::assertSame("no TMSWP kernel booted for namespace '{$namespace}'", $exception->getMessage());
        }

        $file = $config->rootDir() . '/plugin.php';
        $this->expectException(KernelException::class);
        Kernel::forFile($file);
    }

    /**
     * docs/02-kernel.mdの「`#[Action]`/`#[Filter]`メソッドの失敗が…本番ではそのKernelのロガーに`error`で記録されて
     * そのメソッドだけスキップされ、第1引数が返る」を、実物のapply_filters()・do_action()で確かめる。
     * 失敗したメソッドより前のコールバックが変えた値が、失敗したメソッドの位置での第1引数としてそのまま次の
     * コールバックへ渡り、同じフックの後続のコールバックは実行される。引数を宣言しない#[Filter]の失敗は元の値を返す。
     * 記録はこのKernelのロガーだけに入り、fixtureのテーマ・プラグインのKernelには入らない。
     */
    public function testFailingHookMethodsInProductionAreLoggedAsErrorAndSkippedWhileLaterCallbacksRun(): void
    {
        $themeLogger = self::fixtureLogger(self::themeKernel());
        $pluginLogger = self::fixtureLogger(self::pluginKernel());
        $themeBefore = \count($themeLogger->records);
        $pluginBefore = \count($pluginLogger->records);
        $config = $this->pluginConfig('FailingMethods', false);
        $class = $this->writeFailingHook($config);
        (new DiscoveryFile($config))->write(new DiscoveryResult([], [
            new DiscoveredHookMethod($class, 'failFilter', 'tmswp_integration/failing_filter', 10, 2, HookMethodKind::Filter),
            new DiscoveredHookMethod($class, 'failFilterWithoutArguments', 'tmswp_integration/failing_filter_none', 10, 0, HookMethodKind::Filter),
            new DiscoveredHookMethod($class, 'failAction', 'tmswp_integration/failing_action', 10, 1, HookMethodKind::Action),
        ]));
        $this->registerAppAutoloader($config);
        $this->bootBeforeInit($config);

        /** @var list<array{string, mixed}> $later */
        $later = [];
        add_filter('tmswp_integration/failing_filter', static fn(string $value): string => $value . '-early', 5);
        add_filter('tmswp_integration/failing_filter', static function (string $value, string $extra) use (&$later): string {
            $later[] = ['filter', $value . '|' . $extra];

            return $value . '-later';
        }, 20, 2);
        add_action('tmswp_integration/failing_action', static function (string $value) use (&$later): void {
            $later[] = ['action', $value];
        }, 20);

        self::assertSame('original-early-later', apply_filters('tmswp_integration/failing_filter', 'original', 'extra'));
        self::assertSame('kept', apply_filters('tmswp_integration/failing_filter_none', 'kept', 'extra'));
        do_action('tmswp_integration/failing_action', 'fired');

        self::assertSame([['filter', 'original-early|extra'], ['action', 'fired']], $later);
        $failures = array_values(array_filter($this->logger->records, static fn(array $record): bool => $record['message'] === self::FAILURE_MESSAGE));
        self::assertSame(
            [
                ['error', 'failFilter', 'tmswp_integration/failing_filter', RuntimeException::class],
                ['error', 'failFilterWithoutArguments', 'tmswp_integration/failing_filter_none', RuntimeException::class],
                ['error', 'failAction', 'tmswp_integration/failing_action', RuntimeException::class],
            ],
            array_map(static fn(array $record): array => [
                $record['level'],
                $record['context']['method'] ?? null,
                $record['context']['hook'] ?? null,
                $record['context']['exceptionClass'] ?? null,
            ], $failures),
        );

        foreach ($failures as $failure) {
            self::assertSame($class, $failure['context']['class'] ?? null);
        }

        self::assertCount($themeBefore, $themeLogger->records);
        self::assertCount($pluginBefore, $pluginLogger->records);
    }

    /**
     * docs/02-kernel.mdの「引数を宣言しない`#[Filter]`も第1引数を受け取って登録され、…成功時はメソッドの戻り値を返し、
     * メソッドには引数を渡さない(宣言引数数に切り詰める)」を確かめる。WordPressへの登録の引数の数は1で、
     * apply_filters()に2つの引数を渡しても、メソッドが受け取る引数(func_num_args())は0である。
     */
    public function testFilterMethodWithoutParametersIsRegisteredWithOneArgumentAndReceivesNone(): void
    {
        $config = $this->pluginConfig('FilterWithoutArguments', false);
        $namespace = $config->appNamespace;
        $hook = 'tmswp_integration/filter_without_arguments';
        $this->writeFile($config->appDir . '/Hook/NoArgumentsHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hook;

use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Attribute\\Filter;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class NoArgumentsHook extends Hook
{
    #[Filter('{$hook}')]
    public function replace(): string
    {
        do_action('tmswp_integration/filter_without_arguments_received', func_num_args());

        return 'replaced';
    }
}
PHP);
        (new DiscoveryFile($config))->write(new DiscoveryResult([], [
            new DiscoveredHookMethod($namespace . '\\Hook\\NoArgumentsHook', 'replace', $hook, 10, 0, HookMethodKind::Filter),
        ]));
        $this->registerAppAutoloader($config);
        $this->bootBeforeInit($config);
        /** @var list<int> $received */
        $received = [];
        add_action('tmswp_integration/filter_without_arguments_received', static function (int $count) use (&$received): void {
            $received[] = $count;
        });

        $filters = $GLOBALS['wp_filter'];
        self::assertIsArray($filters);
        $wpHook = $filters[$hook] ?? null;
        self::assertInstanceOf(WP_Hook::class, $wpHook);
        $entries = $wpHook->callbacks[10] ?? null;
        self::assertIsArray($entries);
        self::assertCount(1, $entries);
        $entry = array_values($entries)[0];
        self::assertIsArray($entry);
        self::assertSame(1, $entry['accepted_args'] ?? null);

        self::assertSame('replaced', apply_filters($hook, 'original', 'extra'));
        self::assertSame([0], $received);
        self::assertSame([], $this->logger->records);
    }

    /**
     * docs/02-kernel.mdの「`#[Action]`/`#[Filter]`メソッドの失敗(Containerでの解決の失敗を含む)が…本番では…`error`で
     * 記録されてそのメソッドだけスキップされ、第1引数が返る」のうち、Containerでの解決の失敗を確かめる。
     * コンストラクタが既定値の無いstringを取るハンドラは、Containerが解決できない。
     */
    public function testUnresolvableHandlerInProductionIsLoggedAsErrorAndSkipped(): void
    {
        $config = $this->pluginConfig('UnresolvableHandler', false);
        $class = $this->writeUnresolvableHook($config);
        $hook = 'tmswp_integration/unresolvable_filter';
        (new DiscoveryFile($config))->write(new DiscoveryResult([], [
            new DiscoveredHookMethod($class, 'filter', $hook, 10, 1, HookMethodKind::Filter),
        ]));
        $this->registerAppAutoloader($config);
        $this->bootBeforeInit($config);
        /** @var list<string> $later */
        $later = [];
        add_filter($hook, static function (string $value) use (&$later): string {
            $later[] = $value;

            return $value;
        }, 20);

        self::assertSame('original', apply_filters($hook, 'original'));

        self::assertSame(['original'], $later);
        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        self::assertSame('error', $record['level']);
        self::assertSame(self::FAILURE_MESSAGE, $record['message']);
        self::assertSame($class, $record['context']['class'] ?? null);
        self::assertSame('filter', $record['context']['method'] ?? null);
        self::assertSame($hook, $record['context']['hook'] ?? null);
        self::assertSame(ContainerException::class, $record['context']['exceptionClass'] ?? null);
    }

    /**
     * docs/02-kernel.mdの「`#[Action]`/`#[Filter]`メソッドの失敗(Containerでの解決の失敗を含む)が、debugでは例外の
     * まま伝わ」ることを、実物のapply_filters()・do_action()で確かめる。debugのKernelはapp/を走査するため、
     * 探索結果ファイルを書かない。ログには何も記録しない。
     */
    public function testFailingHookMethodsAndUnresolvableHandlersThrowInDebug(): void
    {
        $config = $this->pluginConfig('DebugFailures', true);
        $this->writeFailingHook($config);
        $this->writeUnresolvableHook($config);
        $this->registerAppAutoloader($config);
        $this->bootBeforeInit($config);

        $cases = [
            'filter' => [static fn(): mixed => apply_filters('tmswp_integration/failing_filter', 'original', 'extra'), RuntimeException::class, 'hook method failed'],
            'filter without arguments' => [static fn(): mixed => apply_filters('tmswp_integration/failing_filter_none', 'original'), RuntimeException::class, 'hook method failed'],
            'action' => [static function (): void {
                do_action('tmswp_integration/failing_action', 'fired');
            }, RuntimeException::class, 'hook method failed'],
            'unresolvable' => [static fn(): mixed => apply_filters('tmswp_integration/unresolvable_filter', 'original'), ContainerException::class, null],
        ];

        foreach ($cases as $name => [$fire, $exceptionClass, $message]) {
            $thrown = null;

            try {
                $fire();
            } catch (Throwable $exception) {
                $thrown = $exception;
            }

            self::assertNotNull($thrown, "{$exceptionClass} was not thrown for {$name}");
            self::assertSame($exceptionClass, $thrown::class, $name);

            if ($message !== null) {
                self::assertSame($message, $thrown->getMessage(), $name);
            }
        }

        self::assertSame([], $this->logger->records);
    }

    /**
     * docs/02-kernel.mdの「フック発火時にContainer経由で1回だけインスタンス化されて呼ばれる」を、本番相当のbootで
     * 確かめる。ハンドラのコンストラクタは、Containerだけが渡せるKernel(コンストラクタがprivate)を受け取る。
     * bootの後は生成されず、2つのフックを合わせて3回発火しても生成は1回で、どの発火も同じインスタンスが
     * 処理する。そのインスタンスは、そのKernelのContainerが共有するインスタンスで、渡されたKernelはそのKernelである。
     */
    public function testHookMethodHandlerIsInstantiatedOnceThroughTheKernelContainer(): void
    {
        $config = $this->pluginConfig('ContainerHandler', false);
        $namespace = $config->appNamespace;
        $class = $namespace . '\\Hook\\ContainerProbeHook';
        $this->writeFile($config->appDir . '/Hook/ContainerProbeHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hook;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Attribute\\Filter;
use TMSWP\\Core\\Hook\\Hook;
use TMSWP\\Core\\Kernel\\Kernel;

#[AsHook]
final class ContainerProbeHook extends Hook
{
    public function __construct(Kernel \$kernel)
    {
        do_action('tmswp_integration/container_probe_constructed', \$this, \$kernel);
    }

    #[Action('tmswp_integration/container_probe_action')]
    public function handle(): void
    {
        do_action('tmswp_integration/container_probe_handled', \$this);
    }

    #[Filter('tmswp_integration/container_probe_filter')]
    public function filter(string \$value): string
    {
        do_action('tmswp_integration/container_probe_handled', \$this);

        return \$value . '-filtered';
    }
}
PHP);
        (new DiscoveryFile($config))->write(new DiscoveryResult([], [
            new DiscoveredHookMethod($class, 'handle', 'tmswp_integration/container_probe_action', 10, 0, HookMethodKind::Action),
            new DiscoveredHookMethod($class, 'filter', 'tmswp_integration/container_probe_filter', 10, 1, HookMethodKind::Filter),
        ]));
        $this->registerAppAutoloader($config);
        $record = new HookMethodRuntimeProbeRecord();
        add_action('tmswp_integration/container_probe_constructed', static function (object $probe, object $kernel) use ($record): void {
            $record->constructed[] = ['probe' => $probe, 'kernel' => $kernel];
        }, 10, 2);
        add_action('tmswp_integration/container_probe_handled', static function (object $probe) use ($record): void {
            $record->handled[] = $probe;
        });

        $this->bootBeforeInit($config);
        self::assertSame(0, did_action('tmswp_integration/container_probe_constructed'));

        do_action('tmswp_integration/container_probe_action');
        self::assertSame('a-filtered', apply_filters('tmswp_integration/container_probe_filter', 'a'));
        do_action('tmswp_integration/container_probe_action');

        self::assertSame([], $this->logger->records);
        self::assertCount(1, $record->constructed);
        $probe = $record->constructed[0]['probe'];
        self::assertSame(Kernel::get($namespace), $record->constructed[0]['kernel']);
        self::assertCount(3, $record->handled);

        foreach ($record->handled as $handler) {
            self::assertSame($probe, $handler);
        }

        // Containerから取り出しても、新たには生成しない
        self::assertSame($probe, Kernel::get($namespace)->container()->get($class));
        self::assertSame(1, did_action('tmswp_integration/container_probe_constructed'));
    }

    /**
     * 例外を投げる#[Filter](2引数・引数なし)と#[Action](1引数)を持つHookのクラスを書き、そのFQCNを返す。
     *
     * @return non-empty-string
     */
    private function writeFailingHook(KernelConfig $config): string
    {
        $namespace = $config->appNamespace;
        $this->writeFile($config->appDir . '/Hook/FailingHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hook;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Attribute\\Filter;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class FailingHook extends Hook
{
    #[Filter('tmswp_integration/failing_filter')]
    public function failFilter(string \$value, string \$extra): string
    {
        throw new \\RuntimeException('hook method failed');
    }

    #[Filter('tmswp_integration/failing_filter_none')]
    public function failFilterWithoutArguments(): string
    {
        throw new \\RuntimeException('hook method failed');
    }

    #[Action('tmswp_integration/failing_action')]
    public function failAction(string \$value): void
    {
        throw new \\RuntimeException('hook method failed');
    }
}
PHP);

        return $namespace . '\\Hook\\FailingHook';
    }

    /**
     * コンストラクタが既定値の無いstringを取る(Containerが解決できない)#[Filter]のHookのクラスを書き、そのFQCNを返す。
     *
     * @return non-empty-string
     */
    private function writeUnresolvableHook(KernelConfig $config): string
    {
        $namespace = $config->appNamespace;
        $this->writeFile($config->appDir . '/Hook/UnresolvableHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hook;

use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Attribute\\Filter;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class UnresolvableHook extends Hook
{
    public function __construct(private readonly string \$suffix)
    {
    }

    #[Filter('tmswp_integration/unresolvable_filter')]
    public function filter(string \$value): string
    {
        return \$value . \$this->suffix;
    }
}
PHP);

        return $namespace . '\\Hook\\UnresolvableHook';
    }

    /** fixtureのテーマ・プラグインがtestチャネルに登録したRecordingLoggerを返す。*/
    private static function fixtureLogger(Kernel $kernel): RecordingLogger
    {
        $logger = $kernel->logger()->channel('test');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }

    /**
     * プラグイン形態の構成を、一時ディレクトリのプラグインで作る。ログはこのテストのRecordingLoggerに記録する。
     * テーマ形態にしないのは、テーマ切替のフック(他のテストが発火させる)にこのKernelを登録しないためである。
     */
    private function pluginConfig(string $name, bool $debug): KernelConfig
    {
        $root = $this->createTempDirectory('integration-' . strtolower($name));
        self::assertTrue(mkdir($root . '/app'));
        $this->writeFile($root . '/plugin.php', "<?php\n");

        return KernelConfig::plugin($root . '/plugin.php', $root . '/app', 'TmswpIntegration' . $name . uniqid(), $debug, logChannels: $this->recordLogs());
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
}

/** ContainerProbeHookの生成と呼び出しの記録。*/
final class HookMethodRuntimeProbeRecord
{
    /** @var list<array{probe: object, kernel: object}> 生成されたインスタンスと、コンストラクタが受け取ったKernel */
    public array $constructed = [];

    /** @var list<object> フックの発火を処理したインスタンス */
    public array $handled = [];
}
