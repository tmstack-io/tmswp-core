<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use TMSWP\Core\PHPStan\KernelResetCallChecker;
use TMSWP\Core\PHPStan\KernelResetStaticCallableOutsideTestRule;
use TMSWP\Core\Tests\Support\PHPStanFixtureAutoloader;

/**
 * @extends RuleTestCase<KernelResetStaticCallableOutsideTestRule>
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class KernelResetStaticCallableOutsideTestRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(KernelResetStaticCallableOutsideTestRule::class);
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

    public function testReportsKernelResetStaticCallablesOutsideTestCaseSubclasses(): void
    {
        // Kernel::reset(...) と、class-string・定数文字列の名前による同じ生成を、TestCase の子孫クラスの外でだけ報告する
        $this->analyse([__DIR__ . '/../../fixtures/phpstan/kernel-reset-rule.php'], [
            [KernelResetCallChecker::MESSAGE, 20],
            [KernelResetCallChecker::MESSAGE, 33],
            [KernelResetCallChecker::MESSAGE, 34],
        ]);
    }
}
