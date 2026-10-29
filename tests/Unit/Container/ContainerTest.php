<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Container;

use Closure;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerExceptionInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use TMSWP\Core\Container\Container;
use TMSWP\Core\Exception\CircularDependencyException;
use TMSWP\Core\Exception\ContainerException;
use TMSWP\Core\Exception\NotFoundException;
use TMSWP\Testing\UnitTestCase;
use TypeError;

final class ContainerTest extends UnitTestCase
{
    public function testItResolvesInstancesAliasesFactoriesAndAutowiring(): void
    {
        $container = new Container();
        $dependency = new ContainerDependency();

        $container->set(ContainerDependency::class, $dependency);
        $container->set('service', ContainerService::class);
        $container->set(FactoryService::class, static fn(): FactoryService => new FactoryService());
        $container->alias(ContainerService::class, 'service');

        $service = $container->get(ContainerService::class);

        self::assertInstanceOf(ContainerService::class, $service);
        self::assertSame($dependency, $service->dependency);
        self::assertSame($service, $container->get('service'));
        self::assertInstanceOf(FactoryService::class, $container->get(FactoryService::class));
    }

    public function testItFailsOnCircularDependencies(): void
    {
        $this->expectException(CircularDependencyException::class);

        (new Container())->get(ContainerCircularA::class);
    }

    public function testItFailsOnUnknownEntries(): void
    {
        $this->expectException(NotFoundException::class);

        (new Container())->get('TMSWP\\Core\\Tests\\Unit\\Container\\MissingContainerEntry');
    }

    public function testPublicApiIsLimitedToSetAliasGetAndHas(): void
    {
        $methods = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(Container::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        // factory() / make() のような、共有しない生成の API は無い
        self::assertSame(['alias', 'get', 'has', 'set'], $methods);
    }

    /** @return iterable<string, array{Closure(Container): void, string}> */
    public static function stringBindings(): iterable
    {
        yield 'サービス名からクラスへの束縛' => [static function (Container $container): void {
            $container->set('service', FactoryService::class);
        }, 'service'];
        yield 'interface から実装への束縛' => [static function (Container $container): void {
            $container->set(ContainerContract::class, ContainerContractImplementation::class);
        }, ContainerContract::class];
    }

    /**
     * string 束縛の解決結果は共有され、get() のたびに作り直さない。
     *
     * @param Closure(Container): void $configure
     */
    #[DataProvider('stringBindings')]
    public function testStringBindingsAreShared(Closure $configure, string $id): void
    {
        $container = new Container();
        $configure($container);

        $first = $container->get($id);

        self::assertIsObject($first);
        self::assertSame($first, $container->get($id));
    }

    public function testCircularDependencyOfThreeClassesReportsTheWholeChain(): void
    {
        try {
            (new Container())->get(ContainerCycleA::class);
            self::fail('CircularDependencyException was not thrown');
        } catch (CircularDependencyException $exception) {
            self::assertSame(
                'circular dependency: ' . implode(' -> ', [ContainerCycleA::class, ContainerCycleB::class, ContainerCycleC::class, ContainerCycleA::class]),
                $exception->getMessage(),
            );
        }
    }

    /** @return iterable<string, array{Closure(Container): void, string}> */
    public static function forbiddenRegistrations(): iterable
    {
        yield '登録済みのインスタンスへの再登録' => [static function (Container $container): void {
            $container->set(ContainerDependency::class, new ContainerDependency());
            $container->set(ContainerDependency::class, static fn(): ContainerDependency => new ContainerDependency());
        }, "cannot redefine resolved container entry '" . ContainerDependency::class . "'"];
        yield 'get() で解決した id への再登録' => [static function (Container $container): void {
            $container->set('service', FactoryService::class);
            $container->get('service');
            $container->set('service', static fn(): FactoryService => new FactoryService());
        }, "cannot redefine resolved container entry 'service'"];
        yield 'alias を経由した、解決済みの id への再登録' => [static function (Container $container): void {
            $container->set('service', FactoryService::class);
            $container->alias(FactoryService::class, 'service');
            $container->get(FactoryService::class);
            $container->set(FactoryService::class, new FactoryService());
        }, "cannot redefine resolved container entry 'service'"];
        yield '解決済みの id を alias にする' => [static function (Container $container): void {
            $container->set('service', FactoryService::class);
            $container->get('service');
            $container->alias('service', FactoryService::class);
        }, "cannot alias resolved container entry 'service'"];
        yield '自己参照の alias' => [static function (Container $container): void {
            $container->alias('service', 'service');
        }, "container alias 'service' cannot reference itself"];
        yield 'alias を指す alias(連鎖)' => [static function (Container $container): void {
            $container->set('service', FactoryService::class);
            $container->alias('first', 'service');
            $container->alias('second', 'first');
        }, "container alias 'second' cannot reference another alias 'first'"];
    }

    /**
     * @param Closure(Container): void $register
     */
    #[DataProvider('forbiddenRegistrations')]
    public function testForbiddenRegistrationsFail(Closure $register, string $message): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage($message);

        $register(new Container());
    }

    /**
     * has() が false の id は、get() でも解決できない。
     *
     * @return iterable<string, array{Closure(Container): void, string}>
     */
    public static function unresolvableEntries(): iterable
    {
        yield 'required scalar argument' => [static function (): void {}, ContainerNeedsValue::class];
        yield 'unbound interface dependency' => [static function (): void {}, ContainerNeedsContract::class];
        yield 'circular dependency' => [static function (): void {}, ContainerCircularA::class];
        yield 'binding to a missing class' => [
            static function (Container $container): void {
                $container->set('missing-binding', 'TMSWP\\Core\\Tests\\Unit\\Container\\MissingContainerEntry');
            },
            'missing-binding',
        ];
        yield 'binding to an interface' => [
            static function (Container $container): void {
                $container->set('interface-binding', ContainerContract::class);
            },
            'interface-binding',
        ];
        yield 'alias to a missing binding' => [
            static function (Container $container): void {
                $container->set('missing-binding', 'TMSWP\\Core\\Tests\\Unit\\Container\\MissingContainerEntry');
                $container->alias('alias-to-missing-binding', 'missing-binding');
            },
            'alias-to-missing-binding',
        ];
        yield 'unknown id' => [static function (): void {}, 'TMSWP\\Core\\Tests\\Unit\\Container\\MissingContainerEntry'];
    }

    /**
     * @param Closure(Container): void $configure
     */
    #[DataProvider('unresolvableEntries')]
    public function testHasIsFalseWhenGetCannotResolve(Closure $configure, string $id): void
    {
        $container = new Container();
        $configure($container);

        self::assertFalse($container->has($id));

        $this->expectException(ContainerExceptionInterface::class);

        $container->get($id);
    }

    /**
     * has() が true の id は、get() で解決できる。
     *
     * @return iterable<string, array{Closure(Container): void, string, class-string}>
     */
    public static function resolvableEntries(): iterable
    {
        yield 'autowire with a bound dependency' => [
            static function (Container $container): void {
                $container->set(ContainerContract::class, ContainerContractImplementation::class);
            },
            ContainerNeedsContract::class,
            ContainerNeedsContract::class,
        ];
        yield 'default and nullable arguments' => [static function (): void {}, ContainerOptionalArguments::class, ContainerOptionalArguments::class];
        yield 'interface bound to an implementation' => [
            static function (Container $container): void {
                $container->set(ContainerContract::class, ContainerContractImplementation::class);
            },
            ContainerContract::class,
            ContainerContractImplementation::class,
        ];
        yield 'class bound to a subclass' => [
            static function (Container $container): void {
                $container->set(ContainerContractBase::class, ContainerContractImplementation::class);
            },
            ContainerContractBase::class,
            ContainerContractImplementation::class,
        ];
        yield 'alias to an interface binding' => [
            static function (Container $container): void {
                $container->set(ContainerContract::class, ContainerContractImplementation::class);
                $container->alias('contract', ContainerContract::class);
            },
            'contract',
            ContainerContractImplementation::class,
        ];
        yield 'interface alias to an implementation' => [
            static function (Container $container): void {
                $container->alias(ContainerContract::class, ContainerContractImplementation::class);
            },
            ContainerContract::class,
            ContainerContractImplementation::class,
        ];
        yield 'registered instance' => [
            static function (Container $container): void {
                $container->set(ContainerContract::class, new ContainerContractImplementation());
            },
            ContainerContract::class,
            ContainerContractImplementation::class,
        ];
    }

    /**
     * @param Closure(Container): void $configure
     * @param class-string $expectedClass
     */
    #[DataProvider('resolvableEntries')]
    public function testHasIsTrueWhenGetResolves(Closure $configure, string $id, string $expectedClass): void
    {
        $container = new Container();
        $configure($container);

        self::assertTrue($container->has($id));
        self::assertInstanceOf($expectedClass, $container->get($id));
    }

    public function testDefaultAndNullableArgumentsAreUsedByAutowire(): void
    {
        $service = (new Container())->get(ContainerOptionalArguments::class);

        self::assertNull($service->count);
        self::assertSame('default', $service->name);
    }

    public function testHasDoesNotRunFactories(): void
    {
        $calls = 0;
        $container = new Container();
        $container->set('lazy', static function () use (&$calls): never {
            ++$calls;

            throw new RuntimeException('factory failed');
        });

        self::assertTrue($container->has('lazy'));
        self::assertSame(0, $calls);
    }

    public function testFactoryExceptionsAreWrappedInContainerExceptionWithTheId(): void
    {
        $container = new Container();
        $failure = new RuntimeException('factory failed');
        $container->set('failing-service', static fn(): never => throw $failure);

        try {
            $container->get('failing-service');
            self::fail('a factory failure must be rethrown as ContainerException');
        } catch (ContainerException $exception) {
            self::assertSame(ContainerException::class, $exception::class);
            self::assertStringContainsString("'failing-service'", $exception->getMessage());
            self::assertStringContainsString('factory failed', $exception->getMessage());
            self::assertSame($failure, $exception->getPrevious());
            self::assertNull($failure->getPrevious());
        }
    }

    /**
     * factory が投げる Error(Exception ではない Throwable)。
     *
     * @return iterable<string, array{Error}>
     */
    public static function factoryErrors(): iterable
    {
        yield 'TypeError' => [new TypeError('factory type error')];
        yield 'Error' => [new Error('factory error')];
    }

    #[DataProvider('factoryErrors')]
    public function testFactoryErrorsAreWrappedInContainerExceptionWithTheId(Error $failure): void
    {
        $container = new Container();
        $container->set('erroring-service', static fn(): never => throw $failure);

        try {
            $container->get('erroring-service');
            self::fail('an Error thrown by a factory must be rethrown as ContainerException');
        } catch (ContainerException $exception) {
            self::assertSame(ContainerException::class, $exception::class);
            self::assertStringContainsString("'erroring-service'", $exception->getMessage());
            self::assertStringContainsString($failure->getMessage(), $exception->getMessage());
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function testNestedFactoryFailuresAreWrappedOnlyOnce(): void
    {
        $container = new Container();
        $failure = new RuntimeException('inner failed');
        $container->set('inner', static fn(): never => throw $failure);
        $container->set('outer', static fn(Container $container): mixed => $container->get('inner'));

        try {
            $container->get('outer');
            self::fail('a nested factory failure must be rethrown');
        } catch (ContainerException $exception) {
            self::assertStringContainsString("'inner'", $exception->getMessage());
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function testContainerExceptionsFromFactoriesAreNotWrappedAgain(): void
    {
        $container = new Container();
        $container->set('needs-missing', static fn(Container $container): mixed => $container->get('missing'));
        $container->set('self-referencing', static fn(Container $container): mixed => $container->get('self-referencing'));

        try {
            $container->get('needs-missing');
            self::fail('NotFoundException must propagate');
        } catch (NotFoundException $exception) {
            self::assertSame(NotFoundException::class, $exception::class);
            self::assertNull($exception->getPrevious());
        }

        try {
            $container->get('self-referencing');
            self::fail('CircularDependencyException must propagate');
        } catch (CircularDependencyException $exception) {
            self::assertSame(CircularDependencyException::class, $exception::class);
            self::assertNull($exception->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{Closure(Container): void}>
     */
    public static function incompatibleRegistrations(): iterable
    {
        yield 'instance of another type' => [static function (Container $container): void {
            $container->set(FactoryService::class, new ContainerDependency());
        }];
        yield 'binding to an incompatible class' => [static function (Container $container): void {
            $container->set(ContainerContract::class, ContainerDependency::class);
        }];
        yield 'binding to a missing class' => [static function (Container $container): void {
            $container->set(ContainerContract::class, 'TMSWP\\Core\\Tests\\Unit\\Container\\MissingContainerEntry');
        }];
        yield 'alias to an incompatible instance' => [static function (Container $container): void {
            $container->set('dependency', new ContainerDependency());
            $container->alias(ContainerContract::class, 'dependency');
        }];
        yield 'alias to an incompatible binding' => [static function (Container $container): void {
            $container->set('dependency', ContainerDependency::class);
            $container->alias(ContainerContract::class, 'dependency');
        }];
        yield 'alias to an incompatible class' => [static function (Container $container): void {
            $container->alias(ContainerContract::class, ContainerDependency::class);
        }];
    }

    /**
     * @param Closure(Container): void $register
     */
    #[DataProvider('incompatibleRegistrations')]
    public function testIncompatibleRegistrationsFailWhenRegistered(Closure $register): void
    {
        $this->expectException(ContainerException::class);

        $register(new Container());
    }

    public function testFactoryResultsAreCheckedAgainstTheClassId(): void
    {
        $container = new Container();
        $container->set(FactoryService::class, static fn(): ContainerDependency => new ContainerDependency());

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("container entry 'TMSWP\\Core\\Tests\\Unit\\Container\\FactoryService' resolved to TMSWP\\Core\\Tests\\Unit\\Container\\ContainerDependency");

        $container->get(FactoryService::class);
    }

    /**
     * alias を経由しても、即値と factory の結果が同じ型の規則で扱われる。
     */
    public function testInstancesAndFactoriesAreCheckedAlikeThroughAliases(): void
    {
        // 型の合わない即値は登録の時点で、型の合わない factory の結果は get() の時点で失敗する
        $this->assertContainerException(static function (): void {
            (new Container())->set(FactoryService::class, new stdClass());
        });

        $factory = new Container();
        $factory->set(FactoryService::class, static fn(): stdClass => new stdClass());
        $factory->alias('service', FactoryService::class);
        self::assertTrue($factory->has('service'));
        $this->assertContainerException(static fn(): mixed => $factory->get('service'));
        $this->assertContainerException(static fn(): mixed => $factory->get(FactoryService::class));

        // 型の合う即値と factory は、alias からも同じインスタンスを返す
        $instance = new Container();
        $instance->set(FactoryService::class, new FactoryService());
        $instance->alias('service', FactoryService::class);
        $factory = new Container();
        $factory->set(FactoryService::class, static fn(): FactoryService => new FactoryService());
        $factory->alias('service', FactoryService::class);

        foreach ([$instance, $factory] as $container) {
            self::assertInstanceOf(FactoryService::class, $container->get('service'));
            self::assertSame($container->get(FactoryService::class), $container->get('service'));
        }
    }

    public function testCachedValuesAreCheckedAgainstTheRequestedId(): void
    {
        $container = new Container();
        $container->set('dependency', static fn(): ContainerDependency => new ContainerDependency());
        $container->alias(ContainerContract::class, 'dependency');

        self::assertInstanceOf(ContainerDependency::class, $container->get('dependency'));
        self::assertFalse($container->has(ContainerContract::class));
        $this->assertContainerException(static fn(): mixed => $container->get(ContainerContract::class));
    }

    public function testValuesFailingTheTypeCheckAreNotShared(): void
    {
        $calls = 0;
        $container = new Container();
        $container->set('service', static function () use (&$calls): stdClass {
            ++$calls;

            return new stdClass();
        });
        $container->alias(ContainerContract::class, 'service');

        $this->assertContainerException(static fn(): mixed => $container->get(ContainerContract::class));
        $this->assertContainerException(static fn(): mixed => $container->get(ContainerContract::class));
        self::assertSame(2, $calls);

        // 失敗した値は共有されていないので、正しい factory へ登録し直せる
        $container->set('service', static fn(): ContainerContractImplementation => new ContainerContractImplementation());

        self::assertInstanceOf(ContainerContractImplementation::class, $container->get(ContainerContract::class));
    }

    public function testHasDoesNotReexploreSharedDependencies(): void
    {
        // ContainerDagN は ContainerDag(N-1) と ContainerDag(N-2) に依存する。共有する依存を経路ごとに探索し直すと、
        // 判定の回数がフィボナッチ数で増えて終わらない
        $container = new Container();

        self::assertTrue($container->has(ContainerDag50::class));

        $dag = $container->get(ContainerDag50::class);
        self::assertSame($container->get(ContainerDag49::class), $dag->a);
        self::assertSame($container->get(ContainerDag48::class), $dag->b);
        self::assertSame($dag->a->a, $dag->b);
    }

    /**
     * @param Closure(): mixed $callback
     */
    private function assertContainerException(Closure $callback): void
    {
        try {
            $callback();
        } catch (ContainerException) {
            $this->addToAssertionCount(1);

            return;
        }

        self::fail('ContainerException must be thrown');
    }
}

final class ContainerDependency {}

interface ContainerContract {}

abstract class ContainerContractBase implements ContainerContract {}

final class ContainerContractImplementation extends ContainerContractBase {}

final readonly class ContainerNeedsValue
{
    public function __construct(
        public string $value,
    ) {}
}

final readonly class ContainerNeedsContract
{
    public function __construct(
        public ContainerContract $contract,
    ) {}
}

final readonly class ContainerOptionalArguments
{
    public function __construct(
        public ContainerDependency $dependency,
        public ?int $count,
        public string $name = 'default',
    ) {}
}

final readonly class ContainerService
{
    public function __construct(
        public ContainerDependency $dependency,
    ) {}
}

final class FactoryService {}

final readonly class ContainerCircularA
{
    public function __construct(
        public ContainerCircularB $dependency,
    ) {}
}

final readonly class ContainerCircularB
{
    public function __construct(
        public ContainerCircularA $dependency,
    ) {}
}

final readonly class ContainerCycleA
{
    public function __construct(
        public ContainerCycleB $dependency,
    ) {}
}

final readonly class ContainerCycleB
{
    public function __construct(
        public ContainerCycleC $dependency,
    ) {}
}

final readonly class ContainerCycleC
{
    public function __construct(
        public ContainerCycleA $dependency,
    ) {}
}

final class ContainerDag0 {}

final class ContainerDag1 {}

final readonly class ContainerDag2
{
    public function __construct(
        public ContainerDag1 $a,
        public ContainerDag0 $b,
    ) {}
}

final readonly class ContainerDag3
{
    public function __construct(
        public ContainerDag2 $a,
        public ContainerDag1 $b,
    ) {}
}

final readonly class ContainerDag4
{
    public function __construct(
        public ContainerDag3 $a,
        public ContainerDag2 $b,
    ) {}
}

final readonly class ContainerDag5
{
    public function __construct(
        public ContainerDag4 $a,
        public ContainerDag3 $b,
    ) {}
}

final readonly class ContainerDag6
{
    public function __construct(
        public ContainerDag5 $a,
        public ContainerDag4 $b,
    ) {}
}

final readonly class ContainerDag7
{
    public function __construct(
        public ContainerDag6 $a,
        public ContainerDag5 $b,
    ) {}
}

final readonly class ContainerDag8
{
    public function __construct(
        public ContainerDag7 $a,
        public ContainerDag6 $b,
    ) {}
}

final readonly class ContainerDag9
{
    public function __construct(
        public ContainerDag8 $a,
        public ContainerDag7 $b,
    ) {}
}

final readonly class ContainerDag10
{
    public function __construct(
        public ContainerDag9 $a,
        public ContainerDag8 $b,
    ) {}
}

final readonly class ContainerDag11
{
    public function __construct(
        public ContainerDag10 $a,
        public ContainerDag9 $b,
    ) {}
}

final readonly class ContainerDag12
{
    public function __construct(
        public ContainerDag11 $a,
        public ContainerDag10 $b,
    ) {}
}

final readonly class ContainerDag13
{
    public function __construct(
        public ContainerDag12 $a,
        public ContainerDag11 $b,
    ) {}
}

final readonly class ContainerDag14
{
    public function __construct(
        public ContainerDag13 $a,
        public ContainerDag12 $b,
    ) {}
}

final readonly class ContainerDag15
{
    public function __construct(
        public ContainerDag14 $a,
        public ContainerDag13 $b,
    ) {}
}

final readonly class ContainerDag16
{
    public function __construct(
        public ContainerDag15 $a,
        public ContainerDag14 $b,
    ) {}
}

final readonly class ContainerDag17
{
    public function __construct(
        public ContainerDag16 $a,
        public ContainerDag15 $b,
    ) {}
}

final readonly class ContainerDag18
{
    public function __construct(
        public ContainerDag17 $a,
        public ContainerDag16 $b,
    ) {}
}

final readonly class ContainerDag19
{
    public function __construct(
        public ContainerDag18 $a,
        public ContainerDag17 $b,
    ) {}
}

final readonly class ContainerDag20
{
    public function __construct(
        public ContainerDag19 $a,
        public ContainerDag18 $b,
    ) {}
}

final readonly class ContainerDag21
{
    public function __construct(
        public ContainerDag20 $a,
        public ContainerDag19 $b,
    ) {}
}

final readonly class ContainerDag22
{
    public function __construct(
        public ContainerDag21 $a,
        public ContainerDag20 $b,
    ) {}
}

final readonly class ContainerDag23
{
    public function __construct(
        public ContainerDag22 $a,
        public ContainerDag21 $b,
    ) {}
}

final readonly class ContainerDag24
{
    public function __construct(
        public ContainerDag23 $a,
        public ContainerDag22 $b,
    ) {}
}

final readonly class ContainerDag25
{
    public function __construct(
        public ContainerDag24 $a,
        public ContainerDag23 $b,
    ) {}
}

final readonly class ContainerDag26
{
    public function __construct(
        public ContainerDag25 $a,
        public ContainerDag24 $b,
    ) {}
}

final readonly class ContainerDag27
{
    public function __construct(
        public ContainerDag26 $a,
        public ContainerDag25 $b,
    ) {}
}

final readonly class ContainerDag28
{
    public function __construct(
        public ContainerDag27 $a,
        public ContainerDag26 $b,
    ) {}
}

final readonly class ContainerDag29
{
    public function __construct(
        public ContainerDag28 $a,
        public ContainerDag27 $b,
    ) {}
}

final readonly class ContainerDag30
{
    public function __construct(
        public ContainerDag29 $a,
        public ContainerDag28 $b,
    ) {}
}

final readonly class ContainerDag31
{
    public function __construct(
        public ContainerDag30 $a,
        public ContainerDag29 $b,
    ) {}
}

final readonly class ContainerDag32
{
    public function __construct(
        public ContainerDag31 $a,
        public ContainerDag30 $b,
    ) {}
}

final readonly class ContainerDag33
{
    public function __construct(
        public ContainerDag32 $a,
        public ContainerDag31 $b,
    ) {}
}

final readonly class ContainerDag34
{
    public function __construct(
        public ContainerDag33 $a,
        public ContainerDag32 $b,
    ) {}
}

final readonly class ContainerDag35
{
    public function __construct(
        public ContainerDag34 $a,
        public ContainerDag33 $b,
    ) {}
}

final readonly class ContainerDag36
{
    public function __construct(
        public ContainerDag35 $a,
        public ContainerDag34 $b,
    ) {}
}

final readonly class ContainerDag37
{
    public function __construct(
        public ContainerDag36 $a,
        public ContainerDag35 $b,
    ) {}
}

final readonly class ContainerDag38
{
    public function __construct(
        public ContainerDag37 $a,
        public ContainerDag36 $b,
    ) {}
}

final readonly class ContainerDag39
{
    public function __construct(
        public ContainerDag38 $a,
        public ContainerDag37 $b,
    ) {}
}

final readonly class ContainerDag40
{
    public function __construct(
        public ContainerDag39 $a,
        public ContainerDag38 $b,
    ) {}
}

final readonly class ContainerDag41
{
    public function __construct(
        public ContainerDag40 $a,
        public ContainerDag39 $b,
    ) {}
}

final readonly class ContainerDag42
{
    public function __construct(
        public ContainerDag41 $a,
        public ContainerDag40 $b,
    ) {}
}

final readonly class ContainerDag43
{
    public function __construct(
        public ContainerDag42 $a,
        public ContainerDag41 $b,
    ) {}
}

final readonly class ContainerDag44
{
    public function __construct(
        public ContainerDag43 $a,
        public ContainerDag42 $b,
    ) {}
}

final readonly class ContainerDag45
{
    public function __construct(
        public ContainerDag44 $a,
        public ContainerDag43 $b,
    ) {}
}

final readonly class ContainerDag46
{
    public function __construct(
        public ContainerDag45 $a,
        public ContainerDag44 $b,
    ) {}
}

final readonly class ContainerDag47
{
    public function __construct(
        public ContainerDag46 $a,
        public ContainerDag45 $b,
    ) {}
}

final readonly class ContainerDag48
{
    public function __construct(
        public ContainerDag47 $a,
        public ContainerDag46 $b,
    ) {}
}

final readonly class ContainerDag49
{
    public function __construct(
        public ContainerDag48 $a,
        public ContainerDag47 $b,
    ) {}
}

final readonly class ContainerDag50
{
    public function __construct(
        public ContainerDag49 $a,
        public ContainerDag48 $b,
    ) {}
}
