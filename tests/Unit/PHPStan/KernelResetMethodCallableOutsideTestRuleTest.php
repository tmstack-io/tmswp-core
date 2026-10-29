<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use TMSWP\Core\PHPStan\KernelResetCallChecker;
use TMSWP\Core\PHPStan\KernelResetMethodCallableOutsideTestRule;
use TMSWP\Core\Tests\Support\PHPStanFixtureAutoloader;

/**
 * @extends RuleTestCase<KernelResetMethodCallableOutsideTestRule>
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class KernelResetMethodCallableOutsideTestRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(KernelResetMethodCallableOutsideTestRule::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        PHPStanFixtureAutoloader::register();
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../../phpstan/extension.neon'];
    }

    public function testReportsKernelResetMethodCallablesOutsideTestCaseSubclasses(): void
    {
        // $kernel->reset(...) を TestCase の子孫クラスの外でだけ報告し、Kernel 以外の reset(...) は報告しない
        $this->analyse([__DIR__ . '/../../fixtures/phpstan/kernel-reset-rule.php'], [
            [KernelResetCallChecker::MESSAGE, 39],
        ]);
    }
}
