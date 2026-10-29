<?php

declare(strict_types=1);

namespace TMSWP\Core\Block;

use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Kernel\Kernel;
use WP_Block_Type;
use WP_Block_Type_Registry;

/**
 * Kernelが探索したブロックとブロックカテゴリをWordPressに登録する。
 *
 * ブロックは`init`(INIT_PRIORITY)で`register_block_type()`にblock.jsonのディレクトリを渡して登録する。render等の
 * ファイルの扱いはWordPressに任せる。カテゴリは`block_categories_all`で一覧の末尾に足す。
 */
final class BlockRegistrar
{
    /** ブロックを登録する`init`のpriority */
    public const int INIT_PRIORITY = 10;

    public function __construct(
        private readonly Kernel $kernel,
    ) {}

    /**
     * このKernelのブロックをすべて登録する。同じ名前のブロックが既に登録されているのは設定の誤り(別のKernelや
     * プラグインとの名前の重複)なので、debugに関係なく例外にする。
     *
     * @throws KernelException 同じ名前のブロックが登録済み、登録に失敗した、または登録したブロックの名前が探索結果と
     *                         違う(探索結果ファイルが古い)場合
     */
    public function registerBlocks(): void
    {
        $registry = WP_Block_Type_Registry::get_instance();

        foreach ($this->kernel->blocks() as $block) {
            $directory = $this->kernel->config()->rootDir() . '/' . $block->path;
            $source = "'{$block->name}' of the TMSWP kernel '{$this->kernel->config()->appNamespace}' ({$directory}/block.json)";

            if ($registry->is_registered($block->name)) {
                throw new KernelException("cannot register the block type {$source}: it is already registered by {$this->registeredBy($block->name)}; block names must be unique across the theme and plugins");
            }

            $registered = register_block_type($directory);

            if (!$registered instanceof WP_Block_Type) {
                throw new KernelException("failed to register the block type {$source}");
            }

            if ($registered->name !== $block->name) {
                throw new KernelException("the block type {$source} was registered as '{$registered->name}'; the discovery file is out of date, regenerate it with 'wp tmswp discover' and deploy it together with the code");
            }
        }
    }

    /**
     * `block_categories_all`のコールバック。このKernelのKernelConfigのblockCategoriesを末尾に足す。
     *
     * @param mixed $categories WordPressと、先に動いたフィルタが返した一覧
     *
     * @throws KernelException 一覧が配列でない、または同じスラッグのカテゴリが一覧に既にある場合
     *
     * @return array<mixed>
     */
    public function addCategories(mixed $categories): array
    {
        if (!\is_array($categories)) {
            throw new KernelException('block_categories_all must be an array of block categories, got ' . get_debug_type($categories));
        }

        $slugs = [];

        foreach ($categories as $category) {
            if (\is_array($category) && \is_string($category['slug'] ?? null)) {
                $slugs[] = $category['slug'];
            }
        }

        foreach ($this->kernel->config()->blockCategories as $category) {
            if (\in_array($category->slug, $slugs, true)) {
                throw new KernelException("cannot add the block category '{$category->slug}' of the TMSWP kernel '{$this->kernel->config()->appNamespace}': it is already added by {$this->categoryAddedBy($category->slug)}; block category slugs must be unique across the theme and plugins");
            }

            $categories[] = $category->toArray();
        }

        return $categories;
    }

    /** 登録済みのブロックの出どころ。ほかのKernelが探索したブロックなら、そのKernelとblock.jsonを示す。 */
    private function registeredBy(string $name): string
    {
        foreach (Kernel::all() as $kernel) {
            if ($kernel === $this->kernel) {
                continue;
            }

            foreach ($kernel->blocks() as $block) {
                if ($block->name === $name) {
                    return "the TMSWP kernel '{$kernel->config()->appNamespace}' ({$kernel->config()->rootDir()}/{$block->path}/block.json)";
                }
            }
        }

        return 'WordPress core or another plugin or theme (not by a TMSWP kernel)';
    }

    /** 一覧に既にあるカテゴリの出どころ。ほかのKernelのKernelConfigのカテゴリなら、そのKernelを示す。 */
    private function categoryAddedBy(string $slug): string
    {
        foreach (Kernel::all() as $kernel) {
            if ($kernel === $this->kernel) {
                continue;
            }

            foreach ($kernel->config()->blockCategories as $category) {
                if ($category->slug === $slug) {
                    return "the TMSWP kernel '{$kernel->config()->appNamespace}'";
                }
            }
        }

        return 'WordPress core or another plugin or theme (not by a TMSWP kernel)';
    }
}
