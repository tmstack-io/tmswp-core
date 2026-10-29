<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Kernel;

use ArrayObject;
use Error;
use ParseError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Hook\Hook;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\DiscoveredHookMethod;
use TMSWP\Core\Kernel\Discovery;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\HookMethodKind;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Testing\UnitTestCase;

final class DiscoveryTest extends UnitTestCase
{
    /** @var list<callable(string): void> */
    private array $autoloaders = [];

    protected function tearDown(): void
    {
        foreach ($this->autoloaders as $autoloader) {
            spl_autoload_unregister($autoloader);
        }

        $this->autoloaders = [];

        parent::tearDown();
    }

    public function testItDiscoversMethodLevelHookAttributes(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('discovery-app');

        $this->writePhpFile($appDir, 'Hooks/SampleHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Attribute\\Filter;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class SampleHook extends Hook
{
    #[Action('init', priority: 5)]
    public function boot(): void
    {
    }

    #[Filter('the_title', priority: 12)]
    public function title(string \$title): string
    {
        return \$title;
    }
}
PHP);
        $this->registerAppAutoloader($namespace, $appDir);

        $result = $this->scan($namespace, $appDir);

        self::assertCount(0, $result->components);
        self::assertCount(2, $result->hookMethods);
        self::assertSame("{$namespace}\\Hooks\\SampleHook", $result->hookMethods[0]->class);
        self::assertSame('boot', $result->hookMethods[0]->method);
        self::assertSame('init', $result->hookMethods[0]->hook);
        self::assertSame(5, $result->hookMethods[0]->priority);
        self::assertSame(0, $result->hookMethods[0]->acceptedArgs);
        self::assertSame(HookMethodKind::Action, $result->hookMethods[0]->kind);
        self::assertSame('the_title', $result->hookMethods[1]->hook);
        self::assertSame(1, $result->hookMethods[1]->acceptedArgs);
        self::assertSame(HookMethodKind::Filter, $result->hookMethods[1]->kind);
    }

    public function testItTreatsHookRegisterOverrideAsComponent(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('manual-hook-app');

        $this->writePhpFile($appDir, 'Hooks/ManualHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class ManualHook extends Hook
{
    public function register(): void
    {
    }
}
PHP);
        $this->registerAppAutoloader($namespace, $appDir);

        $result = $this->scan($namespace, $appDir);

        self::assertCount(1, $result->components);
        self::assertCount(0, $result->hookMethods);
        self::assertSame("{$namespace}\\Hooks\\ManualHook", $result->components[0]->class);
    }

    public function testItAllowsOptionSchemaOnEnumCases(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('option-schema-app');

        $this->writePhpFile($appDir, 'Option/SiteOption.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Option;

use TMSWP\\Core\\Attribute\\AsOptionGroup;
use TMSWP\\Core\\Attribute\\OptionSchema;
use TMSWP\\Core\\Meta\\MetaValueType;
use TMSWP\\Core\\Option\\OptionAccess;

#[AsOptionGroup(prefix: 'example_')]
enum SiteOption: string
{
    use OptionAccess;

    #[OptionSchema(type: MetaValueType::String, default: 'info@example.test')]
    case ContactEmail = 'contact_email';
}
PHP);
        $this->registerAppAutoloader($namespace, $appDir);

        $result = $this->scan($namespace, $appDir);

        self::assertCount(1, $result->components);
        self::assertSame("{$namespace}\\Option\\SiteOption", $result->components[0]->class);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function misplacedOptionSchemas(): iterable
    {
        $header = <<<'PHP'
<?php

declare(strict_types=1);

namespace {namespace}\Option;

use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Option\OptionAccess;

PHP;

        yield 'クラス自身' => [
            $header . <<<'PHP'
#[OptionSchema(type: MetaValueType::String)]
final class Misplaced
{
}
PHP,
            '#[OptionSchema] is only allowed on enum cases: {class}',
            'Misplaced',
        ];
        yield 'グループの enum のケースでない定数' => [
            $header . <<<'PHP'
#[AsOptionGroup(prefix: 'example_')]
enum Misplaced: string
{
    use OptionAccess;

    #[OptionSchema(type: MetaValueType::String)]
    public const LEGACY = 'legacy';

    #[OptionSchema(type: MetaValueType::String)]
    case ContactEmail = 'contact_email';
}
PHP,
            '#[OptionSchema] is only allowed on enum cases: {class}::LEGACY',
            'Misplaced',
        ];
        yield 'クラスの定数' => [
            $header . <<<'PHP'
final class Misplaced
{
    #[OptionSchema(type: MetaValueType::String)]
    public const CONTACT_EMAIL = 'contact_email';
}
PHP,
            '#[OptionSchema] is only allowed on enum cases: {class}::CONTACT_EMAIL',
            'Misplaced',
        ];
        yield '#[AsOptionGroup] の無い enum のケース' => [
            $header . <<<'PHP'
enum Misplaced: string
{
    use OptionAccess;

    #[OptionSchema(type: MetaValueType::String)]
    case ContactEmail = 'contact_email';
}
PHP,
            '#[OptionSchema] requires #[AsOptionGroup] on the enum: {class}::ContactEmail',
            'Misplaced',
        ];
    }

    /**
     * #[OptionSchema] を、#[AsOptionGroup] を付けた enum のケース以外(クラス自身、enum のケースでない定数、
     * #[AsOptionGroup] の無い enum のケース)に付けると、黙って無視せずに探索失敗にする。
     */
    #[DataProvider('misplacedOptionSchemas')]
    public function testItRejectsOptionSchemaOutsideOptionGroupCases(string $source, string $message, string $shortName): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('misplaced-option-schema-app');
        $class = "{$namespace}\\Option\\{$shortName}";
        $this->writePhpFile($appDir, "Option/{$shortName}.php", str_replace('{namespace}', $namespace, $source));
        $this->registerAppAutoloader($namespace, $appDir);

        $exception = $this->scanFailure($namespace, $appDir);

        self::assertStringStartsWith(str_replace('{class}', $class, $message) . '; file: ', $exception->getMessage());
    }

    public function testItRejectsMethodHooksWithoutAsHook(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('broken-hook-app');

        $this->writePhpFile($appDir, 'Hooks/BrokenHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\Action;

final class BrokenHook
{
    #[Action('init')]
    public function boot(): void
    {
    }
}
PHP);
        $this->registerAppAutoloader($namespace, $appDir);

        $this->expectException(DiscoveryException::class);

        $this->scan($namespace, $appDir);
    }

    public function testItSortsHookMethodsByStrcmp(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('sorted-hook-app');

        // 数字だけのフック名は配列の <=> では数値として比べられ '9' が先になる。strcmp では '10' が先になる
        $this->writePhpFile($appDir, 'Hooks/NumericHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Attribute\\Filter;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class NumericHook extends Hook
{
    #[Filter('9')]
    #[Action('10')]
    public function handle(): void
    {
    }
}
PHP);
        $this->writePhpFile($appDir, 'Hooks/AlphaHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class AlphaHook extends Hook
{
    #[Action('init')]
    public function handle(): void
    {
    }
}
PHP);
        $this->registerAppAutoloader($namespace, $appDir);

        $result = $this->scan($namespace, $appDir);

        self::assertSame(
            [
                ["{$namespace}\\Hooks\\AlphaHook", 'init'],
                ["{$namespace}\\Hooks\\NumericHook", '10'],
                ["{$namespace}\\Hooks\\NumericHook", '9'],
            ],
            array_map(static fn($hookMethod): array => [$hookMethod->class, $hookMethod->hook], $result->hookMethods),
        );
    }

    public function testItWrapsParseErrorRaisedByAutoload(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('parse-error-app');
        $this->writePhpFile($appDir, 'Broken.php', "<?php\n\nnamespace {$namespace};\n\nfinal class Broken {\n");
        $this->registerAppAutoloader($namespace, $appDir);

        $exception = $this->scanFailure($namespace, $appDir);

        self::assertStringContainsString("{$namespace}\\Broken", $exception->getMessage());
        $file = realpath($appDir . '/Broken.php');
        self::assertIsString($file);
        self::assertStringContainsString($file, $exception->getMessage());
        self::assertInstanceOf(ParseError::class, $exception->getPrevious());
    }

    public function testItWrapsErrorRaisedByAutoload(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('missing-parent-app');
        $this->writePhpFile($appDir, 'Orphan.php', "<?php\n\nnamespace {$namespace};\n\nfinal class Orphan extends MissingParent\n{\n}\n");
        $this->registerAppAutoloader($namespace, $appDir);

        $exception = $this->scanFailure($namespace, $appDir);

        self::assertStringContainsString("failed to autoload {$namespace}\\Orphan", $exception->getMessage());
        self::assertInstanceOf(Error::class, $exception->getPrevious());
    }

    public function testItRejectsAsHookClassNotExtendingHook(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('plain-hook-app');

        // register() という名前のメソッドがあっても、Hook を継承していなければ #[AsHook] は誤り
        $this->writePhpFile($appDir, 'Hooks/PlainHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\AsHook;

#[AsHook]
final class PlainHook
{
    public function register(): void
    {
    }
}
PHP);
        $this->registerAppAutoloader($namespace, $appDir);

        $exception = $this->scanFailure($namespace, $appDir);

        self::assertStringContainsString('#[AsHook] class must extend ' . Hook::class, $exception->getMessage());
        self::assertStringContainsString("{$namespace}\\Hooks\\PlainHook", $exception->getMessage());
    }

    public function testItFailsRegardlessOfDebug(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('non-debug-broken-app');
        $this->writePhpFile($appDir, 'Missing.php', "<?php\n\nnamespace {$namespace};\n\nfinal class Other\n{\n}\n");
        $this->registerAppAutoloader($namespace, $appDir);
        $config = KernelConfig::theme($appDir, $namespace, false);

        $this->expectException(DiscoveryException::class);
        $this->expectExceptionMessage("is expected to define class {$namespace}\\Missing");

        (new Discovery($config, new DiscoveryFile($config)))->scan();
    }

    public function testItWrapsParseErrorOfAttributeClass(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('broken-attribute-app');
        $supportDir = $this->createTempDirectory('broken-attribute-support');

        // 属性クラスは app/ の外に置く。getAttributes(..., IS_INSTANCEOF) が autoload したときに ParseError になる
        $this->writePhpFile($supportDir, 'BrokenAttribute.php', "<?php\n\nnamespace {$namespace}Support;\n\n#[\\Attribute]\nfinal class BrokenAttribute {\n");
        $this->writePhpFile($appDir, 'Tagged.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

#[\\{$namespace}Support\\BrokenAttribute]
final class Tagged
{
}
PHP);
        $this->registerAppAutoloader($namespace . 'Support', $supportDir);
        $this->registerAppAutoloader($namespace, $appDir);

        $exception = $this->scanFailure($namespace, $appDir);

        self::assertStringContainsString("failed to read {$namespace}\\Tagged", $exception->getMessage());
        self::assertStringContainsString('class: ' . $namespace . '\\Tagged', $exception->getMessage());
        self::assertStringContainsString('Tagged.php', $exception->getMessage());
        self::assertInstanceOf(ParseError::class, $exception->getPrevious());
    }

    public function testItWrapsUndefinedClassConstantInAttributeArguments(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('undefined-constant-app');
        $this->writePhpFile($appDir, 'Option/BrokenOption.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Option;

use TMSWP\\Core\\Attribute\\AsOptionGroup;

#[AsOptionGroup(prefix: self::MISSING_PREFIX)]
enum BrokenOption: string
{
    case Email = 'email';
}
PHP);
        $this->registerAppAutoloader($namespace, $appDir);

        $exception = $this->scanFailure($namespace, $appDir);

        self::assertStringContainsString("failed to read {$namespace}\\Option\\BrokenOption", $exception->getMessage());
        self::assertStringContainsString('MISSING_PREFIX', $exception->getMessage());
        self::assertStringContainsString('BrokenOption.php', $exception->getMessage());
        self::assertInstanceOf(Error::class, $exception->getPrevious());
    }

    /**
     * 結果の並びは、ファイル名の順(候補ファイルの相対パスの strcmp 順)とも、ファイルの作成順とも違う。
     * components は (class, attributeClass)、hookMethods は (class, method, hook) の中で priority と kind の順に並ぶ。
     * FQCN の区切り文字 '\' はパスの区切り文字 '/' より後に並ぶため、FooA は Foo/Bar より先、HookA は Hook/Sub/Entry より先になる。
     */
    public function testItOrdersResultsIndependentlyOfFileNamesAndCreationOrder(): void
    {
        $namespace = $this->runtimeNamespace();
        $root = $this->createTempDirectory('ordering');
        $this->writeOrderingApp($root, $namespace, false);
        $loaded = new ArrayObject();
        $this->registerAppAutoloader($namespace, $root . '/app', $loaded);

        $result = $this->scan($namespace, $root . '/app');

        // 候補ファイルは相対パスの strcmp 順に読む
        self::assertSame(
            ['Attribute\\AlphaMark', 'Attribute\\ZetaMark', 'Foo\\Bar', 'FooA', 'Hook\\Sub\\Entry', 'HookA'],
            array_map(static fn(string $class): string => substr($class, \strlen($namespace) + 1), $loaded->getArrayCopy()),
        );
        self::assertSame(
            [
                ["{$namespace}\\FooA", "{$namespace}\\Attribute\\AlphaMark", ['foo-a']],
                ["{$namespace}\\Foo\\Bar", "{$namespace}\\Attribute\\AlphaMark", ['alpha']],
                ["{$namespace}\\Foo\\Bar", "{$namespace}\\Attribute\\ZetaMark", ['zeta']],
            ],
            array_map(static fn(DiscoveredComponent $component): array => [$component->class, $component->attributeClass, $component->attributeArguments], $result->components),
        );
        self::assertSame(
            [
                ["{$namespace}\\HookA", 'only', 'tmswp_order', 10, 'action'],
                ["{$namespace}\\Hook\\Sub\\Entry", 'alpha', 'tmswp_same', 10, 'action'],
                ["{$namespace}\\Hook\\Sub\\Entry", 'alpha', 'tmswp_same', 10, 'filter'],
                ["{$namespace}\\Hook\\Sub\\Entry", 'middle', 'tmswp_a', 10, 'filter'],
                ["{$namespace}\\Hook\\Sub\\Entry", 'middle', 'tmswp_z', 10, 'action'],
                ["{$namespace}\\Hook\\Sub\\Entry", 'zeta', 'tmswp_b', 10, 'filter'],
                ["{$namespace}\\Hook\\Sub\\Entry", 'zeta', 'tmswp_b', 20, 'action'],
            ],
            array_map(
                static fn(DiscoveredHookMethod $hookMethod): array => [$hookMethod->class, $hookMethod->method, $hookMethod->hook, $hookMethod->priority, $hookMethod->kind->value],
                $result->hookMethods,
            ),
        );
    }

    /**
     * 同じファイル群を逆の順で作成した2つのルートを、同じ名前空間でそれぞれ別の PHP プロセスで走査すると、探索結果ファイルの内容が一致する。
     */
    public function testResultsAreIdenticalWhenFilesAreCreatedInReverseOrder(): void
    {
        $script = $this->writeRenderScript();
        $namespace = 'Example\\Tests\\OrderingApp';
        $outputs = [];

        foreach ([false, true] as $reverse) {
            $root = $this->createTempDirectory('ordering-' . ($reverse ? 'reversed' : 'forward'));
            $this->writeOrderingApp($root, $namespace, $reverse);
            $outputs[] = $this->runPhp($script, $root, $namespace);
        }

        self::assertStringContainsString("'hook' => 'tmswp_same'", $outputs[0]);
        self::assertStringNotContainsString('DiscoveryException', $outputs[0]);
        self::assertSame($outputs[0], $outputs[1]);
    }

    public function testItRejectsFileDefiningClassInAnotherNamespace(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('namespace-mismatch-app');
        $this->writePhpFile($appDir, 'Post/ProductPost.php', "<?php\n\nnamespace {$namespace}\\Posts;\n\nfinal class ProductPost\n{\n}\n");
        $this->registerAppAutoloader($namespace, $appDir);

        $exception = $this->scanFailure($namespace, $appDir);

        $file = $this->realPath($appDir . '/Post/ProductPost.php');
        self::assertStringStartsWith("file {$file} is expected to define class {$namespace}\\Post\\ProductPost; ", $exception->getMessage());
        self::assertStringEndsWith("; file: {$file}; class: {$namespace}\\Post\\ProductPost", $exception->getMessage());
    }

    public function testItRejectsClassNameCaseMismatch(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('case-mismatch-app');
        // PHP のクラス名は大文字小文字を区別しないため、class_exists() は成功してしまう
        $this->writePhpFile($appDir, 'ProductPost.php', "<?php\n\nnamespace {$namespace};\n\nfinal class Productpost\n{\n}\n");
        $this->registerAppAutoloader($namespace, $appDir);

        $exception = $this->scanFailure($namespace, $appDir);

        $file = $this->realPath($appDir . '/ProductPost.php');
        self::assertSame(
            "class name case mismatch for {$namespace}\\ProductPost; reflection returned {$namespace}\\Productpost; file: {$file}; class: {$namespace}\\ProductPost",
            $exception->getMessage(),
        );
    }

    public function testItRejectsClassLoadedFromAnotherFile(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('other-file-app');
        $otherDir = $this->createTempDirectory('other-file');
        $source = "<?php\n\nnamespace {$namespace};\n\nfinal class Moved\n{\n}\n";
        $this->writePhpFile($appDir, 'Moved.php', $source);
        // 同じクラスを app/ の外のファイルから先に読み込んでおく(古いクラスマップ等)
        $this->writePhpFile($otherDir, 'Moved.php', $source);
        require $otherDir . '/Moved.php';
        $this->registerAppAutoloader($namespace, $appDir);

        $exception = $this->scanFailure($namespace, $appDir);

        $file = $this->realPath($appDir . '/Moved.php');
        self::assertSame(
            "class {$namespace}\\Moved was loaded from a different file; expected {$file}; file: {$file}; class: {$namespace}\\Moved",
            $exception->getMessage(),
        );
    }

    /**
     * ABSPATH の定義(symlink を経由するか・末尾の区切り文字を付けるか)と、属性引数・フック名に書いたパスの表記の組み合わせ。
     * WordPress の ABSPATH は末尾に区切り文字を付けて定義される。
     *
     * @return iterable<string, array{bool, string, bool, bool}>
     */
    public static function absPathNotations(): iterable
    {
        yield '実ディレクトリ・末尾に区切り文字・属性引数' => [false, '/', false, false];
        yield '実ディレクトリ・区切り文字なし・フック名' => [false, '', false, true];
        yield 'symlink の表記・末尾に区切り文字・属性引数' => [true, '/', false, false];
        yield 'symlink の表記・区切り文字なし・フック名' => [true, '', false, true];
        yield 'symlink の実パス・末尾に区切り文字・フック名' => [true, '/', true, true];
        yield 'symlink の実パス・区切り文字なし・属性引数' => [true, '', true, false];
    }

    /**
     * 定数は取り消せずプロセス全体に漏れるので、分離プロセス内でだけ定義する。
     */
    #[DataProvider('absPathNotations')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testItRejectsPathUnderAbsPath(bool $linked, string $suffix, bool $resolved, bool $inHookName): void
    {
        $namespace = $this->runtimeNamespace();
        $root = $this->createTempDirectory('abspath-app');
        $base = $this->realPath($this->createTempDirectory('abspath'));
        mkdir($base . '/wordpress');
        $link = $base . '/link';
        self::assertTrue(symlink($base . '/wordpress', $link));

        try {
            $abspath = $linked ? $link : $base . '/wordpress';
            \define('ABSPATH', $abspath . $suffix);
            $written = ($resolved ? $this->realPath($abspath) : $abspath) . '/wp-includes/x.php';

            if ($inHookName) {
                $this->writeAssetsApp($root, $namespace, "['styles/main.css' => 'main']", var_export($written, true));
            } else {
                $this->writeAssetsApp($root, $namespace, var_export(['path' => $written], true), "'init'");
            }

            $exception = $this->scanFailure($namespace, $root . '/app');

            $matchedRoot = $resolved ? $this->realPath($abspath) : $abspath;
            self::assertStringContainsString(
                ($inHookName ? "hook name of {$namespace}\\Hook\\ProbeHook::handle" : "attribute argument of {$namespace}\\Assets")
                . " contains the environment-dependent path {$matchedRoot}: '{$written}'",
                $exception->getMessage(),
            );
        } finally {
            // 一時ディレクトリの後始末は symlink を rmdir しようとして失敗するため、先にリンクだけ消す
            unlink($link);
        }
    }

    public function testItSkipsInterfacesTraitsLowercaseFilesAndClassesWithoutAttributes(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('skipped-app');
        $this->writeMarkAttribute($appDir, $namespace, 'Mark');
        // interface・trait は、コンポーネント属性やフック属性を持っていても走査の対象にしない
        $this->writePhpFile($appDir, 'Contract/Marked.php', "<?php\n\nnamespace {$namespace}\\Contract;\n\n#[\\{$namespace}\\Attribute\\Mark]\ninterface Marked\n{\n}\n");
        $this->writePhpFile($appDir, 'Concern/Hooked.php', <<<PHP
<?php

namespace {$namespace}\\Concern;

use TMSWP\\Core\\Attribute\\Action;

trait Hooked
{
    #[Action('init')]
    public function handle(): void
    {
    }
}
PHP);
        // 小文字で始まるファイルはクラスのファイルとみなさず、読み込まない
        $this->writePhpFile($appDir, 'helpers.php', "<?php\n\nthrow new \\RuntimeException('helpers.php must not be loaded by the scan');\n");
        $this->writePhpFile($appDir, 'Support/format.php', "<?php\n\nthrow new \\RuntimeException('format.php must not be loaded by the scan');\n");
        $this->writePhpFile($appDir, 'Plain.php', "<?php\n\nnamespace {$namespace};\n\nfinal class Plain\n{\n}\n");
        $this->writePhpFile($appDir, 'Marked.php', "<?php\n\nnamespace {$namespace};\n\n#[Attribute\\Mark]\nfinal class Marked\n{\n}\n");
        $this->registerAppAutoloader($namespace, $appDir);

        $result = $this->scan($namespace, $appDir);

        self::assertSame(
            [["{$namespace}\\Marked", "{$namespace}\\Attribute\\Mark"]],
            array_map(static fn(DiscoveredComponent $component): array => [$component->class, $component->attributeClass], $result->components),
        );
        self::assertSame([], $result->hookMethods);
        self::assertTrue(interface_exists("{$namespace}\\Contract\\Marked", false));
        self::assertTrue(trait_exists("{$namespace}\\Concern\\Hooked", false));
    }

    public function testItRejectsComponentAttributeOnAbstractClass(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('abstract-component-app');
        $this->writeMarkAttribute($appDir, $namespace, 'Mark');
        $this->writePhpFile($appDir, 'BasePost.php', "<?php\n\nnamespace {$namespace};\n\n#[Attribute\\Mark]\nabstract class BasePost\n{\n}\n");
        $this->registerAppAutoloader($namespace, $appDir);

        $exception = $this->scanFailure($namespace, $appDir);

        self::assertStringStartsWith("component attribute on non-instantiable class {$namespace}\\BasePost; file: {$this->realPath($appDir . '/BasePost.php')}", $exception->getMessage());
    }

    /** @return iterable<string, array{string, string}> */
    public static function misusedHookMethods(): iterable
    {
        yield 'static メソッドの #[Action]' => ["#[Action('init')]\n    public static function handle(): void", 'handle'];
        yield 'protected メソッドの #[Filter]' => ["#[Filter('the_title')]\n    protected function handle(string \$title): string", 'handle'];
        yield 'private メソッドの #[Action]' => ["#[Action('init')]\n    private function handle(): void", 'handle'];
        yield '可変長引数のメソッドの #[Filter]' => ["#[Filter('the_title')]\n    public function handle(mixed ...\$args): mixed", 'handle'];
    }

    #[DataProvider('misusedHookMethods')]
    public function testItRejectsHookAttributeOnUnsupportedMethod(string $method, string $methodName): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('misused-hook-method-app');
        $this->writePhpFile($appDir, 'Hooks/MisusedHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Attribute\\Filter;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class MisusedHook extends Hook
{
    {$method}
    {
        throw new \\LogicException('not called');
    }
}
PHP);
        $this->registerAppAutoloader($namespace, $appDir);

        $exception = $this->scanFailure($namespace, $appDir);

        self::assertStringStartsWith(
            "method-level hook attributes require public non-static methods without variadic parameters: {$namespace}\\Hooks\\MisusedHook::{$methodName}; file: {$this->realPath($appDir . '/Hooks/MisusedHook.php')}",
            $exception->getMessage(),
        );
    }

    public function testItRejectsHookClassRegisteringNoHooks(): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('empty-hook-app');
        $this->writePhpFile($appDir, 'Hooks/EmptyHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hooks;

use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class EmptyHook extends Hook
{
    public function helper(): void
    {
    }
}
PHP);
        $this->registerAppAutoloader($namespace, $appDir);

        $exception = $this->scanFailure($namespace, $appDir);

        self::assertStringStartsWith("hook class {$namespace}\\Hooks\\EmptyHook registers no hooks; file: {$this->realPath($appDir . '/Hooks/EmptyHook.php')}", $exception->getMessage());
    }

    /** @return iterable<string, array{string}> */
    public static function objectAttributeArguments(): iterable
    {
        yield '引数そのもの' => ['new \\ArrayObject()'];
        yield '配列の値' => ["['styles' => new \\ArrayObject()]"];
        yield '入れ子の配列の値' => ["['styles' => ['main' => new \\ArrayObject()]]"];
    }

    #[DataProvider('objectAttributeArguments')]
    public function testItRejectsObjectInAttributeArguments(string $argument): void
    {
        $namespace = $this->runtimeNamespace();
        $appDir = $this->createTempDirectory('object-argument-app');
        $this->writeMarkAttribute($appDir, $namespace, 'Mark');
        $this->writePhpFile($appDir, 'Marked.php', "<?php\n\nnamespace {$namespace};\n\n#[Attribute\\Mark({$argument})]\nfinal class Marked\n{\n}\n");
        $this->registerAppAutoloader($namespace, $appDir);

        $exception = $this->scanFailure($namespace, $appDir);

        self::assertStringStartsWith(
            "attribute arguments must be exportable to the discovery file (scalar/array/enum); file: {$this->realPath($appDir . '/Marked.php')}; class: {$namespace}\\Marked",
            $exception->getMessage(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function environmentDependentAttributeArguments(): iterable
    {
        return [
            '値' => ["['styles' => ['main' => __DIR__ . '/main.css']]"],
            '配列のキー' => ["[__DIR__ . '/main.css' => 'main']"],
            '入れ子の配列のキー' => ["['styles' => [__DIR__ => 'main']]"],
        ];
    }

    #[DataProvider('environmentDependentAttributeArguments')]
    public function testItRejectsEnvironmentDependentPathInAttributeArguments(string $assets): void
    {
        $namespace = $this->runtimeNamespace();
        $root = $this->createTempDirectory('absolute-argument');
        $this->writeAssetsApp($root, $namespace, $assets, "'init'");

        $exception = $this->scanFailure($namespace, $root . '/app');

        self::assertStringContainsString("attribute argument of {$namespace}\\Assets contains the environment-dependent path {$this->realPath($root)}: '{$this->realPath($root)}", $exception->getMessage());
    }

    public function testItRejectsEnvironmentDependentPathInHookName(): void
    {
        $namespace = $this->runtimeNamespace();
        $root = $this->createTempDirectory('absolute-hook');
        $this->writeAssetsApp($root, $namespace, "['styles/main.css' => 'main']", "__DIR__ . '/x'");

        $exception = $this->scanFailure($namespace, $root . '/app');

        self::assertStringContainsString(
            "hook name of {$namespace}\\Hook\\ProbeHook::handle contains the environment-dependent path {$this->realPath($root)}: '{$this->realPath($root)}/app/Hook/x'",
            $exception->getMessage(),
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function rootBoundaries(): iterable
    {
        return [
            'ルートそのもの' => ['{ROOT}', true],
            'ルートの下のファイル' => ['{ROOT}/x.php', true],
            'URL の中のルート' => ['file://{ROOT}/x', true],
            '名前がルートで始まる別のディレクトリ' => ['{ROOT}bc', false],
            'ルートの後に区切り文字以外が続く' => ['{ROOT}-b', false],
        ];
    }

    /** ルートの直後が文字列の終わりか区切り文字のときだけ、環境に依存するとみなす */
    #[DataProvider('rootBoundaries')]
    public function testItMatchesRootOnlyAtSeparatorBoundary(string $template, bool $rejected): void
    {
        $namespace = $this->runtimeNamespace();
        $root = $this->createTempDirectory('boundary');
        $value = str_replace('{ROOT}', $this->realPath($root), $template);
        $this->writeAssetsApp($root, $namespace, var_export(['path' => $value], true), "'init'");

        if ($rejected) {
            self::assertStringContainsString("'{$value}'", $this->scanFailure($namespace, $root . '/app')->getMessage());

            return;
        }

        $result = $this->scan($namespace, $root . '/app');
        self::assertSame(['assets' => ['path' => $value]], $result->components[0]->attributeArguments);
    }

    /** @return iterable<string, array{string}> */
    public static function wordPressRootConstants(): iterable
    {
        return [
            'ABSPATH' => ['ABSPATH'],
            'WP_CONTENT_DIR' => ['WP_CONTENT_DIR'],
        ];
    }

    /**
     * WP_CONTENT_DIR は、プラグイン・テーマ等を外に置く構成ではディレクトリが無くても WordPress が起動するため、存在しない定数のパスで止めない。
     * ABSPATH は存在しなければ WordPress 自体が起動しないが、扱いを WP_CONTENT_DIR と揃える。
     * 定数は取り消せずプロセス全体に漏れるので、分離プロセス内でだけ定義する。
     */
    #[DataProvider('wordPressRootConstants')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testItScansWhenWordPressRootDoesNotExist(string $constant): void
    {
        $namespace = $this->runtimeNamespace();
        $root = $this->createTempDirectory('missing-wp-root-app');
        \define($constant, $this->createTempDirectory('missing-wp-root') . '/missing');
        $this->writeAssetsApp($root, $namespace, "['styles/main.css' => 'main']", "'init'");

        $result = $this->scan($namespace, $root . '/app');

        self::assertSame(['assets' => ['styles/main.css' => 'main']], $result->components[0]->attributeArguments);
    }

    /** @return iterable<string, array{string}> */
    public static function missingContentDirDefinitions(): iterable
    {
        return [
            '区切り文字なし' => [''],
            '末尾に区切り文字' => ['/'],
        ];
    }

    /** 存在しない WP_CONTENT_DIR でも、設定された値(末尾の区切り文字を除く)を含む文字列は拒否する */
    #[DataProvider('missingContentDirDefinitions')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testItRejectsPathUnderMissingWpContentDir(string $suffix): void
    {
        $namespace = $this->runtimeNamespace();
        $root = $this->createTempDirectory('missing-content-app');
        $content = $this->createTempDirectory('missing-content') . '/content';
        \define('WP_CONTENT_DIR', $content . $suffix);
        $this->writeAssetsApp($root, $namespace, var_export(['path' => WP_CONTENT_DIR . '/x.php'], true), "'init'");

        $exception = $this->scanFailure($namespace, $root . '/app');

        self::assertStringContainsString(
            "contains the environment-dependent path {$content}: '{$content}{$suffix}/x.php'",
            $exception->getMessage(),
        );
    }

    /** @return iterable<string, array{bool}> */
    public static function symlinkedContentDirNotations(): iterable
    {
        return [
            'リンクの表記' => [false],
            '実パス' => [true],
        ];
    }

    /** WP_CONTENT_DIR が symlink なら、リンクの表記と実パスのどちらで書いたパスも拒否する */
    #[DataProvider('symlinkedContentDirNotations')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testItRejectsBothNotationsOfSymlinkedWpContentDir(bool $resolved): void
    {
        $namespace = $this->runtimeNamespace();
        $root = $this->createTempDirectory('linked-content-app');
        $base = $this->createTempDirectory('linked-content');
        mkdir($base . '/real');
        $link = $base . '/link';
        self::assertTrue(symlink($base . '/real', $link));

        try {
            \define('WP_CONTENT_DIR', $link);
            $content = $resolved ? $this->realPath($link) : $link;
            $this->writeAssetsApp($root, $namespace, var_export(['path' => $content . '/x.php'], true), "'init'");

            $exception = $this->scanFailure($namespace, $root . '/app');

            self::assertStringContainsString(
                "contains the environment-dependent path {$content}: '{$content}/x.php'",
                $exception->getMessage(),
            );
        } finally {
            // 一時ディレクトリの後始末は symlink を rmdir しようとして失敗するため、先にリンクだけ消す
            unlink($link);
        }
    }

    /**
     * 同じソースを絶対パスの違う2つのルートに置き、それぞれ別の PHP プロセスで scan → render する。
     * 環境に依存しない引数・フック名ならバイト列が一致し、__DIR__ を引数の値・配列のキー・フック名に使うと
     * どちらのルートでも例外になる。
     */
    public function testRenderedDiscoveryFileIsIdenticalAcrossRootsUnlessArgumentsContainAbsolutePaths(): void
    {
        $script = $this->writeRenderScript();

        [[$first, $firstRoot], [$second, $secondRoot]] = $this->renderInTwoRoots($script, 'portable', "['styles/main.css' => ['media' => 'all']]", "'init'");
        self::assertStringContainsString("'styles/main.css' => [", $first);
        self::assertStringContainsString("'hook' => 'init'", $first);
        self::assertSame($first, $second);
        self::assertStringNotContainsString($firstRoot, $first);
        self::assertStringNotContainsString($secondRoot, $second);

        $assetsError = 'DiscoveryException: attribute argument of Example\\Tests\\PortableApp\\Assets contains the environment-dependent path ';
        $hookError = 'DiscoveryException: hook name of Example\\Tests\\PortableApp\\Hook\\ProbeHook::handle contains the environment-dependent path ';
        $failures = [
            [$assetsError, $this->renderInTwoRoots($script, 'value', "['styles' => ['main' => __DIR__ . '/main.css']]", "'init'")],
            [$assetsError, $this->renderInTwoRoots($script, 'key', "[__DIR__ . '/main.css' => 'main']", "'init'")],
            [$hookError, $this->renderInTwoRoots($script, 'hook', "['styles/main.css' => ['media' => 'all']]", "__DIR__ . '/x'")],
        ];

        foreach ($failures as [$prefix, $outputs]) {
            foreach ($outputs as [$output, $root]) {
                self::assertStringStartsWith($prefix . $root . ': ', $output);
            }
        }
    }

    /**
     * 引数のルートの app/ を走査して探索結果ファイルの内容を出力する PHP スクリプトを書き、そのパスを返す。
     * DiscoveryException は、例外の種類とメッセージを出力する。
     */
    private function writeRenderScript(): string
    {
        $script = $this->createTempDirectory('render-script') . '/render.php';
        $this->writePhpFile(\dirname($script), 'render.php', <<<'PHP'
<?php

declare(strict_types=1);

use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Kernel\Discovery;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\KernelConfig;

[, $autoload, $root, $namespace] = $argv;
require $autoload;

spl_autoload_register(static function (string $class) use ($root, $namespace): void {
    $prefix = $namespace . '\\';

    if (str_starts_with($class, $prefix) && is_file($file = $root . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php')) {
        require $file;
    }
});

$config = KernelConfig::theme($root . '/app', $namespace, true);

try {
    echo (new DiscoveryFile($config))->render((new Discovery($config, new DiscoveryFile($config)))->scan());
} catch (DiscoveryException $exception) {
    echo 'DiscoveryException: ' . $exception->getMessage();
}
PHP);

        return $script;
    }

    /**
     * 同じソースを2つのルートに書き、それぞれ別の PHP プロセスで render した出力とルートの実パスを返す。
     *
     * @return array{array{string, string}, array{string, string}}
     */
    private function renderInTwoRoots(string $script, string $variant, string $assets, string $hook): array
    {
        $outputs = [];

        foreach (['first', 'second'] as $name) {
            $root = $this->createTempDirectory("portable-{$variant}-{$name}");
            $this->writeAssetsApp($root, 'Example\\Tests\\PortableApp', $assets, $hook);
            $outputs[] = [$this->runPhp($script, $root, 'Example\\Tests\\PortableApp'), $this->realPath($root)];
        }

        return [$outputs[0], $outputs[1]];
    }

    /**
     * 属性の引数と、Hook のフック名を差し替えられる app/ をルートの下に書く。属性クラスも app/ に置く
     * (コンポーネント属性を持たない普通のクラスとして走査される)。
     */
    private function writeAssetsApp(string $root, string $namespace, string $assets, string $hook): void
    {
        $this->writePhpFile($root, 'app/Attribute/AssetsAttribute.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Attribute;

use TMSWP\\Core\\Attribute\\ComponentAttributeInterface;
use TMSWP\\Core\\Hook\\HookInitializer;
use TMSWP\\Core\\Kernel\\KernelContext;

#[\\Attribute(\\Attribute::TARGET_CLASS)]
final class AssetsAttribute implements ComponentAttributeInterface
{
    /** @param array<array-key, mixed> \$assets */
    public function __construct(public array \$assets) {}

    public function hookName(KernelContext \$context): string
    {
        return 'init';
    }

    public function hookPriority(): int
    {
        return 10;
    }

    public function initializerClass(): string
    {
        return HookInitializer::class;
    }
}
PHP);
        $this->writePhpFile($root, 'app/Assets.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

#[Attribute\\AssetsAttribute(assets: {$assets})]
final class Assets
{
}
PHP);
        $this->writePhpFile($root, 'app/Hook/ProbeHook.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hook;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class ProbeHook extends Hook
{
    #[Action({$hook})]
    public function handle(): void
    {
    }
}
PHP);
        $this->registerAppAutoloader($namespace, $root . '/app');
    }

    /**
     * 結果の並びを確かめる app/ をルートの下に書く。$reverse なら作成の順を逆にする。
     * 作成の順はファイル名の順とも、期待する結果の並びとも違う。
     */
    private function writeOrderingApp(string $root, string $namespace, bool $reverse): void
    {
        $appDir = $root . '/app';
        $files = [
            'HookA.php' => <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Hook\\Hook;

#[AsHook]
final class HookA extends Hook
{
    #[Action('tmswp_order')]
    public function only(): void
    {
    }
}
PHP,
            'Foo/Bar.php' => <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Foo;

use {$namespace}\\Attribute\\AlphaMark;
use {$namespace}\\Attribute\\ZetaMark;

// 宣言の順は attributeClass の順と逆にする
#[ZetaMark('zeta')]
#[AlphaMark('alpha')]
final class Bar
{
}
PHP,
            'Attribute/ZetaMark.php' => $this->markAttributeSource($namespace, 'ZetaMark'),
            'Hook/Sub/Entry.php' => <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Hook\\Sub;

use TMSWP\\Core\\Attribute\\Action;
use TMSWP\\Core\\Attribute\\AsHook;
use TMSWP\\Core\\Attribute\\Filter;
use TMSWP\\Core\\Hook\\Hook;

// メソッドの宣言の順と、各メソッドの属性の順(#[Action] が先に集まる)は、期待する並びと違う
#[AsHook]
final class Entry extends Hook
{
    #[Action('tmswp_b', priority: 20)]
    #[Filter('tmswp_b', priority: 10)]
    public function zeta(mixed \$value = null): mixed
    {
        return \$value;
    }

    #[Action('tmswp_z')]
    #[Filter('tmswp_a')]
    public function middle(mixed \$value = null): mixed
    {
        return \$value;
    }

    #[Filter('tmswp_same')]
    #[Action('tmswp_same')]
    public function alpha(mixed \$value = null): mixed
    {
        return \$value;
    }
}
PHP,
            'FooA.php' => <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

#[Attribute\\AlphaMark('foo-a')]
final class FooA
{
}
PHP,
            'Attribute/AlphaMark.php' => $this->markAttributeSource($namespace, 'AlphaMark'),
        ];

        if ($reverse) {
            $files = array_reverse($files, true);
        }

        foreach ($files as $relativePath => $contents) {
            $this->writePhpFile($appDir, $relativePath, $contents);
        }
    }

    /** 値を1つ受け取るコンポーネント属性を app/Attribute/{$name}.php に書く。 */
    private function writeMarkAttribute(string $appDir, string $namespace, string $name): void
    {
        $this->writePhpFile($appDir, "Attribute/{$name}.php", $this->markAttributeSource($namespace, $name));
    }

    private function markAttributeSource(string $namespace, string $name): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Attribute;

use TMSWP\\Core\\Attribute\\ComponentAttributeInterface;
use TMSWP\\Core\\Hook\\HookInitializer;
use TMSWP\\Core\\Kernel\\KernelContext;

#[\\Attribute(\\Attribute::TARGET_CLASS)]
final class {$name} implements ComponentAttributeInterface
{
    public function __construct(public mixed \$value = null) {}

    public function hookName(KernelContext \$context): string
    {
        return 'init';
    }

    public function hookPriority(): int
    {
        return 10;
    }

    public function initializerClass(): string
    {
        return HookInitializer::class;
    }
}
PHP;
    }

    private function realPath(string $path): string
    {
        $realPath = realpath($path);
        self::assertIsString($realPath);

        return $realPath;
    }

    private function runPhp(string $script, string $root, string $namespace): string
    {
        $process = proc_open(
            [PHP_BINARY, $script, self::composerAutoloadFile(), $root, $namespace],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), "render script failed: {$stderr}");
        self::assertIsString($stdout);

        return $stdout;
    }

    private function scanFailure(string $namespace, string $appDir): DiscoveryException
    {
        try {
            $this->scan($namespace, $appDir);
        } catch (DiscoveryException $exception) {
            return $exception;
        }

        self::fail('DiscoveryException was not thrown');
    }

    private function scan(string $namespace, string $appDir): DiscoveryResult
    {
        $config = KernelConfig::theme($appDir, $namespace, true);

        return (new Discovery($config, new DiscoveryFile($config)))->scan();
    }

    private function runtimeNamespace(): string
    {
        return 'Example\\Tests\\RuntimeApp' . uniqid();
    }

    /**
     * @param ArrayObject<int, string>|null $loaded 渡すと、読み込んだクラス名を読み込んだ順に記録する
     */
    private function registerAppAutoloader(string $namespace, string $appDir, ?ArrayObject $loaded = null): void
    {
        $prefix = $namespace . '\\';
        $loader = static function (string $class) use ($appDir, $prefix, $loaded): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, \strlen($prefix))) . '.php';
            $file = $appDir . DIRECTORY_SEPARATOR . $relative;

            if (!is_file($file)) {
                return;
            }

            $loaded?->append($class);
            require $file;
        };

        spl_autoload_register($loader);
        $this->autoloaders[] = $loader;
    }

    private function writePhpFile(string $appDir, string $relativePath, string $contents): void
    {
        $file = $appDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $dir = \dirname($file);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("fixture ディレクトリを作成できません: {$dir}");
        }

        $bytes = file_put_contents($file, $contents);

        if ($bytes === false) {
            self::fail("fixture ファイルを書き込めません: {$file}");
        }
    }
}
