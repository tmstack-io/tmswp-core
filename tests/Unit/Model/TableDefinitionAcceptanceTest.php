<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Model;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Exception\SchemaException;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\TableDefinition;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;

/**
 * TableDefinitionの受け入れ基準(docs/06の受け入れ基準「TableDefinition」)を、WordPressなしで確かめる。
 */
final class TableDefinitionAcceptanceTest extends UnitTestCase
{
    /** 正準形の宣言のtoSql()が、dbDeltaの要件(1行1定義・PRIMARY KEYの後の空白2つ・小文字の型名・バッククォートなし)を満たす。 */
    public function testCanonicalDeclarationBuildsDbDeltaCompatibleSql(): void
    {
        $sql = self::canonical()->toSql(self::connection());

        self::assertSame(<<<'SQL'
CREATE TABLE wp_inquiries (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
email varchar(191) NOT NULL,
body text DEFAULT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL, $sql);
        self::assertStringNotContainsString('`', $sql);
    }

    /** 同じ宣言からのtoSql()・hash()は、何回呼んでも、別に組み立てた同じ宣言からでも、バイト単位で同じになる。 */
    public function testToSqlAndHashAreByteIdenticalForTheSameDeclaration(): void
    {
        $connection = self::connection();
        $definition = self::detailed();
        $sql = $definition->toSql($connection);
        $hash = $definition->hash($connection);

        self::assertSame($sql, $definition->toSql($connection));
        self::assertSame($sql, self::detailed()->toSql($connection));
        self::assertSame($hash, $definition->hash($connection));
        self::assertSame($hash, self::detailed()->hash($connection));
        self::assertSame(hash('sha256', $sql), $hash);
        // 宣言が違えばハッシュも違う(ハッシュの比較で変更を見つけられる)
        self::assertNotSame($hash, self::detailed()->string('extra')->hash($connection));
    }

    /** toSql()のキーの行は、PRIMARY KEY → UNIQUE KEY(宣言順) → KEY(宣言順)の順に並ぶ(docs/06 §2のtoSql()の手順3)。 */
    public function testKeyLinesAreOrderedPrimaryThenUniqueThenKey(): void
    {
        $sql = self::detailed()->toSql(self::connection());

        self::assertStringContainsString(
            "PRIMARY KEY  (id),\nUNIQUE KEY code_unique (code),\nUNIQUE KEY email_unique (email),\nKEY group_id (group_id),\nKEY status_code (status,code)\n)",
            $sql,
        );
    }

    /** json()はlongtextの列を作り、JSON型を出さない。 */
    public function testJsonColumnIsLongtext(): void
    {
        $sql = TableDefinition::create('payloads')
            ->id()
            ->json('meta')
            ->json('required_meta', nullable: false)
            ->toSql(self::connection());

        self::assertStringContainsString("\nmeta longtext DEFAULT NULL,\n", $sql);
        self::assertStringContainsString("\nrequired_meta longtext NOT NULL,\n", $sql);
        // 列の行の型(列名の次の語)にjsonが無い
        self::assertDoesNotMatchRegularExpression('/^\w+ json\b/im', $sql);
    }

    /** foreignId()は列とKEY {name} ({name})を作り、FOREIGN KEYの制約を作らない。 */
    public function testForeignIdBuildsColumnAndKeyWithoutForeignKeyConstraint(): void
    {
        $sql = TableDefinition::create('members')->id()->foreignId('group_id')->toSql(self::connection());

        self::assertStringContainsString("\ngroup_id bigint(20) unsigned NOT NULL,\n", $sql);
        self::assertStringContainsString("\nKEY group_id (group_id)\n", $sql);
        self::assertStringNotContainsString('FOREIGN', $sql);
        self::assertStringNotContainsString('REFERENCES', $sql);
    }

    /**
     * 主キーの未定義・同名の列・text系の列への索引・64文字を超えるテーブル名と索引の名前はSchemaExceptionになる。
     *
     * @return iterable<string, array{Closure(Connection): mixed, string}>
     */
    public static function invalidSchemas(): iterable
    {
        yield 'primary key is not declared (toSql)' => [
            static fn(Connection $connection): string => TableDefinition::create('no_keys')->string('code')->toSql($connection),
            'table must declare a primary key',
        ];
        yield 'primary key is not declared (primaryColumns)' => [
            static fn(): array => TableDefinition::create('no_keys')->string('code')->primaryColumns(),
            'table must declare a primary key',
        ];
        yield 'duplicate column' => [
            static fn(): TableDefinition => TableDefinition::create('dupes')->id()->string('code')->int('code'),
            'duplicate column: code',
        ];
        yield 'duplicate of the id column' => [
            static fn(): TableDefinition => TableDefinition::create('dupes')->id()->bigint('id'),
            'duplicate column: id',
        ];
        yield 'index on text column' => [
            static fn(): TableDefinition => TableDefinition::create('texts')->id()->text('body')->index('body'),
            "column 'body' cannot be indexed",
        ];
        yield 'unique on json (longtext) column' => [
            static fn(): TableDefinition => TableDefinition::create('texts')->id()->json('meta')->unique('meta'),
            "column 'meta' cannot be indexed",
        ];
        yield 'full table name of 65 characters' => [
            // prefixのwp_を含めて65文字
            static fn(Connection $connection): string => TableDefinition::create(str_repeat('t', 62))->id()->toSql($connection),
            'full table name exceeds 64 characters: wp_' . str_repeat('t', 62),
        ];
        yield 'explicit index name of 65 characters' => [
            static fn(): TableDefinition => TableDefinition::create('long_keys')->id()->string('code')->index('code', str_repeat('k', 65)),
            'index name exceeds 64 characters: ' . str_repeat('k', 65),
        ];
        yield 'automatic unique name over 64 characters' => [
            static fn(): TableDefinition => TableDefinition::create('long_keys')->id()->string(str_repeat('a', 40))->string(str_repeat('b', 40))
                ->unique([str_repeat('a', 40), str_repeat('b', 40)]),
            'index name exceeds 64 characters: ' . str_repeat('a', 40) . '_' . str_repeat('b', 40),
        ];
    }

    /** @param Closure(Connection): mixed $declare */
    #[DataProvider('invalidSchemas')]
    public function testInvalidSchemaThrowsSchemaException(Closure $declare, string $expectedMessage): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage($expectedMessage);

        $declare(self::connection());
    }

    /** 64文字ちょうどのフルテーブル名と索引の名前は受け付ける(境界)。 */
    public function testNamesOfExactly64CharactersAreAccepted(): void
    {
        $sql = TableDefinition::create(str_repeat('t', 61))
            ->id()
            ->string('code')
            ->index('code', str_repeat('k', 64))
            ->toSql(self::connection());

        self::assertStringStartsWith('CREATE TABLE wp_' . str_repeat('t', 61) . " (\n", $sql);
        self::assertStringContainsString("\nKEY " . str_repeat('k', 64) . ' (code)', $sql);
    }

    /** docs/06の受け入れ基準の正準形 */
    private static function canonical(): TableDefinition
    {
        return TableDefinition::create('inquiries')->id()->string('email', 191)->text('body')->datetime('created_at');
    }

    /** 列の種類・索引・charsetを一通り含む宣言 */
    private static function detailed(): TableDefinition
    {
        return TableDefinition::create('members')
            ->id()
            ->string('email', default: '')
            ->string('code', 32)
            ->string('status', 20)
            ->int('score', default: 0)
            ->decimal('rate', 5, 2, default: '1.50')
            ->bool('active', true)
            ->datetime('created_at', default: 'CURRENT_TIMESTAMP')
            ->json('meta')
            ->foreignId('group_id')
            ->unique('code', 'code_unique')
            ->index(['status', 'code'])
            ->unique('email', 'email_unique')
            ->charset('utf8mb4')
            ->collation('utf8mb4_unicode_ci');
    }

    private static function connection(): Connection
    {
        return new Connection(WpdbTestDouble::create());
    }
}
