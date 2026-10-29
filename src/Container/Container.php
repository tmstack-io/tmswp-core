<?php

declare(strict_types=1);

namespace TMSWP\Core\Container;

use Closure;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;
use TMSWP\Core\Exception\CircularDependencyException;
use TMSWP\Core\Exception\ContainerException;
use TMSWP\Core\Exception\NotFoundException;

final class Container implements ContainerInterface
{
    /** @var array<string, Closure(self): mixed|string> */
    private array $definitions = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /** @var list<string> */
    private array $resolving = [];

    /**
     * 共有エントリを登録する。id がクラス名・interface 名なら、登録の時点で型を確かめる。
     * - object: その型のインスタンスであること
     * - string(クラス名の束縛): 束縛先が実在し、その型か、その型のサブクラス・実装であること
     * - Closure(factory): 結果の型は get() の時点で確かめる
     *
     * @throws ContainerException 解決済みidへの再登録・型の合わない登録
     */
    public function set(string $id, object|string $concrete): void
    {
        $resolvedId = $this->resolveAlias($id);

        if (\array_key_exists($resolvedId, $this->instances)) {
            throw new ContainerException("cannot redefine resolved container entry '{$resolvedId}'");
        }

        if ($concrete instanceof Closure) {
            $this->definitions[$resolvedId] = $concrete;

            return;
        }

        foreach (array_unique([$resolvedId, $id]) as $typeId) {
            if (!self::isTypeName($typeId)) {
                continue;
            }

            if (\is_string($concrete) && !(self::isTypeName($concrete) && is_a($concrete, $typeId, true))) {
                throw new ContainerException("container entry '{$typeId}' cannot be bound to '{$concrete}', which is not '{$typeId}' or its subtype");
            }

            if (\is_object($concrete) && !$concrete instanceof $typeId) {
                throw new ContainerException("container entry '{$typeId}' cannot be set to " . $concrete::class . ", which is not an instance of '{$typeId}'");
            }
        }

        if (\is_string($concrete)) {
            $this->definitions[$resolvedId] = $concrete;

            return;
        }

        $this->instances[$resolvedId] = $concrete;
    }

    /**
     * 別名を登録する(単段のみ)。alias 名がクラス名・interface 名なら、alias 先の登録内容と型が合うかを登録の時点で確かめる。
     * alias 先が factory や未登録のサービス名で型が分からない場合は、get() の時点で確かめる。
     *
     * @throws ContainerException 自己参照・alias の連鎖・解決済みidへの再登録・型の合わない alias
     */
    public function alias(string $alias, string $id): void
    {
        if ($alias === $id) {
            throw new ContainerException("container alias '{$alias}' cannot reference itself");
        }

        if (\array_key_exists($alias, $this->instances)) {
            throw new ContainerException("cannot alias resolved container entry '{$alias}'");
        }

        if (\array_key_exists($id, $this->aliases)) {
            throw new ContainerException("container alias '{$alias}' cannot reference another alias '{$id}'");
        }

        if (self::isTypeName($alias) && !$this->isEntryCompatibleWith($id, $alias)) {
            throw new ContainerException("container alias '{$alias}' cannot reference '{$id}', which does not resolve to '{$alias}'");
        }

        $this->aliases[$alias] = $id;
    }

    /**
     * id を解決して共有インスタンスを返す。
     *
     * クラス名・interface 名の id は、その型のインスタンスに解決されることを Container が保証する
     * (違えば ContainerException)。そのため呼び出し側は戻り値の型を確かめなくてよい。
     *
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @throws NotFoundException 解決できない id
     * @throws CircularDependencyException 循環依存
     * @throws ContainerException 解決不能なコンストラクタ引数・factory の失敗・型の食い違い等
     *
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed
    {
        $resolvedId = $this->resolveAlias($id);

        if (\array_key_exists($resolvedId, $this->instances)) {
            return $this->assertResolvedType($id, $resolvedId, $this->instances[$resolvedId]);
        }

        if (\in_array($resolvedId, $this->resolving, true)) {
            $chain = implode(' -> ', [...$this->resolving, $resolvedId]);

            throw new CircularDependencyException("circular dependency: {$chain}");
        }

        $this->resolving[] = $resolvedId;

        try {
            if (\array_key_exists($resolvedId, $this->definitions)) {
                $definition = $this->definitions[$resolvedId];
                if ($definition instanceof Closure) {
                    $value = $this->callFactory($resolvedId, $definition);
                } else {
                    if (!class_exists($definition)) {
                        throw new NotFoundException("defined class '{$definition}' not found for '{$resolvedId}'");
                    }

                    $value = $this->instantiate($definition);
                }
            } elseif (class_exists($resolvedId)) {
                $value = $this->instantiate($resolvedId);
            } else {
                throw new NotFoundException("no entry or class found for '{$resolvedId}'");
            }

            // 要求された id と alias 解決後の id の両方の確認を通った値だけを共有する(失敗した値は保存しない)
            $this->instances[$resolvedId] = $this->assertResolvedType($id, $resolvedId, $value);

            return $this->instances[$resolvedId];
        } finally {
            array_pop($this->resolving);
        }
    }

    /**
     * get() が id を解決できる場合だけ true を返す。判定はインスタンスを作らずに行う。
     * - 解決済み、または登録済みのインスタンスがある
     * - factory(Closure)が登録されている。factory は実行しないため、factory が投げれば get() は失敗しうる
     * - クラス名の束縛先、または未登録の id そのものが、インスタンス化できる実在のクラスで、
     *   コンストラクタの引数をすべて autowire の規則(get() と共有)で解決できる。循環依存は false
     */
    public function has(string $id): bool
    {
        // memo はこの呼び出しの中だけで使う(登録を変えた後の has() に古い判定を持ち越さない)
        $memo = [];

        return $this->canResolve($id, $this->resolving, $memo);
    }

    private function resolveAlias(string $id): string
    {
        if (\array_key_exists($id, $this->aliases)) {
            return $this->aliases[$id];
        }

        return $id;
    }

    private static function isTypeName(string $id): bool
    {
        return class_exists($id) || interface_exists($id);
    }

    /**
     * has() の本体。判定の結果を、渡された id(alias 解決前。要求された id の型の制約に結果が依存するため)ごとに
     * $memo へ覚え、共有する依存を経路ごとに探索し直さない。
     *
     * 循環で得た false も覚えてよい。ある id の解決の途中に循環があれば、どこから get() しても
     * CircularDependencyException で失敗するため、その id を false とすることは入口によらず正しい。
     *
     * @param list<string> $visiting 解決中の id(get() の解決スタックに相当。循環の検出に使う)
     * @param array<string, bool> $memo この has() の呼び出しの中で判定済みの id
     */
    private function canResolve(string $id, array $visiting, array &$memo): bool
    {
        if (!\array_key_exists($id, $memo)) {
            $memo[$id] = $this->canResolveUncached($id, $visiting, $memo);
        }

        return $memo[$id];
    }

    /**
     * get() と同じ順で解決の経路をたどり、インスタンスを作らず factory も実行せずに、解決できるかを判定する。
     *
     * @param list<string> $visiting
     * @param array<string, bool> $memo
     */
    private function canResolveUncached(string $id, array $visiting, array &$memo): bool
    {
        $resolvedId = $this->resolveAlias($id);

        if (\array_key_exists($resolvedId, $this->instances)) {
            $value = $this->instances[$resolvedId];

            return self::isValueOfType($value, $resolvedId) && self::isValueOfType($value, $id);
        }

        if (\in_array($resolvedId, $visiting, true)) {
            return false;
        }

        if (\array_key_exists($resolvedId, $this->definitions)) {
            $definition = $this->definitions[$resolvedId];

            if ($definition instanceof Closure) {
                return true;
            }

            $class = $definition;
        } else {
            $class = $resolvedId;
        }

        if (!class_exists($class) || !self::isClassOfType($class, $resolvedId) || !self::isClassOfType($class, $id)) {
            return false;
        }

        $plan = $this->autowirePlan($class);

        if (\is_string($plan)) {
            return false;
        }

        foreach ($plan as $argument) {
            if ($argument['dependency'] !== null && !$this->canResolve($argument['dependency'], [...$visiting, $resolvedId], $memo)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 登録済みのエントリ(alias 解決前の正規 id)が $type のインスタンスに解決されうるかを、分かる範囲で判定する。
     * factory や未登録のサービス名のように型が分からないものは true を返し、get() の時点の確認に任せる。
     */
    private function isEntryCompatibleWith(string $id, string $type): bool
    {
        if (\array_key_exists($id, $this->instances)) {
            return self::isValueOfType($this->instances[$id], $type);
        }

        if (\array_key_exists($id, $this->definitions)) {
            $definition = $this->definitions[$id];

            return $definition instanceof Closure || is_a($definition, $type, true);
        }

        if (self::isTypeName($id)) {
            return is_a($id, $type, true);
        }

        return true;
    }

    /** id がクラス名・interface 名でなければ型の制約は無い */
    private static function isValueOfType(mixed $value, string $id): bool
    {
        return $value instanceof $id || !self::isTypeName($id);
    }

    /** id がクラス名・interface 名でなければ型の制約は無い */
    private static function isClassOfType(string $class, string $id): bool
    {
        return !self::isTypeName($id) || is_a($class, $id, true);
    }

    /**
     * factory を呼ぶ。factory の中で投げられた例外は、id を含む ContainerException で包む。
     * 入れ子の解決で既に PSR-11 の例外になっているものは包まずにそのまま投げ直す(previous は 1 段に保つ)。
     *
     * @param Closure(self): mixed $factory
     */
    private function callFactory(string $id, Closure $factory): mixed
    {
        try {
            return $factory($this);
        } catch (ContainerExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ContainerException(
                "factory for container entry '{$id}' threw " . $exception::class . ": {$exception->getMessage()}",
                0,
                $exception,
            );
        }
    }

    /**
     * 解決した値が、要求された id と alias 解決後の id の両方の型に合うことを確かめる。
     * クラス名でない id(サービス名等)は確かめない。
     */
    private function assertResolvedType(string $id, string $resolvedId, mixed $value): mixed
    {
        foreach (array_unique([$resolvedId, $id]) as $typeId) {
            if (!self::isValueOfType($value, $typeId)) {
                throw new ContainerException(
                    "container entry '{$typeId}' resolved to " . get_debug_type($value) . ", which is not an instance of '{$typeId}'",
                );
            }
        }

        return $value;
    }

    /**
     * autowire でコンストラクタの各引数をどう得るかを決める。get()(instantiate)と has() が共有する唯一の規則。
     * - クラス・interface 型(ReflectionNamedType の非組み込み型)の引数: その型を id として解決する(dependency)
     * - それ以外で既定値がある引数: 既定値
     * - それ以外で null を許す引数: null
     * - 可変長引数: 渡さない
     * - 上記のどれでもない引数(既定値の無い組み込み型、union 型等): autowire できない
     *
     * @param class-string $class
     *
     * @return list<array{parameter: ReflectionParameter, dependency: string|null}>|string 計画、または autowire できない理由
     */
    private function autowirePlan(string $class): array|string
    {
        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            return "class '{$class}' is not instantiable";
        }

        $plan = [];

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isVariadic()) {
                continue;
            }

            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $plan[] = ['parameter' => $parameter, 'dependency' => $type->getName()];
                continue;
            }

            if ($parameter->isDefaultValueAvailable() || ($type !== null && $type->allowsNull())) {
                $plan[] = ['parameter' => $parameter, 'dependency' => null];
                continue;
            }

            return "unresolvable parameter \${$parameter->getName()} of {$class}::__construct(); register the entry explicitly or add a default value";
        }

        return $plan;
    }

    /**
     * @param class-string $class
     */
    private function instantiate(string $class): object
    {
        $plan = $this->autowirePlan($class);

        if (\is_string($plan)) {
            throw new ContainerException($plan);
        }

        $arguments = [];

        foreach ($plan as ['parameter' => $parameter, 'dependency' => $dependency]) {
            if ($dependency !== null) {
                $arguments[] = $this->get($dependency);
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                $arguments[] = null;
            }
        }

        return (new ReflectionClass($class))->newInstanceArgs($arguments);
    }
}
