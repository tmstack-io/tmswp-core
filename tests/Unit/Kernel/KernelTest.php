<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Kernel;

use ArgumentCountError;
use Attribute;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Closure;
use Error;
use LogicException;
use Mockery;
use ParseError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\ComponentAttributeInterface;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Exception\ContainerException;
use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Exception\DuplicateMetaKeyException;
use TMSWP\Core\Exception\EntityDefinitionException;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Kernel\BootInitializerInterface;
use TMSWP\Core\Kernel\ComponentInitializerInterface;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\DiscoveredHookMethod;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\ExtensionInterface;
use TMSWP\Core\Kernel\HookMethodKind;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Kernel\KernelContext;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Meta\MetaSchemaRegistry;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use TMSWP\Testing\MemoryMetaStorage;
use TMSWP\Testing\UnitTestCase;
use TypeError;

final class KernelTest extends UnitTestCase
{
    /** @var list<Closure(string): void> tearDown で外す app/ の autoload */
    private array $autoloaders = [];

    protected function setUp(): void
    {
        parent::setUp();

        // WordPress が wp-includes/version.php と plugin.php で用意するグローバル。boot はこれで版と発火済みのフックを知る
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
        KernelTestRecordingInitializer::$initialized = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->autoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }

        unset($GLOBALS['wp_version'], $GLOBALS['wp_actions']);
        Kernel::reset();
        parent::tearDown();
    }

    public function testThemeBootRegistersLifecycleHooksAndLeavesTemplateIncludeUntouched(): void
    {
        $appDir = $this->createTempDirectory('kernel-app');
        $namespace = 'Example\\Tests\\KernelApp' . uniqid();

        Actions\expectAdded('init')->once()->with(Mockery::type(Closure::class), 1, 0);
        Actions\expectAdded('after_switch_theme')->once()->with(Mockery::type(Closure::class), 10, 0);
        Actions\expectAdded('switch_theme')->once()->with(Mockery::type(Closure::class), 10, 0);
        // Migrator は有効化・admin_init でテーブルを自動適用せず、管理画面の通知だけを登録する(06-model §5)
        Actions\expectAdded('tmswp/activated')->never();
        Actions\expectAdded('admin_init')->never();
        Actions\expectAdded('admin_notices')->once()->with(Mockery::type(Closure::class), 10, 0);
        // テンプレート階層には介入しない(テンプレートの前処理はテンプレートの先頭で明示的に呼ぶ)
        Filters\expectAdded('template_include')->never();

        $kernel = Kernel::boot(KernelConfig::theme($appDir, $namespace, true));

        self::assertSame($kernel, Kernel::get($namespace));
        self::assertSame([], $kernel->components());
    }

    public function testTextdomainLoaderLoadsCoreMoFileForDeterminedLocale(): void
    {
        $appDir = $this->createTempDirectory('kernel-textdomain-app');
        $namespace = 'Example\\Tests\\KernelTextdomainApp' . uniqid();
        $loader = null;

        Actions\expectAdded('init')->once()->with(Mockery::type(Closure::class), 1, 0)
            ->whenHappen(static function (Closure $callback) use (&$loader): void {
                $loader = $callback;
            });

        Kernel::boot(KernelConfig::theme($appDir, $namespace, true));

        self::assertInstanceOf(Closure::class, $loader);

        // ローダーは .mo のパスを渡し、同名の .l10n.php の優先読み込みは WordPress の load_textdomain() が行う
        $moFile = \dirname(__DIR__, 3) . '/languages/tmswp-ja.mo';
        self::assertFileExists($moFile);
        Functions\expect('determine_locale')->once()->withNoArgs()->andReturn('ja');
        Functions\expect('load_textdomain')->once()->with('tmswp', $moFile)->andReturn(true);

        $loader();
    }

    public function testBootLoadsDiscoveryFileWithoutScanningWhenDebugIsDisabled(): void
    {
        $appDir = $this->createTempDirectory('discovery-file-kernel-app');
        $namespace = 'Example\\Tests\\DiscoveryFileKernelApp' . uniqid();
        $fileOnlyClass = $namespace . '\\Hooks\\FileOnlyHook';
        $config = KernelConfig::theme($appDir, $namespace, false);

        // 走査すれば見つかる app/ のクラスと、探索結果ファイルにだけ書いたクラスを用意し、ファイルの方が使われることを示す
        $this->writeFile($appDir . '/Hooks/ScannedHook.php', $this->hookClass($namespace, 'ScannedHook', 'tmswp_scanned_hook'));
        (new DiscoveryFile($config))->write(new DiscoveryResult(
            [],
            [
                new DiscoveredHookMethod($fileOnlyClass, 'handle', 'tmswp_file_only_hook', 7, 1, HookMethodKind::Action),
            ],
        ));

        Filters\expectAdded('tmswp_file_only_hook')->once()->with(Mockery::type(Closure::class), 7, 1);
        Filters\expectAdded('tmswp_scanned_hook')->never();
        Actions\expectAdded('init')->once()->with(Mockery::type(Closure::class), 1, 0);

        Kernel::boot($config);

        self::assertFalse(class_exists($fileOnlyClass, false));
        self::assertFalse(class_exists($namespace . '\\Hooks\\ScannedHook', false));
    }

    public function testBootFailsWhenDiscoveryFileIsMissingAndDebugIsDisabled(): void
    {
        $appDir = $this->createTempDirectory('missing-discovery-file-app');
        $namespace = 'Example\\Tests\\MissingDiscoveryFileApp' . uniqid();
        $config = KernelConfig::theme($appDir, $namespace, false);

        try {
            Kernel::boot($config);
            self::fail('DiscoveryException was not thrown');
        } catch (DiscoveryException $exception) {
            self::assertStringContainsString($config->discoveryFile(), $exception->getMessage());
            self::assertStringContainsString('wp tmswp discover', $exception->getMessage());
        }

        self::assertSame([], Kernel::all());
    }

    public function testDebugBootScansAppDirectoryAndIgnoresDiscoveryFile(): void
    {
        $appDir = $this->createTempDirectory('debug-scan-kernel-app');
        $namespace = 'Example\\Tests\\DebugScanKernelApp' . uniqid();
        $config = KernelConfig::theme($appDir, $namespace, true);
        $this->writeFile($appDir . '/Hooks/ScannedHook.php', $this->hookClass($namespace, 'ScannedHook', 'tmswp_debug_scanned_hook'));
        (new DiscoveryFile($config))->write(new DiscoveryResult(
            [],
            [
                new DiscoveredHookMethod($namespace . '\\Hooks\\FileOnlyHook', 'handle', 'tmswp_debug_file_only_hook', 10, 1, HookMethodKind::Action),
            ],
        ));
        $autoload = $this->registerAppAutoloader($namespace, $appDir);

        Filters\expectAdded('tmswp_debug_scanned_hook')->once()->with(Mockery::type(Closure::class), 10, 1);
        Filters\expectAdded('tmswp_debug_file_only_hook')->never();

        try {
            Kernel::boot($config);
        } finally {
            spl_autoload_unregister($autoload);
        }
    }

    public function testFailedScanLeavesNoRegistrations(): void
    {
        $appDir = $this->createTempDirectory('failed-scan-kernel-app');
        $namespace = 'Example\\Tests\\FailedScanKernelApp' . uniqid();

        // 先に走査される AlphaHook は正しく、後の ZuluHook が誤り(#[AsHook] なしのメソッド属性)
        $this->writeFile($appDir . '/Hooks/AlphaHook.php', $this->hookClass($namespace, 'AlphaHook', 'tmswp_alpha_hook'));
        $this->writeFile($appDir . '/Hooks/ZuluHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\Action;

final class ZuluHook
{
    #[Action('tmswp_zulu_hook')]
    public function handle(): void
    {
    }
}
PHP);
        $autoload = $this->registerAppAutoloader($namespace, $appDir);

        Filters\expectAdded('tmswp_alpha_hook')->never();
        Actions\expectAdded('init')->never();

        try {
            Kernel::boot(KernelConfig::theme($appDir, $namespace, true));
            self::fail('DiscoveryException was not thrown');
        } catch (DiscoveryException $exception) {
            self::assertStringContainsString("{$namespace}\\Hooks\\ZuluHook", $exception->getMessage());
        } finally {
            spl_autoload_unregister($autoload);
        }

        self::assertSame([], Kernel::all());
    }

    public function testBootRejectsKernelSharingDiscoveryFile(): void
    {
        $root = $this->createTempDirectory('shared-discovery-file-root');
        $this->writeFile($root . '/app-a/.keep', '');
        $this->writeFile($root . '/app-b/.keep', '');
        $first = KernelConfig::theme($root . '/app-a', 'Example\\Tests\\SharedDiscoveryA' . uniqid(), false);
        $second = KernelConfig::theme($root . '/app-b', 'Example\\Tests\\SharedDiscoveryB' . uniqid(), false);
        $firstFile = new DiscoveryFile($first);
        $firstFile->write(new DiscoveryResult([], []));
        $contents = file_get_contents($first->discoveryFile());

        Kernel::boot($first);

        try {
            Kernel::boot($second);
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertStringContainsString("share the discovery file {$first->discoveryFile()}", $exception->getMessage());
        }

        // 衝突した Kernel は登録されないため、探索コマンドが同じファイルを2つの Kernel の内容で上書きすることもない
        self::assertSame([$first->appNamespace], array_keys(Kernel::all()));
        self::assertSame($contents, file_get_contents($first->discoveryFile()));
        self::assertTrue($firstFile->isUpToDate(new DiscoveryResult([], [])));
    }

    #[RunInSeparateProcess]
    public function testPluginActivationSandboxRegistersLifecycleHooksWithoutRuntimeScheduling(): void
    {
        \define('WP_SANDBOX_SCRAPING', true);

        $appDir = $this->createTempDirectory('kernel-plugin-activation-app');
        $pluginFile = $this->writeFile($this->createTempDirectory('kernel-plugin-activation-root') . '/plugin.php', "<?php\n");
        $namespace = 'Example\\Tests\\PluginActivationApp' . uniqid();
        $hookFile = $appDir . '/Hook/ActivationHook.php';

        $this->writeFile($hookFile, <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hook;

use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class ActivationHook extends Hook
{
    public function register(): void
    {
    }
}
PHP);

        $autoload = static function (string $class) use ($namespace, $appDir): void {
            $prefix = $namespace . '\\';

            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, \strlen($prefix));
            $file = $appDir . '/' . str_replace('\\', '/', $relative) . '.php';

            if (!is_file($file)) {
                throw new RuntimeException("autoload target file not found: {$file}");
            }

            require $file;
        };

        spl_autoload_register($autoload);

        Functions\expect('TMSWP\Core\Kernel\register_activation_hook')->once()->with(Mockery::type('string'), Mockery::type(Closure::class));
        Functions\expect('TMSWP\Core\Kernel\register_deactivation_hook')->once()->with(Mockery::type('string'), Mockery::type(Closure::class));
        Actions\expectAdded('init')->never();
        // 有効化でテーブルを自動適用しない。有効化のリクエストは通知を表示せずにリダイレクトするため、Migrator の
        // 通知も登録しない
        Actions\expectAdded('tmswp/activated')->never();
        Actions\expectAdded('admin_init')->never();
        Actions\expectAdded('admin_notices')->never();
        Functions\expect('did_action')->never();

        try {
            $kernel = Kernel::boot(KernelConfig::plugin($pluginFile, $appDir, $namespace, true));
        } finally {
            spl_autoload_unregister($autoload);
        }

        self::assertSame($kernel, Kernel::get($namespace));
        self::assertCount(1, $kernel->components());
    }

    public function testSecondBootOfSameNamespaceIsRejected(): void
    {
        $namespace = 'Example\Tests\DoubleBootApp' . uniqid();
        $first = Kernel::boot(KernelConfig::theme($this->createTempDirectory('double-boot-first'), $namespace, true));

        foreach ([true, false] as $debug) {
            try {
                Kernel::boot(KernelConfig::theme($this->createTempDirectory('double-boot-second'), $namespace, $debug));
                self::fail('KernelException was not thrown');
            } catch (KernelException $exception) {
                self::assertSame("TMSWP kernel for namespace '{$namespace}' is already booted", $exception->getMessage());
            }
        }

        self::assertSame([$namespace => $first], Kernel::all());
    }

    /**
     * 先に boot する Kernel と後に boot する Kernel の (appNamespace, ルートからの appDir の相対パス)。
     * 名前空間の{NS}は一意な名前空間に、{NS_LOWER}はそれを小文字にしたものに置き換える。
     *
     * @return iterable<string, array{array{string, string}, array{string, string}, string}>
     */
    public static function conflictingKernels(): iterable
    {
        yield '入れ子の名前空間(親が先)' => [['{NS}', 'first/app'], ['{NS}\Sub', 'second/app'], 'nested app namespaces are forbidden: {SECOND_NS}, {FIRST_NS}'];
        yield '入れ子の名前空間(子が先)' => [['{NS}\Sub', 'first/app'], ['{NS}', 'second/app'], 'nested app namespaces are forbidden: {SECOND_NS}, {FIRST_NS}'];
        // PHPの名前空間は大文字と小文字を区別しないため、大文字と小文字だけが違う名前空間も同じとみなす
        yield '大文字と小文字だけが違う名前空間' => [['{NS}', 'first/app'], ['{NS_LOWER}', 'second/app'], "TMSWP kernel for namespace '{SECOND_NS}' is already booted"];
        yield '大文字と小文字だけが違う入れ子の名前空間' => [['{NS}', 'first/app'], ['{NS_LOWER}\Sub', 'second/app'], 'nested app namespaces are forbidden: {SECOND_NS}, {FIRST_NS}'];
        yield '同一の appDir' => [['{NS}A', 'first/app'], ['{NS}B', 'first/app'], 'shared or nested app directories are forbidden: {SECOND_DIR}, {FIRST_DIR}'];
        yield '入れ子の appDir(親が先)' => [['{NS}A', 'first/app'], ['{NS}B', 'first/app/sub'], 'shared or nested app directories are forbidden: {SECOND_DIR}, {FIRST_DIR}'];
        yield '入れ子の appDir(子が先)' => [['{NS}A', 'first/app/sub'], ['{NS}B', 'first/app'], 'shared or nested app directories are forbidden: {SECOND_DIR}, {FIRST_DIR}'];
    }

    /**
     * @param array{string, string} $first
     * @param array{string, string} $second
     */
    #[DataProvider('conflictingKernels')]
    public function testBootRejectsConflictingKernelWithoutTouchingTheFirstDiscoveryFile(array $first, array $second, string $message): void
    {
        $root = $this->createTempDirectory('conflicting-kernels');
        $namespace = 'Example\Tests\ConflictingApp' . uniqid();
        [$firstConfig, $secondConfig] = array_map(function (array $kernel) use ($root, $namespace): KernelConfig {
            [$appNamespace, $appDir] = $kernel;
            $this->writeFile("{$root}/{$appDir}/.keep", '');

            return KernelConfig::theme("{$root}/{$appDir}", strtr($appNamespace, ['{NS}' => $namespace, '{NS_LOWER}' => strtolower($namespace)]), false);
        }, [$first, $second]);
        (new DiscoveryFile($firstConfig))->write(new DiscoveryResult([], []));
        $contents = file_get_contents($firstConfig->discoveryFile());
        Kernel::boot($firstConfig);

        try {
            Kernel::boot($secondConfig);
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertSame(
                strtr($message, [
                    '{FIRST_NS}' => $firstConfig->appNamespace,
                    '{SECOND_NS}' => $secondConfig->appNamespace,
                    '{FIRST_DIR}' => $firstConfig->appDir,
                    '{SECOND_DIR}' => $secondConfig->appDir,
                ]),
                $exception->getMessage(),
            );
        }

        // 拒否した Kernel は登録されないため、探索コマンドが先の Kernel の探索結果ファイルを上書きすることもない
        self::assertSame([$firstConfig->appNamespace], array_keys(Kernel::all()));
        self::assertSame($contents, file_get_contents($firstConfig->discoveryFile()));
    }

    public function testForClassReturnsTheKernelOwningTheClass(): void
    {
        $base = 'Example\Tests\ForClass' . uniqid();
        // 探索結果ファイルを共有しないよう、それぞれのルートの下に appDir を置く
        $theme = Kernel::boot(KernelConfig::theme($this->createAppDirectory('for-class-theme'), $base . '\App', true));
        $plugin = Kernel::boot(KernelConfig::theme($this->createAppDirectory('for-class-plugin'), $base . '\Shop', true));

        self::assertSame($theme, Kernel::forClass($base . '\App\Post\ProductPost'));
        self::assertSame($plugin, Kernel::forClass($base . '\Shop\Hook\CartHook'));
    }

    /** @return iterable<string, array{string}> */
    public static function classesOutsideKernels(): iterable
    {
        yield '名前が Kernel の名前空間で始まる別の名前空間' => ['{BASE}\AppX\Foo'];
        yield '親の名前空間' => ['{BASE}\Foo'];
        yield '無関係の名前空間' => ['Example\Unrelated\Foo'];
    }

    #[DataProvider('classesOutsideKernels')]
    public function testForClassRejectsClassOutsideBootedKernels(string $class): void
    {
        $base = 'Example\Tests\ForClassMissing' . uniqid();
        Kernel::boot(KernelConfig::theme($this->createTempDirectory('for-class-missing'), $base . '\App', true));
        $class = str_replace('{BASE}', $base, $class);

        $this->expectException(KernelException::class);
        $this->expectExceptionMessage("class '{$class}' does not belong to any booted TMSWP application");

        Kernel::forClass($class);
    }

    public function testResetAllowsBootingSameNamespaceAgain(): void
    {
        $config = KernelConfig::theme($this->createTempDirectory('reset-app'), 'Example\Tests\ResetApp' . uniqid(), true);
        $first = Kernel::boot($config);

        Kernel::reset();
        self::assertSame([], Kernel::all());

        $second = Kernel::boot($config);

        self::assertNotSame($first, $second);
        self::assertSame([$config->appNamespace => $second], Kernel::all());
    }

    public function testBootFromExtensionRegisterIsRejected(): void
    {
        $namespace = 'Example\Tests\ReentrantExtension' . uniqid();
        $inner = KernelConfig::theme($this->createAppDirectory('reentrant-extension-inner'), $namespace . '\Sub', true);
        $outer = KernelConfig::theme($this->createAppDirectory('reentrant-extension-outer'), $namespace, true, extensions: [new KernelTestBootingExtension($inner)]);

        // 外側の Kernel はまだレジストリに無いため、再入を禁止しなければ入れ子の名前空間の検査をすり抜ける
        $this->assertReentrantBootIsRejected($outer, $inner);
    }

    public function testBootOfParentNamespaceFromLogChannelsIsRejected(): void
    {
        $namespace = 'Example\Tests\ReentrantParent' . uniqid();
        $inner = KernelConfig::theme($this->createAppDirectory('reentrant-parent-inner'), $namespace, true);
        $outer = KernelConfig::theme($this->createAppDirectory('reentrant-parent-outer'), $namespace . '\Sub', true, logChannels: self::bootingLogChannels($inner));

        $this->assertReentrantBootIsRejected($outer, $inner);
    }

    public function testBootOfSameNamespaceFromLogChannelsIsRejected(): void
    {
        $namespace = 'Example\Tests\ReentrantSame' . uniqid();
        $inner = KernelConfig::theme($this->createAppDirectory('reentrant-same-inner'), $namespace, true);
        $outer = KernelConfig::theme($this->createAppDirectory('reentrant-same-outer'), $namespace, true, logChannels: self::bootingLogChannels($inner));

        $this->assertReentrantBootIsRejected($outer, $inner);
    }

    public function testBootFromProductionCatchUpInitializerIsRejected(): void
    {
        $namespace = 'Example\Tests\ReentrantCatchUp' . uniqid();
        $outer = KernelConfig::theme($this->createAppDirectory('reentrant-catch-up-outer'), $namespace, false, logChannels: self::recordTo(new KernelTestLogger()));
        (new DiscoveryFile($outer))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\Booting', KernelTestCatchUpAttribute::class, ['hook' => 'init', 'priority' => 10, 'initializer' => KernelTestBootingInitializer::class]),
        ], []));
        KernelTestBootingInitializer::$config = KernelConfig::theme($this->createAppDirectory('reentrant-catch-up-inner'), $namespace . '\Sub', true);
        // init は発火済み。init 10 の初期化は、外側の Kernel をレジストリに登録した後のキャッチアップで動く
        $GLOBALS['wp_actions'] = ['init' => 1];

        $this->assertReentrantBootIsRejected($outer, KernelTestBootingInitializer::$config);
    }

    #[RunInSeparateProcess]
    public function testPluginActivationSandboxBootDoesNotBlockLaterBoot(): void
    {
        \define('WP_SANDBOX_SCRAPING', true);

        $pluginFile = $this->writeFile($this->createTempDirectory('sandbox-reentry-root') . '/plugin.php', "<?php\n");
        Functions\when('TMSWP\Core\Kernel\register_activation_hook')->justReturn();
        Functions\when('TMSWP\Core\Kernel\register_deactivation_hook')->justReturn();
        $sandboxed = Kernel::boot(KernelConfig::plugin($pluginFile, $this->createAppDirectory('sandbox-reentry-plugin'), 'Example\Tests\SandboxReentryPlugin' . uniqid(), true));

        // サンドボックスの boot は途中で return するが、boot の進行中の印は外れている
        $theme = Kernel::boot(KernelConfig::theme($this->createAppDirectory('sandbox-reentry-theme'), 'Example\Tests\SandboxReentryTheme' . uniqid(), true));

        self::assertSame([$sandboxed->config()->appNamespace => $sandboxed, $theme->config()->appNamespace => $theme], Kernel::all());
    }

    /**
     * 探索結果ファイルの属性を復元できない場合、本番(debug=false)でも boot が DiscoveryException になる。
     * 最後の要素は、属性クラスとして autoload で読み込ませるファイルの内容(null なら読み込ませない)。属性クラス・
     * メッセージ・ファイルの内容の {NS} は一意な名前空間に置き換える。
     *
     * @return iterable<string, array{non-empty-string, array<array-key, mixed>, string, class-string|null, string|null}>
     */
    public static function unrestorableAttributes(): iterable
    {
        yield '属性クラスが無い' => ['Example\Tests\MissingAttribute', [], 'component attribute class not found: Example\Tests\MissingAttribute', null, null];
        yield 'ComponentAttributeInterface を実装しない' => [KernelTestHookProbe::class, [], "attribute class '" . KernelTestHookProbe::class . "' does not implement ComponentAttributeInterface", null, null];
        yield '必須の引数が無い' => [KernelTestCatchUpAttribute::class, ['hook' => 'init'], 'failed to restore component attribute ' . KernelTestCatchUpAttribute::class, ArgumentCountError::class, null];
        yield '引数の型が違う' => [KernelTestCatchUpAttribute::class, ['hook' => 'init', 'priority' => 'ten'], 'failed to restore component attribute ' . KernelTestCatchUpAttribute::class, TypeError::class, null];
        yield '属性クラスの autoload で Error(未定義の親クラス)' => ['{NS}\BrokenAttribute', [], 'failed to restore component attribute {NS}\BrokenAttribute', Error::class, "<?php\n\nnamespace {NS};\n\nfinal class BrokenAttribute extends MissingParent\n{\n}\n"];
        yield '属性クラスの autoload で ParseError(構文の誤り)' => ['{NS}\BrokenAttribute', [], 'failed to restore component attribute {NS}\BrokenAttribute', ParseError::class, "<?php\n\nnamespace {NS};\n\nfinal class BrokenAttribute {\n"];
    }

    /**
     * @param non-empty-string $attributeClass
     * @param array<array-key, mixed> $arguments
     * @param class-string|null $previous
     */
    #[DataProvider('unrestorableAttributes')]
    public function testUnrestorableAttributeFailsBootInProduction(string $attributeClass, array $arguments, string $message, ?string $previous, ?string $attributeSource): void
    {
        $config = KernelConfig::theme($this->createTempDirectory('unrestorable-attribute-app'), 'Example\Tests\UnrestorableAttributeApp' . uniqid(), false);
        $attributeNamespace = 'Example\Tests\UnrestorableAttribute' . uniqid();
        $attributeClass = str_replace('{NS}', $attributeNamespace, $attributeClass);
        $message = str_replace('{NS}', $attributeNamespace, $message);

        if ($attributeSource !== null) {
            // 属性クラスのファイルは app/ の外に置き、通常の autoload で読み込ませる
            $attributeDir = $this->createTempDirectory('unrestorable-attribute-class');
            $this->writeFile($attributeDir . '/BrokenAttribute.php', str_replace('{NS}', $attributeNamespace, $attributeSource));
            $this->autoloaders[] = $this->registerAppAutoloader($attributeNamespace, $attributeDir);
        }

        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($config->appNamespace . '\Component', $attributeClass, $arguments),
        ], []));

        try {
            Kernel::boot($config);
            self::fail('DiscoveryException was not thrown');
        } catch (DiscoveryException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
            self::assertStringEndsWith("; regenerate the discovery file with 'wp tmswp discover' and deploy it together with the code", $exception->getMessage());
            self::assertSame($previous, $exception->getPrevious() === null ? null : $exception->getPrevious()::class);
        }

        self::assertSame([], KernelTestRecordingInitializer::$initialized);
        self::assertSame([], Kernel::all());
    }

    /**
     * init の前の本番の boot で、エンティティのメタスキーマは boot の中で MetaSchemaRegistry に登録され、WordPress への
     * 登録(register_post_meta())は init 20 まで行われない。acf-bridge が init 6 で外部管理のキーを申告する時点では
     * スキーマが揃っているため、#[MetaSchema] と同じキーの申告はその場で DuplicateMetaKeyException になる。
     */
    public function testBootRegistersEntityMetaSchemasWithoutRegisteringThemWithWordPress(): void
    {
        $config = KernelConfig::theme($this->createTempDirectory('boot-meta-app'), 'Example\Tests\BootMetaApp' . uniqid(), false);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent(KernelTestMetaPost::class, AsPostType::class, []),
        ], []));
        Functions\expect('register_post_meta')->never();

        $kernel = Kernel::boot($config);
        $registry = $kernel->container()->get(MetaSchemaRegistry::class);

        self::assertSame(ObjectType::Post, $registry->objectTypeOf(KernelTestMetaPost::class));
        self::assertSame(['tmswp_kernel_note'], array_map(
            static fn(array $entry): string => $entry['schema']->key,
            array_values(array_filter($registry->schemas(), static fn(array $entry): bool => $entry['subtype'] === 'tmswp_kernel_meta')),
        ));

        $this->expectException(DuplicateMetaKeyException::class);
        $this->expectExceptionMessage("externally managed meta key 'tmswp_kernel_note' for post:tmswp_kernel_meta is already declared by " . KernelTestMetaPost::class);

        $registry->markExternallyManaged(ObjectType::Post, 'tmswp_kernel_meta', 'tmswp_kernel_note', MemoryMetaStorage::class, "field group 'group_kernel'");
    }

    public function testDeferOutsideCatchUpIsAddAction(): void
    {
        $kernel = Kernel::boot(KernelConfig::theme($this->createAppDirectory('defer-app'), 'Example\Tests\DeferApp' . uniqid(), true));
        $callback = static function (): void {};
        // キャッチアップ中でなければ、発火済みのフックへの予約も add_action() と同じ
        $GLOBALS['wp_actions'] = ['plugins_loaded' => 1];
        Actions\expectAdded('init')->once()->with($callback, 11, 0);
        Actions\expectAdded('plugins_loaded')->once()->with($callback, 5, 0);

        $kernel->defer('init', 11, $callback);
        $kernel->defer('plugins_loaded', 5, $callback);
    }

    /**
     * 本番の遅延 boot のキャッチアップの中で発火済みのフックへ予約したものは、残りのグループの (最初に発火した順,
     * priority) の位置(同じ位置のグループの後)で実行され、遅延 boot のログは記録しない。未発火のフックへの予約は
     * add_action() する。
     */
    public function testDeferDuringCatchUpRunsAtFiredOrderAndPriorityAmongRemainingGroups(): void
    {
        $logger = new KernelTestLogger();
        $config = KernelConfig::theme($this->createTempDirectory('defer-catch-up-app'), 'Example\Tests\DeferCatchUpApp' . uniqid(), false, logChannels: self::recordTo($logger));
        $namespace = $config->appNamespace;
        $attribute = KernelTestCatchUpAttribute::class;
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\A', $attribute, ['hook' => 'init', 'priority' => 9, 'initializer' => KernelTestDeferringInitializer::class]),
            new DiscoveredComponent($namespace . '\B', $attribute, ['hook' => 'init', 'priority' => 10]),
            new DiscoveredComponent($namespace . '\C', $attribute, ['hook' => 'init', 'priority' => 12]),
        ], []));
        $GLOBALS['wp_actions'] = ['plugins_loaded' => 1, 'init' => 1];
        Actions\expectAdded('wp_loaded')->once()->with(Mockery::type(Closure::class), 10, 0);

        Kernel::boot($config);

        self::assertSame(
            [
                $namespace . '\A',
                // plugins_loaded は init より先に発火したため、残りのグループの先頭に入る
                'deferred plugins_loaded 10',
                $namespace . '\B',
                // 同じ (init, 10) のグループ B の後
                'deferred init 10',
                'deferred init 11',
                $namespace . '\C',
            ],
            KernelTestRecordingInitializer::$initialized,
        );
        // 発火済みのグループ(init 9・10・12 と MetaSchema の init 20)ごとに1回
        self::assertCount(4, $logger->records);
    }

    /**
     * 本番の遅延bootで、拡張のregister()がdefer()で発火済みのフックへ予約したものは、キャッチアップの列の
     * (最初に発火した順, priority)の位置で実行される(init 6はinit 10のコンポーネントの初期化と、MetaSchemaの
     * WordPressへの登録(init 20)より前)。未発火のフックへの予約はadd_action()する。
     */
    public function testDeferFromExtensionRegisterIsCaughtUpInLateBoot(): void
    {
        $logger = new KernelTestLogger();
        $config = KernelConfig::theme(
            $this->createTempDirectory('defer-extension-app'),
            'Example\Tests\DeferExtensionApp' . uniqid(),
            false,
            [new KernelTestDeferringExtension()],
            self::recordTo($logger),
        );
        $namespace = $config->appNamespace;
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\A', KernelTestCatchUpAttribute::class, ['hook' => 'init', 'priority' => 10]),
            new DiscoveredComponent($namespace . '\C', KernelTestCatchUpAttribute::class, ['hook' => 'init', 'priority' => 30]),
        ], []));
        $GLOBALS['wp_actions'] = ['plugins_loaded' => 1, 'init' => 1];
        Functions\when('register_post_meta')->justReturn(true);
        Actions\expectAdded('wp_loaded')->once()->with(Mockery::type(Closure::class), 10, 0);

        Kernel::boot($config);

        self::assertSame(
            ['extension plugins_loaded 5', 'extension init 6', $namespace . '\A', 'extension init 25', $namespace . '\C'],
            KernelTestRecordingInitializer::$initialized,
        );
        // 遅延bootのログは発火済みのグループ(init 10・20・30)ごとに1回で、拡張の予約は記録しない
        self::assertCount(3, $logger->records);
    }

    /**
     * 本番の遅延 boot では、メタスキーマの MetaSchemaRegistry への登録(boot 時の初期化)がキャッチアップより先に動き、
     * キャッチアップの中の MetaSchema の WordPress への登録(init 20)は init 9・10 のコンポーネントの初期化より後、
     * init 30 の初期化より前に動く。boot 時に登録したエンティティのメタを register_post_meta() する位置で順序を確かめる。
     */
    public function testLateBootCatchUpRunsMetaSchemaRegistrationAfterInitNineAndTen(): void
    {
        $config = KernelConfig::theme($this->createTempDirectory('catch-up-meta-app'), 'Example\Tests\CatchUpMetaApp' . uniqid(), false, logChannels: self::recordTo(new KernelTestLogger()));
        $namespace = $config->appNamespace;
        $attribute = KernelTestCatchUpAttribute::class;
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\A', $attribute, ['hook' => 'init', 'priority' => 30]),
            new DiscoveredComponent($namespace . '\B', $attribute, ['hook' => 'init', 'priority' => 10]),
            new DiscoveredComponent($namespace . '\C', $attribute, ['hook' => 'init', 'priority' => 9, 'initializer' => KernelTestMetaEntityInitializer::class]),
        ], []));
        $GLOBALS['wp_actions'] = ['plugins_loaded' => 1, 'init' => 1];
        Functions\when('register_post_meta')->alias(static function (string $postType, string $key): bool {
            KernelTestRecordingInitializer::$initialized[] = "register_post_meta({$postType}, {$key})";

            return true;
        });

        Kernel::boot($config);

        self::assertSame(
            ['boot ' . $namespace . '\C', $namespace . '\C', $namespace . '\B', 'register_post_meta(tmswp_kernel_meta, tmswp_kernel_note)', $namespace . '\A'],
            KernelTestRecordingInitializer::$initialized,
        );
    }

    public function testUnresolvableHookHandlerThrowsInDebug(): void
    {
        $appDir = $this->createTempDirectory('unresolvable-debug-hook-app');
        $namespace = 'Example\Tests\UnresolvableDebugHookApp' . uniqid();
        $this->writeFile($appDir . '/Hooks/NeedsValueHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\Filter;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class NeedsValueHook extends Hook
{
    // Container が autowire で解決できない組み込み型の引数
    public function __construct(private string \$value)
    {
    }

    #[Filter('tmswp_unresolvable_debug_filter')]
    public function filter(string \$value): string
    {
        return \$this->value . \$value;
    }
}
PHP);
        $logger = new KernelTestLogger();
        $this->autoloaders[] = $this->registerAppAutoloader($namespace, $appDir);
        $filter = $this->bootAndCaptureHookCallback(KernelConfig::theme($appDir, $namespace, true, logChannels: self::recordTo($logger)), 'tmswp_unresolvable_debug_filter', 1);

        try {
            $filter('original');
            self::fail('ContainerException was not thrown');
        } catch (ContainerException $exception) {
            self::assertStringContainsString("{$namespace}\\Hooks\\NeedsValueHook", $exception->getMessage());
        }

        self::assertSame([], $logger->records);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedWordPressVersions(): iterable
    {
        yield '6.9' => ['6.9'];
        yield '6.9.4' => ['6.9.4'];
        yield '6.9 の RC' => ['6.9-RC1'];
        yield '6.0' => ['6.0'];
    }

    #[DataProvider('unsupportedWordPressVersions')]
    public function testBootRejectsWordPressOlderThanSevenRegardlessOfDebug(string $version): void
    {
        $GLOBALS['wp_version'] = $version;

        foreach ([true, false] as $debug) {
            $config = KernelConfig::theme(
                $this->createTempDirectory('old-wp-app'),
                'Example\Tests\OldWordPressApp' . uniqid(),
                $debug,
            );

            try {
                Kernel::boot($config);
                self::fail('KernelException was not thrown');
            } catch (KernelException $exception) {
                self::assertSame("TMSWP requires WordPress 7.0 or later (running {$version})", $exception->getMessage());
            }
        }

        self::assertSame([], Kernel::all());
    }

    /** @return iterable<string, array{string}> */
    public static function supportedWordPressVersions(): iterable
    {
        yield '7.0' => ['7.0'];
        // WordPress 自身の「Requires at least」の判定と同じく、接尾辞を除いて比べる
        yield '7.0 の RC' => ['7.0-RC1'];
        yield '7.0 のベータ' => ['7.0-beta2'];
        yield '7.0 の開発版' => ['7.0-alpha-60000-src'];
        yield '7.0.1' => ['7.0.1'];
        yield '7.1' => ['7.1'];
        yield '8.0' => ['8.0'];
    }

    #[DataProvider('supportedWordPressVersions')]
    public function testBootAcceptsWordPressSevenAndLaterIncludingPrereleases(string $version): void
    {
        $GLOBALS['wp_version'] = $version;
        $namespace = 'Example\Tests\SupportedWordPressApp' . uniqid();

        Kernel::boot(KernelConfig::theme($this->createTempDirectory('supported-wp-app'), $namespace, true));

        self::assertArrayHasKey($namespace, Kernel::all());
    }

    public function testBootFailsWhenWordPressVersionIsUnknown(): void
    {
        unset($GLOBALS['wp_version']);

        $this->expectException(KernelException::class);
        $this->expectExceptionMessage('cannot determine the WordPress version');

        Kernel::boot(KernelConfig::theme($this->createTempDirectory('unknown-wp-app'), 'Example\Tests\UnknownWordPressApp' . uniqid(), true));
    }

    public function testThemeActivationAndDeactivationOnlyDeleteRewriteRules(): void
    {
        $namespace = 'Example\Tests\ThemeLifecycleApp' . uniqid();
        $activate = null;
        $deactivate = null;

        Actions\expectAdded('after_switch_theme')->once()->with(Mockery::type(Closure::class), 10, 0)
            ->whenHappen(static function (Closure $callback) use (&$activate): void {
                $activate = $callback;
            });
        Actions\expectAdded('switch_theme')->once()->with(Mockery::type(Closure::class), 10, 0)
            ->whenHappen(static function (Closure $callback) use (&$deactivate): void {
                $deactivate = $callback;
            });

        $kernel = Kernel::boot(KernelConfig::theme($this->createTempDirectory('theme-lifecycle-app'), $namespace, true));

        $this->assertLifecycleCallbacksOnlyDeleteRewriteRules($kernel, $activate, $deactivate);
    }

    public function testPluginActivationAndDeactivationOnlyDeleteRewriteRules(): void
    {
        $pluginFile = $this->writeFile($this->createTempDirectory('plugin-lifecycle-root') . '/plugin.php', "<?php\n");
        $namespace = 'Example\Tests\PluginLifecycleApp' . uniqid();
        $activate = null;
        $deactivate = null;

        Functions\expect('TMSWP\Core\Kernel\register_activation_hook')->once()
            ->andReturnUsing(static function (string $file, Closure $callback) use (&$activate): void {
                $activate = $callback;
            });
        Functions\expect('TMSWP\Core\Kernel\register_deactivation_hook')->once()
            ->andReturnUsing(static function (string $file, Closure $callback) use (&$deactivate): void {
                $deactivate = $callback;
            });

        $kernel = Kernel::boot(KernelConfig::plugin($pluginFile, $this->createTempDirectory('plugin-lifecycle-app'), $namespace, true));

        $this->assertLifecycleCallbacksOnlyDeleteRewriteRules($kernel, $activate, $deactivate);
    }

    public function testBootRegistersNoPerRequestRewriteCheck(): void
    {
        $priorities = [];

        // 有効化のフラグを毎リクエストの init で確かめる処理は無い(TMSWP が登録する init は翻訳の読み込みと MetaSchema だけ)
        Actions\expectAdded('init')->twice()
            ->whenHappen(static function (Closure $callback, int $priority) use (&$priorities): void {
                $priorities[] = $priority;
            });
        Functions\expect('get_option')->never();
        Functions\expect('add_option')->never();

        Kernel::boot(KernelConfig::theme($this->createTempDirectory('no-rewrite-check-app'), 'Example\Tests\NoRewriteCheckApp' . uniqid(), true));

        sort($priorities);
        self::assertSame([1, 20], $priorities);
    }

    public function testHookMethodIsCalledWithWordPressWeakTypeCoercion(): void
    {
        $filter = $this->bootWithFileOnlyHookMethod('tmswp_coercion_filter', 'addOne', 1, HookMethodKind::Filter, 1);

        // strict_types のファイルから直接呼ぶと TypeError になる数値文字列を、WordPress と同じく int に変換して渡す
        self::assertSame(42, $filter('41'));
    }

    public function testFailingHookMethodIsLoggedAndSkippedInProduction(): void
    {
        $logger = new KernelTestLogger();
        $filter = $this->bootWithFileOnlyHookMethod('tmswp_failing_filter', 'fail', 2, HookMethodKind::Filter, 2, $logger);

        // filter は第1引数をそのまま返し、値を変えない
        self::assertSame('original', $filter('original', 'extra'));

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('tmswp.kernel: hook method {class}::{method} failed on {hook}', $logger->records[0]['message']);
        self::assertSame(KernelTestHookProbe::class, $logger->records[0]['context']['class'] ?? null);
        self::assertSame('fail', $logger->records[0]['context']['method'] ?? null);
        // 文脈には例外のクラス名と投げられた場所(previous はクラス名)だけを入れ、例外のオブジェクト・メッセージ・
        // トレースは入れない(メッセージには利用者の入力値が入りうる)
        self::assertSame([
            'class' => KernelTestHookProbe::class,
            'method' => 'fail',
            'hook' => 'tmswp_failing_filter',
            'exceptionClass' => RuntimeException::class,
            'exceptionAt' => __FILE__ . ':' . KernelTestHookProbe::$failLine,
            'previousClasses' => [LogicException::class],
        ], $logger->records[0]['context']);
    }

    public function testFailingActionWithoutArgumentsIsSkippedInProduction(): void
    {
        $logger = new KernelTestLogger();
        // Action は宣言した引数の数(0)で登録する。戻り値は WordPress が使わない
        $action = $this->bootWithFileOnlyHookMethod('tmswp_failing_action', 'failWithoutArguments', 0, HookMethodKind::Action, 0, $logger);

        self::assertNull($action());
        self::assertCount(1, $logger->records);
    }

    public function testFailingFilterWithoutArgumentsReturnsOriginalValueInProduction(): void
    {
        $logger = new KernelTestLogger();
        // 引数を宣言しない Filter も、元の値を返せるよう第1引数を受け取って登録する
        $filter = $this->bootWithFileOnlyHookMethod('tmswp_failing_zero_filter', 'failWithoutArguments', 0, HookMethodKind::Filter, 1, $logger);

        self::assertSame('original', $filter('original'));
        self::assertCount(1, $logger->records);
    }

    public function testFilterWithoutArgumentsReturnsHandlerValueAndReceivesNoArguments(): void
    {
        $filter = $this->bootWithFileOnlyHookMethod('tmswp_zero_filter', 'countArguments', 0, HookMethodKind::Filter, 1);

        // 登録は第1引数を受け取るが、メソッドには宣言した引数の数(0)に切り詰めて渡す
        self::assertSame(0, $filter('original'));
    }

    public function testFailingHookMethodThrowsInDebug(): void
    {
        $logger = new KernelTestLogger();
        $appDir = $this->createTempDirectory('failing-debug-hook-app');
        $namespace = 'Example\Tests\FailingDebugHookApp' . uniqid();
        $this->writeFile($appDir . '/Hooks/FailingHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\Filter;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class FailingHook extends Hook
{
    #[Filter('tmswp_failing_debug_filter')]
    public function fail(string \$value): string
    {
        throw new \\RuntimeException('hook method failed in debug');
    }
}
PHP);
        $autoload = $this->registerAppAutoloader($namespace, $appDir);

        try {
            $filter = $this->bootAndCaptureHookCallback(KernelConfig::theme($appDir, $namespace, true, logChannels: self::recordTo($logger)), 'tmswp_failing_debug_filter', 1);

            try {
                $filter('original');
                self::fail('RuntimeException was not thrown');
            } catch (RuntimeException $exception) {
                self::assertSame('hook method failed in debug', $exception->getMessage());
            }
        } finally {
            spl_autoload_unregister($autoload);
        }

        self::assertSame([], $logger->records);
    }

    public function testUnresolvableHookHandlerIsLoggedAndSkippedInProduction(): void
    {
        $logger = new KernelTestLogger();
        $appDir = $this->createTempDirectory('stale-hook-app');
        $namespace = 'Example\Tests\StaleHookApp' . uniqid();
        $config = KernelConfig::theme($appDir, $namespace, false, logChannels: self::recordTo($logger));

        // 探索結果ファイルが古く、削除済みのクラスを指している
        (new DiscoveryFile($config))->write(new DiscoveryResult([], [
            new DiscoveredHookMethod($namespace . '\Hook\RemovedHook', 'handle', 'tmswp_stale_filter', 10, 1, HookMethodKind::Filter),
        ]));
        $filter = $this->bootAndCaptureHookCallback($config, 'tmswp_stale_filter', 1);

        self::assertSame('value', $filter('value'));
        self::assertCount(1, $logger->records);
    }

    public function testLateBootCatchesUpFiredHooksInWordPressFiringOrder(): void
    {
        $logger = new KernelTestLogger();
        $appDir = $this->createTempDirectory('catch-up-app');
        $namespace = 'Example\Tests\CatchUpApp' . uniqid();
        $config = KernelConfig::theme($appDir, $namespace, false, logChannels: self::recordTo($logger));
        $attribute = KernelTestCatchUpAttribute::class;

        // コンポーネントは FQCN の順に並ぶ。フック名の辞書順(init < plugins_loaded)とも priority の順とも違う順にしておく
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\A', $attribute, ['hook' => 'init', 'priority' => 10]),
            new DiscoveredComponent($namespace . '\B', $attribute, ['hook' => 'plugins_loaded', 'priority' => 5]),
            new DiscoveredComponent($namespace . '\C', $attribute, ['hook' => 'wp_loaded', 'priority' => 10]),
            new DiscoveredComponent($namespace . '\D', $attribute, ['hook' => 'init', 'priority' => 9]),
            new DiscoveredComponent($namespace . '\E', $attribute, ['hook' => 'plugins_loaded', 'priority' => 0]),
        ], []));

        // WordPress の読み込みどおりの発火順。値は発火回数で、順序には関係しない
        $GLOBALS['wp_actions'] = [
            'muplugins_loaded' => 1,
            'plugins_loaded' => 1,
            'setup_theme' => 1,
            'after_setup_theme' => 1,
            'init' => 3,
        ];

        // 未発火のフックは従来どおり add_action する
        Actions\expectAdded('wp_loaded')->once()->with(Mockery::type(Closure::class), 10, 0);

        Kernel::boot($config);

        self::assertSame(
            [$namespace . '\E', $namespace . '\B', $namespace . '\D', $namespace . '\A'],
            KernelTestRecordingInitializer::$initialized,
        );
        self::assertSame(
            [
                "Kernel::boot() was called after the 'plugins_loaded' hook had fired. Boot from the top level of functions.php or the plugin main file.",
                "Kernel::boot() was called after the 'plugins_loaded' hook had fired. Boot from the top level of functions.php or the plugin main file.",
                "Kernel::boot() was called after the 'init' hook had fired. Boot from the top level of functions.php or the plugin main file.",
                "Kernel::boot() was called after the 'init' hook had fired. Boot from the top level of functions.php or the plugin main file.",
                // MetaSchema の登録(init 20)も同じ規則で、init のコンポーネントの後に動く
                "Kernel::boot() was called after the 'init' hook had fired. Boot from the top level of functions.php or the plugin main file.",
            ],
            array_column($logger->records, 'message'),
        );
    }

    public function testLateBootInDebugThrowsForEarliestFiredHookWithoutRegisteringOrRunningAnything(): void
    {
        $appDir = $this->createTempDirectory('late-debug-app');
        $namespace = 'Example\Tests\LateDebugApp' . uniqid();
        $attribute = KernelTestCatchUpAttribute::class;
        $this->writeFile($appDir . '/Late/A.php', $this->catchUpComponentClass($namespace, 'A', 'init', 10));
        $this->writeFile($appDir . '/Late/B.php', $this->catchUpComponentClass($namespace, 'B', 'plugins_loaded', 5));
        $this->writeFile($appDir . '/Hooks/LateHook.php', $this->hookClass($namespace, 'LateHook', 'tmswp_late_debug_hook'));
        $autoload = $this->registerAppAutoloader($namespace, $appDir);
        $GLOBALS['wp_actions'] = ['plugins_loaded' => 1, 'init' => 1];

        // 遅延 boot の検査はどのフックの登録よりも先で、フックメソッドもグループも登録されない
        Filters\expectAdded('tmswp_late_debug_hook')->never();
        Actions\expectAdded('init')->never();
        Actions\expectAdded('plugins_loaded')->never();

        try {
            Kernel::boot(KernelConfig::theme($appDir, $namespace, true));
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertStringContainsString("after the 'plugins_loaded' hook had fired", $exception->getMessage());
        } finally {
            spl_autoload_unregister($autoload);
        }

        self::assertSame([], KernelTestRecordingInitializer::$initialized);
        self::assertSame([], Kernel::all());
        self::assertTrue(class_exists($attribute));
    }

    public function testInvalidPostTypeComponentFailsBootInProduction(): void
    {
        $appDir = $this->createTempDirectory('invalid-post-type-app');
        $namespace = 'Example\Tests\InvalidPostTypeApp' . uniqid();
        $config = KernelConfig::theme($appDir, $namespace, false);
        // PostType を継承しないクラスに #[AsPostType] が付いている(探索結果ファイルが古い等の設定の誤り)。
        // メタスキーマの登録(boot 時の初期化)で検出し、init を待たずに boot が失敗する
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent(KernelTestHookProbe::class, AsPostType::class, []),
        ], []));

        try {
            Kernel::boot($config);
            self::fail('DiscoveryException was not thrown');
        } catch (DiscoveryException $exception) {
            self::assertSame('post type component must extend PostType: ' . KernelTestHookProbe::class, $exception->getMessage());
        }

        self::assertSame([], Kernel::all());
    }

    public function testInitializerWithoutInterfaceThrowsInProduction(): void
    {
        $appDir = $this->createTempDirectory('invalid-initializer-app');
        $namespace = 'Example\Tests\InvalidInitializerApp' . uniqid();
        $config = KernelConfig::theme($appDir, $namespace, false);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\Component', KernelTestCatchUpAttribute::class, [
                'hook' => 'tmswp_invalid_initializer',
                'priority' => 10,
                'initializer' => KernelTestHookProbe::class,
            ]),
        ], []));
        $runner = null;
        Actions\expectAdded('tmswp_invalid_initializer')->once()->with(Mockery::type(Closure::class), 10, 0)
            ->whenHappen(static function (Closure $callback) use (&$runner): void {
                $runner = $callback;
            });

        Kernel::boot($config);

        self::assertInstanceOf(Closure::class, $runner);
        $this->expectException(DiscoveryException::class);
        $this->expectExceptionMessage("initializer '" . KernelTestHookProbe::class . "' must implement ComponentInitializerInterface");

        $runner();
    }

    public function testFailingCatchUpInitializationFailsBootInProduction(): void
    {
        $logger = new KernelTestLogger();
        $appDir = $this->createTempDirectory('failing-catch-up-app');
        $namespace = 'Example\Tests\FailingCatchUpApp' . uniqid();
        $config = KernelConfig::theme($appDir, $namespace, false, logChannels: self::recordTo($logger));
        // アダプタ先の builtin の投稿タイプが無い(register() が投げる設定の誤り)
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent(KernelTestMissingBuiltinPost::class, AsPostType::class, []),
        ], []));
        $GLOBALS['wp_actions'] = ['init' => 1];
        Functions\when('post_type_exists')->justReturn(false);

        try {
            Kernel::boot($config);
            self::fail('EntityDefinitionException was not thrown');
        } catch (EntityDefinitionException $exception) {
            self::assertSame('builtin post type does not exist: tmswp_kernel_missing', $exception->getMessage());
        }

        self::assertSame([], Kernel::all());
        // 失敗は本番でもログに記録して隠さない(記録は遅延 boot の1件だけ)
        self::assertSame(["Kernel::boot() was called after the 'init' hook had fired. Boot from the top level of functions.php or the plugin main file."], array_column($logger->records, 'message'));
    }

    public function testProductionCatchUpRegistersKernelSoThatAppCodeCanLogToIt(): void
    {
        $logger = new KernelTestLogger();
        $root = $this->createTempDirectory('catch-up-log-root');
        $appDir = $root . '/app';
        $namespace = 'Example\Tests\CatchUpLogApp' . uniqid();
        // register() を上書きする #[AsHook] は、テーマ形態では after_setup_theme(0)で初期化される
        $this->writeFile($appDir . '/Hook/LateLogHook.php', <<<PHP
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
        Log::error('logged during catch-up');
    }
}
PHP);
        $config = KernelConfig::theme($appDir, $namespace, false, logChannels: self::recordTo($logger));
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\Hook\LateLogHook', AsHook::class, []),
        ], []));
        $GLOBALS['wp_actions'] = ['after_setup_theme' => 1];
        // テーマ形態の Kernel は、子テーマのディレクトリも forFile() の判定に使う(ここでは子テーマ無し)
        Functions\when('get_template_directory')->justReturn($config->rootDir());
        Functions\when('get_stylesheet_directory')->justReturn($config->rootDir());
        $autoload = $this->registerAppAutoloader($namespace, $appDir);

        try {
            $kernel = Kernel::boot($config);
        } finally {
            spl_autoload_unregister($autoload);
        }

        // キャッチアップの中のアプリのコードの Log::error() が、この Kernel に記録される
        self::assertContains('logged during catch-up', array_column($logger->records, 'message'));
        self::assertSame($kernel, Kernel::get($namespace));
    }

    public function testFailedCatchUpRemovesKernelThatWasRegisteredForIt(): void
    {
        $appDir = $this->createTempDirectory('failed-catch-up-registration-app');
        $namespace = 'Example\Tests\FailedCatchUpRegistrationApp' . uniqid();
        $config = KernelConfig::theme($appDir, $namespace, false, logChannels: self::recordTo(new KernelTestLogger()));
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($namespace . '\Failing', KernelTestCatchUpAttribute::class, ['hook' => 'init', 'priority' => 10, 'initializer' => KernelTestRegisteredThenFailingInitializer::class]),
        ], []));
        $GLOBALS['wp_actions'] = ['init' => 1];
        KernelTestRegisteredThenFailingInitializer::$registeredDuringCatchUp = null;

        try {
            Kernel::boot($config);
            self::fail('RuntimeException was not thrown');
        } catch (RuntimeException $exception) {
            self::assertSame('catch-up initialization failed', $exception->getMessage());
        }

        // キャッチアップの間は登録されていたが、失敗した boot の後には残らない
        self::assertTrue(KernelTestRegisteredThenFailingInitializer::$registeredDuringCatchUp);
        self::assertArrayNotHasKey($namespace, Kernel::all());
    }

    public function testInitializerCanUseServiceRegisteredByExtensionInNormalBoot(): void
    {
        $config = $this->extensionServiceConfig('NormalBootService', debug: true);
        $runner = null;
        // init は未発火。init 10 のグループは add_action され、発火のときに初期化する
        Actions\expectAdded('init')->once()->with(Mockery::type(Closure::class), 10, 0)
            ->whenHappen(static function (Closure $callback) use (&$runner): void {
                $runner = $callback;
            });

        $kernel = Kernel::boot($config);
        self::assertInstanceOf(Closure::class, $runner);
        $runner();

        self::assertSame([$kernel->container()->get(KernelTestExtensionService::class)], KernelTestServiceUsingInitializer::$resolved);
    }

    public function testInitializerCanUseServiceRegisteredByExtensionInProductionCatchUp(): void
    {
        $config = $this->extensionServiceConfig('CatchUpService', debug: false);
        (new DiscoveryFile($config))->write(new DiscoveryResult([
            new DiscoveredComponent($config->appNamespace . '\Uses', KernelTestCatchUpAttribute::class, ['hook' => 'init', 'priority' => 10, 'initializer' => KernelTestServiceUsingInitializer::class]),
        ], []));
        // init は発火済み。キャッチアップの初期化より先に拡張が登録され、サービスを使える
        $GLOBALS['wp_actions'] = ['init' => 1];

        $kernel = Kernel::boot($config);

        self::assertSame([$kernel->container()->get(KernelTestExtensionService::class)], KernelTestServiceUsingInitializer::$resolved);
    }

    /**
     * 拡張が Container に KernelTestExtensionService を入れ、init 10 のコンポーネントの初期化がそれを使う構成を作る。
     * 本番の構成のコンポーネントは、呼び出し側が探索結果ファイルに書く。
     */
    private function extensionServiceConfig(string $name, bool $debug): KernelConfig
    {
        KernelTestServiceUsingInitializer::$resolved = [];
        $root = $this->createTempDirectory('extension-service-' . strtolower($name));
        $appDir = $root . '/app';
        $namespace = 'Example\\Tests\\' . $name . uniqid();

        if ($debug) {
            // debug の boot は appDir を走査するため、init 10 のコンポーネントを app/ に置く
            $attribute = KernelTestCatchUpAttribute::class;
            $initializer = KernelTestServiceUsingInitializer::class;
            $this->writeFile($appDir . '/Uses.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

#[\\{$attribute}(hook: 'init', priority: 10, initializer: \\{$initializer}::class)]
final class Uses
{
}
PHP);
            $this->autoloaders[] = $this->registerAppAutoloader($namespace, $appDir);
        } elseif (!mkdir($appDir, 0775, true)) {
            self::fail("ディレクトリを作成できません: {$appDir}");
        }

        return KernelConfig::theme($appDir, $namespace, $debug, extensions: [new KernelTestServiceExtension()], logChannels: self::recordTo(new KernelTestLogger()));
    }

    /**
     * $outer の boot の途中で $inner の boot が呼ばれる構成で、内側の boot が KernelException になり、外側の boot も
     * その例外で失敗してレジストリに何も残らないことを確かめる。その後、外側と同じ名前空間を普通に boot できる
     * (boot の進行中の印が外れている)ことも確かめる。
     */
    private function assertReentrantBootIsRejected(KernelConfig $outer, KernelConfig $inner): void
    {
        try {
            Kernel::boot($outer);
            self::fail('KernelException was not thrown');
        } catch (KernelException $exception) {
            self::assertSame(
                "cannot boot the TMSWP kernel for namespace '{$inner->appNamespace}' while the kernel for namespace '{$outer->appNamespace}' is booting; boot each kernel from the top level of functions.php or the plugin main file",
                $exception->getMessage(),
            );
        }

        self::assertSame([], Kernel::all());

        $GLOBALS['wp_actions'] = [];
        $kernel = Kernel::boot(KernelConfig::theme($this->createAppDirectory('reentrant-reboot'), $outer->appNamespace, true));

        self::assertSame([$outer->appNamespace => $kernel], Kernel::all());
    }

    /**
     * 呼ばれると $config の Kernel を boot する logChannels を返す。
     *
     * @return Closure(): array<string, LogChannel>
     */
    private static function bootingLogChannels(KernelConfig $config): Closure
    {
        return static function () use ($config): array {
            Kernel::boot($config);

            return ['test' => new LogChannel(new KernelTestLogger())];
        };
    }

    private function assertLifecycleCallbacksOnlyDeleteRewriteRules(Kernel $kernel, mixed $activate, mixed $deactivate): void
    {
        self::assertInstanceOf(Closure::class, $activate);
        self::assertInstanceOf(Closure::class, $deactivate);

        Functions\expect('delete_option')->twice()->with('rewrite_rules')->andReturn(true);
        Functions\expect('flush_rewrite_rules')->never();
        Functions\expect('add_option')->never();
        Functions\expect('update_option')->never();
        Actions\expectDone('tmswp/activated')->once()->with($kernel);
        Actions\expectDone('tmswp/deactivated')->once()->with($kernel);

        $activate();
        $deactivate();
    }

    /**
     * 探索結果ファイルにだけ KernelTestHookProbe のメソッドを書いて本番相当(debug=false)で boot し、登録されたコールバックを返す。
     *
     * @return Closure(mixed...): mixed
     */
    private function bootWithFileOnlyHookMethod(string $hook, string $method, int $acceptedArgs, HookMethodKind $kind, int $registeredArgs, KernelTestLogger $logger = new KernelTestLogger()): Closure
    {
        $config = KernelConfig::theme($this->createTempDirectory('hook-method-app'), 'Example\Tests\HookMethodApp' . uniqid(), false, logChannels: self::recordTo($logger));
        (new DiscoveryFile($config))->write(new DiscoveryResult([], [
            new DiscoveredHookMethod(KernelTestHookProbe::class, $method, $hook, 10, $acceptedArgs, $kind),
        ]));

        return $this->bootAndCaptureHookCallback($config, $hook, $registeredArgs);
    }

    /** @return Closure(mixed...): mixed */
    private function bootAndCaptureHookCallback(KernelConfig $config, string $hook, int $registeredArgs): Closure
    {
        $callback = null;

        Filters\expectAdded($hook)->once()->with(Mockery::type(Closure::class), 10, $registeredArgs)
            ->whenHappen(static function (Closure $registered) use (&$callback): void {
                $callback = $registered;
            });

        Kernel::boot($config);

        self::assertInstanceOf(Closure::class, $callback);

        return $callback;
    }

    /**
     * Kernel のログを、記録を残すだけのロガーへ送る logChannels を返す。
     *
     * @return Closure(): array<string, LogChannel>
     */
    private static function recordTo(KernelTestLogger $logger): Closure
    {
        return static fn(): array => ['test' => new LogChannel($logger)];
    }

    private function catchUpComponentClass(string $namespace, string $class, string $hook, int $priority): string
    {
        $attribute = KernelTestCatchUpAttribute::class;

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Late;

#[\\{$attribute}(hook: '{$hook}', priority: {$priority})]
final class {$class}
{
}
PHP;
    }

    private function hookClass(string $namespace, string $class, string $hook): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class {$class} extends Hook
{
    #[Action('{$hook}')]
    public function handle(mixed \$value): void
    {
    }
}
PHP;
    }

    /** @return Closure(string): void */
    private function registerAppAutoloader(string $namespace, string $appDir): Closure
    {
        $autoload = static function (string $class) use ($namespace, $appDir): void {
            $prefix = $namespace . '\\';

            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $file = $appDir . '/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';

            if (is_file($file)) {
                require $file;
            }
        };

        spl_autoload_register($autoload);

        return $autoload;
    }

    /** ルートの一時ディレクトリの下に app/ を作り、そのパスを返す。 */
    private function createAppDirectory(string $prefix): string
    {
        $appDir = $this->createTempDirectory($prefix) . '/app';

        if (!mkdir($appDir)) {
            self::fail("ディレクトリを作成できません: {$appDir}");
        }

        return $appDir;
    }

    private function writeFile(string $path, string $contents): string
    {
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("ディレクトリを作成できません: {$dir}");
        }

        if (file_put_contents($path, $contents) === false) {
            self::fail("ファイルを書き込めません: {$path}");
        }

        return $path;
    }
}

/**
 * 探索結果ファイルから呼ばれるフックメソッドの代役。
 */
final class KernelTestHookProbe
{
    public function addOne(int $value): int
    {
        return $value + 1;
    }

    /** fail() が例外を投げる行。ログの文脈の exceptionAt と照らし合わせる */
    public static int $failLine = 0;

    public function fail(string $value, string $extra): string
    {
        // 例外が投げられた場所は、例外を生成した行になる
        self::$failLine = __LINE__ + 1;
        $exception = new RuntimeException("hook method failed with {$value}", previous: new LogicException("previous failure with {$extra}"));

        throw $exception;
    }

    public function failWithoutArguments(): void
    {
        throw new RuntimeException('hook method failed');
    }

    public function countArguments(): int
    {
        return \func_num_args();
    }
}

/**
 * フック名と priority を引数で受け取るコンポーネント属性。キャッチアップの順序を確かめるために使う。
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class KernelTestCatchUpAttribute implements ComponentAttributeInterface
{
    /**
     * @param class-string<ComponentInitializerInterface> $initializer
     */
    public function __construct(
        private string $hook,
        private int $priority,
        private string $initializer = KernelTestRecordingInitializer::class,
    ) {}

    public function hookName(KernelContext $context): string
    {
        return $this->hook;
    }

    public function hookPriority(): int
    {
        return $this->priority;
    }

    public function initializerClass(): string
    {
        return $this->initializer;
    }
}

/** 拡張が Container に登録するサービスの id(interface のため autowire では解決できない) */
interface KernelTestExtensionService {}

final class KernelTestExtensionServiceImplementation implements KernelTestExtensionService {}

/**
 * Container に KernelTestExtensionService を登録する拡張。
 */
final class KernelTestServiceExtension implements ExtensionInterface
{
    public function register(Kernel $kernel): void
    {
        $kernel->container()->set(KernelTestExtensionService::class, new KernelTestExtensionServiceImplementation());
    }
}

/**
 * register()の中で、発火済みのフック(plugins_loaded・init)と未発火のフック(wp_loaded)へdefer()で予約する拡張。
 * 実行した予約をKernelTestRecordingInitializer::$initializedに記録する。
 */
final class KernelTestDeferringExtension implements ExtensionInterface
{
    public function register(Kernel $kernel): void
    {
        foreach ([['init', 25], ['init', 6], ['plugins_loaded', 5], ['wp_loaded', 10]] as [$hook, $priority]) {
            $kernel->defer($hook, $priority, static function () use ($hook, $priority): void {
                KernelTestRecordingInitializer::$initialized[] = "extension {$hook} {$priority}";
            });
        }
    }
}

/**
 * register() の中で別の Kernel を boot する拡張(boot の再入の禁止を確かめる)。
 */
final readonly class KernelTestBootingExtension implements ExtensionInterface
{
    public function __construct(
        private KernelConfig $config,
    ) {}

    public function register(Kernel $kernel): void
    {
        Kernel::boot($this->config);
    }
}

/**
 * 初期化の中で、$config の Kernel を boot する(boot の再入の禁止を確かめる)。
 */
final class KernelTestBootingInitializer implements ComponentInitializerInterface
{
    public static ?KernelConfig $config = null;

    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        if (self::$config === null) {
            throw new RuntimeException('KernelTestBootingInitializer::$config is not set');
        }

        Kernel::boot(self::$config);
    }
}

/**
 * 初期化で、拡張が登録した KernelTestExtensionService を Container から取る。
 */
final class KernelTestServiceUsingInitializer implements ComponentInitializerInterface
{
    /** @var list<KernelTestExtensionService> */
    public static array $resolved = [];

    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        self::$resolved[] = $kernel->container()->get(KernelTestExtensionService::class);
    }
}

/**
 * キャッチアップの初期化の時点で、そのコンポーネントの Kernel がレジストリにあったかを記録してから失敗する。
 */
final class KernelTestRegisteredThenFailingInitializer implements ComponentInitializerInterface
{
    public static ?bool $registeredDuringCatchUp = null;

    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        self::$registeredDuringCatchUp = \array_key_exists($kernel->config()->appNamespace, Kernel::all());

        throw new RuntimeException('catch-up initialization failed');
    }
}

/**
 * 初期化されたコンポーネントのクラス名を、初期化された順に記録する。
 */
final class KernelTestRecordingInitializer implements ComponentInitializerInterface
{
    /** @var list<string> */
    public static array $initialized = [];

    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        self::$initialized[] = $component->class;
    }
}

/**
 * メタを1つ宣言する投稿タイプ。KernelTestMetaEntityInitializer が MetaSchemaRegistry に登録する。
 */
final class KernelTestMetaPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const META_NOTE = 'tmswp_kernel_note';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('tmswp_kernel_meta');
    }
}

/**
 * boot 時の初期化で KernelTestMetaPost を MetaSchemaRegistry に登録し、boot 時の初期化と初期化のそれぞれで
 * コンポーネントのクラス名を記録する(boot 時の初期化は 'boot ' を前に付ける)。
 */
final class KernelTestMetaEntityInitializer implements BootInitializerInterface
{
    public function initializeAtBoot(DiscoveredComponent $component, Kernel $kernel): void
    {
        $kernel->container()->get(MetaSchemaRegistry::class)->registerEntity(KernelTestMetaPost::class, ObjectType::Post, 'tmswp_kernel_meta');
        KernelTestRecordingInitializer::$initialized[] = 'boot ' . $component->class;
    }

    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        KernelTestRecordingInitializer::$initialized[] = $component->class;
    }
}

/**
 * 初期化されたコンポーネントのクラス名を記録し、Kernel::defer() で記録の処理を予約する。発火済みの init(10・11)・
 * plugins_loaded(10)と、未発火の wp_loaded(10)に予約する。
 */
final class KernelTestDeferringInitializer implements ComponentInitializerInterface
{
    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        KernelTestRecordingInitializer::$initialized[] = $component->class;

        foreach ([['init', 11], ['init', 10], ['plugins_loaded', 10], ['wp_loaded', 10]] as [$hook, $priority]) {
            $kernel->defer($hook, $priority, static function () use ($hook, $priority): void {
                KernelTestRecordingInitializer::$initialized[] = "deferred {$hook} {$priority}";
            });
        }
    }
}

/**
 * アダプタ先の builtin の投稿タイプが無い投稿タイプ。register() が EntityDefinitionException を投げる。
 */
final class KernelTestMissingBuiltinPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String)]
    public const META_NOTE = 'tmswp_kernel_missing_note';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::builtin('tmswp_kernel_missing');
    }
}

/**
 * 記録されたログを、出力せずに保持する。
 */
final class KernelTestLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
