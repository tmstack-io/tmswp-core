<?php

declare(strict_types=1);

namespace TMSWP\Core\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\StaticMethodCallableNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * `Kernel::reset(...)`(静的メソッドの第一級 callable の生成)を、TestCase の子孫クラスの外で禁じる。
 *
 * @implements Rule<StaticMethodCallableNode>
 */
final class KernelResetStaticCallableOutsideTestRule implements Rule
{
    public function __construct(
        private readonly KernelResetCallChecker $checker,
    ) {}

    public function getNodeType(): string
    {
        return StaticMethodCallableNode::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        return $this->checker->check($node->getClass(), $node->getName(), $scope);
    }
}
