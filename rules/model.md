# 独自DBテーブルを作る

独自テーブルは`Model`と`TableDefinition`で宣言し、`wp tmswp migrate`で適用する。読み書きは`Model`の静的メソッドと`QueryBuilder`で行い、値はすべて`wpdb::prepare()`を通る。SQL文字列の組み立てと`$wpdb`の直書きは禁止。
例はテーマのもの。コード例の名前空間`App\`は、自分のプロジェクトの名前空間(composer.jsonの`autoload.psr-4`で`app/`に割り当て、`tmswp-kernel.php`の`appNamespace`に書いたもの。スターターから作ったプロジェクトでは、setup.phpがスラッグから作った名前。例: `my-theme`なら`MyTheme\`)に読み替える。

## 手順

1. `app/Model/<Name>Model.php`を作る。`#[AsModel]`を付けた`final class`にし、`Model`を継承する。
2. `table()`でスキーマを宣言する(下の「テーブル定義」)。
3. 行をDTOで受け取るなら、`rowClass()`を上書きして`@return class-string<Row>`を宣言する。上書きしなければ行は`stdClass`。
4. `wp tmswp migrate`を実行してテーブルを作る。本番では、ファイルを上げた後に実行する(下の「本番での実行」)。
5. `composer qa`を通す。

## 完全な実装例

商品カタログのテーブル。行は`CatalogItemRow`で受け取る。

`app/Model/CatalogStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Model;

enum CatalogStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
```

`app/Model/CatalogItemRow.php`:

```php
<?php

declare(strict_types=1);

namespace App\Model;

use DateTimeImmutable;

/** catalog_itemsの1行。引数名(またはそのsnake_case)の列の値を、宣言した型にして受け取る */
final class CatalogItemRow
{
    /**
     * @param array<string, mixed> $spec json()の列。JSONをデコードした配列
     */
    public function __construct(
        public readonly int $id,
        public readonly string $sku,
        public readonly string $name,
        public readonly CatalogStatus $status,
        public readonly int $price,
        public readonly array $spec,
        public readonly ?DateTimeImmutable $publishedAt,
        public readonly DateTimeImmutable $createdAt,
    ) {}
}
```

`app/Model/CatalogItemModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Model;

use TMSWP\Core\Attribute\AsModel;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;

#[AsModel]
final class CatalogItemModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('catalog_items')
            ->id()
            ->string('sku', 40)
            ->string('name')
            ->string('status', 20, default: 'draft')
            ->int('price', unsigned: true)
            ->json('spec', nullable: false)
            ->datetime('published_at', nullable: true)
            ->datetime('created_at', default: 'CURRENT_TIMESTAMP')
            ->unique('sku', 'sku_unique')
            ->index(['status', 'published_at'], 'status_published');
    }

    /** @return class-string<CatalogItemRow> */
    public static function rowClass(): string
    {
        return CatalogItemRow::class;
    }

    public static function findBySku(string $sku): ?CatalogItemRow
    {
        return self::query()->where('sku', $sku)->first();
    }

    /** @return list<CatalogItemRow> */
    public static function latestPublished(int $limit): array
    {
        return self::query()
            ->where('status', CatalogStatus::Published)
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }
}
```

使い方:

```php
<?php

declare(strict_types=1);

use App\Model\CatalogItemModel;
use App\Model\CatalogStatus;

$id = CatalogItemModel::insert([
    'sku' => 'A-100',
    'name' => '温度計',
    'status' => CatalogStatus::Published,                          // enumは値('published')で保存する
    'price' => 1200,
    'spec' => json_encode(['range' => '-20..50'], JSON_THROW_ON_ERROR),
    'published_at' => new DateTimeImmutable('now', wp_timezone()), // 'Y-m-d H:i:s'で保存する
]);

$item = CatalogItemModel::find($id);                               // CatalogItemRow|null
$latest = CatalogItemModel::latestPublished(10);                    // list<CatalogItemRow>

CatalogItemModel::update(['status' => CatalogStatus::Draft, 'published_at' => null], $id); // nullはSQLのNULL
$drafts = CatalogItemModel::count(['status' => CatalogStatus::Draft]);
CatalogItemModel::delete($id);
```

## テーブル定義(TableDefinition)

`TableDefinition::create()`にprefixの無いテーブル名(ベア名。英小文字で始まり、英小文字・数字・`_`だけ)を渡し、列とキーを宣言順に並べる。`table()`は副作用の無い、毎回同じ定義を返す関数にする(結果はクラスごとにキャッシュされる)。

### 組込の列のメソッド

| メソッド | 生成する型 | NULLの既定 | 注意 |
|---|---|---|---|
| `id($name = 'id')` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT`と主キー | 許さない | `primary()`と併用しない |
| `string($name, $length = 191, $nullable = false, $default = null)` | `varchar($length)` | 許さない | 既定の長さは191 |
| `text($name, $nullable = true)` | `text` | 許す | 索引を張れない |
| `int($name, $nullable = false, $default = null, $unsigned = false)` | `int(11)`(`unsigned`) | 許さない | |
| `bigint($name, $nullable = false, $default = null, $unsigned = false)` | `bigint(20)`(`unsigned`) | 許さない | |
| `decimal($name, $precision = 10, $scale = 2, $nullable = false, $default = null, $unsigned = false)` | `decimal($precision,$scale)` | 許さない | 既定値は10進の数の文字列(`'0.00'`)。指数・空白・`+`は`SchemaException` |
| `bool($name, $default = false)` | `tinyint(1) NOT NULL DEFAULT 0/1` | 許さない | 3値が要るなら`int(nullable: true)` |
| `datetime($name, $nullable = false, $default = null, $onUpdateCurrentTimestamp = false)` | `datetime` | 許さない | 既定値`'CURRENT_TIMESTAMP'`だけが引用符なしで出る。`$onUpdateCurrentTimestamp`は下の「ON UPDATE CURRENT_TIMESTAMP」 |
| `json($name, $nullable = true)` | `longtext` | 許す | JSONの型は使わない。値はアプリがencodeし、DTOの`array`の引数でdecodeする。索引を張れない |
| `foreignId($name, $nullable = false)` | `bigint(20) unsigned`と同名の`KEY` | 許さない | 外部キー制約は作らない(dbDeltaが扱えない) |
| `generated($name, $type, $expression, $storage)` | `$type AS ($expression) STORED`か`VIRTUAL` | 常に許す | 下の「生成カラム」 |
| `rawColumn($name, $definition)` | 型以降の定義をそのまま | 定義に`NOT NULL`が無ければ許す | 下の「rawColumn」 |

- キーは`primary(...$columns)`(`id()`を使わない場合。複合可)・`unique($columns, $name)`・`index($columns, $name)`で宣言する。キー名を省略すると列名を`_`でつないだ名前になる。
- 文字列の既定値に`'`を含めると`SchemaException`。
- テーブル名(prefixを含む)とキー名は64文字まで。超えると`SchemaException`(キー名は明示する)。

### 予約語

- 列名とキー名(`id()`の`$name`、`rawColumn()`、`index()`・`unique()`の`$name`を含む)に、SQLの予約語を使わない。`order`・`key`・`group`・`desc`・`range`・`rank`・`offset`等は、大文字小文字を問わず宣言の時点で`SchemaException`になる。
- 予約語の集合は、MySQL 8.0の予約語と、MariaDBで引用符なしで列名に使えない語の和集合。生成するSQLは識別子を引用符で囲まない(dbDeltaの要件)ため、予約語の列はCREATE TABLEやクエリで構文エラーになる。
- `sort_order`・`group_key`のように語を足して避ける。`status`・`name`・`date`等の予約語でないキーワードは使える。
- テーブル名はprefixが付くため対象外。

### charsetとcollation

- 省略すると、wpdbの既定(`$wpdb->get_charset_collate()`)で作る。
- 照合順序を指定するときは`charset()`の後に`collation()`を呼ぶ(`->charset('utf8mb4')->collation('utf8mb4_unicode_ci')`)。`charset()`の無い`collation()`は宣言の時点で`SchemaException`。
- 照合順序の変更はdbDeltaが適用しないため、`wp tmswp migrate`が`MigrationException`になる(下の「失敗したとき」)。

### rawColumn

組込のメソッドで書けない型にだけ使う。`$definition`は型以降の定義(`'mediumint(9) NOT NULL'`)。前後の空白は除かれる。

- 引用符(`'` `"` `` ` ``)と`;`を含められない。文字列の既定値を持つ列は`string()`等で宣言する。
- コメント(`/*`・`*/`・`#`・`--`)と、生成カラムの構文(`AS (`・`GENERATED ALWAYS`)は`SchemaException`になる。生成カラムは`generated()`で宣言する(下の「生成カラム」)。
- 既定値とON UPDATEの`NOW()`・`LOCALTIMESTAMP`・`LOCALTIME`は、`CURRENT_TIMESTAMP`と同じものとして扱われる(DBは`CURRENT_TIMESTAMP`と記録する)。ただし、MariaDB 12.3は`ON UPDATE LOCALTIMESTAMP`・`ON UPDATE LOCALTIME`を構文の誤りにし、`DEFAULT LOCALTIME`を時刻だけの`curtime()`と記録する(`wp tmswp migrate`が既定値の食い違いで止まる)。日時の関数は`CURRENT_TIMESTAMP`か`NOW()`で書く。
- `ON UPDATE CURRENT_TIMESTAMP`は書ける。`datetime`の列なら`datetime(onUpdateCurrentTimestamp: true)`でも同じ行になる(下の「ON UPDATE CURRENT_TIMESTAMP」)。
- DBが別の表記で記録する型の表記は、dbDeltaの差分が適用の後も残るため、宣言の時点で`SchemaException`になる。メッセージが案内する表記で書く。

| 拒否する表記 | 使う表記 |
|---|---|
| `integer`・`int4` | `int` |
| `int1` | `tinyint` |
| `int2` | `smallint` |
| `int3`・`middleint` | `mediumint` |
| `int8` | `bigint` |
| `numeric`・`dec`・`fixed` | `decimal` |
| `real`・`float8` | `double` |
| `float4` | `float` |
| `boolean`・`bool` | `tinyint(1)` |
| `character`・`nchar` | `char` |
| `character varying`・`char varying`・`varcharacter`・`nvarchar` | `varchar` |
| `national` | `char`か`varchar` |
| `long`・`long varchar` | `mediumtext` |
| `json` | `longtext`(JSONの列は`json()`か`longtext`で宣言する) |
| `serial` | `id()`か`bigint(20) unsigned NOT NULL AUTO_INCREMENT` |
| 精度の引数が1つの`float(p)`・`double(p)` | 精度24までは`float`、25〜53は`double` |
| 表示幅の無い整数の型(`tinyint`・`smallint`・`mediumint`・`int`・`bigint`) | `tinyint(4)`・`smallint(6)`・`mediumint(9)`・`int(11)`・`bigint(20)`(`unsigned`はその後) |

- `float(10,2)`のような精度と桁の2つの引数と、`double precision`は受け付ける。
- これ以外の表記でもdbDeltaの差分が残る場合(引数の無い`decimal`・`char`、`timestamp(0)`等)は、`wp tmswp migrate`が`dbDelta left differences on table ...`の`MigrationException`になる。定義の型を、メッセージの差分の文に出るDBの表記に合わせる。

### 生成カラム(generated())

ほかの列の値からDBが計算する列。`generated($name, $type, $expression, $storage)`で宣言し、`{name} {type} AS ({expression}) STORED`(または`VIRTUAL`)の行になる。`GeneratedColumnStorage::Stored`は値を行に保存し、`GeneratedColumnStorage::Virtual`は読むときに計算する。生成カラムには索引を張れる。

よく使うのは「アクティブな行だけで一意にする」形である。アクティブな行だけが値を持ち、非アクティブならNULLになる生成カラムを作り、それにUNIQUEの索引を張る。UNIQUEの索引はNULLを重複とみなさないため、アクティブな行どうしの重複だけをDBが拒否し、非アクティブな行は何行でも共存できる。

`app/Model/AccountModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Model;

use TMSWP\Core\Attribute\AsModel;
use TMSWP\Core\Model\GeneratedColumnStorage;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;

#[AsModel]
final class AccountModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('accounts')
            ->id()
            ->string('account_type', 20)
            ->string('status', 20)
            ->bigint('corp_id', nullable: true, unsigned: true)
            ->datetime('created_at', default: 'CURRENT_TIMESTAMP')
            ->datetime('updated_at', default: 'CURRENT_TIMESTAMP', onUpdateCurrentTimestamp: true)
            // 生成カラムは通常の列の後にまとめて宣言する。会社の管理者のアクティブな行だけが、会社のIDを値に持つ
            ->generated(
                'corp_admin_active_key',
                'bigint(20) unsigned',
                "CASE WHEN account_type = 'corp_admin' AND status NOT IN ('expired','deleted') THEN corp_id ELSE NULL END",
                GeneratedColumnStorage::Stored,
            )
            // 1つの会社にアクティブな管理者は1人だけ
            ->unique('corp_admin_active_key', 'uq_corp_admin');
    }
}
```

`app/Service/CorpAdminAssignment.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\AccountModel;

final class CorpAdminAssignment
{
    /**
     * アカウントを会社の管理者としてアクティブにする。その会社にアクティブな管理者が既にいればfalse。
     * 同時に別の処理が同じ会社の管理者をアクティブにした場合は、UNIQUEの索引の重複でQueryExceptionになる。
     */
    public static function activate(int $accountId, int $corpId): bool
    {
        // 生成カラムは条件に使える
        if (AccountModel::count(['corp_admin_active_key' => $corpId]) > 0) {
            return false;
        }

        // 生成カラムには書き込まない。account_type・status・corp_idを変えれば、DBが計算し直す
        return AccountModel::update(['account_type' => 'corp_admin', 'status' => 'active', 'corp_id' => $corpId], $accountId) === 1;
    }
}
```

- 重複はDBの索引が拒否し、`Model::insert()`・`update()`は`QueryException`(メッセージに`Duplicate entry`)を投げる。非アクティブ(`expired`・`deleted`)の行をアクティブに戻すときも、同じ会社にアクティブな管理者がいれば拒否される。
- `$type`は小文字の型(`bigint(20) unsigned`・`varchar(40)`等)で書く。大文字・`NOT NULL`等の属性を含む型と、`rawColumn()`で拒否する型の表記(型の別名・表示幅の無い整数等)は`SchemaException`。
- 生成カラムは常にNULLを許す(MariaDBは生成カラムの`NOT NULL`を構文の誤りにする)。既定値は持てない。
- 生成カラムは、通常の列をすべて宣言した後にまとめて宣言する。生成カラムの後に通常の列(`id()`を含む)を宣言すると`SchemaException`。主キーに生成カラムを含められない。
- 式は文字列のリテラルで書く(PHPの変数をつなげない。PHPStanが検査する)。式の中に`'corp_admin'`のような文字列のリテラルを書ける(リテラルの中の`'`は`''`)。次は宣言の時点で`SchemaException`になる。
  - 空の式、改行・タブ・ASCIIでない文字を含む式
  - `;`・`` ` ``・`"`・`\`(リテラルの中でも)
  - 閉じていない文字列のリテラル
  - リテラルの外のコメント(`#`・`--`・`/*`・`*/`)
  - 括弧の釣り合わない式(`AS (`…`)`の外に出る式)
  - 語`DEFAULT`(大小文字を問わない。リテラルの中でも)
- `%`(剰余)は書ける。式の中の列名・関数が正しいかは、`wp tmswp migrate`の適用でDBが確かめる(誤りは`MigrationException`)。
- 生成カラムへの書き込みは、`Model::insert()`・`Model::update()`・`Model::query()->update()`が、DBに送る前に`InvalidQueryException`にする。MariaDBは、WordPressの接続(strictでないsql_mode)では生成カラムへの書き込みを警告だけで黙って捨て、MySQLはエラーにする。DBの違いを出さないため、生成カラムを持つテーブルにはModelを通して書く。
- `Model::connection()`の`insert()`・`update()`・`execute()`と、`new QueryBuilder(...)`で作ったビルダは、テーブルの定義を持たないため、生成カラムへの書き込みを検査しない。MariaDBでは値が黙って捨てられる。
- 条件(`update()`・`delete()`・`count()`の条件のマップ、`where()`)には生成カラムを使える。

適用(`wp tmswp migrate`)での扱い:

- 生成カラムを足すと、`--dry-run`が`changed`を出し、適用で列と索引が足される(既にある行の値も計算される)。
- 式を変えると、`--dry-run`が`changed`を出し、適用で`ALTER TABLE … MODIFY COLUMN`が式を変える。dbDeltaは既にある生成カラムの式を変えないため、Migratorが、既にある生成カラムのすべてに`MODIFY`を実行する。
- 式の変更と、その生成カラムへの索引の追加は、2回に分けて適用する(式を変えて`wp tmswp migrate`を実行した後に、索引を足してもう一度実行する)。同じ回に適用すると、dbDeltaの索引の追加が`MODIFY`より先に、古い式の値で走るため、古い式の値が重複していれば索引の追加が失敗する。
- `MODIFY`は式が変わっていなくても実行する。MariaDBは`MODIFY`のたびに表を作り直して全行を写すため、大きなテーブルでは、定義を変えた後の適用と`--force`に時間がかかる。保守モードの間に実行する。
- `MODIFY`の失敗は`failed to apply the generated column {t}.{c}: …`の`MigrationException`になる。STOREDとVIRTUALの入れ替えと、通常の列からVIRTUALへの変更は、`MODIFY`でできない(MariaDBは1907、MySQLは3106)。手動の`ALTER TABLE … DROP COLUMN`と`ADD COLUMN`で列を作り直してから、もう一度実行する。通常の列からSTOREDへの変更は`MODIFY`でできる。UNIQUEの索引を張った生成カラムで、新しい式の値が重複すると`Duplicate entry`で失敗する。データを直すか、式を見直す。
- 定義で通常の列にした列がDBでは生成カラムである場合等の食い違いは、`is expected to be a regular column but the database has a STORED generated column`等の`MigrationException`になる。手動の`ALTER TABLE`でDBを直すか、定義をDBに合わせる(`generated()`で宣言する)。

### ON UPDATE CURRENT_TIMESTAMP

`datetime(..., onUpdateCurrentTimestamp: true)`の列は、行を更新するたびにDBが現在の日時を入れる。

- 例: `->datetime('updated_at', default: 'CURRENT_TIMESTAMP', onUpdateCurrentTimestamp: true)`は`updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`になる(上の`AccountModel`)。
- これは`rawColumn('updated_at', 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP')`とまったく同じ行で、`rawColumn()`から書き換えても定義のハッシュは変わらない(`wp tmswp migrate`は何もしない)。
- 値はDBが入れるため、`update()`に`updated_at`を渡さない。行の値が1つも変わらない`update()`(同じ値の代入)では、DBは日時を変えない。
- テーブルを作るときと列を足すときは、そのまま`ON UPDATE`が付く。既にある列にあとから付ける・外すと、dbDeltaは適用しないため、`wp tmswp migrate`は`is expected to have ON UPDATE CURRENT_TIMESTAMP but the database has no ON UPDATE`等の`MigrationException`で止まる。下の「失敗したとき」に従って手動の`ALTER TABLE … MODIFY COLUMN`を実行してから、もう一度実行する。

## マイグレーション(wp tmswp migrate)

テーブルの定義は`wp tmswp migrate`だけが適用する。起動時・管理画面(`admin_init`)・ajax・cron・プラグインの有効化・テーマの切り替えでは適用しない。FTPのアップロードの途中のファイルでテーブルを変えないためである。

- テーマやプラグインを有効化しただけでは、テーブルは作られない。有効化の後に`wp tmswp migrate`を実行する。
- テーマ・プラグインのすべてのKernelの`#[AsModel]`のテーブルが、1回の実行で対象になる。
- 適用したテーブルの定義のハッシュは、autoloadしないoption`tmswp_schema_hashes`に保存される。定義が同じテーブルは、次の実行でdbDeltaを実行しない。

### 実行

```sh
wp tmswp migrate --dry-run   # 定義がDBと食い違うテーブルを「catalog_items: changed」「…: up-to-date」で出す。DBは変えない
wp tmswp migrate             # 食い違うテーブルだけにdbDeltaを実行する(「Migrated: catalog_items」「Skipped: … (up to date)」)
wp tmswp migrate --force     # 定義が同じテーブルにもdbDeltaを実行する
```

- 日本語のサイトでは、出力は訳される(`changed`は`変更あり`、`up-to-date`は`最新`、`Migrated: …`は`適用しました: …`、`Skipped: … (up to date)`は`スキップしました: …(最新)`、`No models registered.`は`登録されたModelがありません。`)。
- `--force`と`--dry-run`は値を取らず、併用できない(エラーで止まる)。
- Modelが1つも無ければ`No models registered.`の警告で終わる。
- wp-envの開発用サイトでは`node node_modules/@wordpress/env/bin/wp-env run cli wp tmswp migrate`で実行する。

### 本番での実行

本番では、テーマ・プラグインのファイルをすべて上げた後に、`wp tmswp migrate --dry-run`で変わるテーブルを確かめてから`wp tmswp migrate`を実行する。保守モード・上げるファイル・実行の順序を含む手順の全体は、rules/deploy.mdにある。失敗したら(終了コードが0でない)、下の「失敗したとき」に従って直し、もう一度実行する。

### 管理画面の通知

定義のハッシュが保存済みのハッシュと食い違うテーブルがあると、管理画面に「The database tables catalog_items do not match their TMSWP table definitions. Run wp tmswp migrate to apply the definitions.」の通知が出る。

- 通知が出るのは`manage_options`の権限を持つ利用者だけ。ほかの利用者のリクエストではoptionも読まない。
- 通知はテーブルを変えない。通知を見たら`wp tmswp migrate`を実行する。
- 通知の確認が失敗したら(DBのエラー等)、Kernelのdebugでは例外、本番ではKernelのログにerrorで記録して通知を出さない。

### dbDeltaが適用しない変更

dbDeltaが適用するのは、テーブルの作成、列と索引の追加、列の型の変更、引用符で囲む既定値(文字列等)の変更だけである。

- 列の削除・名前の変更・型の縮小のデータの変換はしない。不要になった列はDBに残る。
- 数値の既定値(`bool()`・`int()`・`decimal()`等)の変更、既定値の削除、`CURRENT_TIMESTAMP`の追加、既にある列への`ON UPDATE`の追加・削除、NULLの可否の変更、テーブルの照合順序の変更は適用しない。`wp tmswp migrate`は適用の後の構造を定義と照らし合わせ、食い違えば`MigrationException`で止まる。
- 生成カラムの式の変更だけは、dbDeltaの代わりにMigratorが`MODIFY`で適用する(上の「生成カラム」)。

### 失敗したとき(MigrationException)

失敗したテーブルのハッシュは更新されず、後のテーブルは処理されない。原因を直して`wp tmswp migrate`をもう一度実行すれば、そのテーブルから適用し直す。

| メッセージ | 意味 | 直し方 |
|---|---|---|
| `dbDelta failed on table {t}: {DBのエラー}` | dbDeltaのSQLがDBのエラーになった | DBのエラーの原因(既存のデータが新しい型に入らない等)を直す |
| `dbDelta left differences on table {t}: {差分}; ...` | 適用の後も差分が残った。型の表記をDBが別の表記で記録している | 定義の型を、差分の文に出るDBの表記に合わせる |
| `table {t}: column {c} is expected to ... but the database has ...; dbDelta does not apply this change, so apply it with a manual ALTER TABLE` | dbDeltaが適用しない変更(既定値・NULLの可否・ON UPDATE・生成カラムかどうか・照合順序) | 手動のALTER TABLEで変更してから、もう一度実行する。ON UPDATE・生成カラムの食い違いは、メッセージの続きのとおり、定義をDBに合わせてもよい |
| `failed to apply the generated column {t}.{c}: {DBのエラー}; ...` | 生成カラムの`MODIFY`がDBのエラーになった | 上の「生成カラム」の適用での扱い |
| 上の文の後に`, or, if the database stored a rounded value, change the definition to the value the database stores`が続く | 既定値の食い違い。DBが既定値を丸めて記録した場合もある(`double`で表せない桁の数等) | ALTER TABLEで直らない(DBがまた丸める)場合は、定義の既定値をメッセージの`the database has DEFAULT`の値に合わせる |
| `table was not created`・`column was not created`・`index was not created`・`primary key does not match` | 作られるはずのテーブル・列・索引・主キーが無い | dbDeltaが扱えない変更(主キーの変更等)。手動のALTER TABLEで合わせる |
| `failed to read the schema hashes: ...`・`failed to save the schema hashes` | 保存済みのハッシュの読み書きがDBのエラーになった | DBの状態を直す |

手動のALTER TABLEは、`wp tmswp migrate`の前に一度だけ実行する。テーブル名にはprefixが付く。次は、`price`列に既定値0を足した定義(`->int('price', default: 0, unsigned: true)`)が既定値の食い違いで止まったときの例。

```sh
wp db query "ALTER TABLE wp_catalog_items ALTER COLUMN price SET DEFAULT 0"
wp tmswp migrate
```

既にある`updated_at`に`onUpdateCurrentTimestamp: true`を足した定義が止まったときは、定義の列の行のとおりに列を変える。

```sh
wp db query "ALTER TABLE wp_accounts MODIFY COLUMN updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
wp tmswp migrate
```

### アンインストール

プラグインの`uninstall.php`で、`Migrator::dropTable()`にModelのクラスを渡す。テーブルを削除し、保存済みのハッシュも消す。

```php
<?php

declare(strict_types=1);

use App\Model\CatalogItemModel;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\Migrator;

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

(new Migrator(Connection::fromGlobal()))->dropTable(CatalogItemModel::class);
```

## Modelの使い方

| メソッド | 動き |
|---|---|
| `find(int $id)` | 主キーで1行。無ければnull |
| `insert(array $data)` | 1行を入れて`insert_id`を返す。`insert([])`は`INSERT INTO {t} () VALUES ()`(採番だけ) |
| `upsert(array $values, array $update)` | 同じキーの行が無ければ挿入し、あれば`$update`の列を更新する。影響行数(1=挿入、2=更新、0=変化なし)を返す。下の「upsert」 |
| `update(array $data, array\|int $where)` | 影響行数を返す |
| `delete(array\|int $where)` | 影響行数を返す |
| `count(array $where = [])` | 件数 |
| `query()` | このテーブルの新しい`QueryBuilder`(毎回別のインスタンス) |
| `tableName()`・`bareTableName()`・`primaryKey()` | prefix付きのテーブル名・ベア名・単一列の主キー名 |
| `connection()`・`useConnection(?Connection)` | 下の「接続」 |

- `update()`・`delete()`の`$where`は、intなら主キーの等値、配列なら列名の等値の条件(値がnullなら`IS NULL`)。空の配列・整数のキー・配列の値は`InvalidQueryException`。IN条件は`query()->whereIn()`を使う。
- `count()`の`$where`は等値の条件で、値が配列なら`IN`、nullなら`IS NULL`。空の配列なら全件。
- 業務のメソッド(`findBySku()`等)はModelに静的メソッドで足す。Modelにプロパティやコンストラクタを書かない。Modelは状態を持たず、行の入れ物にもならない(コンストラクタは引数の無い`final`で、サブクラスは宣言できない)。

### 行の型

- `rowClass()`を上書きしなければ、行は`stdClass`で、値はDBのままの`string`か`null`(数値の列も文字列)。
- DTOで受け取るなら`rowClass()`を上書きし、`@return class-string<Row>`を宣言する。FW同梱のPHPStan拡張がこの宣言から、`find()`・`first()`を`Row|null`、`get()`を`list<Row>`に絞る。宣言が無いと`object|null`・`list<object>`のまま。`@return class-string<Row>|null`なら`Row|stdClass`。
- その場だけDTOにするなら`query()->as(Row::class)`。`as()`は`rowClass()`より優先する。
- `rowClass()`・`as()`にModelのクラスを渡すと`InvalidQueryException`。存在しないクラスは`HydrationException`。
- DTOはコンストラクタの引数で列を受け取る。引数名と同じ列、無ければsnake_caseにした列(`publishedAt`→`published_at`)を使う。行に無い列は、引数に既定値があればそれを使い、無ければ`HydrationException`。
- 引数の型で値を変換する。`int`・`float`・`string`・`bool`(`'0'`・`'1'`)、`DateTimeImmutable`(`'Y-m-d H:i:s'`の厳密な形を`wp_timezone()`で読む)、backed enum(`::from()`)、`array`(JSONのデコード)、`?T`(NULLならnull)。変換できない値・intの範囲を超える値・NULLを許さない型へのNULLは`HydrationException`。

### 値の渡し方

`insert()`・`update()`・`where()`等に渡す値は、型ごとに次のプレースホルダと前処理でSQLに入る。

| 値 | 入り方 |
|---|---|
| `int`・`bool` | `%d`(boolは0か1) |
| `float` | `%f` |
| `string`・`Stringable` | `%s`(`Stringable`は文字列にする) |
| `DateTimeInterface` | `%s`の`'Y-m-d H:i:s'`。タイムゾーンは変換しない(サイトのタイムゾーンで作るのは呼び出し側。`new DateTimeImmutable('now', wp_timezone())`) |
| backed enum | backing valueの型に従い`%d`か`%s` |
| `null` | 代入(`insert()`の値・`update()`のSET)はSQLの`NULL`、比較(`where('col', null)`)は`IS NULL`、`whereIn()`の要素は`InvalidQueryException` |
| 配列・その他のオブジェクト | `InvalidQueryException`(配列の比較は`whereIn()`) |

### 接続

- Modelは、属するKernel(`#[AsModel]`を見つけたKernel)の`Connection`を使う。テーマ・プラグインの中のコードでは指定しない。
- Kernelの外(プラグインの`uninstall.php`、Kernelを起動しない単独のスクリプト等)では、Modelを使う前に`Model::useConnection(Connection::fromGlobal())`で接続を明示する。指定しないと`KernelException`になる(グローバルの`$wpdb`に暗黙に切り替えない)。
- `useConnection()`は呼んだクラスに関係なく、すべてのModelに効く。`useConnection(null)`で解除する。

```php
<?php

declare(strict_types=1);

use App\Model\CatalogItemModel;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\Model;

Model::useConnection(Connection::fromGlobal());

try {
    $total = CatalogItemModel::count();
} finally {
    Model::useConnection(null);
}
```

## クエリ(QueryBuilder)

`Model::query()`から組み立てる。条件はメソッドを呼んだ時点でSQLの断片とプレースホルダの値になり、値がSQLに直接つながることは無い。

- `where('col', $value)`(値がnullなら`IS NULL`)、`where('col', '>=', $value)`(演算子は`=` `!=` `<>` `>` `>=` `<` `<=` `LIKE` `NOT LIKE`)、`where(fn (QueryBuilder $q) => ...)`(括弧のグループ。グループの中ではwhere系だけ)。`orWhere()`も同じ形。
- `whereIn()`・`whereNotIn()`はlistを渡す(要素にnull・配列は不可)。空のlistは、`whereIn()`が常に偽、`whereNotIn()`が常に真になる。
- `whereNull()`・`whereNotNull()`・`whereBetween()`・`whereLike()`。`whereLike()`のパターンは`Model::connection()->escLike($input)`でエスケープしてから`%`を足す(`'%' . $conn->escLike($keyword) . '%'`)。
- `select('col', 't.col', 'col AS alias')`、`alias('t')`、`innerJoin()`・`leftJoin()`、`groupBy()`、`orderBy('col', SortOrder::Desc)`・`orderByDesc()`、`limit()`・`offset()`(負の数は例外。`offset()`は`limit()`と一緒に使う)。

### テーブル名はベア名で渡す

- `innerJoin()`・`leftJoin()`・`innerJoinRaw()`・`leftJoinRaw()`のテーブル名は、prefixの無いベア名か`ベア名 AS 別名`で渡す。prefix(既定の`prefix: true`)はFWが付けるため、WPコアのテーブル(`terms`等)もほかのModelのテーブルもベア名で書ける。
- ベア名は英小文字で始まり、英小文字・数字・`_`だけ。大文字を含む名前は小文字にせず`InvalidQueryException`。prefix付きのフル名を渡さない(ベア名の規則に合わなければ例外、合えばprefixが二重に付く)。
- prefixの無いテーブルを結合するときは`prefix: false`でフル名を渡す。
- `Model::connection()`の`insert()`・`update()`・`delete()`もベア名だけを受け付ける。

### raw断片

`whereRaw()`・`orWhereRaw()`・`selectRaw()`・`innerJoinRaw()`・`leftJoinRaw()`のSQLの断片は、次を守る。

- 断片はリテラルの文字列だけで書く。変数をつないだ断片(`"{$column} = %s"`)はPHPStan(level max)のエラーになる。動的な識別子は`%i`のプレースホルダで値として渡す(`->whereRaw('%i = %s', [$column, $value])`)。
- `'` `"` `` ` `` `;`を含めない(`InvalidQueryException`)。値はプレースホルダで渡す。
- プレースホルダは`%d` `%f` `%s` `%i`だけ。プレースホルダの数と値の数を合わせる。
- リテラルの`%`は`%%`と書く(剰余は`id %% 2`)。FWが`%`に戻す。
- 番号付き(`%1$s`)・書式付き(`%5s`)・大文字(`%F`)・単独の`%`(`%Y`等)と、`%%`の直後のプレースホルダ(`%%%s`。wpdbが引用符で囲まずに埋め込む)は`InvalidQueryException`。LIKEの`%`は値に入れて`%s`で渡す。

### 終端のメソッド

| メソッド | 動き |
|---|---|
| `get()` | 行のlist |
| `first()` | `LIMIT 1`にした先頭の行。無ければnull |
| `value($column)` | 先頭の行の列の値。無ければnull |
| `pluck($column, $keyColumn = null)` | 列の値のlist。`$keyColumn`を渡すと、その列の値をキーにした配列(キーが重なれば後の行) |
| `count()` | 件数(int) |
| `exists()` | 1行でもあるか |
| `update(array $values)`・`delete()` | 影響行数を返す |
| `toSql()` | 値を当てはめた、実行するとおりのSELECT文を返す(実行しない。デバッグ・ログ用) |

- `value()`・`pluck()`は、`select()`・`selectRaw()`を呼んでいなければ、指定した列(`$keyColumn`を含む)だけをSELECTする。呼んでいれば、そのSELECT句と値をそのまま使う(集計の別名を取り出せる)。
- `value()`・`pluck()`は、結果の行のキー(SELECT句の別名か、修飾しない列名)で列を探す。`t.col`はドットの後の`col`で探す。結果に無い列は`QueryException`。JOINで同じ名前の列があるときは、`select('t.col AS t_col')`で別名を付ける。
- `pluck()`のキーの列の値がintでもstringでもない(NULLを含む)と`QueryException`。
- `value()`・`pluck()`・`count()`・`exists()`は行をDTOにしない。`value()`・`pluck()`の値はDBのままの文字列かnull。
- `count()`は`select()`・`selectRaw()`・`orderBy()`・`limit()`・`offset()`を捨てて数える。`groupBy()`があれば、グループの数を返す(ページ送りの総件数に使える)。
- `update()`・`delete()`はWHEREが必須。全行を対象にするときだけ`whereRaw('1 = 1')`を書く。`alias()`・JOIN・`groupBy()`・`orderBy()`・`limit()`とは併用できない。`update()`の値のnullは`= NULL`を代入する。
- 終端のメソッドを同じビルダで何度呼んでもよい(ビルダの状態は変わらない)。乱択(`ORDER BY RAND()`)はできない。`pluck()`の後にPHPで選ぶ。

## トランザクションと行ロック

複数の書き込みをまとめて取り消したいときと、読んだ行をほかの処理に変えさせたくないときは、`Model::connection()->transaction()`で1つのトランザクションにする。行のロックは、トランザクションの中で`QueryBuilder::lock()`を付けたSELECTで取る。対応するDBは、MySQL 8.4以上とMariaDB 10.11以上である。

在庫を引き当てる例。在庫の行をロックして読み、足りれば減らす。

`app/Model/StockModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Model;

use TMSWP\Core\Attribute\AsModel;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;

#[AsModel]
final class StockModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('stocks')
            ->id()
            ->string('sku', 40)
            ->int('quantity', unsigned: true)
            ->unique('sku', 'sku_unique');
    }
}
```

`app/Service/StockReservation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\StockModel;
use TMSWP\Core\Exception\DeadlockException;
use TMSWP\Core\Exception\LockWaitTimeoutException;
use TMSWP\Core\Model\RowLock;

final class StockReservation
{
    /**
     * 在庫を$quantityだけ引き当てる。行が無いか在庫が足りなければfalse。
     *
     * @throws LockWaitTimeoutException ほかの処理が5秒を超えて行をロックしている場合
     * @throws DeadlockException DBがこのトランザクションを取り消した場合(全体をやり直せる)
     */
    public static function reserve(string $sku, int $quantity): bool
    {
        // ロック待ちの上限は5秒(この間だけ)。コールバックが例外を投げればROLLBACKし、正常に終わればCOMMITする
        return StockModel::connection()->transaction(static function () use ($sku, $quantity): bool {
            $available = StockModel::query()->where('sku', $sku)->lock(RowLock::ForUpdate)->value('quantity');

            if (!\is_string($available) || (int) $available < $quantity) {
                return false;
            }

            StockModel::query()->where('sku', $sku)->update(['quantity' => (int) $available - $quantity]);

            return true;
        }, lockWaitTimeout: 5);
    }
}
```

呼び出し側で、デッドロックならやり直し、ロック待ちの時間切れなら利用者に知らせる:

```php
<?php

declare(strict_types=1);

use App\Service\StockReservation;
use TMSWP\Core\Exception\DeadlockException;
use TMSWP\Core\Exception\LockWaitTimeoutException;

try {
    try {
        $reserved = StockReservation::reserve('A-100', 1);
    } catch (DeadlockException) {
        $reserved = StockReservation::reserve('A-100', 1); // transaction()の全体を1回だけやり直す
    }
} catch (LockWaitTimeoutException) {
    $reserved = false; // 混み合っている。時間を置いて試すよう利用者に知らせる
}
```

### transaction()

- `transaction(callable $callback, ?int $lockWaitTimeout = null)`は、`$callback`を引数なしで呼び、その戻り値を返す。`$callback`が例外を投げればROLLBACKして、同じ例外を包まずに投げる。正常に終わればCOMMITする。COMMITの失敗は`QueryException`になる。
- Modelに`transaction()`は無い。トランザクションはテーブルではなく接続の全体に効くため、`StockModel::connection()->transaction(...)`と書く。どのModelの`connection()`から呼んでも同じ接続である。
- 入れ子は`InvalidQueryException`になる(SAVEPOINTによる入れ子は作らない)。`transaction()`を使うメソッドを、ほかの`transaction()`のコールバックから呼ばない。実行中かどうかは`Model::connection()->inTransaction()`で分かる。
- `$lockWaitTimeout`(1〜1073741824の秒数)を渡すと、この間だけ`innodb_lock_wait_timeout`をその秒数にし、終わった後(例外のときも)に元の値に戻す。省くとDBの設定のまま(既定は50秒)。範囲の外は`InvalidQueryException`。COMMITの後に元の値に戻せなかった場合も`QueryException`になる。このときはコミット済みなので、やり直さない。
- 自動の再試行はしない。デッドロック(`DeadlockException`)とロック待ちの時間切れ(`LockWaitTimeoutException`)をどう扱うか(やり直すか、利用者にエラーを見せるか)は、呼び出し側で決める。2つとも`QueryException`の派生なので、`catch (QueryException $e)`でも捕まえられる。
- トランザクションの中の`Model::connection()->execute()`は、トランザクションを暗黙に終える文と、トランザクションの状態を直接変える文を`InvalidQueryException`で拒否する。先頭の語が`START`・`BEGIN`・`COMMIT`・`ROLLBACK`・`SAVEPOINT`・`LOCK TABLE(S)`・`UNLOCK TABLE(S)`・`CREATE`・`ALTER`・`DROP`・`TRUNCATE`・`RENAME`・`OPTIMIZE`・`ANALYZE TABLE`・`REPAIR`・`CHECK TABLE`・`FLUSH`・`GRANT`・`REVOKE`の文と、`SET autocommit`(`SET SESSION`・`SET LOCAL`・`SET @@`の形を含む)である(DDL等は暗黙にコミットする)。
- トランザクションは`transaction()`だけで扱う。`$wpdb->query('START TRANSACTION')`や`Model::connection()->wpdb()`でトランザクションを直接操作すると、Connectionはそれを検出できない(入れ子の拒否・`lock()`の検査が働かない)。

### 行ロックの句(lock())

| `RowLock` | SQLの句 | 動き |
|---|---|---|
| `ForUpdate` | `FOR UPDATE` | 排他ロック。ほかの処理がロックしていれば、ロック待ちの秒数まで待つ |
| `ForUpdateNoWait` | `FOR UPDATE NOWAIT` | 排他ロック。ほかの処理がロックしていれば、待たずに`LockWaitTimeoutException` |
| `ForUpdateSkipLocked` | `FOR UPDATE SKIP LOCKED` | 排他ロック。ほかの処理がロックしている行を飛ばす(キューから未処理の行を取る等) |
| `Shared` | `LOCK IN SHARE MODE` | 共有ロック。読んだ行をほかの処理に変えさせない(ほかの処理も共有ロックで読める) |

- 句はSELECT文の最後(OFFSETの後)に付く。`toSql()`にも出る。2回目の`lock()`は上書きする。例: `QueueModel::query()->where('completed', false)->orderBy('queued_at')->limit(10)->lock(RowLock::ForUpdateSkipLocked)->get()`は`SELECT * FROM wp_queue WHERE completed = 0 ORDER BY queued_at ASC LIMIT 10 FOR UPDATE SKIP LOCKED`を実行する。
- `get()`・`first()`・`value()`・`pluck()`・`exists()`に効く。トランザクションの外で実行すると`InvalidQueryException`(トランザクションの外の行ロックは、文の終わりで解けて意味が無い)。`count()`・`update()`・`delete()`と、`where()`のグループの中では使えない(`InvalidQueryException`)。
- 共有ロックは、MariaDBで構文の誤りになる`FOR SHARE`ではなく、MariaDB・MySQLの両方で使える`LOCK IN SHARE MODE`を出す。共有ロックに`NOWAIT`・`SKIP LOCKED`を付けた句は無い(MySQLで構文の誤りになる)。DBの種類で句を変えない。
- ロック待ちの時間切れ(エラー番号1205)と、`NOWAIT`でロックを得られないとき(MariaDBは1205、MySQLは3572)は`LockWaitTimeoutException`、デッドロック(1213)は`DeadlockException`になる。

### 注意

1. ロック待ちの時間切れ(`LockWaitTimeoutException`)では、DBの`innodb_rollback_on_timeout`が既定のOFFのとき、DBはその文だけを取り消し、トランザクションは続く。コールバックの中でこの例外を捕まえて処理を続けると、それまでの書き込みは残ったままコミットされる。全体を取り消すなら、コールバックの中で捕まえずに`transaction()`の外へ出す。
2. デッドロック(`DeadlockException`)では、DBがトランザクションの全体を取り消している。コールバックの中でこの例外を捕まえて処理を続けると、以降の文はトランザクションの外で実行される。捕まえずに`transaction()`の外へ出し、呼び出し側で`transaction()`の全体をやり直す。
3. トランザクションの中でWordPressのAPI(`update_option()`・`wp_insert_post()`等)を呼ぶと、ロールバックしても、WordPressのオブジェクトキャッシュ(永続化のキャッシュを含む)は戻らない。WordPressのAPIは、`transaction()`が戻った後(コミットの後)に呼ぶ。
4. コールバックの中で`exit`・`wp_die()`・`wp_send_json*()`・リダイレクト(`wp_safe_redirect()`の後の`exit`)をしない。COMMITされずに書き込みが失われる。応答は`transaction()`が戻った後に返す。
5. トランザクションの実行中にDBとの接続が切れると、wpdbは再接続して文を新しいセッション(トランザクションの外)で実行し直す。`transaction()`はこれを検出して、COMMITせずに`QueryException`を投げる。再接続の前の書き込みは失われ、再接続の後の文は確定している可能性がある。
6. 接続を文ごとに切り替えるdb.phpのドロップイン(HyperDB・LudicrousDB等)の環境では、`transaction()`を使えない(接続が替わったとして`QueryException`になる)。
7. `WP_DEBUG_DISPLAY`が真の開発環境では、まれにエラー番号を読めず、デッドロック・ロック待ちの時間切れが素の`QueryException`になることがある。

## upsert

同じキーの行が無ければ挿入し、あれば更新するときは、`Model::upsert(array $values, array $update)`を使う。`$values`の主キーかUNIQUEの索引の列の値が既にある行と重複すると、DBは挿入の代わりに、その行の`$update`の列を更新する(`INSERT … ON DUPLICATE KEY UPDATE`)。読んでから書く(`find()`等で探して、無ければ`insert()`、あれば`update()`)と、2つの処理が同時に同じキーを挿入して重複のエラーになることがある。upsertは1つの文なので、そうならない。

| `UpsertUpdate` | 重複したときの更新 |
|---|---|
| `Replace` | `列 = 挿入しようとした値` |
| `Increment` | `列 = 列 + 挿入しようとした値` |

視聴の進捗を保存する例。利用者と動画の組ごとに1行を持ち、再生位置を置き換え、視聴した秒数を累計に足す。

`app/Model/WatchProgressModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Model;

use TMSWP\Core\Attribute\AsModel;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;

#[AsModel]
final class WatchProgressModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('watch_progress')
            ->id()
            ->bigint('user_id', unsigned: true)
            ->bigint('video_id', unsigned: true)
            ->int('position_seconds', unsigned: true)
            ->int('watched_seconds', unsigned: true)
            ->datetime('updated_at', default: 'CURRENT_TIMESTAMP', onUpdateCurrentTimestamp: true)
            // upsertの重複の判定に使うキー
            ->unique(['user_id', 'video_id'], 'user_video');
    }
}
```

`app/Service/WatchProgressRecorder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\WatchProgressModel;
use TMSWP\Core\Model\UpsertUpdate;

final class WatchProgressRecorder
{
    /**
     * 再生位置を保存し、前回の保存から視聴した秒数を累計に足す。その利用者と動画の行が無ければ作る。
     *
     * @param int<0, max> $position 再生位置の秒数
     * @param int<0, max> $watchedSeconds 前回の保存から視聴した秒数
     */
    public static function save(int $userId, int $videoId, int $position, int $watchedSeconds): void
    {
        // INSERT INTO wp_watch_progress (user_id, video_id, position_seconds, watched_seconds) VALUES (%d, %d, %d, %d)
        // ON DUPLICATE KEY UPDATE position_seconds = %d, watched_seconds = watched_seconds + %d
        WatchProgressModel::upsert(
            ['user_id' => $userId, 'video_id' => $videoId, 'position_seconds' => $position, 'watched_seconds' => $watchedSeconds],
            ['position_seconds' => UpsertUpdate::Replace, 'watched_seconds' => UpsertUpdate::Increment],
        );
    }
}
```

APIの利用回数を、APIキーと日ごとに数える例。その日の最初の利用かどうかを、戻り値(1=挿入)で知る。

`app/Model/ApiUsageModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Model;

use TMSWP\Core\Attribute\AsModel;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;

#[AsModel]
final class ApiUsageModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('api_usage')
            ->id()
            ->string('api_key', 64)
            ->rawColumn('usage_date', 'date NOT NULL')
            ->int('calls', unsigned: true)
            ->unique(['api_key', 'usage_date'], 'key_date');
    }
}
```

`app/Service/ApiUsageCounter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\ApiUsageModel;
use DateTimeImmutable;
use TMSWP\Core\Model\UpsertUpdate;

final class ApiUsageCounter
{
    /** APIキーの$nowの日の利用回数に1を足す。その日の最初の利用(行を作った)ならtrue。 */
    public static function record(string $apiKey, DateTimeImmutable $now): bool
    {
        // INSERT INTO wp_api_usage (api_key, usage_date, calls) VALUES (%s, %s, %d) ON DUPLICATE KEY UPDATE calls = calls + %d
        $rows = ApiUsageModel::upsert(
            ['api_key' => $apiKey, 'usage_date' => $now->format('Y-m-d'), 'calls' => 1],
            ['calls' => UpsertUpdate::Increment],
        );

        return $rows === 1;
    }
}
```

- 出すSQLは`INSERT INTO {t} ({$valuesの列}) VALUES ({プレースホルダ}) ON DUPLICATE KEY UPDATE {列} = {プレースホルダ}, {列} = {列} + {プレースホルダ}`で、`$update`の各列に、`$values`のその列の値をもう一度バインドする。値のプレースホルダと前処理は`insert()`と同じ(上の「値の渡し方」)。
- `VALUES(列)`(MySQLで非推奨。MySQL 8.4・9.7で警告1287)と行の別名(`VALUES (…) AS n … n.列`。MariaDBで構文の誤り)は出さない。DBの種類でSQLを変えない。
- `$update`のキーは`$values`にある列(キーの文字列が大小文字まで同じもの)に限る。`$update`が空、`$update`のキーが`$values`に無い、不正な列名は`InvalidQueryException`。挿入だけで更新しないなら`insert()`を使う。
- `$values`のnullはSQLの`NULL`になる。nullの`Replace`は`列 = NULL`、nullの`Increment`は`InvalidQueryException`。
- `Increment`の値は`int`か`float`だけ。文字列(`'3'`のような数字だけの文字列を含む)・`bool`・backed enum・`Stringable`・`DateTimeInterface`は`InvalidQueryException`。WordPressの接続(strictでないsql_mode)では、`列 + '文字列'`が警告だけで数値に変わり、黙って誤った値になるためである。フォームの入力等の文字列は、数として検証してから(例: `filter_var($value, FILTER_VALIDATE_INT)`が`false`でないことを確かめてから)、その結果のintを渡す。`(int)`での変換は、`'abc'`を0にするため使わない。
- 戻り値は影響行数で、挿入が1、更新が2、値の変わらない更新(同じ値の`Replace`、0の`Increment`)が0。`Model::connection()->insertId()`は、挿入なら新しいID、更新なら更新した行のID、変化なしなら0を返す。
- 重複は、主キーとすべてのUNIQUEの索引で判定する。重複の判定に使うUNIQUEの索引は1つにし、`$values`に`id`を入れない。UNIQUEの索引が2つ以上あると、どの索引で重複したかで更新する行が変わる。
- 生成カラムを`$values`に入れると、`Model::upsert()`は`InvalidQueryException`にする(列名の大小文字を問わない)。`Model::connection()->upsert('ベア名', $values, $update)`はテーブルの定義を持たないため、検査しない(MariaDBは値を黙って捨てる)。生成カラムを持つテーブルには`Model::upsert()`で書く。
- 更新になったupsertも、AUTO_INCREMENTの値を1つ消費する(どのDBでも同じ)。頻繁にupsertするテーブルのIDは飛び飛びになるため、IDの連番を前提にしない。
- トランザクションの中でも使える。`ON UPDATE CURRENT_TIMESTAMP`の列は、upsertが行の値を変えたときにDBが更新する。

## 既存の固定ページで独自テーブルの詳細を出す

固定ページ`catalog-detail`を器にして、`/catalog-detail/?record=123`で独自テーブルの1行を出す場合、WordPressのメインクエリは固定ページを見つけるため、`record`の行が無くても404にならない(200で空のページになる)。次のどちらかにする。

- メインクエリで解決できるURLにする。詳細を投稿タイプで持つ等、レコードの有無をWordPressのメインクエリが判定する形にする。
- テンプレートの選択より前に、`app/Hook`のフックで行の有無を判定し、無ければ404にする。テンプレートの前処理クラス(rules/page.md)では404に切り替えない(テンプレートが選ばれた後では、ステータス行とテンプレートが食い違う)。

`app/Hook/CatalogDetailHook.php`:

```php
<?php

declare(strict_types=1);

namespace App\Hook;

use App\Model\CatalogItemModel;
use TMSWP\Core\Attribute\AsHook;
use TMSWP\Core\Attribute\Filter;
use TMSWP\Core\Hook\Hook;
use WP_Query;

#[AsHook]
final class CatalogDetailHook extends Hook
{
    /** 行の無い/catalog-detail/?record=…を、テンプレートの選択より前に404にする */
    #[Filter('pre_handle_404')]
    public function notFoundWithoutRecord(bool $preempt, WP_Query $query): bool
    {
        if ($preempt || !$query->is_page('catalog-detail')) {
            return $preempt;
        }

        $id = filter_input(INPUT_GET, 'record', FILTER_VALIDATE_INT);

        if (\is_int($id) && CatalogItemModel::find($id) !== null) {
            return false;
        }

        $query->set_404();
        status_header(404);
        nocache_headers();

        return true;
    }
}
```

- 行があるときだけテンプレートが選ばれる。テンプレートの前処理クラスでは行を読み直してDTOに入れる。

## テスト

PHPUnitの設定・`tests/bootstrap.php`・Brain Monkeyの使い方は`vendor/tmswp/testing/rules/testing.md`にある。この節はModelのテストに要ることだけを書く。

- WordPressなしのユニットテストでは、tmswp/testing(`composer require --dev tmswp/testing`)の`WpdbTestDouble`をwpdbの代役にし、`Model::useConnection(new Connection(WpdbTestDouble::create()))`で接続を渡す。`tearDown()`で`Model::useConnection(null)`を呼んで解除する。Kernelのbootは要らない。
- `WpdbTestDouble`の`prepare()`は、実際の`wpdb::prepare()`と同じSQLを返す。実行したSQLは`queries()`、`insert()`・`update()`・`delete()`の呼び出しは`insertCalls()`・`updateCalls()`・`deleteCalls()`で確かめる。読み取りの結果は、実行の前に`queueResultSet()`(行のlist)・`queueRow()`(1行)・`queueVar()`(1つの値)で入れておく。代役は行を持たないため、`update()`・`delete()`(QueryBuilderの`update()`・`delete()`を含む)の前に、影響する行の数を`$wpdb->queueAffectedRows(<行の数>)`で入れ、`insert()`の前に、作る行のID(`insert()`の戻り値になる`insert_id`。AUTO_INCREMENTの列の無いテーブルなら0)を`$wpdb->queueInsertId(<ID>)`で入れる。
- 行を読む呼び出し(`find()`・`first()`・`get()`・`value()`・`pluck()`)は、wpdbの定数`ARRAY_A`を使う。テストでは`WpdbTestDouble::defineOutputConstants()`で定義し、定数がほかのテストに漏れないよう、そのテストを`#[RunInSeparateProcess]`・`#[PreserveGlobalState(false)]`で別のプロセスにする。
- `DateTimeImmutable`の列をDTOにするときは`wp_timezone()`を呼ぶため、Brain Monkeyで差し替える。
- dbDeltaの適用・既定値・照合順序・実際のSQLの結果は、WordPressの上(wp-envの統合テスト等)で確かめる。

`tests/Unit/Model/CatalogItemModelTest.php`(`app/Model/CatalogItemModel.php`のテスト):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\CatalogItemModel;
use App\Model\CatalogItemRow;
use App\Model\CatalogStatus;
use Brain\Monkey\Functions;
use DateTimeZone;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\Model;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;

final class CatalogItemModelTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Model::useConnection(null);
        parent::tearDown();
    }

    public function testInsertStoresTheEnumValue(): void
    {
        $wpdb = WpdbTestDouble::create();
        Model::useConnection(new Connection($wpdb));
        // 代役は行を持たないため、作る行のIDをテストが決める
        $wpdb->queueInsertId(1);

        CatalogItemModel::insert([
            'sku' => 'A-100',
            'name' => 'Thermometer',
            'status' => CatalogStatus::Published,
            'price' => 1200,
            'spec' => '{}',
        ]);

        $insert = $wpdb->insertCalls()[0];
        self::assertSame('wp_catalog_items', $insert['table']);
        self::assertSame('published', $insert['data']['status']);
    }

    // 行を読む呼び出しはwpdbの定数ARRAY_Aを使うため、定数を別のプロセスの中でだけ定義する
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFindBySkuReturnsTheRowAsDto(): void
    {
        WpdbTestDouble::defineOutputConstants();
        Functions\when('wp_timezone')->justReturn(new DateTimeZone('Asia/Tokyo'));
        $wpdb = WpdbTestDouble::create();
        Model::useConnection(new Connection($wpdb));
        $wpdb->queueResultSet([[
            'id' => '1',
            'sku' => 'A-100',
            'name' => 'Thermometer',
            'status' => 'published',
            'price' => '1200',
            'spec' => '{"range":"-20..50"}',
            'published_at' => '2026-10-01 09:00:00',
            'created_at' => '2026-10-01 09:00:00',
        ]]);

        $item = CatalogItemModel::findBySku('A-100');

        self::assertInstanceOf(CatalogItemRow::class, $item);
        self::assertSame(CatalogStatus::Published, $item->status);
        self::assertSame(['range' => '-20..50'], $item->spec);
        self::assertSame("SELECT * FROM wp_catalog_items WHERE sku = 'A-100' LIMIT 1", $wpdb->queries()[0]);
    }
}
```

`transaction()`と`lock()`を使うコードも、`WpdbTestDouble`でテストできる。代役は`START TRANSACTION`・`COMMIT`・`ROLLBACK`と`SET SESSION innodb_lock_wait_timeout = N`を受け付け(ロック待ちの秒数の既定は`'50'`)、実行した順に`queries()`に残す。行を持たないため、ロールバックで書き込みが消えることと、ロックの競合・デッドロック・ロック待ちの時間切れは再現しない。代役はmysqliの接続を持たず、DBのエラーは常に`QueryException`になる。デッドロック等の扱いのテストは、コールバックが呼ぶ処理から`new DeadlockException('...')`等を投げて書く(vendor/tmswp/testing/rules/testing.md)。

`tests/Unit/Service/StockReservationTest.php`(上の「トランザクションと行ロック」の`app/Service/StockReservation.php`のテスト):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\StockReservation;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\Model;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;

final class StockReservationTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Model::useConnection(null);
        parent::tearDown();
    }

    // value()は行を読むためwpdbの定数ARRAY_Aを使う。定数を別のプロセスの中でだけ定義する
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReserveLocksTheRowAndDecreasesTheQuantityInATransaction(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        Model::useConnection(new Connection($wpdb));
        $wpdb->queueRow(['quantity' => '3']);
        $wpdb->queueAffectedRows(1);

        self::assertTrue(StockReservation::reserve('A-100', 2));
        self::assertFalse($wpdb->inTransaction());
        self::assertSame([
            'SELECT @@SESSION.innodb_lock_wait_timeout',
            'SET SESSION innodb_lock_wait_timeout = 5',
            'START TRANSACTION',
            "SELECT quantity FROM wp_stocks WHERE sku = 'A-100' LIMIT 1 FOR UPDATE",
            "UPDATE wp_stocks SET quantity = 1 WHERE sku = 'A-100'",
            'COMMIT',
            'SET SESSION innodb_lock_wait_timeout = 50',
        ], $wpdb->queries());
    }
}
```

`upsert()`を使うコードも、`WpdbTestDouble`でテストできる。代役は`INSERT … ON DUPLICATE KEY UPDATE`を`INSERT`として受け付け、実行したSQLを`queries()`に残す。行を持たないため、upsertの前に、影響行数(1=挿入、2=更新、0=変化なし)を`queueAffectedRows()`で入れ、影響行数が1か2なら`insert_id`(挿入なら新しいID、更新なら更新した行のID)を`queueInsertId()`で入れる。影響行数が0なら、実際のwpdbと同じく`insert_id`は0になり、`queueInsertId()`は要らない。

`tests/Unit/Service/ApiUsageCounterTest.php`(上の「upsert」の`app/Service/ApiUsageCounter.php`のテスト):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ApiUsageCounter;
use DateTimeImmutable;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\Model;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;

final class ApiUsageCounterTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Model::useConnection(null);
        parent::tearDown();
    }

    public function testRecordIncrementsTheCallsAndTellsTheFirstCallOfTheDay(): void
    {
        $wpdb = WpdbTestDouble::create();
        Model::useConnection(new Connection($wpdb));
        // 代役は行を持たないため、影響行数(1=挿入、2=更新)と、そのときのinsert_idをテストが決める
        $wpdb->queueAffectedRows(1);
        $wpdb->queueInsertId(1);
        $wpdb->queueAffectedRows(2);
        $wpdb->queueInsertId(1);
        $now = new DateTimeImmutable('2026-10-30 12:00:00');

        self::assertTrue(ApiUsageCounter::record('key-1', $now));
        self::assertFalse(ApiUsageCounter::record('key-1', $now));
        self::assertSame(
            "INSERT INTO wp_api_usage (api_key, usage_date, calls) VALUES ('key-1', '2026-10-30', 1) ON DUPLICATE KEY UPDATE calls = calls + 1",
            $wpdb->queries()[1],
        );
    }
}
```

## してはいけないこと

- `$wpdb->query()`等を直接呼ばない。`Model::connection()->wpdb()`は、FWで書けない処理の最終手段にする。
- `CREATE TABLE`を手書きしない。`ALTER TABLE`は、dbDeltaが適用しない変更(上の「失敗したとき」)に限って、`wp tmswp migrate`の前に手で実行する。
- `register_activation_hook`・`after_switch_theme`・`init`・`admin_init`等でマイグレーションを実行しない。`Migrator::migrateAll()`を自分で呼ばない。
- raw断片に変数をつながない。値をSQLの文字列に埋め込まない。
- 予約語の列名、`rawColumn()`の`json`の型を使わない。
- 生成カラムに`Model::connection()`の`insert()`・`update()`・`upsert()`・`execute()`で書き込まない(MariaDBは値を黙って捨てる)。
- upsertを`execute()`のSQLの文字列で書かない。`VALUES(列)`を使わない(MySQLで非推奨)。`Model::upsert()`を使う。
- 行の値をModelのプロパティに持たせない。DTOは`rowClass()`か`as()`で指定する。
- 全行の更新・削除を`whereRaw('1 = 1')`以外の方法で書かない。
- トランザクションを`$wpdb->query('START TRANSACTION')`等で直接操作しない。`Model::connection()->transaction()`を使う。
- 行ロックの句をSQLの文字列やraw断片で書かない。`lock()`を使う。
- `transaction()`のコールバックの中で`DeadlockException`を捕まえて処理を続けない。WordPressのAPIをコールバックの中で呼ばない(上の「注意」)。

## 確認方法

1. `composer qa`が通ること。
2. `wp tmswp migrate --dry-run`が対象のテーブルを`changed`(日本語のサイトでは`変更あり`)と出し、`wp tmswp migrate`が成功し、もう一度の`--dry-run`で`up-to-date`(日本語のサイトでは`最新`)と出ること。
3. `insert()`した行を`find()`で読めること。

## 発展

- 行の有無で404にするフック: rules/hooks.md
- テーブルの値をテンプレートで使う前処理: rules/page.md
- 本番へのデプロイの手順: rules/deploy.md
- テストの書き方の全体(PHPUnitの設定・Brain Monkey): vendor/tmswp/testing/rules/testing.md
- Model・QueryBuilder・Migratorの仕様: TMSWPの設計文書[docs/06-model.md](https://github.com/tmstack-io/tmswp/blob/v0.10.0/docs/06-model.md)
