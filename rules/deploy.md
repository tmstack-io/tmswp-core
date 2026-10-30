# 本番へデプロイする

TMSWPを使うテーマ・プラグインを、FTPで本番のサーバーへ上げる手順である。本番のWordPressは`WP_DEBUG`を無効にし、サーバーでWP-CLIを使える前提にする。
このレシピにコードの例は無い。`composer`・`npm`のコマンドは、開発環境のテーマ・プラグインのルート(composer.jsonのあるディレクトリ)で実行する。`wp`のコマンドは、注記の無いものは本番のサーバーで実行する。

## 手順

1. 開発環境で`composer qa`を通す。`composer qa`は、探索結果ファイル`tmswp-discovery.php`が最新かの照合(`composer discover:check`)を含む。照合が失敗したら、`composer discover`で作り直してコミットする。ブロックを変えたときは先に`npm run build`を、tmswp/acf-bridgeを使うときは`composer acf:check -- --strict`も実行する。
2. 同じサイトでTMSWPを使うテーマ・プラグインの`tmswp/*`の版がそろっていることを確かめる(下の「版をそろえる」)。
3. 本番用の`vendor/`を`composer install --no-dev --optimize-autoloader`で作る(下の「本番用のvendor/」)。
4. 本番のバックアップを取る。`wp db export`でDBを書き出し、本番のテーマ・プラグインのディレクトリをFTPで手元に控える(下の「バックアップと元に戻す手順」)。
5. 本番で`wp maintenance-mode activate`を実行し、保守モードにする。ブラウザでトップページが503(保守の画面)になることを確かめてから上げる。503にならなければ、OPcacheを空にしてから確かめ直す(下の「保守モード」「OPcache」)。
6. 下の「上げるもの・上げないもの」のとおりに、FTPで上げる。`tmswp/*`の版を変えるときは、TMSWPを使うテーマ・プラグインのすべてを、この保守モードの間に上げる。
7. OPcacheがファイルの更新を確かめない設定のサーバーでは、OPcacheを空にする(下の「OPcache」)。
8. `wp tmswp discover --check`を実行し、上げた探索結果ファイルが、上げた`app/`・`build/blocks/`と合っていることを確かめる。失敗したら、探索結果ファイルと`app/`・`build/blocks/`を上げ直し、成功するまで次へ進まない。
9. Model(独自テーブル)を使うときは、`wp tmswp migrate --dry-run`で変わるテーブルを確かめてから、`wp tmswp migrate`を実行する。失敗したときの直し方はrules/model.mdにある。
10. `wp maintenance-mode deactivate`で保守モードを解く。
11. ページキャッシュのプラグインを使うサイトでは、ページキャッシュを消す(下の「ページキャッシュ」)。
12. 開発環境で`composer install`を実行し、開発の依存(PHPUnit・PHPStan等)を`vendor/`に戻す。

## 版をそろえる

- 同じサイトでTMSWPを使うテーマ・プラグインは、すべて同じ版の`tmswp/core`を`vendor/`に入れる。PHPのクラスは先に読み込まれた1組だけが使われるため、Kernelは起動のたびに、読み込まれたすべての`vendor/composer/installed.php`の`tmswp/core`の版を、動いている`tmswp/core`の版と比べる。1つでも違えば、`KernelException`(`tmswp/core versions on this site do not match the loaded core ...`)で止まる。
- 比べるのは版の全体(`0.10.0`等)である。composer.jsonの制約(`^0.10`)を両方が満たしていても、`0.10.0`と`0.10.1`では止まる。tmswp/core以外の`tmswp/*`(拡張)はtmswp/coreと同じ版で出るため、`composer update 'tmswp/*'`で一緒に上げる。
- Kernelが比べるのは`tmswp/core`の版だけで、拡張(tmswp/acf-bridge等)の版の食い違いは検出しない。拡張の版も、下の`composer show 'tmswp/*'`で確かめる。
- 止まると、サイトのすべてのページ(管理画面とRESTを含む)が500の致命的なエラーの画面になり、そのサイトでは`wp`のコマンドも同じ例外で止まる。直すには、版をそろえた`vendor/`を上げ直す。
- 開発環境の版は、テーマ・プラグインのそれぞれで`composer show 'tmswp/*'`を実行し、入っているすべての`tmswp/*`の版が同じであることを確かめる。スターターから作ったプロジェクトは`composer.lock`をコミットしない(`.gitignore`)ため、別のパソコンで`composer install`すると、制約の範囲の別の版が入ることがある。上げる前に毎回確かめる。
- 本番に入っている版は、各テーマ・プラグインの`vendor/composer/installed.php`の`'tmswp/core'`の`'pretty_version'`である。Kernelが比べるのもこの値である。
- 開発版(`dev-main`等のブランチ)とプレリリースの版は、比べる対象から外れる。本番にはリリースの版を入れる。

## 本番用のvendor/

- `vendor/`はgitに入れない(`.gitignore`)が、本番には上げる。テーマの`functions.php`とプラグインの本体ファイルが`vendor/autoload.php`を読むためである。
- `composer install --no-dev --optimize-autoloader`は、`require-dev`の依存(PHPUnit・PHPStan・php-cs-fixer・Brain Monkey等)を`vendor/`から外し、クラスの対応表を作る。`vendor/`に`phpunit/`・`phpstan/`が無いことを確かめてから上げる。
- 本番の`vendor/`は、古いものを消してから、新しい`vendor/`をすべて上げる。上書きだけでは、新しい版で無くなったファイルが残る。

## 上げるもの・上げないもの

下の「上げないもの」の表にあるもの以外は、すべて上げる。テーマの`theme.json`・`screenshot.png`や、テーマ・プラグインが自分で足したディレクトリも上げる。次の表は、その中で特に上げ忘れやすいものである。

特に上げ忘れやすいもの:

| ファイル | 理由 |
|---|---|
| テーマの`style.css`・`functions.php`・テンプレート(`index.php`・`header.php`等)・`parts/`・`assets/`、プラグインの本体ファイル(`<スラッグ>.php`)・`templates/` | テーマ・プラグインの本体 |
| `tmswp-kernel.php` | Kernelの構成。`functions.php`・本体ファイルが読む |
| `app/` | クラス。tmswp/acf-bridgeの生成物(`app/Acf/`)を含む |
| `vendor/`(`--no-dev`で作ったもの) | Composerのオートローダとtmswp/* |
| `tmswp-discovery.php` | 本番(debugが偽)は探索結果ファイルだけを読み、`app/`を走査しない。無いと起動時にエラーになる |
| `build/blocks/` | 独自ブロックは、この出力の`block.json`から登録される(rules/blocks.md) |
| `acf-json/`(tmswp/acf-bridgeを使うとき) | ACFがフィールドグループを読む(rules/acf.md) |
| `languages/` | 翻訳ファイル |

上げないもの:

| ファイル | 理由 |
|---|---|
| `node_modules/` | ブロックのビルドの道具 |
| `blocks/` | ブロックのソース。登録は`build/blocks/`から行う |
| `tests/` | テスト |
| `composer.json`・`composer.lock`・`package.json`・`package-lock.json`・`phpstan.neon`・`phpunit.xml.dist`・`.php-cs-fixer.dist.php` | 開発の設定ファイル。実行時に使わない |
| 名前が`.`で始まるファイル・ディレクトリ(`.git/`・`.github/`・`.gitignore`・`.gitattributes`・`.wp-env.json`・`.env`・`.DS_Store`、キャッシュの`.php-cs-fixer.cache`・`.phpunit.cache/`・`.phpunit.result.cache`等) | 開発の道具の設定・履歴・キャッシュで、実行時に使わない。`.env`等に秘密の値(APIの鍵・パスワード等)があると、Webから読めるようになる |
| `AGENTS.md`・`README.md` | 開発の文書 |

## バックアップと元に戻す手順

- 上げる前に、本番で`wp db export <ファイル名>.sql`を実行してDBを書き出す(`wp db export`はサーバーの`mysqldump`を使う。無いサーバーでは、サーバー会社の管理画面等で書き出す)。書き出したファイルは、Webから読める場所(WordPressのディレクトリの中)に置いたままにせず、手元に移して本番から消す。
- 本番のテーマ・プラグインのディレクトリ(`vendor/`と探索結果ファイルを含む)を、FTPで手元に控える。
- 元に戻すときは、保守モードにする(起動時のエラーで`wp`のコマンドが止まるときは、`wp --skip-plugins --skip-themes maintenance-mode activate`)。そのうえで、控えたテーマ・プラグインのディレクトリを上げ直す(`vendor/`は、手元に控えたものを、本番の`vendor/`を消してから上げる)。OPcacheがファイルの更新を確かめない設定のサーバーでは、OPcacheを空にする。
- `wp tmswp migrate`でテーブルを変えた後で、控えたコードが変えた後のテーブルで動かないときは、`wp db import <ファイル名>.sql`でDBを書き出した時点に戻す。書き出した後に保存されたデータ(問い合わせ・投稿等)は失われる。

## 保守モード

- `wp maintenance-mode activate`は、WordPressのルートに`.maintenance`を作る。保守モードの間、Webの要求には503の保守の画面が返り、テーマ・プラグインのPHPは実行されない。上げる途中の、版や探索結果ファイルが食い違った状態のファイルが実行されないようにするためである。
- 保守モードの間も`wp`のコマンドは動く。手順の7〜9は保守モードの間に行う。
- WordPressは、`.maintenance`を作ってから10分を過ぎると保守モードを解いたものとして扱う。上げるのに10分を超えるときは、途中で`wp maintenance-mode activate --force`を実行して作り直す。
- `.maintenance`は、WordPressが`require`で読むPHPのファイルのため、OPcacheの対象になる(下の「OPcache」)。ファイルの更新を確かめない設定のサーバーでは、作り直した`.maintenance`ではなく、前の保守モードのときの古い時刻が使われ、10分を過ぎたものとして保守モードが効かないことがある。そのため、保守モードにした後は、トップページが503になることを確かめる。
- 版の食い違いや探索結果ファイルの不足で起動時にエラーになると、`wp maintenance-mode deactivate`等の`wp`のコマンドも同じエラーで止まる。保守モードの操作は、テーマ・プラグインを読み込まない`wp --skip-plugins --skip-themes maintenance-mode deactivate`で行える。原因のファイルは上げ直して直す。

## OPcache

- Webサーバーで動くPHP(Apacheのモジュール、PHP-FPM等)は、読み込んだPHPのファイル(`.maintenance`を含む)をOPcacheに持つ。`opcache.validate_timestamps`が1(既定)なら、`opcache.revalidate_freq`の秒数(既定は2)ごとにファイルの更新を確かめるため、上げたファイルはその後に使われる。
- `opcache.validate_timestamps`が0のサーバーでは、OPcacheを空にするまで、上げる前のファイルが使われ続ける。新しいファイルと古いファイルが混ざって動くことがあり、保守モードも効かないことがある(上の「保守モード」)。保守モードにした後と、保守モードを解く前に、サーバー会社の管理画面でOPcacheを空にするか、PHPを再起動する。
- 設定は、Webサーバーで動くPHPの`phpinfo()`か、サーバー会社の管理画面で確かめる。`wp`のコマンドが使うPHP(CLI)の設定は、Webサーバーの設定と違うことがある。
- `wp eval 'opcache_reset();'`等、WP-CLIから空にしても、空になるのはCLIのPHPのOPcacheだけで、WebサーバーのOPcacheは空にならない。`wp tmswp discover`が書いたファイルのOPcacheを無効にするのも、同じくCLIのPHPの中だけである。

## ページキャッシュ

- ページキャッシュのプラグイン(WP Super Cache等)は、上げる前のコードで作ったHTMLを返し続ける。保守モードを解いた後に、プラグインの管理画面でキャッシュを消す。
- `wp cache flush`はオブジェクトキャッシュを消すコマンドで、ページキャッシュは消さない。

## してはいけないこと

- バックアップ(DBの書き出しと、テーマ・プラグインのディレクトリの控え)を取らずに`wp tmswp migrate`を実行しない。
- 開発の依存を入れたままの`vendor/`を上げない。
- gitの中身だけを上げない。`vendor/`はgitに入っていない。
- TMSWPを使うテーマ・プラグインの一方だけ、`tmswp/*`の版を変えて上げない。
- 保守モードにせずに、版を変える`vendor/`や探索結果ファイルを上げない。
- 本番で`wp tmswp discover`(`--check`なし)を実行して探索結果ファイルを作らない。探索結果ファイルは開発環境で作ってコミットしたものを上げる。本番で作ると、リポジトリのファイルと食い違う。
- 本番で`WP_DEBUG`を有効にしない。`tmswp-kernel.php`の`debug`に`true`を書かない(省略すると`WP_DEBUG`の値になる)。debugが真だと、起動のたびに`app/`と`build/blocks/`を走査し、実行時のデータの取得の失敗が例外になる。
- 開発版(`dev-main`等)の`tmswp/*`を本番に入れない。版を比べる対象から外れ、食い違いを検出できない。

## 確認方法

1. 保守モードを解いた後、本番のトップページと管理画面が、致命的なエラーの画面にならずに表示されること。
2. `wp tmswp discover --check`が成功すること。
3. Modelを使うときは、`wp tmswp migrate --dry-run`がすべてのテーブルを`up-to-date`(日本語のサイトでは`最新`)と出すこと。
4. 上げた`vendor/`に`phpunit/`・`phpstan/`等の開発の依存が無いこと。

## 発展

- マイグレーションの実行と失敗したときの直し方: rules/model.md
- ブロックのビルドと探索コマンドの連結: rules/blocks.md
- acf-jsonと生成物を同じコミットにする: rules/acf.md
- 版の照合と探索結果ファイルの仕様: TMSWPの設計文書[docs/01-architecture.md](https://github.com/tmstack-io/tmswp/blob/v0.10.0/docs/01-architecture.md)・[docs/02-kernel.md](https://github.com/tmstack-io/tmswp/blob/v0.10.0/docs/02-kernel.md)
