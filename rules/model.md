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
| `datetime($name, $nullable = false, $default = null)` | `datetime` | 許さない | 既定値`'CURRENT_TIMESTAMP'`だけが引用符なしで出る |
| `json($name, $nullable = true)` | `longtext` | 許す | JSONの型は使わない。値はアプリがencodeし、DTOの`array`の引数でdecodeする。索引を張れない |
| `foreignId($name, $nullable = false)` | `bigint(20) unsigned`と同名の`KEY` | 許さない | 外部キー制約は作らない(dbDeltaが扱えない) |
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
- 数値の既定値(`bool()`・`int()`・`decimal()`等)の変更、既定値の削除、`CURRENT_TIMESTAMP`の追加、NULLの可否の変更、テーブルの照合順序の変更は適用しない。`wp tmswp migrate`は適用の後の構造を定義と照らし合わせ、食い違えば`MigrationException`で止まる。

### 失敗したとき(MigrationException)

失敗したテーブルのハッシュは更新されず、後のテーブルは処理されない。原因を直して`wp tmswp migrate`をもう一度実行すれば、そのテーブルから適用し直す。

| メッセージ | 意味 | 直し方 |
|---|---|---|
| `dbDelta failed on table {t}: {DBのエラー}` | dbDeltaのSQLがDBのエラーになった | DBのエラーの原因(既存のデータが新しい型に入らない等)を直す |
| `dbDelta left differences on table {t}: {差分}; ...` | 適用の後も差分が残った。型の表記をDBが別の表記で記録している | 定義の型を、差分の文に出るDBの表記に合わせる |
| `table {t}: column {c} is expected to ... but the database has ...; dbDelta does not apply this change, so apply it with a manual ALTER TABLE` | dbDeltaが適用しない変更(既定値・NULLの可否・照合順序) | 手動のALTER TABLEで変更してから、もう一度実行する |
| 上の文の後に`, or, if the database stored a rounded value, change the definition to the value the database stores`が続く | 既定値の食い違い。DBが既定値を丸めて記録した場合もある(`double`で表せない桁の数等) | ALTER TABLEで直らない(DBがまた丸める)場合は、定義の既定値をメッセージの`the database has DEFAULT`の値に合わせる |
| `table was not created`・`column was not created`・`index was not created`・`primary key does not match` | 作られるはずのテーブル・列・索引・主キーが無い | dbDeltaが扱えない変更(主キーの変更等)。手動のALTER TABLEで合わせる |
| `failed to read the schema hashes: ...`・`failed to save the schema hashes` | 保存済みのハッシュの読み書きがDBのエラーになった | DBの状態を直す |

手動のALTER TABLEは、`wp tmswp migrate`の前に一度だけ実行する。テーブル名にはprefixが付く。次は、`price`列に既定値0を足した定義(`->int('price', default: 0, unsigned: true)`)が既定値の食い違いで止まったときの例。

```sh
wp db query "ALTER TABLE wp_catalog_items ALTER COLUMN price SET DEFAULT 0"
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

## してはいけないこと

- `$wpdb->query()`等を直接呼ばない。`Model::connection()->wpdb()`は、FWで書けない処理の最終手段にする。
- `CREATE TABLE`を手書きしない。`ALTER TABLE`は、dbDeltaが適用しない変更(上の「失敗したとき」)に限って、`wp tmswp migrate`の前に手で実行する。
- `register_activation_hook`・`after_switch_theme`・`init`・`admin_init`等でマイグレーションを実行しない。`Migrator::migrateAll()`を自分で呼ばない。
- raw断片に変数をつながない。値をSQLの文字列に埋め込まない。
- 予約語の列名、`rawColumn()`の`json`の型を使わない。
- 行の値をModelのプロパティに持たせない。DTOは`rowClass()`か`as()`で指定する。
- 全行の更新・削除を`whereRaw('1 = 1')`以外の方法で書かない。

## 確認方法

1. `composer qa`が通ること。
2. `wp tmswp migrate --dry-run`が対象のテーブルを`changed`(日本語のサイトでは`変更あり`)と出し、`wp tmswp migrate`が成功し、もう一度の`--dry-run`で`up-to-date`(日本語のサイトでは`最新`)と出ること。
3. `insert()`した行を`find()`で読めること。

## 発展

- 行の有無で404にするフック: rules/hooks.md
- テーブルの値をテンプレートで使う前処理: rules/page.md
- 本番へのデプロイの手順: rules/deploy.md
- テストの書き方の全体(PHPUnitの設定・Brain Monkey): vendor/tmswp/testing/rules/testing.md
- Model・QueryBuilder・Migratorの仕様: TMSWPの設計文書[docs/06-model.md](https://github.com/tmstack-io/tmswp/blob/v0.9.1/docs/06-model.md)
