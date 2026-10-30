# テンプレートの前処理を書く

テンプレートで使う値の用意・フォームの駆動・PRGのリダイレクトは、`app/Page/`の前処理クラスに書き、テンプレートの先頭で明示的に呼ぶ。前処理クラスは、テンプレートが使う値を`public readonly`のプロパティで持つDTOを返す。テンプレートの選択はWordPress標準のテンプレート階層のままで、FWはテンプレートの選択に介入しない。
例はテーマのもの。コード例の名前空間`App\`は、自分のプロジェクトの名前空間(composer.jsonの`autoload.psr-4`で`app/`に割り当て、`tmswp-kernel.php`の`appNamespace`に書いたもの。スターターから作ったプロジェクトでは、setup.phpがスラッグから作った名前。例: `my-theme`なら`MyTheme\`)に読み替える。

- テンプレート1つにつき前処理クラスを1つ作る。前処理の要らないテンプレート(値を用意しないもの)には作らない。
- プラグインはトップレベルのテンプレートを持たないため、この方式を使わない。ショートコードのフォームの駆動はrules/form.mdを参照する。
- 例のテキストドメイン`my-theme`は、テーマのstyle.cssの`Text Domain`に置き換える。

## 手順

1. `app/Page/<Name>Page.php`を作る。`final class`にし、コンストラクタを`private`にして、テンプレートが使う値を`public readonly`のプロパティで宣言する。
2. `public static function handle(): self`に前処理を書き、用意した値を`new self(...)`で返す。
3. テンプレートの先頭(`get_header()`より前)で`$vm = <Name>Page::handle();`を1回だけ呼び、以降は`$vm`のプロパティを読む。
4. パーツには、値を`Template::part()`の`$args`で渡す(rules/view.md)。
5. `composer qa`を通す。

## 完全な実装例

お知らせの詳細ページ(`single-news.php`)に、同じカテゴリの新しいお知らせを3件、関連として出す。投稿タイプ`App\Post\NewsPost`はrules/post-type.md、タクソノミー`App\Tax\NewsCategoryTax`はrules/taxonomy.mdの例である。

### 前処理クラス

`app/Page/NewsSinglePage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Page;

use App\Post\NewsPost;
use App\Tax\NewsCategoryTax;
use LogicException;
use TMSWP\Core\Query\TermField;
use WP_Post;
use WP_Term;

/** single-news.phpの前処理。お知らせのカテゴリと、同じカテゴリの関連のお知らせを用意する */
final class NewsSinglePage
{
    /**
     * @param list<WP_Post> $related
     */
    private function __construct(
        public readonly ?WP_Term $category,
        public readonly array $related,
    ) {}

    /**
     * @throws LogicException お知らせの詳細ページ以外のテンプレートから呼んだ場合
     */
    public static function handle(): self
    {
        $queried = get_queried_object();
        $news = $queried instanceof WP_Post ? NewsPost::get($queried) : null;

        if ($news === null) {
            // 呼び出したテンプレートの誤り。空の値で表示を続けない
            throw new LogicException('NewsSinglePage::handle() must be called from the news single template.');
        }

        $category = NewsPost::firstTerm($news, NewsCategoryTax::class);

        // カテゴリが付いていなければ関連は無し。データが無いことはエラーにしない
        $related = $category === null ? [] : NewsPost::query()
            ->idNotIn($news->ID)
            ->whereTerm(NewsCategoryTax::class, $category->term_id, TermField::TermId)
            ->orderByDesc('date')
            ->limit(3)
            ->get();

        return new self($category, $related);
    }
}
```

- テンプレートが使う値の型は、DTOのプロパティの型で決まる。テンプレートで`@var`を書いて型を補わない。
- 表示する投稿は、WordPressがテンプレートを選んだ時点のメインクエリ(`get_queried_object()`)から取る。前処理クラスで別の投稿を探して差し替えない。
- 想定外のテンプレートから呼ばれたこと(呼び出しの誤り)は例外にする。カテゴリが無い・関連が0件といったデータが無いことは、空の値をDTOに入れてテンプレートで出し分ける。

### テンプレート

`single-news.php`:

```php
<?php

declare(strict_types=1);

use App\Page\NewsSinglePage;
use TMSWP\Core\View\Template;

// 出力の前(get_header()より前)に1回だけ呼ぶ
$vm = NewsSinglePage::handle();

get_header();
?>
<main class="news">
    <?php while (have_posts()) : ?>
        <?php the_post(); ?>
        <article>
            <h1><?php the_title(); ?></h1>
            <?php if ($vm->category !== null) : ?>
                <p class="news-category"><?php echo esc_html($vm->category->name); ?></p>
            <?php endif; ?>
            <?php the_content(); ?>
        </article>
    <?php endwhile; ?>

    <?php if ($vm->related !== []) : ?>
        <?php Template::part('parts/news-related', ['related' => $vm->related]); ?>
    <?php endif; ?>
</main>
<?php
get_footer();
```

### パーツ

`parts/news-related.php`:

```php
<?php

declare(strict_types=1);

use App\Post\NewsPost;

/** @var array{related: list<WP_Post>} $args */
?>
<section class="news-related">
    <h2><?php esc_html_e('Related news', 'my-theme'); ?></h2>
    <ul>
        <?php foreach ($args['related'] as $item) : ?>
            <li>
                <a href="<?php echo esc_url((string) NewsPost::permalink($item)); ?>"><?php echo esc_html(get_the_title($item)); ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
```

## フォームを駆動する場合

フォームのページも同じ形で書く。`handle()`の中でフォームの`handle()`を呼び、完了したら`wp_safe_redirect()`の直後に`exit`する(PRG)。完全な例はrules/form.mdの`App\Page\ContactPage`。

## してはいけないこと

- 前処理を`template_redirect`・`template_include`等のフックで、テンプレートを判定して実行しない。テンプレートの先頭で前処理クラスを明示的に呼ぶ。どのテンプレートで何が動くかを、テンプレートのファイルだけで追えるようにするため。
- `get_header()`の後で`handle()`を呼ばない。出力が始まった後では、リダイレクトとnocacheのヘッダが効かない。
- `handle()`をパーツや複数の場所から呼ばない。フォームの駆動やリダイレクトの副作用があるため、テンプレートの先頭で1回だけ呼ぶ。パーツには値を`$args`で渡す。
- 前処理クラスで出力(`echo`等)しない。HTMLはテンプレートとパーツに書く。
- 前処理クラスで`$wp_query->set_404()`を呼んで404に切り替えない。テンプレートが選ばれた後では、ステータス行とテンプレートが食い違う。404かどうかはWordPressのメインクエリに任せる。
- PRGの`wp_safe_redirect()`の直後以外で`exit`を使わない。
- 値を`global`・`extract()`・連想配列で受け渡さない。DTOの型付きのプロパティで渡す。

## 確認方法

1. `composer qa`が通ること。
2. カテゴリの付いたお知らせの詳細ページに、同じカテゴリの関連のお知らせが出ること。
3. カテゴリの無いお知らせの詳細ページが、関連の欄なしで表示されること。

## 発展

- テンプレートパーツに値を渡す: rules/view.md
- フォームのページの前処理(PRG): rules/form.md
- テンプレートの前処理の方式の仕様: TMSWPの設計文書[docs/07-support.md](https://github.com/tmstack-io/tmswp/blob/v0.9.1/docs/07-support.md)
