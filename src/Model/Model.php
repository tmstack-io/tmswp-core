<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Exception\SchemaException;
use TMSWP\Core\Kernel\Kernel;

/**
 * 1テーブル=1クラスの静的ファサード。サブクラスは table() でスキーマを宣言し、行は stdClass か
 * rowClass() / QueryBuilder::as() で指定した別の DTO クラスで受け取る。
 */
abstract class Model
{
    /** useConnection() で指定した、全 Model に共通の Connection */
    private static ?Connection $sharedConnection = null;

    /** @var array<class-string<Model>, TableDefinition> table() の結果のクラス別キャッシュ */
    private static array $definitionCache = [];

    /**
     * Model は状態を持たず、行の入れ物にもならない。引数の無い final のコンストラクタにして、サブクラスが行の値を受ける
     * コンストラクタを宣言することを封じる(行はコンストラクタを持つ別の DTO クラスで受け取る)。private にしないのは、
     * Discovery が #[AsModel] のクラスにインスタンス化できること(抽象クラスでないこと)を求めるため。
     */
    final public function __construct() {}

    /** サブクラスが実装するスキーマ宣言。冪等で副作用の無いこと(結果はクラスごとにキャッシュする) */
    abstract public static function table(): TableDefinition;

    /**
     * 行の既定のハイドレーション先 DTO。null なら stdClass で返す。
     * 上書きするときは `@return class-string<Row>` を宣言すると、PHPStan 拡張が find() 等の戻り値の型を Row に絞る。
     *
     * @return class-string|null
     */
    public static function rowClass(): ?string
    {
        return null;
    }

    final public static function tableName(): string
    {
        return static::connection()->tableName(static::bareTableName());
    }

    final public static function bareTableName(): string
    {
        return self::definition()->tableName();
    }

    /** @throws SchemaException 主キーが単一カラムでない(複合・未定義)場合 */
    final public static function primaryKey(): string
    {
        $primaryKey = self::definition()->primaryKey();

        if ($primaryKey === null) {
            throw new SchemaException('model requires a single-column primary key: ' . static::class);
        }

        return $primaryKey;
    }

    /**
     * このモデルのテーブルを対象とした新規 QueryBuilder。rowClass() が null でなければ as() で既定のハイドレーション先にする。
     *
     * @throws InvalidQueryException rowClass() が Model のサブクラスを返す場合(as() が拒否する)
     */
    final public static function query(): QueryBuilder
    {
        // 定義を渡し、update() に生成カラムへの代入を拒否させる
        $query = new QueryBuilder(static::connection(), static::bareTableName(), null, self::definition());
        $rowClass = static::rowClass();

        return $rowClass === null ? $query : $query->as($rowClass);
    }

    /** 主キー検索。rowClass() が null でなければその DTO、null なら stdClass */
    final public static function find(int $id): ?object
    {
        return static::query()->where(static::primaryKey(), $id)->first();
    }

    /**
     * INSERT。戻り値は insert_id。$data が空なら 'INSERT INTO {t} () VALUES ()' を発行する(AUTO_INCREMENT の採番のみ)。
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidQueryException $data に生成カラムのキーがある場合
     */
    final public static function insert(array $data): int
    {
        self::assertNoGeneratedColumn($data, 'insert()');
        $connection = static::connection();

        if ($data === []) {
            $connection->execute('INSERT INTO ' . static::tableName() . ' () VALUES ()');

            return $connection->insertId();
        }

        return $connection->insert(static::bareTableName(), $data);
    }

    /**
     * このテーブルへの upsert(INSERT … ON DUPLICATE KEY UPDATE。Connection::upsert())。戻り値は影響行数(1=挿入、2=更新、
     * 0=変化なし)。insert_id は connection()->insertId() で読む(更新なら更新した行の ID)。
     *
     * @param array<string, mixed> $values 挿入する列 => 値
     * @param non-empty-array<string, UpsertUpdate> $update 重複のときに更新する列。キーは $values にある列に限る
     *
     * @throws InvalidQueryException $values に生成カラムのキーがある場合と、Connection::upsert() が拒否する引数の場合
     */
    final public static function upsert(array $values, array $update): int
    {
        self::assertNoGeneratedColumn($values, 'upsert()');

        return static::connection()->upsert(static::bareTableName(), $values, $update);
    }

    /**
     * UPDATE。$where が int なら主キーの等値条件、配列なら等値条件のマップ(null は IS NULL)。戻り値は影響行数。
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|int $where
     *
     * @throws InvalidQueryException $where が空の配列・整数のキーを含む・配列の値を含む場合と、$data に生成カラムのキーが
     *                               ある場合(query() のビルダの update() が拒否する)。$where の生成カラムは条件なので拒否しない
     */
    final public static function update(array $data, array|int $where): int
    {
        return self::writeQuery($where)->update($data);
    }

    /**
     * DELETE。$where の解釈と制約は update() と同じ。戻り値は影響行数。
     *
     * @param array<string, mixed>|int $where
     *
     * @throws InvalidQueryException $where が空の配列・整数のキーを含む・配列の値を含む場合
     */
    final public static function delete(array|int $where): int
    {
        return self::writeQuery($where)->delete();
    }

    /**
     * 件数。$where は等値条件のマップ(値が配列なら IN、null なら IS NULL)。空の配列で全件数。
     *
     * @param array<string, mixed> $where
     *
     * @throws InvalidQueryException $where が整数のキーを含む場合
     */
    final public static function count(array $where = []): int
    {
        $query = static::query();

        foreach (self::whereColumns($where) as $column => $value) {
            if (\is_array($value)) {
                if (!array_is_list($value)) {
                    throw new InvalidQueryException("count values for '{$column}' must be a list");
                }

                $query->whereIn($column, $value);

                continue;
            }

            $query->where($column, $value);
        }

        return $query->count();
    }

    /**
     * 解決済みの Connection。useConnection() で指定したものがあればそれを、無ければクラスの属する Kernel の
     * Container の Connection を返す。
     *
     * @throws KernelException どの Kernel にも属さず、useConnection() も指定されていない場合
     */
    final public static function connection(): Connection
    {
        if (self::$sharedConnection !== null) {
            return self::$sharedConnection;
        }

        try {
            $kernel = Kernel::forClass(static::class);
        } catch (KernelException $exception) {
            throw new KernelException(
                'model ' . static::class . ' does not belong to any booted TMSWP kernel; call Model::useConnection() to set the connection outside a kernel',
                previous: $exception,
            );
        }

        return $kernel->container()->get(Connection::class);
    }

    /**
     * 全 Model に共通の Connection を指定する。null で解除する。Kernel の外(uninstall.php 等)とユニットテストで使う。
     */
    final public static function useConnection(?Connection $connection): void
    {
        self::$sharedConnection = $connection;
    }

    private static function definition(): TableDefinition
    {
        return self::$definitionCache[static::class] ??= static::table();
    }

    /**
     * $data のキーに生成カラムがあれば InvalidQueryException を投げる。DB の違い(MySQL はエラー、MariaDB は値を黙って
     * 捨てる)をなくすため、生成カラムへの書き込みは DB に送る前に拒否する。列名は大小文字を区別しないため、そろえて比べる。
     *
     * @param array<string, mixed> $data
     */
    private static function assertNoGeneratedColumn(array $data, string $method): void
    {
        $keys = array_change_key_case($data);

        foreach (self::definition()->generatedColumns() as $column) {
            if (\array_key_exists(strtolower($column), $keys)) {
                throw new InvalidQueryException("{$method} cannot write to the generated column {$column}; the database computes its value");
            }
        }
    }

    /**
     * update() / delete() の対象を絞った QueryBuilder。
     *
     * @param array<string, mixed>|int $where
     */
    private static function writeQuery(array|int $where): QueryBuilder
    {
        $query = static::query();

        if (\is_int($where)) {
            return $query->where(static::primaryKey(), $where);
        }

        if ($where === []) {
            throw new InvalidQueryException('where map must not be empty; pass a primary key or column conditions');
        }

        foreach (self::whereColumns($where) as $column => $value) {
            // 配列の値は QueryBuilder::where() が InvalidQueryException にする(IN 条件は query() で書く)
            $query->where($column, $value);
        }

        return $query;
    }

    /**
     * @param array<mixed> $where
     *
     * @return array<string, mixed>
     */
    private static function whereColumns(array $where): array
    {
        $columns = [];

        foreach ($where as $column => $value) {
            if (!\is_string($column)) {
                throw new InvalidQueryException("where map keys must be column names: {$column}");
            }

            $columns[$column] = $value;
        }

        return $columns;
    }
}
