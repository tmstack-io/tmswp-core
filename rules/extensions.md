# 拡張パッケージを導入する

coreに含まれない機能は、公式の拡張パッケージを`composer require`し、テーマ・プラグインのルートの`tmswp-kernel.php`で`KernelConfig`の`extensions`に入口のクラスを渡して有効にする。このレシピは導入(インストールと登録)だけを扱う。各拡張の使い方は、拡張に同梱のレシピ(下の表の「詳しいレシピ」)を読む。
コード例の名前空間`App\`は、自分のプロジェクトの名前空間(composer.jsonの`autoload.psr-4`で`app/`に割り当て、`tmswp-kernel.php`の`appNamespace`に書いたもの。スターターから作ったプロジェクトでは、setup.phpがスラッグから作った名前。例: `my-theme`なら`MyTheme\`)に読み替える。

| パッケージ | 機能 | 入口のクラス | 詳しいレシピ |
|---|---|---|---|
| `tmswp/acf-bridge` | acf-jsonからの型付きアクセサの生成と照合、メタの保存先をACFにする`AcfMetaStorage` | `TMSWP\AcfBridge\AcfBridge` | rules/acf.md(coreのレシピ)、`vendor/tmswp/acf-bridge/rules/sync-check.md` |
| `tmswp/settings-page` | 管理画面の設定ページ(coreのFormとオプショングループを使う) | `TMSWP\SettingsPage\SettingsPages` | `vendor/tmswp/settings-page/rules/settings-page.md` |
| `tmswp/thumbnail` | 署名付きのURLで作るサムネイル(`tms_thumb()`)。`ext-gd`が要る | `TMSWP\Thumbnail\Thumbnail` | `vendor/tmswp/thumbnail/rules/thumbnail.md` |
| `tmswp/ja-toolkit` | 日本語サイト向けのモジュール(日本語スラッグ・全角スペースの検索の正規化・wptexturizeの無効化)とフリガナの項目 | `TMSWP\JaToolkit\JaToolkit` | `vendor/tmswp/ja-toolkit/rules/ja-toolkit.md` |
| `tmswp/testing` | ユニットテストの基底クラスと、メタ・オプションの保存先のメモリの実装 | なし(Kernelに登録しない) | `vendor/tmswp/testing/rules/testing.md` |

## 手順

1. 使う拡張だけを`composer require tmswp/<パッケージ>`で入れる。`tmswp/testing`は開発用の依存なので`composer require --dev tmswp/testing`にする。
2. テーマ・プラグインのルートの`tmswp-kernel.php`で、`KernelConfig::theme()`・`KernelConfig::plugin()`の`extensions`に、入口のクラスのインスタンスを渡す(下の「完全な実装例」)。`tmswp/testing`は渡さない。
3. `functions.php`(プラグインは本体ファイル)は変えない。`tmswp-kernel.php`の構成で`Kernel::boot()`するだけにする。
4. 拡張のレシピ(上の表)に従って、拡張を使うクラス(`#[AsSettingsPage]`のクラス等)を`app/`に置く。属性を付けたクラスを足したら、WordPressの環境で`wp tmswp discover`(スターターから作ったプロジェクトでは`composer discover`)を実行し、探索結果ファイル`tmswp-discovery.php`を作り直してコミットする。
5. `composer qa`を通す。

拡張は、`composer require`しただけでは何もしない。`extensions`に渡したKernelでだけ有効になる。Kernelは、bootの中で`extensions`の各インスタンスの`register()`を、渡した順に1回ずつ呼ぶ。`Kernel::boot()`の後に拡張を登録するAPIは無い。

## 完全な実装例

テーマの`tmswp-kernel.php`(4つの拡張をすべて使う場合):

```php
<?php

declare(strict_types=1);

use TMSWP\AcfBridge\AcfBridge;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\JaToolkit\JaToolkit;
use TMSWP\JaToolkit\Module\FullwidthSearchNormalizer;
use TMSWP\JaToolkit\Module\JapaneseSlug;
use TMSWP\SettingsPage\SettingsPages;
use TMSWP\Thumbnail\Thumbnail;
use TMSWP\Thumbnail\ThumbnailConfig;

// functions.phpのbootと、WordPressを起動しないtms-acf(tmswp/acf-bridge)が読む。
// このファイルと、拡張・設定のクラスのコンストラクタでは、WordPressの関数を呼ばない
return KernelConfig::theme(
    appDir: __DIR__ . '/app',
    appNamespace: 'App',
    extensions: [
        new AcfBridge(),
        new SettingsPages(),
        new Thumbnail(new ThumbnailConfig(defaultQuality: 80)),
        new JaToolkit(new JapaneseSlug(), new FullwidthSearchNormalizer()),
    ],
);
```

- 引数の無い`new AcfBridge()`・`new SettingsPages()`・`new Thumbnail()`・`new JaToolkit()`は、それぞれ既定の設定で動く。設定の引数(`BridgeConfig`・`ThumbnailConfig`の各値、ja-toolkitのモジュール、`SettingsPages`に渡すクラス)の意味は、各拡張のレシピにある。
- ja-toolkitは、コンストラクタに渡したモジュールだけを有効にする。モジュールを渡さない`new JaToolkit()`は、フリガナの項目(`KanaTextField`)の訳だけを読み込む。

テーマの`functions.php`(拡張を使っても変わらない):

```php
<?php

declare(strict_types=1);

use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;

require_once __DIR__ . '/vendor/autoload.php';

Kernel::boot(KernelConfig::load(__DIR__ . '/tmswp-kernel.php'));
```

テーマとプラグインの両方で拡張を使うときは、それぞれの`tmswp-kernel.php`で別のインスタンスを`new`して渡す。拡張はKernelごとに別々に動く。ただしthumbnailは、どちらか1つのKernelにだけ登録する(下の「登録の制約」)。

プラグインの`tmswp-kernel.php`:

```php
<?php

declare(strict_types=1);

use TMSWP\AcfBridge\AcfBridge;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\JaToolkit\JaToolkit;

// 本体ファイルのパスはこのファイルの位置から決める。
// thumbnailはテーマのKernelに登録済みなので、ここには渡さない
return KernelConfig::plugin(
    file: __DIR__ . '/my-plugin.php',
    appDir: __DIR__ . '/app',
    appNamespace: 'MyPlugin',
    extensions: [new AcfBridge(), new JaToolkit()],
);
```

## 拡張ごとの導入の要点

### tmswp/acf-bridge

- 登録に加えて、composer.jsonの`scripts`に`"acf:sync": "tms-acf sync"`と`"acf:check": "tms-acf check"`を足す(スターターには含まれない)。
- フィールドグループの作り方・生成アクセサの使い方・`MetaBag`との使い分けはrules/acf.md、sync・checkの報告の対処と`BridgeConfig`による生成の調整は`vendor/tmswp/acf-bridge/rules/sync-check.md`を読む。

### tmswp/settings-page

- `app/`に置いた`#[AsSettingsPage]`のクラスは、`SettingsPages`を登録したKernelが探索で見つける。設定ページの作り方は`vendor/tmswp/settings-page/rules/settings-page.md`を読む。
- 設定ページの値は、オプショングループ(rules/options.md)に保存する。

### tmswp/thumbnail

- REST APIのルート`tmswp/v1/thumb`を、ログインしていない訪問者が使えることを前提にする。uploadsはローカルのファイルシステムに置く。
- `tms_thumb()`の使い方とキャッシュの削除は`vendor/tmswp/thumbnail/rules/thumbnail.md`を読む。

### tmswp/ja-toolkit

- 必要なモジュールだけを`JaToolkit`のコンストラクタに渡す。各モジュールの効果とフリガナの項目は`vendor/tmswp/ja-toolkit/rules/ja-toolkit.md`を読む。

### tmswp/testing

- `tmswp-kernel.php`には登録しない。テストの中でKernelをbootするときだけ、`TMSWP\Testing\StorageExtension`をそのテストの`KernelConfig`の`extensions`に渡す。
- PHPUnitの設定・テストの基底クラス・Brain Monkeyの使い方は`vendor/tmswp/testing/rules/testing.md`、メタ・オプションのテストはrules/meta.md・rules/options.mdの「テスト」を読む。

## 登録の制約

次はどれも設定の誤りとして、debugに関係なく例外で起動を止める。

- 同じクラスの拡張を同じKernelに2つ渡すと、`KernelConfig`が`KernelException`を投げる。
- 1つのインスタンスは、1つのKernelにだけ登録できる。同じインスタンスを2つのKernelに渡すと、2つ目のKernelでの`register()`が例外を投げる。
- thumbnailを登録できるKernelは、サイトに1つだけである。2つ目のKernelの`register()`は`LogicException`になる。`tms_thumb()`・RESTのルート・`wp tmswp thumb`は、thumbnailを登録したKernelのdebug・ロガーと、そのKernelに渡した`ThumbnailConfig`に従う。
- 拡張のdebugの判定は、登録したKernelの`KernelConfig`の`debug`に従う(`WP_DEBUG`を直接見ない)。

## してはいけないこと

- 拡張が提供する機能を、vendor配下からコピーしたり、自作したりしない。
- 拡張を`tmswp-kernel.php`の`extensions`以外の場所(`functions.php`の`Kernel::boot()`の後、フックの中等)で登録しようとしない。
- 1つのインスタンスを複数のKernelに渡さない。thumbnailを2つ以上のKernelに登録しない。
- `tmswp-kernel.php`と、拡張・設定のクラスのコンストラクタで、WordPressの関数(`__()`・`get_option()`等)を呼ばない。`tmswp-kernel.php`は、WordPressを起動しない`tms-acf`(acf-bridge)も読む。
- `tmswp/testing`を`require`(本番の依存)に入れたり、`tmswp-kernel.php`に登録したりしない。
- ja-toolkitのモジュールを、要件を確かめずにすべて有効にしない。
- ACFの値を`get_field()`で直接読まない(rules/acf.md)。

## 確認方法

1. `composer require`の後に`composer qa`が通ること。
2. 登録した拡張の機能が、WordPressで動くこと(例: settings-pageなら管理画面のメニューに設定ページが出る、thumbnailなら`tms_thumb()`のURLで画像が表示される)。手順は各拡張のレシピの「確認方法」にある。
3. テーマとプラグインの両方に登録した場合は、どちらのKernelでも拡張が動き、起動時に例外が出ないこと。

## 発展

- 各拡張の使い方: 上の表の「詳しいレシピ」
- Kernelごとの拡張の登録の仕様: docs/02-kernel.md §3.10、各拡張の設計: docs/08-extensions.md(settings-page・thumbnail・ja-toolkit)、docs/09-acf-bridge.md
