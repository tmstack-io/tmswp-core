<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Model;

use ArrayObject;
use Closure;
use mysqli;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Throwable;
use TMSWP\Core\Exception\DeadlockException;
use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Exception\LockWaitTimeoutException;
use TMSWP\Core\Exception\QueryException;
use TMSWP\Core\Model\Connection;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;
use wpdb;

/**
 * Connection::transaction()の制御の文の順序・入れ子の拒否・ロック待ちの秒数の戻し方と、トランザクションの実行中の
 * execute()の制限を、wpdbの代役で確かめる。ロールバックの効果・ロックの競合・エラー番号の読み取りは、実物のDBで
 * 確かめる(統合層のTransactionIntegrationTest)。
 */
final class ConnectionTransactionTest extends UnitTestCase
{
    /** 正常に終わればCOMMITし、コールバックの戻り値を返す。実行中だけinTransaction()が真になる。 */
    public function testTransactionCommitsAndReturnsTheCallbackResult(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $wpdb->queueAffectedRows(1);
        $inside = null;

        $result = $connection->transaction(static function () use ($connection, &$inside): string {
            $inside = $connection->inTransaction();
            $connection->execute('UPDATE wp_t SET a = %d WHERE id = %d', [1, 2]);

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertTrue($inside);
        self::assertFalse($connection->inTransaction());
        self::assertFalse($wpdb->inTransaction());
        self::assertSame(['START TRANSACTION', 'UPDATE wp_t SET a = 1 WHERE id = 2', 'COMMIT'], $wpdb->queries());
    }

    /** コールバックが例外を投げればROLLBACKし、同じ例外をそのまま投げる。 */
    public function testTransactionRollsBackAndRethrowsTheSameException(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $failure = new RuntimeException('failed in callback');

        self::assertSame($failure, self::thrown(static fn() => $connection->transaction(static function () use ($failure): never {
            throw $failure;
        })));
        self::assertFalse($connection->inTransaction());
        self::assertSame(['START TRANSACTION', 'ROLLBACK'], $wpdb->queries());
    }

    /** 利用者がコールバックから投げたDeadlockException等も、包まずにそのまま投げる。 */
    public function testLockExceptionsFromTheCallbackAreRethrownAsTheyAre(): void
    {
        $connection = new Connection(WpdbTestDouble::create());

        foreach ([new DeadlockException('deadlock'), new LockWaitTimeoutException('timeout')] as $failure) {
            self::assertSame($failure, self::thrown(static fn() => $connection->transaction(static function () use ($failure): never {
                throw $failure;
            })));
        }
    }

    /** ロック待ちの秒数を指定すると、元の値を読んでから変え、終わった後(正常・例外とも)に元の値に戻す。 */
    public function testLockWaitTimeoutIsSetAndRestored(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $wpdb->query('SET SESSION innodb_lock_wait_timeout = 30');

        $connection->transaction(static fn(): null => null, 5);
        self::thrown(static fn() => $connection->transaction(static function (): never {
            throw new RuntimeException('failed');
        }, 1073741824));

        self::assertSame([
            'SET SESSION innodb_lock_wait_timeout = 30',
            'SELECT @@SESSION.innodb_lock_wait_timeout',
            'SET SESSION innodb_lock_wait_timeout = 5',
            'START TRANSACTION',
            'COMMIT',
            'SET SESSION innodb_lock_wait_timeout = 30',
            'SELECT @@SESSION.innodb_lock_wait_timeout',
            'SET SESSION innodb_lock_wait_timeout = 1073741824',
            'START TRANSACTION',
            'ROLLBACK',
            'SET SESSION innodb_lock_wait_timeout = 30',
        ], $wpdb->queries());
        self::assertSame('30', $wpdb->get_var('SELECT @@SESSION.innodb_lock_wait_timeout'));
    }

    /** 範囲の外の秒数は、SQLを実行する前にInvalidQueryExceptionにする。 */
    #[DataProvider('outOfRangeTimeouts')]
    public function testLockWaitTimeoutOutOfRangeIsRejectedBeforeAnySql(int $seconds): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        /** @var ArrayObject<int, true> $called */
        $called = new ArrayObject();

        // 型の宣言(int<1, 1073741824>)に合わない秒数を、実行時の検査に届けるため、リフレクションで渡す
        $transaction = new ReflectionMethod($connection, 'transaction');
        $failure = self::thrown(static fn() => $transaction->invoke($connection, static function () use ($called): void {
            $called[] = true;
        }, $seconds));

        self::assertInstanceOf(InvalidQueryException::class, $failure);
        self::assertSame("lock wait timeout must be between 1 and 1073741824 seconds, {$seconds} given", $failure->getMessage());
        self::assertCount(0, $called);
        self::assertSame([], $wpdb->queries());
    }

    /** @return iterable<string, array{int}> */
    public static function outOfRangeTimeouts(): iterable
    {
        yield '0' => [0];
        yield '負の数' => [-1];
        yield '上限を超える' => [1073741825];
    }

    /**
     * 入れ子はInvalidQueryExceptionにする。判定はwpdbごとなので、同じwpdbを使う別のConnectionからの入れ子も拒否する。
     * 内側の例外を外のコールバックが投げれば、外のトランザクションはロールバックする。
     */
    public function testNestedTransactionIsRejectedPerWpdb(): void
    {
        $wpdb = WpdbTestDouble::create();
        $outer = new Connection($wpdb);
        $other = new Connection($wpdb);
        $separate = new Connection(WpdbTestDouble::create());
        $observed = new ArrayObject();

        $failure = self::thrown(static fn() => $outer->transaction(static function () use ($other, $separate, $observed): void {
            $observed->exchangeArray([$other->inTransaction(), $separate->inTransaction()]);
            $other->transaction(static fn(): null => null);
        }));

        self::assertInstanceOf(InvalidQueryException::class, $failure);
        self::assertSame('transaction() cannot be nested', $failure->getMessage());
        self::assertSame([true, false], $observed->getArrayCopy());
        self::assertSame(['START TRANSACTION', 'ROLLBACK'], $wpdb->queries());
        self::assertFalse($outer->inTransaction());

        // 終わった後は、同じwpdbで新しいトランザクションを始められる
        self::assertSame(1, $other->transaction(static fn(): int => 1));
    }

    /** トランザクションの実行中のexecute()は、トランザクションを終える文・状態を変える文を、SQLを実行せずに拒否する。 */
    #[DataProvider('transactionBreakingStatements')]
    public function testExecuteRejectsTransactionBreakingStatementsInsideTransaction(string $sql): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);

        $failure = self::thrown(static fn() => $connection->transaction(static fn(): int => $connection->execute($sql)));

        self::assertInstanceOf(InvalidQueryException::class, $failure);
        self::assertSame("execute() cannot run a statement that ends or controls the transaction inside transaction(): {$sql}", $failure->getMessage());
        self::assertSame(['START TRANSACTION', 'ROLLBACK'], $wpdb->queries());
    }

    /** @return iterable<string, array{string}> */
    public static function transactionBreakingStatements(): iterable
    {
        yield 'START' => ['START TRANSACTION'];
        yield 'BEGIN' => ['begin'];
        yield 'COMMIT' => ['  COMMIT'];
        yield 'ROLLBACK' => ['ROLLBACK TO SAVEPOINT a'];
        yield 'SAVEPOINT' => ['SAVEPOINT a'];
        yield 'SET autocommit' => ['SET autocommit = 1'];
        yield 'SET SESSION autocommit' => ['set session autocommit=0'];
        yield 'SET @@autocommit' => ['SET @@autocommit = 1'];
        yield 'SET @@session.autocommit' => ['SET @@session.autocommit = 1'];
        yield 'LOCK TABLES' => ["LOCK\tTABLES wp_t WRITE"];
        yield 'UNLOCK TABLES' => ['unlock tables'];
        yield 'CREATE' => ['CREATE TABLE wp_t (id int)'];
        yield 'ALTER' => ['ALTER TABLE wp_t ADD c int'];
        yield 'DROP' => ['DROP TABLE wp_t'];
        yield 'TRUNCATE' => ['TRUNCATE wp_t'];
        yield 'RENAME' => ['RENAME TABLE wp_a TO wp_b'];
        yield 'プレースホルダ付き' => ['DROP TABLE %i'];
        yield 'LOCK TABLE' => ['LOCK TABLE wp_t READ'];
        yield 'UNLOCK TABLE' => ['unlock table'];
        yield 'OPTIMIZE' => ['OPTIMIZE TABLE wp_t'];
        yield 'ANALYZE' => ['ANALYZE TABLE wp_t'];
        yield 'ANALYZE LOCAL TABLES' => ['analyze local tables wp_t'];
        yield 'ANALYZE NO_WRITE_TO_BINLOG TABLE' => ['ANALYZE NO_WRITE_TO_BINLOG TABLE wp_t'];
        yield 'REPAIR' => ['REPAIR TABLE wp_t'];
        yield 'CHECK TABLE' => ['check  table wp_t'];
        yield 'FLUSH' => ['FLUSH TABLES'];
        yield 'GRANT' => ['GRANT SELECT ON wp_t TO u'];
        yield 'REVOKE' => ['REVOKE SELECT ON wp_t FROM u'];
        yield 'SET LOCAL autocommit' => ['SET LOCAL autocommit = 0'];
        yield 'SET @@local.autocommit' => ['SET @@local.autocommit = 0'];
    }

    /**
     * 先頭の語で判定するため、同じ語で始まらない文と、語の一部が一致するだけの文は通す。トランザクションの外では
     * 制限しない。
     */
    public function testExecuteAcceptsOtherStatementsInsideAndEverythingOutsideTransaction(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $wpdb->queueAffectedRows(1);
        $wpdb->queueAffectedRows(1);

        $connection->transaction(static function () use ($connection): void {
            $connection->execute('UPDATE wp_t SET autocommit_flag = 1 WHERE id = 1');
            $connection->execute('DELETE FROM wp_beginnings WHERE id = 1');
        });
        $connection->execute('CREATE TABLE wp_t (id int)');

        self::assertSame([
            'START TRANSACTION',
            'UPDATE wp_t SET autocommit_flag = 1 WHERE id = 1',
            'DELETE FROM wp_beginnings WHERE id = 1',
            'COMMIT',
            'CREATE TABLE wp_t (id int)',
        ], $wpdb->queries());
    }

    /** COMMITとROLLBACKの両方が失敗したら、ROLLBACKの失敗を捨てて、COMMITの失敗を投げる。 */
    public function testCommitFailureWinsOverRollbackFailure(): void
    {
        $queries = new ArrayObject();
        $connection = new Connection(self::failingWpdb(['COMMIT' => 'commit failed', 'ROLLBACK' => 'rollback failed'], $queries));

        $failure = self::thrown(static fn() => $connection->transaction(static fn(): string => 'done'));

        self::assertSame('database error: commit failed; SQL: COMMIT', $failure->getMessage());
        self::assertSame(['START TRANSACTION', 'COMMIT', 'ROLLBACK'], $queries->getArrayCopy());
        self::assertFalse($connection->inTransaction());
    }

    /** COMMITが失敗しても、ロック待ちの秒数は元に戻る。 */
    public function testCommitFailureStillRestoresTheLockWaitTimeout(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $wpdb->query('SET SESSION innodb_lock_wait_timeout = 30');

        $failure = self::thrown(static fn() => $connection->transaction(static function () use ($wpdb): void {
            $wpdb->failNextWrite('commit failed');
        }, 5));

        self::assertSame('database error: commit failed; SQL: COMMIT', $failure->getMessage());
        self::assertSame(['COMMIT', 'ROLLBACK', 'SET SESSION innodb_lock_wait_timeout = 30'], \array_slice($wpdb->queries(), -3));
        self::assertSame('30', $wpdb->get_var('SELECT @@SESSION.innodb_lock_wait_timeout'));
    }

    /** COMMITと秒数の復元の両方が失敗したら、復元の失敗を捨てて、COMMITの失敗を投げる。 */
    public function testCommitFailureWinsOverRestoreFailure(): void
    {
        $queries = new ArrayObject();
        $connection = new Connection(self::failingWpdb(['COMMIT' => 'commit failed', 'SET SESSION innodb_lock_wait_timeout = 50' => 'restore failed'], $queries));

        $failure = self::thrown(static fn() => $connection->transaction(static fn(): string => 'done', 4));

        self::assertSame('database error: commit failed; SQL: COMMIT', $failure->getMessage());
        self::assertSame(['COMMIT', 'ROLLBACK', 'SET SESSION innodb_lock_wait_timeout = 50'], \array_slice($queries->getArrayCopy(), -3));
    }

    /**
     * トランザクションの実行中にwpdbの接続のオブジェクトが替わった(再接続した・nullになった)ら、その文の後か、
     * COMMITの前にQueryExceptionを投げ、COMMITを確定させない。
     *
     * @param 'callback'|'COMMIT' $when 接続が替わる時点。'callback'はコールバックの始め、'COMMIT'はCOMMITの実行中
     * @param Closure(Connection): mixed $callback 接続が替わった後にコールバックの中で行うこと
     * @param list<string> $expectedQueries
     */
    #[DataProvider('reconnections')]
    public function testReconnectionInsideTransactionIsNotCommitted(string $when, bool $toNull, Closure $callback, array $expectedQueries): void
    {
        $queries = new ArrayObject();
        $reconnect = static function (wpdb $wpdb) use ($toNull): void {
            (new ReflectionProperty($wpdb, 'dbh'))->setValue($wpdb, $toNull ? null : new stdClass());
        };
        $wpdb = self::failingWpdb([], $queries, $when === 'COMMIT' ? ['COMMIT' => $reconnect] : []);
        (new ReflectionProperty($wpdb, 'dbh'))->setValue($wpdb, new stdClass());
        $connection = new Connection($wpdb);

        $failure = self::thrown(static fn() => $connection->transaction(static function () use ($when, $reconnect, $wpdb, $connection, $callback): mixed {
            if ($when === 'callback') {
                $reconnect($wpdb);
            }

            return $callback($connection);
        }));

        self::assertSame(QueryException::class, $failure::class);
        self::assertSame('the database connection changed inside transaction() (a reconnection, or a db.php drop-in that switches connections per statement); the transaction cannot continue, and statements run on the new connection may have been committed', $failure->getMessage());
        self::assertSame($expectedQueries, $queries->getArrayCopy());
        self::assertFalse($connection->inTransaction());
    }

    /** @return iterable<string, array{'callback'|'COMMIT', bool, Closure(Connection): mixed, list<string>}> */
    public static function reconnections(): iterable
    {
        $update = static fn(Connection $connection): int => $connection->execute('UPDATE wp_t SET a = 1 WHERE id = 1');
        $nothing = static fn(Connection $connection): string => 'done';

        yield '次の文の後' => ['callback', false, $update, ['START TRANSACTION', 'UPDATE wp_t SET a = 1 WHERE id = 1', 'ROLLBACK']];
        yield 'COMMITの前' => ['callback', false, $nothing, ['START TRANSACTION', 'ROLLBACK']];
        yield 'COMMITの実行中(COMMITは出るが確定させない)' => ['COMMIT', false, $nothing, ['START TRANSACTION', 'COMMIT', 'ROLLBACK']];
        yield 'nullになる(再接続の失敗)' => ['callback', true, $update, ['START TRANSACTION', 'UPDATE wp_t SET a = 1 WHERE id = 1', 'ROLLBACK']];
    }

    /** 接続が替わらなければ、照合はトランザクションを妨げない(同じオブジェクトのまま)。 */
    public function testSameConnectionObjectCommits(): void
    {
        $queries = new ArrayObject();
        $wpdb = self::failingWpdb([], $queries);
        (new ReflectionProperty($wpdb, 'dbh'))->setValue($wpdb, new stdClass());
        $connection = new Connection($wpdb);

        self::assertSame('done', $connection->transaction(static function () use ($connection): string {
            $connection->execute('UPDATE wp_t SET a = 1 WHERE id = 1');

            return 'done';
        }));
        self::assertSame(['START TRANSACTION', 'UPDATE wp_t SET a = 1 WHERE id = 1', 'COMMIT'], $queries->getArrayCopy());
    }

    /** ANALYZEは、テーブルの統計を更新する形(ANALYZE [NO_WRITE_TO_BINLOG|LOCAL] TABLE)だけを拒否し、ANALYZE SELECTは通す。 */
    public function testAnalyzeSelectIsAcceptedInsideTransaction(): void
    {
        $queries = new ArrayObject();
        $connection = new Connection(self::failingWpdb([], $queries));

        $connection->transaction(static fn(): int => $connection->execute('ANALYZE SELECT * FROM wp_t'));

        self::assertSame(['START TRANSACTION', 'ANALYZE SELECT * FROM wp_t', 'COMMIT'], $queries->getArrayCopy());
    }

    /** COMMITが失敗したら、ROLLBACKを試みてから、COMMITの失敗をQueryExceptionで投げる。 */
    public function testCommitFailureRollsBackAndThrows(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);

        $failure = self::thrown(static fn() => $connection->transaction(static function () use ($wpdb): void {
            $wpdb->failNextWrite('commit failed');
        }));

        self::assertSame(QueryException::class, $failure::class);
        self::assertSame('database error: commit failed; SQL: COMMIT', $failure->getMessage());
        self::assertSame(['START TRANSACTION', 'COMMIT', 'ROLLBACK'], $wpdb->queries());
        self::assertFalse($connection->inTransaction());
    }

    /** ROLLBACKも失敗した場合は、先に起きたコールバックの例外を投げる。 */
    public function testRollbackFailureKeepsTheCallbackException(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $failure = new RuntimeException('failed in callback');

        self::assertSame($failure, self::thrown(static fn() => $connection->transaction(static function () use ($wpdb, $failure): never {
            $wpdb->failNextWrite('rollback failed');

            throw $failure;
        })));
        self::assertSame(['START TRANSACTION', 'ROLLBACK'], $wpdb->queries());
        self::assertFalse($connection->inTransaction());
    }

    /** START TRANSACTIONが失敗したら、コールバックを呼ばずにQueryExceptionを投げ、ロック待ちの秒数を元に戻す。 */
    public function testStartFailureRestoresTheLockWaitTimeout(): void
    {
        $queries = new ArrayObject();
        $connection = new Connection(self::failingWpdb(['START TRANSACTION' => 'start failed'], $queries));
        /** @var ArrayObject<int, true> $called */
        $called = new ArrayObject();

        $failure = self::thrown(static fn() => $connection->transaction(static function () use ($called): void {
            $called[] = true;
        }, 3));

        self::assertSame('database error: start failed; SQL: START TRANSACTION', $failure->getMessage());
        self::assertCount(0, $called);
        self::assertFalse($connection->inTransaction());
        self::assertSame([
            'SELECT @@SESSION.innodb_lock_wait_timeout',
            'SET SESSION innodb_lock_wait_timeout = 3',
            'START TRANSACTION',
            'SET SESSION innodb_lock_wait_timeout = 50',
        ], $queries->getArrayCopy());
    }

    /**
     * 秒数を元に戻せなかった場合、正常に終わった後ならQueryExceptionを投げ、例外を投げている途中ならその例外を優先する。
     *
     * @param Closure(): mixed $callback
     */
    #[DataProvider('restoreFailures')]
    public function testRestoreFailureIsThrownOnlyAfterSuccess(Closure $callback, ?string $expectedMessage): void
    {
        $connection = new Connection(self::failingWpdb(['SET SESSION innodb_lock_wait_timeout = 50' => 'restore failed'], new ArrayObject()));

        $failure = self::thrown(static fn() => $connection->transaction($callback, 2));

        self::assertSame($expectedMessage ?? 'failed in callback', $failure->getMessage());
        self::assertSame($expectedMessage === null ? RuntimeException::class : QueryException::class, $failure::class);
        self::assertFalse($connection->inTransaction());
    }

    /** @return iterable<string, array{Closure(): mixed, string|null}> */
    public static function restoreFailures(): iterable
    {
        yield '正常に終わった後' => [static fn(): string => 'done', 'database error: restore failed; SQL: SET SESSION innodb_lock_wait_timeout = 50'];
        yield '例外を投げている途中' => [static function (): never {
            throw new RuntimeException('failed in callback');
        }, null];
    }

    /** 代役はmysqliの接続を持たないため、エラー番号は0とみなし、DBのエラーは常にQueryExceptionになる。 */
    public function testErrorsWithoutMysqliConnectionAreQueryExceptions(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $wpdb->failNextWrite('Deadlock found when trying to get lock; try restarting transaction');

        $failure = self::thrown(static fn() => $connection->execute('DELETE FROM wp_t WHERE id = 1'));

        self::assertSame(QueryException::class, $failure::class);
    }

    /**
     * 指定した文だけを失敗させるwpdb。代役のfailNextWrite()は次の書き込みしか失敗させられず、COMMITの後の文や、
     * SET SESSIONの後のSTART TRANSACTIONを狙えないため、そのテストに限って使う。get_var()はロック待ちの秒数の'50'を
     * 返す。ユニット層のwpdbの型は代役を読み込んだときに宣言されるため、代役を読み込んでから無名クラスで作る。
     *
     * @param array<string, string> $failures 失敗させる文 => last_errorに入れる文言
     * @param ArrayObject<int, string> $queries 実行した文を入れる
     * @param array<string, Closure(wpdb): void> $onQuery その文を実行したときに呼ぶ処理(接続の差し替え等)
     */
    private static function failingWpdb(array $failures, ArrayObject $queries, array $onQuery = []): wpdb
    {
        self::assertTrue(class_exists(WpdbTestDouble::class));

        // ユニット層のwpdbはコンストラクタを持たず、引数は使われない(型の宣言の実wpdbに合わせて渡す)
        $wpdb = new class ('', '', '', '') extends wpdb {
            /** @var string */
            public $last_error = '';

            /** @var mysqli|false|null 接続。テストがリフレクションで、mysqliでないオブジェクトを入れる */
            protected $dbh;

            /** @var array<string, string> */
            public array $failures = [];

            /** @var ArrayObject<int, string> */
            public ArrayObject $log;

            /** @var array<string, Closure(wpdb): void> */
            public array $onQuery = [];

            /** @param mixed $query */
            public function query($query): int|false
            {
                $sql = self::sql($query);
                $this->log[] = $sql;
                if (isset($this->onQuery[$sql])) {
                    ($this->onQuery[$sql])($this);
                }

                $this->last_error = $this->failures[$sql] ?? '';

                return $this->last_error === '' ? 0 : false;
            }

            /**
             * @param mixed $query
             * @param mixed $x
             * @param mixed $y
             */
            public function get_var($query = null, $x = 0, $y = 0): string
            {
                $this->log[] = self::sql($query);
                $this->last_error = '';

                return '50';
            }

            private static function sql(mixed $query): string
            {
                if (!\is_string($query)) {
                    throw new RuntimeException('query must be string');
                }

                return $query;
            }
        };
        $wpdb->failures = $failures;
        $wpdb->log = $queries;
        $wpdb->onQuery = $onQuery;

        return $wpdb;
    }

    /** @param Closure(): mixed $call */
    private static function thrown(Closure $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('no exception was thrown');
    }
}
