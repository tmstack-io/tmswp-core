<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use TMSWP\Core\PHPStan\KernelResetCallChecker;
use TMSWP\Core\PHPStan\KernelResetOutsideTestRule;
use TMSWP\Core\Tests\Support\PHPStanFixtureAutoloader;

/**
 * @extends RuleTestCase<KernelResetOutsideTestRule>
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class KernelResetOutsideTestRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(KernelResetOutsideTestRule::class);
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

    public function testReportsKernelResetCallsOutsideTestCaseSubclasses(): void
    {
        // 静的呼び出しとインスタンス経由の呼び出しを報告する。TestCase の直接・間接の子孫クラス(クロージャの中を含む)、Kernel 以外の reset()、定数に定まらない名前、callable の配列・文字列は報告しない
        $this->analyse([__DIR__ . '/../../fixtures/phpstan/kernel-reset-rule.php'], [
            [KernelResetCallChecker::MESSAGE, 12],
            [KernelResetCallChecker::MESSAGE, 19],
            [KernelResetCallChecker::MESSAGE, 27],
            [KernelResetCallChecker::MESSAGE, 28],
            [KernelResetCallChecker::MESSAGE, 29],
            [KernelResetCallChecker::MESSAGE, 30],
            [KernelResetCallChecker::MESSAGE, 31],
            [KernelResetCallChecker::MESSAGE, 35],
            [KernelResetCallChecker::MESSAGE, 36],
            [KernelResetCallChecker::MESSAGE, 37],
            [KernelResetCallChecker::MESSAGE, 38],
        ]);
    }
}
