<?php

declare(strict_types=1);

namespace TMSWP\Core\Label;

/**
 * register_post_type / register_taxonomy の labels 引数を、FW 内蔵のテンプレートから組み立てる。
 *
 * テンプレートの `%1$s` は単数形、`%2$s` は複数形に `sprintf` で展開する。gettext は通さない。
 * キーの集合と、階層の有無で null になるキーは、WordPress コアの get_post_type_labels() / get_taxonomy_labels()
 * (`WP_Post_Type::get_default_labels()`・`WP_Taxonomy::get_default_labels()` と `_get_custom_object_labels()`)に合わせる。
 * En の文言はコアの既定の英語ラベルのオブジェクト名の部分にラベルをそのまま入れたもので、大文字・小文字は変えず、
 * 名詞の前の不定冠詞(a/an)は除く。
 */
final class LabelGenerator
{
    /** 非階層の投稿タイプでコアが null にするキー */
    private const POST_TYPE_HIERARCHICAL_ONLY = ['parent_item_colon'];

    /** 非階層のタクソノミーでコアが null にするキー */
    private const TAXONOMY_HIERARCHICAL_ONLY = ['parent_item', 'parent_item_colon', 'parent_field_description', 'filter_by_item'];

    /** 階層のタクソノミーでコアが null にするキー */
    private const TAXONOMY_FLAT_ONLY = ['popular_items', 'separate_items_with_commas', 'add_or_remove_items', 'choose_from_most_used'];

    /**
     * @param string $singular 単数形(`%1$s`)
     * @param string $plural 複数形(`%2$s`)
     * @param bool $hierarchical 投稿タイプが階層か(register_post_type の hierarchical)
     *
     * @return array<string, string|null> register_post_type の labels 引数
     */
    public static function forPostType(string $singular, string $plural, LabelLanguage $language, bool $hierarchical): array
    {
        $templates = match ($language) {
            LabelLanguage::Ja => [
                'name' => '%2$s',
                'singular_name' => '%1$s',
                'add_new' => '新規追加',
                'add_new_item' => '新規%1$sを追加',
                'edit_item' => '%1$sを編集',
                'new_item' => '新規%1$s',
                'view_item' => '%1$sを表示',
                'view_items' => '%2$s一覧を表示',
                'search_items' => '%2$sを検索',
                'not_found' => '%2$sが見つかりませんでした',
                'not_found_in_trash' => 'ゴミ箱内に%2$sが見つかりませんでした',
                'parent_item_colon' => '親%1$s:',
                'all_items' => '%2$s一覧',
                'archives' => '%1$sアーカイブ',
                'attributes' => '%1$sの属性',
                'insert_into_item' => '%1$sに挿入',
                'uploaded_to_this_item' => 'この%1$sへのアップロード',
                'featured_image' => 'アイキャッチ画像',
                'set_featured_image' => 'アイキャッチ画像を設定',
                'remove_featured_image' => 'アイキャッチ画像を削除',
                'use_featured_image' => 'アイキャッチ画像として使用',
                'filter_items_list' => '%2$s一覧を絞り込む',
                'filter_by_date' => '日付で絞り込む',
                'items_list_navigation' => '%2$s一覧ナビゲーション',
                'items_list' => '%2$s一覧',
                'item_published' => '%1$sを公開しました',
                'item_published_privately' => '%1$sを非公開で公開しました',
                'item_reverted_to_draft' => '%1$sを下書きに戻しました',
                'item_trashed' => '%1$sをゴミ箱に移動しました',
                'item_scheduled' => '%1$sの公開を予約しました',
                'item_updated' => '%1$sを更新しました',
                'item_link' => '%1$sリンク',
                'item_link_description' => '%1$sへのリンク',
                'menu_name' => '%2$s',
                'name_admin_bar' => '%1$s',
                'template_name' => '%1$sの個別ページ',
            ],
            LabelLanguage::En => [
                'name' => '%2$s',
                'singular_name' => '%1$s',
                'add_new' => 'Add',
                'add_new_item' => 'Add %1$s',
                'edit_item' => 'Edit %1$s',
                'new_item' => 'New %1$s',
                'view_item' => 'View %1$s',
                'view_items' => 'View %2$s',
                'search_items' => 'Search %2$s',
                'not_found' => 'No %2$s found.',
                'not_found_in_trash' => 'No %2$s found in Trash.',
                'parent_item_colon' => 'Parent %1$s:',
                'all_items' => 'All %2$s',
                'archives' => '%1$s Archives',
                'attributes' => '%1$s Attributes',
                'insert_into_item' => 'Insert into %1$s',
                'uploaded_to_this_item' => 'Uploaded to this %1$s',
                'featured_image' => 'Featured image',
                'set_featured_image' => 'Set featured image',
                'remove_featured_image' => 'Remove featured image',
                'use_featured_image' => 'Use as featured image',
                'filter_items_list' => 'Filter %2$s list',
                'filter_by_date' => 'Filter by date',
                'items_list_navigation' => '%2$s list navigation',
                'items_list' => '%2$s list',
                'item_published' => '%1$s published.',
                'item_published_privately' => '%1$s published privately.',
                'item_reverted_to_draft' => '%1$s reverted to draft.',
                'item_trashed' => '%1$s trashed.',
                'item_scheduled' => '%1$s scheduled.',
                'item_updated' => '%1$s updated.',
                'item_link' => '%1$s Link',
                'item_link_description' => 'A link to %1$s.',
                'menu_name' => '%2$s',
                'name_admin_bar' => '%1$s',
                'template_name' => 'Single item: %1$s',
            ],
        };

        return self::expand($templates, $hierarchical ? [] : self::POST_TYPE_HIERARCHICAL_ONLY, $singular, $plural);
    }

    /**
     * @param string $singular 単数形(`%1$s`)
     * @param string $plural 複数形(`%2$s`)
     * @param bool $hierarchical タクソノミーが階層か(register_taxonomy の hierarchical)
     *
     * @return array<string, string|null> register_taxonomy の labels 引数
     */
    public static function forTaxonomy(string $singular, string $plural, LabelLanguage $language, bool $hierarchical): array
    {
        $templates = match ($language) {
            LabelLanguage::Ja => [
                'name' => '%2$s',
                'singular_name' => '%1$s',
                'search_items' => '%2$sを検索',
                'popular_items' => 'よく使われている%2$s',
                'all_items' => '%2$s一覧',
                'parent_item' => '親%1$s',
                'parent_item_colon' => '親%1$s:',
                'name_field_description' => 'サイト上に表示される名前です。',
                'slug_field_description' => '「スラッグ」はURLに適した形式の名前です。通常はすべて小文字で、半角英数字とハイフンのみを含みます。',
                'parent_field_description' => '親タームを指定して階層を作ります。たとえば「ジャズ」というタームは「ビバップ」や「ビッグバンド」の親になります。',
                'desc_field_description' => '説明は標準では目立ちませんが、テーマによっては表示されます。',
                'edit_item' => '%1$sを編集',
                'view_item' => '%1$sを表示',
                'update_item' => '%1$sを更新',
                'add_new_item' => '新規%1$sを追加',
                'new_item_name' => '新規%1$s名',
                'separate_items_with_commas' => '複数の%2$sはコンマで区切ってください',
                'add_or_remove_items' => '%2$sの追加または削除',
                'choose_from_most_used' => 'よく使われている%2$sから選択',
                'not_found' => '%2$sが見つかりませんでした',
                'no_terms' => '%2$sなし',
                'filter_by_item' => '%1$sで絞り込む',
                'items_list_navigation' => '%2$s一覧ナビゲーション',
                'items_list' => '%2$s一覧',
                'most_used' => 'よく使うもの',
                'back_to_items' => '← %2$s一覧へ戻る',
                'item_link' => '%1$sリンク',
                'item_link_description' => '%1$sへのリンク',
                'menu_name' => '%2$s',
                'name_admin_bar' => '%1$s',
                'archives' => '%2$s一覧',
                'template_name' => '%1$sアーカイブ',
            ],
            LabelLanguage::En => [
                'name' => '%2$s',
                'singular_name' => '%1$s',
                'search_items' => 'Search %2$s',
                'popular_items' => 'Popular %2$s',
                'all_items' => 'All %2$s',
                'parent_item' => 'Parent %1$s',
                'parent_item_colon' => 'Parent %1$s:',
                'name_field_description' => 'The name is how it appears on your site.',
                'slug_field_description' => 'The &#8220;slug&#8221; is the URL-friendly version of the name. It is usually all lowercase and contains only letters, numbers, and hyphens.',
                'parent_field_description' => 'Assign a parent term to create a hierarchy. The term Jazz, for example, would be the parent of Bebop and Big Band.',
                'desc_field_description' => 'The description is not prominent by default; however, some themes may show it.',
                'edit_item' => 'Edit %1$s',
                'view_item' => 'View %1$s',
                'update_item' => 'Update %1$s',
                'add_new_item' => 'Add %1$s',
                'new_item_name' => 'New %1$s Name',
                'separate_items_with_commas' => 'Separate %2$s with commas',
                'add_or_remove_items' => 'Add or remove %2$s',
                'choose_from_most_used' => 'Choose from the most used %2$s',
                'not_found' => 'No %2$s found.',
                'no_terms' => 'No %2$s',
                'filter_by_item' => 'Filter by %1$s',
                'items_list_navigation' => '%2$s list navigation',
                'items_list' => '%2$s list',
                'most_used' => 'Most Used',
                'back_to_items' => '← Go to %2$s',
                'item_link' => '%1$s Link',
                'item_link_description' => 'A link to %1$s.',
                'menu_name' => '%2$s',
                'name_admin_bar' => '%1$s',
                'archives' => 'All %2$s',
                'template_name' => '%1$s Archives',
            ],
        };

        return self::expand($templates, $hierarchical ? self::TAXONOMY_FLAT_ONLY : self::TAXONOMY_HIERARCHICAL_ONLY, $singular, $plural);
    }

    /**
     * @param array<string, string> $templates
     * @param list<string> $nullKeys コアがこの階層の有無で null にするキー
     *
     * @return array<string, string|null>
     */
    private static function expand(array $templates, array $nullKeys, string $singular, string $plural): array
    {
        $labels = [];

        foreach ($templates as $key => $template) {
            $labels[$key] = \in_array($key, $nullKeys, true) ? null : \sprintf($template, $singular, $plural);
        }

        return $labels;
    }
}
