# ACFでフィールドを管理する

ACFのフィールドグループは`acf-json/`のJSONで管理し、tmswp/acf-bridgeの`composer acf:sync`(`vendor/bin/tms-acf sync`)で型付きアクセサを生成して読み書きする。
アプリのコードで`get_field()`等のACFの関数を直接呼ばない。
例はテーマのもの。コード例の名前空間`App\`は、自分のプロジェクトの名前空間(composer.jsonの`autoload.psr-4`で`app/`に割り当て、`tmswp-kernel.php`の`appNamespace`に書いたもの。スターターから作ったプロジェクトでは、setup.phpがスラッグから作った名前。例: `my-theme`なら`MyTheme\`)に読み替える。

## 使い分け

値の種類ごとに、宣言と読み書きの経路を1つに決める。同じ値を複数の経路で宣言しない。

| 値の種類 | 経路 |
| --- | --- |
| 単純な値(文字列・数値・真偽・日付・単一画像ID等) | エンティティの定数に`#[MetaSchema]`で宣言し、アプリは`MetaBag`だけで読み書きする。ACFの画面で編集する場合は、エンティティに`#[UseMetaStorage(AcfMetaStorage::class)]`を付けて保存先だけをACFに切り替える(下の「単純な値をACFの画面で編集する」)。 |
| ACF固有の複合型(repeater・flexible_content・group・gallery・relationship) | 生成アクセサ(`XxxFields`)で読み書きする。その案件はACFに依存するものと割り切る。 |
| 入力検証が重要なシステム設定(メールの宛先、APIキー等) | オプショングループのenumに`#[OptionSchema]`で宣言し、設定画面はsettings-pageで作る。 |
| フロントに出すコンテンツ設定(画像、繰り返し項目等) | ACFのオプションページで作り、生成アクセサ(`XxxOptions`)で読む。 |

- 単純な値を`MetaBag`で扱うのは、ACFを外しても宣言と読み書きのコードがそのまま動くようにするためである。
- ACFのフィールドグループに単純な値の型のフィールドがあると、生成アクセサにもそのメソッド(`price()`等)が生成される。`#[MetaSchema]`で宣言したキーは`MetaBag`だけで読み書きし、生成アクセサのそのメソッドを呼ばない。

## 手順

1. `composer require tmswp/acf-bridge`を実行する。
2. テーマ・プラグインのルートの`tmswp-kernel.php`が返す`KernelConfig`の`extensions`に`new AcfBridge()`を渡す。テーマとプラグインで使うときは、それぞれの`tmswp-kernel.php`に渡す。
3. composer.jsonの`scripts`に`"acf:check": "tms-acf check"`と`"acf:sync": "tms-acf sync"`を足す(スターターには含まれない)。
4. ACFの管理画面でフィールドグループを作り、location(投稿タイプ・タクソノミー・オプションページ等)を設定して保存する。
5. ルートの`acf-json/`にJSONが保存されたことを確かめる。プラグインのacf-jsonから読み込んだグループは、プラグインのacf-jsonへ書き戻される。
6. テーマ・プラグインのルートで`composer acf:sync`を実行する。WordPressを起動せずに、`tmswp-kernel.php`の構成で`app/Acf/`にクラスを生成する。別のディレクトリからは`vendor/bin/tms-acf sync --root=<ルート>`で対象を選ぶ。
7. `acf-json/`の変更と`app/Acf/`の生成物を、同じコミットに入れる(下の「acf-jsonと生成物を同じコミットにする」)。
8. 単純な値は`MetaBag`で、複合型は`app/Acf/`の生成アクセサで読み書きする。

生成されるクラスは、フィールドグループのlocationで決まる。

| location | 生成クラス | 取得 |
| --- | --- | --- |
| 投稿タイプ(`post_type == item`) | `App\Acf\ItemFields` | `ItemFields::of($post)` |
| テンプレート(`page_template == templates/page-about.php`) | `App\Acf\PageAboutTemplateFields` | `PageAboutTemplateFields::of($post)` |
| タクソノミー(`taxonomy == genre`。`all`は`TermFields`) | `App\Acf\GenreTermFields` | `GenreTermFields::of($term)` |
| ユーザー | `App\Acf\UserFields` | `UserFields::of($user)` |
| オプションページ(`options_page == theme-settings`) | `App\Acf\ThemeSettingsOptions` | `ThemeSettingsOptions::instance()` |

- `of()`は`WP_Post|int`(タームは`WP_Term|int`、ユーザーは`WP_User|int`)を受け取る。1未満のIDは`InvalidArgumentException`になる。`get_the_ID()`は`int|false`を返すため、そのまま渡さない。シングルのテンプレートでは`get_queried_object_id()`を渡し、ループの中では`get_post()`が`null`でないことを確かめてから渡す。
- 生成クラスは、フィールドごとに、getter(`stores()`)・setter(`setStores()`)・`hasStores()`・`deleteStores()`と、フィールド名の定数(`STORES`)・フィールドのkeyの定数(`KEY_STORES`)を持つ。date_picker・date_time_pickerには`{名前}AsDateTime(): ?DateTimeImmutable`も生成される。
- getterは未設定の値を、getterの戻り値の型で決まる値で返す。`string`の型(text・wysiwyg・date_picker等)は`''`、`bool`の型(true_false)は`false`、`list<>`の型(repeater・flexible_content・gallery・relationship・複数選択等)は`[]`である。`null`を含む型は`null`で、数値(`int|float|null`)・単一のselect・ID・オブジェクトと、配列のshapeの型(group・返り値の形式が配列のimage・link等。`AcfImage|null`等)がこれに当たる。
- `app/Acf/AcfManifest.php`も生成される。ACFで管理するメタのキーの一覧で、acf-bridgeが実行時に読む。

## 完全な実装例

投稿タイプ`item`(商品)に、ACFのフィールドグループ「商品情報」を作る。フィールドは次の3つ。

| フィールド名 | 型 | 経路 |
| --- | --- | --- |
| `price` | number | `#[MetaSchema]`と`MetaBag` |
| `main_visual` | image(返り値の形式は画像ID) | `#[MetaSchema]`と`MetaBag` |
| `stores` | repeater(サブフィールド`shop_name`・`tel`はtext) | 生成アクセサ`ItemFields::stores()` |

### Kernelの構成

`tmswp-kernel.php`(テーマのルート。WordPressを起動しない`tms-acf`も読むため、WordPressの関数を呼ばない):

```php
<?php

declare(strict_types=1);

use TMSWP\AcfBridge\AcfBridge;
use TMSWP\Core\Kernel\KernelConfig;

return KernelConfig::theme(appDir: __DIR__ . '/app', appNamespace: 'App', extensions: [new AcfBridge()]);
```

`functions.php`:

```php
<?php

declare(strict_types=1);

use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;

require_once __DIR__ . '/vendor/autoload.php';

Kernel::boot(KernelConfig::load(__DIR__ . '/tmswp-kernel.php'));
```

### 単純な値をACFの画面で編集する

`app/Post/ItemPost.php`:

```php
<?php

declare(strict_types=1);

namespace App\Post;

use TMSWP\AcfBridge\AcfMetaStorage;
use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Attribute\MetaSchema;
use TMSWP\Core\Attribute\UseMetaStorage;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;
use WP_Post;

#[AsPostType]
#[UseMetaStorage(AcfMetaStorage::class)]
final class ItemPost extends PostType
{
    // ACFのフィールドグループ「商品情報」のnumberのフィールド
    #[MetaSchema(type: MetaValueType::Integer, default: 0)]
    public const META_PRICE = 'price';

    // ACFのフィールドグループ「商品情報」のimageのフィールド(添付ファイルのID。未設定は0)
    #[MetaSchema(type: MetaValueType::Integer, default: 0)]
    public const META_MAIN_VISUAL = 'main_visual';

    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('item')
            ->label('商品')
            ->labelLanguage(LabelLanguage::Ja)
            ->supports('title', 'editor');
    }

    public static function price(WP_Post|int $post): int
    {
        return self::meta($post)->int(self::META_PRICE);
    }

    public static function mainVisualId(WP_Post|int $post): int
    {
        return self::meta($post)->int(self::META_MAIN_VISUAL);
    }
}
```

- `#[UseMetaStorage(AcfMetaStorage::class)]`を付けると、`ItemPost::meta($post)`の`MetaBag`は、ACFのフィールドの保存先(値のメタと、フィールドのkeyを持つ参照のメタ`_{キー}`)を読み書きする。ACFの画面で保存した値を`MetaBag`で読め、`MetaBag`で保存した値をACFの画面で編集できる。
- `AcfMetaStorage`は、ACFの値の書き込みのAPIとフィルタ(`update_field()`・`acf/update_value`等)を通さずに、メタを直接読み書きする。読み取りは保存された値(raw)を返す。画像の配列等のフォーマット済みの値が要るときは、`wp_get_attachment_image()`等にIDを渡す。
- `#[MetaSchema]`で宣言できるACFのフィールドは、単純な値の型(`AcfMetaStorage::SUPPORTED_FIELD_TYPES`。text・textarea・number・range・email・url・password・wysiwyg・oembed・true_false・select・radio・button_group・date_picker・time_picker・date_time_picker・color_picker・image・file)だけである。selectは`multiple`でないもの、radioは`save_other_choice`でないもの、image・fileは単一の添付ファイルのID(int)に限る。
- `MetaBag`には、キーを宣言したエンティティの定数を渡す(`ItemPost::meta($post)->int(ItemPost::META_PRICE)`)。

### テンプレートで読む

`single-item.php`:

```php
<?php

declare(strict_types=1);

use App\Acf\ItemFields;
use App\Post\ItemPost;

// シングルのテンプレートの表示中の投稿のID(get_the_ID()はint|falseを返すため使わない)
$itemId = get_queried_object_id();
$price = ItemPost::price($itemId);
$mainVisualId = ItemPost::mainVisualId($itemId);
$stores = ItemFields::of($itemId)->stores();

get_header();
?>
<main class="item">
    <h1><?php echo esc_html(get_the_title($itemId)); ?></h1>
    <?php if ($mainVisualId > 0) : ?>
        <?php echo wp_get_attachment_image($mainVisualId, 'large'); ?>
    <?php endif; ?>
    <p class="price"><?php echo esc_html(number_format_i18n($price)); ?>円</p>

    <?php if ($stores !== []) : ?>
        <ul class="stores">
            <?php foreach ($stores as $store) : ?>
                <li><?php echo esc_html($store['shop_name']); ?>(<?php echo esc_html($store['tel']); ?>)</li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>
<?php
get_footer();
```

- `stores()`の戻り値の型は`list<array{shop_name: string, tel: string}>`で、行もサブフィールドも型どおりに正規化されている。
- ACFが返した値が型に合わないとき(textに配列が保存されている等)は、debugでは`AcfBridgeRuntimeException`になり、本番ではログに記録して未設定の値を返す(listは不正な要素だけを除く)。

### flexible_contentをmatchで出し分ける

固定ページ(`post_type == page`)のフィールド`sections`(flexible_content)に、レイアウト`hero`(サブフィールド`title`はtext、`image`はimageで返り値の形式は画像ID)と`text_block`(`body`はwysiwyg)がある場合、生成クラス`App\Acf\PageFields`の`sections()`の戻り値の型は`list<Sections_Hero|Sections_TextBlock>`になる。型の別名は、生成クラスのPHPDocに次のとおり宣言される。

```text
@phpstan-type Sections_Hero array{acf_fc_layout: 'hero', title: string, image: int|null}
@phpstan-type Sections_TextBlock array{acf_fc_layout: 'text_block', body: string}
```

各行を`acf_fc_layout`で`match`する。`acf_fc_layout`はレイアウトの名前のリテラルの型のため、PHPStanは各アームで、そのレイアウトのキー(`$section['title']`等)を型どおりに扱う。

`page.php`:

```php
<?php

declare(strict_types=1);

use App\Acf\PageFields;

$sections = PageFields::of(get_queried_object_id())->sections();

get_header();
?>
<main class="page">
    <?php foreach ($sections as $section) : ?>
        <section class="section">
            <?php echo match ($section['acf_fc_layout']) {
                'hero' => '<h2>' . esc_html($section['title']) . '</h2>'
                    . ($section['image'] !== null ? wp_get_attachment_image($section['image'], 'large') : ''),
                'text_block' => wp_kses_post($section['body']),
            }; ?>
        </section>
    <?php endforeach; ?>
</main>
<?php
get_footer();
```

- `match`に`default`のアームを書かない。ACFでレイアウトを足してsyncしたとき、扱っていないレイアウトの行が実行時に`UnhandledMatchError`になり、PHPStanが解析するコード(`app/`)では解析の時点で報告されるため、出し分けの漏れに気づける。`default`があると、新しいレイアウトが黙って表示されない。
- 型の別名の名前は`{フィールド名のStudly}_{レイアウト名のStudly}`。
- `sections()`は、レイアウトに無い行を返さない(debugでは`AcfBridgeRuntimeException`、本番ではログに記録して除く)。

### オプションページの値を読む

ACFのオプションページ(menu_slug`theme-settings`)のフィールド`contact_tel`(text)と`site_logo`(imageで返り値の形式は画像ID)は、生成クラス`App\Acf\ThemeSettingsOptions`で読む。

`parts/site-footer.php`:

```php
<?php

declare(strict_types=1);

use App\Acf\ThemeSettingsOptions;

$settings = ThemeSettingsOptions::instance();
$logoId = $settings->siteLogo();
$tel = $settings->contactTel();
?>
<footer class="site-footer">
    <?php if ($logoId !== null) : ?>
        <?php echo wp_get_attachment_image($logoId, 'medium'); ?>
    <?php endif; ?>
    <?php if ($tel !== '') : ?>
        <p class="tel"><?php echo esc_html($tel); ?></p>
    <?php endif; ?>
</footer>
```

- 生成クラスが読むACFのpost_idは、オプションページの`post_id`の設定(acf-jsonの`ui_options_page_*.json`)で決まり、設定が無ければ`option`になる。
- オプションページの値を`#[OptionSchema]`で重複して宣言しない(下の「してはいけないこと」)。

### value()で名前を指定して読む

生成クラスは、フィールドの名前でACFを呼ぶ公開のメソッドも持つ。生成アクセサの無いフィールドを読み書きするときだけ使う。

| メソッド | 動作 |
| --- | --- |
| `value(string $name): mixed` | フォーマット済みの値(`get_field($name, $id, true)`)。型を検査しない |
| `rawValue(string $name): mixed` | 保存された値(`get_field($name, $id, false)`) |
| `has(string $name): bool` | 値が保存されているか(フィールドの`default_value`は「ある」としない) |
| `update(string $name, mixed $value): bool` | `update_field()`で保存する |
| `delete(string $name): bool` | `delete_field()`で消す |

`value()`の戻り値は`mixed`のため、型を確かめてから使う。

```php
<?php

declare(strict_types=1);

use App\Acf\ItemFields;

// フィールド名が日本語(「備考」)のため、生成アクセサが無い
$note = ItemFields::of(get_queried_object_id())->value('備考');
?>
<?php if (is_string($note) && $note !== '') : ?>
    <p class="note"><?php echo esc_html($note); ?></p>
<?php endif; ?>
```

- ASCIIのsnake_case(`^[a-z][a-z0-9_]*$`)でないフィールド名には、生成アクセサが生成されず、syncとcheckがW009を出す。W009が残ると`check --strict`は失敗するため、ACFでフィールド名を変えるか、`BridgeConfig`の`methodNameMap`でメソッド名を付けて生成アクセサで読むのが原則である(vendor/tmswp/acf-bridge/rules/sync-check.md)。`value()`は、それまでの間(既存のサイトのコードを移す途中等)に名前で読むために使う。

## acf-jsonと生成物を同じコミットにする

生成物(`app/Acf/`。`AcfManifest.php`を含む)は、acf-jsonから作るものである。acf-bridgeは実行時にacf-jsonを読まず、コミットされた生成物だけを使う。acf-jsonと生成物が食い違うと、生成アクセサが古いフィールドのkeyでACFを呼んで`AcfBridgeRuntimeException`になったり、`AcfManifest`のメタのキーが古いままになったりする。

1. ACFの管理画面でフィールドグループを保存したら、`composer acf:sync`を実行する。
2. `acf-json/`の変更と`app/Acf/`の変更(追加・変更・削除)を、同じコミットに入れる。
3. CIで`composer acf:check -- --strict`を実行し、失敗したPRをマージしない。
4. 生成物(`app/Acf/`)と`acf-json/`を本番へ上げる(上げるものはrules/deploy.md)。`AcfManifest`クラスが無いと、WordPressの実行時(`init`)に`AcfBridgeRuntimeException`になる。

`check`は、メモリ上で生成した内容と`app/Acf/`のファイルを比べる。WordPressとデータベースは要らない。

| 終了コード | 意味 |
| --- | --- |
| 0 | 差分が無い。警告(W*)があっても、`--strict`を付けなければ0になる |
| 1 | 差分がある(`missing`・`modified`・`orphan`の行で示す)。`--strict`のときは、差分が無くても警告(W*)があれば1になる |
| 2 | エラー(E*)、または構成・引数の誤り |

差分は`composer acf:sync`で直す。E*とW*の対処はvendor/tmswp/acf-bridge/rules/sync-check.mdを見る。

GitHub Actionsのステップ:

```yaml
- name: ACF drift check
  run: composer acf:check -- --strict
```

- `tms-acf check`は、`#[MetaSchema]`の宣言とACFで管理するキーの重複(E101)を検査しない。起動したKernelが要るためである。E101は、WordPressの環境で`wp tmswp acf check --strict`を実行して検査する。wp-envでは、package.jsonで版を固定して`npm ci`で入れた`@wordpress/env`をパスで呼ぶ(`node node_modules/@wordpress/env/bin/wp-env run cli wp tmswp acf check --strict`)。
- テーマとプラグインの両方でacf-bridgeを使うときは、それぞれのルートで`composer acf:check -- --strict`を実行する。`wp tmswp acf check`は`--app=<appNamespace>`で対象のKernelを選ぶ。

## してはいけないこと

- アプリのコードで`get_field()`・`the_field()`・`update_field()`等のACFの関数を直接呼ばない。生成アクセサか`MetaBag`を使う。名前で読む必要があれば生成クラスの`value()`を使う。
- `app/Acf/`の生成ファイルを手で編集しない。`app/Acf/`に手書きのPHPファイルを置かない(syncとcheckがI002を出す)。
- acf-jsonの変更と生成物の再生成を別のコミットにしない。`check --strict`が失敗したPRをマージしない。警告を消すために`--strict`を外さない。
- ACFの管理画面(ACF 6.1以降の「投稿タイプ」「タクソノミー」の画面)で投稿タイプ・タクソノミーを登録しない。`#[AsPostType]`・`#[AsTaxonomy]`でPHPに宣言する。acf-jsonの`post_type_*.json`・`taxonomy_*.json`は生成の対象外で、syncとcheckがW001を出す。
- フィールドグループを`acf_add_local_field_group()`でPHPから登録しない。生成はacf-jsonだけを読むため、アクセサが生成されない。
- ACFのオプションページで管理する値を、オプショングループのenumの`#[OptionSchema]`でも宣言しない。保存先が2つになり、どちらの値が使われるか分からなくなる。この重複は起動時にも`check`でも検出されない。システム設定はオプショングループとsettings-page、コンテンツ設定はACFのオプションページのどちらか1つに決める(上の「使い分け」)。
- ACFのフィールドグループのlocationに`block`(ACF Blocks)を使わない。acf-bridgeはACF Blocksのアクセサを生成せず、syncとcheckがW006を出す。独自ブロックは、フレームワークの独自ブロックの方式で作る。
- ACFのフィールドグループで管理するキーを、`#[UseMetaStorage(AcfMetaStorage::class)]`の無いエンティティの`#[MetaSchema]`で宣言しない。`init`(priority 6)の時点で`DuplicateMetaKeyException`になり、`wp tmswp acf check`もE101で失敗する。
- 単純な値の型でないACFのフィールド(gallery・relationship・group・repeater等)のキーを、`#[MetaSchema]`で宣言しない。`AcfMetaStorage`での書き込みが`AcfBridgeRuntimeException`になる。生成アクセサで読み書きする。
- 同じキーを、`MetaBag`と生成アクセサの両方から読み書きしない。`#[MetaSchema]`で宣言したキーは`MetaBag`だけで扱う。
- `value()`・`rawValue()`の戻り値を、型を確かめずに使わない。
- flexible_contentの`match`に`default`のアームを書かない。

## 確認方法

1. `composer acf:check -- --strict`が通ること。
2. WordPressの環境で`wp tmswp acf check --strict`が通ること(E101を含めて検査する)。
3. `composer qa`が通ること。
4. 管理画面で入力した値が、テンプレートに表示されること。`MetaBag`で扱う値は、`MetaBag`で保存した値が管理画面のフィールドに表示されることも確かめる。

## 発展

- sync・checkが出すE*・W*・I002の対処と、`BridgeConfig`で生成を調整する方法(子テーマのacf-json、クラス名・メソッド名の指定、PHPで登録したオプションページ): vendor/tmswp/acf-bridge/rules/sync-check.md
- `MetaBag`と`#[MetaSchema]`の規則(キーの渡し方・書き込みの戻り値、ACFのフィールドグループで管理するキー): rules/meta.md
- オプショングループとsettings-page: rules/options.md
- 投稿タイプ・タクソノミーをPHPで宣言する: rules/post-type.md・rules/taxonomy.md
- 本番へのデプロイの手順: rules/deploy.md
