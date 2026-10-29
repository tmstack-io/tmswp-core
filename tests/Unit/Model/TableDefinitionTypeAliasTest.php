<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Exception\SchemaException;
use TMSWP\Core\Model\Connection;
use TMSWP\Core\Model\TableDefinition;
use TMSWP\Core\Tests\Support\RawColumnTypes;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;

/**
 * rawColumn() の型の別名と、DB が別の表記で記録する型の表記(serial・精度の引数が1つの float / double・表示幅の無い
 * 整数の型)は、宣言の時点で拒否する。dbDelta は宣言の型名と DB の型名を文字列で比べるため、そのままでは適用の後に
 * 差分が永久に残り、`wp tmswp migrate` が必ず失敗する(06-model §2)。
 */
final class TableDefinitionTypeAliasTest extends UnitTestCase
{
    /** @return iterable<string, array{string, string, string}> 定義、別名、案内する型名 */
    public static function aliases(): iterable
    {
        yield 'integer' => ['integer(11) NOT NULL', 'integer', 'int'];
        yield 'numeric' => ['numeric(10,2) NOT NULL', 'numeric', 'decimal'];
        yield 'real' => ['real NOT NULL', 'real', 'double'];
        yield 'boolean' => ['boolean NOT NULL', 'boolean', 'tinyint(1)'];
        yield 'bool' => ['bool NOT NULL', 'bool', 'tinyint(1)'];
        yield 'dec' => ['dec(10,2) NOT NULL', 'dec', 'decimal'];
        yield 'fixed' => ['fixed(10,2) NOT NULL', 'fixed', 'decimal'];
        yield 'character varying' => ['character varying(10) NOT NULL', 'character varying', 'varchar'];
        yield 'char varying' => ['char  varying(10) NOT NULL', 'char varying', 'varchar'];
        yield 'character' => ['character(5) NOT NULL', 'character', 'char'];
        yield 'varcharacter' => ['varcharacter(10) NOT NULL', 'varcharacter', 'varchar'];
        yield 'national' => ['national char(5) NOT NULL', 'national', 'char or varchar'];
        yield 'nchar' => ['nchar(5) NOT NULL', 'nchar', 'char'];
        yield 'nvarchar' => ['nvarchar(5) NOT NULL', 'nvarchar', 'varchar'];
        yield 'int1' => ['int1 NOT NULL', 'int1', 'tinyint'];
        yield 'int2' => ['int2 NOT NULL', 'int2', 'smallint'];
        yield 'int3' => ['int3 NOT NULL', 'int3', 'mediumint'];
        yield 'middleint' => ['middleint NOT NULL', 'middleint', 'mediumint'];
        yield 'int4' => ['int4 NOT NULL', 'int4', 'int'];
        yield 'int8' => ['int8 NOT NULL', 'int8', 'bigint'];
        yield 'float4' => ['float4 NOT NULL', 'float4', 'float'];
        yield 'float8' => ['float8 NOT NULL', 'float8', 'double'];
        yield 'long varchar' => ['long varchar NULL', 'long varchar', 'mediumtext'];
        yield 'long' => ['long NULL', 'long', 'mediumtext'];
        yield 'json' => ['json NULL', 'json', 'longtext'];
        yield '大文字' => ['INTEGER UNSIGNED NOT NULL', 'integer', 'int'];
    }

    #[DataProvider('aliases')]
    public function testRawColumnRejectsTypeAlias(string $definition, string $alias, string $canonical): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage("column 'valuecol' uses the type alias '{$alias}', which the database stores under another name; use '{$canonical}' instead");

        TableDefinition::create('raw_alias')->id()->rawColumn('valuecol', $definition);
    }

    /** @return iterable<string, array{string, string}> 定義、メッセージ */
    public static function nonConvergingNotations(): iterable
    {
        yield 'serial' => ['serial', "column 'valuecol' uses the type 'serial', which the database stores as bigint unsigned with AUTO_INCREMENT; use id() or 'bigint(20) unsigned NOT NULL AUTO_INCREMENT' instead"];
        yield 'float(24)' => ['float(24) NOT NULL', "column 'valuecol' uses 'float(24)' with a single precision argument, which the database stores as float or double; use 'float' for a precision up to 24 or 'double' for 25 to 53 instead"];
        yield 'float(30)' => ['FLOAT( 30 ) NOT NULL', "column 'valuecol' uses 'float(30)' with a single precision argument, which the database stores as float or double; use 'float' for a precision up to 24 or 'double' for 25 to 53 instead"];
        yield 'double(30)' => ['double(30) NOT NULL', "column 'valuecol' uses 'double(30)' with a single precision argument, which the database stores as float or double; use 'float' for a precision up to 24 or 'double' for 25 to 53 instead"];
        yield 'tinyint' => ['tinyint NOT NULL', "column 'valuecol' uses the integer type 'tinyint' without a display width, which MariaDB stores with one; use 'tinyint(4)' instead"];
        yield 'smallint' => ['smallint NOT NULL', "column 'valuecol' uses the integer type 'smallint' without a display width, which MariaDB stores with one; use 'smallint(6)' instead"];
        yield 'mediumint' => ['mediumint NOT NULL', "column 'valuecol' uses the integer type 'mediumint' without a display width, which MariaDB stores with one; use 'mediumint(9)' instead"];
        yield 'int' => ['int NOT NULL', "column 'valuecol' uses the integer type 'int' without a display width, which MariaDB stores with one; use 'int(11)' instead"];
        yield 'bigint unsigned' => ['BIGINT UNSIGNED NOT NULL', "column 'valuecol' uses the integer type 'bigint' without a display width, which MariaDB stores with one; use 'bigint(20)' instead"];
    }

    #[DataProvider('nonConvergingNotations')]
    public function testRawColumnRejectsNotationThatDbDeltaCannotConverge(string $definition, string $message): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage($message);

        TableDefinition::create('raw_notation')->id()->rawColumn('valuecol', $definition);
    }

    /**
     * 統合テスト(MigratorColumnTypeIntegrationTest)が実物のDBで作成と再適用を確かめる定義と同じもの。
     *
     * @return iterable<string, array{string}>
     */
    public static function canonicalTypes(): iterable
    {
        foreach (RawColumnTypes::ACCEPTED as $label => $definition) {
            yield $label => [$definition];
        }

        yield 'bigint(20) unsigned AUTO_INCREMENT' => [RawColumnTypes::ACCEPTED_AUTO_INCREMENT];
    }

    /** dbDelta は列名の後の1つの空白に続く語を型として読むため、定義の前後の空白は除いて書く。 */
    public function testRawColumnTrimsSurroundingWhitespace(): void
    {
        $sql = TableDefinition::create('raw_trim')->id()->rawColumn('valuecol', "  int(11) NOT NULL \t")->toSql(new Connection(WpdbTestDouble::create()));

        self::assertStringContainsString("\nvaluecol int(11) NOT NULL,\n", $sql);
    }

    #[DataProvider('canonicalTypes')]
    public function testRawColumnAcceptsCanonicalType(string $definition): void
    {
        self::assertSame(['id', 'valuecol'], TableDefinition::create('raw_canonical')->id()->rawColumn('valuecol', $definition)->columns());
    }
}
