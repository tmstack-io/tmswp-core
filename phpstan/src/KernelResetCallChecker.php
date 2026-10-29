<?php

declare(strict_types=1);

namespace TMSWP\Core\PHPStan;

use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;
use PHPUnit\Framework\TestCase;
use TMSWP\Core\Kernel\Kernel;

/**
 * `Kernel::reset()` を PHPUnit の TestCase の子孫クラスの外で参照しているかを判定する。Kernel の reset を禁じる
 * 各ルール(呼び出しと第一級 callable の生成。ノードの種類ごとに分かれる)が共有する。
 *
 * - メソッド名: Identifier か、型が定数文字列の式で、`reset` に一致するもの(大文字・小文字を区別しない)。
 *   定数に定まらない動的な名前は判定できないため対象外とする。
 * - 呼び出し先: クラス名、式の型のオブジェクトのクラス名、class-string のオブジェクト型、定数文字列のクラス名の
 *   どれかが Kernel(またはそのサブクラス)であるもの。
 */
final class KernelResetCallChecker
{
    public const MESSAGE = 'Kernel::reset() is for tests only; call it only from a subclass of ' . TestCase::class . '.';

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    /**
     * @param Name|Expr $target 静的呼び出しのクラス名か、インスタンスメソッド呼び出しの対象の式
     *
     * @return list<IdentifierRuleError>
     */
    public function check(Name|Expr $target, Identifier|Expr $name, Scope $scope): array
    {
        if (!$this->isReset($name, $scope) || !$this->targetsKernel($target, $scope) || $this->isInTestCase($scope->getClassReflection())) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::MESSAGE)
                ->identifier('tmswp.kernelReset')
                ->build(),
        ];
    }

    private function isReset(Identifier|Expr $name, Scope $scope): bool
    {
        if ($name instanceof Identifier) {
            return $name->toLowerString() === 'reset';
        }

        foreach ($scope->getType($name)->getConstantStrings() as $constant) {
            if (strtolower($constant->getValue()) === 'reset') {
                return true;
            }
        }

        return false;
    }

    private function targetsKernel(Name|Expr $target, Scope $scope): bool
    {
        $classNames = $target instanceof Name
            ? [$scope->resolveName($target)]
            : $this->classNames($scope->getType($target));

        foreach ($classNames as $className) {
            $className = ltrim($className, '\\');

            if (!$this->reflectionProvider->hasClass($className)) {
                continue;
            }

            $class = $this->reflectionProvider->getClass($className);

            if ($class->getName() === Kernel::class || \in_array(Kernel::class, $class->getParentClassesNames(), true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function classNames(Type $type): array
    {
        return [
            ...$type->getObjectClassNames(),
            ...$type->getClassStringObjectType()->getObjectClassNames(),
            ...array_map(static fn($constant): string => $constant->getValue(), $type->getConstantStrings()),
        ];
    }

    private function isInTestCase(?ClassReflection $class): bool
    {
        if (!$class instanceof ClassReflection) {
            return false;
        }

        return \in_array(TestCase::class, $class->getParentClassesNames(), true);
    }
}
