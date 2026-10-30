<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Model;

use Brain\Monkey\Functions;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionClass;
use ReflectionMethod;
use stdClass;
use Stringable;
use TMSWP\Core\Attribute\AsModel;
use TMSWP\Core\Exception\HydrationException;
use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Exception\KernelException;
use TMSWP\Core\Exception\QueryException;
use TMSWP\Core\Exception\SchemaException;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\MigrationStatus;
use TMSWP\Core\Model\Migrator;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\ModelInitializer;
use TMSWP\Core\Model\QueryBuilder;
use TMSWP\Core\Model\TableDefinition;
use TMSWP\Core\Model\UpsertUpdate;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;

final class ModelLayerTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Model::useConnection(null);
        parent::tearDown();
    }

    public function testTableDefinitionBuildsDbDeltaCompatibleSql(): void
    {
        $connection = new Connection(WpdbTestDouble::create());
        $definition = TableDefinition::create('contacts')
            ->id()
            ->string('email', default: '')
            ->text('body')
            ->bool('active', true)
            ->datetime('created_at', default: 'CURRENT_TIMESTAMP')
            ->foreignId('user_id')
            ->unique('email', 'email_unique')
            ->charset('utf8mb4')
            ->collation('utf8mb4_unicode_ci');

        self::assertSame(<<<'SQL'
CREATE TABLE wp_contacts (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
email varchar(191) NOT NULL DEFAULT '',
body text DEFAULT NULL,
active tinyint(1) NOT NULL DEFAULT 1,
created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
user_id bigint(20) unsigned NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY email_unique (email),
KEY user_id (user_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL, $definition->toSql($connection));
        self::assertSame(['id', 'email', 'body', 'active', 'created_at', 'user_id'], $definition->columns());
        self::assertSame(['id'], $definition->primaryColumns());
    }

    public function testTableDefinitionRejectsInvalidSchema(): void
    {
        $this->expectException(SchemaException::class);

        TableDefinition::create('contacts')
            ->id()
            ->text('body')
            ->index('body');
    }

    // Connection::select()/selectRow() が wpdb の ARRAY_A 定数を参照するため、定数を分離プロセス内でだけ定義する。
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testQueryBuilderBuildsSqlAndKeepsValuePluckRaw(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);

        $wpdb->queueRow(['email' => 'a@example.test']);
        $email = (new QueryBuilder($connection, 'contacts', TestContactRow::class))
            ->where('id', 10)
            ->value('email');

        self::assertSame('a@example.test', $email);
        self::assertSame('SELECT email FROM wp_contacts WHERE id = 10 LIMIT 1', $wpdb->queries()[0]);

        $wpdb->queueResultSet([
            ['id' => '1', 'email' => 'a@example.test'],
            ['id' => '2', 'email' => 'b@example.test'],
        ]);
        $emails = (new QueryBuilder($connection, 'contacts', TestContactRow::class))
            ->orderBy('id')
            ->pluck('email', 'id');

        self::assertSame([1 => 'a@example.test', 2 => 'b@example.test'], $emails);
        self::assertSame('SELECT email, id FROM wp_contacts ORDER BY id ASC', $wpdb->queries()[1]);
    }

    // Connection::select()/selectRow() が wpdb の ARRAY_A 定数を参照するため、定数を分離プロセス内でだけ定義する。
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testQueryBuilderHydratesStrictConstructorValues(): void
    {
        WpdbTestDouble::defineOutputConstants();
        Functions\when('wp_timezone')->justReturn(new DateTimeZone('Asia/Tokyo'));

        $wpdb = WpdbTestDouble::create();
        $wpdb->queueResultSet([[
            'id' => '10',
            'email' => 'a@example.test',
            'active' => '1',
            'meta' => '{"tags":["news"]}',
            'created_at' => '2026-06-13 09:10:11',
            'status' => 'published',
        ]]);
        $rows = (new QueryBuilder(new Connection($wpdb), 'contacts'))
            ->as(TestContactRow::class)
            ->get();

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame(10, $row->id);
        self::assertTrue($row->active);
        self::assertSame(['tags' => ['news']], $row->meta);
        self::assertSame('2026-06-13 09:10:11', $row->createdAt->format('Y-m-d H:i:s'));
        self::assertSame(TestStatus::Published, $row->status);
    }

    // Connection::select()/selectRow() が wpdb の ARRAY_A 定数を参照するため、定数を分離プロセス内でだけ定義する。
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testQueryBuilderRejectsInvalidHydrationAndUnsafeRawSql(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        $wpdb->queueResultSet([[
            'id' => 'not-int',
            'email' => 'a@example.test',
            'active' => '1',
            'meta' => '{}',
            'created_at' => '2026-06-13 09:10:11',
            'status' => 'published',
        ]]);

        $this->expectException(HydrationException::class);
        (new QueryBuilder(new Connection($wpdb), 'contacts'))->as(TestContactRow::class)->get();
    }

    public function testQueryBuilderRejectsInvalidQueryShapes(): void
    {
        $connection = new Connection(WpdbTestDouble::create());

        self::assertSame(
            'SELECT * FROM wp_contacts WHERE 0 = 1',
            (new QueryBuilder($connection, 'contacts'))->whereIn('id', [])->toSql(),
        );

        $this->expectException(InvalidQueryException::class);
        (new QueryBuilder($connection, 'contacts'))->offset(10)->toSql();
    }

    // Connection::select()/selectRow() が wpdb の ARRAY_A 定数を参照するため、定数を分離プロセス内でだけ定義する。
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testModelFacadeUsesConfiguredConnection(): void
    {
        WpdbTestDouble::defineOutputConstants();
        Functions\when('wp_timezone')->justReturn(new DateTimeZone('UTC'));

        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        Model::useConnection($connection);
        // 挿入が作る行のID(insert_id)
        $wpdb->queueInsertId(1);
        $wpdb->queueInsertId(2);

        self::assertSame(1, TestContactModel::insert(['email' => 'a@example.test', 'active' => true]));
        self::assertSame([
            'table' => 'wp_contacts',
            'data' => ['email' => 'a@example.test', 'active' => 1],
            'format' => ['%s', '%d'],
        ], $wpdb->insertCalls()[0]);

        $wpdb->queueAffectedRows(1);
        self::assertSame(2, TestCounterModel::insert([]));
        self::assertSame('INSERT INTO wp_counters () VALUES ()', $wpdb->queries()[0]);

        $wpdb->queueResultSet([[
            'id' => '10',
            'email' => 'b@example.test',
            'active' => '0',
            'meta' => '{}',
            'created_at' => '2026-06-13 00:00:00',
            'status' => 'draft',
        ]]);
        $found = TestContactModel::find(10);

        self::assertInstanceOf(TestContactRow::class, $found);
        self::assertSame('b@example.test', $found->email);
        $wpdb->queueAffectedRows(1);
        $wpdb->queueAffectedRows(1);
        self::assertSame(1, TestContactModel::update(['email' => 'c@example.test'], 10));
        self::assertSame(1, TestContactModel::delete(10));
        self::assertSame('UPDATE wp_contacts SET email = \'c@example.test\' WHERE id = 10', $wpdb->queries()[2]);
        self::assertSame('DELETE FROM wp_contacts WHERE id = 10', $wpdb->queries()[3]);
    }

    /**
     * @param Closure(Connection): int $write
     */
    #[DataProvider('failingWrites')]
    public function testConnectionThrowsQueryExceptionWhenWpdbWriteReturnsFalse(Closure $write, string $expectedMessage): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $wpdb->failNextWrite();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage($expectedMessage);

        $write($connection);
    }

    /**
     * @return iterable<string, array{Closure(Connection): int, string}>
     */
    public static function failingWrites(): iterable
    {
        yield 'execute' => [
            static fn(Connection $connection): int => $connection->execute('UPDATE wp_contacts SET active = %d', [0]),
            'query failed: UPDATE wp_contacts SET active = 0',
        ];
        yield 'insert' => [
            static fn(Connection $connection): int => $connection->insert('contacts', ['email' => 'a@example.test']),
            'insert failed: wp_contacts',
        ];
        yield 'update' => [
            static fn(Connection $connection): int => $connection->update('contacts', ['email' => 'b@example.test'], ['id' => 10]),
            'update failed: wp_contacts',
        ];
        yield 'delete' => [
            static fn(Connection $connection): int => $connection->delete('contacts', ['id' => 10]),
            'delete failed: wp_contacts',
        ];
        yield 'upsert' => [
            static fn(Connection $connection): int => $connection->upsert('contacts', ['email' => 'a@example.test', 'visits' => 1], ['visits' => UpsertUpdate::Increment]),
            "query failed: INSERT INTO wp_contacts (email, visits) VALUES ('a@example.test', 1) ON DUPLICATE KEY UPDATE visits = visits + 1",
        ];
    }

    public function testConnectionReportsWpdbLastErrorAndRecoversOnNextWrite(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $wpdb->failNextWrite("Duplicate entry 'a@example.test' for key 'email_unique'");
        // 失敗した挿入は行を作らないため、このIDは次の成功した挿入が使う
        $wpdb->queueInsertId(1);

        try {
            $connection->insert('contacts', ['email' => 'a@example.test']);
            self::fail('wpdb の失敗が QueryException になっていません。');
        } catch (QueryException $exception) {
            self::assertSame(
                "database error: Duplicate entry 'a@example.test' for key 'email_unique'; SQL: INSERT INTO wp_contacts",
                $exception->getMessage(),
            );
        }

        self::assertSame(1, $connection->insert('contacts', ['email' => 'b@example.test']));
        self::assertSame('', $connection->lastError());
        self::assertCount(2, $wpdb->insertCalls());
    }

    public function testConnectionWritesNormalizeValuesByR3(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $createdAt = new DateTimeImmutable('2026-06-13 09:10:11', new DateTimeZone('Asia/Tokyo'));
        // insert()のIDと、update()・delete()が影響する行の数(代役は行を持たないため、テストが決める)
        $wpdb->queueInsertId(1);
        $wpdb->queueAffectedRows(1);
        $wpdb->queueAffectedRows(1);

        $connection->insert('contacts', [
            'email' => new TestEmail('a@example.test'),
            'active' => true,
            'created_at' => $createdAt,
            'status' => TestStatus::Published,
            'priority' => TestPriority::High,
            'deleted_at' => null,
        ]);
        $connection->update('contacts', ['status' => TestStatus::Draft, 'deleted_at' => null], ['created_at' => $createdAt, 'priority' => TestPriority::High]);
        $connection->delete('contacts', ['status' => TestStatus::Draft, 'deleted_at' => null]);

        self::assertSame([
            'table' => 'wp_contacts',
            'data' => [
                'email' => 'a@example.test',
                'active' => 1,
                'created_at' => '2026-06-13 09:10:11',
                'status' => 'published',
                'priority' => 2,
                'deleted_at' => null,
            ],
            'format' => ['%s', '%d', '%s', '%s', '%d', '%s'],
        ], $wpdb->insertCalls()[0]);
        self::assertSame([
            'table' => 'wp_contacts',
            'data' => ['status' => 'draft', 'deleted_at' => null],
            'where' => ['created_at' => '2026-06-13 09:10:11', 'priority' => 2],
            'format' => ['%s', '%s'],
            'where_format' => ['%s', '%d'],
        ], $wpdb->updateCalls()[0]);
        self::assertSame([
            'table' => 'wp_contacts',
            'where' => ['status' => 'draft', 'deleted_at' => null],
            'where_format' => ['%s', '%s'],
        ], $wpdb->deleteCalls()[0]);
    }

    /**
     * @param Closure(Connection): int $write
     */
    #[DataProvider('writesWithNonBareTableName')]
    public function testConnectionWritesAcceptOnlyBareTableNames(Closure $write): void
    {
        $wpdb = WpdbTestDouble::create();

        try {
            $write(new Connection($wpdb));
            self::fail('ベア名でないテーブル名が InvalidQueryException になっていません。');
        } catch (InvalidQueryException $exception) {
            self::assertSame('invalid bare table name: Wp_contacts', $exception->getMessage());
        }

        self::assertSame([], $wpdb->insertCalls());
        self::assertSame([], $wpdb->updateCalls());
        self::assertSame([], $wpdb->deleteCalls());
        self::assertSame([], $wpdb->queries());
    }

    /**
     * @return iterable<string, array{Closure(Connection): int}>
     */
    public static function writesWithNonBareTableName(): iterable
    {
        yield 'insert' => [static fn(Connection $connection): int => $connection->insert('Wp_contacts', ['email' => 'a@example.test'])];
        yield 'update' => [static fn(Connection $connection): int => $connection->update('Wp_contacts', ['email' => 'a@example.test'], ['id' => 10])];
        yield 'delete' => [static fn(Connection $connection): int => $connection->delete('Wp_contacts', ['id' => 10])];
        yield 'upsert' => [static fn(Connection $connection): int => $connection->upsert('Wp_contacts', ['email' => 'a@example.test'], ['email' => UpsertUpdate::Replace])];
    }

    public function testConnectionPrepareTurnsEscapedPercentIntoLiteralWithoutBindings(): void
    {
        $connection = new Connection(WpdbTestDouble::create());

        self::assertSame('SELECT id % 2 FROM wp_contacts', $connection->prepare('SELECT id %% 2 FROM wp_contacts'));
        self::assertSame(
            'SELECT id % 2 AS odd FROM wp_contacts',
            (new QueryBuilder($connection, 'contacts'))->selectRaw('id %% 2 AS odd')->toSql(),
        );
    }

    /**
     * wpdb::prepare()は`%`をエスケープの文字列(`{` + 16進 + `}`)に置き換えて返す。prepare()とtoSql()は、それを`%`に
     * 戻したSQLを返す。
     */
    public function testPrepareAndToSqlReturnPercentWithoutPlaceholderEscape(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);

        self::assertSame(
            "SELECT id % 2 FROM wp_contacts WHERE email LIKE '%news%'",
            $connection->prepare('SELECT id %% 2 FROM wp_contacts WHERE email LIKE %s', ['%news%']),
        );
        self::assertSame(
            "SELECT id % 2 AS odd FROM wp_contacts WHERE email LIKE '%news%' AND id % 2 = 1",
            (new QueryBuilder($connection, 'contacts'))->selectRaw('id %% 2 AS odd')->whereLike('email', '%news%')->whereRaw('id %% %d = %d', [2, 1])->toSql(),
        );
        // prepare()とtoSql()はSQLを組み立てるだけで、実行しない
        self::assertSame([], $wpdb->queries());
    }

    /** toSql()は、JOIN・グループ・GROUP BY・ORDER BY・LIMIT・OFFSETを含むビルダでも、SQLを1件も実行しない。 */
    public function testToSqlDoesNotExecuteQuery(): void
    {
        $wpdb = WpdbTestDouble::create();
        $builder = (new QueryBuilder(new Connection($wpdb), 'contacts'))
            ->alias('c')
            ->select('c.status')
            ->selectRaw('COUNT(*) AS total')
            ->leftJoin('users AS u', 'u.ID', '=', 'c.user_id')
            ->where(static fn(QueryBuilder $group): QueryBuilder => $group->where('c.active', true)->orWhere('c.deleted_at', null))
            ->groupBy('c.status')
            ->orderBy('c.status')
            ->limit(10)
            ->offset(20);

        self::assertSame(
            'SELECT c.status, COUNT(*) AS total FROM wp_contacts AS c LEFT JOIN wp_users AS u ON u.ID = c.user_id'
            . ' WHERE (c.active = 1 OR c.deleted_at IS NULL) GROUP BY c.status ORDER BY c.status ASC LIMIT 10 OFFSET 20',
            $builder->toSql(),
        );
        self::assertSame($builder->toSql(), $builder->toSql());
        self::assertSame([], $wpdb->queries());
    }

    /**
     * @param list<mixed> $bindings
     */
    #[DataProvider('invalidPlaceholderSql')]
    public function testConnectionPrepareRejectsUnsupportedPercentSequences(string $sql, array $bindings, string $expectedMessage): void
    {
        $connection = new Connection(WpdbTestDouble::create());

        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage($expectedMessage);

        $connection->prepare($sql, $bindings);
    }

    /**
     * @return iterable<string, array{string, list<mixed>, string}>
     */
    public static function invalidPlaceholderSql(): iterable
    {
        yield 'placeholder right after an escaped percent' => [
            'SELECT id FROM wp_contacts WHERE email LIKE %%%s',
            [],
            'a placeholder must not directly follow %%',
        ];
        yield 'numbered placeholder' => [
            'SELECT id FROM wp_contacts WHERE email = %1$s',
            ['a@example.test'],
            "unsupported percent sequence '%1'",
        ];
        yield 'uppercase float placeholder' => [
            'SELECT id FROM wp_contacts WHERE score = %F',
            [1.5],
            "unsupported percent sequence '%F'",
        ];
        yield 'unescaped literal percent' => [
            'SELECT DATE_FORMAT(created_at, %Y) FROM wp_contacts',
            [],
            "unsupported percent sequence '%Y'",
        ];
        yield 'trailing percent' => [
            'SELECT id % FROM wp_contacts',
            [],
            "unsupported percent sequence '% '",
        ];
    }

    public function testQueryBuilderUpdateAssignsNull(): void
    {
        $wpdb = WpdbTestDouble::create();
        $wpdb->queueAffectedRows(1);

        $affected = (new QueryBuilder(new Connection($wpdb), 'contacts'))
            ->where('id', 10)
            ->update(['deleted_at' => null, 'email' => 'a@example.test']);

        self::assertSame(1, $affected);
        self::assertSame("UPDATE wp_contacts SET deleted_at = NULL, email = 'a@example.test' WHERE id = 10", $wpdb->queries()[0]);
    }

    // Connection::select()/selectRow() が wpdb の ARRAY_A 定数を参照するため、定数を分離プロセス内でだけ定義する。
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testValueAndPluckKeepExistingSelectClause(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);

        $wpdb->queueRow(['next_id' => '11']);
        $nextId = (new QueryBuilder($connection, 'contacts'))
            ->selectRaw('MAX(id) + %d AS next_id', [1])
            ->value('next_id');

        self::assertSame('11', $nextId);
        self::assertSame('SELECT MAX(id) + 1 AS next_id FROM wp_contacts LIMIT 1', $wpdb->queries()[0]);

        $wpdb->queueResultSet([
            ['status' => 'draft', 'total' => '2'],
            ['status' => 'published', 'total' => '5'],
        ]);
        $totals = (new QueryBuilder($connection, 'contacts'))
            ->alias('c')
            ->select('c.status')
            ->selectRaw('COUNT(*) AS total')
            ->where('c.active', true)
            ->groupBy('c.status')
            ->pluck('total', 'c.status');

        self::assertSame(['draft' => '2', 'published' => '5'], $totals);
        self::assertSame('SELECT c.status, COUNT(*) AS total FROM wp_contacts AS c WHERE c.active = 1 GROUP BY c.status', $wpdb->queries()[1]);
    }

    // Connection::select()/selectRow() が wpdb の ARRAY_A 定数を参照するため、定数を分離プロセス内でだけ定義する。
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testValueAndPluckRejectMissingResultColumns(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);
        $thrown = [];

        $wpdb->queueRow(['total' => '3']);
        $wpdb->queueResultSet([['total' => '3']]);
        $wpdb->queueResultSet([['email' => 'a@example.test']]);
        $wpdb->queueResultSet([['email' => 'a@example.test', 'id' => null]]);
        $cases = [
            'value の列が結果に無い' => static fn(): mixed => (new QueryBuilder($connection, 'contacts'))->selectRaw('COUNT(*) AS total')->value('email'),
            'pluck の値の列が結果に無い' => static fn(): mixed => (new QueryBuilder($connection, 'contacts'))->selectRaw('COUNT(*) AS total')->pluck('email'),
            'pluck のキーの列が結果に無い' => static fn(): mixed => (new QueryBuilder($connection, 'contacts'))->select('email')->pluck('email', 'id'),
            'pluck のキーが NULL' => static fn(): mixed => (new QueryBuilder($connection, 'contacts'))->pluck('email', 'id'),
        ];

        foreach ($cases as $label => $case) {
            try {
                $case();
                $thrown[$label] = null;
            } catch (QueryException $exception) {
                $thrown[$label] = $exception::class;
            }
        }

        self::assertSame(array_fill_keys(array_keys($cases), QueryException::class), $thrown);
    }

    public function testCountDiscardsOrderLimitAndOffset(): void
    {
        $wpdb = WpdbTestDouble::create();
        $connection = new Connection($wpdb);

        $wpdb->queueVar('3');
        $grouped = (new QueryBuilder($connection, 'contacts'))
            ->where('active', true)
            ->groupBy('status')
            ->orderByDesc('status')
            ->limit(10)
            ->offset(20)
            ->count();

        $wpdb->queueVar('7');
        $offsetOnly = (new QueryBuilder($connection, 'contacts'))->orderBy('id')->offset(5)->count();

        self::assertSame(3, $grouped);
        self::assertSame(7, $offsetOnly);
        self::assertSame([
            'SELECT COUNT(*) FROM (SELECT 1 FROM wp_contacts WHERE active = 1 GROUP BY status) AS tmswp_count',
            'SELECT COUNT(*) FROM wp_contacts',
        ], $wpdb->queries());
    }

    public function testWhereInRejectsNonListValues(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('whereIn values must be a list');

        // list でない配列は PHPStan が呼び出し側で弾くため、静的解析を通らないアプリのコードを再現するリフレクションで渡す
        (new ReflectionMethod(QueryBuilder::class, 'whereIn'))
            ->invoke(new QueryBuilder(new Connection(WpdbTestDouble::create()), 'contacts'), 'id', ['first' => 1, 'second' => 2]);
    }

    public function testJoinRejectsNonBareTableNameWithPrefix(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('invalid bare table name: Users');

        (new QueryBuilder(new Connection(WpdbTestDouble::create()), 'contacts'))->innerJoin('Users AS u', 'u.ID', '=', 'contacts.user_id');
    }

    public function testWhereGroupRejectsAlias(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('where group can contain only where clauses');

        (new QueryBuilder(new Connection(WpdbTestDouble::create()), 'contacts'))
            ->where(static fn(QueryBuilder $group): QueryBuilder => $group->alias('c')->where('c.id', 1));
    }

    // Connection::select()/selectRow() が wpdb の ARRAY_A 定数を参照するため、定数を分離プロセス内でだけ定義する。
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHydrationUsesConstructorDefaultForMissingColumn(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        $wpdb->queueResultSet([['id' => '10']]);

        $rows = (new QueryBuilder(new Connection($wpdb), 'contacts'))->as(TestNoteRow::class)->get();

        self::assertEquals([new TestNoteRow(10, 'unset')], $rows);
    }

    // Connection::select()/selectRow() が wpdb の ARRAY_A 定数を参照するため、定数を分離プロセス内でだけ定義する。
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHydrationCastsStringValueForIntBackedEnum(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        $wpdb->queueResultSet([['id' => '10', 'priority' => '2']]);

        $rows = (new QueryBuilder(new Connection($wpdb), 'contacts'))->as(TestPriorityRow::class)->get();

        self::assertEquals([new TestPriorityRow(10, TestPriority::High)], $rows);
    }

    // Connection::select()/selectRow() が wpdb の ARRAY_A 定数を参照するため、定数を分離プロセス内でだけ定義する。
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHydrationRejectsIntegersOutsidePhpRange(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        $wpdb->queueResultSet([['id' => '9223372036854775808', 'note' => 'big']]);

        $this->expectException(HydrationException::class);
        $this->expectExceptionMessage("integer out of range for 'id'");

        (new QueryBuilder(new Connection($wpdb), 'contacts'))->as(TestNoteRow::class)->get();
    }

    public function testMigratorRegistersModelsAndReportsStatusWithoutMigrating(): void
    {
        Functions\when('get_option')->justReturn(false);
        $wpdb = WpdbTestDouble::create();
        // 保存済みのハッシュの読み取りは、グローバルの $wpdb でデータベースのエラーを確かめる
        $GLOBALS['wpdb'] = $wpdb;

        try {
            $migrator = new Migrator(new Connection($wpdb));
            $migrator->register(TestContactModel::class);
            $statuses = $migrator->status();
        } finally {
            unset($GLOBALS['wpdb']);
        }

        self::assertContainsOnlyInstancesOf(MigrationStatus::class, $statuses);
        self::assertSame('contacts', $statuses[0]->table);
        self::assertFalse($statuses[0]->current);
        self::assertNull($statuses[0]->storedHash);
        self::assertSame([TestContactModel::class], $migrator->models());
    }

    public function testMigratorRejectsDuplicateTables(): void
    {
        $migrator = new Migrator(new Connection(WpdbTestDouble::create()));
        $migrator->register(TestContactModel::class);

        $this->expectException(SchemaException::class);
        $migrator->register(DuplicateContactModel::class);
    }

    public function testAsModelUsesModelInitializer(): void
    {
        self::assertSame(ModelInitializer::class, (new AsModel())->initializerClass());
    }

    /** rowClass() を上書きしない Model の find() は、行を stdClass で返す(値は DB 由来の文字列のまま)。 */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFindReturnsStdClassRowWhenRowClassIsNotOverridden(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        Model::useConnection(new Connection($wpdb));

        $wpdb->queueResultSet([['id' => '7', 'email' => 'plain@example.test', 'deleted_at' => null]]);
        $found = TestPlainModel::find(7);

        self::assertNull(TestPlainModel::rowClass());
        self::assertInstanceOf(stdClass::class, $found);
        self::assertEquals((object) ['id' => '7', 'email' => 'plain@example.test', 'deleted_at' => null], $found);
        self::assertSame('7', $found->id);
        self::assertSame('plain@example.test', $found->email);
        self::assertNull($found->deleted_at);
        self::assertSame('SELECT * FROM wp_plain_rows WHERE id = 7 LIMIT 1', $wpdb->queries()[0]);

        // get()の既定の行もstdClassで、NULLはnullのまま、ほかの値はDB由来の文字列のまま
        $wpdb->queueResultSet([
            ['id' => '8', 'email' => 'a@example.test', 'deleted_at' => null],
            ['id' => '9', 'email' => '', 'deleted_at' => '2026-06-13 00:00:00'],
        ]);
        $rows = TestPlainModel::query()->orderBy('id')->get();

        self::assertCount(2, $rows);
        self::assertContainsOnlyInstancesOf(stdClass::class, $rows);
        self::assertSame(['id' => '8', 'email' => 'a@example.test', 'deleted_at' => null], get_object_vars($rows[0]));
        self::assertNull(get_object_vars($rows[0])['deleted_at']);
        self::assertSame(['id' => '9', 'email' => '', 'deleted_at' => '2026-06-13 00:00:00'], get_object_vars($rows[1]));
    }

    /** Model は行の入れ物にならない。コンストラクタは引数の無い final で、サブクラスはコンストラクタを宣言できない。 */
    public function testModelConstructorIsFinalWithoutParameters(): void
    {
        $constructor = (new ReflectionClass(Model::class))->getConstructor();

        self::assertInstanceOf(ReflectionMethod::class, $constructor);
        self::assertSame(Model::class, $constructor->getDeclaringClass()->getName());
        self::assertTrue($constructor->isFinal());
        self::assertSame(0, $constructor->getNumberOfParameters());
    }

    /** rowClass() が Model のサブクラスを返すと、query() が as() と同じ例外にする。 */
    public function testQueryRejectsModelClassAsRowClass(): void
    {
        Model::useConnection(new Connection(WpdbTestDouble::create()));

        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('row class must not be a Model');
        TestSelfRowModel::query();
    }

    /** コンストラクタの行クラスの指定も、as() と同じく Model のサブクラスを拒否する。 */
    public function testConstructorRejectsModelClassAsRowClass(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('row class must not be a Model');
        new QueryBuilder(new Connection(WpdbTestDouble::create()), 'plain_rows', TestPlainModel::class);
    }

    /** コンストラクタの行クラスの指定も、as() と同じく存在しないクラスを拒否する。 */
    public function testConstructorRejectsMissingRowClass(): void
    {
        $this->expectException(HydrationException::class);
        $this->expectExceptionMessage('row class not found');

        // 存在しないクラス名は PHPStan が呼び出し側で弾くため、静的解析を通らないアプリのコードを再現するリフレクションで渡す
        (new ReflectionClass(QueryBuilder::class))->newInstance(new Connection(WpdbTestDouble::create()), 'plain_rows', 'TMSWP\\Core\\Tests\\Unit\\Model\\MissingRow');
    }

    /** as() に Model のサブクラスを渡すと例外にする(カラムを無視した空の Model を返さない)。 */
    public function testAsRejectsModelClassAsRowClass(): void
    {
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('row class must not be a Model');
        (new QueryBuilder(new Connection(WpdbTestDouble::create()), 'plain_rows'))->as(TestPlainModel::class);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function modelSignatures(): iterable
    {
        yield 'table' => ['table', []];
        yield 'rowClass' => ['rowClass', []];
        yield 'tableName' => ['tableName', []];
        yield 'bareTableName' => ['bareTableName', []];
        yield 'primaryKey' => ['primaryKey', []];
        yield 'query' => ['query', []];
        yield 'find' => ['find', ['int $id']];
        yield 'insert' => ['insert', ['array $data']];
        yield 'upsert' => ['upsert', ['array $values', 'array $update']];
        yield 'update' => ['update', ['array $data', 'array|int $where']];
        yield 'delete' => ['delete', ['array|int $where']];
        yield 'count' => ['count', ['array $where = []']];
        yield 'connection' => ['connection', []];
        yield 'useConnection' => ['useConnection', ['?TMSWP\Core\Model\Connection $connection']];
    }

    /**
     * Model の公開メソッドの引数が docs/06 §3 のシグネチャどおりである。
     *
     * @param list<string> $expected
     */
    #[DataProvider('modelSignatures')]
    public function testModelPublicSignaturesMatchDesign(string $method, array $expected): void
    {
        $parameters = [];

        foreach ((new ReflectionMethod(Model::class, $method))->getParameters() as $parameter) {
            $parameters[] = (string) $parameter->getType() . ' $' . $parameter->getName()
                . ($parameter->isDefaultValueAvailable() ? ' = ' . var_export($parameter->getDefaultValue(), true) : '');
        }

        self::assertSame($expected, str_replace('array (' . "\n" . ')', '[]', $parameters));
    }

    public function testModelPublicMethodsAreExactlyTheDesignedOnes(): void
    {
        $methods = [];

        foreach ((new ReflectionClass(Model::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methods[] = $method->getName();
        }

        sort($methods);

        self::assertSame(
            ['__construct', 'bareTableName', 'connection', 'count', 'delete', 'find', 'insert', 'primaryKey', 'query', 'rowClass', 'table', 'tableName', 'update', 'upsert', 'useConnection'],
            $methods,
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUpdateAndDeleteTakePrimaryKeyOrEqualityMap(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $wpdb = WpdbTestDouble::create();
        Model::useConnection(new Connection($wpdb));

        // 影響した行の数は、wpdbが返した数をそのまま返す
        foreach ([1, 3, 0, 2] as $rows) {
            $wpdb->queueAffectedRows($rows);
        }

        self::assertSame(1, TestPlainModel::update(['email' => 'new@example.test'], 7));
        self::assertSame(3, TestPlainModel::update(['deleted_at' => null], ['email' => 'old@example.test', 'deleted_at' => null]));
        self::assertSame(0, TestPlainModel::delete(7));
        self::assertSame(2, TestPlainModel::delete(['email' => 'old@example.test']));

        self::assertSame([
            "UPDATE wp_plain_rows SET email = 'new@example.test' WHERE id = 7",
            "UPDATE wp_plain_rows SET deleted_at = NULL WHERE email = 'old@example.test' AND deleted_at IS NULL",
            'DELETE FROM wp_plain_rows WHERE id = 7',
            "DELETE FROM wp_plain_rows WHERE email = 'old@example.test'",
        ], $wpdb->queries());
    }

    /** @return iterable<string, array{Closure(): mixed, string}> */
    public static function invalidWhereMaps(): iterable
    {
        yield 'update with empty map' => [static fn(): int => TestPlainModel::update(['email' => 'x'], []), 'where map must not be empty'];
        yield 'delete with empty map' => [static fn(): int => TestPlainModel::delete([]), 'where map must not be empty'];
        // 整数のキーは PHPStan が呼び出し側で弾くため、静的解析を通らないアプリのコードを再現するリフレクションで渡す
        yield 'update with integer key' => [static fn(): mixed => (new ReflectionMethod(TestPlainModel::class, 'update'))->invoke(null, ['email' => 'x'], ['id = 1']), 'where map keys must be column names'];
        yield 'delete with array value' => [static fn(): int => TestPlainModel::delete(['id' => [1, 2]]), 'where value must not be array'];
        yield 'count with integer key' => [static fn(): mixed => (new ReflectionMethod(TestPlainModel::class, 'count'))->invoke(null, ['id = 1']), 'where map keys must be column names'];
    }

    /** @param Closure(): mixed $call */
    #[DataProvider('invalidWhereMaps')]
    public function testWhereMapRejectsEmptyIntegerKeysAndArraysForWrites(Closure $call, string $expectedMessage): void
    {
        Model::useConnection(new Connection(WpdbTestDouble::create()));

        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage($expectedMessage);
        $call();
    }

    public function testCountBuildsEqualityInAndNullConditions(): void
    {
        $wpdb = WpdbTestDouble::create();
        Model::useConnection(new Connection($wpdb));
        $wpdb->queueVar('3');
        $wpdb->queueVar('9');

        self::assertSame(3, TestPlainModel::count(['email' => ['a@example.test', 'b@example.test'], 'deleted_at' => null, 'id' => 5]));
        self::assertSame(9, TestPlainModel::count());
        self::assertSame([
            "SELECT COUNT(*) FROM wp_plain_rows WHERE email IN ('a@example.test', 'b@example.test') AND deleted_at IS NULL AND id = 5",
            'SELECT COUNT(*) FROM wp_plain_rows',
        ], $wpdb->queries());
    }

    /** useConnection() は全 Model に共通で効き、null で解除する。 */
    public function testUseConnectionAppliesToEveryModelAndNullClearsIt(): void
    {
        $connection = new Connection(WpdbTestDouble::create());
        TestPlainModel::useConnection($connection);

        self::assertSame($connection, Model::connection());
        self::assertSame($connection, TestPlainModel::connection());
        self::assertSame($connection, TestCounterModel::connection());

        Model::useConnection(null);

        $this->expectException(KernelException::class);
        TestPlainModel::connection();
    }

    /** どの Kernel にも属さない Model は、グローバルの $wpdb があっても fromGlobal() に切り替えず例外にする。 */
    public function testConnectionOfModelOutsideAnyKernelThrowsInsteadOfUsingGlobalWpdb(): void
    {
        $previous = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = WpdbTestDouble::create();

        try {
            $this->expectException(KernelException::class);
            $this->expectExceptionMessage('Model::useConnection()');
            TestPlainModel::connection();
        } finally {
            $GLOBALS['wpdb'] = $previous;
        }
    }

    public function testTableDefinitionIsBuiltOncePerModelClass(): void
    {
        TestCountingTableModel::$tableCalls = 0;

        self::assertSame('counted', TestCountingTableModel::bareTableName());
        self::assertSame('id', TestCountingTableModel::primaryKey());
        self::assertSame('counted', TestCountingTableModel::bareTableName());
        self::assertSame(1, TestCountingTableModel::$tableCalls);
    }

    /** @return iterable<string, array{Closure(TableDefinition): TableDefinition}> */
    public static function reservedWordDeclarations(): iterable
    {
        yield 'column order' => [static fn(TableDefinition $table): TableDefinition => $table->int('order')];
        yield 'column KEY (upper case)' => [static fn(TableDefinition $table): TableDefinition => $table->string('KEY')];
        yield 'column group' => [static fn(TableDefinition $table): TableDefinition => $table->text('group')];
        yield 'column desc' => [static fn(TableDefinition $table): TableDefinition => $table->bool('desc')];
        yield 'column range' => [static fn(TableDefinition $table): TableDefinition => $table->datetime('range')];
        yield 'column offset (MariaDB)' => [static fn(TableDefinition $table): TableDefinition => $table->bigint('offset')];
        yield 'column rank (MySQL)' => [static fn(TableDefinition $table): TableDefinition => $table->rawColumn('rank', 'int(11) NOT NULL')];
        yield 'id column' => [static fn(TableDefinition $table): TableDefinition => TableDefinition::create('reserved_rows')->id('select')];
        yield 'index name' => [static fn(TableDefinition $table): TableDefinition => $table->string('code')->index('code', 'index')];
        yield 'unique name' => [static fn(TableDefinition $table): TableDefinition => $table->string('code')->unique('code', 'unique')];
    }

    /** @param Closure(TableDefinition): TableDefinition $declare */
    #[DataProvider('reservedWordDeclarations')]
    public function testTableDefinitionRejectsReservedWordsAtDeclaration(Closure $declare): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('reserved word');
        $declare(TableDefinition::create('reserved_rows')->id());
    }

    public function testTableDefinitionAcceptsNonReservedKeywordsAsColumnNames(): void
    {
        $definition = TableDefinition::create('keyword_rows')->id()->string('status')->string('name')->datetime('date');

        self::assertSame(['id', 'status', 'name', 'date'], $definition->columns());
    }

    public function testTableDefinitionRejectsCollationWithoutCharsetAtDeclaration(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('collation requires charset');
        TableDefinition::create('collated_rows')->id()->collation('utf8mb4_unicode_ci');
    }
}

final class TestPlainModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('plain_rows')
            ->id()
            ->string('email')
            ->datetime('deleted_at', nullable: true);
    }
}

final class TestSelfRowModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('self_rows')->id();
    }

    public static function rowClass(): string
    {
        return self::class;
    }
}

final class TestCountingTableModel extends Model
{
    public static int $tableCalls = 0;

    public static function table(): TableDefinition
    {
        ++self::$tableCalls;

        return TableDefinition::create('counted')->id();
    }
}

enum TestStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

enum TestPriority: int
{
    case Low = 1;
    case High = 2;
}

final readonly class TestEmail implements Stringable
{
    public function __construct(
        private string $address,
    ) {}

    public function __toString(): string
    {
        return $this->address;
    }
}

final readonly class TestPriorityRow
{
    public function __construct(
        public int $id,
        public TestPriority $priority,
    ) {}
}

final readonly class TestNoteRow
{
    public function __construct(
        public int $id,
        public string $note = 'unset',
    ) {}
}

final readonly class TestContactRow
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public int $id,
        public string $email,
        public bool $active,
        public array $meta,
        public DateTimeImmutable $createdAt,
        public TestStatus $status,
    ) {}
}

final class TestContactModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('contacts')
            ->id()
            ->string('email')
            ->bool('active')
            ->json('meta')
            ->datetime('created_at')
            ->string('status')
            ->unique('email', 'email_unique');
    }

    /** @return class-string<TestContactRow> */
    public static function rowClass(): string
    {
        return TestContactRow::class;
    }
}

final class DuplicateContactModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('contacts')
            ->id()
            ->string('code');
    }
}

final class TestCounterModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('counters')->id();
    }

    public static function rowClass(): ?string
    {
        return null;
    }
}
