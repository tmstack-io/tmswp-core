<?php

declare(strict_types=1);

namespace TMSWP\Core\Kernel;

use Closure;
use TMSWP\Core\Block\BlockCategory;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Log\LogChannel;

final readonly class KernelConfig
{
    private const DISCOVERY_FILE_NAME = 'tmswp-discovery.php';

    /**
     * @param list<ExtensionInterface> $extensions
     * @param (Closure(): array<string, LogChannel>)|null $logChannels
     * @param list<BlockCategory> $blockCategories
     */
    private function __construct(
        public KernelContext $context,
        public string $appDir,
        public string $appNamespace,
        public ?string $pluginFile,
        public bool $debug,
        public array $extensions,
        public ?Closure $logChannels,
        public LabelLanguage $labelLanguage,
        public array $blockCategories,
    ) {}

    /**
     * @param list<ExtensionInterface> $extensions この Kernel で有効にする拡張。同じクラスは1つまで
     * @param (Closure(): array<string, LogChannel>)|null $logChannels ログのチャネル(チャネル名 => 設定)を返すクロージャ。
     *                                                                 boot 中に1回呼ぶ。null なら default チャネル(SimpleLogger)を遅延生成する
     * @param LabelLanguage $labelLanguage labelLanguage() を指定しない PostTypeDefinition・TaxonomyDefinition のラベル言語
     * @param list<BlockCategory> $blockCategories このKernelが`block_categories_all`で足すブロックカテゴリ。スラッグは
     *                                             Kernelの中で1つまでで、WordPressの既定のカテゴリと同じスラッグは使えない
     *
     * @throws KernelException 引数が不正な場合
     */
    public static function theme(
        string $appDir,
        string $appNamespace = 'App',
        ?bool $debug = null,
        array $extensions = [],
        ?Closure $logChannels = null,
        LabelLanguage $labelLanguage = LabelLanguage::En,
        array $blockCategories = [],
    ): self {
        return new self(
            KernelContext::Theme,
            self::resolveAppDir($appDir),
            self::normalizeNamespace($appNamespace),
            null,
            self::resolveDebug($debug),
            self::validateExtensions($extensions),
            $logChannels,
            $labelLanguage,
            self::validateBlockCategories($blockCategories),
        );
    }

    /**
     * @param list<ExtensionInterface> $extensions この Kernel で有効にする拡張。同じクラスは1つまで
     * @param (Closure(): array<string, LogChannel>)|null $logChannels ログのチャネル(チャネル名 => 設定)を返すクロージャ。
     *                                                                 boot 中に1回呼ぶ。null なら default チャネル(SimpleLogger)を遅延生成する
     * @param LabelLanguage $labelLanguage labelLanguage() を指定しない PostTypeDefinition・TaxonomyDefinition のラベル言語
     * @param list<BlockCategory> $blockCategories このKernelが`block_categories_all`で足すブロックカテゴリ。スラッグは
     *                                             Kernelの中で1つまでで、WordPressの既定のカテゴリと同じスラッグは使えない
     *
     * @throws KernelException 引数が不正な場合
     */
    public static function plugin(
        string $file,
        string $appDir,
        string $appNamespace = 'App',
        ?bool $debug = null,
        array $extensions = [],
        ?Closure $logChannels = null,
        LabelLanguage $labelLanguage = LabelLanguage::En,
        array $blockCategories = [],
    ): self {
        $pluginFile = realpath($file);

        if ($pluginFile === false || !is_file($pluginFile)) {
            throw new KernelException("plugin file not found: {$file}");
        }

        return new self(
            KernelContext::Plugin,
            self::resolveAppDir($appDir),
            self::normalizeNamespace($appNamespace),
            $pluginFile,
            self::resolveDebug($debug),
            self::validateExtensions($extensions),
            $logChannels,
            $labelLanguage,
            self::validateBlockCategories($blockCategories),
        );
    }

    /**
     * KernelConfigを返すPHPファイル(テーマ・プラグインのルートのtmswp-kernel.php)を読み込み、そのKernelConfigを返す。
     * functions.php・プラグインの本体ファイルのbootと、拡張のパッケージがWordPressを起動せずに実行するコマンドが同じ
     * この関数で読むため、ファイルの中ではWordPressの関数を呼ばない。
     *
     * 相対パスはカレントディレクトリを基準にした絶対パス(realpath())に確定し、検査したそのファイルを読み込む。相対パスの
     * まま読み込むと、PHPがinclude_pathを先に探し、別の同じ名前のファイルを読みうるためである。
     *
     * @throws KernelException ファイルが無い・読めない場合と、ファイルがKernelConfigを返さない場合
     */
    public static function load(string $file): self
    {
        $path = realpath($file);

        if ($path === false || !is_file($path) || !is_readable($path)) {
            throw new KernelException("kernel config file not found or not readable: {$file}; put a file that returns KernelConfig::theme() or KernelConfig::plugin() there");
        }

        $config = (static fn(string $absolutePath): mixed => require $absolutePath)($path);

        if (!$config instanceof self) {
            throw new KernelException("kernel config file {$file} must return " . self::class . ', got ' . get_debug_type($config));
        }

        return $config;
    }

    /**
     * テーマ・プラグインのルートディレクトリ(appDir の親ディレクトリ)。探索結果ファイルの置き場で、Log の静的な呼び出しが
     * 記録先の Kernel を決める範囲でもある。
     */
    public function rootDir(): string
    {
        return \dirname($this->appDir);
    }

    /**
     * 探索結果ファイルの絶対パス。appDir の親ディレクトリ(テーマ・プラグインのルート)直下に固定する。
     */
    public function discoveryFile(): string
    {
        return $this->rootDir() . '/' . self::DISCOVERY_FILE_NAME;
    }

    /**
     * @param list<ExtensionInterface> $extensions
     *
     * @throws KernelException 同じクラスの拡張が2つ以上ある場合(設定の誤り)
     *
     * @return list<ExtensionInterface>
     */
    private static function validateExtensions(array $extensions): array
    {
        $classes = [];

        foreach ($extensions as $extension) {
            $class = $extension::class;

            if (\array_key_exists($class, $classes)) {
                throw new KernelException("extension {$class} is registered more than once in the same kernel");
            }

            $classes[$class] = true;
        }

        return $extensions;
    }

    /**
     * @param list<BlockCategory> $blockCategories
     *
     * @throws KernelException 同じスラッグのカテゴリが2つ以上ある、またはWordPressの既定のカテゴリと同じスラッグの
     *                         場合(設定の誤り)
     *
     * @return list<BlockCategory>
     */
    private static function validateBlockCategories(array $blockCategories): array
    {
        $slugs = [];

        foreach ($blockCategories as $category) {
            if (\in_array($category->slug, BlockCategory::CORE_SLUGS, true)) {
                throw new KernelException("block category '{$category->slug}' is a WordPress core category; use it in block.json without registering it");
            }

            if (\array_key_exists($category->slug, $slugs)) {
                throw new KernelException("block category '{$category->slug}' is registered more than once in the same kernel");
            }

            $slugs[$category->slug] = true;
        }

        return $blockCategories;
    }

    private static function normalizeNamespace(string $appNamespace): string
    {
        $namespace = trim($appNamespace, '\\');

        if ($namespace === '') {
            throw new KernelException('app namespace must not be empty');
        }

        if (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/', $namespace) !== 1) {
            throw new KernelException("invalid app namespace: {$appNamespace}");
        }

        // PHPの名前空間は大文字と小文字を区別しないため、Tmswp・tmswp\App等もTMSWPの名前空間として拒む
        $lower = strtolower($namespace);

        if ($lower === 'tmswp' || str_starts_with($lower, 'tmswp\\')) {
            throw new KernelException("app namespace is reserved by TMSWP core: {$namespace}");
        }

        return $namespace;
    }

    private static function resolveAppDir(string $appDir): string
    {
        $realPath = realpath($appDir);

        if ($realPath === false || !is_dir($realPath) || !is_readable($realPath)) {
            throw new KernelException("app directory not found: {$appDir}");
        }

        return rtrim($realPath, '/\\');
    }

    private static function resolveDebug(?bool $debug): bool
    {
        if ($debug !== null) {
            return $debug;
        }

        // && は真偽値を返すため、WP_DEBUG が 1 や 'true' 等の真値でも true になる(true との厳密比較はしない)
        return \defined('WP_DEBUG') && WP_DEBUG;
    }
}
