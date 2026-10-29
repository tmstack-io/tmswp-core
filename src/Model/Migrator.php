<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

use Throwable;
use TMSWP\Core\Exception\MigrationException;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Core\Exception\SchemaException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Meta\WpDatabaseGuard;
use TMSWP\Core\Option\WpOptionStorage;

/**
 * 登録された Model のテーブル定義を dbDelta で適用する(06-model §5)。適用は `wp tmswp migrate`(MigrateCommand)
 * だけが行い、起動時・admin_init・ajax・cron では行わない(FTP のアップロード途中の状態でテーブルを変えないため)。
 * 管理画面では、定義のハッシュが保存済みのハッシュと食い違うテーブルを、権限のある管理者に通知するだけにする。
 */
final class Migrator
{
    /** 適用したテーブル定義のハッシュを保存するオプション。値は ベアテーブル名 => sha256。autoload しない */
    private const string OPTION_NAME = 'tmswp_schema_hashes';

    /** 定義と DB の食い違いの通知を見せる権限 */
    private const string NOTICE_CAPABILITY = 'manage_options';

    /** @var array<class-string<Model>, TableDefinition> 登録順 */
    private array $models = [];

    /** @var array<string, class-string<Model>> ベアテーブル名 => Model */
    private array $tables = [];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * Model を登録する。Kernel が #[AsModel] のクラスの初期化で呼ぶ。同じ Model の二度目の登録は同じ定義で上書きする。
     *
     * @param class-string<Model> $modelClass
     *
     * @throws SchemaException Model を継承していない場合と、別の Model が同じテーブルを登録済みの場合(宣言の誤り)
     */
    public function register(string $modelClass): void
    {
        $definition = self::definitionOf($modelClass);
        $table = $definition->tableName();
        $registered = $this->tables[$table] ?? null;

        if ($registered !== null && $registered !== $modelClass) {
            throw new SchemaException("table '{$table}' is already registered by {$registered}");
        }

        $this->models[$modelClass] = $definition;
        $this->tables[$table] = $modelClass;
    }

    /** @return list<class-string<Model>> 登録順 */
    public function models(): array
    {
        return array_keys($this->models);
    }

    /**
     * 各テーブルの定義のハッシュを、保存済みのハッシュと比べる。DB のテーブルは変えない(`--dry-run` と管理画面の通知が使う)。
     * Model が無ければ、保存済みのハッシュも読まない。
     *
     * @throws MigrationException 保存済みのハッシュのオプションが壊れている場合
     *
     * @return list<MigrationStatus> 登録順
     */
    public function status(): array
    {
        if ($this->models === []) {
            return [];
        }

        $hashes = $this->loadHashes();
        $statuses = [];

        foreach ($this->models as $model => $definition) {
            $table = $definition->tableName();
            $hash = $definition->hash($this->connection);
            $storedHash = $hashes[$table] ?? null;
            $statuses[] = new MigrationStatus($model, $table, $storedHash === $hash, $hash, $storedHash);
        }

        return $statuses;
    }

    /**
     * 登録順に各テーブルの定義を適用する。定義のハッシュが保存済みのハッシュと同じテーブルは、$force でなければ
     * dbDelta を実行しない。適用に成功したテーブルだけ、その都度ハッシュを保存する。
     *
     * 1テーブルの失敗で止め、後のテーブルは処理しない。失敗したテーブルのハッシュは更新しないため、原因を直して
     * もう一度実行すれば、そのテーブルから適用し直す。
     *
     * @throws MigrationException dbDelta の後にデータベースのエラーか残った差分がある場合、適用後のテーブルの構造が
     *                            定義と合わない場合、ハッシュを保存できない場合
     *
     * @return list<MigrationResult> 登録順
     */
    public function migrateAll(bool $force = false): array
    {
        if ($this->models === []) {
            return [];
        }

        $hashes = $this->loadHashes();
        $results = [];

        foreach ($this->models as $model => $definition) {
            $table = $definition->tableName();
            $hash = $definition->hash($this->connection);

            if (!$force && ($hashes[$table] ?? null) === $hash) {
                $results[] = new MigrationResult($model, $table, MigrationAction::Skipped, $hash);
                continue;
            }

            $this->apply($table, $definition->toSql($this->connection));
            $this->assertMigrated($definition);
            $hashes[$table] = $hash;
            $this->saveHashes($hashes);
            $results[] = new MigrationResult($model, $table, MigrationAction::Migrated, $hash);
        }

        return $results;
    }

    /**
     * テーブルを削除し(DROP TABLE IF EXISTS)、保存済みのハッシュを消す。uninstall.php 用で、登録していない Model も渡せる。
     *
     * @param class-string<Model> $modelClass
     *
     * @throws SchemaException Model を継承していない場合
     */
    public function dropTable(string $modelClass): void
    {
        $table = self::definitionOf($modelClass)->tableName();
        $this->connection->execute('DROP TABLE IF EXISTS ' . $this->connection->tableName($table));
        $hashes = $this->loadHashes();

        if (\array_key_exists($table, $hashes)) {
            unset($hashes[$table]);
            $this->saveHashes($hashes);
        }
    }

    /**
     * 管理画面の通知(admin_notices)と `wp tmswp migrate` を登録する。Kernel が boot で1回呼ぶ。
     *
     * Migrator と Connection(グローバルの $wpdb)はここでは解決せず、通知を確かめる時点で $kernel の Container から
     * 解決する。そのため boot はグローバルの $wpdb を要さない。
     */
    public static function listen(Kernel $kernel): void
    {
        add_action('admin_notices', static function () use ($kernel): void {
            self::noticeChangedDefinitions($kernel);
        }, 10, 0);

        MigrateCommand::register();
    }

    /**
     * 定義のハッシュが保存済みのハッシュと食い違うテーブルを、権限のある管理者に知らせる。テーブルは変えない。
     *
     * admin_notices は管理画面の表示でだけ発火する(ajax・cron・REST では発火しない)。確かめるのは、通知を見せる
     * 権限のあるユーザーのリクエストに限る。確かめる処理の失敗はエラー方針(00-overview.md §5.6)で隔離する。debug では
     * 例外をそのまま投げ、本番では $kernel のログに error で記録して通知をやめる。
     */
    private static function noticeChangedDefinitions(Kernel $kernel): void
    {
        if (!current_user_can(self::NOTICE_CAPABILITY)) {
            return;
        }

        $changed = [];

        try {
            foreach ($kernel->container()->get(self::class)->status() as $status) {
                if (!$status->current) {
                    $changed[] = $status->table;
                }
            }
        } catch (Throwable $exception) {
            if ($kernel->config()->debug) {
                throw $exception;
            }

            $kernel->logger()->error('tmswp.model: failed to compare the table definitions with the database', self::exceptionContext($exception));

            return;
        }

        if ($changed === []) {
            return;
        }

        // テーブル名は R1 のベアテーブル名(英小文字・数字・_)のため、HTML として解釈される文字を含まない
        wp_admin_notice(
            \sprintf(
                /* translators: 1: comma-separated database table names, 2: WP-CLI command */
                __('The database tables %1$s do not match their TMSWP table definitions. Run %2$s to apply the definitions.', 'tmswp'),
                implode(', ', $changed),
                'wp tmswp migrate',
            ),
            ['type' => 'error'],
        );
    }

    /**
     * ログの文脈に入れる例外の情報。例外のメッセージ・トレース・オブジェクトは入れない(Form の §ログ と同じ)。
     *
     * @return array{exceptionClass: class-string<Throwable>, exceptionAt: string, previousClasses: list<class-string<Throwable>>}
     */
    private static function exceptionContext(Throwable $exception): array
    {
        $previous = [];

        for ($current = $exception->getPrevious(); $current !== null; $current = $current->getPrevious()) {
            $previous[] = $current::class;
        }

        return [
            'exceptionClass' => $exception::class,
            'exceptionAt' => $exception->getFile() . ':' . $exception->getLine(),
            'previousClasses' => $previous,
        ];
    }

    /**
     * @param class-string<Model> $modelClass
     *
     * @throws SchemaException Model を継承していない場合
     */
    private static function definitionOf(string $modelClass): TableDefinition
    {
        if (!is_subclass_of($modelClass, Model::class)) {
            throw new SchemaException("model must extend Model: {$modelClass}");
        }

        return $modelClass::table();
    }

    /**
     * dbDelta で定義を適用し、失敗していないことを確かめる。
     *
     * dbDelta は1つの定義に複数の文(DESCRIBE・SHOW INDEX・ALTER 等)を発行する。wpdb は文ごとに last_error を
     * 消すため、適用の後の last_error は最後の文のエラーしか表さない。途中の文の失敗と、エラーを残さずに反映されなかった
     * 変更は、同じ定義の dry-run(execute=false)が返す残った差分で検出する。
     *
     * @throws MigrationException
     */
    private function apply(string $table, string $sql): void
    {
        $this->loadDbDelta();
        dbDelta($sql);
        $error = $this->connection->lastError();

        if ($error !== '') {
            throw new MigrationException("dbDelta failed on table {$table}: {$error}");
        }

        $remaining = dbDelta($sql, false);

        if ($remaining !== []) {
            throw new MigrationException("dbDelta left differences on table {$table}: " . implode('; ', $remaining) . '; if the database records a column type in another notation, write the type in the table definition as the database records it');
        }
    }

    /**
     * 適用後のテーブルの実際の構造を定義と照らし合わせる。dbDelta の比較(apply() の残った差分)を補う。
     *
     * 列・主キー・索引があることに加え、各列の NULL の可否と既定値、collation() で宣言したテーブルの照合順序を比べる。
     * dbDelta は型と引用符で囲んだ既定値の変更しか適用せず、数値の既定値・NULL の可否・照合順序の変更は、適用も
     * 残った差分の報告もしない。食い違えば、足りない変更を自動で ALTER せずに例外にする(手動の ALTER が要る)。
     *
     * @throws MigrationException
     */
    private function assertMigrated(TableDefinition $definition): void
    {
        $table = $definition->tableName();

        if (!$this->tableExists($definition)) {
            throw new MigrationException("table was not created: {$table}");
        }

        $existingColumns = $this->existingColumns($definition);

        foreach ($definition->columns() as $column) {
            if (!\array_key_exists($column, $existingColumns)) {
                throw new MigrationException("column was not created: {$table}.{$column}");
            }

            $existing = $existingColumns[$column];
            $nullable = $definition->columnNullable($column);

            if ($existing['nullable'] !== $nullable) {
                throw self::unappliedChange($table, "column {$column} is expected to be " . self::nullability($nullable) . ' but the database has ' . self::nullability($existing['nullable']));
            }

            $default = $definition->columnDefault($column);

            if (!self::sameDefault($definition->columnKind($column), $default, $existing['default'])) {
                // DB は double・float の既定値を丸めて記録することがあり、その場合は ALTER TABLE でも直らない
                throw self::unappliedChange(
                    $table,
                    "column {$column} is expected to have " . self::describeDefault($default) . ' but the database has ' . self::describeDefault($existing['default']),
                    ', or, if the database stored a rounded value, change the definition to the value the database stores',
                );
            }
        }

        $collation = $definition->declaredCollation();

        if ($collation !== null) {
            $existingCollation = $this->existingCollation($definition);

            if (self::normalizeCollation($existingCollation) !== self::normalizeCollation($collation)) {
                throw self::unappliedChange($table, "the table is expected to have collation {$collation} but the database has {$existingCollation}");
            }
        }

        $existingIndexes = $this->existingIndexes($definition);
        $primaryKey = $existingIndexes['PRIMARY'] ?? [];

        if ($primaryKey !== $definition->primaryColumns()) {
            throw new MigrationException("primary key does not match table definition: {$definition->tableName()}");
        }

        foreach ($definition->indexes() as $index) {
            $columns = $existingIndexes[$index['name']] ?? [];

            if ($columns !== $index['columns']) {
                throw new MigrationException("index was not created: {$definition->tableName()}.{$index['name']}");
            }
        }
    }

    /**
     * dbDelta が適用しない食い違いの例外。$alternative は、手動の ALTER TABLE のほかの直し方の案内(先頭に区切りを含める)。
     */
    private static function unappliedChange(string $table, string $difference, string $alternative = ''): MigrationException
    {
        return new MigrationException("table {$table}: {$difference}; dbDelta does not apply this change, so apply it with a manual ALTER TABLE{$alternative}");
    }

    private static function nullability(bool $nullable): string
    {
        return $nullable ? 'NULL' : 'NOT NULL';
    }

    private static function describeDefault(?string $default): string
    {
        return $default === null ? 'no default' : "DEFAULT {$default}";
    }

    /**
     * 既定値を、列の種類ごとに、DB が返す表記の違いを除いて比べる。どちらかが null(既定値なし)なら、両方が null のときだけ同じ。
     *
     * - number: 10進の数として正規化して厳密に比べる(`1` と `1.00` は同じ。DB が返す指数の表記 `1e20` も、指数の無い
     *   表記と同じ正規形にする。桁を丸めないため、`9007199254740992` と `9007199254740993` は別)。10進の数として
     *   読めない値は、そのまま比べる。
     * - temporal: CURRENT_TIMESTAMP の表記(大小文字・空の括弧・精度 0)をそろえ、精度は保って比べる。MariaDB は
     *   `current_timestamp()`・`current_timestamp(6)` と返す。
     * - string: そのまま比べる(`001` と `1` は別)。
     *
     * @param 'number'|'string'|'temporal' $kind
     */
    private static function sameDefault(string $kind, ?string $expected, ?string $actual): bool
    {
        if ($expected === null || $actual === null) {
            return $expected === $actual;
        }

        return match ($kind) {
            'number' => (self::normalizeNumber($expected) ?? $expected) === (self::normalizeNumber($actual) ?? $actual),
            'temporal' => self::normalizeTemporal($expected) === self::normalizeTemporal($actual),
            'string' => $expected === $actual,
        };
    }

    /**
     * 10進の数の表記(指数の表記を含む)を、指数の無い正規形にする。float を使わず、文字列の操作だけで扱う。
     *
     * 係数の数字の並びと、小数点の位置(整数部の桁数に指数を足したもの)から組み立て直す。先頭の0・末尾の0を除き、
     * 値が0なら符号を付けない(`-0` は `0`)。`1e20` と `100000000000000000000`、`1E-3` と `0.001` は同じ正規形になる。
     * 10進の数として読めない値と、指数の絶対値が400を超える値(DB の double の範囲 1e308 と DECIMAL の65桁を十分に
     * 超える)は null を返し、呼び出し側はそのまま比べる。
     */
    private static function normalizeNumber(string $value): ?string
    {
        if (preg_match('/\A([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?\z/', $value, $matches) !== 1) {
            return null;
        }

        $integer = $matches[2];
        $digits = $integer . ($matches[3] ?? '');
        $exponent = $matches[4] ?? '0';

        if ($digits === '' || \strlen(ltrim($exponent, '+-0')) > 3 || abs((int) $exponent) > 400) {
            return null;
        }

        // 小数点は、数字の並びの先頭から $point 桁目の後ろにある
        $point = \strlen($integer) + (int) $exponent;
        $significant = ltrim($digits, '0');
        $point -= \strlen($digits) - \strlen($significant);
        $significant = rtrim($significant, '0');

        if ($significant === '') {
            return '0';
        }

        $length = \strlen($significant);

        if ($point <= 0) {
            $number = '0.' . str_repeat('0', -$point) . $significant;
        } elseif ($point >= $length) {
            $number = $significant . str_repeat('0', $point - $length);
        } else {
            $number = substr($significant, 0, $point) . '.' . substr($significant, $point);
        }

        return ($matches[1] === '-' ? '-' : '') . $number;
    }

    /** CURRENT_TIMESTAMP の表記をそろえる。精度は保ち、空の括弧と精度 0 は精度なしとする。それ以外の値はそのまま。 */
    private static function normalizeTemporal(string $value): string
    {
        if (preg_match('/\Acurrent_timestamp(?:\((\d*)\))?\z/i', $value, $matches) !== 1) {
            return $value;
        }

        $precision = ltrim($matches[1] ?? '', '0');

        return $precision === '' ? 'CURRENT_TIMESTAMP' : "CURRENT_TIMESTAMP({$precision})";
    }

    /** utf8 は MySQL 8.0・MariaDB 10.6 以降が utf8mb3 と返すため、そろえて比べる。 */
    private static function normalizeCollation(string $collation): string
    {
        $collation = strtolower($collation);

        return str_starts_with($collation, 'utf8_') ? 'utf8mb3_' . substr($collation, 5) : $collation;
    }

    private function existingCollation(TableDefinition $definition): string
    {
        $row = $this->connection->selectRow('SHOW TABLE STATUS LIKE %s', [$this->connection->tableName($definition->tableName())]);
        $collation = $row['Collation'] ?? null;

        if (!\is_string($collation)) {
            throw new MigrationException("SHOW TABLE STATUS returned no collation for {$definition->tableName()}");
        }

        return $collation;
    }

    private function tableExists(TableDefinition $definition): bool
    {
        return $this->connection->selectVar('SHOW TABLES LIKE %s', [$this->connection->tableName($definition->tableName())]) !== null;
    }

    /** @return array<string, array{nullable: bool, default: string|null}> 列名 => NULL の可否と既定値 */
    private function existingColumns(TableDefinition $definition): array
    {
        $columns = [];

        foreach ($this->connection->select('SHOW FULL COLUMNS FROM ' . $this->connection->tableName($definition->tableName())) as $row) {
            $field = $row['Field'] ?? null;
            $null = $row['Null'] ?? null;
            $default = $row['Default'] ?? null;

            if (!\is_string($field) || ($null !== 'YES' && $null !== 'NO') || ($default !== null && !\is_string($default))) {
                throw new MigrationException("SHOW FULL COLUMNS returned an invalid row for {$definition->tableName()}");
            }

            $columns[$field] = ['nullable' => $null === 'YES', 'default' => $default];
        }

        return $columns;
    }

    /** @return array<string, list<string>> */
    private function existingIndexes(TableDefinition $definition): array
    {
        /** @var array<string, array<int, string>> $indexes */
        $indexes = [];

        foreach ($this->connection->select('SHOW INDEX FROM ' . $this->connection->tableName($definition->tableName())) as $row) {
            $name = $row['Key_name'] ?? null;
            $column = $row['Column_name'] ?? null;
            $sequence = $row['Seq_in_index'] ?? null;

            if (!\is_string($name) || !\is_string($column) || (!\is_int($sequence) && !\is_string($sequence))) {
                throw new MigrationException("SHOW INDEX returned an invalid row for {$definition->tableName()}");
            }

            $sequence = \is_int($sequence) ? $sequence : (ctype_digit($sequence) ? (int) $sequence : 0);

            if ($sequence < 1) {
                throw new MigrationException("SHOW INDEX returned an invalid sequence for {$definition->tableName()}");
            }

            $indexes[$name][$sequence] = $column;
        }

        $normalized = [];

        foreach ($indexes as $name => $columns) {
            ksort($columns);
            $normalized[$name] = array_values($columns);
        }

        return $normalized;
    }

    /** @return array<string, string> */
    private function loadHashes(): array
    {
        $value = $this->readHashOption();

        if ($value === false) {
            return [];
        }

        if (!\is_array($value)) {
            throw new MigrationException('schema hash option must be an array');
        }

        $hashes = [];

        foreach ($value as $table => $hash) {
            if (!\is_string($table) || !\is_string($hash)) {
                throw new MigrationException('schema hash option contains an invalid entry');
            }

            $hashes[$table] = $hash;
        }

        return $hashes;
    }

    /** @param array<string, string> $hashes */
    private function saveHashes(array $hashes): void
    {
        $exists = $this->readHashOption() !== false;

        try {
            $saved = WpDatabaseGuard::write(
                static fn(): bool => $exists
                    ? update_option(self::OPTION_NAME, $hashes, false)
                    : add_option(self::OPTION_NAME, $hashes, '', false),
                'failed to save the schema hashes',
            );
        } catch (RuntimeException $exception) {
            throw new MigrationException($exception->getMessage(), previous: $exception);
        }

        // update_option() は同じ値でも false を返すため、保存されている値で確かめる
        if (!$saved && $this->readHashOption() !== $hashes) {
            throw new MigrationException('failed to save the schema hashes');
        }
    }

    /**
     * 保存済みのハッシュのオプションを読む。無ければ false。
     *
     * get_option() は読み込みのクエリが失敗しても「オプションが無い」と同じ false を返すため、データベースのエラーを
     * WpDatabaseGuard(呼び出しの間のクエリの num_queries と last_error。D-47)で検出し、「ハッシュが無い」とは扱わずに
     * 例外にする。失敗した読み込みで WP が書いた「オプションが無い」のキャッシュも消す。
     *
     * @throws MigrationException データベースのエラー
     */
    private function readHashOption(): mixed
    {
        try {
            return WpDatabaseGuard::read(
                static fn(): mixed => get_option(self::OPTION_NAME),
                'failed to read the schema hashes',
                static fn() => WpOptionStorage::forgetFailedRead(self::OPTION_NAME),
            );
        } catch (RuntimeException $exception) {
            throw new MigrationException($exception->getMessage(), previous: $exception);
        }
    }

    private function loadDbDelta(): void
    {
        if (\function_exists('dbDelta')) {
            return;
        }

        if (!\defined('ABSPATH')) {
            throw new MigrationException('ABSPATH is required to load dbDelta');
        }

        $path = rtrim(ABSPATH, '/\\') . '/wp-admin/includes/upgrade.php';

        if (!is_file($path)) {
            throw new MigrationException("dbDelta file not found: {$path}");
        }

        require_once $path;
    }
}
