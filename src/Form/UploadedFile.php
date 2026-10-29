<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use Exception;
use finfo;
use TMSWP\Core\Exception\Form\TempFileException;

final readonly class UploadedFile
{
    public function __construct(
        public string $tmpPath,
        public string $clientName,
        public string $clientMime,
        public int $size,
        public int $error,
    ) {}

    /** @param array{name: string, type: string, tmp_name: string, error: int, size: int} $entry */
    public static function fromFilesEntry(array $entry): self
    {
        return new self($entry['tmp_name'], $entry['name'], $entry['type'], $entry['size'], $entry['error']);
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && is_uploaded_file($this->tmpPath);
    }

    /**
     * 実体の MIME を finfo で判定する。判定できないときに既定の MIME で代えると、実体の検査を黙って飛ばすことになる
     * ため、例外にする。どちらの失敗も保管(TempFileStore::store())の中で起き、Form はエラー方針に従って扱う。
     *
     * @throws TempFileException finfo を使えない場合(fileinfo の拡張が無い・magic のデータベースを読めない等の環境の異常)と、
     *                           ファイルを判定できない場合(読み取れない等)
     */
    public function detectedMime(): string
    {
        if (!\extension_loaded('fileinfo')) {
            throw new TempFileException('the fileinfo extension is required to detect the MIME type of an uploaded file');
        }

        try {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
        } catch (Exception $exception) {
            throw new TempFileException('failed to initialize finfo to detect the MIME type of an uploaded file', previous: $exception);
        }

        $error = null;
        set_error_handler(static function (int $_severity, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $mime = $finfo->file($this->tmpPath);
        } finally {
            restore_error_handler();
        }

        if (!\is_string($mime)) {
            throw new TempFileException("failed to detect the MIME type of the uploaded file {$this->tmpPath}" . ($error === null ? '' : " ({$error})"));
        }

        return $mime;
    }

    public function clientExtension(): string
    {
        return strtolower(pathinfo($this->clientName, PATHINFO_EXTENSION));
    }

    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => __('The uploaded file is too large.', 'tmswp'),
            UPLOAD_ERR_PARTIAL => __('The file was only partially uploaded.', 'tmswp'),
            UPLOAD_ERR_NO_FILE => __('Please select a file.', 'tmswp'),
            UPLOAD_ERR_NO_TMP_DIR => __('The temporary upload folder is missing.', 'tmswp'),
            UPLOAD_ERR_CANT_WRITE => __('The file could not be written.', 'tmswp'),
            UPLOAD_ERR_EXTENSION => __('A PHP extension stopped the file upload.', 'tmswp'),
            default => __('The file upload failed.', 'tmswp'),
        };
    }
}
