<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Rule;

use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;

final class Regex extends Rule
{
    public function __construct(private readonly string $pattern, ?string $message = null)
    {
        parent::__construct($message);
    }

    protected function passes(mixed $value, ValidationContext $context): bool
    {
        $text = Validator::stringValue($value);

        if ($text === null) {
            return false;
        }

        $error = null;
        set_error_handler(static function (int $_severity, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $result = preg_match($this->pattern, $text);
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            $message = $error === null ? "invalid regex pattern: {$this->pattern}" : "invalid regex pattern: {$this->pattern} ({$error})";

            throw new FormConfigException($message);
        }

        return $result === 1;
    }

    public function defaultMessage(): string
    {
        return __('{label} has an invalid format.', 'tmswp');
    }
}
