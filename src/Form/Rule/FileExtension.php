<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Rule;

use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\StoredFile;
use TMSWP\Core\Form\UploadedFile;
use TMSWP\Core\Form\ValidationContext;

final class FileExtension extends Rule
{
    /** @var list<string> */
    private readonly array $extensions;

    /** @param list<string> $extensions */
    public function __construct(array $extensions, ?string $message = null)
    {
        if ($extensions === []) {
            throw new FormConfigException('FileExtension extensions must not be empty');
        }

        $this->extensions = array_values(array_map(static fn(string $extension): string => strtolower(ltrim($extension, '.')), $extensions));

        parent::__construct($message);
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

        $extension = $value instanceof UploadedFile ? $value->clientExtension() : strtolower(pathinfo($value->clientName, PATHINFO_EXTENSION));

        if (!\in_array($extension, $this->extensions, true)) {
            return false;
        }

        if ($value instanceof UploadedFile) {
            $check = wp_check_filetype_and_ext($value->tmpPath, $value->clientName);

            return \is_array($check) && ($check['ext'] ?? false) !== false;
        }

        return true;
    }

    public function defaultMessage(): string
    {
        return __('Please upload {label} as one of these file types: {extensions}.', 'tmswp');
    }

    public function messageParams(): array
    {
        return ['extensions' => implode(', ', $this->extensions)];
    }
}
