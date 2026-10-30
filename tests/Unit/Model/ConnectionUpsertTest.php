<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Model;

use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use stdClass;
use Stringable;
use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;
use TMSWP\Core\Model\UpsertUpdate;
use TMSWP\Core\Query\SortOrder;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;

/**
 * Connection::upsert()・Model::upsert()が出すSQLと、引数の誤りの拒否を、wpdbの代役で確かめる。重複したときの更新の
 * 効果・影響行数とinsert_idの実際の値は、実物のDBで確かめる(統合層のUpsertIntegrationTest)。
 */
final class ConnectionUpsertTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Model::useConnection(null);
        parent::tearDown();
    }

    /**
     * INSERT … ON DUPLICATE KEY UPDATEを、挿入する値を更新の句にもう一度バインドする形で出す。Incrementは`列 = 列 + 値`、
     * Replaceは`列 = 値`。VALUES()と行の別名は使わない。
     */
    public function testUpsertRebindsTheValuesInTheUpdateClause(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $wpdb->queueAffectedRows(1);
        $wpdb->queueInsertId(5);

        $rows = $connection->upsert('t', ['k' => 'a', 'cnt' => 2, 'note' => 'x'], ['cnt' => UpsertUpdate::Increment, 'note' => UpsertUpdate::Replace]);

        self::assertSame(1, $rows);
        self::assertSame(5, $connection->insertId());
        self::assertSame(["INSERT INTO wp_t (k, cnt, note) VALUES ('a', 2, 'x') ON DUPLICATE KEY UPDATE cnt = cnt + 2, note = 'x'"], $wpdb->queries());
    }

    /** 値の型ごとのプレースホルダと前処理は、insert()と同じ(boolは0・1、floatは%f、enumはその値、日時は文字列)。 */
    public function testUpsertMapsValuesLikeInsert(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $wpdb->queueAffectedRows(2);
        $wpdb->queueInsertId(3);

        $connection->upsert(
            'stats',
            ['day' => new DateTimeImmutable('2026-10-30 09:15:00'), 'sort' => SortOrder::Desc, 'done' => true, 'ratio' => 0.5, 'label' => '100%'],
            ['done' => UpsertUpdate::Replace, 'ratio' => UpsertUpdate::Increment, 'label' => UpsertUpdate::Replace],
        );

        self::assertSame(
            ["INSERT INTO wp_stats (day, sort, done, ratio, label) VALUES ('2026-10-30 09:15:00', 'DESC', 1, 0.500000, '100%') ON DUPLICATE KEY UPDATE done = 1, ratio = ratio + 0.500000, label = '100%'"],
            $wpdb->queries(),
        );
    }

    /** $valuesのnullはSQLのNULLと書いてバインドしない。nullのReplaceは`列 = NULL`になる。 */
    public function testNullIsWrittenAsSqlNull(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $wpdb->queueAffectedRows(2);
        $wpdb->queueInsertId(9);

        $connection->upsert('t', ['k' => 'a', 'note' => null, 'cnt' => 1], ['note' => UpsertUpdate::Replace, 'cnt' => UpsertUpdate::Increment]);

        self::assertSame(["INSERT INTO wp_t (k, note, cnt) VALUES ('a', NULL, 1) ON DUPLICATE KEY UPDATE note = NULL, cnt = cnt + 1"], $wpdb->queries());
    }

    /**
     * 戻り値はwpdbの影響行数(1=挿入、2=更新、0=変化なし)で、insertId()は挿入なら新しいID、更新なら更新した行のID、
     * 変化なしなら0。代役には、テストが宣言するのと同じく、影響する行の数とIDを入れる(影響行数0ではIDを使わない)。
     */
    public function testReturnsTheAffectedRowsAndInsertIdOfEachOutcome(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);

        foreach ([1, 2, 0] as $rows) {
            $wpdb->queueAffectedRows($rows);
        }

        $wpdb->queueInsertId(4);
        $wpdb->queueInsertId(4);
        $outcomes = [];

        foreach ([1, 1, 0] as $count) {
            $outcomes[] = [$connection->upsert('t', ['k' => 'a', 'cnt' => $count], ['cnt' => UpsertUpdate::Increment]), $connection->insertId()];
        }

        self::assertSame([[1, 4], [2, 4], [0, 0]], $outcomes);
    }

    /** トランザクションの中でも使える(INSERTはトランザクションを終えない)。 */
    public function testUpsertRunsInsideATransaction(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $wpdb->queueAffectedRows(1);
        $wpdb->queueInsertId(1);

        $rows = $connection->transaction(static fn(): int => $connection->upsert('t', ['k' => 'a', 'cnt' => 1], ['cnt' => UpsertUpdate::Increment]));

        self::assertSame(1, $rows);
        self::assertSame([
            'START TRANSACTION',
            "INSERT INTO wp_t (k, cnt) VALUES ('a', 1) ON DUPLICATE KEY UPDATE cnt = cnt + 1",
            'COMMIT',
        ], $wpdb->queries());
    }

    /** @return iterable<string, array{Closure(Connection): int, string}> */
    public static function invalidUpserts(): iterable
    {
        yield 'nullのIncrement' => [
            static fn(Connection $c): int => $c->upsert('t', ['k' => 'a', 'cnt' => null], ['cnt' => UpsertUpdate::Increment]),
            'Connection::upsert cannot increment cnt by null',
        ];
        yield '文字列のIncrement' => [
            static fn(Connection $c): int => $c->upsert('t', ['k' => 'a', 'cnt' => 'abc'], ['cnt' => UpsertUpdate::Increment]),
            'Connection::upsert cannot increment cnt by a non-numeric value',
        ];
        yield '数字だけの文字列のIncrement' => [
            static fn(Connection $c): int => $c->upsert('t', ['k' => 'a', 'cnt' => '3'], ['cnt' => UpsertUpdate::Increment]),
            'Connection::upsert cannot increment cnt by a non-numeric value',
        ];
        yield 'boolのIncrement' => [
            static fn(Connection $c): int => $c->upsert('t', ['k' => 'a', 'cnt' => true], ['cnt' => UpsertUpdate::Increment]),
            'Connection::upsert cannot increment cnt by a non-numeric value',
        ];
        yield '日時のIncrement' => [
            static fn(Connection $c): int => $c->upsert('t', ['k' => 'a', 'cnt' => new DateTimeImmutable('2026-10-30 00:00:00')], ['cnt' => UpsertUpdate::Increment]),
            'Connection::upsert cannot increment cnt by a non-numeric value',
        ];
        yield '文字列のBackedEnumのIncrement' => [
            static fn(Connection $c): int => $c->upsert('t', ['k' => 'a', 'cnt' => SortOrder::Asc], ['cnt' => UpsertUpdate::Increment]),
            'Connection::upsert cannot increment cnt by a non-numeric value',
        ];
        yield 'StringableのIncrement' => [
            static fn(Connection $c): int => $c->upsert('t', ['k' => 'a', 'cnt' => new class implements Stringable {
                public function __toString(): string
                {
                    return '1';
                }
            }], ['cnt' => UpsertUpdate::Increment]),
            'Connection::upsert cannot increment cnt by a non-numeric value',
        ];
        yield '$updateのキーが$valuesに無い' => [
            static fn(Connection $c): int => $c->upsert('t', ['k' => 'a'], ['cnt' => UpsertUpdate::Increment]),
            'Connection::upsert cannot update cnt; the columns to update must be in the values (keys are compared case-sensitively)',
        ];
        yield '$updateのキーの大小文字が$valuesと違う' => [
            static fn(Connection $c): int => $c->upsert('t', ['k' => 'a', 'cnt' => 1], ['CNT' => UpsertUpdate::Increment]),
            'Connection::upsert cannot update CNT; the columns to update must be in the values (keys are compared case-sensitively)',
        ];
        yield '$valuesが空' => [
            static fn(Connection $c): int => $c->upsert('t', [], ['cnt' => UpsertUpdate::Replace]),
            'Connection::upsert cannot update cnt; the columns to update must be in the values (keys are compared case-sensitively)',
        ];
        yield '不正な列名' => [
            static fn(Connection $c): int => $c->upsert('t', ['k; DROP' => 'a', 'cnt' => 1], ['cnt' => UpsertUpdate::Increment]),
            'invalid SQL identifier: k; DROP',
        ];
        yield '不正なテーブル名' => [
            static fn(Connection $c): int => $c->upsert('wp_T', ['k' => 'a', 'cnt' => 1], ['cnt' => UpsertUpdate::Increment]),
            'invalid bare table name: wp_T',
        ];
        yield '写像できない値' => [
            static fn(Connection $c): int => $c->upsert('t', ['k' => 'a', 'cnt' => [1]], ['cnt' => UpsertUpdate::Replace]),
            'value cannot be bound to SQL placeholder',
        ];
        yield '写像できないオブジェクト' => [
            static fn(Connection $c): int => $c->upsert('t', ['k' => new stdClass(), 'cnt' => 1], ['cnt' => UpsertUpdate::Replace]),
            'value cannot be bound to SQL placeholder',
        ];
    }

    /** 引数の誤りはInvalidQueryExceptionになり、DBに何も送らない。 */
    #[DataProvider('invalidUpserts')]
    public function testInvalidArgumentsAreRejectedBeforeAnySql(Closure $upsert, string $message): void
    {
        $wpdb = WpdbTestDouble::create();

        try {
            $upsert(new Connection($wpdb));
            self::fail('expected InvalidQueryException');
        } catch (InvalidQueryException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertSame([], $wpdb->queries());
    }

    /** $updateが空ならInvalidQueryExceptionになり、DBに何も送らない(更新しないなら、insert()を使う)。 */
    public function testEmptyUpdateIsRejected(): void
    {
        $wpdb = WpdbTestDouble::create();

        try {
            // 型の宣言(non-empty-array)に合わない空の配列を、実行時の検査に届けるため、リフレクションで渡す
            (new ReflectionMethod(Connection::class, 'upsert'))->invoke(new Connection($wpdb), 't', ['k' => 'a'], []);
            self::fail('expected InvalidQueryException');
        } catch (InvalidQueryException $exception) {
            self::assertSame('Connection::upsert requires at least one column to update', $exception->getMessage());
        }

        self::assertSame([], $wpdb->queries());
    }

    /** Model::upsert()は、そのModelのテーブルへConnection::upsert()を実行し、影響行数を返す。 */
    public function testModelUpsertTargetsItsTable(): void
    {
        $wpdb = WpdbTestDouble::create();
        Model::useConnection(new Connection($wpdb));
        $wpdb->queueAffectedRows(2);
        $wpdb->queueInsertId(12);

        $rows = ConnectionUpsertTestCounterModel::upsert(['name' => 'views', 'hits' => 3], ['hits' => UpsertUpdate::Increment]);

        self::assertSame(2, $rows);
        self::assertSame(12, ConnectionUpsertTestCounterModel::connection()->insertId());
        self::assertSame(["INSERT INTO wp_counters (name, hits) VALUES ('views', 3) ON DUPLICATE KEY UPDATE hits = hits + 3"], $wpdb->queries());
    }
}

final class ConnectionUpsertTestCounterModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('counters')
            ->id()
            ->string('name', 40)
            ->int('hits', unsigned: true)
            ->unique('name', 'name_unique');
    }
}
