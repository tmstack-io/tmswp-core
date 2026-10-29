<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\Kernel as AliasedKernel;
use TMSWP\Testing\UnitTestCase;

Kernel::reset(); // static

/**
 * @param class-string<Kernel> $class
 */
function phpstanKernelResetViaClassString(string $class): void
{
    $class::reset(); // static
    $class::reset(...); // static-callable
}

final class PHPStanKernelResetProductionService
{
    public function boot(Kernel $kernel): void
    {
        Kernel::reset(); // static
        AliasedKernel::reset(); // static
        \TMSWP\Core\Kernel\Kernel::reset(); // static
        Kernel::RESET(); // static
        Kernel::{'reset'}(); // static
        $method = 'reset';
        Kernel::$method(...); // static-callable
        Kernel::reset(...); // static-callable
        'TMSWP\Core\Kernel\Kernel'::reset(); // static
        $kernel::reset(); // static
        $kernel->reset(); // method
        $kernel?->reset(); // method
        $kernel->reset(...); // method-callable
    }

    public function dynamicName(string $name): void
    {
        // 定数に定まらない名前は判定できないため対象外
        Kernel::$name();
    }

    /** @return list<mixed> */
    public function callables(): array
    {
        // call_user_func 等に渡す callable の配列・文字列は対象外
        return [[Kernel::class, 'reset'], 'TMSWP\Core\Kernel\Kernel::reset'];
    }
}

final class PHPStanKernelResetDirectTest extends TestCase
{
    public function testAllForms(Kernel $kernel): void
    {
        Kernel::reset();
        (static fn() => Kernel::reset())();
        Kernel::{'reset'}();
        $method = 'reset';
        Kernel::$method(...);
        Kernel::reset(...);
        $kernel::reset();
        $kernel->reset();
        $kernel->reset(...);
        phpstanKernelResetViaClassString(Kernel::class);
    }
}

final class PHPStanKernelResetIndirectTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Kernel::reset();
        parent::tearDown();
    }
}

final class PHPStanKernelResetOtherReset
{
    public static function reset(): void
    {
    }

    public function call(self $other): void
    {
        self::reset();
        PHPStanKernelResetOtherReset::reset();
        $other->reset();
        $other->reset(...);
        Kernel::all();
    }
}
