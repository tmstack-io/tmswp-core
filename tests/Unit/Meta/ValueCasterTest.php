<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Meta;

use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Stringable;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Meta\ValueCaster;
use TMSWP\Testing\UnitTestCase;

final class ValueCasterTest extends UnitTestCase
{
    /** @return iterable<string, array{string, mixed, mixed}> */
    public static function castTable(): iterable
    {
        $object = new stdClass();

        // toInt(): int はそのまま / float は (int) / bool は 0・1 / 数値の文字列は (int)(float) / それ以外は 0
        yield 'toInt int' => ['toInt', 12, 12];
        yield 'toInt float' => ['toInt', 12.8, 12];
        yield 'toInt true' => ['toInt', true, 1];
        yield 'toInt false' => ['toInt', false, 0];
        yield 'toInt numeric string' => ['toInt', '12.8', 12];
        yield 'toInt numeric string with exponent' => ['toInt', '1e3', 1000];
        // (int)(float) のため、float の精度を超える整数の文字列は float に丸めてから整数にする
        yield 'toInt numeric string beyond float precision' => ['toInt', '9007199254740993', 9007199254740992];
        yield 'toInt non-numeric string' => ['toInt', 'abc', 0];
        yield 'toInt array' => ['toInt', [1], 0];
        yield 'toInt object' => ['toInt', $object, 0];
        yield 'toInt null' => ['toInt', null, 0];

        // toFloat(): float はそのまま / int は (float) / bool は 0.0・1.0 / 数値の文字列は (float) / それ以外は 0.0
        yield 'toFloat float' => ['toFloat', 12.5, 12.5];
        yield 'toFloat int' => ['toFloat', 12, 12.0];
        yield 'toFloat true' => ['toFloat', true, 1.0];
        yield 'toFloat false' => ['toFloat', false, 0.0];
        yield 'toFloat numeric string' => ['toFloat', '12.5', 12.5];
        yield 'toFloat non-numeric string' => ['toFloat', 'abc', 0.0];
        yield 'toFloat array' => ['toFloat', [1.5], 0.0];
        yield 'toFloat object' => ['toFloat', $object, 0.0];
        yield 'toFloat null' => ['toFloat', null, 0.0];

        // toBool(): bool はそのまま / ''・'0'・'false'(大文字小文字を問わない)・0・0.0・null・[] は false / それ以外は true
        yield 'toBool true' => ['toBool', true, true];
        yield 'toBool false' => ['toBool', false, false];
        yield 'toBool empty string' => ['toBool', '', false];
        yield 'toBool zero string' => ['toBool', '0', false];
        yield 'toBool false string' => ['toBool', 'false', false];
        yield 'toBool false string in upper case' => ['toBool', 'FALSE', false];
        yield 'toBool zero' => ['toBool', 0, false];
        yield 'toBool zero float' => ['toBool', 0.0, false];
        yield 'toBool null' => ['toBool', null, false];
        yield 'toBool empty array' => ['toBool', [], false];
        yield 'toBool true string' => ['toBool', 'true', true];
        yield 'toBool other string' => ['toBool', 'no', true];
        yield 'toBool non-zero int' => ['toBool', -1, true];
        yield 'toBool non-empty array' => ['toBool', [0], true];
        yield 'toBool object' => ['toBool', $object, true];

        // toString(): string はそのまま / int・float は (string) / bool は '1'・'' / Stringable は (string) / array・object・null は ''
        yield 'toString string' => ['toString', 'value', 'value'];
        yield 'toString int' => ['toString', 12, '12'];
        yield 'toString float' => ['toString', 1.5, '1.5'];
        yield 'toString true' => ['toString', true, '1'];
        yield 'toString false' => ['toString', false, ''];
        yield 'toString stringable' => ['toString', new ValueCasterStringable(), 'stringable'];
        yield 'toString array' => ['toString', ['a'], ''];
        yield 'toString object' => ['toString', $object, ''];
        yield 'toString null' => ['toString', null, ''];

        // toList(): array は array_values() / null は [] / それ以外のスカラーは1要素の list
        yield 'toList list' => ['toList', ['a', 'b'], ['a', 'b']];
        yield 'toList associative array' => ['toList', ['first' => 'a', 'second' => 'b'], ['a', 'b']];
        yield 'toList null' => ['toList', null, []];
        yield 'toList string' => ['toList', 'value', ['value']];
        yield 'toList int' => ['toList', 0, [0]];
        yield 'toList false' => ['toList', false, [false]];

        // toJsonArray(): array はそのまま / 文字列は json_decode(連想配列)。失敗・配列でない結果は [] / それ以外は []
        yield 'toJsonArray array' => ['toJsonArray', ['a' => 1], ['a' => 1]];
        yield 'toJsonArray json object' => ['toJsonArray', '{"a":1}', ['a' => 1]];
        yield 'toJsonArray json list' => ['toJsonArray', '[1,2]', [1, 2]];
        yield 'toJsonArray invalid json' => ['toJsonArray', 'invalid', []];
        yield 'toJsonArray json scalar' => ['toJsonArray', '"text"', []];
        yield 'toJsonArray json null' => ['toJsonArray', 'null', []];
        yield 'toJsonArray int' => ['toJsonArray', 1, []];
        yield 'toJsonArray null' => ['toJsonArray', null, []];
        yield 'toJsonArray object' => ['toJsonArray', $object, []];
    }

    /** ValueCaster のキャスト表(docs/04 §4.7)のすべての行と、表の「それ以外」に当たる入力を固定する。 */
    #[DataProvider('castTable')]
    public function testCastTable(string $method, mixed $input, mixed $expected): void
    {
        $actual = match ($method) {
            'toInt' => ValueCaster::toInt($input),
            'toFloat' => ValueCaster::toFloat($input),
            'toBool' => ValueCaster::toBool($input),
            'toString' => ValueCaster::toString($input),
            'toList' => ValueCaster::toList($input),
            'toJsonArray' => ValueCaster::toJsonArray($input),
            default => self::fail("unknown cast {$method}"),
        };

        self::assertSame($expected, $actual);
    }

    public function testItValidatesSchemaDefaultCompatibility(): void
    {
        self::assertTrue(ValueCaster::accepts(MetaValueType::String, 'value'));
        self::assertTrue(ValueCaster::accepts(MetaValueType::String, 123));
        // Stringable のオブジェクトは受け付けない(オプションは object を保存でき、メタは保存できないため、型の検査で揃えて拒否する)
        self::assertFalse(ValueCaster::accepts(MetaValueType::String, new ValueCasterStringable()));
        self::assertFalse(ValueCaster::accepts(MetaValueType::String, []));

        self::assertTrue(ValueCaster::accepts(MetaValueType::Boolean, false));
        self::assertFalse(ValueCaster::accepts(MetaValueType::Boolean, 0));

        self::assertTrue(ValueCaster::accepts(MetaValueType::Integer, 1));
        self::assertFalse(ValueCaster::accepts(MetaValueType::Integer, 1.0));

        self::assertTrue(ValueCaster::accepts(MetaValueType::Number, 1));
        self::assertTrue(ValueCaster::accepts(MetaValueType::Number, 1.5));
        self::assertFalse(ValueCaster::accepts(MetaValueType::Number, '1.5'));

        self::assertTrue(ValueCaster::accepts(MetaValueType::Array, []));
        self::assertFalse(ValueCaster::accepts(MetaValueType::Array, '[]'));

        self::assertTrue(ValueCaster::accepts(MetaValueType::Object, ['key' => 'value']));
        self::assertFalse(ValueCaster::accepts(MetaValueType::Object, new stdClass()));
    }
}

final class ValueCasterStringable implements Stringable
{
    public function __toString(): string
    {
        return 'stringable';
    }
}
