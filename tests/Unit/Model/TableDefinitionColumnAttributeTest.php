<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Exception\SchemaException;
use TMSWP\Core\Model\TableDefinition;
use TMSWP\Testing\UnitTestCase;

/**
 * TableDefinition が持つ列の属性(NULL の可否・既定値・既定値の種類)。Migrator が適用後の構造と照らし合わせる
 * (06-model §2・§5)。組込のメソッドは引数の値を持ち、SQL の文字列を解析し直さない。
 */
final class TableDefinitionColumnAttributeTest extends UnitTestCase
{
    /** @return iterable<string, array{TableDefinition, bool, string|null, string}> 定義、NULL の可否、既定値、種類 */
    public static function columns(): iterable
    {
        $table = static fn(): TableDefinition => TableDefinition::create('attributes')->id();

        yield '文字列の既定値の中の NOT NULL は制約ではない' => [$table()->string('val', 20, nullable: true, default: 'NOT NULL'), true, 'NOT NULL', 'string'];
        yield '文字列の既定値の中の DEFAULT' => [$table()->string('val', 20, default: 'x DEFAULT y NOT NULL'), false, 'x DEFAULT y NOT NULL', 'string'];
        yield '空の文字列の既定値' => [$table()->string('val', default: ''), false, '', 'string'];
        yield 'NULL を許し既定値の無い文字列' => [$table()->string('val', nullable: true), true, null, 'string'];
        yield 'bool の既定値' => [$table()->bool('val', true), false, '1', 'number'];
        yield 'int の負の既定値' => [$table()->int('val', default: -5), false, '-5', 'number'];
        yield 'bigint の大きな既定値' => [$table()->bigint('val', default: 9007199254740993), false, '9007199254740993', 'number'];
        yield 'decimal の既定値' => [$table()->decimal('val', 30, 2, default: '9007199254740993.10'), false, '9007199254740993.10', 'number'];
        yield 'datetime の CURRENT_TIMESTAMP' => [$table()->datetime('val', default: 'CURRENT_TIMESTAMP'), false, 'CURRENT_TIMESTAMP', 'temporal'];
        yield 'json' => [$table()->json('val'), true, null, 'string'];
        yield 'foreignId' => [$table()->foreignId('val', nullable: true), true, null, 'number'];
        yield 'raw の数値の型' => [$table()->rawColumn('val', 'int(11) NOT NULL DEFAULT 007'), false, '007', 'number'];
        yield 'raw の日時の型と精度' => [$table()->rawColumn('val', 'timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)'), false, 'CURRENT_TIMESTAMP(6)', 'temporal'];
        yield 'raw の DEFAULT NULL' => [$table()->rawColumn('val', 'varchar(20) DEFAULT NULL'), true, null, 'string'];
    }

    #[DataProvider('columns')]
    public function testColumnAttributes(TableDefinition $definition, bool $nullable, ?string $default, string $kind): void
    {
        self::assertSame([$nullable, $default, $kind], [$definition->columnNullable('val'), $definition->columnDefault('val'), $definition->columnKind('val')]);
    }

    /** 主キーの列は、定義に NOT NULL が無くても DB が NOT NULL にする。 */
    public function testPrimaryKeyColumnIsNotNullable(): void
    {
        self::assertFalse(TableDefinition::create('raw_pk')->rawColumn('code', 'char(5)')->primary('code')->columnNullable('code'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDecimalDefaults(): iterable
    {
        yield '指数' => ['1e3'];
        yield '先頭の空白' => [' 1'];
        yield '+ の符号' => ['+1'];
        yield '数でない' => ['abc'];
    }

    /** decimal() の既定値は SQL にそのまま書くため、10進の数の表記だけを受け付ける。 */
    #[DataProvider('invalidDecimalDefaults')]
    public function testDecimalDefaultMustBeDecimalNumber(string $default): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('decimal default must be a decimal number string such as 12.50');

        TableDefinition::create('decimal_default')->id()->decimal('val', default: $default);
    }
}
