<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use SplFileInfo;
use Throwable;
use TMSWP\Core\Attribute\Action;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\ComponentAttributeInterface;
use TMSWP\Core\Attribute\Filter;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Block\BlockScanner;
use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Hook\Hook;
use UnitEnum;

final class Discovery
{
    public function __construct(
        private readonly KernelConfig $config,
        private readonly DiscoveryFile $file,
    ) {}

    /**
     * debug 時は appDir を走査し、それ以外は探索結果ファイルを読む(走査しない)。
     *
     * @throws DiscoveryException 走査の失敗、または探索結果ファイルが無い・構成と合わない場合
     */
    public function discover(): DiscoveryResult
    {
        return $this->config->debug ? $this->scan() : $this->file->load();
    }

    /**
     * appDirと、テーマ・プラグインのルートの`build/blocks/`(BlockScanner)を走査する。失敗はdebugに関係なく
     * DiscoveryExceptionにし、途中までの結果は返さない。
     *
     * @throws DiscoveryException
     */
    public function scan(): DiscoveryResult
    {
        $components = [];
        $hookMethods = [];
        /** @var array<string, string> $files クラス名 => 定義ファイル(エラーメッセージ用) */
        $files = [];

        foreach ($this->candidateFiles() as $relativePath => $file) {
            $fqcn = $this->config->appNamespace . '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', substr($relativePath, 0, -4));

            try {
                [$classComponents, $classHookMethods] = $this->scanClass($file, $fqcn);
            } catch (DiscoveryException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                // 属性クラスの autoload(getAttributes の IS_INSTANCEOF)や属性引数の定数式の評価で起きる Error 等
                $this->fail("failed to read {$fqcn}: {$exception->getMessage()}", $file, $fqcn, $exception);
            }

            $components = [...$components, ...$classComponents];
            $hookMethods = [...$hookMethods, ...$classHookMethods];
            $files[$fqcn] = $file;
        }

        $this->assertEnvironmentIndependent($components, $hookMethods, $files);

        // 並べ順はバイト単位の strcmp で決める(配列の <=> は数字だけの文字列を数値として比べるため使わない)
        usort($components, static function (DiscoveredComponent $a, DiscoveredComponent $b): int {
            $order = strcmp($a->class, $b->class);

            return $order !== 0 ? $order : strcmp($a->attributeClass, $b->attributeClass);
        });
        usort($hookMethods, static function (DiscoveredHookMethod $a, DiscoveredHookMethod $b): int {
            foreach ([[$a->class, $b->class], [$a->method, $b->method], [$a->hook, $b->hook]] as [$left, $right]) {
                $order = strcmp($left, $right);

                if ($order !== 0) {
                    return $order;
                }
            }

            $order = $a->priority <=> $b->priority;

            // 1つのメソッドに同じフックの #[Action] と #[Filter] を付けた場合も順序を決める
            return $order !== 0 ? $order : strcmp($a->kind->value, $b->kind->value);
        });

        return new DiscoveryResult($components, $hookMethods, (new BlockScanner($this->config))->scan());
    }

    /**
     * 候補クラス1つを走査し、そのクラスのコンポーネントとフックメソッドを返す。
     *
     * @return array{list<DiscoveredComponent>, list<DiscoveredHookMethod>}
     */
    private function scanClass(string $file, string $fqcn): array
    {
        $reflection = $this->reflectCandidate($file, $fqcn);

        if (!$reflection instanceof ReflectionClass) {
            return [[], []];
        }

        $components = [];
        $hookMethods = [];
        $className = $reflection->getName();
        $this->assertOptionSchemaTargets($reflection, $file, $fqcn);
        $componentAttributes = $reflection->getAttributes(ComponentAttributeInterface::class, ReflectionAttribute::IS_INSTANCEOF);
        $hasAsHook = $reflection->getAttributes(AsHook::class) !== [];

        if ($hasAsHook && !$reflection->isSubclassOf(Hook::class)) {
            $this->fail('#[AsHook] class must extend ' . Hook::class, $file, $fqcn);
        }

        $hasRegisterOverride = $hasAsHook && $reflection->getMethod('register')->getDeclaringClass()->getName() !== Hook::class;
        $hasHookMethod = false;

        if ($componentAttributes !== [] && !$reflection->isInstantiable() && !$reflection->isEnum()) {
            $this->fail("component attribute on non-instantiable class {$fqcn}", $file, $fqcn);
        }

        foreach ($componentAttributes as $attribute) {
            $arguments = $attribute->getArguments();

            if (!$this->isExportable($arguments)) {
                $this->fail('attribute arguments must be exportable to the discovery file (scalar/array/enum)', $file, $fqcn);
            }

            try {
                $instance = $attribute->newInstance();
            } catch (Throwable $exception) {
                $this->fail("failed to instantiate attribute on {$fqcn}: {$exception->getMessage()}", $file, $fqcn, $exception);
            }

            if ($instance instanceof AsHook && !$hasRegisterOverride) {
                continue;
            }

            $components[] = new DiscoveredComponent($className, $attribute->getName(), $arguments);
        }

        foreach ($reflection->getMethods() as $method) {
            $methodAttributes = [
                ...$method->getAttributes(Action::class),
                ...$method->getAttributes(Filter::class),
            ];

            if ($methodAttributes === []) {
                continue;
            }

            $hasHookMethod = true;

            if (!$hasAsHook) {
                $this->fail("method-level #[Action]/#[Filter] requires #[AsHook] on class {$fqcn}", $file, $fqcn);
            }

            if ($method->isStatic() || !$method->isPublic() || $this->hasVariadicParameter($method)) {
                $this->fail("method-level hook attributes require public non-static methods without variadic parameters: {$fqcn}::{$method->getName()}", $file, $fqcn);
            }

            foreach ($methodAttributes as $methodAttribute) {
                try {
                    $hookAttribute = $methodAttribute->newInstance();
                } catch (Throwable $exception) {
                    $this->fail("failed to instantiate hook attribute on {$fqcn}::{$method->getName()}: {$exception->getMessage()}", $file, $fqcn, $exception);
                }

                $hookMethods[] = new DiscoveredHookMethod(
                    $className,
                    $method->getName(),
                    $hookAttribute->hook,
                    $hookAttribute->priority,
                    $method->getNumberOfParameters(),
                    $hookAttribute instanceof Filter ? HookMethodKind::Filter : HookMethodKind::Action,
                );
            }
        }

        if ($hasAsHook && !$hasHookMethod && !$hasRegisterOverride) {
            $this->fail("hook class {$fqcn} registers no hooks", $file, $fqcn);
        }

        return [$components, $hookMethods];
    }

    /**
     * 走査対象のクラス候補ファイルを、appDir からの相対パス => 実パスで返す。相対パスの strcmp 順に並べる。
     *
     * @return array<string, string>
     */
    private function candidateFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($this->config->appDir, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $current): bool {
                    if (!$current->isDir()) {
                        return true;
                    }

                    $baseName = $current->getBasename();

                    return !str_starts_with($baseName, '.') && $baseName !== 'vendor' && $baseName !== 'node_modules';
                },
            ),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            if (preg_match('/^[A-Z][A-Za-z0-9]*\.php$/', $fileInfo->getBasename()) !== 1) {
                continue;
            }

            $realPath = $fileInfo->getRealPath();

            if ($realPath === false) {
                throw new DiscoveryException("cannot resolve the real path of {$fileInfo->getPathname()}");
            }

            $files[substr($fileInfo->getPathname(), \strlen($this->config->appDir) + 1)] = $realPath;
        }

        uksort($files, strcmp(...));

        return $files;
    }

    /**
     * @return ReflectionClass<object>|null
     */
    private function reflectCandidate(string $file, string $fqcn): ?ReflectionClass
    {
        try {
            // オートローダ経由の読み込みは class_exists() の1回だけにする(読み込んだファイルが別の名前を定義していた場合に
            // 2回目の autoload で同じファイルを再度読み込まない)。ParseError や未定義の親クラス等の Error もここで起きる
            $exists = class_exists($fqcn) || interface_exists($fqcn, false) || trait_exists($fqcn, false) || enum_exists($fqcn, false);
        } catch (Throwable $exception) {
            $this->fail("failed to autoload {$fqcn}: {$exception->getMessage()}", $file, $fqcn, $exception);
        }

        if (!$exists) {
            $this->fail(
                "file {$file} is expected to define class {$fqcn}; check the PSR-4 autoload mapping '{$this->config->appNamespace}\\' => '{$this->config->appDir}' in composer.json and run 'composer dump-autoload' if the mapping looks correct",
                $file,
                $fqcn,
            );
        }

        $reflection = new ReflectionClass($fqcn);

        if ($reflection->isInterface() || $reflection->isTrait()) {
            return null;
        }

        if ($reflection->getName() !== $fqcn) {
            $this->fail("class name case mismatch for {$fqcn}; reflection returned {$reflection->getName()}", $file, $fqcn);
        }

        $declaredFile = $reflection->getFileName();

        if ($declaredFile === false || realpath($declaredFile) !== $file) {
            $this->fail("class {$fqcn} was loaded from a different file; expected {$file}", $file, $fqcn);
        }

        return $reflection;
    }

    private function hasVariadicParameter(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function assertOptionSchemaTargets(ReflectionClass $reflection, string $file, string $fqcn): void
    {
        if ($reflection->getAttributes(OptionSchema::class) !== []) {
            $this->fail("#[OptionSchema] is only allowed on enum cases: {$fqcn}", $file, $fqcn);
        }

        $className = $reflection->getName();
        $enum = $reflection->isEnum() && is_subclass_of($className, UnitEnum::class) ? new ReflectionEnum($className) : null;
        $isOptionGroup = $reflection->getAttributes(AsOptionGroup::class) !== [];

        foreach ($reflection->getReflectionConstants() as $constant) {
            if ($constant->getAttributes(OptionSchema::class) === []) {
                continue;
            }

            if (!$enum instanceof ReflectionEnum || !$enum->hasCase($constant->getName())) {
                $this->fail("#[OptionSchema] is only allowed on enum cases: {$fqcn}::{$constant->getName()}", $file, $fqcn);
            }

            // #[AsOptionGroup] の無い enum はオプショングループとして探索されず、ケースの宣言が使われないため黙って無視しない
            if (!$isOptionGroup) {
                $this->fail("#[OptionSchema] requires #[AsOptionGroup] on the enum: {$fqcn}::{$constant->getName()}", $file, $fqcn);
            }
        }
    }

    /**
     * 探索結果ファイルに書き出す文字列(属性引数のキーと値、フック名)が、環境によって変わる絶対パスを含まないことを確かめる。
     * class・attributeClass・method は PHP の識別子のため対象にしない。
     *
     * @param list<DiscoveredComponent> $components
     * @param list<DiscoveredHookMethod> $hookMethods
     * @param array<string, string> $files
     */
    private function assertEnvironmentIndependent(array $components, array $hookMethods, array $files): void
    {
        $roots = $this->environmentRoots();

        foreach ($components as $component) {
            foreach ($this->strings($component->attributeArguments) as $value) {
                $root = $this->environmentRootIn($value, $roots);

                if ($root !== null) {
                    $this->fail(
                        "attribute argument of {$component->class} contains the environment-dependent path {$root}: '{$value}'; write paths relative to the theme or plugin root",
                        $files[$component->class] ?? $component->class,
                        $component->class,
                    );
                }
            }
        }

        foreach ($hookMethods as $hookMethod) {
            $root = $this->environmentRootIn($hookMethod->hook, $roots);

            if ($root !== null) {
                $this->fail(
                    "hook name of {$hookMethod->class}::{$hookMethod->method} contains the environment-dependent path {$root}: '{$hookMethod->hook}'",
                    $files[$hookMethod->class] ?? $hookMethod->class,
                    $hookMethod->class,
                );
            }
        }
    }

    /**
     * 配列のキーと値(再帰的)に含まれる文字列をすべて返す。
     *
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (\is_string($value)) {
            return [$value];
        }

        if (!\is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $key => $entry) {
            if (\is_string($key)) {
                $strings[] = $key;
            }

            $strings = [...$strings, ...$this->strings($entry)];
        }

        return $strings;
    }

    /**
     * 文字列に既知のルートが現れ、その直後が文字列の終わりか区切り文字(/ または \)であれば、そのルートを返す。
     * ルート '/srv/a' に対し '/srv/a/x.php' は該当し、'/srv/abc' や '/srv/a-b' は該当しない。
     *
     * @param list<non-empty-string> $roots
     */
    private function environmentRootIn(string $value, array $roots): ?string
    {
        foreach ($roots as $root) {
            $offset = 0;

            while (($position = strpos($value, $root, $offset)) !== false) {
                $next = $value[$position + \strlen($root)] ?? null;

                if ($next === null || $next === '/' || $next === '\\') {
                    return $root;
                }

                $offset = $position + 1;
            }
        }

        return null;
    }

    /**
     * 環境によって変わる既知のルートを返す。いずれも末尾の区切り文字(/ または \)を除き、空になるもの('/' 等)と重複は除く。
     *
     * - Kernel のルート(appDir の親ディレクトリ): 実パスに正規化する。appDir は実在が前提のため、解決できなければ例外にする。
     * - ABSPATH・WP_CONTENT_DIR(定義されている場合のみ): 設定された値そのものと、realpath() で解決できればその実パスの両方。
     *   解決できなくても例外にしない。WP_CONTENT_DIR は、プラグイン・テーマ等を外に置く構成ではディレクトリが無くても WordPress が起動するため。
     *   ABSPATH は存在しなければ WordPress 自体が起動しないが、扱いを WP_CONTENT_DIR と揃え、存在を探索の前提にしない。
     *
     * @return list<non-empty-string>
     */
    private function environmentRoots(): array
    {
        $kernelRoot = \dirname($this->config->appDir);
        $realKernelRoot = realpath($kernelRoot);

        if ($realKernelRoot === false) {
            throw new DiscoveryException("cannot resolve the real path of Kernel root: {$kernelRoot}");
        }

        $candidates = [$realKernelRoot];

        foreach (['ABSPATH', 'WP_CONTENT_DIR'] as $constant) {
            if (\defined($constant)) {
                $value = \constant($constant);

                if (!\is_string($value)) {
                    throw new DiscoveryException("{$constant} must be a string path");
                }

                $candidates[] = $value;
                $realPath = realpath($value);

                if ($realPath !== false) {
                    $candidates[] = $realPath;
                }
            }
        }

        $roots = [];

        foreach ($candidates as $candidate) {
            $root = rtrim($candidate, '/\\');

            if ($root !== '' && !\in_array($root, $roots, true)) {
                $roots[] = $root;
            }
        }

        return $roots;
    }

    private function isExportable(mixed $value): bool
    {
        if ($value === null || \is_scalar($value) || $value instanceof UnitEnum) {
            return true;
        }

        if (!\is_array($value)) {
            return false;
        }

        foreach ($value as $entry) {
            if (!$this->isExportable($entry)) {
                return false;
            }
        }

        return true;
    }

    private function fail(string $message, string $file, string $class, ?Throwable $previous = null): never
    {
        throw new DiscoveryException("{$message}; file: {$file}; class: {$class}", previous: $previous);
    }
}
