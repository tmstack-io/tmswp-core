<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Model;

use BackedEnum;
use Brain\Monkey\Functions;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;
use TMSWP\Core\Exception\HydrationException;
use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\QueryBuilder;
use TMSWP\Core\Query\CastType;
use TMSWP\Core\Query\Compare;
use TMSWP\Core\Query\SortOrder;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;

/**
 * QueryBuilder・Connectionの受け入れ基準(docs/06の受け入れ基準「QueryBuilder / Connection」)のうち、WordPressなしで
 * 確かめられるものを確かめる。
 */
final class QueryBuilderAcceptanceTest extends UnitTestCase
{
    /** 後ろの引数の型(LATE_ENUM)がテストの途中まで定義されない行クラス(tests/fixtures/model/late-enum-row.php) */
    private const LATE_ENUM_ROW = 'TMSWP\\Core\\Tests\\Fixtures\\Model\\LateEnumRow';

    /** LATE_ENUM_ROWの変換の後に読み込むenum(tests/fixtures/model/late-enum.php) */
    private const LATE_ENUM = 'TMSWP\\Core\\Tests\\Fixtures\\Model\\LateEnum';

    /** whereIn()の空配列は恒偽の0 = 1、whereNotIn()の空配列は恒真の1 = 1になる。 */
    public function testEmptyWhereInIsAlwaysFalseAndEmptyWhereNotInIsAlwaysTrue(): void
    {
        self::assertSame('SELECT * FROM wp_contacts WHERE 0 = 1', self::query()->whereIn('id', [])->toSql());
        self::assertSame('SELECT * FROM wp_contacts WHERE 1 = 1', self::query()->whereNotIn('id', [])->toSql());
        self::assertSame(
            'SELECT * FROM wp_contacts WHERE active = 1 AND 1 = 1 AND status NOT IN (\'draft\')',
            self::query()->where('active', true)->whereNotIn('id', [])->whereNotIn('status', ['draft'])->toSql(),
        );
    }

    /**
     * Closureのグループは括弧で囲んだSQLになり、値はSQLに現れる順(SELECT句 → JOIN句 → WHERE句 →
     * LIMIT・OFFSET)に当てはまる。値はそれぞれ違うため、順が入れ替われば別の位置に入る。
     */
    public function testClosureGroupIsParenthesizedAndBindingsFollowSqlOrder(): void
    {
        $sql = self::query()
            ->alias('c')
            ->selectRaw('c.id, %d AS marker', [7])
            ->innerJoinRaw('users AS u', 'u.ID = c.user_id AND u.user_status = %d', [0])
            ->where('c.active', true)
            ->where(static function (QueryBuilder $group): void {
                $group->where('c.status', 'draft')
                    ->orWhere('c.status', 'pending')
                    ->orWhere(static function (QueryBuilder $nested): void {
                        $nested->where('c.score', '>', 10)->where('c.email', 'LIKE', '%@example.test');
                    });
            })
            ->orWhere(static function (QueryBuilder $group): void {
                $group->whereIn('c.id', [100, 200])->whereNull('c.deleted_at');
            })
            ->orderBy('c.id', SortOrder::Desc)
            ->limit(30)
            ->offset(60)
            ->toSql();

        self::assertSame(
            'SELECT c.id, 7 AS marker FROM wp_contacts AS c INNER JOIN wp_users AS u ON u.ID = c.user_id AND u.user_status = 0'
            . " WHERE c.active = 1 AND (c.status = 'draft' OR c.status = 'pending' OR (c.score > 10 AND c.email LIKE '%@example.test'))"
            . ' OR (c.id IN (100, 200) AND c.deleted_at IS NULL)'
            . ' ORDER BY c.id DESC LIMIT 30 OFFSET 60',
            $sql,
        );
    }

    /** Compare・CastType・SortOrderは、docs/06 §4の正準定義どおりのcaseと値を、この順に持つ。 */
    public function testQueryVocabularyEnumsHaveCanonicalCasesAndValues(): void
    {
        self::assertSame([
            'Eq' => '=',
            'NotEq' => '!=',
            'Gt' => '>',
            'Gte' => '>=',
            'Lt' => '<',
            'Lte' => '<=',
            'Like' => 'LIKE',
            'NotLike' => 'NOT LIKE',
            'In' => 'IN',
            'NotIn' => 'NOT IN',
            'Between' => 'BETWEEN',
            'NotBetween' => 'NOT BETWEEN',
            'Regexp' => 'REGEXP',
            'NotRegexp' => 'NOT REGEXP',
            'Rlike' => 'RLIKE',
        ], array_column(array_map(static fn(Compare $case): array => [$case->name, $case->value], Compare::cases()), 1, 0));
        self::assertSame([
            'Numeric' => 'NUMERIC',
            'Binary' => 'BINARY',
            'Char' => 'CHAR',
            'Date' => 'DATE',
            'Datetime' => 'DATETIME',
            'Decimal' => 'DECIMAL',
            'Signed' => 'SIGNED',
            'Time' => 'TIME',
            'Unsigned' => 'UNSIGNED',
        ], array_column(array_map(static fn(CastType $case): array => [$case->name, $case->value], CastType::cases()), 1, 0));
        self::assertSame(
            ['Asc' => 'ASC', 'Desc' => 'DESC'],
            array_column(array_map(static fn(SortOrder $case): array => [$case->name, $case->value], SortOrder::cases()), 1, 0),
        );
    }

    /** orderBy()の方向はSortOrderだけを受け取り(文字列は型で渡せない)、句を組み立てるときに->valueでSQLにする。 */
    public function testOrderByTakesSortOrderAndWritesItsValue(): void
    {
        $direction = (new ReflectionMethod(QueryBuilder::class, 'orderBy'))->getParameters()[1]->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $direction);
        self::assertSame(SortOrder::class, $direction->getName());
        self::assertFalse($direction->allowsNull());
        self::assertSame(
            'SELECT * FROM wp_contacts ORDER BY id DESC, email ASC, status ASC',
            self::query()->orderBy('id', SortOrder::Desc)->orderBy('email', SortOrder::Asc)->orderBy('status')->toSql(),
        );
    }

    /**
     * APIの誤用は、SQLを実行する前にInvalidQueryExceptionになる。
     *
     * @return iterable<string, array{Closure(QueryBuilder): mixed, string}>
     */
    public static function invalidQueries(): iterable
    {
        $quotes = 'raw SQL fragment must not contain quotes, backticks, or semicolons';

        yield 'whereRaw with single quote' => [static fn(QueryBuilder $query): QueryBuilder => $query->whereRaw("col = 'x'"), $quotes];
        yield 'whereRaw with double quote' => [static fn(QueryBuilder $query): QueryBuilder => $query->whereRaw('col = "x"'), $quotes];
        yield 'whereRaw with backtick' => [static fn(QueryBuilder $query): QueryBuilder => $query->whereRaw('`col` = %s', ['x']), $quotes];
        yield 'whereRaw with semicolon' => [static fn(QueryBuilder $query): QueryBuilder => $query->whereRaw('col = 1; DROP TABLE wp_users'), $quotes];
        yield 'orWhereRaw with quote' => [static fn(QueryBuilder $query): QueryBuilder => $query->orWhereRaw("col = 'x'"), $quotes];
        yield 'selectRaw with quote' => [static fn(QueryBuilder $query): QueryBuilder => $query->selectRaw("'x' AS literal"), $quotes];
        yield 'innerJoinRaw with quote' => [static fn(QueryBuilder $query): QueryBuilder => $query->innerJoinRaw('users AS u', "u.ID = 'x'"), $quotes];
        yield 'leftJoinRaw with quote' => [static fn(QueryBuilder $query): QueryBuilder => $query->leftJoinRaw('users AS u', "u.ID = 'x'"), $quotes];
        yield 'whereRaw with fewer bindings than placeholders' => [
            static fn(QueryBuilder $query): QueryBuilder => $query->whereRaw('col = %s', []),
            'SQL placeholder count 1 does not match bindings count 0',
        ];
        yield 'whereRaw with more bindings than placeholders' => [
            static fn(QueryBuilder $query): QueryBuilder => $query->whereRaw('col = %s', ['a', 'b']),
            'SQL placeholder count 1 does not match bindings count 2',
        ];
        yield 'orWhereRaw with placeholder count mismatch' => [
            static fn(QueryBuilder $query): QueryBuilder => $query->orWhereRaw('col BETWEEN %d AND %d', [1]),
            'SQL placeholder count 2 does not match bindings count 1',
        ];
        yield 'selectRaw with placeholder count mismatch' => [
            static fn(QueryBuilder $query): QueryBuilder => $query->selectRaw('%d AS one'),
            'SQL placeholder count 1 does not match bindings count 0',
        ];
        yield 'innerJoinRaw with placeholder count mismatch' => [
            static fn(QueryBuilder $query): QueryBuilder => $query->innerJoinRaw('users AS u', 'u.ID = %d', []),
            'SQL placeholder count 1 does not match bindings count 0',
        ];
        yield 'leftJoinRaw with placeholder count mismatch' => [
            static fn(QueryBuilder $query): QueryBuilder => $query->leftJoinRaw('users AS u', 'u.ID = c.user_id', [1]),
            'SQL placeholder count 0 does not match bindings count 1',
        ];
        yield 'where with array value' => [
            static fn(QueryBuilder $query): QueryBuilder => $query->where('col', [1, 2]),
            'where value must not be array',
        ];
        yield 'where with operator and array value' => [
            static fn(QueryBuilder $query): QueryBuilder => $query->where('col', '=', [1, 2]),
            'where value must not be array',
        ];
        yield 'where with invalid operator' => [
            static fn(QueryBuilder $query): QueryBuilder => $query->where('col', 'IN', 1),
            'invalid where operator: IN',
        ];
        yield 'where with column only' => [
            static fn(QueryBuilder $query): QueryBuilder => $query->where('col'),
            'where requires a value, or use whereNull()',
        ];
        yield 'offset without limit' => [
            static fn(QueryBuilder $query): string => $query->offset(10)->toSql(),
            'offset requires limit',
        ];
        yield 'update without where' => [
            static fn(QueryBuilder $query): int => $query->update(['active' => false]),
            'write query requires where clause',
        ];
        yield 'delete without where' => [
            static fn(QueryBuilder $query): int => $query->delete(),
            'write query requires where clause',
        ];
        yield 'update with order and limit' => [
            static fn(QueryBuilder $query): int => $query->where('id', 1)->orderBy('id')->limit(1)->update(['active' => false]),
            'write query does not support join, group, order, limit, offset, or alias',
        ];
        yield 'invalid identifier' => [
            static fn(QueryBuilder $query): QueryBuilder => $query->where('id = 1 OR 1', 1),
            'invalid SQL identifier: id = 1 OR 1',
        ];
    }

    /** @param Closure(QueryBuilder): mixed $call */
    #[DataProvider('invalidQueries')]
    public function testMisuseThrowsInvalidQueryExceptionWithoutRunningSql(Closure $call, string $expectedMessage): void
    {
        $wpdb = WpdbTestDouble::create();

        try {
            $call(new QueryBuilder(new Connection($wpdb), 'contacts'));
            self::fail('InvalidQueryExceptionが投げられる必要があります。');
        } catch (InvalidQueryException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
        }

        self::assertSame([], $wpdb->queries());
    }

    /**
     * DTOのDateTimeImmutableへの変換は'Y-m-d H:i:s'の厳密な書式で解析する。相対の書式・Unix時刻・存在しない日付
     * (parse warning)はHydrationExceptionになる。
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDtoDateTimeIsParsedStrictly(): void
    {
        WpdbTestDouble::defineOutputConstants();
        Functions\when('wp_timezone')->justReturn(new DateTimeZone('Asia/Tokyo'));

        $valid = self::hydrate(QueryBuilderAcceptanceDateRow::class, ['id' => '1', 'published_at' => '2024-02-29 23:59:58']);
        self::assertInstanceOf(QueryBuilderAcceptanceDateRow::class, $valid);
        self::assertSame('2024-02-29 23:59:58.000000 Asia/Tokyo', $valid->publishedAt->format('Y-m-d H:i:s.u e'));

        $interface = self::hydrate(QueryBuilderAcceptanceDateInterfaceRow::class, ['published_at' => '2026-06-13 09:10:11']);
        self::assertInstanceOf(QueryBuilderAcceptanceDateInterfaceRow::class, $interface);
        self::assertInstanceOf(DateTimeImmutable::class, $interface->publishedAt);

        $thrown = [];

        foreach (['tomorrow', '@0', '2024-02-30 00:00:00', '+1 day', '2024-02-29', '2024-02-29T00:00:00', '2024-02-29 00:00:00.5', '2024-02-29 24:00:00', '', "2024-02-29 00:00:00\0", "\0"] as $value) {
            try {
                self::hydrate(QueryBuilderAcceptanceDateRow::class, ['id' => '1', 'published_at' => $value]);
                $thrown[$value] = null;
            } catch (HydrationException $exception) {
                $thrown[$value] = $exception->getMessage();
            }
        }

        self::assertSame([
            'tomorrow' => "invalid datetime for 'publishedAt'",
            '@0' => "invalid datetime for 'publishedAt'",
            '2024-02-30 00:00:00' => "invalid datetime for 'publishedAt'",
            '+1 day' => "invalid datetime for 'publishedAt'",
            '2024-02-29' => "invalid datetime for 'publishedAt'",
            '2024-02-29T00:00:00' => "invalid datetime for 'publishedAt'",
            '2024-02-29 00:00:00.5' => "invalid datetime for 'publishedAt'",
            '2024-02-29 24:00:00' => "invalid datetime for 'publishedAt'",
            '' => "invalid datetime for 'publishedAt'",
            "2024-02-29 00:00:00\0" => "invalid datetime for 'publishedAt'",
            "\0" => "invalid datetime for 'publishedAt'",
        ], $thrown);
    }

    /**
     * as()の行クラスのコンストラクタの引数に当たる列が無く、既定値も無ければ、HydrationExceptionになる。メッセージには
     * 引数名・クラス名・行にある列の一覧を含める。既定値のある引数は、列が無ければ既定値になる。
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAsThrowsHydrationExceptionForMissingColumnWithoutDefault(): void
    {
        WpdbTestDouble::defineOutputConstants();

        self::assertEquals(
            new QueryBuilderAcceptanceDefaultRow(5, 'none'),
            self::hydrate(QueryBuilderAcceptanceDefaultRow::class, ['id' => '5']),
        );

        $this->expectException(HydrationException::class);
        $this->expectExceptionMessage(
            "missing column 'displayName' for row class '" . QueryBuilderAcceptanceNameRow::class . "'; available columns: id, email",
        );

        self::hydrate(QueryBuilderAcceptanceNameRow::class, ['id' => '5', 'email' => 'a@example.test']);
    }

    /**
     * ハイドレーションの失敗(列の欠落・型の変換の失敗・publicでないコンストラクタ等。docs/06 R4)は、すべて
     * HydrationExceptionになる。
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHydrationFailuresThrowHydrationException(): void
    {
        WpdbTestDouble::defineOutputConstants();
        Functions\when('wp_timezone')->justReturn(new DateTimeZone('UTC'));

        $cases = [
            'publicでないコンストラクタ' => [QueryBuilderAcceptancePrivateConstructorRow::class, ['id' => '1'], "row class '" . QueryBuilderAcceptancePrivateConstructorRow::class . "' requires a public constructor"],
            'コンストラクタが無い' => [QueryBuilderAcceptanceNoConstructorRow::class, ['id' => '1'], "row class '" . QueryBuilderAcceptanceNoConstructorRow::class . "' requires a public constructor"],
            'intに変換できない' => [QueryBuilderAcceptanceTypedRow::class, self::typedRow(['id' => '1.5']), "invalid integer for 'id'"],
            'floatに変換できない' => [QueryBuilderAcceptanceTypedRow::class, self::typedRow(['ratio' => 'abc']), "invalid float for 'ratio'"],
            'boolに変換できない' => [QueryBuilderAcceptanceTypedRow::class, self::typedRow(['active' => 'yes']), "invalid boolean for 'active'"],
            'enumの値に無い' => [QueryBuilderAcceptanceTypedRow::class, self::typedRow(['state' => 'archived']), "invalid enum value for 'state'"],
            'JSONとして不正' => [QueryBuilderAcceptanceTypedRow::class, self::typedRow(['meta' => '{']), "invalid JSON for 'meta'"],
            'JSONが配列にならない' => [QueryBuilderAcceptanceTypedRow::class, self::typedRow(['meta' => '"text"']), "JSON value for 'meta' must decode to array"],
            'nullableでない引数にnull' => [QueryBuilderAcceptanceTypedRow::class, self::typedRow(['label' => null]), "non-nullable parameter 'label' received null"],
            'ユニオン型' => [QueryBuilderAcceptanceUnionRow::class, ['value' => '1'], "unsupported parameter type for 'value'"],
            '対応しないクラス' => [QueryBuilderAcceptanceObjectRow::class, ['value' => '1'], "unsupported parameter class '" . QueryBuilderAcceptanceDefaultRow::class . "'"],
        ];
        $thrown = [];

        foreach ($cases as $label => [$class, $row, $message]) {
            try {
                self::hydrate($class, $row);
                $thrown[$label] = null;
            } catch (Throwable $exception) {
                $thrown[$label] = [$exception::class, $exception->getMessage()];
            }
        }

        self::assertSame(
            array_map(static fn(array $case): array => [HydrationException::class, $case[2]], $cases),
            $thrown,
        );
        // 変換できる行は、宣言した型の値になる(上の失敗が行の作りの誤りによるものでないことの確認)
        self::assertEquals(
            new QueryBuilderAcceptanceTypedRow(1, 0.5, true, QueryBuilderAcceptanceState::Open, ['a' => 1], 'label', null),
            self::hydrate(QueryBuilderAcceptanceTypedRow::class, self::typedRow([])),
        );
    }

    /**
     * DTOへの変換は、行クラスのコンストラクタの引数の情報とenumのbacking typeをクラスごとに1回だけReflectionで求め、
     * 行ごと・クエリごとに求め直さない(監査A4-L07)。キャッシュの中身は外から見えないため、privateのキャッシュを読み、
     * 2回目のクエリの後も1回目に求めた同じ情報(同じReflectionのオブジェクト)のままであることで確かめる。
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDtoHydrationReflectsEachRowClassOnlyOnce(): void
    {
        WpdbTestDouble::defineOutputConstants();
        $cache = new ReflectionProperty(QueryBuilder::class, 'rowConstructors');
        $enumCache = new ReflectionProperty(QueryBuilder::class, 'enumBackingTypes');
        $rows = [
            self::typedRow([]),
            self::typedRow(['id' => '2', 'ratio' => '1.25', 'active' => '0', 'state' => 'closed', 'meta' => '[]', 'note' => 'n']),
            self::typedRow(['id' => '3', 'label' => 'third']),
        ];
        $expected = [
            new QueryBuilderAcceptanceTypedRow(1, 0.5, true, QueryBuilderAcceptanceState::Open, ['a' => 1], 'label', null),
            new QueryBuilderAcceptanceTypedRow(2, 1.25, false, QueryBuilderAcceptanceState::Closed, [], 'label', 'n'),
            new QueryBuilderAcceptanceTypedRow(3, 0.5, true, QueryBuilderAcceptanceState::Open, ['a' => 1], 'third', null),
        ];
        $wpdb = WpdbTestDouble::create();
        $wpdb->queueResultSet($rows);
        $wpdb->queueResultSet($rows);
        $query = (new QueryBuilder(new Connection($wpdb), 'contacts'))->as(QueryBuilderAcceptanceTypedRow::class);

        self::assertSame([], $cache->getValue());
        self::assertSame([], $enumCache->getValue());
        self::assertEquals($expected, $query->get());

        $first = $cache->getValue();
        self::assertIsArray($first);
        self::assertSame([QueryBuilderAcceptanceTypedRow::class], array_keys($first));
        self::assertSame([QueryBuilderAcceptanceState::class => 'string'], $enumCache->getValue());

        self::assertEquals($expected, $query->get());
        self::assertSame($first, $cache->getValue());
        self::assertSame([QueryBuilderAcceptanceState::class => 'string'], $enumCache->getValue());
    }

    /**
     * 引数の型がenumかどうかは、その引数の値を変換するときに求める。行クラスを初めて変換した時点でenumがまだ
     * 読み込まれていなくても(nullと既定値の行だけを変換した場合)、後で読み込まれたenumの値を変換できる。
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDtoEnumLoadedAfterEarlierRowsIsConverted(): void
    {
        WpdbTestDouble::defineOutputConstants();
        // LateEnumRowを読み込む。引数の型のLateEnumは読み込まない
        $row = self::requireFixtureClass(__DIR__ . '/../../fixtures/model/late-enum-row.php', self::LATE_ENUM_ROW);
        self::assertFalse(enum_exists(self::LATE_ENUM, false));

        self::assertSame([$row, 1, null], self::lateEnumRowValues(self::hydrate($row, ['id' => '1', 'state' => null])));
        self::assertSame([$row, 2, null], self::lateEnumRowValues(self::hydrate($row, ['id' => '2'])));

        require_once __DIR__ . '/../../fixtures/model/late-enum.php';

        self::assertSame(
            [$row, 3, [self::LATE_ENUM, 'open']],
            self::lateEnumRowValues(self::hydrate($row, ['id' => '3', 'state' => 'open'])),
        );
    }

    /**
     * 引数の型のクラスは、その引数の値を変換するときにだけ読み込む。前の引数の変換が失敗した場合と、後ろの引数がnullか
     * 既定値の場合は、後ろの引数の型のクラスをautoloadしない。読み込みを求められると例外を投げるautoloaderで確かめる。
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDtoHydrationLoadsParameterTypeOnlyWhenConvertingItsValue(): void
    {
        WpdbTestDouble::defineOutputConstants();
        // LateEnumRowを読み込む。引数の型のLateEnumは読み込まない
        $row = self::requireFixtureClass(__DIR__ . '/../../fixtures/model/late-enum-row.php', self::LATE_ENUM_ROW);
        $requested = [];
        $loader = static function (string $class) use (&$requested): void {
            if ($class === self::LATE_ENUM) {
                $requested[] = $class;

                throw new LogicException('autoloader was asked for ' . $class);
            }
        };
        $cases = [
            '前の引数の列が無い' => ['state' => null],
            '前の引数を変換できない' => ['id' => 'bad', 'state' => null],
            '後ろの引数がnull' => ['id' => '7', 'state' => null],
            '後ろの引数が既定値' => ['id' => '7'],
            '後ろの引数を変換する' => ['id' => '7', 'state' => 'open'],
        ];
        $outcomes = [];
        $requestedBeforeConvertingState = null;
        spl_autoload_register($loader);

        try {
            foreach ($cases as $label => $values) {
                if ($label === '後ろの引数を変換する') {
                    $requestedBeforeConvertingState = $requested;
                }

                try {
                    $outcomes[$label] = self::lateEnumRowValues(self::hydrate($row, $values));
                } catch (Throwable $exception) {
                    $outcomes[$label] = [$exception::class, $exception->getMessage()];
                }
            }
        } finally {
            spl_autoload_unregister($loader);
        }

        self::assertSame([], $requestedBeforeConvertingState);
        // 後ろの引数の値を変換するときは型のクラスを読み込む(上の観測でautoloaderが働いていることの確認)
        self::assertSame([self::LATE_ENUM], $requested);
        self::assertSame([
            '前の引数の列が無い' => [HydrationException::class, "missing column 'id' for row class '{$row}'; available columns: state"],
            '前の引数を変換できない' => [HydrationException::class, "invalid integer for 'id'"],
            '後ろの引数がnull' => [$row, 7, null],
            '後ろの引数が既定値' => [$row, 7, null],
            '後ろの引数を変換する' => [LogicException::class, 'autoloader was asked for ' . self::LATE_ENUM],
        ], $outcomes);
    }

    /**
     * fixtureのファイルを読み込み、そこで定義したクラスの名前を返す。
     *
     * @return class-string
     */
    private static function requireFixtureClass(string $file, string $class): string
    {
        require_once $file;

        if (!class_exists($class, false)) {
            self::fail("fixtureのクラスが見つかりません: {$class}");
        }

        return $class;
    }

    /**
     * LateEnumRowの、クラス名・idの値・stateの値(enumならクラス名とbacking value)。
     *
     * @return list<mixed>
     */
    private static function lateEnumRowValues(object $row): array
    {
        $values = get_object_vars($row);
        $state = $values['state'] ?? null;

        return [$row::class, $values['id'] ?? null, $state instanceof BackedEnum ? [$state::class, $state->value] : $state];
    }

    private static function query(): QueryBuilder
    {
        return new QueryBuilder(new Connection(WpdbTestDouble::create()), 'contacts');
    }

    /**
     * 1行の結果をas($class)でハイドレートして返す。
     *
     * @param class-string $class
     * @param array<string, mixed> $row
     */
    private static function hydrate(string $class, array $row): object
    {
        $wpdb = WpdbTestDouble::create();
        $wpdb->queueResultSet([$row]);
        $rows = (new QueryBuilder(new Connection($wpdb), 'contacts'))->as($class)->get();
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /**
     * QueryBuilderAcceptanceTypedRowに変換できる行の、$overrideの列を置き換えたもの。
     *
     * @param array<string, string|null> $override
     *
     * @return array<string, string|null>
     */
    private static function typedRow(array $override): array
    {
        return [...['id' => '1', 'ratio' => '0.5', 'active' => '1', 'state' => 'open', 'meta' => '{"a":1}', 'label' => 'label', 'note' => null], ...$override];
    }
}

enum QueryBuilderAcceptanceState: string
{
    case Open = 'open';
    case Closed = 'closed';
}

final readonly class QueryBuilderAcceptanceDateRow
{
    public function __construct(
        public int $id,
        public DateTimeImmutable $publishedAt,
    ) {}
}

final readonly class QueryBuilderAcceptanceDateInterfaceRow
{
    public function __construct(
        public DateTimeInterface $publishedAt,
    ) {}
}

final readonly class QueryBuilderAcceptanceDefaultRow
{
    public function __construct(
        public int $id,
        public string $displayName = 'none',
    ) {}
}

final readonly class QueryBuilderAcceptanceNameRow
{
    public function __construct(
        public int $id,
        public string $displayName,
    ) {}
}

final readonly class QueryBuilderAcceptancePrivateConstructorRow
{
    private function __construct(
        public int $id,
    ) {}

    public static function make(int $id): self
    {
        return new self($id);
    }
}

final class QueryBuilderAcceptanceNoConstructorRow
{
    public int $id = 0;
}

final readonly class QueryBuilderAcceptanceTypedRow
{
    /** @param array<mixed> $meta */
    public function __construct(
        public int $id,
        public float $ratio,
        public bool $active,
        public QueryBuilderAcceptanceState $state,
        public array $meta,
        public string $label,
        public ?string $note,
    ) {}
}

final readonly class QueryBuilderAcceptanceUnionRow
{
    public function __construct(
        public int|string $value,
    ) {}
}

final readonly class QueryBuilderAcceptanceObjectRow
{
    public function __construct(
        public QueryBuilderAcceptanceDefaultRow $value,
    ) {}
}
