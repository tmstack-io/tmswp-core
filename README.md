# tmswp/core

TMStack WordPress Framework(TMSWP)のコアパッケージです。テーマ・プラグインの`app/`にAttributeを付けたクラスを置くと、Kernelがそれを探索し、投稿タイプ・タクソノミー・ユーザーロール・メタ・オプション・フック・独自テーブル(Model)・独自ブロックをWordPressに登録します。

- 探索の結果は、テーマ・プラグインのルートの探索結果ファイル(`tmswp-discovery.php`)に書き、コードと一緒にコミットします。本番(debugでない環境)はこのファイルだけを読み、`app/`を走査しません。
- WordPress標準のテンプレート階層をそのまま使います。テンプレートで使う値は、テンプレートの先頭で呼ぶ前処理クラスが用意します。
- フォーム(確認画面・CSRF・二重送信の防止・ファイルの受け取り)、テンプレートパーツ、ログ、型付きのクエリ(`PostQuery`・`TermQuery`・`UserQuery`)、独自テーブルのクエリビルダとマイグレーションを持ちます。
- runtimeの依存は`psr/container`と`psr/log`だけです。

## 要件

- PHP 8.3〜8.5
- WordPress 7.0以上。クラシックテーマ(PHPのテンプレート階層)とプラグインが対象で、ブロックテーマ(フルサイト編集)とマルチサイトは対象外です。
- 任意: `ext-mbstring`(`TMSWP\Core\Support\Str`のマルチバイト文字列の処理)、`ext-intl`(フォームの規則`Url`の国際化ドメイン名の検証)

## 導入

### スターターから作る

テーマ・プラグインは、スターターから作るのが基本です。

```bash
# テーマ(wp-content/themes/で実行する)
composer create-project tmswp/starter-theme my-theme

# プラグイン(wp-content/plugins/で実行する)
composer create-project tmswp/starter-plugin my-plugin
```

作成の直後に、スターターの`bin/setup.php`がスラッグと表示名を尋ね、スラッグから作った名前空間(例: `my-theme`なら`MyTheme\`)でプロジェクトを書き換えます。端末の無い環境では、環境変数`TMSWP_SETUP_SLUG`・`TMSWP_SETUP_NAME`で渡します。作ったプロジェクトには、`tmswp-kernel.php`・`app/`・開発の規約の`AGENTS.md`・`composer qa`等のscriptsがそろっています。

### 既存のテーマ・プラグインに足す

1. `composer require tmswp/core`を実行します。
2. composer.jsonの`autoload.psr-4`で、自分の名前空間を`app/`に割り当てます(例: `"MyTheme\\": "app/"`)。
3. テーマ・プラグインのルートに、`KernelConfig`を返す`tmswp-kernel.php`を置きます(下の「tmswp-kernel.phpの最小の例」)。
4. テーマは`functions.php`、プラグインは本体ファイルで、Composerのオートローダを読み込み、`tmswp-kernel.php`の構成でKernelを起動します。
5. WordPressの環境で`wp tmswp discover`を実行し、探索結果ファイル`tmswp-discovery.php`を作ってコミットします。

## tmswp-kernel.phpの最小の例

テーマの`tmswp-kernel.php`:

```php
<?php

declare(strict_types=1);

use TMSWP\Core\Kernel\KernelConfig;

// functions.phpのbootと、WordPressを起動しないtms-acf(tmswp/acf-bridge)が読む。WordPressの関数はここで呼ばない
return KernelConfig::theme(
    appDir: __DIR__ . '/app',
    appNamespace: 'MyTheme',
);
```

テーマの`functions.php`:

```php
<?php

declare(strict_types=1);

use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;

require_once __DIR__ . '/vendor/autoload.php';

Kernel::boot(KernelConfig::load(__DIR__ . '/tmswp-kernel.php'));
```

プラグインは`KernelConfig::plugin(file: __DIR__ . '/my-plugin.php', appDir: __DIR__ . '/app', appNamespace: 'MyPlugin')`を返し、本体ファイル(`my-plugin.php`)で同じように`Kernel::boot()`を呼びます。

`KernelConfig::theme()`・`plugin()`は、ほかに次の引数を受け取ります。

| 引数 | 内容 |
|---|---|
| `debug` | 省略すると`WP_DEBUG`の値。debugでは起動のたびに`app/`を走査し、実行時のデータの取得の失敗を例外にする。debugでないときは探索結果ファイルだけを読み、失敗をログに記録して既定値を返す |
| `extensions` | このKernelで有効にする拡張(`tmswp/acf-bridge`等)のインスタンスのlist。同じクラスは1つまで |
| `logChannels` | ログのチャネル(チャネル名 => `LogChannel`)を返すクロージャ |
| `labelLanguage` | `labelLanguage()`を指定しない投稿タイプ・タクソノミーのラベルの言語(`LabelLanguage::En`が既定。スターターは`Ja`) |
| `blockCategories` | このKernelが足すブロックカテゴリ(`BlockCategory`のlist) |

テーマとプラグインの両方でTMSWPを使えます。Kernelは`appNamespace`ごとに1つで、Containerや拡張はKernelごとに別々に動きます。

## レシピと設計文書

機能ごとの手順(レシピ)は、このパッケージの`rules/`にあります。利用側のプロジェクトからは`vendor/tmswp/core/rules/*.md`で読めます。1つのタスクにつき、該当するレシピ1本を読めば実装できるように書いています。

| レシピ | 対象のタスク |
|---|---|
| `rules/post-type.md` | カスタム投稿タイプの追加、既存の投稿タイプのアダプタ、投稿のクエリ |
| `rules/taxonomy.md` | タクソノミーの追加、既存のタクソノミーのアダプタ、タームのクエリ |
| `rules/user-role.md` | ユーザーロールの追加、capの追加、ログイン・会員登録 |
| `rules/meta.md` | ACFを使わないカスタムフィールド(メタ)の宣言と読み書き |
| `rules/options.md` | サイトの設定値(オプショングループ) |
| `rules/acf.md` | ACFのフィールドの管理(tmswp/acf-bridgeの導入と生成アクセサ) |
| `rules/form.md` | フォーム(入力・確認・完了の3画面) |
| `rules/form-mail.md` | フォームの完了処理のメール送信 |
| `rules/page.md` | テンプレートの前処理クラス |
| `rules/view.md` | テンプレートパーツ |
| `rules/hooks.md` | WordPressのフックの置き場 |
| `rules/model.md` | 独自テーブル(Model)とマイグレーション |
| `rules/blocks.md` | 独自ブロック |
| `rules/extensions.md` | 拡張パッケージの導入 |
| `rules/rest.md` | REST APIのルート(WordPress標準の`register_rest_route()`) |
| `rules/deploy.md` | 本番へのデプロイ(FTP) |

各APIの設計と仕様は、TMSWPのmonorepo([tmstack-io/tmswp](https://github.com/tmstack-io/tmswp))の[docs/](https://github.com/tmstack-io/tmswp/tree/v0.9.1/docs/)にあります(Kernel・探索は[docs/02-kernel.md](https://github.com/tmstack-io/tmswp/blob/v0.9.1/docs/02-kernel.md)、エンティティは[docs/03-entities.md](https://github.com/tmstack-io/tmswp/blob/v0.9.1/docs/03-entities.md)、メタ・オプションは[docs/04-meta-options.md](https://github.com/tmstack-io/tmswp/blob/v0.9.1/docs/04-meta-options.md)、フォームは[docs/05-form.md](https://github.com/tmstack-io/tmswp/blob/v0.9.1/docs/05-form.md)、Modelは[docs/06-model.md](https://github.com/tmstack-io/tmswp/blob/v0.9.1/docs/06-model.md)、テンプレート・ログ等は[docs/07-support.md](https://github.com/tmstack-io/tmswp/blob/v0.9.1/docs/07-support.md))。

## WP-CLIコマンド

| コマンド | 内容 |
|---|---|
| `wp tmswp discover` | 起動しているすべてのKernelの`app/`と`build/blocks/`を走査し、探索結果ファイル(`tmswp-discovery.php`)を書く |
| `wp tmswp discover --check` | 探索結果ファイルが最新かを照合する。無い・古いファイルがあれば非0で終わる(CIで使う) |
| `wp tmswp discover --kernels=<count>` | 起動しているKernelの数を確かめ、違えば走査せずに非0で終わる。`--check`と併用できる |
| `wp tmswp migrate` | `#[AsModel]`のテーブルの定義をデータベースに適用する(dbDelta)。定義が変わっていないテーブルは飛ばす |
| `wp tmswp migrate --dry-run` | 適用せずに、定義とデータベースが食い違うテーブルを出す |
| `wp tmswp migrate --force` | 定義が変わっていないテーブルにも適用する |

テーブルは、テーマ・プラグインの有効化や管理画面の表示では作られません。デプロイの後に`wp tmswp migrate`を実行します(`rules/model.md`・`rules/deploy.md`)。

## PHPStanの拡張

このパッケージは、PHPStanの拡張(`phpstan/extension.neon`)を同梱します。`phpstan/extension-installer`を入れていれば自動で読み込まれます。

- `MetaBag`・オプショングループの型付きのgetterと、Modelの`find()`・`first()`・`get()`の戻り値の型を、宣言から絞ります。
- 宣言済みのメタキーを文字列で渡した呼び出し(`tmswp.metaKeyLiteral`)と、ほかのエンティティのメタキーの定数を渡した呼び出し(`tmswp.metaKeyOwnership`)を報告します。
- `Kernel::reset()`をPHPUnitのテストの外で呼ぶコードを報告します(`tmswp.kernelReset`)。

## 利用条件

### 同じサイトでは同じ版の tmswp/core を使う

テーマとプラグインがそれぞれ `vendor/` に tmswp/core を同梱する場合、PHP は先に読み込まれた側のクラスだけを使います。そのため、同じサイトのテーマ・プラグインは同じ版の tmswp/core を同梱してください。

`Kernel::boot()` は、読み込まれたすべての Composer の vendor の `installed.php` にある tmswp/core の版を `Kernel::VERSION` と照合し、食い違えば `KernelException` で止まります。メッセージには、食い違う版と、その vendor の install path が含まれます。dev 版(`dev-*` のブランチや path repository 等)は照合の対象外です。

### 古い psr/container・psr/log を先に読み込む構成では動かない

tmswp/core は psr/container ^2.0 と psr/log ^3.0 の宣言に合わせて `Container`・`LoggerRegistry` 等を実装しています。PHP の interface は最初に読み込んだ定義で固定されるため、他のプラグインや WP-CLI の phar が古い psr/container(1.x)・psr/log(1.x・2.x)を TMSWP より先に読み込む構成では動きません。

`Kernel::boot()` はこれを起動時に検出し、Fatal error の代わりに次のような `KernelException` で止まります。メッセージには、その interface を定義したファイルのパスと、必要な版が含まれます。

```
TMSWP requires psr/container ^2.0, but Psr\Container\ContainerInterface loaded from phar:///usr/local/bin/wp/vendor/psr/container/src/ContainerInterface.php is incompatible (...)
```

検出されたときの対処:

- ファイルのパスが `phar://` で始まる場合は、WP-CLI の phar が同梱する psr/container 1.0 が先に読み込まれています。TMSWP の Composer のオートローダを、WP-CLI の `--require` や `wp-cli.yml` の `require` で早く登録せず、テーマの functions.php・プラグインの本体ファイルの読み込み時に登録してください(WP-CLI の phar は `--require` の後に自身のオートローダを先頭に登録します)。
- 他のプラグインのファイルの場合は、そのプラグインを更新するか、TMSWP を使うテーマ・プラグインと併用しないでください。

### WordPress 7.0 未満では起動しない

`Kernel::boot()` は、WordPress の版が 7.0 未満なら `KernelException` で止まります。

## 開発

このパッケージは[tmstack-io/tmswp](https://github.com/tmstack-io/tmswp)のmonorepoで開発しています。

## ライセンス

GPL-2.0-or-later(`LICENSE`)
