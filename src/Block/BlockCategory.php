<?php

declare(strict_types=1);

namespace TMSWP\Core\Block;

use TMSWP\Core\Exception\KernelException;

/**
 * KernelConfigのblockCategoriesで登録するブロックカテゴリ。Kernelが`block_categories_all`でWordPressの
 * カテゴリの一覧の末尾に足す。
 */
final readonly class BlockCategory
{
    /**
     * WordPressが既定で登録するカテゴリのスラッグ(WordPress 7.0のget_default_block_categories())。block.jsonの
     * categoryには、これとKernelConfigのblockCategoriesのスラッグだけを書ける。
     */
    public const array CORE_SLUGS = ['text', 'media', 'design', 'widgets', 'theme', 'embed', 'reusable'];

    /**
     * @param string $slug block.jsonのcategoryに書くスラッグ(英小文字・数字・`-`)
     * @param string $title ブロックの挿入画面に出す見出し
     * @param string|null $icon Dashiconsの名前(`dashicons-`を除く)。nullなら出さない
     *
     * @throws KernelException スラッグの形式が違う、または見出しが空の場合
     */
    public function __construct(
        public string $slug,
        public string $title,
        public ?string $icon = null,
    ) {
        if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            throw new KernelException("invalid block category slug '{$slug}': use lowercase letters, digits and '-'");
        }

        if ($title === '') {
            throw new KernelException("block category '{$slug}' requires a non-empty title");
        }
    }

    /**
     * `block_categories_all`の要素の形にする。
     *
     * @return array{slug: string, title: string, icon: string|null}
     */
    public function toArray(): array
    {
        return ['slug' => $this->slug, 'title' => $this->title, 'icon' => $this->icon];
    }
}
