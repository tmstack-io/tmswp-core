# カスタムタクソノミーを追加する

タクソノミー1つにつきクラス1つを `app/Tax/` に置く。`register_taxonomy()` は直接呼ばない。
コード例の名前空間`App\`は、自分のプロジェクトの名前空間(composer.jsonの`autoload.psr-4`で`app/`に割り当て、`tmswp-kernel.php`の`appNamespace`に書いたもの。スターターから作ったプロジェクトでは、setup.phpがスラッグから作った名前。例: `my-theme`なら`MyTheme\`)に読み替える。

## 手順

1. 紐付け先の投稿タイプのクラスを用意する(rules/post-type.md。既存の投稿タイプは、その投稿タイプのbuiltinのアダプタ)。
2. `app/Tax/<Name>Tax.php`を作る(例: `NewsCategoryTax`)。
3. `#[AsTaxonomy]`を付け、`TMSWP\Core\Tax\Taxonomy`を継承した`final class`にする。
4. `public static function definition(): TaxonomyDefinition`で、`TaxonomyDefinition::create('<スラッグ>')`の定義を返す。紐付け先は`objectType()`に投稿タイプのクラスを渡す。
5. WordPressの環境で`wp tmswp discover`(スターターから作ったプロジェクトでは`composer discover`)を実行し、探索結果ファイル`tmswp-discovery.php`を作り直してコミットする。
6. `composer qa`を通す。
7. 有効化済みのテーマ・プラグインに足したときは、管理画面の「設定 → パーマリンク」を開いて保存し、リライトのルールを作り直す(タームのアーカイブのURLが404になるのを防ぐ)。テーマ・プラグインの有効化のときは、FWがリライトのルールを作り直させる。

## 完全な実装例

`app/Tax/NewsCategoryTax.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tax;

use App\Post\NewsPost;
use TMSWP\Core\Attribute\AsTaxonomy;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tax\TaxonomyDefinition;

#[AsTaxonomy]
final class NewsCategoryTax extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::create('news_category')
            ->label('お知らせカテゴリ')
            ->labelLanguage(LabelLanguage::Ja)
            ->objectType(NewsPost::class)
            ->hierarchical()
            ->showInRest();
    }
}
```

## 定義の既定値と引数

- `TaxonomyDefinition::create()` は引数の既定値を変えない。呼ばなかったセッターの分は WordPress の既定(`public` は `true`)に任せる。
- スラッグは英小文字・数字・`_`・`-` の32文字以内にする。違反は `EntityDefinitionException` になる。
- 専用のセッターが無い `register_taxonomy()` の引数は `args([...])` で渡す。キーごとに上書きし、配列の形は PHPStan が `register_taxonomy()` の引数と同じ形で検査する。`labels` は常に `label()`・`labels()` の値が優先する。紐付け先の投稿タイプは `args()` ではなく `objectType()` で指定する。
- Kernel は `init` の priority 9 でタクソノミーを登録する(投稿タイプの priority 10 より先)。

## テンプレートでの使い方

```php
<?php foreach (\App\Tax\NewsCategoryTax::all() as $term) : ?>
    <a href="<?php echo esc_url((string) \App\Tax\NewsCategoryTax::url($term)); ?>">
        <?php echo esc_html($term->name); ?>
    </a>
<?php endforeach; ?>
```

タクソノミーのクラスの静的メソッド(`$term`は`WP_Term|int`):

| メソッド | 動き |
|---|---|
| `query()` | このタクソノミーの`TermQuery`(下の「クエリの書き方」) |
| `all($hideEmpty = false)` | すべてのターム |
| `get($term)` | このタクソノミーのタームなら`WP_Term`、違えば・無ければnull |
| `findBySlug($slug)`・`findByName($name)` | スラッグ・名前で1件。無ければnull |
| `is($term)` | このタクソノミーのタームか(nullも受け取る) |
| `url($term)` | タームのアーカイブのURL(`$term`にはスラッグの文字列も渡せる)。無ければnull |
| `parents($term, $includeSelf)` | 親のタームのlist(階層のあるタクソノミー) |
| `meta($term)` | メタの`MetaBag`(rules/meta.md)。このタクソノミーのタームでなければ`InvalidArgumentException` |
| `create($name, $args)` | タームを作り、IDを返す。値はslashしない元の値を渡す。失敗(同じ名前のターム等)は`EntityPersistenceException` |
| `slug()`・`taxonomyObject()` | スラッグ・`WP_Taxonomy` |

## クエリの書き方

`query()` が返す `TermQuery` は、比較演算子・キャスト型・並び順を `PostQuery` と同じ enum(`Compare`・`CastType`・`SortOrder`)で受け取る(rules/post-type.md の「クエリの書き方」)。

```php
<?php

use App\Tax\NewsCategoryTax;
use TMSWP\Core\Query\SortOrder;

$topLevel = NewsCategoryTax::query()
    ->hideEmpty()
    ->parent(0)
    ->orderBy('name', SortOrder::Asc)
    ->limit(10)
    ->get();

// 表示中の投稿に付いたターム
$postTerms = NewsCategoryTax::query()->forPost((int) get_the_ID())->get();
```

- 既定では投稿の付いていないタームも返す(`hide_empty` は `false`)。投稿の付いたタームだけにするときは `hideEmpty()` を呼ぶ。`all()` は `query()->hideEmpty($hideEmpty)->get()` と同じ。
- 件数を指定しない `get()` は全件を返す。`limit()` は1以上、`offset()` は0以上で、`limit()` の無い `offset()` は `InvalidQueryException` になる。
- 並び替えは1組だけ指定できる。2回目の `orderBy()`・`orderByMeta()` は `InvalidQueryException` になる。
- `paginate()` は無い。ページ分けは `limit()`・`offset()`・`count()` で組み立てる。
- `idIn()`・`slugIn()`・`forPost()` を空で呼ぶと結果は0件になる。`idNotIn()` を空で呼ぶと条件なしになる。
- 専用のメソッドが無い `get_terms()` の引数は `withArgs([...])` で渡す。キーごとに上書きし、`taxonomy` は上書きできない。
- WordPress の関数が `WP_Error` を返した場合(タクソノミーが未登録等)は、debug では `QueryException` になり、本番では Kernel のロガーに `error` で記録して空の結果(空の配列・null・0)を返す。該当なしは例外にならない。

## 既存のタクソノミーを使う(builtin)

コアの `category`・`post_tag` や、ほかのプラグインのタクソノミーは、`TaxonomyDefinition::builtin()` のアダプタを定義して使う。クエリは必ずタクソノミーのクラスから作る(`CategoryTax::query()`)ため、検索・メタ・URL のメソッドを使うには、このアダプタが要る。

`app/Tax/CategoryTax.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tax;

use App\Post\NewsPost;
use TMSWP\Core\Attribute\AsTaxonomy;
use TMSWP\Core\Tax\Taxonomy;
use TMSWP\Core\Tax\TaxonomyDefinition;

#[AsTaxonomy]
final class CategoryTax extends Taxonomy
{
    public static function definition(): TaxonomyDefinition
    {
        return TaxonomyDefinition::builtin('category')
            ->objectType(NewsPost::class);
    }
}
```

- builtin の定義で呼べるセッターは `objectType()` だけ。ほかのセッター(`label()`・`args()` 等)を呼ぶと、その場で `EntityDefinitionException` になる。
- `objectType()` の投稿タイプへは `register_taxonomy_for_object_type()` で紐付ける。この時点(priority 9)で紐付けられなかった投稿タイプ(同じテーマ・プラグインが priority 10 で登録する投稿タイプ等)は、priority 11 で1回だけやり直す。それでも紐付けられない場合(投稿タイプのスラッグの誤り等)は、例外にせず Kernel のロガーに `warning` で記録する。
- 紐付けが要らなければ `objectType()` を省いてよい。
- アダプタ先のタクソノミーが登録の時点(`init` の priority 9)で無い場合は、`objectType()` の有無に関係なく `EntityDefinitionException` になる。
- builtin のクラスにも `#[MetaSchema]` の定数を宣言できる(rules/meta.md)。そのタクソノミーのタームのメタとして登録される。

## してはいけないこと

- `register_taxonomy()` を直接呼ばない。
- `definition()` の中で WP 関数や `__()` を呼ばない。`definition()` は純粋な関数にする。Kernel が boot の時点(init より前)でメタスキーマの登録のために呼び、結果を使い回す。
- 投稿タイプのスラッグ文字列を手で重複管理しない。`objectType()` には投稿タイプクラスを渡す。
- 基底クラスに `#[AsTaxonomy]` を付けて継承させない。PHP の属性は継承されないため、子クラスは探索されない。具象クラスごとに付ける。
- タクソノミーのクラスを定義せずに、`get_terms()` でタクソノミーを直接検索しない。コアやほかのプラグインのタクソノミーは builtin のアダプタを定義して `query()` を使う。

## 確認方法

1. `composer qa` が通ること。
2. 投稿編集画面にタクソノミーUIが出ること。

## 発展

- 紐付け先の投稿タイプを作る: rules/post-type.md
- タームにメタを持たせる: rules/meta.md
- タームの一覧をテンプレートの前処理クラスで用意する: rules/page.md
- タクソノミーの定義・クエリの仕様: TMSWPの設計文書[docs/03-entities.md](https://github.com/tmstack-io/tmswp/blob/v0.10.0/docs/03-entities.md)
