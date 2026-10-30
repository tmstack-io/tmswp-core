# 非ACFカスタムフィールドを定義する

投稿・ターム・ユーザーのメタはエンティティクラスの `#[MetaSchema]` 付き定数で宣言する。
`get_post_meta()` / `update_post_meta()` は直接呼ばない。
コード例の名前空間`App\`は、自分のプロジェクトの名前空間(composer.jsonの`autoload.psr-4`で`app/`に割り当て、`tmswp-kernel.php`の`appNamespace`に書いたもの。スターターから作ったプロジェクトでは、setup.phpがスラッグから作った名前。例: `my-theme`なら`MyTheme\`)に読み替える。

## 使い分け

値の種類ごとに経路を1つに決める。同じ値を複数の経路で宣言しない。

- 単純な値(文字列・数値・真偽・日付・単一画像ID等)は、必ず `#[MetaSchema]` で宣言し、`MetaBag`(`::meta($object)`)だけで読み書きする。
- 単純な値をACFの画面で編集する場合も、宣言と`MetaBag`はそのままにし、エンティティに`#[UseMetaStorage(AcfMetaStorage::class)]`を付けて保存先だけを切り替える(rules/acf.mdの「単純な値をACFの画面で編集する」)。そのキーはACFのフィールドグループで管理してよい(下の「ACFのフィールドグループで管理するキー」)。
- ACF固有の複合型(repeater・flexible_content・group・gallery・relationship)は、acf-bridgeの生成アクセサ(`XxxFields`)で扱う(rules/acf.md)。
- サイト全体の設定はメタではなく設定で扱う(rules/options.md の「使い分け」)。

### ACFのフィールドグループで管理するキー

acf-bridgeは、ACFのフィールドグループで管理するキーを外部管理(ストレージは`AcfMetaStorage`)として申告する。

- そのキーを`#[MetaSchema]`で宣言するエンティティには`#[UseMetaStorage(AcfMetaStorage::class)]`を付ける。申告と衝突せず、宣言どおりに登録される。
- `#[UseMetaStorage]`の無いエンティティで同じキーを宣言すると、acf-bridgeが申告する`init`(priority 6)の時点で`DuplicateMetaKeyException`になる。`wp tmswp acf check`・`sync`だけは起動の時点で止まらず、この重複をE101で報告する。
- 同じキーを`MetaBag`と生成アクセサ(`XxxFields`)の両方から読み書きしない。

## 手順

1. 対象エンティティクラスに public の文字列定数を追加する。定数名は `META_` で始めるのを推奨する(宣言の根拠は属性だけで、名前は検査しない)。
2. 定数へ `#[MetaSchema]` を付ける。
3. 読み書きは `::meta($object)` の `MetaBag` 経由で行う。
4. テンプレート等から頻繁に読むフィールドは、エンティティに明示アクセサ(下の例の `price()`)を書く。

## 完全な実装例

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
use WP_Post;

#[AsPostType]
final class ProductPost extends PostType
{
    #[MetaSchema(type: MetaValueType::Integer, default: 0, showInRest: true)]
    public const META_PRICE = 'price';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('product')
            ->label('製品')
            ->labelLanguage(LabelLanguage::Ja)
            ->supports('title', 'editor');
    }

    public static function price(WP_Post|int $post): int
    {
        return self::meta($post)->int(self::META_PRICE);
    }
}
```

## テンプレートでの使い方

`single-product.php`:

```php
<?php

declare(strict_types=1);

use App\Post\ProductPost;

// シングルのテンプレートの表示中の投稿のID(get_the_ID()はint|falseを返すため使わない)
$productId = get_queried_object_id();

get_header();
?>
<main class="product">
    <h1><?php echo esc_html(ProductPost::title($productId)); ?></h1>
    <p class="price"><?php echo esc_html(number_format_i18n(ProductPost::price($productId))); ?>円</p>
</main>
<?php
get_footer();
```

- テンプレートからは、エンティティの明示アクセサ(`price()`)を呼ぶ。`meta()`とキーの定数をテンプレートに並べない。
- 読む値が多いときは、テンプレートの前処理クラスでDTOにまとめる(rules/page.md)。

## #[MetaSchema]の引数

| 引数 | 既定 | 内容 |
|---|---|---|
| `type` | `MetaValueType::String` | 値の型。`String`・`Boolean`・`Integer`・`Number`・`Array`・`Object`。`single: false`では1行ごとの型 |
| `default` | `null` | 値が無いときに読み取りが返す既定値。データベースには保存しない |
| `single` | `true` | `false`にすると、同じキーに複数の行を持つ(読み書きはlist) |
| `showInRest` | `false` | RESTに出すか。`Array`・`Object`は`['schema' => [...]]`で形を書く |
| `description` | `''` | `register_*_meta()`の`description` |
| `sanitize` | `null` | 保存の前にWordPressが呼ぶ関数。関数名の文字列か`[クラス名, 'メソッド名']`(クロージャは属性に書けない) |

`single: false`と`default`の併用、`type`に合わない`default`、`sanitize`の形の誤り・存在しないクラスは、起動時に`InvalidSchemaException`になる。

## MetaBagのメソッド

`::meta($object)`が返す`MetaBag`のメソッド(`$key`はエンティティの`META_`の定数):

| メソッド | 動き |
|---|---|
| `int($key)`・`float($key)`・`bool($key)`・`string($key)` | 型付きで読む。値が無ければスキーマの`default`、それも無ければ引数の`$default` |
| `list($key)` | listで読む(`single: false`のキーは全行。`int()`等の型付きのgetterは先頭の行だけを読む) |
| `json($key)` | 保存されたJSONの文字列か配列を、配列で読む。読めなければ`[]` |
| `get($key, $default = null)` | 型を変えずに読む |
| `has($key)` | 行があるか(既定値は「ある」としない) |
| `all()` | 宣言済みの全キーの値 |
| `set($key, $value)` | 書き込む。書き込んだら`true`(下の「書き込みの規則」) |
| `delete($key)` | 消す |

## キーの渡し方

- メタキーは、そのキーを宣言したエンティティの定数で渡す(`ProductPost::meta($post)->int(ProductPost::META_PRICE)`)。文字列リテラルで渡しても実行時は同じスキーマが効くが、PHPStanの戻り値の型の絞り込みが効かず、宣言済みのキーと同じ文字列なら PHPStan が `tmswp.metaKeyLiteral` で報告する。ほかのエンティティの定数を渡すと `tmswp.metaKeyOwnership` で報告する。
- 頻繁に読むフィールドは、テンプレートから `meta()` を直接呼ばず、エンティティの明示アクセサを呼ぶ。
- スキーマになるのは、そのクラス自身が宣言した定数だけ。親クラスの定数は子クラスのスキーマにならない。

## 未宣言のキーを読み書きする

`#[MetaSchema]` で宣言していないキー(WordPress の `_thumbnail_id`、ほかのプラグインのキー等)も、`MetaBag` に文字列で渡せば読み書きできる。エラーにはならないが、スキーマの既定値・`single: false`・REST への公開・型の検査・PHPStan の型の絞り込みは効かない。

## 使える時点

- メタスキーマは Kernel の boot の中で登録されるため、`::meta($object)` の読み取りと `delete()` は boot の後ならいつでも使える(init の前、app の `#[Action('init')]` の中を含む)。`sanitize` を宣言していないキーの `set()` も同じ。
- WordPress への `register_*_meta` は `init` の priority 20 で行われる。WordPress が `sanitize` を適用するのはその後なので、`sanitize` を宣言したキーの `set()` は `init` の priority 20 より後で呼ぶ。それより前に呼ぶと `LogicException` になる。
- REST への公開のような、WordPress への登録に頼るほかの動作も `init` の priority 20 より後で効く。

## 読み取りの規則

- 読み取り(`has()`・`get()`・型付きの getter・`all()`)の途中でデータベースのエラーが起きたら、エンティティの Kernel の debug では `RuntimeException` が投げられる。本番では、その Kernel のログに error で記録され、値が無いときと同じ結果(引数の既定値・スキーマの既定値等)が返る。
- キャッシュから読んでデータベースに問い合わせなかった読み取りは、エラーにならない。

## 書き込みの規則

- `set()` は書き込んだら `true`、書き込まなかったら `false` を返す。`false` は失敗ではなく、同じ値だった場合と、ほかのプラグインのフィルタが書き込みを止めた場合で、保存されている値は変わっていない。データベースのエラーで書き込めなかったら `RuntimeException` が投げられる。
- 宣言済みのキーには、スキーマの型に合う値だけを渡す(`MetaValueType::Integer` なら int)。合わない値は `InvalidArgumentException` になる。フォームの文字列は呼び出し側で型を変換してから渡す。
- `MetaValueType::String` のキーに `Stringable` のオブジェクトを渡すと `InvalidArgumentException` になる。`(string)` にしてから渡す。
- WordPress のメタは `false` を空文字 `''` で保存する。`false` を保存したキーの `get()` は `''` を返す。`MetaValueType::Boolean` のキーは `bool()` で読む(`''` は `false` に戻る)。
- `single: false` のキーには list を渡す。`type` は1行ごとの型を書く。
- 値を消すときは `delete()` を使う。`null` を `set()` すると `InvalidArgumentException` になる。
- unslash済みの元の値をそのまま渡す。バックスラッシュを含む文字列(JSON・Windowsのパス・正規表現)も、FWが保存時に `wp_slash()` するので往復する。エンティティの `create()` の `meta_input` も同じ(`null`・object は `InvalidArgumentException`)。
- `#[WithMetaRevisions]` を付けた投稿タイプのプレビューでは、`has()`・`get()`・型付きの getter・`all()` のどれもオートセーブの値を返す(値が `0`・`''` でも行がある扱いになる)。

## プレビューにメタの変更を反映する(#[WithMetaRevisions])

投稿タイプのクラスに`#[WithMetaRevisions]`を付けると、そのクラスの`#[MetaSchema]`のキーが`register_post_meta()`の`revisions_enabled`で登録される。保存・オートセーブのときにメタがリビジョンへ複製され、プレビューではオートセーブの値が読まれる(WordPressのコアの機能。FWは独自のフックを足さない)。

- 付けられるのは投稿タイプ(`PostType`の子クラス)だけである。タクソノミー・ロールのクラスに付けると、起動時に`InvalidSchemaException`になる。
- 投稿タイプの定義で`supports('revisions')`を指定する。指定が無いと、起動時に`InvalidSchemaException`(`#[WithMetaRevisions] requires supports('revisions')`)になる。
- builtinの`post`のクラスでも、定義で`supports('revisions')`を指定する。FWは定義の`supports()`の値を検査するため、WordPressが`post`に付けているrevisionsでは足りない。

`app/Post/BlogPost.php`(builtinの`post`のアダプタ):

```php
<?php

declare(strict_types=1);

namespace App\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Attribute\WithMetaRevisions;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use WP_Post;

#[AsPostType]
#[WithMetaRevisions]
final class BlogPost extends PostType
{
    #[MetaSchema(type: MetaValueType::String, default: '')]
    public const META_SUBTITLE = 'subtitle';

    public static function definition(): PostTypeDefinition
    {
        // postにもWordPressがrevisionsを付けているが、#[WithMetaRevisions]には定義での指定が要る
        return PostTypeDefinition::builtin('post')
            ->supports('revisions');
    }

    public static function subtitle(WP_Post|int $post): string
    {
        return self::meta($post)->string(self::META_SUBTITLE);
    }
}
```

## 未保存の投稿も検索の対象にする

`MetaBag` の既定値はスキーマの `default` で補われ、データベースに行を作らない。そのため、メタを保存していない投稿は `whereMeta()`・`whereMetaExists()` の条件に合わない(`orderByMeta()` はメタの無い投稿も並べる)。メタの無い投稿も検索の対象にしたいときは、保存のフックで既定値を明示的に保存する。

`app/Hook/ProductMetaDefaultsHook.php`:

```php
<?php

declare(strict_types=1);

namespace App\Hook;

use App\Post\ProductPost;
use TMSWP\Core\Attribute\Action;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Hook\Hook;
use WP_Post;

#[AsHook]
final class ProductMetaDefaultsHook extends Hook
{
    /** 価格を保存していない製品に、既定値の行を保存する(whereMeta() の条件に合わせるため) */
    #[Action('save_post_product')]
    public function insertDefaults(int $postId, WP_Post $post): void
    {
        $meta = ProductPost::meta($post);

        if (!$meta->has(ProductPost::META_PRICE)) {
            $meta->set(ProductPost::META_PRICE, 0);
        }
    }
}
```

- フック名は `save_post_<投稿タイプのスラッグ>` にする。リビジョン(公開済みの投稿や他人の下書きの自動保存が作るオートセーブのリビジョンを含む)は投稿タイプが `revision` なので、このフックを通らない。一方、自分が作成者でロックの無い下書き(`draft`・`auto-draft`)の自動保存は投稿そのものを更新するので、このフックを通る(既定値の行の保存はそのときに行われても問題ない)。自動保存のときに処理を除きたい場合は、フック名に頼らず `if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }` のように明示的に除く。
- この方法は、フックを入れた後に保存した投稿にだけ効く。既存の投稿には、一度だけの処理(WP-CLI 等)で同じように保存する。

## テスト

PHPUnitの設定・`tests/bootstrap.php`・Brain Monkeyの使い方は`vendor/tmswp/testing/rules/testing.md`にある。この節はメタのテストに要ることだけを書く。

WordPressなしのユニットテストでは、tmswp/testing(`composer require --dev tmswp/testing`)を使い、Kernelの既定のメタの保存先をメモリの実装に差し替えてbootする。

- テストのクラスは`TMSWP\Testing\UnitTestCase`を継承する(Brain MonkeyとMockeryの準備と検証、翻訳関数のスタブを持つ)。
- `setUp()`で`StorageExtension`をテストの`KernelConfig`の`extensions`に渡してbootし、`tearDown()`で`Kernel::reset()`する。`StorageExtension`は、Containerの`MetaStorageInterface`・`OptionStorageInterface`のidに、渡した`MemoryMetaStorage`・`MemoryOptionStorage`を登録する。既定の保存先を差し替える方法は、拡張の`register()`の中での登録だけである(bootの後の登録は`ContainerException`になる)。
- bootの前に置くグローバルは、`$wp_version`(WordPressの版の検査)と`$wp_actions`(発火済みのフックの判定)だけである。`$wpdb`は要らない。フックの関数(`add_action()`等)はBrain Monkeyが受ける。
- `debug: true`で起動すると、`app/`をその場で探索する。探索結果ファイル(`tmswp-discovery.php`)は読まず、書き換えもしない。
- `#[UseMetaStorage]`を付けたエンティティは、差し替えに関係なく属性で指定したストレージを使う。
- `MemoryMetaStorage`は値をそのまま保存する。WordPressのメタと違い、`false`は`''`に、数値は文字列にならない。
- `WP_Post`は、Mockeryの代役に`ID`・`post_type`を入れて渡す。`MetaBag`を直接`new`せず、テストでも`::meta($object)`で取得する。
- `WpMetaStorage`そのものの動き(保存の形、データベースのエラーの扱い)に頼るコードは、WordPressの上(wp-envの統合テスト等)で確かめる。

`tests/Unit/Post/ProductPriceTest.php`(`app/Post/ProductPost.php`のテスト。`tests/Unit/`は`app/`の構成をミラーする):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Post;

use App\Post\ProductPost;
use Mockery;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Testing\MemoryMetaStorage;
use TMSWP\Testing\MemoryOptionStorage;
use TMSWP\Testing\StorageExtension;
use TMSWP\Testing\UnitTestCase;
use WP_Post;

final class ProductPriceTest extends UnitTestCase
{
    private MemoryMetaStorage $meta;

    protected function setUp(): void
    {
        parent::setUp();
        // Kernel::boot()が読むWordPressの版と発火済みのフック。$wpdbは要らない
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];

        $this->meta = new MemoryMetaStorage();
        Kernel::boot(KernelConfig::theme(
            \dirname(__DIR__, 3) . '/app',
            'App',
            debug: true,
            extensions: [new StorageExtension($this->meta, new MemoryOptionStorage())],
        ));
    }

    protected function tearDown(): void
    {
        Kernel::reset();
        unset($GLOBALS['wp_version'], $GLOBALS['wp_actions']);
        parent::tearDown();
    }

    public function testDefaultIsUsedWhenNothingIsStored(): void
    {
        self::assertSame(0, ProductPost::price(self::product(11)));
        self::assertFalse(ProductPost::meta(self::product(11))->has(ProductPost::META_PRICE));
    }

    public function testPriceIsReadFromAndWrittenToTheMemoryStorage(): void
    {
        $this->meta->set(ObjectType::Post, 10, ProductPost::META_PRICE, 2500);
        $post = self::product(10);

        self::assertSame(2500, ProductPost::price($post));
        self::assertTrue(ProductPost::meta($post)->set(ProductPost::META_PRICE, 3000));
        self::assertSame(3000, $this->meta->get(ObjectType::Post, 10, ProductPost::META_PRICE));
    }

    private static function product(int $id): WP_Post
    {
        $post = Mockery::mock(WP_Post::class);
        self::assertInstanceOf(WP_Post::class, $post);
        $post->ID = $id;
        $post->post_type = 'product';

        return $post;
    }
}
```

## してはいけないこと

- メタキー文字列をテンプレートに直書きしない。宣言済みのキーは定数で渡す。
- ACFのフィールドグループで管理するキーを、`#[UseMetaStorage(AcfMetaStorage::class)]`の無いエンティティで宣言しない(「ACFのフィールドグループで管理するキー」)。
- 未保存判定に falsy 判定を使わない。必要なら `has()` を使う。
- `#[MetaSchema]` を `protected` / `private` の定数に付けない(起動時に `InvalidSchemaException` になる)。
- `set()` に渡す値を自分で `wp_slash()` しない(バックスラッシュが残る)。
- `set()` の戻り値 `false` を失敗として扱わない(保存値は変わっていない)。
- `type: MetaValueType::Array` / `Object` のメタを `showInRest: true` だけでRESTに出さない。`showInRest: ['schema' => ['items' => [...]]]`(Objectは `properties` 等)を書く。

## 確認方法

1. `composer qa` が通ること。
2. `showInRest: true` のメタがRESTレスポンスに含まれること。
3. `set()` した値が再取得できること。

## 発展

- ACFの画面で編集する・ACFの複合型を扱う: rules/acf.md
- サイト全体の設定値: rules/options.md
- メタを持たせる投稿タイプ・タクソノミー・ロール: rules/post-type.md・rules/taxonomy.md・rules/user-role.md
- テストの書き方の全体(PHPUnitの設定・Brain Monkey): vendor/tmswp/testing/rules/testing.md
- `#[MetaSchema]`・`MetaBag`・ストレージの仕様: TMSWPの設計文書[docs/04-meta-options.md](https://github.com/tmstack-io/tmswp/blob/v0.9.1/docs/04-meta-options.md)
