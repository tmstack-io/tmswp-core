<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Support;

use DateTimeImmutable;
use Generator;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use Stringable;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Support\Str;
use TMSWP\Testing\UnitTestCase;

/**
 * Support\Strの仕様(docs/07 §3)。旧iwf2bのTextTestのうち、新しいAPIにあるものの移植先(docs/10 §8.1の4)。
 */
final class StrTest extends UnitTestCase
{
    /**
     * replace()は{key}を1回の走査で置き換え、置き換えた値の中のプレースホルダは再び置き換えない(§3.2A)。
     *
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function replaceCases(): iterable
    {
        yield 'value containing another placeholder' => ['Hello {name}', ['name' => 'Taro {nested}', 'nested' => 'ignored'], 'Hello Taro {nested}'];
        yield 'placeholder not in the vars' => ['Hello {name}', [], 'Hello {name}'];
        yield 'non-string value' => ['{count} items', ['count' => 3], '3 items'];
        yield 'other delimiters are not placeholders' => ['%name%', ['name' => 'Taro'], '%name%'];
    }

    /** @param array<string, mixed> $vars */
    #[DataProvider('replaceCases')]
    public function testReplaceSubstitutesPlaceholdersInOnePass(string $text, array $vars, string $expected): void
    {
        self::assertSame($expected, Str::replace($text, $vars));
    }

    /**
     * truncate()は文字数で数え、長さちょうどまでは変えず、超えたら省略記号を足す(省略記号は長さに含めない)。
     *
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function truncateCases(): iterable
    {
        yield 'longer than the length' => ['あいうえお', 3, '…', 'あいう…'];
        yield 'exactly the length' => ['あいう', 3, '…', 'あいう'];
        yield 'shorter than the length' => ['あい', 3, '…', 'あい'];
        yield 'custom ellipsis' => ['abcdef', 3, '...', 'abc...'];
        yield 'length of 1' => ['ab', 1, '…', 'a…'];
    }

    #[DataProvider('truncateCases')]
    public function testTruncateCountsCharacters(string $text, int $length, string $ellipsis, string $expected): void
    {
        self::assertSame($expected, Str::truncate($text, $length, $ellipsis));
    }

    /** @return iterable<string, array{int}> */
    public static function invalidTruncateLengths(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('invalidTruncateLengths')]
    public function testTruncateRejectsInvalidLength(int $length): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::truncate('abc', $length);
    }

    /**
     * convertEol()はCRLFを1つの改行として扱い、置き換えた後の改行を再び置き換えない(§3.5)。
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function convertEolCases(): iterable
    {
        yield 'to LF' => ["a\r\nb\rc", "\n", "a\nb\nc"];
        yield 'to CRLF' => ["a\r\nb\rc\nd", "\r\n", "a\r\nb\r\nc\r\nd"];
        yield 'to a tag' => ["a\r\nb\rc", '<br>', 'a<br>b<br>c'];
        yield 'CRLF followed by LF' => ["a\r\n\nb", "\r\n", "a\r\n\r\nb"];
    }

    #[DataProvider('convertEolCases')]
    public function testConvertEolUnifiesLineBreaks(string $text, string $to, string $expected): void
    {
        self::assertSame($expected, Str::convertEol($text, $to));
    }

    /**
     * ケース変換(§3.4)。連続する大文字を頭字語として扱わない。
     *
     * @return iterable<string, array{'snake'|'kebab'|'pascal'|'camel', string, string}>
     */
    public static function caseConversions(): iterable
    {
        yield 'snake from Pascal' => ['snake', 'ProductPost', 'product_post'];
        yield 'snake from kebab' => ['snake', 'product-post', 'product_post'];
        yield 'snake of an acronym' => ['snake', 'ABTest', 'a_b_test'];
        yield 'kebab from Pascal' => ['kebab', 'ProductPost', 'product-post'];
        yield 'kebab from snake' => ['kebab', 'product_post', 'product-post'];
        yield 'pascal from kebab' => ['pascal', 'product-post', 'ProductPost'];
        yield 'pascal from snake' => ['pascal', 'product_post', 'ProductPost'];
        yield 'camel from snake' => ['camel', 'product_post', 'productPost'];
    }

    /** @param 'snake'|'kebab'|'pascal'|'camel' $method */
    #[DataProvider('caseConversions')]
    public function testCaseConversion(string $method, string $text, string $expected): void
    {
        $converted = match ($method) {
            'snake' => Str::snake($text),
            'kebab' => Str::kebab($text),
            'pascal' => Str::pascal($text),
            'camel' => Str::camel($text),
        };

        self::assertSame($expected, $converted);
    }

    /**
     * stringify()の変換規則(§3.3)を1つずつ。
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function stringifyCases(): iterable
    {
        yield 'null' => [null, ''];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'int' => [10, '10'];
        yield 'backed enum' => [StrBackedEnum::Draft, 'draft'];
        yield 'unit enum' => [StrUnitEnum::Published, 'Published'];
        yield 'date' => [new DateTimeImmutable('2026-06-13 00:00:00 UTC'), '2026-06-13T00:00:00+00:00'];
        yield 'traversable' => [self::generator(), 'a, b'];
        yield 'nested array' => [['a', ['b']], 'a, b'];
        yield 'stringable' => [new StrStringable(), 'stringable'];
        yield 'json serializable' => [new StrJsonSerializable(), '{"key":"value"}'];
        yield 'closure' => [static fn(): null => null, '(Closure)'];
    }

    #[DataProvider('stringifyCases')]
    public function testStringifyFollowsTheConversionRules(mixed $value, string $expected): void
    {
        self::assertSame($expected, Str::stringify($value));
    }

    /** @return Generator<int, string> */
    private static function generator(): Generator
    {
        yield 'a';
        yield 'b';
    }
}

enum StrBackedEnum: string
{
    case Draft = 'draft';
}

enum StrUnitEnum
{
    case Published;
}

final class StrStringable implements Stringable
{
    public function __toString(): string
    {
        return 'stringable';
    }
}

final class StrJsonSerializable implements JsonSerializable
{
    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return ['key' => 'value'];
    }
}
