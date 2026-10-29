<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Rule;

use Closure;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\Messages;
use TMSWP\Core\Form\ValidationContext;

final class Callback implements RuleInterface
{
    /** @param Closure(mixed, ValidationContext): (bool|string) $callback true は合格、false は違反、文字列はそれをメッセージにした違反 */
    public function __construct(
        private readonly Closure $callback,
        private readonly ?string $message = null,
    ) {}

    public function skipIfEmpty(): bool
    {
        return true;
    }

    public function eachElement(): bool
    {
        return false;
    }

    /** @throws FormConfigException コールバックが bool と文字列以外を返した場合 */
    public function validate(mixed $value, ValidationContext $context): ?string
    {
        $result = ($this->callback)($value, $context);

        return match (true) {
            $result === true => null,
            $result === false => Messages::resolve($this, $this->message ?? __('{label} is invalid.', 'tmswp'), $context),
            \is_string($result) => Messages::resolve($this, $result, $context),
            default => throw new FormConfigException('the Callback rule must return bool or string, got ' . get_debug_type($result)),
        };
    }
}
