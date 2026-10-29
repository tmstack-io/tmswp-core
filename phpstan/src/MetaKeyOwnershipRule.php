<?php

declare(strict_types=1);

namespace TMSWP\Core\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;
use TMSWP\Core\Meta\MetaBag;

/**
 * @implements Rule<MethodCall>
 */
final class MetaKeyOwnershipRule implements Rule
{
    /** @var list<string> */
    private const METHODS = ['get', 'int', 'float', 'bool', 'string', 'list', 'json', 'has', 'set', 'delete'];

    public function __construct(
        private readonly MetaSchemaReflector $reflector,
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || !\in_array($node->name->toString(), self::METHODS, true)) {
            return [];
        }

        if (!\array_key_exists(0, $node->getArgs())) {
            return [];
        }

        $entityClass = $this->entityClassFromCall($node, $scope);

        if ($entityClass === null) {
            return [];
        }

        $keyArg = $node->getArgs()[0]->value;

        if ($keyArg instanceof ClassConstFetch && $keyArg->class instanceof Name && $keyArg->name instanceof Identifier) {
            return $this->checkClassConstant($scope, $entityClass, $keyArg);
        }

        $constantStrings = $scope->getType($keyArg)->getConstantStrings();

        if (\count($constantStrings) !== 1) {
            return [];
        }

        $key = $constantStrings[0]->getValue();
        $constantName = $this->reflector->metaConstantNameForKey($entityClass, $key);

        if ($constantName === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message("Use {$entityClass}::{$constantName} instead of string literal '{$key}'.")
                ->identifier('tmswp.metaKeyLiteral')
                ->build(),
        ];
    }

    /** @return list<IdentifierRuleError> */
    private function checkClassConstant(Scope $scope, string $entityClass, ClassConstFetch $fetch): array
    {
        if (!$fetch->class instanceof Name || !$fetch->name instanceof Identifier) {
            return [];
        }

        $className = $scope->resolveName($fetch->class);
        $declaringClass = $this->reflector->declaringClassOfMetaConstant($className, $fetch->name->toString());

        if ($declaringClass === null || $declaringClass === $entityClass) {
            return [];
        }

        return [
            RuleErrorBuilder::message("Meta key {$className}::{$fetch->name->toString()} does not belong to entity {$entityClass}.")
                ->identifier('tmswp.metaKeyOwnership')
                ->build(),
        ];
    }

    private function entityClassFromCall(MethodCall $node, Scope $scope): ?string
    {
        if ($node->var instanceof StaticCall && $node->var->class instanceof Name && $node->var->name instanceof Identifier && $node->var->name->toString() === 'meta') {
            $className = $scope->resolveName($node->var->class);

            if ($this->reflectionProvider->hasClass($className)) {
                return $this->reflectionProvider->getClass($className)->getName();
            }
        }

        return $this->entityClassFromReceiver($scope->getType($node->var));
    }

    private function entityClassFromReceiver(Type $type): ?string
    {
        if (!\in_array(MetaBag::class, $type->getObjectClassNames(), true)) {
            return null;
        }

        $classNames = $type->getTemplateType(MetaBag::class, 'TEntity')->getObjectClassNames();

        if (\count($classNames) !== 1 || !$this->reflectionProvider->hasClass($classNames[0])) {
            return null;
        }

        return $classNames[0];
    }
}
