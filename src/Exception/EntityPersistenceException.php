<?php

declare(strict_types=1);

namespace TMSWP\Core\Exception;

class EntityPersistenceException extends RuntimeException
{
    public readonly ?\WP_Error $wpError;

    public function __construct(string $message, ?\WP_Error $wpError = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->wpError = $wpError;
    }

    public static function fromWpError(\WP_Error $error): self
    {
        return new self($error->get_error_message(), $error);
    }
}
