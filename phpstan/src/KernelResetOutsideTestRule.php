<?php

declare(strict_types=1);

namespace TMSWP\Core\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * `Kernel::reset()` を PHPUnit の TestCase の子孫クラスの外から呼ぶことを禁じる。
 *
 * reset() は起動済みの全 Kernel をレジストリから外すテスト専用の API で、本番で呼ぶと他のテーマ・プラグインの
 * Kernel まで解決できなくなる(02-kernel.md §3.5)。静的呼び出しと、インスタンス経由の呼び出し(`$kernel->reset()`)を
 * 検査する。第一級 callable の生成は KernelResetStaticCallableOutsideTestRule と
 * KernelResetMethodCallableOutsideTestRule が検査する。
 *
 * @implements Rule<CallLike>
 */
final class KernelResetOutsideTestRule implements Rule
{
    public function __construct(
        private readonly KernelResetCallChecker $checker,
    ) {}

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof StaticCall) {
            return $this->checker->check($node->class, $node->name, $scope);
        }

        // `$kernel?->reset()` も、PHPStan は MethodCall として渡す
        if ($node instanceof MethodCall) {
            return $this->checker->check($node->var, $node->name, $scope);
        }

        return [];
    }
}
