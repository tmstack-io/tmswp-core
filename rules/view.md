# テンプレートパーツを扱う

テンプレートの部品(カード・一覧の行・フォームの項目等)は、テーマの`parts/`(プラグインは`templates/<プラグインのスラッグ>/`)にパーツとして置き、`TMSWP\Core\View\Template::part()`で読む。パーツには値を`$args`の連想配列で渡し、パーツの中では`$args`だけを入力にする。テンプレートの選択はWordPress標準のテンプレート階層のままで、FWは介入しない。
コード例の名前空間`App\`は、自分のプロジェクトの名前空間(composer.jsonの`autoload.psr-4`で`app/`に割り当て、`tmswp-kernel.php`の`appNamespace`に書いたもの。スターターから作ったプロジェクトでは、setup.phpがスラッグから作った名前。例: `my-theme`なら`MyTheme\`)に読み替える。

## 手順

1. パーツのファイルを作る。テーマは`parts/<名前>.php`、プラグインは`templates/<プラグインのスラッグ>/<名前>.php`にする。
2. パーツの先頭に`declare(strict_types=1);`と、受け取る値の形を`/** @var array{...} $args */`で書く。
3. テンプレートから`Template::part('<テーマ・プラグインのルートからの相対パス(.phpを除く)>', [...])`で読む。
4. パーツの中では、出力する値をすべてエスケープする(`esc_html()`・`esc_url()`・`esc_attr()`等)。
5. `composer qa`を通し、表示を確かめる。

## 完全な実装例

投稿の一覧(`archive.php`)で、1件ずつカードのパーツを出す。

`parts/post-card.php`:

```php
<?php

declare(strict_types=1);

/** @var array{post: WP_Post, show_excerpt: bool} $args */
?>
<article class="post-card">
    <h2 class="post-card__title">
        <a href="<?php echo esc_url((string) get_permalink($args['post'])); ?>"><?php echo esc_html(get_the_title($args['post'])); ?></a>
    </h2>
    <?php if ($args['show_excerpt']) : ?>
        <p class="post-card__excerpt"><?php echo esc_html(get_the_excerpt($args['post'])); ?></p>
    <?php endif; ?>
</article>
```

`archive.php`:

```php
<?php

declare(strict_types=1);

use TMSWP\Core\View\Template;

get_header();
?>
<main class="archive">
    <?php while (have_posts()) : ?>
        <?php the_post(); ?>
        <?php $item = get_post(); ?>
        <?php if ($item instanceof WP_Post) : ?>
            <?php Template::part('parts/post-card', ['post' => $item, 'show_excerpt' => true]); ?>
        <?php endif; ?>
    <?php endwhile; ?>
</main>
<?php
get_footer();
```

- パーツのファイルの中で使える変数は`$args`だけである(`Template::part()`はパーツをクラスの外のスコープで読み込み、`extract()`しない)。
- パーツに渡す値の型は、パーツの`@var`と呼び出し側の配列で揃える。テンプレートで使う値の用意(クエリ・メタの読み取り)が多いときは、テンプレートの前処理クラスで用意してから渡す(rules/page.md)。

## テンプレートでの使い方

| メソッド | 動き |
|---|---|
| `Template::part(string $slug, array $args = []): void` | パーツを読み込んで出力する。見つからなければ`TemplateNotFoundException`(探した候補のパスをメッセージに含む) |
| `Template::render(string $slug, array $args = []): string` | パーツの出力を文字列で返す(ショートコード・REST・メールの本文等に使う)。パーツの例外はそのまま投げる |
| `Template::exists(string $slug): bool` | パーツがあるか |
| `Template::locate(string $slug): ?string` | パーツのファイルの絶対パス。無ければnull |

ショートコードのように文字列を返す場所では、`render()`でパーツの出力を受け取る。次の例の`App\Post\NewsPost`は、rules/post-type.mdの例の投稿タイプのクラスである。投稿は、投稿タイプのクラスの`query()`で取得する(コアの`post`等も、builtinのアダプタのクラスを定義して使う。rules/post-type.md)。

`app/Hook/LatestNewsShortcodeHook.php`:

```php
<?php

declare(strict_types=1);

namespace App\Hook;

use App\Post\NewsPost;
use TMSWP\Core\Attribute\Action;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Hook\Hook;
use TMSWP\Core\View\Template;

#[AsHook]
final class LatestNewsShortcodeHook extends Hook
{
    #[Action('init')]
    public function registerShortcode(): void
    {
        add_shortcode('latest_news_card', [$this, 'renderLatestNewsCard']);
    }

    /** [latest_news_card]の出力。公開済みのお知らせが無ければ何も出さない */
    public function renderLatestNewsCard(): string
    {
        $latest = NewsPost::query()->orderByDesc('date')->first();

        if ($latest === null) {
            return '';
        }

        return Template::render('parts/post-card', ['post' => $latest, 'show_excerpt' => false]);
    }
}
```

- slugは、テーマ・プラグインのルートからの相対パスから`.php`を除いたものである。英数字・`_`・`-`をスラッシュでつないだ形だけを受け付け、`.php`・`..`・先頭のスラッシュを含むslugは`InvalidTemplateSlugException`になる。
- パーツは、子テーマ → 親テーマ → プラグインの`templates/`(Kernelを起動したプラグインの順)の順で探す。テーマに同じ相対パスのファイルを置くと、プラグインのパーツを上書きできる。
- プラグインのパーツのslugは、`<プラグインのスラッグ>/`で始める(例: `my-plugin/order-summary` → `templates/my-plugin/order-summary.php`)。プラグインの`templates/`はすべてのプラグインで共有の探し先になるため、ほかのプラグインのパーツとの衝突を避けるためである。テーマで上書きするときは`<テーマ>/my-plugin/order-summary.php`に置く。
- `Template::part()`は`get_template_part()`と同じフック(`get_template_part_{$slug}`・`get_template_part`)を同じ引数で発火する。

## してはいけないこと

- パーツで`extract()`を使わない。`global`の変数(`$post`等)やループの状態に頼らず、要る値は`$args`で渡す。
- パーツの中でクエリやメタの読み書き等の前処理をしない。値はテンプレートの前処理クラスかテンプレートで用意して渡す。
- パーツの中で値をエスケープせずに出力しない。
- `template_include`等のフックでテンプレート階層を迂回しない。
- `get_template_part()`の戻り値`false`(見つからない)を黙って見過ごす書き方に頼らない。パーツは`Template::part()`で読み、無いパーツは例外で気づく。
- プラグインのパーツを、`<プラグインのスラッグ>/`で始まらないslugで置かない。

## 確認方法

1. `composer qa`が通ること。
2. パーツが表示され、渡した値が反映されること。
3. パーツのファイル名を一時的に変えると`TemplateNotFoundException`になり、メッセージに探した候補のパスが出ること。確かめたら元に戻す。

## 発展

- テンプレートで使う値をDTOにして渡す: rules/page.md
- テンプレートの部品をブロックエディタで使えるようにする: rules/blocks.md
- `Template`・`TemplateLocator`の仕様: TMSWPの設計文書 docs/07-support.md
