# カスタム投稿タイプ(CPT)を追加する

投稿タイプ1つにつきクラス1つを `app/Post/` に置く。`register_post_type()` は直接呼ばない。
コード例の名前空間`App\`は、自分のプロジェクトの名前空間(composer.jsonの`autoload.psr-4`で`app/`に割り当て、`tmswp-kernel.php`の`appNamespace`に書いたもの。スターターから作ったプロジェクトでは、setup.phpがスラッグから作った名前。例: `my-theme`なら`MyTheme\`)に読み替える。

## 手順

1. `app/Post/<Name>Post.php`を作る(例: `NewsPost`)。
2. `#[AsPostType]`を付け、`TMSWP\Core\Post\PostType`を継承した`final class`にする。
3. `public static function definition(): PostTypeDefinition`で、`PostTypeDefinition::create('<スラッグ>')`の定義を返す。
4. メタを持たせるときは、同じクラスに`#[MetaSchema]`の定数を宣言する(rules/meta.md)。
5. WordPressの環境で`wp tmswp discover`(スターターから作ったプロジェクトでは`composer discover`)を実行し、探索結果ファイル`tmswp-discovery.php`を作り直してコミットする。
6. `composer qa`を通す。
7. 有効化済みのテーマ・プラグインに足したときは、管理画面の「設定 → パーマリンク」を開いて保存し、リライトのルールを作り直す(アーカイブ・個別のURLが404になるのを防ぐ)。テーマ・プラグインの有効化のときは、FWがリライトのルールを作り直させる。

## 完全な実装例

`app/Post/NewsPost.php`:

```php
<?php

declare(strict_types=1);

namespace App\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

#[AsPostType]
final class NewsPost extends PostType
{
    /** 関連のお知らせのID(1行に1つ。メタの宣言はrules/meta.md) */
    #[MetaSchema(type: MetaValueType::Integer, single: false)]
    public const META_RELATED_NEWS = 'related_news';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('news')
            ->label('お知らせ')
            ->labelLanguage(LabelLanguage::Ja)
            ->menuIcon('dashicons-megaphone')
            ->supports('title', 'editor', 'thumbnail')
            ->hasArchive();
    }
}
```

## 定義の既定値と引数

- `PostTypeDefinition::create()` は `public` を `true` にする(WordPress の既定は `false`)。FW が既定値を変える引数はこの1つだけで、ほかの引数は、呼ばなかったセッターの分を WordPress の既定に任せる。公開しない投稿タイプは `->public(false)` を明示する。
- スラッグは英小文字・数字・`_`・`-` の20文字以内にする。違反は `EntityDefinitionException` になる。
- `label()`は、ラベルの言語が日本語(`LabelLanguage::Ja`)なら単数形だけでよい。英語(`LabelLanguage::En`)では複数形も渡す(`->label('News item', 'News items')`)。`labelLanguage()`を呼ばない定義は、`tmswp-kernel.php`の`KernelConfig`の`labelLanguage`(既定は`En`。スターターは`Ja`)に従う。
- 主なセッター: `public()`・`hierarchical()`・`excludeFromSearch()`・`publiclyQueryable()`・`showUi()`・`showInMenu()`・`showInNavMenus()`・`showInAdminBar()`・`menuPosition()`・`menuIcon()`・`showInRest()`・`restBase()`・`blockEditor()`・`allowedBlocks()`(rules/blocks.md)・`capabilityType()`・`capabilities()`・`supports()`・`taxonomies()`・`hasArchive()`・`rewrite()`・`rewriteSlug()`・`queryVar()`・`canExport()`・`deleteWithUser()`・`template()`・`description()`・`labels()`。`supports()`は呼ぶたびに機能の一覧を置き換える。
- 専用のセッターが無い `register_post_type()` の引数は `args([...])` で渡す。キーごとに上書きし、配列の形は PHPStan が `register_post_type()` の引数と同じ形で検査する。`labels`・`label` は常に、`supports`・`taxonomies` は `supports()`・`taxonomies()` を呼んだときに、セッターの値が `args()` の値より優先する。

## テンプレートでの使い方

```php
<?php foreach (\App\Post\NewsPost::query()->limit(5)->get() as $news) : ?>
    <a href="<?php echo esc_url((string) \App\Post\NewsPost::permalink($news)); ?>">
        <?php echo esc_html(get_the_title($news)); ?>
    </a>
<?php endforeach; ?>
```

投稿タイプのクラスの静的メソッド(`$post`は`WP_Post|int`):

| メソッド | 動き |
|---|---|
| `query()` | この投稿タイプの`PostQuery`(下の「クエリの書き方」) |
| `get($post)` | この投稿タイプの投稿なら`WP_Post`、違えば・無ければnull |
| `findBySlug($slug)` | スラッグ(`post_name`)で1件。無ければnull |
| `is($post)` | この投稿タイプの投稿か(nullも受け取る) |
| `permalink($post)`・`archiveUrl()` | 個別のURL・アーカイブのURL。無ければnull |
| `title($post)` | タイトル(`get_the_title()`と同じフィルタを通る) |
| `thumbnail($post, $size)`・`thumbnailUrl($post, $size)` | アイキャッチの`Thumbnail`・URL。無ければnull(下の「アイキャッチ未設定時のフォールバック画像」) |
| `terms($post, $taxonomy)`・`firstTerm($post, $taxonomy)` | 付いているタームのlist・先頭のターム。`$taxonomy`にはタクソノミーのクラス(`NewsCategoryTax::class`)かスラッグを渡す |
| `parents($post, $includeSelf)` | 親の投稿のlist(階層のある投稿タイプ) |
| `meta($post)` | メタの`MetaBag`(rules/meta.md)。この投稿タイプの投稿でなければ`InvalidArgumentException` |
| `create($postarr)` | この投稿タイプで投稿を作り、IDを返す。値はslashしない元の値を渡す。失敗は`EntityPersistenceException` |
| `slug()`・`postTypeObject()` | スラッグ・`WP_Post_Type` |

## クエリの書き方

`query()` が返す `PostQuery` は、比較演算子・キャスト型・並び順を `TMSWP\Core\Query\` の enum(`Compare`・`CastType`・`SortOrder`)で受け取る。`whereTerm()` の field・operator も enum(`TermField`・`TermOperator`)で受け取る。タクソノミー・ロールのクエリ(`TermQuery`・`UserQuery`)も同じ。

```php
<?php

use App\Post\NewsPost;
use TMSWP\Core\Query\CastType;
use TMSWP\Core\Query\Compare;
use TMSWP\Core\Query\SortOrder;
use TMSWP\Core\Query\TermField;

// お知らせの詳細ページ(single-news.php)で、表示中のお知らせのメタから関連のIDを読む
$relatedIds = array_map('intval', NewsPost::meta(get_queried_object_id())->list(NewsPost::META_RELATED_NEWS));

// relatedIds が空なら、WordPress を呼ばずに空の配列を返す(全件にならない)
$related = NewsPost::query()->idIn(...$relatedIds)->get();

$pickup = NewsPost::query()
    ->whereMeta('priority', 3, Compare::Gte, CastType::Numeric)
    ->whereTerm('category', [12, 15], TermField::TermId)
    ->orderByMeta('priority', SortOrder::Desc, CastType::Numeric)
    ->limit(5)
    ->get();

$page = NewsPost::query()->orderByDesc('date')->paginate(10);
```

- 既定では公開済み(`publish`)の投稿だけを返す。`status()`は、指定した状態だけに置き換える(公開済みも要るなら`status('publish', 'draft')`)。`anyStatus()`は、ゴミ箱と自動下書きを除くすべての状態にする。
- 既定で先頭固定の投稿を先頭に寄せない(`ignore_sticky_posts`が`true`)。WordPressの既定に戻すときは`ignoreSticky(false)`を呼ぶ。
- 件数を指定しない `get()` は全件を返す(管理画面の「1ページに表示する最大投稿数」は効かない)。件数を絞るときは `limit()` を呼ぶ。`limit()` は1以上、`offset()` は0以上で、範囲外の値は `InvalidArgumentException` になる。
- `author()` に 0(未ログインの `wp_get_current_user()` のように ID が 0 の `WP_User` を含む)を渡すと結果は0件になる。負の値は `InvalidArgumentException` になる。
- `idIn()`・`slugIn()` 等の IN の指定が空なら結果は0件になる。`idNotIn()` 等の除外が空なら条件なしになる。`idIn()` と `idNotIn()` を併用すると、除外した ID を差し引いた ID に絞る。
- `orderByMeta()` は並び替えだけで、メタの無い投稿も結果に残る(ASC では先頭、DESC では末尾)。メタのある投稿だけにするときは `whereMetaExists()` を併用する。
- `offset()` は `limit()` と一緒に使う(`limit()`の無い`offset()`は、`get()`・`ids()`で`InvalidQueryException`になる)。`noPaging()` と `limit()`・`offset()`・`paginate()`、`limit()`・`offset()` と `paginate()` は併用できない(`InvalidQueryException`)。`first()` は常に1件だけ取得する。
- `paginate($perPage)`は、ページの番号を省くとクエリ変数の`paged`(無ければ`page`)から決める。戻り値の`PageResult`は、`foreach`でそのページの投稿を回せ、`total`(全体の件数)・`totalPages()`・`hasPrev()`・`hasNext()`・`isEmpty()`を持つ。`count($page)`はそのページの件数である。
- タクソノミーは、スラッグの代わりに Taxonomy クラスの FQCN(`NewsCategoryTax::class`)を渡せる。存在しないクラスと、`Taxonomy`を継承しないクラスは`EntityDefinitionException`になる。
- 専用のメソッドが無い `WP_Query` の引数は `withArgs([...])` で渡す。キーごとに上書きし、`post_type` は上書きできない。`withArgs()` で渡した `post__in`・`post__not_in` は、`idIn()`・`idNotIn()` と差し引かずにそのまま渡る。
- `terms()`・`firstTerm()` 等で WordPress の関数が `WP_Error` を返した場合(存在しないタクソノミー等)は、debug では `QueryException` になり、本番では Kernel のロガーに `error` で記録して空の結果(空の配列・null)を返す。該当なし(タームが付いていない等)は例外にならない。

## 既存の投稿タイプを使う(builtin)

コアの `post`・`page` や、ほかのプラグインの投稿タイプは、`PostTypeDefinition::builtin()` のアダプタを定義して使う。クエリは必ず投稿タイプのクラスから作る(`PagePost::query()`)ため、検索・メタ・テンプレート向けのメソッドを使うには、このアダプタが要る。

`app/Post/PagePost.php`:

```php
<?php

declare(strict_types=1);

namespace App\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

#[AsPostType]
final class PagePost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::builtin('page')
            ->supports('excerpt');
    }
}
```

テンプレートでの使い方:

```php
<?php // ページテンプレートのファイル名から固定ページの URL を引く ?>
<a href="<?php echo esc_url(\App\Post\PagePost::query()->template('page-contact.php')->url() ?? home_url('/')); ?>">お問い合わせ</a>
```

- builtin の定義で呼べるセッターは `supports()` と `blockEditor()` だけ。ほかのセッター(`label()`・`args()` 等)を呼ぶと、その場で `EntityDefinitionException` になる。
- `supports()` の機能は `add_post_type_support()` で足される。`register_post_type()` は呼ばない。
- アダプタ先の投稿タイプが登録の時点(`init` の priority 10)で無い場合は、`EntityDefinitionException` になる(スラッグの誤り、ほかのプラグインが priority 10 より後に登録する投稿タイプ等)。
- builtin のクラスにも `#[MetaSchema]` の定数を宣言できる(rules/meta.md)。その投稿タイプのメタとして登録される。
- builtinの`post`のクラスに`#[WithMetaRevisions]`(プレビューにメタの変更を反映する。rules/meta.md)を付けるときは、定義で`supports('revisions')`を指定する。FWは定義の`supports()`の値を検査するため、WordPressが`post`に付けているrevisionsでは足りず、指定が無いと起動時に`InvalidSchemaException`になる。

### 添付ファイル(attachment)

添付ファイルのアダプタのクラスの`query()`は、`status('inherit')`を指定しないと添付ファイルを返さない。`PostQuery`の既定の`post_status`は`publish`で、添付ファイルの状態は`inherit`だからである。非公開の投稿に添付したファイルも含めるときは`status('inherit', 'private')`にする。

`app/Post/AttachmentPost.php`:

```php
<?php

declare(strict_types=1);

namespace App\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

#[AsPostType]
final class AttachmentPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::builtin('attachment');
    }
}
```

```php
<?php

use App\Post\AttachmentPost;

// status('inherit')が無いと、post_statusがpublishになり0件になる
$images = AttachmentPost::query()
    ->status('inherit')
    ->withArgs(['post_mime_type' => 'image'])
    ->orderByDesc('date')
    ->limit(12)
    ->get();
```

## アイキャッチ未設定時のフォールバック画像

`PostType::thumbnail()` はアイキャッチが未設定のときに `tmswp/post/thumbnail_fallback` フィルタを呼ぶ。フィルタは `?TMSWP\Core\Post\Thumbnail $thumbnail, WP_Post $post, string|array $size` を受け取り、代替画像がある場合だけ `TMSWP\Core\Post\Thumbnail` DTO を返す。それ以外の戻り値は無視される。

本文先頭の `<img src>` を使う場合は、抽出規則を app 側の要件に合わせて実装する。`width`・`height` には画像の実寸を渡す。`$size` は要求したサイズであり、画像の実寸ではないため、そのまま渡さない。メディアライブラリの画像(ブロックエディタが付ける `wp-image-<ID>` クラスを持つ画像)なら `wp_get_attachment_image_src()` で要求したサイズの URL と実寸を取り、それ以外の画像は実寸が分からないので `null` を渡す(`img()` は `width`・`height` を出力しない)。遅延読み込み属性や `srcset` を優先したい場合も、このクラス内で拡張する。

`Thumbnail::img()` は `src` を `esc_url()` でエスケープし、許可されないプロトコル(`javascript:` 等)の URL は例外にする。フィルタで返す URL は `esc_url_raw()` 等で `http`・`https` に絞っておく。

`app/Hook/ThumbnailFallbackHook.php`:

```php
<?php

declare(strict_types=1);

namespace App\Hook;

use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Attribute\Filter;
use TMSWP\Core\Hook\Hook;
use TMSWP\Core\Post\Thumbnail;
use WP_Post;

#[AsHook]
final class ThumbnailFallbackHook extends Hook
{
    /**
     * @param string|array<int> $size
     */
    #[Filter('tmswp/post/thumbnail_fallback')]
    public function firstContentImage(?Thumbnail $thumbnail, WP_Post $post, string|array $size): ?Thumbnail
    {
        if ($thumbnail instanceof Thumbnail) {
            return $thumbnail;
        }

        if (preg_match('/<img\s[^>]*>/i', $post->post_content, $tag) !== 1) {
            return null;
        }

        // メディアライブラリの画像は、要求したサイズの URL と実寸を使う
        if (preg_match('/\bwp-image-(\d+)\b/', $tag[0], $id) === 1) {
            $src = wp_get_attachment_image_src((int) $id[1], $size);

            if (is_array($src)) {
                return new Thumbnail($src[0], $src[1], $src[2], '');
            }
        }

        if (preg_match('/\ssrc=["\']([^"\']+)["\']/i', $tag[0], $matches) !== 1) {
            return null;
        }

        $url = esc_url_raw($matches[1], ['http', 'https']);

        if ($url === '') {
            return null;
        }

        // メディアライブラリに無い画像は実寸が分からないため、width・height を渡さない
        return new Thumbnail($url, null, null, '');
    }
}
```

## してはいけないこと

- `register_post_type()` や `add_action('init', ...)` を自分で書かない。
- 件数を絞りたいクエリで `limit()` を省かない(省くと全件を読み込む)。
- `definition()` の中で WP 関数や `__()` を呼ばない。`definition()` は純粋な関数にする。Kernel が boot の時点(init より前)でメタスキーマの登録のために呼び、結果を使い回す。
- 基底クラスに `#[AsPostType]` を付けて継承させない。PHP の属性は継承されないため、子クラスは探索されない。具象クラスごとに付ける。
- 投稿タイプのクラスを定義せずに、`WP_Query` や `get_posts()` で投稿タイプを直接検索しない。コアやほかのプラグインの投稿タイプは builtin のアダプタを定義して `query()` を使う。
- `functions.php` に投稿タイプ関連のロジックを書かない。
- 1クラスで複数投稿タイプを定義しない。

## 確認方法

1. `composer qa` が通ること。
2. 管理画面に投稿タイプが表示されること。
3. 本番で表示されない場合は `wp tmswp discover --check` で探索結果ファイルが最新かを確かめ、古ければ `wp tmswp discover` で作り直してコミットする。

## 発展

- タクソノミーを足して投稿タイプに紐付ける: rules/taxonomy.md
- 投稿タイプにメタ(カスタムフィールド)を持たせる: rules/meta.md。ACFの画面で編集する場合: rules/acf.md
- 投稿タイプごとに使えるブロックを限る(`allowedBlocks()`): rules/blocks.md
- テンプレートで使う値を前処理クラスで用意する: rules/page.md
- 投稿タイプの定義・クエリの仕様: TMSWPの設計文書[docs/03-entities.md](https://github.com/tmstack-io/tmswp/blob/v0.10.0/docs/03-entities.md)
