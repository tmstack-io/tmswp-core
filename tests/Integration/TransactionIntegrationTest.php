<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use Closure;
use mysqli;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use TMSWP\Core\Exception\DeadlockException;
use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Exception\LockWaitTimeoutException;
use TMSWP\Core\Exception\QueryException;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\QueryBuilder;
use TMSWP\Core\Model\RowLock;
use TMSWP\Testing\IntegrationTestCase;
use wpdb;

/**
 * Connection::transaction()と行ロックの句(QueryBuilder::lock())を、実物のDBで確かめる。CIでは、wp-envのMariaDBと、
 * MariaDB 10.11・MySQL 8.4・9.7のすべてで走る。
 *
 * WordPressのテストライブラリは、テストごとに`SET autocommit = 0`と`START TRANSACTION`でトランザクションを張り、
 * テーブルを一時テーブルに書き換える。一時テーブルはほかの接続から見えず、ロックの競合を起こせないため、
 * useRealTables()で実際のテーブルを作る。ロックの競合とコミットの確認は、別に作った2つ目のwpdbの接続(自動コミット)
 * で行う。2つ目の接続はコミットされた行だけを読むため、1つ目の接続のコミットとロールバックを確かめられる。
 */
final class TransactionIntegrationTest extends IntegrationTestCase
{
    private const TABLE = 'tmswp_it_transactions';

    private Connection $connection;

    /** このテストで作った2つ目の接続。tear_downで、テーブルを消す前にロールバックして閉じる。 */
    private ?wpdb $second = null;

    /** このテストの前のinnodb_lock_wait_timeout。テストが変えたら、tear_downで戻す。 */
    private string $originalLockWaitTimeout = '';

    /** このテストの前のwpdbのsuppress_errorsの値。tear_downで戻す。 */
    private bool $suppressedErrors = false;

    /**
     * 時間切れ・デッドロック等のDBのエラーは、テストで起こすものなので、wpdbのエラーの表示とログを止める(例外の判定に
     * 使うlast_errorとエラー番号は変わらない)。
     */
    public function set_up(): void
    {
        parent::set_up();
        $this->suppressedErrors = self::wpdb()->suppress_errors();
        $this->useRealTables(self::TABLE);
        $this->connection = new Connection(self::wpdb());
        $this->originalLockWaitTimeout = self::stringValue($this->connection->selectVar('SELECT @@SESSION.innodb_lock_wait_timeout'));
        $this->connection->execute('CREATE TABLE ' . self::table() . ' (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
name varchar(20) NOT NULL,
qty int(11) NOT NULL DEFAULT 0,
PRIMARY KEY  (id)
) ENGINE=InnoDB');
    }

    /**
     * 2つ目の接続のロックを解いて閉じてから(閉じないと、テーブルの削除がメタデータのロックを待つ)、ロック待ちの秒数を
     * 戻し、実際のテーブルを消す。
     */
    public function tear_down(): void
    {
        $second = $this->second;
        $this->second = null;

        try {
            if ($second !== null) {
                $second->query('ROLLBACK');
                $second->close();
            }

            if ($this->originalLockWaitTimeout !== '') {
                self::wpdb()->query('SET SESSION innodb_lock_wait_timeout = ' . (int) $this->originalLockWaitTimeout);
            }
        } finally {
            self::wpdb()->suppress_errors($this->suppressedErrors);
            parent::tear_down();
        }
    }

    /** コールバックが例外を投げると、その中のINSERT・UPDATEがすべて取り消され、同じ例外がそのまま投げられる。 */
    public function testExceptionRollsBackEveryInsertAndUpdate(): void
    {
        [$id] = $this->seed('before');
        $failure = new RuntimeException('stop');

        $thrown = self::thrown(fn() => $this->connection->transaction(function () use ($id, $failure): never {
            $this->connection->insert(self::TABLE, ['name' => 'inserted', 'qty' => 1]);
            $this->connection->update(self::TABLE, ['qty' => 5], ['id' => $id]);
            $this->builder()->where('id', $id)->update(['name' => 'renamed']);
            $this->connection->execute('INSERT INTO ' . self::table() . ' (name) VALUES (%s)', ['executed']);
            self::assertCount(3, $this->builder()->get());

            throw $failure;
        }));

        self::assertSame($failure, $thrown);
        self::assertFalse($this->connection->inTransaction());
        self::assertSame([['before', '0']], $this->committedRows());
        self::assertSame([['before', '0']], $this->ownRows());
    }

    /** 正常に終わればコミットされ(2つ目の接続から見える)、コールバックの戻り値が返る。 */
    public function testSuccessCommitsAndReturnsTheCallbackResult(): void
    {
        [$id] = $this->seed('before');

        $result = $this->connection->transaction(function () use ($id): string {
            $this->connection->insert(self::TABLE, ['name' => 'inserted', 'qty' => 1]);
            $this->connection->update(self::TABLE, ['qty' => 5], ['id' => $id]);

            return 'committed';
        });

        self::assertSame('committed', $result);
        self::assertSame([['before', '5'], ['inserted', '1']], $this->committedRows());
    }

    /**
     * 2つ目の接続がロックした行を1つ目の接続でロックすると、指定した秒数でLockWaitTimeoutException(1205)になる。
     * 終わった後(例外・正常とも)、innodb_lock_wait_timeoutは元の値に戻っている。
     */
    public function testLockContentionTimesOutInTheGivenSecondsAndRestoresTheTimeout(): void
    {
        [$id] = $this->seed('locked');
        $this->connection->execute('SET SESSION innodb_lock_wait_timeout = 37');
        $second = $this->secondConnection();
        self::assertSame(0, $second->query('START TRANSACTION'));
        self::assertSame(1, $second->query('SELECT id FROM ' . self::table() . " WHERE id = {$id} FOR UPDATE"));
        $observedTimeout = null;
        $started = microtime(true);

        $thrown = self::thrown(function () use ($id, &$observedTimeout): ?object {
            return $this->connection->transaction(function () use ($id, &$observedTimeout): ?object {
                $observedTimeout = $this->connection->selectVar('SELECT @@SESSION.innodb_lock_wait_timeout');

                return $this->builder()->where('id', $id)->lock(RowLock::ForUpdate)->first();
            }, 1);
        });
        $elapsed = microtime(true) - $started;

        self::assertInstanceOf(LockWaitTimeoutException::class, $thrown, $thrown->getMessage());
        self::assertSame('1', $observedTimeout);
        self::assertGreaterThanOrEqual(0.9, $elapsed);
        self::assertLessThan(10.0, $elapsed);
        self::assertSame('37', $this->connection->selectVar('SELECT @@SESSION.innodb_lock_wait_timeout'));

        self::assertSame(2, $this->connection->transaction(static fn(): int => 2, 3));
        self::assertSame('37', $this->connection->selectVar('SELECT @@SESSION.innodb_lock_wait_timeout'));
    }

    /** NOWAITで行のロックを得られないときは、待たずにLockWaitTimeoutException(MariaDBは1205、MySQLは3572)になる。 */
    public function testNoWaitThatCannotGetTheLockIsLockWaitTimeout(): void
    {
        [$id] = $this->seed('locked');
        $second = $this->secondConnection();
        $second->query('START TRANSACTION');
        $second->query('SELECT id FROM ' . self::table() . " WHERE id = {$id} FOR UPDATE");
        $started = microtime(true);

        $thrown = self::thrown(fn() => $this->connection->transaction(
            fn(): ?object => $this->builder()->where('id', $id)->lock(RowLock::ForUpdateNoWait)->first(),
            30,
        ));

        self::assertInstanceOf(LockWaitTimeoutException::class, $thrown, $thrown->getMessage());
        self::assertLessThan(5.0, microtime(true) - $started);
    }

    /** SKIP LOCKEDは、ほかの接続がロックしている行を飛ばす。 */
    public function testSkipLockedSkipsRowsLockedByAnotherConnection(): void
    {
        [$locked, $free] = $this->seed('locked', 'free');
        $second = $this->secondConnection();
        $second->query('START TRANSACTION');
        $second->query('SELECT id FROM ' . self::table() . " WHERE id = {$locked} FOR UPDATE");

        $ids = $this->connection->transaction(fn(): array => $this->builder()->orderBy('id')->limit(10)->lock(RowLock::ForUpdateSkipLocked)->pluck('id'));

        self::assertSame([(string) $free], $ids);
    }

    /** 4つの句は、toSql()がOFFSETの後に句を出し、すべてのDBで実行できる。終端のメソッドのすべてに効く。 */
    public function testEveryLockClauseRunsOnTheDatabase(): void
    {
        $this->seed('a', 'b');

        foreach (RowLock::cases() as $lock) {
            $builder = $this->builder()->where('id', '>', 0)->orderBy('id')->limit(10)->offset(0)->lock($lock);

            self::assertStringEndsWith(" ORDER BY id ASC LIMIT 10 OFFSET 0 {$lock->value}", $builder->toSql());
            self::assertSame(
                [2, true, 'a', ['a', 'b'], true],
                $this->connection->transaction(static fn(): array => [
                    \count($builder->get()),
                    $builder->first() !== null,
                    $builder->value('name'),
                    $builder->pluck('name'),
                    $builder->exists(),
                ]),
                $lock->name,
            );
        }
    }

    /** トランザクションの外で行ロックを使うと、SQLを実行せずにInvalidQueryExceptionになる。 */
    public function testLockOutsideTransactionIsRejected(): void
    {
        $this->seed('a');
        $queries = [];
        $record = static function (string $query) use (&$queries): string {
            $queries[] = $query;

            return $query;
        };
        add_filter('query', $record);

        try {
            $thrown = self::thrown(fn() => $this->builder()->lock(RowLock::ForUpdate)->get());
        } finally {
            remove_filter('query', $record);
        }

        self::assertInstanceOf(InvalidQueryException::class, $thrown);
        self::assertSame([], $queries);
    }

    /**
     * 入れ子はInvalidQueryExceptionになり、内側はSTART TRANSACTION(外のトランザクションを暗黙にコミットする)を
     * 出さない。外のコールバックがその後に例外を投げれば、内側を呼ぶ前の書き込みも取り消される。
     */
    public function testNestedTransactionIsRejectedWithoutCommittingTheOuterOne(): void
    {
        $this->seed('before');
        $nested = null;

        $outer = self::thrown(function () use (&$nested): void {
            $this->connection->transaction(function () use (&$nested): never {
                $this->connection->insert(self::TABLE, ['name' => 'outer']);
                $nested = self::thrown(static fn() => (new Connection(self::wpdb()))->transaction(static fn(): null => null));

                throw new RuntimeException('stop');
            });
        });

        self::assertSame('stop', $outer->getMessage());
        self::assertInstanceOf(InvalidQueryException::class, $nested);
        self::assertSame([['before', '0']], $this->committedRows());
    }

    /**
     * トランザクションの中のexecute()のDDL・トランザクションの制御の文はInvalidQueryExceptionになり、実行されない
     * (DDLの暗黙のコミットが起きない)。
     */
    public function testTransactionBreakingStatementsAreRejectedInsideTransaction(): void
    {
        $this->seed('before');
        $rejected = [];

        $outer = self::thrown(function () use (&$rejected): void {
            $this->connection->transaction(function () use (&$rejected): never {
                $this->connection->insert(self::TABLE, ['name' => 'inside']);

                foreach (['CREATE TABLE ' . self::table() . '_x (id int)', 'COMMIT', 'SET autocommit = 1', 'TRUNCATE ' . self::table()] as $sql) {
                    $rejected[] = self::thrown(fn() => $this->connection->execute($sql))::class;
                }

                throw new RuntimeException('stop');
            });
        });

        self::assertSame('stop', $outer->getMessage());
        self::assertSame(array_fill(0, 4, InvalidQueryException::class), $rejected);
        self::assertSame([['before', '0']], $this->committedRows());
    }

    /**
     * 2つの接続が互いのロックを待つと、DBが一方を取り消し、取り消された側はDeadlockException(1213)になる。2つ目の
     * 接続に多くの行を書かせ、DBが小さいトランザクション(1つ目の接続)を取り消すようにする。2つ目の接続の待つ文は、
     * 処理を止めないよう非同期で送る。
     */
    public function testDeadlockIsDeadlockException(): void
    {
        [$a, $b] = $this->seed('a', 'b');
        $second = $this->secondConnection();
        $dbh = (new ReflectionProperty($second, 'dbh'))->getValue($second);
        self::assertInstanceOf(mysqli::class, $dbh);
        $second->query('SET SESSION innodb_lock_wait_timeout = 10');
        $second->query('START TRANSACTION');
        self::assertSame(1, $second->query('UPDATE ' . self::table() . " SET qty = qty + 1 WHERE id = {$b}"));
        self::assertSame(20, $second->query('INSERT INTO ' . self::table() . ' (name) VALUES ' . implode(', ', array_fill(0, 20, "('weight')"))));
        $pending = false;

        try {
            $thrown = self::thrown(function () use ($a, $b, $dbh, &$pending): void {
                $this->connection->transaction(function () use ($a, $b, $dbh, &$pending): void {
                    $this->builder()->where('id', $a)->lock(RowLock::ForUpdate)->first();
                    self::assertTrue(mysqli_query($dbh, 'SELECT id FROM ' . self::table() . " WHERE id = {$a} FOR UPDATE", MYSQLI_ASYNC));
                    $pending = true;
                    $this->waitUntilWaitingForLock($dbh->thread_id);
                    $this->builder()->where('id', $b)->lock(RowLock::ForUpdate)->first();
                }, 10);
            });
        } finally {
            if ($pending) {
                // 1つ目の接続が取り消された後、2つ目の接続の待っていた文が終わる
                self::assertNotFalse(mysqli_reap_async_query($dbh), mysqli_error($dbh));
            }
        }

        self::assertInstanceOf(DeadlockException::class, $thrown, $thrown->getMessage());
        self::assertFalse($this->connection->inTransaction());
    }

    /**
     * トランザクションの実行中に接続が切れると、wpdbは再接続して文を新しいセッション(自動コミット)で実行し直す。
     * transaction()は接続が替わったことを検出してQueryExceptionを投げ、COMMITしない。再接続の前の書き込みは失われ、
     * 再接続の後の文は確定している(例外のメッセージのとおり)。接続は2つ目の接続からKILLで切る。
     */
    public function testReconnectionInsideTransactionIsDetectedWithoutCommit(): void
    {
        $this->seed('before');
        $threadId = self::stringValue($this->connection->selectVar('SELECT CONNECTION_ID()'));
        $second = $this->secondConnection();

        $thrown = self::thrown(fn() => $this->connection->transaction(function () use ($second, $threadId): string {
            $this->connection->insert(self::TABLE, ['name' => 'lost']);
            self::assertSame(0, $second->query("KILL {$threadId}"));
            $this->connection->insert(self::TABLE, ['name' => 'reconnected']);

            return 'not committed';
        }));

        self::assertSame(QueryException::class, $thrown::class);
        self::assertSame('the database connection changed inside transaction() (a reconnection, or a db.php drop-in that switches connections per statement); the transaction cannot continue, and statements run on the new connection may have been committed', $thrown->getMessage());
        self::assertFalse($this->connection->inTransaction());
        self::assertNotSame($threadId, $this->connection->selectVar('SELECT CONNECTION_ID()'));
        self::assertSame([['before', '0'], ['reconnected', '0']], $this->committedRows());
    }

    /** ロックに関係しないDBのエラーは、派生ではないQueryExceptionのままである(エラー番号の読み取りの確認)。 */
    public function testOtherDatabaseErrorsStayQueryException(): void
    {
        $thrown = self::thrown(fn() => $this->connection->selectVar('SELECT id FROM ' . self::table() . '_missing'));

        self::assertSame(QueryException::class, $thrown::class);
    }

    /**
     * 行を入れてコミットし、IDを返す。1つ目の接続は自動コミットでない(テストライブラリが外す)ため、トランザクションで
     * コミットして、2つ目の接続から見えるようにし、行のロックを残さない。
     *
     * @return list<int>
     */
    private function seed(string ...$names): array
    {
        return $this->connection->transaction(function () use ($names): array {
            $ids = [];

            foreach ($names as $name) {
                $ids[] = $this->connection->insert(self::TABLE, ['name' => $name]);
            }

            return $ids;
        });
    }

    private function builder(): QueryBuilder
    {
        return new QueryBuilder($this->connection, self::TABLE);
    }

    /**
     * 2つ目の接続(自動コミット)から読んだ、コミットされた行の名前と数。
     *
     * @return list<list<mixed>>
     */
    private function committedRows(): array
    {
        return self::rows($this->second ?? $this->secondConnection());
    }

    /**
     * 1つ目の接続から読んだ行の名前と数。
     *
     * @return list<list<mixed>>
     */
    private function ownRows(): array
    {
        return self::rows(self::wpdb());
    }

    /** @return list<list<mixed>> */
    private static function rows(wpdb $db): array
    {
        $rows = $db->get_results('SELECT name, qty FROM ' . self::table() . ' ORDER BY id', ARRAY_N);
        self::assertSame('', $db->last_error);
        self::assertIsArray($rows);

        return array_values(array_map(static fn(mixed $row): array => \is_array($row) ? array_values($row) : [], $rows));
    }

    /** テストの接続と同じ設定で、別のセッションの接続を作る。 */
    private function secondConnection(): wpdb
    {
        $settings = [];

        foreach (['DB_USER', 'DB_PASSWORD', 'DB_NAME', 'DB_HOST'] as $constant) {
            $settings[] = self::stringValue(\constant($constant));
        }

        $second = new wpdb(...$settings);
        self::assertSame('', $second->last_error);
        $this->second = $second;

        return $second;
    }

    /** 2つ目の接続(スレッドのID)が行のロックを待つ状態になるまで、最大5秒待つ。 */
    private function waitUntilWaitingForLock(int $threadId): void
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $waiting = $this->connection->selectVar(
                "SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = %d AND trx_state = 'LOCK WAIT'",
                [$threadId],
            );

            if ($waiting === '1') {
                return;
            }

            usleep(50000);
        }

        self::fail("the second connection did not start waiting for the row lock: thread {$threadId}");
    }

    private static function table(): string
    {
        return self::wpdb()->prefix . self::TABLE;
    }

    private static function stringValue(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * $callが投げた例外を返す。テストのアサーションの失敗は、確かめる対象の例外ではないため、そのまま投げる。
     *
     * @param Closure(): mixed $call
     */
    private static function thrown(Closure $call): Throwable
    {
        try {
            $call();
        } catch (AssertionFailedError $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('no exception was thrown');
    }
}
