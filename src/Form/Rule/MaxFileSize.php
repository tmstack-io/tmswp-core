<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Rule;

use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\StoredFile;
use TMSWP\Core\Form\UploadedFile;
use TMSWP\Core\Form\ValidationContext;

final class MaxFileSize extends Rule
{
    public function __construct(private readonly int $bytes, ?string $message = null)
    {
        if ($bytes < 1) {
            throw new FormConfigException('MaxFileSize bytes must be greater than 0');
        }

        parent::__construct($message);
    }

    public static function mb(int $mb, ?string $message = null): self
    {
        return new self($mb * 1024 * 1024, $message);
    }

    public function eachElement(): bool
    {
        return false;
    }

    protected function passes(mixed $value, ValidationContext $context): bool
    {
        if (!$value instanceof UploadedFile && !$value instanceof StoredFile) {
            return false;
        }

        return $value->size <= $this->bytes;
    }

    public function defaultMessage(): string
    {
        return __('{label} must be {size} or smaller.', 'tmswp');
    }

    public function messageParams(): array
    {
        return ['size' => size_format($this->bytes)];
    }
}
