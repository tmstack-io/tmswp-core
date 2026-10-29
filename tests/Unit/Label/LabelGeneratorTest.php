<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Label;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Label\LabelGenerator;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Testing\UnitTestCase;

/**
 * 期待するキーの一覧は WordPress 7.0 のコアから写したもの。
 *
 * - 投稿タイプ: WP_Post_Type::get_default_labels() のキーと、get_post_type_labels() が加える menu_name・template_name、
 *   _get_custom_object_labels() が加える name_admin_bar
 * - タクソノミー: WP_Taxonomy::get_default_labels() のキーと、get_taxonomy_labels() が加える menu_name・template_name、
 *   _get_custom_object_labels() が加える name_admin_bar・archives
 */
final class LabelGeneratorTest extends UnitTestCase
{
    private const POST_TYPE_KEYS = [
        'name', 'singular_name', 'add_new', 'add_new_item', 'edit_item', 'new_item', 'view_item', 'view_items',
        'search_items', 'not_found', 'not_found_in_trash', 'parent_item_colon', 'all_items', 'archives', 'attributes',
        'insert_into_item', 'uploaded_to_this_item', 'featured_image', 'set_featured_image', 'remove_featured_image',
        'use_featured_image', 'filter_items_list', 'filter_by_date', 'items_list_navigation', 'items_list',
        'item_published', 'item_published_privately', 'item_reverted_to_draft', 'item_trashed', 'item_scheduled',
        'item_updated', 'item_link', 'item_link_description',
        'menu_name', 'name_admin_bar', 'template_name',
    ];

    private const TAXONOMY_KEYS = [
        'name', 'singular_name', 'search_items', 'popular_items', 'all_items', 'parent_item', 'parent_item_colon',
        'name_field_description', 'slug_field_description', 'parent_field_description', 'desc_field_description',
        'edit_item', 'view_item', 'update_item', 'add_new_item', 'new_item_name', 'separate_items_with_commas',
        'add_or_remove_items', 'choose_from_most_used', 'not_found', 'no_terms', 'filter_by_item',
        'items_list_navigation', 'items_list', 'most_used', 'back_to_items', 'item_link', 'item_link_description',
        'menu_name', 'name_admin_bar', 'archives', 'template_name',
    ];

    /** @return iterable<string, array{LabelLanguage, bool}> */
    public static function languagesAndHierarchy(): iterable
    {
        foreach (LabelLanguage::cases() as $language) {
            yield "{$language->value} flat" => [$language, false];
            yield "{$language->value} hierarchical" => [$language, true];
        }
    }

    #[DataProvider('languagesAndHierarchy')]
    public function testPostTypeLabelsHaveExactlyTheCoreKeys(LabelLanguage $language, bool $hierarchical): void
    {
        $labels = LabelGenerator::forPostType('Book', 'Books', $language, $hierarchical);

        self::assertEqualsCanonicalizing(self::POST_TYPE_KEYS, array_keys($labels));
        // コアの既定と同じく、非階層では parent_item_colon だけが null になる
        self::assertSame($hierarchical ? [] : ['parent_item_colon'], self::nullKeysIn($labels));
    }

    #[DataProvider('languagesAndHierarchy')]
    public function testTaxonomyLabelsHaveExactlyTheCoreKeys(LabelLanguage $language, bool $hierarchical): void
    {
        $labels = LabelGenerator::forTaxonomy('Genre', 'Genres', $language, $hierarchical);

        self::assertEqualsCanonicalizing(self::TAXONOMY_KEYS, array_keys($labels));

        // コアの既定と同じく、階層の有無で片方だけが null になる
        $hierarchicalOnly = ['parent_item', 'parent_item_colon', 'parent_field_description', 'filter_by_item'];
        $flatOnly = ['popular_items', 'separate_items_with_commas', 'add_or_remove_items', 'choose_from_most_used'];
        self::assertSame($hierarchical ? $flatOnly : $hierarchicalOnly, self::nullKeysIn($labels));
    }

    public function testEnglishPostTypeLabelsUseSingularAndPluralLikeCore(): void
    {
        $labels = LabelGenerator::forPostType('Book', 'Books', LabelLanguage::En, true);

        self::assertSame('Books', $labels['name']);
        self::assertSame('Books', $labels['menu_name']);
        self::assertSame('All Books', $labels['all_items']);
        self::assertSame('Search Books', $labels['search_items']);
        self::assertSame('View Books', $labels['view_items']);
        self::assertSame('No Books found.', $labels['not_found']);
        self::assertSame('No Books found in Trash.', $labels['not_found_in_trash']);
        self::assertSame('Filter Books list', $labels['filter_items_list']);
        self::assertSame('Books list', $labels['items_list']);
        self::assertSame('Books list navigation', $labels['items_list_navigation']);

        self::assertSame('Book', $labels['singular_name']);
        self::assertSame('Book', $labels['name_admin_bar']);
        self::assertSame('Add Book', $labels['add_new_item']);
        self::assertSame('Edit Book', $labels['edit_item']);
        self::assertSame('New Book', $labels['new_item']);
        self::assertSame('View Book', $labels['view_item']);
        self::assertSame('Parent Book:', $labels['parent_item_colon']);
        self::assertSame('Book Archives', $labels['archives']);
        self::assertSame('Book Attributes', $labels['attributes']);
        self::assertSame('Insert into Book', $labels['insert_into_item']);
        self::assertSame('Uploaded to this Book', $labels['uploaded_to_this_item']);
        self::assertSame('Book published.', $labels['item_published']);
        self::assertSame('Book scheduled.', $labels['item_scheduled']);
        self::assertSame('Book Link', $labels['item_link']);
        self::assertSame('A link to Book.', $labels['item_link_description']);
        self::assertSame('Single item: Book', $labels['template_name']);
        self::assertSame('Featured image', $labels['featured_image']);
        self::assertSame('Add', $labels['add_new']);
    }

    public function testEnglishTaxonomyLabelsUseSingularAndPluralLikeCore(): void
    {
        $labels = LabelGenerator::forTaxonomy('Genre', 'Genres', LabelLanguage::En, false);

        self::assertSame('Genres', $labels['name']);
        self::assertSame('Genres', $labels['menu_name']);
        self::assertSame('All Genres', $labels['all_items']);
        self::assertSame('All Genres', $labels['archives']);
        self::assertSame('Search Genres', $labels['search_items']);
        self::assertSame('Popular Genres', $labels['popular_items']);
        self::assertSame('Separate Genres with commas', $labels['separate_items_with_commas']);
        self::assertSame('Add or remove Genres', $labels['add_or_remove_items']);
        self::assertSame('Choose from the most used Genres', $labels['choose_from_most_used']);
        self::assertSame('No Genres found.', $labels['not_found']);
        self::assertSame('No Genres', $labels['no_terms']);
        self::assertSame('← Go to Genres', $labels['back_to_items']);

        self::assertSame('Genre', $labels['singular_name']);
        self::assertSame('Genre', $labels['name_admin_bar']);
        self::assertSame('Edit Genre', $labels['edit_item']);
        self::assertSame('View Genre', $labels['view_item']);
        self::assertSame('Update Genre', $labels['update_item']);
        self::assertSame('Add Genre', $labels['add_new_item']);
        self::assertSame('New Genre Name', $labels['new_item_name']);
        self::assertSame('Genre Link', $labels['item_link']);
        self::assertSame('Genre Archives', $labels['template_name']);

        $hierarchical = LabelGenerator::forTaxonomy('Genre', 'Genres', LabelLanguage::En, true);
        self::assertSame('Parent Genre', $hierarchical['parent_item']);
        self::assertSame('Filter by Genre', $hierarchical['filter_by_item']);
    }

    public function testJapaneseLabelsExpandTheGivenForms(): void
    {
        $postType = LabelGenerator::forPostType('製品', '製品', LabelLanguage::Ja, false);
        self::assertSame('新規製品を追加', $postType['add_new_item']);
        self::assertSame('製品一覧', $postType['all_items']);
        self::assertSame('製品の公開を予約しました', $postType['item_scheduled']);
        self::assertSame('アイキャッチ画像', $postType['featured_image']);

        $taxonomy = LabelGenerator::forTaxonomy('製品カテゴリ', '製品カテゴリ', LabelLanguage::Ja, true);
        self::assertSame('親製品カテゴリ', $taxonomy['parent_item']);
        self::assertSame('製品カテゴリで絞り込む', $taxonomy['filter_by_item']);
        self::assertSame('← 製品カテゴリ一覧へ戻る', $taxonomy['back_to_items']);
    }

    public function testLabelsAreExpandedLiterallyWithoutFormatInterpretation(): void
    {
        $labels = LabelGenerator::forPostType('50% Off', '50% Offs', LabelLanguage::En, false);

        self::assertSame('Add 50% Off', $labels['add_new_item']);
        self::assertSame('All 50% Offs', $labels['all_items']);
    }

    /**
     * @param array<string, string|null> $labels
     *
     * @return list<string>
     */
    private static function nullKeysIn(array $labels): array
    {
        return array_keys(array_filter($labels, static fn(?string $label): bool => $label === null));
    }
}
