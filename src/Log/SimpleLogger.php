<?php

declare(strict_types=1);

namespace TMSWP\Core\Log;

use BackedEnum;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Support\Str;

/**
 * 依存ゼロのファイルロガー(PSR-3 v3)。
 *
 * ファイル名は `{fileNameFormat の展開結果}-{wp_hash 由来の接尾辞}.log` とし、URL から推測できないようにする。
 * ファイルへ書き込めないときは、その行を PHP の error_log() へ出し、初回だけ wp_trigger_error() で知らせる。
 * ローテーションに失敗したときは、その理由を PHP の error_log() へ出し、同じく初回だけ知らせる。
 */
final class SimpleLogger implements LoggerInterface
{
    use LoggerTrait;

    private const string EXTENSION = '.log';

    private const string HASH_SEED = 'tmswp-simple-logger';

    private const array LEVELS = [
        LogLevel::DEBUG,
        LogLevel::INFO,
        LogLevel::NOTICE,
        LogLevel::WARNING,
        LogLevel::ERROR,
        LogLevel::CRITICAL,
        LogLevel::ALERT,
        LogLevel::EMERGENCY,
    ];

    private readonly string $dir;

    /** fileNameFormat の最後のセグメント(ローテーションで自分のファイルを見分けるのに使う) */
    private readonly string $fileBaseFormat;

    private bool $failureReported = false;

    /**
     * @param string|null $dir 出力先ディレクトリ。null なら WP_CONTENT_DIR . '/tmswp-logs'
     * @param string $fileNameFormat 拡張子を除いたファイル名。{date} と {level} を展開し、/ はサブディレクトリになる
     * @param int $maxFiles ディレクトリごとに残す自分のファイル数。0 ならローテーションしない
     *
     * @throws InvalidArgumentException 引数が不正なとき、または dir を省略して WP_CONTENT_DIR が無いとき
     */
    public function __construct(
        ?string $dir = null,
        private readonly string $fileNameFormat = '{date}',
        private readonly string $dateFormat = 'Y-m-d H:i:s',
        private readonly int $maxFiles = 30,
    ) {
        if ($maxFiles < 0) {
            throw new InvalidArgumentException('maxFiles must not be negative');
        }

        if ($fileNameFormat === '') {
            throw new InvalidArgumentException('fileNameFormat must not be empty');
        }

        $segments = preg_split('#[\\\\/]+#', $fileNameFormat);

        if ($segments === false || \in_array('..', $segments, true)) {
            throw new InvalidArgumentException("fileNameFormat must not contain '..' segments: {$fileNameFormat}");
        }

        $this->fileBaseFormat = (string) end($segments);

        if ($dir === null) {
            if (!\defined('WP_CONTENT_DIR')) {
                throw new InvalidArgumentException('SimpleLogger requires WP_CONTENT_DIR when dir is omitted');
            }

            $dir = WP_CONTENT_DIR . '/tmswp-logs';
        }

        $this->dir = rtrim($dir, '/\\');
    }

    /**
     * @param mixed $level
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!\is_string($level) || !\in_array($level, self::LEVELS, true)) {
            throw new \Psr\Log\InvalidArgumentException('unknown log level: ' . get_debug_type($level));
        }

        $line = $this->formatLine($level, $message, $context);
        $failure = $this->writeToFile($level, $line);

        if ($failure !== null) {
            // ログの喪失を防ぐため、ファイルに書けなかった行は PHP 標準のエラーログへ出す。
            error_log($line);
            $this->reportFailureOnce("ログをファイルに書き込めないため、PHP の error_log へ出力します。{$failure}");
        }
    }

    /**
     * 行をログファイルへ追記する。
     *
     * @return string|null 失敗した理由。成功したら null
     */
    private function writeToFile(string $level, string $line): ?string
    {
        // wp_hash() は pluggable.php にあり、プラグインのファイルを読み込む間はまだ定義されていない。
        if (!\function_exists('wp_hash')) {
            return 'wp_hash() が未定義のため、ログファイル名を決められません(pluggable.php の読み込み前)';
        }

        // 接尾辞を fileNameFormat ごとに分け、ローテーションで別の形式のロガーのファイルを自分のものと見なさない。
        $suffix = wp_hash(self::HASH_SEED . ':' . $this->fileNameFormat);
        $baseFailure = $this->prepareDirectory($this->dir, true);

        if ($baseFailure !== null) {
            return $baseFailure;
        }

        $relative = Str::replace($this->fileNameFormat, [
            'date' => wp_date('Ymd'),
            'level' => $level,
        ]);
        $path = $this->dir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative) . '-' . $suffix . self::EXTENSION;
        $fileDir = \dirname($path);

        if ($fileDir !== $this->dir) {
            $fileDirFailure = $this->prepareDirectory($fileDir, false);

            if ($fileDirFailure !== null) {
                return $fileDirFailure;
            }
        }

        $isNewFile = !is_file($path);

        if (!$isNewFile && !is_writable($path)) {
            return "ログファイルに書き込めません: {$path}";
        }

        if (!error_log($line . "\n", 3, $path)) {
            return "ログファイルへの追記に失敗しました: {$path}";
        }

        if ($isNewFile && $this->maxFiles > 0) {
            $rotationFailure = $this->rotate($fileDir, $suffix);

            if ($rotationFailure !== null) {
                $message = "ログのローテーションに失敗しました。{$rotationFailure}";
                error_log(self::class . ': ' . $message);
                $this->reportFailureOnce($message);
            }
        }

        return null;
    }

    /**
     * ディレクトリを作成し、書き込めることを確かめて保護ファイルを置く。
     *
     * @return string|null 失敗した理由。成功したら null
     */
    private function prepareDirectory(string $dir, bool $isBase): ?string
    {
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return "ログディレクトリを作成できません: {$dir}";
        }

        if (!is_writable($dir)) {
            return "ログディレクトリに書き込めません: {$dir}";
        }

        $files = ['index.php' => '<?php // Silence is golden.'];

        if ($isBase) {
            $files['.htaccess'] = "Require all denied\n";
        }

        foreach ($files as $name => $contents) {
            $file = $dir . DIRECTORY_SEPARATOR . $name;

            if (!is_file($file) && file_put_contents($file, $contents) === false) {
                return "保護ファイルを作成できません: {$file}";
            }
        }

        return null;
    }

    /**
     * 失敗を wp_trigger_error() で知らせる。インスタンスごとに初回の失敗だけを知らせる。
     */
    private function reportFailureOnce(string $message): void
    {
        if ($this->failureReported) {
            return;
        }

        $this->failureReported = true;
        wp_trigger_error(self::class . '::log', $message, E_USER_WARNING);
    }

    /** @param array<mixed> $context */
    private function formatLine(string $level, string|Stringable $message, array $context): string
    {
        $exception = $context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            unset($context['exception']);
        }

        $line = wp_date($this->dateFormat) . ' [' . strtoupper($level) . '] ' . $this->interpolate((string) $message, $context);
        $contextJson = $this->contextJson($context);

        if ($contextJson !== '') {
            $line .= ' ' . $contextJson;
        }

        if ($exception instanceof Throwable) {
            $line .= ' ' . $this->formatException($exception);
        }

        // 1記録を必ず1行にする(メッセージ中の改行による行の偽装を防ぐ)。
        return str_replace(["\r", "\n"], ['\r', '\n'], $line);
    }

    private function formatException(Throwable $exception): string
    {
        $parts = [];

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $parts[] = ($parts === [] ? '' : 'Caused by: ')
                . $current::class . ': ' . $current->getMessage() . ' in ' . $current->getFile() . ':' . $current->getLine() . "\n"
                . $current->getTraceAsString();
        }

        return implode("\n", $parts);
    }

    /** @param array<mixed> $context */
    private function interpolate(string $message, array $context): string
    {
        return preg_replace_callback('/\{([A-Za-z0-9_.]+)\}/', static function (array $matches) use ($context): string {
            $key = $matches[1];

            if (!\array_key_exists($key, $context)) {
                return $matches[0];
            }

            $value = $context[$key];

            if ($value === null || \is_scalar($value) || $value instanceof Stringable || $value instanceof BackedEnum) {
                return Str::stringify($value);
            }

            return $matches[0];
        }, $message) ?? $message;
    }

    /** @param array<mixed> $context */
    private function contextJson(array $context): string
    {
        if ($context === []) {
            return '';
        }

        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return \is_string($json) ? $json : '';
    }

    /**
     * $dir 内の自分のファイル(fileNameFormat と接尾辞に一致するもの)だけを数え、古いものから削除する。
     *
     * @return string|null 失敗した理由。成功したら null
     */
    private function rotate(string $dir, string $suffix): ?string
    {
        // glob() は $dir の [ や * をパターンとして解釈するため、scandir() で $dir を文字どおりに列挙する。
        $entries = scandir($dir);

        if ($entries === false) {
            return "ログディレクトリを列挙できません: {$dir}";
        }

        $pattern = $this->ownFilePattern($suffix);
        $mtimes = [];

        foreach ($entries as $entry) {
            if (preg_match($pattern, $entry) !== 1) {
                continue;
            }

            $file = $dir . DIRECTORY_SEPARATOR . $entry;

            // このロガーはディレクトリやシンボリックリンクを作らないため、名前が一致しても自分のファイルではない。
            if (is_link($file) || !is_file($file)) {
                continue;
            }

            $mtime = filemtime($file);

            if ($mtime === false) {
                return "ログファイルの更新日時を取得できません: {$file}";
            }

            $mtimes[$file] = $mtime;
        }

        if (\count($mtimes) <= $this->maxFiles) {
            return null;
        }

        asort($mtimes);
        $failures = [];

        foreach (\array_slice(array_keys($mtimes), 0, \count($mtimes) - $this->maxFiles) as $file) {
            if (!unlink($file)) {
                $failures[] = $file;
            }
        }

        return $failures === [] ? null : '古いログファイルを削除できません: ' . implode(', ', $failures);
    }

    private function ownFilePattern(string $suffix): string
    {
        $levels = implode('|', array_map(static fn(string $level): string => preg_quote($level, '/'), self::LEVELS));

        $pattern = strtr(preg_quote($this->fileBaseFormat, '/'), [
            preg_quote('{date}', '/') => '[0-9]{8}',
            preg_quote('{level}', '/') => '(?:' . $levels . ')',
        ]);

        return '/\A' . $pattern . preg_quote('-' . $suffix . self::EXTENSION, '/') . '\z/';
    }
}
