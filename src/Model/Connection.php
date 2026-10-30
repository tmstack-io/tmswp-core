<?php

declare(strict_types=1);

namespace TMSWP\Core\Model;

use BackedEnum;
use DateTimeInterface;
use mysqli;
use ReflectionMethod;
use ReflectionObject;
use Stringable;
use Throwable;
use TMSWP\Core\Exception\DeadlockException;
use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Exception\LockWaitTimeoutException;
use TMSWP\Core\Exception\QueryException;
use WeakMap;
use wpdb;

final class Connection
{
    /** transaction()のロック待ちの秒数の上限(innodb_lock_wait_timeoutの最大値) */
    private const MAX_LOCK_WAIT_TIMEOUT = 1073741824;

    /**
     * トランザクションの実行中にexecute()が拒否する文。トランザクションを暗黙に終える文(DDLは暗黙にコミットする)と、
     * トランザクションの状態を直接変える文。prepareを当てる前のSQLの先頭で判定する。
     */
    private const TRANSACTION_BREAKING_SQL = '/^\s*(start|begin|commit|rollback|savepoint|lock\s+tables?|unlock\s+tables?|create|alter|drop|truncate|rename|optimize|analyze\s+(no_write_to_binlog\s+|local\s+)?tables?|repair|check\s+table|flush|grant|revoke)\b|^\s*set\s+(session\s+|local\s+|@@((session|local)\.)?)?autocommit\b/i';

    /**
     * transaction()の実行中のwpdbと、START TRANSACTIONの直後のwpdbの接続(`$dbh`)のオブジェクト(接続がオブジェクトで
     * なければfalseで、照合しない)。Kernelごとに別のConnectionが同じwpdbを使うため、インスタンスではなくwpdb(DBの
     * セッション)ごとに持つ。接続は強い参照で持ち、照合の間に解放されて別の接続と取り違えることを防ぐ。
     *
     * @var WeakMap<wpdb, object|false>|null
     */
    private static ?WeakMap $transactions = null;

    public function __construct(
        private readonly wpdb $wpdb,
    ) {}

    public static function fromGlobal(): self
    {
        global $wpdb;

        if (!class_exists(wpdb::class) || !$wpdb instanceof wpdb) {
            throw new QueryException('global wpdb is not available');
        }

        return new self($wpdb);
    }

    public function wpdb(): wpdb
    {
        return $this->wpdb;
    }

    public function prefix(): string
    {
        return $this->wpdb->prefix;
    }

    public function tableName(string $bareName): string
    {
        Identifier::bareTable($bareName);

        return $this->prefix() . $bareName;
    }

    public function escLike(string $value): string
    {
        return $this->wpdb->esc_like($value);
    }

    public function charsetCollate(): string
    {
        return $this->wpdb->get_charset_collate();
    }

    /**
     * プレースホルダに値を当てはめた SQL を返す。
     *
     * `%` で始まる並びは `%d` `%f` `%s` `%i` のプレースホルダと、リテラルの `%` を表す `%%` だけを受け付ける。
     * 値が無いときは wpdb::prepare() を通さず、`%%` を `%` に戻して返す。値があるときは、wpdb::prepare()が`%`を
     * 置き換えたエスケープの文字列(`{` + 16進 + `}`)をremove_placeholder_escape()で`%`に戻して返す。
     *
     * @param list<mixed> $bindings
     */
    public function prepare(string $sql, array $bindings = []): string
    {
        $count = self::placeholderCount($sql);

        if ($count !== \count($bindings)) {
            throw new InvalidQueryException("SQL placeholder count {$count} does not match bindings count " . \count($bindings));
        }

        if ($bindings === []) {
            return str_replace('%%', '%', $sql);
        }

        $prepared = $this->prepareWpdb($sql, $this->normalizeBindings($bindings));

        if (!\is_string($prepared)) {
            throw new QueryException('wpdb::prepare failed');
        }

        return $this->wpdb->remove_placeholder_escape($prepared);
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $prepared = $this->prepare($sql, $bindings);
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);
        $this->assertNoLastError($prepared);

        if (!\is_array($rows)) {
            throw new QueryException("query did not return rows: {$prepared}");
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                throw new QueryException("query returned a non-array row: {$prepared}");
            }

            $normalized[] = $this->stringKeyedRow($row);
        }

        return $normalized;
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return array<string, mixed>|null
     */
    public function selectRow(string $sql, array $bindings = []): ?array
    {
        $prepared = $this->prepare($sql, $bindings);
        $row = $this->wpdb->get_row($prepared, ARRAY_A);
        $this->assertNoLastError($prepared);

        if ($row === null) {
            return null;
        }

        if (!\is_array($row)) {
            throw new QueryException("query returned a non-array row: {$prepared}");
        }

        return $this->stringKeyedRow($row);
    }

    /** @param list<mixed> $bindings */
    public function selectVar(string $sql, array $bindings = []): mixed
    {
        $prepared = $this->prepare($sql, $bindings);
        $value = $this->wpdb->get_var($prepared);
        $this->assertNoLastError($prepared);

        return $value;
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return list<mixed>
     */
    public function selectCol(string $sql, array $bindings = []): array
    {
        $prepared = $this->prepare($sql, $bindings);
        $values = $this->wpdb->get_col($prepared);
        $this->assertNoLastError($prepared);

        if (!\is_array($values)) {
            throw new QueryException("query did not return a column: {$prepared}");
        }

        return array_values($values);
    }

    /**
     * 書き込み・DDLを実行し、影響行数を返す。トランザクションの実行中は、トランザクションを暗黙に終える文と、
     * トランザクションの状態を直接変える文(START・BEGIN・COMMIT・ROLLBACK・SAVEPOINT・SET autocommit・LOCK TABLE(S)・
     * UNLOCK TABLE(S)・CREATE・ALTER・DROP・TRUNCATE・RENAME・OPTIMIZE・ANALYZE TABLE・REPAIR・CHECK TABLE・FLUSH・
     * GRANT・REVOKEで始まる文)を拒否する。
     *
     * @param list<mixed> $bindings
     *
     * @throws InvalidQueryException トランザクションの実行中に上の文を渡した場合
     */
    public function execute(string $sql, array $bindings = []): int
    {
        if ($this->inTransaction() && preg_match(self::TRANSACTION_BREAKING_SQL, $sql) === 1) {
            throw new InvalidQueryException("execute() cannot run a statement that ends or controls the transaction inside transaction(): {$sql}");
        }

        $prepared = $this->prepare($sql, $bindings);
        $result = $this->wpdb->query($prepared);
        $this->assertNoLastError($prepared);

        if ($result === false) {
            throw new QueryException("query failed: {$prepared}");
        }

        return \is_int($result) ? $result : 0;
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new InvalidQueryException('Connection::insert requires at least one value');
        }

        $table = $this->tableName($table);
        [$values, $formats] = $this->writeValues($data);
        $result = $this->wpdb->insert($table, $values, $formats);
        $this->assertNoLastError("INSERT INTO {$table}");

        if ($result === false) {
            throw new QueryException("insert failed: {$table}");
        }

        return $this->insertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === [] || $where === []) {
            throw new InvalidQueryException('Connection::update requires data and where');
        }

        $this->assertScalarWhere($where);
        $table = $this->tableName($table);
        [$values, $formats] = $this->writeValues($data);
        [$whereValues, $whereFormats] = $this->writeValues($where);
        $result = $this->wpdb->update($table, $values, $whereValues, $formats, $whereFormats);
        $this->assertNoLastError("UPDATE {$table}");

        if ($result === false) {
            throw new QueryException("update failed: {$table}");
        }

        return \is_int($result) ? $result : 0;
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where): int
    {
        if ($where === []) {
            throw new InvalidQueryException('Connection::delete requires where');
        }

        $this->assertScalarWhere($where);
        $table = $this->tableName($table);
        [$whereValues, $whereFormats] = $this->writeValues($where);
        $result = $this->wpdb->delete($table, $whereValues, $whereFormats);
        $this->assertNoLastError("DELETE FROM {$table}");

        if ($result === false) {
            throw new QueryException("delete failed: {$table}");
        }

        return \is_int($result) ? $result : 0;
    }

    /**
     * INSERT … ON DUPLICATE KEY UPDATEを実行し、影響行数(1=挿入、2=更新、0=変化なし)を返す。insert_idはinsertId()で
     * 読む(挿入なら新しいID、更新なら更新した行のID、変化なしなら0)。
     *
     * 更新の句には、挿入する値をもう一度バインドする(`cnt = cnt + %d`)。`VALUES(col)`はMySQLで非推奨(警告1287)、
     * 行の別名(`VALUES (...) AS n`)はMariaDBで構文の誤りのため、どちらも使わない。$valuesのnullはSQLの`NULL`と書き、
     * バインドしない。nullのReplaceは`列 = NULL`にする。Incrementの値はintかfloatに限る。テーブルの定義を持たないため、生成カラムへの書き込みは検査しない
     * (Model::upsert()が検査する)。
     *
     * @param array<string, mixed> $values 挿入する列 => 値
     * @param non-empty-array<string, UpsertUpdate> $update 重複のときに更新する列。キーは$valuesにある列に限る
     *
     * @throws InvalidQueryException $updateが空、$updateのキーが$valuesに無い(キーは大小文字を区別して比べる)、Incrementの
     *                               値がintでもfloatでもない(nullを含む)、不正な識別子・値の場合
     */
    public function upsert(string $table, array $values, array $update): int
    {
        if ($update === []) {
            throw new InvalidQueryException('Connection::upsert requires at least one column to update');
        }

        $columns = [];
        $placeholders = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            $columns[] = Identifier::simple($column);

            if ($value === null) {
                $placeholders[] = 'NULL';

                continue;
            }

            $placeholders[] = self::placeholderFor($value);
            $bindings[] = $value;
        }

        $assignments = [];

        foreach ($update as $column => $mode) {
            if (!\array_key_exists($column, $values)) {
                throw new InvalidQueryException("Connection::upsert cannot update {$column}; the columns to update must be in the values (keys are compared case-sensitively)");
            }

            $value = $values[$column];

            if ($value === null) {
                if ($mode === UpsertUpdate::Increment) {
                    throw new InvalidQueryException("Connection::upsert cannot increment {$column} by null");
                }

                $assignments[] = "{$column} = NULL";

                continue;
            }

            // wpdbはstrictのsql_modeを外すため、`列 + '文字列'`は警告だけで数値に変わり、黙って誤った値になる。数として
            // 足せる値(BackedEnumを値に戻す前のintとfloat)に限る
            if ($mode === UpsertUpdate::Increment && !\is_int($value) && !\is_float($value)) {
                throw new InvalidQueryException("Connection::upsert cannot increment {$column} by a non-numeric value");
            }

            $placeholder = self::placeholderFor($value);
            $assignments[] = match ($mode) {
                UpsertUpdate::Replace => "{$column} = {$placeholder}",
                UpsertUpdate::Increment => "{$column} = {$column} + {$placeholder}",
            };
            $bindings[] = $value;
        }

        return $this->execute(
            'INSERT INTO ' . $this->tableName($table) . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments),
            $bindings,
        );
    }

    /**
     * $callbackを1つのトランザクションで実行し、その戻り値を返す。
     *
     * $callbackが例外を投げればROLLBACKして、その例外をそのまま投げる(包まない)。正常に終わればCOMMITする。自動の
     * 再試行はしない。入れ子は拒否する(SAVEPOINTによる入れ子は作らない)。$lockWaitTimeoutを指定すると、この間だけ
     * innodb_lock_wait_timeoutをその秒数にし、終わった後に元の値に戻す。
     *
     * DBとの接続が切れると、wpdbは再接続して文を新しいセッションで実行し直す(接続を文ごとに切り替えるdb.phpの
     * ドロップインも、別のセッションで文を実行する)。そのセッションはトランザクションの外であるため、実行中に接続が
     * 替わったことを各文の後とCOMMITの前に検出したら、COMMITせずにQueryExceptionを投げる。
     * ROLLBACK・秒数の復元の失敗が先の失敗に重なった場合、後の失敗は捨てて先の失敗を投げる。
     *
     * @template T
     *
     * @param callable(): T $callback
     * @param int<1, 1073741824>|null $lockWaitTimeout ロック待ちの上限の秒数。nullならinnodb_lock_wait_timeoutを変えない
     *
     * @throws InvalidQueryException 入れ子、または範囲の外の秒数
     * @throws DeadlockException|LockWaitTimeoutException|QueryException
     *
     * @return T
     */
    public function transaction(callable $callback, ?int $lockWaitTimeout = null): mixed
    {
        if ($this->inTransaction()) {
            throw new InvalidQueryException('transaction() cannot be nested');
        }

        if ($lockWaitTimeout !== null && ($lockWaitTimeout < 1 || $lockWaitTimeout > self::MAX_LOCK_WAIT_TIMEOUT)) {
            throw new InvalidQueryException('lock wait timeout must be between 1 and ' . self::MAX_LOCK_WAIT_TIMEOUT . " seconds, {$lockWaitTimeout} given");
        }

        $originalTimeout = null;

        if ($lockWaitTimeout !== null) {
            $originalTimeout = $this->sessionLockWaitTimeout();
            $this->runControl(\sprintf('SET SESSION innodb_lock_wait_timeout = %d', $lockWaitTimeout));
        }

        $completed = false;

        try {
            $this->runControl('START TRANSACTION');
            $dbh = $this->dbh();
            self::transactions()[$this->wpdb] = \is_object($dbh) ? $dbh : false;

            try {
                $result = $callback();
            } catch (Throwable $failure) {
                $this->rollBackAfter($failure);
            }

            try {
                $this->assertSameSession();
                $this->runControl('COMMIT');
            } catch (QueryException $failure) {
                $this->rollBackAfter($failure);
            }

            $completed = true;

            return $result;
        } finally {
            unset(self::transactions()[$this->wpdb]);

            if ($originalTimeout !== null) {
                $this->restoreLockWaitTimeout($originalTimeout, $completed);
            }
        }
    }

    /** このwpdb(DBのセッション)でtransaction()の実行中か。 */
    public function inTransaction(): bool
    {
        return isset(self::transactions()[$this->wpdb]);
    }

    public function insertId(): int
    {
        return (int) $this->wpdb->insert_id;
    }

    public function lastError(): string
    {
        return (string) $this->wpdb->last_error;
    }

    /**
     * SQL のプレースホルダを数える。`%` は直後の1文字と組にして左から読む。
     *
     * 番号付き(`%1$s`)・書式付き(`%5s`)・大文字(`%F`)のプレースホルダは拒否する。番号付きは、QueryBuilder が
     * 断片ごとの値を1つのリストへつなぐため、番号が別の断片の値を指してしまう。
     * `%%` の直後のプレースホルダも拒否する。wpdb::prepare() は `%%%s` の `%s` を引用符で囲まずに埋め込む。
     */
    private static function placeholderCount(string $sql): int
    {
        if (preg_match_all('/%(.?)/s', $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            throw new InvalidQueryException('failed to parse SQL placeholders');
        }

        $count = 0;
        $literalPercentEnd = null;

        foreach ($matches as $match) {
            [$sequence, $offset] = $match[0];
            $type = $match[1][0];

            if ($type === '%') {
                $literalPercentEnd = $offset + 2;

                continue;
            }

            if (!\in_array($type, ['d', 'f', 's', 'i'], true)) {
                throw new InvalidQueryException("unsupported percent sequence '{$sequence}' in SQL; use %d, %f, %s, %i, or %% for a literal percent");
            }

            if ($offset === $literalPercentEnd) {
                throw new InvalidQueryException('a placeholder must not directly follow %%');
            }

            ++$count;
        }

        return $count;
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return list<int|float|string>
     */
    private function normalizeBindings(array $bindings): array
    {
        $normalized = [];

        foreach ($bindings as $binding) {
            $normalized[] = self::normalizeValue($binding);
        }

        return $normalized;
    }

    public static function placeholderFor(mixed $value): string
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;

        if (\is_int($value)) {
            return '%d';
        }

        if (\is_float($value)) {
            return '%f';
        }

        if (\is_bool($value)) {
            return '%d';
        }

        if (\is_string($value) || $value instanceof Stringable || $value instanceof DateTimeInterface) {
            return '%s';
        }

        throw new InvalidQueryException('value cannot be bound to SQL placeholder');
    }

    public static function normalizeValue(mixed $value): int|float|string
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;

        if (\is_int($value) || \is_float($value) || \is_string($value)) {
            return $value;
        }

        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        throw new InvalidQueryException('value cannot be converted to SQL binding');
    }

    /**
     * 直前の操作のDBエラーを例外にする。エラー番号が1213ならDeadlockException、1205(ロック待ちの時間切れ)と
     * 3572(MySQLのNOWAITでロックを得られない)ならLockWaitTimeoutException、それ以外はQueryException。
     * トランザクションの実行中に接続が替わっていれば、エラーの有無にかかわらずQueryException。
     */
    private function assertNoLastError(string $sql): void
    {
        $this->assertSameSession();
        $error = $this->lastError();

        if ($error === '') {
            return;
        }

        $message = "database error: {$error}; SQL: {$sql}";

        throw match ($this->lastErrorNumber()) {
            1213 => new DeadlockException($message),
            1205, 3572 => new LockWaitTimeoutException($message),
            default => new QueryException($message),
        };
    }

    /**
     * 直前の操作のエラー番号。wpdbは番号を公開せず、接続(`$dbh`)をprotectedで持つため、リフレクションで読む。接続が
     * mysqliでなければ0とする(テストの代役等。WordPressの無いユニットテストの代役は、プロパティ自体を持たない)。
     */
    private function lastErrorNumber(): int
    {
        $dbh = $this->dbh();

        return $dbh instanceof mysqli ? mysqli_errno($dbh) : 0;
    }

    /**
     * wpdbの接続(`$dbh`)。wpdbはprotectedで持つため、リフレクションで読む。WordPressの無いユニットテストの代役は、
     * プロパティ自体を持たない(null)。
     */
    private function dbh(): mixed
    {
        $reflection = new ReflectionObject($this->wpdb);

        return $reflection->hasProperty('dbh') ? $reflection->getProperty('dbh')->getValue($this->wpdb) : null;
    }

    /**
     * トランザクションの実行中に、wpdbの接続のオブジェクトがSTART TRANSACTIONの直後と替わっていれば(nullになった
     * 場合を含む)QueryExceptionを投げる。wpdbは、接続が切れると新しいmysqliを作って再接続し、文を新しいセッション
     * (トランザクションの外)で実行し直すため、そのままCOMMITすると、再接続の前の書き込みが失われたことに気づかない。
     */
    private function assertSameSession(): void
    {
        $transactions = self::transactions();

        if (!isset($transactions[$this->wpdb])) {
            return;
        }

        $started = $transactions[$this->wpdb];

        if ($started !== false && $this->dbh() !== $started) {
            throw new QueryException('the database connection changed inside transaction() (a reconnection, or a db.php drop-in that switches connections per statement); the transaction cannot continue, and statements run on the new connection may have been committed');
        }
    }

    /** @return WeakMap<wpdb, object|false> */
    private static function transactions(): WeakMap
    {
        /** @var WeakMap<wpdb, object|false> */
        return self::$transactions ??= new WeakMap();
    }

    /**
     * transaction()が出す制御の文(SET SESSION・START TRANSACTION・COMMIT・ROLLBACK)を、execute()の制限を通さずに
     * 実行する。
     */
    private function runControl(string $sql): void
    {
        $result = $this->wpdb->query($sql);
        $this->assertNoLastError($sql);

        if ($result === false) {
            throw new QueryException("query failed: {$sql}");
        }
    }

    /**
     * $failureの後にROLLBACKし、$failureを投げる。ROLLBACKも失敗した場合は、その失敗を捨てて、先に起きた$failureを
     * 投げる。
     *
     * @throws Throwable $failure
     */
    private function rollBackAfter(Throwable $failure): never
    {
        try {
            $this->runControl('ROLLBACK');
        } catch (Throwable) {
            // 先に起きた$failureが原因であり、それを呼び出し側に伝える(ROLLBACKの失敗を記録する手段は持たない)
        }

        throw $failure;
    }

    /** このセッションのinnodb_lock_wait_timeoutの秒数。 */
    private function sessionLockWaitTimeout(): int
    {
        $sql = 'SELECT @@SESSION.innodb_lock_wait_timeout';
        $value = $this->wpdb->get_var($sql);
        $this->assertNoLastError($sql);

        if (!\is_string($value) || !ctype_digit($value)) {
            throw new QueryException('innodb_lock_wait_timeout is not an integer: ' . var_export($value, true));
        }

        return (int) $value;
    }

    /**
     * innodb_lock_wait_timeoutを$secondsに戻す。正常に終わった後($completed。コミット済み)なら失敗を投げ、例外を
     * 投げている途中なら、その例外を優先して戻す失敗を捨てる。
     */
    private function restoreLockWaitTimeout(int $seconds, bool $completed): void
    {
        try {
            $this->runControl(\sprintf('SET SESSION innodb_lock_wait_timeout = %d', $seconds));
        } catch (Throwable $failure) {
            if ($completed) {
                throw $failure;
            }
        }
    }

    /**
     * スタブの wpdb::prepare() は $query に literal-string を要求するが、prefix 由来のテーブル名を含む SQL は
     * literal-string にならないため、リフレクションで呼ぶ。
     *
     * @param list<int|float|string> $bindings
     */
    private function prepareWpdb(string $sql, array $bindings): mixed
    {
        $method = new ReflectionMethod($this->wpdb, 'prepare');

        return $method->invokeArgs($this->wpdb, [$sql, ...$bindings]);
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function stringKeyedRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            if (!\is_string($key)) {
                throw new QueryException('query row contains a non-string key');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * wpdb の insert/update/delete に渡す値を正規化し、format を導出する。BackedEnum はその value にし、bool は 1・0、
     * Stringable は文字列、DateTimeInterface は `Y-m-d H:i:s` の文字列にし、int・float・string はそのまま渡す。format は、
     * int と bool が %d、float が %f、それ以外が %s である(BackedEnum は value の型で決める)。null は wpdb が SQL の NULL
     * (where では IS NULL)にするため、そのまま渡す(format は wpdb が無視する)。
     *
     * @param array<string, mixed> $values
     *
     * @return array{0: array<string, int|float|string|null>, 1: list<string>}
     */
    private function writeValues(array $values): array
    {
        $normalized = [];
        $formats = [];

        foreach ($values as $column => $value) {
            Identifier::simple($column);

            if ($value === null) {
                $normalized[$column] = null;
                $formats[] = '%s';

                continue;
            }

            $normalized[$column] = self::normalizeValue($value);
            $formats[] = self::placeholderFor($value);
        }

        return [$normalized, $formats];
    }

    /** @param array<string, mixed> $where */
    private function assertScalarWhere(array $where): void
    {
        foreach ($where as $column => $value) {
            Identifier::simple($column);

            if (\is_array($value)) {
                throw new InvalidQueryException('Connection where values cannot be arrays');
            }
        }
    }
}
