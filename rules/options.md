# サイト設定値を定義する

サイト設定は backed enum と `OptionAccess` で宣言する。`get_option()` / `update_option()` を直接呼ばない。
コード例の名前空間`App\`は、自分のプロジェクトの名前空間(composer.jsonの`autoload.psr-4`で`app/`に割り当て、`tmswp-kernel.php`の`appNamespace`に書いたもの。スターターから作ったプロジェクトでは、setup.phpがスラッグから作った名前。例: `my-theme`なら`MyTheme\`)に読み替える。

## 使い分け

- 入力検証が重要なシステム設定(メールの宛先、APIキー等)は、このオプショングループで宣言し、設定画面はsettings-pageで作る。APIキー等の秘密情報もここに保存してよい(下の「してはいけないこと」の扱いを守る)。
- フロントに出すコンテンツ設定(画像、繰り返し項目等)は、ACFのオプションページで作り、acf-bridgeの生成アクセサ(`XxxOptions`)で読む(rules/acf.md)。同じ値をオプショングループにも宣言しない。
- 投稿・ターム・ユーザーごとの値は設定ではなくメタで扱う(rules/meta.md)。

## 手順

1. `app/Option/<Name>Option.php`を作る(例: `SiteOption`)。
2. `#[AsOptionGroup(prefix: '<接頭辞>_')]`を付けたstring backed enumにし、`OptionAccess`をuseする。
3. 各ケースに`#[OptionSchema]`で型と既定値を宣言する。ケースの値がオプション名(接頭辞の後)になる。
4. 頻繁に読む設定は、enumに明示アクセサ(下の例の`contactEmail()`)をstaticメソッドで書く。
5. WordPressの環境で`wp tmswp discover`(スターターから作ったプロジェクトでは`composer discover`)を実行し、探索結果ファイル`tmswp-discovery.php`を作り直してコミットする。
6. `composer qa`を通す。

## 完全な実装例

```php
<?php

declare(strict_types=1);

namespace App\Option;

use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Option\OptionAccess;

#[AsOptionGroup(prefix: 'site_')]
enum SiteOption: string
{
    use OptionAccess;

    #[OptionSchema(type: MetaValueType::String, default: '')]
    case ContactEmail = 'contact_email';

    public static function contactEmail(): string
    {
        return self::ContactEmail->string();
    }
}
```

- 実際のオプション名は、接頭辞とケースの値をつないだもの(`site_contact_email`)になる。
- 管理画面で編集する場合は、tmswp/settings-pageの設定ページで作る(rules/extensions.md、`vendor/tmswp/settings-page/rules/settings-page.md`)。

## テンプレートでの使い方

```php
<?php

declare(strict_types=1);

use App\Option\SiteOption;

$contactEmail = SiteOption::contactEmail();
?>
<?php if ($contactEmail !== '') : ?>
    <a href="<?php echo esc_url('mailto:' . antispambot($contactEmail)); ?>"><?php echo esc_html(antispambot($contactEmail)); ?></a>
<?php endif; ?>
```

- テンプレートからは、enumの明示アクセサ(`contactEmail()`)を呼ぶ。未設定(既定値)の場合の出し分けを書く。

## 属性の引数

| 属性 | 引数 | 既定 | 内容 |
|---|---|---|---|
| `#[AsOptionGroup]` | `prefix` | `''` | オプション名の接頭辞。ほかのテーマ・プラグインと重ならない名前にする |
| `#[AsOptionGroup]` | `storage` | `OptionStorageInterface::class` | 保存先のContainerのid。通常は省く(Kernelの既定の保存先) |
| `#[OptionSchema]` | `type` | `MetaValueType::String` | 値の型。`String`・`Boolean`・`Integer`・`Number`・`Array`・`Object` |
| `#[OptionSchema]` | `default` | `null` | 値が無いときに読み取りが返す既定値。データベースには保存しない |
| `#[OptionSchema]` | `autoload` | `true` | `add_option()`のautoload |

## ケースのメソッド

| メソッド | 動き |
|---|---|
| `SiteOption::ContactEmail->int()`・`float()`・`bool()`・`string()`・`list()`・`json()` | 型付きで読む。値が無ければスキーマの`default`、それも無ければ引数の既定値 |
| `->get()` | 型を変えずに読む |
| `->has()` | 保存されているか |
| `->set($value)` | 書き込む。書き込んだら`true`(下の「書き込みの規則」) |
| `->delete()` | 消す |
| `->name()` | 接頭辞の付いたオプション名 |
| `SiteOption::all()` | 宣言済みの全ケースの値(接頭辞の無いケースの値 => 値) |
| `SiteOption::deleteAll()` | 宣言済みの全ケースを消し、実際に消したオプション名を返す |
| `SiteOption::repository()` | 接頭辞の付く`OptionRepository`(下の「未宣言のキーを読み書きする」) |

## キーの渡し方

- 宣言済みのキーは enum のケースで読み書きする(`SiteOption::ContactEmail->string()`)。`SiteOption::repository()->get('contact_email')` のように文字列で読み書きしない。この規約は自動では検査しないため、レビューで確かめる。
- 頻繁に読む設定は、テンプレートから enum のケースを直接呼ばず、明示アクセサ(`SiteOption::contactEmail()`)を呼ぶ。

## 未宣言のキーを読み書きする

- グループの接頭辞の中で、ケースとして宣言していないキーは、`SiteOption::repository()` の `OptionRepository` に文字列で渡して読み書きする。接頭辞は付くが、スキーマの既定値と型の検査は効かない。
- ほかのプラグインのオプション等、接頭辞の無いオプション名は、アプリの Kernel の Container から `OptionStorageInterface` の保存先を取り出して読み書きする。Container が作るクラス(`#[AsHook]` のクラス等)なら、コンストラクタの引数に `OptionStorageInterface` を書けば渡される。`get_option()` を直接書かない。`WpOptionStorage` を自分で `new` しない(保存先は、取り出した Kernel の debug とログでデータベースのエラーを扱い、テストで差し替えた実装にもなる)。

```php
<?php

use App\Option\SiteOption;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Option\OptionStorageInterface;

// 実際のオプション名は site_extra_note(グループの接頭辞が付く)
$note = SiteOption::repository()->string('extra_note');

// 接頭辞の無いオプション名
$options = Kernel::forClass(SiteOption::class)->container()->get(OptionStorageInterface::class);
$siteName = $options->get('blogname');
```

## 読み取りの規則

- 読み取り(`has()`・`get()`・型付きの getter・`all()`)の途中でデータベースのエラーが起きたら、オプショングループ(または保存先を取り出した Container)の Kernel の debug では `RuntimeException` が投げられる。本番では、その Kernel のログに error で記録され、値が無いときと同じ結果(引数の既定値・スキーマの既定値等)が返る。
- キャッシュから読んでデータベースに問い合わせなかった読み取りは、エラーにならない。

## 書き込みの規則

- `set()` は書き込んだら `true`、書き込まなかったら `false` を返す。`false` は失敗ではなく、同じ値だった場合と、ほかのプラグインのフィルタが書き込みを止めた場合で、保存されている値は変わっていない。データベースのエラーで書き込めなかったら `RuntimeException` が投げられる。
- 同じ値かどうかは WordPress の `update_option()` が決める。サニタイズ(`sanitize_option_{$name}`)やフィルタの結果が保存済みの値と同じになった場合も、`update_option()` が書き込まずに `false` を返すので `false` になる。
- 宣言済みのケースには、`#[OptionSchema]` の型に合う値だけを渡す(`MetaValueType::Boolean` なら bool)。合わない値は `InvalidArgumentException` になる。フォームの文字列は呼び出し側で型を変換してから渡す。`MetaValueType::String` のケースに `Stringable` のオブジェクトを渡しても `InvalidArgumentException` になる(`(string)` にしてから渡す)。
- 値を消すときは `delete()` を使う。`null` を `set()` すると `InvalidArgumentException` になり、保存されない。
- `deleteAll()` は実際に削除したオプション名だけを返す。

## テスト

PHPUnitの設定・`tests/bootstrap.php`・Brain Monkeyの使い方は`vendor/tmswp/testing/rules/testing.md`にある。この節はオプションのテストに要ることだけを書く。

WordPressなしのユニットテストでは、tmswp/testing(`composer require --dev tmswp/testing`)を使い、Kernelの既定のオプションの保存先をメモリの実装に差し替えてbootする。

- テストのクラスは`TMSWP\Testing\UnitTestCase`を継承する(Brain MonkeyとMockeryの準備と検証、翻訳関数のスタブを持つ)。
- `setUp()`で`StorageExtension`をテストの`KernelConfig`の`extensions`に渡してbootし、`tearDown()`で`Kernel::reset()`する。`StorageExtension`は、Containerの`MetaStorageInterface`・`OptionStorageInterface`のidに、渡した`MemoryMetaStorage`・`MemoryOptionStorage`を登録する。既定の保存先を差し替える方法は、拡張の`register()`の中での登録だけである(bootの後の登録は`ContainerException`になる)。
- 差し替わるのは、`#[AsOptionGroup]`で`storage`を指定しないグループと、Containerから取り出した`OptionStorageInterface`である。`storage`を指定したグループは、差し替えに関係なく指定したストレージを使う。
- bootの前に置くグローバルは、`$wp_version`(WordPressの版の検査)と`$wp_actions`(発火済みのフックの判定)だけである。`$wpdb`は要らない。フックの関数(`add_action()`等)はBrain Monkeyが受ける。
- `debug: true`で起動すると、`app/`をその場で探索する。探索結果ファイル(`tmswp-discovery.php`)は読まず、書き換えもしない。
- `MemoryOptionStorage`は、グループの接頭辞の付いたオプション名(`site_contact_email`)で値を持つ。値はそのまま保存し、同じ値かどうかは`===`で判定する。
- `get_option()`・`update_option()`をBrain Monkeyで差し替えてオプショングループをテストしない。保存先は`StorageExtension`で差し替える。
- `WpOptionStorage`そのものの動き(同じ値の判定、データベースのエラーの扱い)に頼るコードは、WordPressの上(wp-envの統合テスト等)で確かめる。

`tests/Unit/Option/SiteOptionTest.php`(`app/Option/SiteOption.php`のテスト。`tests/Unit/`は`app/`の構成をミラーする):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Option;

use App\Option\SiteOption;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Testing\MemoryMetaStorage;
use TMSWP\Testing\MemoryOptionStorage;
use TMSWP\Testing\StorageExtension;
use TMSWP\Testing\UnitTestCase;

final class SiteOptionTest extends UnitTestCase
{
    private MemoryOptionStorage $options;

    protected function setUp(): void
    {
        parent::setUp();
        // Kernel::boot()が読むWordPressの版と発火済みのフック。$wpdbは要らない
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];

        $this->options = new MemoryOptionStorage();
        Kernel::boot(KernelConfig::theme(
            \dirname(__DIR__, 3) . '/app',
            'App',
            debug: true,
            extensions: [new StorageExtension(new MemoryMetaStorage(), $this->options)],
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
        self::assertSame('', SiteOption::contactEmail());
    }

    public function testContactEmailIsStoredUnderThePrefixedOptionName(): void
    {
        $this->options->set('site_contact_email', 'info@example.test');
        self::assertSame('info@example.test', SiteOption::contactEmail());

        self::assertTrue(SiteOption::ContactEmail->set('sales@example.test'));
        self::assertSame('sales@example.test', $this->options->get('site_contact_email'));
    }
}
```

## してはいけないこと

- オプション名を文字列で直書きしない。
- 巨大な配列を1オプションに保存しない。
- APIキー等の秘密情報をオプションに保存する場合も、テンプレートへの出力・ログへの記録・フロントへの公開(REST等)をしない。
- `#[OptionSchema]` をenumのケース以外(ケースでない定数・enum自身・メソッド・引数)に付けない(debugのbootと`wp tmswp discover`では`DiscoveryException`、本番では`init`(priority 5)か最初の`repository()`で`InvalidSchemaException`になる)。`#[AsOptionGroup]` の無い enum のケースにも付けない(debug の boot と `wp tmswp discover` で `DiscoveryException` になる)。
- `set()` の戻り値 `false` を失敗として扱わない(保存値は変わっていない)。

## 確認方法

1. `composer qa` が通ること。
2. `SiteOption::ContactEmail->set()` 後に値が取得できること。

## 発展

- 設定値を管理画面で編集する: vendor/tmswp/settings-page/rules/settings-page.md(導入はrules/extensions.md)
- フロントに出すコンテンツの設定(ACFのオプションページ): rules/acf.md
- 投稿・ターム・ユーザーごとの値: rules/meta.md
- テストの書き方の全体(PHPUnitの設定・Brain Monkey): vendor/tmswp/testing/rules/testing.md
- オプショングループの仕様: TMSWPの設計文書[docs/04-meta-options.md](https://github.com/tmstack-io/tmswp/blob/v0.9.1/docs/04-meta-options.md)
