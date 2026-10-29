<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use FilesystemIterator;
use JsonException;
use RuntimeException;
use SplFileInfo;
use TMSWP\Core\Exception\Form\TempFileException;
use UnexpectedValueException;

/**
 * アップロードされたファイルの一時保管。保管ごとに推測できないトークンのディレクトリを作り、ファイルを固定の名前
 * (FILE_NAME)、メタデータを META_NAME で置く。クライアントのファイル名は保存名に使わず、メタデータに記録するだけにする。
 */
final class TempFileStore
{
    /** トークンのディレクトリの中のファイルの保存名。クライアントのファイル名を使わない(衝突と実行可能な拡張子を避ける) */
    public const string FILE_NAME = 'file';

    /** トークンのディレクトリの中のメタデータのファイル名 */
    public const string META_NAME = 'meta.json';

    /**
     * Apache で保管場所への直接のアクセスを拒否する設定。2.4 の mod_authz_core の構文と、2.2 の構文の両方を書く。
     */
    private const string HTACCESS = <<<'HTACCESS'
        <IfModule mod_authz_core.c>
            Require all denied
        </IfModule>
        <IfModule !mod_authz_core.c>
            Order deny,allow
            Deny from all
        </IfModule>

        HTACCESS;

    private const string TOKEN_PATTERN = '/\A[a-f0-9]{32}\z/';

    public function __construct(private readonly string $baseDir) {}

    public static function default(): self
    {
        if (!\function_exists('wp_upload_dir')) {
            throw new TempFileException('wp_upload_dir is not available');
        }

        $upload = wp_upload_dir();

        if (!\is_array($upload) || !\is_string($upload['basedir'] ?? null) || $upload['basedir'] === '') {
            throw new TempFileException('wp_upload_dir did not return basedir');
        }

        return new self(rtrim($upload['basedir'], '/\\') . '/tmswp-form-tmp');
    }

    /**
     * ファイルを保管する。保管の前に、期限切れのファイルを消す WP-Cron のイベント(TempFileGc)が無ければスケジュールする。
     *
     * @throws TempFileException アップロードに成功したファイルでない場合と、保管の失敗(イベントのスケジュール・
     *                           ディレクトリの作成・実体の MIME の判定・移動・メタデータの書き込み)
     */
    public function store(UploadedFile $file): StoredFile
    {
        if (!$file->isValid()) {
            throw new TempFileException($file->errorMessage());
        }

        TempFileGc::schedule();
        $this->prepareBaseDir();
        $token = bin2hex(random_bytes(16));
        $dir = $this->baseDir . DIRECTORY_SEPARATOR . $token;

        if (!mkdir($dir, 0700)) {
            throw new TempFileException("failed to create temporary file directory: {$dir}");
        }

        if (!\function_exists('sanitize_file_name')) {
            throw new TempFileException('sanitize_file_name is not available');
        }

        $name = sanitize_file_name($file->clientName);
        $name = $name !== '' ? $name : 'file.bin';
        $path = $dir . DIRECTORY_SEPARATOR . self::FILE_NAME;
        $mime = $file->detectedMime();

        if (!move_uploaded_file($file->tmpPath, $path)) {
            throw new TempFileException("failed to move uploaded file: {$file->tmpPath}");
        }

        $meta = ['name' => $name, 'mime' => $mime, 'size' => $file->size, 'created' => time()];

        if (file_put_contents($dir . DIRECTORY_SEPARATOR . self::META_NAME, json_encode($meta, JSON_THROW_ON_ERROR)) === false) {
            throw new TempFileException('failed to write temporary file metadata');
        }

        return new StoredFile($token, $path, $name, $mime, $file->size);
    }

    /**
     * トークンの保管が揃っているか(メタデータとファイルの両方がある)。削除が途中で失敗してファイルだけが消えたトークンも、
     * 無いものとして扱う。
     */
    public function exists(string $token): bool
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return false;
        }

        $dir = $this->baseDir . DIRECTORY_SEPARATOR . $token . DIRECTORY_SEPARATOR;

        return is_file($dir . self::META_NAME) && is_file($dir . self::FILE_NAME);
    }

    /**
     * @throws TempFileException トークンの形の誤り・メタデータの不在や破損・ファイルの不在
     */
    public function resolve(string $token): StoredFile
    {
        $dir = $this->tokenDirectory($token);
        $metaFile = $dir . DIRECTORY_SEPARATOR . self::META_NAME;
        $json = is_file($metaFile) ? file_get_contents($metaFile) : false;

        if (!\is_string($json)) {
            throw new TempFileException("temporary file metadata not found: {$token}");
        }

        try {
            $meta = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new TempFileException("temporary file metadata is broken: {$token}", previous: $exception);
        }

        if (!\is_array($meta) || !\is_string($meta['name'] ?? null) || !\is_string($meta['mime'] ?? null) || !\is_int($meta['size'] ?? null)) {
            throw new TempFileException("temporary file metadata is broken: {$token}");
        }

        $path = $dir . DIRECTORY_SEPARATOR . self::FILE_NAME;

        if (!is_file($path)) {
            throw new TempFileException("temporary file not found: {$token}");
        }

        return new StoredFile($token, $path, $meta['name'], $meta['mime'], $meta['size']);
    }

    /**
     * トークンのディレクトリを消す。無ければ何もしない。
     *
     * @throws TempFileException トークンの形の誤りと、削除の失敗
     */
    public function delete(string $token): void
    {
        $dir = $this->tokenDirectory($token);

        if (is_dir($dir)) {
            self::deleteDirectory($dir);
        }
    }

    /**
     * 保管から $maxAgeSeconds を過ぎたトークンのディレクトリを消す。baseDir の直下の、トークンの形の名前の
     * ディレクトリだけを対象にする。1つの削除に失敗しても残りを続け、最後に失敗をまとめて例外にする。
     *
     * @throws TempFileException baseDir の一覧を取れない場合と、削除に失敗したディレクトリがあった場合
     *
     * @return int 消したディレクトリの数
     */
    public function gc(int $maxAgeSeconds = 86400): int
    {
        if (!is_dir($this->baseDir)) {
            return 0;
        }

        $deleted = 0;
        $failures = [];
        $now = time();

        foreach ($this->tokenDirectories() as $dir) {
            try {
                if (($now - $this->createdAt($dir)) <= $maxAgeSeconds) {
                    continue;
                }

                self::deleteDirectory($dir->getPathname());
                ++$deleted;
            } catch (TempFileException $exception) {
                $failures[] = $exception;
            }
        }

        if ($failures !== []) {
            throw new TempFileException(\count($failures) . " expired temporary file directories could not be deleted ({$deleted} deleted): " . $failures[0]->getMessage(), previous: $failures[0]);
        }

        return $deleted;
    }

    /**
     * トークンのディレクトリの中のファイルを消してから、ディレクトリを消す。個人情報を含みうる本体(FILE_NAME)を先に、
     * 次にメタデータ(META_NAME)を、最後にそのほかの残りを消す。順はファイルシステムの列挙の順に依存しないため、途中で
     * 失敗しても、本体の削除より前にほかのファイルで止まることは無い。トークンのディレクトリはファイルだけを持つため、
     * サブディレクトリがあれば rmdir() が失敗して例外になる。
     *
     * @internal TempFileStore と StoredFile だけが使う。
     *
     * @throws TempFileException 削除の失敗(ディレクトリの一覧を取れない場合を含む)
     */
    public static function deleteDirectory(string $dir): void
    {
        foreach ([self::FILE_NAME, self::META_NAME] as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;

            if (is_file($path) && !unlink($path)) {
                throw new TempFileException("failed to delete temporary file: {$path}");
            }
        }

        try {
            foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $fileInfo) {
                if ($fileInfo instanceof SplFileInfo && !$fileInfo->isDir() && !unlink($fileInfo->getPathname())) {
                    throw new TempFileException("failed to delete temporary file: {$fileInfo->getPathname()}");
                }
            }
        } catch (UnexpectedValueException $exception) {
            // 読み取りの権限が無い等で一覧を取れない。削除の失敗として、原因を previous に持たせる
            throw new TempFileException("failed to list temporary file directory: {$dir}", previous: $exception);
        }

        if (!rmdir($dir)) {
            throw new TempFileException("failed to delete temporary file directory: {$dir}");
        }
    }

    /**
     * baseDir の直下の、名前がトークンの形のディレクトリ。
     *
     * @throws TempFileException baseDir の一覧を取れない場合
     *
     * @return list<SplFileInfo>
     */
    private function tokenDirectories(): array
    {
        $dirs = [];

        try {
            foreach (new FilesystemIterator($this->baseDir, FilesystemIterator::SKIP_DOTS) as $fileInfo) {
                if ($fileInfo instanceof SplFileInfo && $fileInfo->isDir() && preg_match(self::TOKEN_PATTERN, $fileInfo->getFilename()) === 1) {
                    $dirs[] = $fileInfo;
                }
            }
        } catch (UnexpectedValueException $exception) {
            throw new TempFileException("failed to list temporary file base directory: {$this->baseDir}", previous: $exception);
        }

        return $dirs;
    }

    /**
     * 保管した時刻。メタデータの created が読めなければ、ディレクトリの更新時刻。
     *
     * @throws TempFileException ディレクトリの更新時刻を取れない場合
     */
    private function createdAt(SplFileInfo $dir): int
    {
        $metaFile = $dir->getPathname() . DIRECTORY_SEPARATOR . self::META_NAME;
        $json = is_file($metaFile) ? file_get_contents($metaFile) : false;
        $meta = \is_string($json) ? json_decode($json, true) : null;

        if (\is_array($meta) && \is_int($meta['created'] ?? null)) {
            return $meta['created'];
        }

        try {
            return $dir->getMTime();
        } catch (RuntimeException $exception) {
            throw new TempFileException("failed to read the modification time of temporary file directory: {$dir->getPathname()}", previous: $exception);
        }
    }

    /**
     * トークンのディレクトリのパス。トークンの形(32桁の16進数)の確認が、利用者の値からパスを作るときの境界になる。
     *
     * @throws TempFileException トークンの形の誤り
     */
    private function tokenDirectory(string $token): string
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new TempFileException("invalid temporary file token: {$token}");
        }

        return $this->baseDir . DIRECTORY_SEPARATOR . $token;
    }

    /**
     * baseDir を作り、直接のアクセスを拒否する .htaccess と空の index.html を置く。どちらも無いときだけ書く。
     *
     * @throws TempFileException 作成・書き込みの失敗
     */
    private function prepareBaseDir(): void
    {
        if (!is_dir($this->baseDir) && !mkdir($this->baseDir, 0700, true) && !is_dir($this->baseDir)) {
            throw new TempFileException("failed to create temporary file base directory: {$this->baseDir}");
        }

        $htaccess = $this->baseDir . '/.htaccess';

        if (!is_file($htaccess) && file_put_contents($htaccess, self::HTACCESS) === false) {
            throw new TempFileException('failed to write temporary file directory .htaccess');
        }

        $index = $this->baseDir . '/index.html';

        if (!is_file($index) && file_put_contents($index, '') === false) {
            throw new TempFileException('failed to write temporary file directory index.html');
        }
    }
}
