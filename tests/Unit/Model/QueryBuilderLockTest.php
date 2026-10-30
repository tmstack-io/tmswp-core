<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Model;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\QueryBuilder;
use TMSWP\Core\Model\RowLock;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;

/**
 * QueryBuilder::lock()の句の組み立てと、トランザクションの外での実行・count()等との併用の拒否を、wpdbの代役で
 * 確かめる。4つの句が実物のDBで実行できることは、統合層のTransactionIntegrationTestで確かめる。
 */
final class QueryBuilderLockTest extends UnitTestCase
{
    /** RowLockは4つの句だけを持ち、backing valueがそのままSQLの句である(FOR SHAREと、共有ロックのNOWAIT等は持たない)。 */
    public function testRowLockHasTheFourClauses(): void
    {
        self::assertSame([
            'ForUpdate' => 'FOR UPDATE',
            'ForUpdateNoWait' => 'FOR UPDATE NOWAIT',
            'ForUpdateSkipLocked' => 'FOR UPDATE SKIP LOCKED',
            'Shared' => 'LOCK IN SHARE MODE',
        ], array_column(array_map(static fn(RowLock $lock): array => [$lock->name, $lock->value], RowLock::cases()), 1, 0));
    }

    /** 句はOFFSETの後(SELECT文の最後)に付く。toSql()はトランザクションの外でも組み立てる(実行しない)。 */
    #[DataProvider('locks')]
    public function testLockClauseIsAppendedAfterOffset(RowLock $lock): void
    {
        $wpdb = WpdbTestDouble::create();
        $builder = (new QueryBuilder(new Connection($wpdb), 'queue'))
            ->where('completed', false)
            ->orderBy('queued_at')
            ->limit(10)
            ->offset(20)
            ->lock($lock);

        self::assertSame("SELECT * FROM wp_queue WHERE completed = 0 ORDER BY queued_at ASC LIMIT 10 OFFSET 20 {$lock->value}", $builder->toSql());
        self::assertSame([], $wpdb->queries());
    }

    /** @return iterable<string, array{RowLock}> */
    public static function locks(): iterable
    {
        foreach (RowLock::cases() as $lock) {
            yield $lock->name => [$lock];
        }
    }

    /** 2回目のlock()は上書きする。 */
    public function testSecondLockOverwritesTheFirst(): void
    {
        $builder = (new QueryBuilder(new Connection(WpdbTestDouble::create()), 'queue'))
            ->lock(RowLock::Shared)
            ->lock(RowLock::ForUpdateSkipLocked);

        self::assertSame('SELECT * FROM wp_queue FOR UPDATE SKIP LOCKED', $builder->toSql());
    }

    /**
     * 行ロックの句を付けたSELECTは、トランザクションの外ではSQLを実行せずにInvalidQueryExceptionにする。
     *
     * @param Closure(QueryBuilder): mixed $terminal
     */
    #[DataProvider('selectTerminals')]
    public function testLockedSelectOutsideTransactionIsRejected(Closure $terminal): void
    {
        $wpdb = WpdbTestDouble::create();
        $builder = (new QueryBuilder(new Connection($wpdb), 'queue'))->where('id', 1)->lock(RowLock::ForUpdate);

        try {
            $terminal($builder);
            self::fail('InvalidQueryException was not thrown');
        } catch (InvalidQueryException $exception) {
            self::assertSame('lock() requires Connection::transaction(); a row lock outside a transaction is released at the end of the statement', $exception->getMessage());
        }

        self::assertSame([], $wpdb->queries());
    }

    /** @return iterable<string, array{Closure(QueryBuilder): mixed}> */
    public static function selectTerminals(): iterable
    {
        yield 'get' => [static fn(QueryBuilder $builder): array => $builder->get()];
        yield 'first' => [static fn(QueryBuilder $builder): ?object => $builder->first()];
        yield 'value' => [static fn(QueryBuilder $builder): mixed => $builder->value('id')];
        yield 'pluck' => [static fn(QueryBuilder $builder): array => $builder->pluck('id')];
        yield 'exists' => [static fn(QueryBuilder $builder): bool => $builder->exists()];
    }

    /**
     * count()・update()・delete()とlock()の併用は、トランザクションの中でもInvalidQueryExceptionにする。
     *
     * @param Closure(QueryBuilder): mixed $terminal
     */
    #[DataProvider('unlockableTerminals')]
    public function testLockWithCountUpdateOrDeleteIsRejected(Closure $terminal, string $expectedMessage): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);

        try {
            $connection->transaction(static fn(): mixed => $terminal((new QueryBuilder($connection, 'queue'))->where('id', 1)->lock(RowLock::ForUpdate)));
            self::fail('InvalidQueryException was not thrown');
        } catch (InvalidQueryException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
        }

        self::assertSame(['START TRANSACTION', 'ROLLBACK'], $wpdb->queries());
    }

    /** @return iterable<string, array{Closure(QueryBuilder): mixed, string}> */
    public static function unlockableTerminals(): iterable
    {
        yield 'count' => [static fn(QueryBuilder $builder): int => $builder->count(), 'count() cannot be used with lock()'];
        yield 'update' => [static fn(QueryBuilder $builder): int => $builder->update(['done' => 1]), 'update() and delete() cannot be used with lock()'];
        yield 'delete' => [static fn(QueryBuilder $builder): int => $builder->delete(), 'update() and delete() cannot be used with lock()'];
    }

    /** where()のグループの中でlock()を呼ぶと、グループを足す呼び出しでInvalidQueryExceptionにする。 */
    public function testLockInsideWhereGroupIsRejected(): void
    {
        $builder = new QueryBuilder(new Connection(WpdbTestDouble::create()), 'queue');

        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('where group can contain only where clauses');

        $builder->where(static fn(QueryBuilder $group): QueryBuilder => $group->where('id', 1)->lock(RowLock::ForUpdate));
    }

    /** トランザクションの中では、exists()が句を付けたSELECTを実行する。 */
    public function testExistsRunsTheLockedSelectInsideTransaction(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $wpdb->queueVar('1');

        $exists = $connection->transaction(static fn(): bool => (new QueryBuilder($connection, 'queue'))->where('id', 1)->lock(RowLock::Shared)->exists());

        self::assertTrue($exists);
        self::assertSame(['START TRANSACTION', 'SELECT 1 FROM wp_queue WHERE id = 1 LIMIT 1 LOCK IN SHARE MODE', 'COMMIT'], $wpdb->queries());
    }

    /** トランザクションの中では、get()・first()・value()・pluck()が句を付けたSELECTを実行する。 */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRowReadingTerminalsRunTheLockedSelectInsideTransaction(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $wpdb->queueResultSet([['id' => '1'], ['id' => '2']]);
        $wpdb->queueResultSet([['id' => '1']]);
        $wpdb->queueRow(['id' => '1']);
        $wpdb->queueResultSet([['id' => '1']]);

        $results = $connection->transaction(static function () use ($connection): array {
            $builder = (new QueryBuilder($connection, 'queue'))->where('completed', false)->limit(10)->lock(RowLock::ForUpdateSkipLocked);

            return [\count($builder->get()), $builder->first() !== null, $builder->value('id'), $builder->pluck('id')];
        });

        self::assertSame([2, true, '1', ['1']], $results);
        self::assertSame([
            'START TRANSACTION',
            'SELECT * FROM wp_queue WHERE completed = 0 LIMIT 10 FOR UPDATE SKIP LOCKED',
            'SELECT * FROM wp_queue WHERE completed = 0 LIMIT 1 FOR UPDATE SKIP LOCKED',
            'SELECT id FROM wp_queue WHERE completed = 0 LIMIT 1 FOR UPDATE SKIP LOCKED',
            'SELECT id FROM wp_queue WHERE completed = 0 LIMIT 10 FOR UPDATE SKIP LOCKED',
            'COMMIT',
        ], $wpdb->queries());
    }
}
