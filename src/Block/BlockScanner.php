<?php

declare(strict_types=1);

namespace TMSWP\Core\Block;

use FilesystemIterator;
use JsonException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TMSWP\Core\Exception\DiscoveryException;
use TMSWP\Core\Kernel\KernelConfig;
use UnexpectedValueException;

/**
 * テーマ・プラグインのルートの`build/blocks/`以下(入れ子を含む)のblock.jsonを走査する。
 *
 * 走査するのはnpmのビルドの出力だけで、ソース(`blocks/`)は見ない。探索コマンドとdebugのbootが
 * Discovery::scan()から呼ぶ。失敗はdebugに関係なくDiscoveryExceptionにし、途中までの結果は返さない。
 */
final class BlockScanner
{
    /** 走査するディレクトリの、テーマ・プラグインのルートからの相対パス */
    public const string BUILD_DIR = 'build/blocks';

    private const string HINT = "; fix the block source and rebuild it, then run 'wp tmswp discover'";

    public function __construct(
        private readonly KernelConfig $config,
    ) {}

    /**
     * block.jsonを読み、ブロック名の昇順(strcmp)で返す。`build/blocks/`が無ければ空を返す。
     *
     * @throws DiscoveryException block.jsonを読めない・JSONのオブジェクトでない・nameの形式が違う、categoryが
     *                            登録されていない、または同じnameのblock.jsonが2つ以上ある場合
     *
     * @return list<DiscoveredBlock>
     */
    public function scan(): array
    {
        $root = $this->config->rootDir();
        $buildDir = $root . '/' . self::BUILD_DIR;

        if (!file_exists($buildDir)) {
            return [];
        }

        if (!is_dir($buildDir)) {
            throw new DiscoveryException("{$buildDir} must be a directory of built blocks" . self::HINT);
        }

        /** @var array<string, DiscoveredBlock> $blocks ブロック名 => ブロック */
        $blocks = [];

        foreach ($this->metadataFiles($buildDir) as $relativeDir => $file) {
            $block = $this->read($file, $relativeDir);

            if (\array_key_exists($block->name, $blocks)) {
                throw new DiscoveryException("block name '{$block->name}' is declared by both {$root}/{$blocks[$block->name]->path}/block.json and {$file}; block names must be unique" . self::HINT);
            }

            $blocks[$block->name] = $block;
        }

        uksort($blocks, strcmp(...));

        return array_values($blocks);
    }

    /**
     * `build/blocks/`以下のblock.jsonを、そのディレクトリのルートからの相対パス => block.jsonのパスで返す。
     * 相対パスのstrcmp順に並べる(重複の報告を環境によらず同じにするため)。
     *
     * @throws DiscoveryException `build/blocks/`かその下のディレクトリを読めない場合(途中までの結果で走査を続けない)
     *
     * @return array<non-empty-string, string>
     */
    private function metadataFiles(string $buildDir): array
    {
        $files = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($buildDir, FilesystemIterator::SKIP_DOTS),
                    static fn(SplFileInfo $current): bool => !$current->isDir() || !str_starts_with($current->getBasename(), '.'),
                ),
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile() || $fileInfo->getBasename() !== 'block.json') {
                    continue;
                }

                $relativeDir = self::BUILD_DIR . str_replace(DIRECTORY_SEPARATOR, '/', substr($fileInfo->getPath(), \strlen($buildDir)));
                $files[$relativeDir] = $fileInfo->getPathname();
            }
        } catch (UnexpectedValueException $exception) {
            // ディレクトリを開けない(権限が無い等)。RecursiveDirectoryIteratorの生成と、子のディレクトリへの反復で起きる
            throw new DiscoveryException(
                "failed to read the built blocks under {$buildDir}: {$exception->getMessage()}; make the directories readable, then run 'wp tmswp discover' again",
                previous: $exception,
            );
        }

        uksort($files, strcmp(...));

        return $files;
    }

    /**
     * block.jsonを1つ読み、nameとcategoryを検査する。
     *
     * @param non-empty-string $relativeDir
     *
     * @throws DiscoveryException
     */
    private function read(string $file, string $relativeDir): DiscoveredBlock
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new DiscoveryException("failed to read {$file}" . self::HINT);
        }

        try {
            $metadata = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            $canonical = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DiscoveryException("invalid JSON in {$file}: {$exception->getMessage()}" . self::HINT, previous: $exception);
        }

        if (!\is_array($metadata) || array_is_list($metadata)) {
            throw new DiscoveryException("{$file} must contain a JSON object" . self::HINT);
        }

        $name = $metadata['name'] ?? null;

        if (!\is_string($name) || $name === '' || !BlockName::isValid($name)) {
            throw new DiscoveryException("{$file} must have a 'name' in the form 'namespace/name' (lowercase letters, digits and '-')" . self::HINT);
        }

        $this->assertRegisteredCategory($metadata['category'] ?? null, $name, $file);

        return new DiscoveredBlock($name, $relativeDir, hash('sha256', $canonical));
    }

    /**
     * categoryは省略できる。書いた場合は、WordPressの既定のカテゴリか、このKernelのKernelConfigの
     * blockCategoriesのスラッグでなければならない(別のKernelやプラグインのカテゴリは、探索の時点で有無を
     * 確かめられないため使えない)。
     *
     * @throws DiscoveryException
     */
    private function assertRegisteredCategory(mixed $category, string $name, string $file): void
    {
        if ($category === null) {
            return;
        }

        $registered = [
            ...BlockCategory::CORE_SLUGS,
            ...array_map(static fn(BlockCategory $blockCategory): string => $blockCategory->slug, $this->config->blockCategories),
        ];

        if (!\is_string($category) || !\in_array($category, $registered, true)) {
            $value = \is_string($category) ? "'{$category}'" : get_debug_type($category);

            throw new DiscoveryException(
                "block '{$name}' in {$file} uses the unregistered category {$value}; use one of WordPress's categories ("
                . implode(', ', BlockCategory::CORE_SLUGS)
                . ') or register it with the blockCategories of KernelConfig'
                . self::HINT,
            );
        }
    }
}
