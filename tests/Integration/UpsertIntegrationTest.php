<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Integration;

use Closure;
use ReflectionMethod;
use TMSWP\Core\Exception\InvalidQueryException;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;
use TMSWP\Core\Model\UpsertUpdate;
use TMSWP\Testing\IntegrationTestCase;

/**
 * Connection::upsert()・Model::upsert()を、実物のDBで確かめる。CIでは、wp-envのMariaDBと、MariaDB 10.11・MySQL 8.4・
 * 9.7のすべてで走る。
 *
 * 出すSQLは、挿入する値を更新の句にもう一度バインドする形で、MySQLで非推奨(警告1287)のVALUES()と、MariaDBで構文の
 * 誤りになる行の別名を使わない。影響行数(1=挿入、2=更新、0=変化なし)とinsert_id(挿入なら新しいID、更新なら更新した
 * 行のID、変化なしなら0)は、どのDBでも同じになる。
 */
final class UpsertIntegrationTest extends IntegrationTestCase
{
    private const string TABLE = 'tmswp_it_upserts';

    private Connection $connection;

    public function set_up(): void
    {
        parent::set_up();
        $this->useRealTables(self::TABLE);
        $this->connection = new Connection(self::wpdb());
        Model::useConnection($this->connection);
        $this->connection->execute('CREATE TABLE ' . $this->connection->tableName(self::TABLE) . ' (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
name varchar(40) NOT NULL,
hits int(11) NOT NULL DEFAULT 0,
note varchar(40) NULL,
PRIMARY KEY  (id),
UNIQUE KEY name_unique (name)
) ENGINE=InnoDB');
    }

    public function tear_down(): void
    {
        try {
            Model::useConnection(null);
        } finally {
            parent::tear_down();
        }
    }

    /**
     * 同じキーで2回実行すると、1行だけが残り、Incrementの加算(`hits = hits + 値`。VALUES(hits)を使う形と同じ結果)と
     * Replaceの置き換えが反映される。戻り値は挿入が1・更新が2・変化なしが0で、insertId()は挿入なら新しいID、更新なら
     * 更新した行のID、変化なしなら0。
     */
    public function testSameKeyTwiceKeepsOneRowAndAppliesTheUpdate(): void
    {
        $views = $this->connection->upsert(self::TABLE, ['name' => 'views', 'hits' => 2, 'note' => 'first'], ['hits' => UpsertUpdate::Increment, 'note' => UpsertUpdate::Replace]);
        $viewsId = $this->connection->insertId();
        $clicks = $this->connection->upsert(self::TABLE, ['name' => 'clicks', 'hits' => 1], ['hits' => UpsertUpdate::Increment]);
        $clicksId = $this->connection->insertId();
        $updated = $this->connection->upsert(self::TABLE, ['name' => 'views', 'hits' => 3, 'note' => 'second'], ['hits' => UpsertUpdate::Increment, 'note' => UpsertUpdate::Replace]);
        $updatedId = $this->connection->insertId();
        $unchanged = $this->connection->upsert(self::TABLE, ['name' => 'views', 'hits' => 0, 'note' => 'second'], ['hits' => UpsertUpdate::Increment, 'note' => UpsertUpdate::Replace]);
        $unchangedId = $this->connection->insertId();

        self::assertSame([1, 1, 2, 0], [$views, $clicks, $updated, $unchanged]);
        self::assertGreaterThan(0, $viewsId);
        self::assertNotSame($viewsId, $clicksId);
        self::assertSame([$viewsId, 0], [$updatedId, $unchangedId]);
        self::assertSame([
            [(string) $viewsId, 'views', '5', 'second'],
            [(string) $clicksId, 'clicks', '1', null],
        ], $this->rows());
    }

    /** nullのReplaceは`列 = NULL`になる。nullのIncrementはDBに送る前にInvalidQueryExceptionになり、行は変わらない。 */
    public function testNullReplaceSetsNullAndNullIncrementIsRejected(): void
    {
        $this->connection->upsert(self::TABLE, ['name' => 'views', 'hits' => 1, 'note' => 'kept'], ['hits' => UpsertUpdate::Increment]);

        self::assertSame(2, $this->connection->upsert(self::TABLE, ['name' => 'views', 'hits' => 1, 'note' => null], ['note' => UpsertUpdate::Replace]));
        self::assertSame([['views', '1', null]], $this->rowsWithoutId());

        $message = self::invalidQuery(fn(): int => $this->connection->upsert(self::TABLE, ['name' => 'views', 'note' => null], ['note' => UpsertUpdate::Increment]));

        self::assertSame('Connection::upsert cannot increment note by null', $message);
        self::assertSame([['views', '1', null]], $this->rowsWithoutId());
    }

    /**
     * $updateが空、$updateのキーが$valuesに無い、識別子が不正の場合は、InvalidQueryExceptionになり、行は変わらない。
     */
    public function testInvalidArgumentsAreRejected(): void
    {
        $this->connection->upsert(self::TABLE, ['name' => 'views', 'hits' => 1], ['hits' => UpsertUpdate::Increment]);
        $connection = $this->connection;

        self::assertSame('Connection::upsert requires at least one column to update', self::invalidQuery(static function () use ($connection): int {
            // 型の宣言(non-empty-array)に合わない空の配列を、実行時の検査に届けるため、リフレクションで渡す
            $rows = (new ReflectionMethod(Connection::class, 'upsert'))->invoke($connection, self::TABLE, ['name' => 'views', 'hits' => 1], []);
            self::assertIsInt($rows);

            return $rows;
        }));
        self::assertSame('Connection::upsert cannot update note; the columns to update must be in the values (keys are compared case-sensitively)', self::invalidQuery(static fn(): int => $connection->upsert(self::TABLE, ['name' => 'views', 'hits' => 1], ['note' => UpsertUpdate::Replace])));
        self::assertSame('invalid SQL identifier: hits = 0, name', self::invalidQuery(static fn(): int => $connection->upsert(self::TABLE, ['name' => 'views', 'hits = 0, name' => 1], ['name' => UpsertUpdate::Replace])));
        self::assertSame('invalid bare table name: TMSWP_IT_UPSERTS', self::invalidQuery(static fn(): int => $connection->upsert('TMSWP_IT_UPSERTS', ['name' => 'views', 'hits' => 1], ['hits' => UpsertUpdate::Increment])));
        self::assertSame([['views', '1', null]], $this->rowsWithoutId());
    }

    /**
     * upsertは警告を出さない。MySQL 9.7でもVALUES()を使わないため、非推奨の警告1287が出ない。確かめ方が働くことは、
     * MySQLでVALUES()を使う形が1287を出すことで確かめる(MariaDBではVALUES()も警告を出さない)。
     */
    public function testUpsertRaisesNoWarningWhileValuesFunctionIsDeprecatedOnMysql(): void
    {
        $this->connection->upsert(self::TABLE, ['name' => 'views', 'hits' => 1], ['hits' => UpsertUpdate::Increment]);
        self::assertSame([], $this->warnings());
        $this->connection->upsert(self::TABLE, ['name' => 'views', 'hits' => 1], ['hits' => UpsertUpdate::Increment]);
        self::assertSame([], $this->warnings());

        // SHOW WARNINGSは直前の文の警告を返すため、版はVALUES()の形の前に読む
        $version = $this->connection->selectVar('SELECT VERSION()');
        self::assertIsString($version);
        $this->connection->execute('INSERT INTO ' . $this->connection->tableName(self::TABLE) . ' (name, hits) VALUES (%s, %d) ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)', ['views', 1]);
        self::assertSame(str_contains($version, 'MariaDB') ? [] : ['1287'], $this->warnings());
        self::assertSame([['views', '3', null]], $this->rowsWithoutId());
    }

    /**
     * 更新になったupsertも、AUTO_INCREMENTの値を1つ消費する。Aを挿入し、Aをupsertで更新してから、Bを挿入すると、Bの
     * IDはAのIDより2大きい(innodb_autoinc_lock_modeが1のMariaDBと2のMySQLで同じ)。
     */
    public function testUpdatingUpsertConsumesAnAutoIncrementValue(): void
    {
        self::assertSame(1, $this->connection->upsert(self::TABLE, ['name' => 'a', 'hits' => 1], ['hits' => UpsertUpdate::Increment]));
        $a = $this->connection->insertId();
        self::assertSame(2, $this->connection->upsert(self::TABLE, ['name' => 'a', 'hits' => 1], ['hits' => UpsertUpdate::Increment]));
        self::assertSame(1, $this->connection->upsert(self::TABLE, ['name' => 'b', 'hits' => 1], ['hits' => UpsertUpdate::Increment]));

        self::assertSame($a + 2, $this->connection->insertId());
    }

    /** Model::upsert()は、そのModelのテーブルにupsertし、影響行数を返す。 */
    public function testModelUpsertWritesToItsTable(): void
    {
        self::assertSame(1, UpsertIntegrationTestCounterModel::upsert(['name' => 'views', 'hits' => 4], ['hits' => UpsertUpdate::Increment]));
        $id = UpsertIntegrationTestCounterModel::connection()->insertId();
        self::assertSame(2, UpsertIntegrationTestCounterModel::upsert(['name' => 'views', 'hits' => 4], ['hits' => UpsertUpdate::Increment]));
        self::assertSame($id, UpsertIntegrationTestCounterModel::connection()->insertId());
        self::assertSame(1, UpsertIntegrationTestCounterModel::count());
        self::assertSame([['views', '8', null]], $this->rowsWithoutId());
    }

    /**
     * 直前の文の警告のエラー番号。
     *
     * @return list<string>
     */
    private function warnings(): array
    {
        return array_map(static function (array $row): string {
            $code = $row['Code'] ?? null;
            self::assertTrue(\is_string($code) || \is_int($code));

            return (string) $code;
        }, $this->connection->select('SHOW WARNINGS'));
    }

    /** @return list<list<mixed>> idの順の、id・name・hits・noteの行 */
    private function rows(): array
    {
        return array_map(array_values(...), $this->connection->select('SELECT id, name, hits, note FROM ' . $this->connection->tableName(self::TABLE) . ' ORDER BY id'));
    }

    /** @return list<list<mixed>> idの順の、name・hits・noteの行 */
    private function rowsWithoutId(): array
    {
        return array_map(array_values(...), $this->connection->select('SELECT name, hits, note FROM ' . $this->connection->tableName(self::TABLE) . ' ORDER BY id'));
    }

    /** @param Closure(): int $call */
    private static function invalidQuery(Closure $call): string
    {
        try {
            $call();
        } catch (InvalidQueryException $exception) {
            return $exception->getMessage();
        }

        self::fail('expected InvalidQueryException');
    }
}

final class UpsertIntegrationTestCounterModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_it_upserts')
            ->id()
            ->string('name', 40)
            ->int('hits')
            ->string('note', 40, nullable: true)
            ->unique('name', 'name_unique');
    }
}
