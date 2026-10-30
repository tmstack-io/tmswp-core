# WordPressのフックを登録する

テーマ・プラグイン固有のフックの処理は、`app/Hook/`の`#[AsHook]`のクラスに書く。フックの登録は、publicメソッドに付けた`#[Action]`・`#[Filter]`で宣言する。`add_action()`・`add_filter()`は直接書かない。
コード例の名前空間`App\`は、自分のプロジェクトの名前空間(composer.jsonの`autoload.psr-4`で`app/`に割り当て、`tmswp-kernel.php`の`appNamespace`に書いたもの。スターターから作ったプロジェクトでは、setup.phpがスラッグから作った名前。例: `my-theme`なら`MyTheme\`)に読み替える。

## 手順

1. `app/Hook/<関心事>Hook.php`を作る(例: `ThemeSetupHook`・`AdminCleanupHook`)。関心事ごとにクラスを分ける。
2. `#[AsHook]`を付け、`TMSWP\Core\Hook\Hook`を継承した`final class`にする。
3. publicのインスタンスメソッドに`#[Action('フック名')]`・`#[Filter('フック名')]`を付ける。priorityは`priority:`で渡す(既定は10)。
4. WordPressの環境で`wp tmswp discover`(スターターから作ったプロジェクトでは`composer discover`)を実行し、探索結果ファイル`tmswp-discovery.php`を作り直してコミットする。フックのメソッドを足す・消す・フック名を変えるたびに作り直す。
5. `composer qa`を通す。

## 完全な実装例

```php
<?php

declare(strict_types=1);

namespace App\Hook;

use TMSWP\Core\Attribute\Action;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Attribute\Filter;
use TMSWP\Core\Hook\Hook;

#[AsHook]
final class ThemeSetupHook extends Hook
{
    #[Action('after_setup_theme')]
    public function setupTheme(): void
    {
        add_theme_support('post-thumbnails');
        add_theme_support('title-tag');
    }

    #[Filter('excerpt_more')]
    public function excerptMore(string $more): string
    {
        return '...';
    }
}
```

## 管理画面・wp_head クリーンアップの実装例

管理メニュー・管理バー・ダッシュボード・wp_head 出力の整理は、削除対象がテーマ毎に異なるためFWはコードを提供しない。`app/Hook/` のフッククラスとして実装する。

```php
<?php

declare(strict_types=1);

namespace App\Hook;

use TMSWP\Core\Attribute\Action;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Hook\Hook;
use WP_Admin_Bar;

#[AsHook]
final class AdminCleanupHook extends Hook
{
    /** 削除対象はテーマの要件に合わせて決める(以下は一例)。 */
    private const MENUS = ['edit-comments.php', 'index.php'];

    private const ADMIN_BAR_NODES = ['wp-logo', 'comments', 'updates'];

    /** [ウィジェットID, コンテキスト] の組。 */
    private const DASHBOARD_WIDGETS = [
        ['dashboard_primary', 'side'],
        ['dashboard_quick_press', 'side'],
    ];

    #[Action('admin_menu', priority: 999)]
    public function removeAdminMenus(): void
    {
        foreach (self::MENUS as $menu) {
            remove_menu_page($menu);
        }
    }

    #[Action('admin_bar_menu', priority: 999)]
    public function removeAdminBarNodes(WP_Admin_Bar $bar): void
    {
        foreach (self::ADMIN_BAR_NODES as $node) {
            $bar->remove_node($node);
        }
    }

    #[Action('wp_dashboard_setup')]
    public function removeDashboardWidgets(): void
    {
        foreach (self::DASHBOARD_WIDGETS as [$id, $context]) {
            remove_meta_box($id, 'dashboard', $context);
        }

        remove_action('welcome_panel', 'wp_welcome_panel');
    }

    #[Action('init')]
    public function cleanupHead(): void
    {
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wp_shortlink_wp_head');
        remove_action('wp_head', 'rest_output_link_wp_head');
        remove_action('wp_head', 'feed_links_extra', 3);
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('admin_print_styles', 'print_emoji_styles');
        add_filter('emoji_svg_url', '__return_false');
    }
}
```

- 効果を理解した項目だけを削除する。全部入りのコピペをしない。
- 標準ウィジェットの解除が必要な場合も同様に `widgets_init`(priority 11)で `unregister_widget()` を呼ぶメソッドを足す。

## フックのメソッドの規則

- WordPressに渡す引数の数(`accepted_args`)は、メソッドの引数の数から決まる。属性で引数の数は指定しない。使う引数だけをメソッドに宣言する。
- `#[Filter]`のメソッドは、必ず値を返す。引数を宣言しないメソッドでも、フィルタの対象の値(第1引数)はWordPressから受け取っている。
- 1つのメソッドには、`#[Action]`・`#[Filter]`を1つだけ付ける(属性は繰り返せない)。同じ処理を2つのフックに登録するときは、メソッドを分ける。
- メソッドは、フックが発火したときにKernelのContainerから解決したインスタンスで呼ばれる。コンストラクタの引数にクラス・interfaceの型(`OptionStorageInterface`等)を書くと、Containerが渡す。
- メソッドが例外を投げたときは、Kernelのdebugでは例外がそのまま出る。本番ではKernelのロガーに`error`で記録し、そのメソッドだけを飛ばす(フィルタの値は変えない)。ログの文脈には、例外のクラス名と投げられた場所だけが入る。
- WordPressは数値の文字列等を引数に渡すことがある。フックの呼び出しはWordPressと同じ弱い型変換で行われるため、`int`の引数に数値の文字列が来ても`TypeError`にならない。

## 動的に登録する(register())

フック名をループで作る等、属性で書けない登録に限り、`Hook`の`register()`を上書きして`add_action()`・`add_filter()`を呼ぶ。`register()`を上書きしたクラスは、テーマでは`after_setup_theme`、プラグインでは`plugins_loaded`のpriority 0で1回だけ呼ばれる。

`app/Hook/PostTypeColumnHook.php`:

```php
<?php

declare(strict_types=1);

namespace App\Hook;

use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Hook\Hook;

#[AsHook]
final class PostTypeColumnHook extends Hook
{
    /** 一覧の画面に「更新日」の列を足す投稿タイプ */
    private const POST_TYPES = ['news', 'event'];

    public function register(): void
    {
        // フック名が投稿タイプのスラッグを含むため、属性で書けない
        foreach (self::POST_TYPES as $postType) {
            add_filter("manage_{$postType}_posts_columns", [$this, 'addModifiedColumn']);
        }
    }

    /**
     * @param array<string, string> $columns
     *
     * @return array<string, string>
     */
    public function addModifiedColumn(array $columns): array
    {
        $columns['modified'] = __('Last modified', 'my-theme');

        return $columns;
    }
}
```

- 例のテキストドメイン`my-theme`は、テーマのstyle.css(プラグインはプラグインヘッダ)の`Text Domain`に置き換える。
- 列の中身の出力(`manage_{$post_type}_posts_custom_column`)も同じく`register()`で登録する。

## してはいけないこと

- `functions.php`・プラグインの本体ファイルに`add_action()`・`add_filter()`を直書きしない。
- テンプレートの中でフックを登録しない。
- 属性で書けるフックを`register()`に並べない。`register()`は、フック名を動的に作る登録だけに使う。
- `#[Filter]`のメソッドで値を返し忘れない(`void`にしない)。
- 基底クラスに`#[AsHook]`を付けて継承させない。PHPの属性は継承されないため、子クラスは探索されない。具象クラスごとに付ける。
- `#[Action]`・`#[Filter]`を、`#[AsHook]`の無いクラスや、public以外・static・可変長引数のメソッドに付けない(探索で`DiscoveryException`になる)。フックを1つも登録しない(`#[Action]`・`#[Filter]`のメソッドも`register()`の上書きも無い)`#[AsHook]`のクラスも`DiscoveryException`になる。
- フックのメソッドの中で例外を握りつぶさない。失敗は例外にし、本番の記録はKernelに任せる。
- 削除の対象を理解しないまま、管理画面・`wp_head`の整理のコードを丸ごと写さない。

## 確認方法

1. `composer qa`が通ること(`wp tmswp discover --check`で、探索結果ファイルが最新であることも確かめる)。
2. 対象のフックの効果がWordPressで確かめられること(例: `excerpt_more`なら抜粋の末尾、`admin_menu`なら管理画面のメニュー)。
3. 本番で効かない場合は、`wp tmswp discover --check`で探索結果ファイルが最新かを確かめ、古ければ`wp tmswp discover`で作り直してコミットする。

## 発展

- 投稿タイプ・タクソノミー・ロールの登録はフックで書かない: rules/post-type.md・rules/taxonomy.md・rules/user-role.md
- テンプレートで使う値の用意はフックで書かない: rules/page.md
- ブロックの描画を変えるフック: rules/blocks.mdの「ブロック専用のフック」
- フックの属性・探索・初期化のタイミングの仕様: TMSWPの設計文書[docs/02-kernel.md](https://github.com/tmstack-io/tmswp/blob/v0.10.0/docs/02-kernel.md)の「ComponentAttributeInterface / ComponentInitializerInterface」と「コンポーネント初期化スケジューリング」の節
