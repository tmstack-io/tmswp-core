<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use TMSWP\Core\Exception\Form\TempFileException;

final readonly class StoredFile
{
    public function __construct(
        public string $token,
        public string $path,
        public string $clientName,
        public string $mime,
        public int $size,
    ) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * ファイルを $directory へ移し、トークンのディレクトリを消す。
     *
     * @param ?string $filename 移動先のファイル名。省略時は clientName。wp_unique_filename() で衝突を避ける
     *
     * @throws TempFileException ファイルが無い場合と、ディレクトリの作成・移動・トークンのディレクトリの削除の失敗
     *
     * @return string 移動先の絶対パス
     */
    public function moveTo(string $directory, ?string $filename = null): string
    {
        if (!$this->exists()) {
            throw new TempFileException('temporary file does not exist');
        }

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new TempFileException("failed to create directory: {$directory}");
        }

        $name = $filename ?? $this->clientName;

        if (!\function_exists('wp_unique_filename')) {
            throw new TempFileException('wp_unique_filename is not available');
        }

        $name = wp_unique_filename($directory, $name);
        $target = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;

        if (!rename($this->path, $target)) {
            throw new TempFileException("failed to move temporary file: {$this->path}");
        }

        TempFileStore::deleteDirectory(\dirname($this->path));

        return $target;
    }

    /**
     * メディアライブラリの添付ファイルにし、トークンのディレクトリを消す。media_handle_sideload() は管理画面の関数のため、
     * 読み込まれていなければ(フロントのリクエスト)wp-admin/includes の file.php・media.php・image.php を読み込む。
     *
     * @throws TempFileException ファイルが無い場合、WordPress が読み込まれていない場合、media_handle_sideload() が
     *                           WP_Error を返した場合(そのメッセージを持つ)と、トークンのディレクトリの削除の失敗
     *
     * @return int 添付ファイルの ID
     */
    public function toAttachment(int $parentPostId = 0): int
    {
        if (!$this->exists()) {
            throw new TempFileException('temporary file does not exist');
        }

        self::loadMediaFunctions();
        $attachmentId = media_handle_sideload($this->sideloadFile(), $parentPostId);

        if (is_wp_error($attachmentId)) {
            throw new TempFileException($attachmentId->get_error_message());
        }

        TempFileStore::deleteDirectory(\dirname($this->path));

        return $attachmentId;
    }

    /**
     * ファイルとトークンのディレクトリを消す。既に無ければ何もしない。
     *
     * @throws TempFileException 削除の失敗
     */
    public function delete(): void
    {
        $dir = \dirname($this->path);

        if (is_dir($dir)) {
            TempFileStore::deleteDirectory($dir);
        }
    }

    /**
     * @throws TempFileException WordPress が読み込まれていない場合
     */
    private static function loadMediaFunctions(): void
    {
        if (\function_exists('media_handle_sideload')) {
            return;
        }

        if (!\defined('ABSPATH')) {
            throw new TempFileException('WordPress is not loaded: ABSPATH is not defined');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    /** @return array<string, string> */
    private function sideloadFile(): array
    {
        return [
            'name' => $this->clientName,
            'type' => $this->mime,
            'tmp_name' => $this->path,
            'error' => '0',
            'size' => (string) $this->size,
        ];
    }
}
