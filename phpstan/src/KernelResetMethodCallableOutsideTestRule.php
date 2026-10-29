<?php

declare(strict_types=1);

namespace TMSWP\Core\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\MethodCallableNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * `$kernel->reset(...)`(インスタンス経由の第一級 callable の生成)を、TestCase の子孫クラスの外で禁じる。
 *
 * @implements Rule<MethodCallableNode>
 */
final class KernelResetMethodCallableOutsideTestRule implements Rule
{
    public function __construct(
        private readonly KernelResetCallChecker $checker,
    ) {}

    public function getNodeType(): string
    {
        return MethodCallableNode::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        return $this->checker->check($node->getVar(), $node->getName(), $scope);
    }
}
