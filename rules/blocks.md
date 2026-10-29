# 独自ブロックを追加する

React(`@wordpress/scripts`)で作る独自ブロックは、ビルドの出力を置くだけでKernelが登録する。`register_block_type()`は直接呼ばない。
コード例の名前空間`App\`は、自分のプロジェクトの名前空間(composer.jsonの`autoload.psr-4`で`app/`に割り当て、`tmswp-kernel.php`の`appNamespace`に書いたもの。スターターから作ったプロジェクトでは、setup.phpがスラッグから作った名前。例: `my-theme`なら`MyTheme\`)に読み替える。

## 対象外

- ブロックテーマ(フルサイト編集)には対応しない。対象はクラシックテーマ(PHPのテンプレート階層)と、そのテーマ・プラグインに置く独自ブロックである。
- ACF Blocks向けのコード生成は作らない。acf-bridgeもACF Blocksのコードを生成しない。ブロックはblock.jsonとReactで作る。

## 置き場

1ブロックにつき1ディレクトリ。ディレクトリは入れ子にしてよい。

| 場所 | 中身 | 探索 |
|---|---|---|
| `blocks/<slug>/` | ソース(`block.json`・`index.js`・`render.php`等) | しない |
| `build/blocks/<slug>/` | `@wordpress/scripts`のビルドの出力 | する |

- 探索コマンド(`wp tmswp discover`)は、テーマ・プラグインのルートの`build/blocks/`以下(入れ子を含む)の`block.json`だけを走査し、探索結果ファイル(`tmswp-discovery.php`)に書く。ソースの`blocks/`は走査しない。
- debugでは、bootのたびに`build/blocks/`を走査する。本番は探索結果ファイルだけを読む。
- `build/blocks/`は、探索結果ファイルと同じくコミットしてデプロイする。本番とCIの照合は、ビルドをせずにこの出力を使う。

## 手順

1. `blocks/<slug>/block.json`・`index.js`・`render.php`を作る。
2. ブロックカテゴリを新しく作るときは、`tmswp-kernel.php`の`blockCategories`に登録する。
3. `npm run build`でビルドし、続けて探索コマンドで探索結果ファイルを作り直す(下の「ビルドと探索コマンドの連結」)。
4. `build/blocks/`と`tmswp-discovery.php`をコミットする。
5. `composer qa`を通す。

## 完全な実装例

`blocks/card/block.json`:

```json
{
  "$schema": "https://schemas.wp.org/trunk/block.json",
  "apiVersion": 3,
  "name": "my-theme/card",
  "title": "Card",
  "category": "my-theme",
  "attributes": {
    "title": {
      "type": "string",
      "default": ""
    }
  },
  "editorScript": "file:./index.js",
  "render": "file:./render.php"
}
```

- `name`は`namespace/name`の形(英小文字・数字・`-`)にする。テーマとすべてのプラグインを通して一意にする。
- `category`は、WordPressの既定のカテゴリ(`text`・`media`・`design`・`widgets`・`theme`・`embed`・`reusable`)か、同じテーマ・プラグインの`tmswp-kernel.php`の`blockCategories`のスラッグにする。省略してもよい。
- 動的描画は`"render": "file:./render.php"`で指定する。`render.php`・`index.js`等のファイルの読み込みはWordPressに任せる。`@wordpress/scripts`は、`render`に書いたPHPのファイルを`build/blocks/`にコピーする。

`blocks/card/render.php`(WordPressが`$attributes`・`$content`・`$block`を渡して読み込む):

```php
<?php

declare(strict_types=1);

/** @var array<string, mixed> $attributes */
$title = is_string($attributes['title'] ?? null) ? $attributes['title'] : '';
?>
<div <?php echo get_block_wrapper_attributes(); ?>>
    <h3><?php echo esc_html($title); ?></h3>
</div>
```

`blocks/card/index.js`:

```js
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import metadata from './block.json';

registerBlockType(metadata.name, {
    edit: () => <div {...useBlockProps()}>Card</div>,
});
```

テーマの`tmswp-kernel.php`(ブロックカテゴリの登録):

```php
<?php

declare(strict_types=1);

use TMSWP\Core\Block\BlockCategory;
use TMSWP\Core\Kernel\KernelConfig;

return KernelConfig::theme(
    __DIR__ . '/app',
    'App',
    blockCategories: [
        new BlockCategory('my-theme', 'My Theme', 'star-filled'),
    ],
);
```

- `BlockCategory`は、スラッグ・見出し・Dashiconsの名前(`dashicons-`を除く。省略できる)を受け取る。カテゴリは、ブロックの挿入画面でWordPressの既定のカテゴリの後に並ぶ。スラッグが英小文字・数字・`-`でない場合と、見出しが空の場合は`KernelException`になる。
- 見出しに`__()`は使えない(`tmswp-kernel.php`はWordPressなしでも読まれる)。
- 既定のカテゴリと同じスラッグ、同じ`tmswp-kernel.php`の中で重複したスラッグは、`KernelException`になる。

## ビルドと探索コマンドの連結

ビルドの後に探索結果ファイルを作り直さないと、照合(`wp tmswp discover --check`。`composer qa`とCI)が失敗する。`package.json`のスクリプトで、ビルドと探索コマンドを連結する。

```json
{
  "scripts": {
    "start": "wp-scripts start --source-path=blocks --output-path=build/blocks",
    "build": "wp-scripts build --source-path=blocks --output-path=build/blocks && npm run discover",
    "discover": "wp tmswp discover"
  },
  "devDependencies": {
    "@wordpress/scripts": "<固定した版>"
  }
}
```

- `--source-path=blocks`で`blocks/`以下の`block.json`を探し、`--output-path=build/blocks`に出力する。
- `discover`は、WordPressのサイトでWP-CLIを動かせるコマンドにする。wp-envを使う場合は、ローカルに入れた`@wordpress/env`をパスで呼ぶ(`node node_modules/@wordpress/env/bin/wp-env run cli wp tmswp discover`)。`npx wp-env`のように、コマンド名で第三者のパッケージを解決する呼び出しは使わない。
- 開発環境(debug)ではbootのたびに`build/blocks/`を走査するため、`npm run start`の監視中は探索結果ファイルを作り直さなくてもブロックが登録される。コミットの前に`npm run build`を実行する。
- `wp tmswp discover`は、探索結果ファイルに`block.json`の内容のハッシュを書く。`block.json`だけを変えた場合も、作り直さなければ照合が失敗する。

## 投稿タイプごとに使えるブロックを限る

`PostTypeDefinition`の`allowedBlocks()`に、その投稿タイプの編集画面で使えるブロックの名前を渡す。`allowed_block_types_all`でこの投稿タイプにだけ反映され、ほかの投稿タイプ(別のテーマ・プラグインのKernelが登録したものを含む)には影響しない。指定しない投稿タイプは、WordPressの既定のまま(すべてのブロックを使える)になる。

ほかのプラグインが`allowed_block_types_all`で先に絞っている場合は、その制限を広げない。先のフィルタが`false`(ブロックを使わせない)を返していれば`false`のまま、配列を返していれば`allowedBlocks()`の指定との共通部分だけになる。

`app/Post/EventPost.php`:

```php
<?php

declare(strict_types=1);

namespace App\Post;

use TMSWP\Core\Attribute\AsPostType;
use TMSWP\Core\Label\LabelLanguage;
use TMSWP\Core\Post\PostType;
use TMSWP\Core\Post\PostTypeDefinition;

#[AsPostType]
final class EventPost extends PostType
{
    public static function definition(): PostTypeDefinition
    {
        return PostTypeDefinition::create('event')
            ->label('イベント')
            ->labelLanguage(LabelLanguage::Ja)
            ->showInRest()
            ->supports('title', 'editor')
            ->allowedBlocks('core/paragraph', 'core/heading', 'core/image', 'my-theme/card');
    }
}
```

- ブロック名を1つも渡さない呼び出しと、`namespace/name`の形でないブロック名は`EntityDefinitionException`になる。ブロックエディタを使わない投稿タイプは`blockEditor(false)`にする。
- `builtin()`の定義(`post`・`page`等)では呼べない(`EntityDefinitionException`)。
- ブロックエディタで編集するため、`showInRest()`と`supports('editor')`も指定する。

## ブロック専用のフック

ブロックの描画を変える等のフックは、ほかのフックと同じく`#[AsHook]`のクラスに書く(rules/hooks.md)。

`app/Hook/CardBlockHook.php`:

```php
<?php

declare(strict_types=1);

namespace App\Hook;

use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Attribute\Filter;
use TMSWP\Core\Hook\Hook;

#[AsHook]
final class CardBlockHook extends Hook
{
    /** カードのブロックの出力を、ほかのブロックと区別できる要素で包む */
    #[Filter('render_block_my-theme/card')]
    public function wrap(string $content): string
    {
        return '<div class="card-block">' . $content . '</div>';
    }
}
```

## 起きるエラー

| いつ | 内容 | 例外 |
|---|---|---|
| 探索(`wp tmswp discover`・debugのboot) | 同じテーマ・プラグインの中のブロック名の重複(両方の`block.json`を示す) | `DiscoveryException` |
| 探索 | 未登録のカテゴリ、壊れたJSON、`name`の形の誤り | `DiscoveryException` |
| 登録(`init`) | テーマとプラグインをまたぐブロック名の重複、コア・ほかのプラグインのブロックとの重複(両方の出どころを示す) | `KernelException` |
| 登録(`init`) | 探索結果ファイルの`name`と`block.json`の`name`の食い違い(作り直し忘れ) | `KernelException` |
| エディタの表示 | ほかのテーマ・プラグインが同じスラッグのカテゴリを足している | `KernelException` |

いずれも設定の誤りなので、debugに関係なく例外になる。テーマとプラグインをまたぐ重複は、探索コマンドがKernelごとに走査するため、探索では検出できない。

## してはいけないこと

- `register_block_type()`や`register_block_type_from_metadata()`を自分で呼ばない。
- `block_categories_all`のフィルタを自分で書かない。カテゴリは`blockCategories`で登録する。
- `build/blocks/`のファイルを手で編集しない。ソースを直してビルドする。
- ソースの`blocks/`にだけブロックを置いたまま、ビルドせずにコミットしない(登録されない)。
- ほかのテーマ・プラグインのカテゴリを`category`に書かない(探索の時点で有無を確かめられないため、未登録のカテゴリとしてエラーになる)。

## 確認方法

1. `npm run build`の後、`wp tmswp discover --check`が成功すること(`composer qa`に含まれる)。
2. ブロックエディタの挿入画面に、ブロックとカテゴリが表示されること。
3. 本番で表示されない場合は、`build/blocks/`がデプロイされているかと、`wp tmswp discover --check`で探索結果ファイルが最新かを確かめる。

## 発展

- ブロックを使う投稿タイプ: rules/post-type.md
- ブロックの描画を変えるフック: rules/hooks.md
- ブロックテーマ・ACF Blocksは対象外(上の「対象外」)
- 探索・ブロックの登録の仕様: TMSWPの設計文書 docs/02-kernel.md §4・§7
