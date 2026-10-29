<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Support\Arr;
use TMSWP\Testing\UnitTestCase;

/**
 * Support\Arrのドット記法と補助関数の仕様(docs/07 §2.3)。旧iwf2bのArrTestの移植先(docs/10 §8.1の3)。
 */
final class ArrTest extends UnitTestCase
{
    /**
     * get()の境界。intのキーは分解せず、数値の文字列のセグメントはPHPの配列キーの正規化に従い、途中で配列でなくなれば既定値。
     *
     * @return iterable<string, array{string|int, mixed, mixed}>
     */
    public static function getCases(): iterable
    {
        yield 'nested path' => ['user.address.city', null, 'Tokyo'];
        yield 'existing null value' => ['user.name', 'default', null];
        yield 'missing leaf with a default' => ['user.address.zip', 'missing', 'missing'];
        yield 'int key' => [10, null, 'ten'];
        yield 'numeric string segment' => ['10', null, 'ten'];
        yield 'path through a scalar' => ['user.address.city.name', 'default', 'default'];
        yield 'literal dot in a data key' => ['dotted.key', 'default', 'default'];
    }

    #[DataProvider('getCases')]
    public function testGetFollowsTheDotNotation(string|int $key, mixed $default, mixed $expected): void
    {
        self::assertSame($expected, Arr::get(self::sample(), $key, $default));
    }

    /**
     * has()の境界。値がnullでもキーがあればtrue。
     *
     * @return iterable<string, array{string|int, bool}>
     */
    public static function hasCases(): iterable
    {
        yield 'existing null value' => ['user.name', true];
        yield 'int key' => [10, true];
        yield 'nested path' => ['user.address.city', true];
        yield 'missing leaf' => ['user.address.zip', false];
        yield 'path through a scalar' => ['user.address.city.name', false];
        yield 'literal dot in a data key' => ['dotted.key', false];
    }

    #[DataProvider('hasCases')]
    public function testHasFollowsTheDotNotation(string|int $key, bool $expected): void
    {
        self::assertSame($expected, Arr::has(self::sample(), $key));
    }

    /**
     * set()の境界。中間のノードが配列でなければ空の配列で上書きし、intのキーは分解しない。
     *
     * @return iterable<string, array{string|int, mixed, array<int|string, mixed>}>
     */
    public static function setCases(): iterable
    {
        yield 'new leaf in a nested path' => [
            'user.address.zip',
            '100-0001',
            ['user' => ['address' => ['city' => 'Tokyo', 'zip' => '100-0001'], 'name' => null], 10 => 'ten', 'dotted.key' => 'literal'],
        ];
        yield 'non-array node is overwritten' => [
            'user.name.first',
            'Taro',
            ['user' => ['address' => ['city' => 'Tokyo'], 'name' => ['first' => 'Taro']], 10 => 'ten', 'dotted.key' => 'literal'],
        ];
        yield 'int key' => [
            10,
            'TEN',
            ['user' => ['address' => ['city' => 'Tokyo'], 'name' => null], 10 => 'TEN', 'dotted.key' => 'literal'],
        ];
        yield 'literal dot is split' => [
            'dotted.key',
            'new',
            ['user' => ['address' => ['city' => 'Tokyo'], 'name' => null], 10 => 'ten', 'dotted.key' => 'literal', 'dotted' => ['key' => 'new']],
        ];
    }

    /** @param array<int|string, mixed> $expected */
    #[DataProvider('setCases')]
    public function testSetFollowsTheDotNotation(string|int $key, mixed $value, array $expected): void
    {
        $array = self::sample();

        Arr::set($array, $key, $value);

        self::assertSame($expected, $array);
        self::assertSame($value, Arr::get($array, $key));
    }

    /**
     * only()はトップレベルのキーだけを、元の配列の順に取り出し、無いキーは含めない。
     *
     * @return iterable<string, array{list<string|int>, array<int|string, mixed>}>
     */
    public static function onlyCases(): iterable
    {
        $sample = self::sample();

        yield 'existing and missing keys' => [['user', 'missing'], ['user' => $sample['user']]];
        yield 'order of the original array' => [[10, 'user'], ['user' => $sample['user'], 10 => 'ten']];
        yield 'dot notation is not followed' => [['user.name'], []];
        yield 'no keys' => [[], []];
    }

    /**
     * @param list<string|int> $keys
     * @param array<int|string, mixed> $expected
     */
    #[DataProvider('onlyCases')]
    public function testOnlyPicksTopLevelKeysInTheOriginalOrder(array $keys, array $expected): void
    {
        self::assertSame($expected, Arr::only(self::sample(), $keys));
    }

    /**
     * first()は先頭の要素、または条件を最初に満たす要素を返し、無ければ既定値。
     *
     * @return iterable<string, array{array<int|string, mixed>, (callable(mixed, string|int): bool)|null, mixed}>
     */
    public static function firstCases(): iterable
    {
        yield 'predicate' => [self::sample(), static fn(mixed $value): bool => $value === 'ten', 'ten'];
        yield 'predicate with the key' => [['a' => 1, 'b' => 2], static fn(mixed $value, string|int $key): bool => $key === 'b', 2];
        yield 'no predicate' => [['a' => 1, 'b' => 2], null, 1];
        yield 'no match' => [['a' => 1], static fn(mixed $value): bool => false, 'default'];
        yield 'empty array' => [[], null, 'default'];
    }

    /** @param array<int|string, mixed> $array */
    #[DataProvider('firstCases')]
    public function testFirstReturnsTheFirstMatchingElementOrTheDefault(array $array, ?callable $predicate, mixed $expected): void
    {
        self::assertSame($expected, Arr::first($array, $predicate, 'default'));
    }

    /**
     * wrap()はnullを空の配列に、配列をそのまま、それ以外を1要素の配列にする。
     *
     * @return iterable<string, array{mixed, array<int|string, mixed>}>
     */
    public static function wrapCases(): iterable
    {
        yield 'null' => [null, []];
        yield 'string' => ['x', ['x']];
        yield 'empty string' => ['', ['']];
        yield 'false' => [false, [false]];
        yield 'array' => [['a' => 1], ['a' => 1]];
        yield 'empty array' => [[], []];
    }

    /** @param array<int|string, mixed> $expected */
    #[DataProvider('wrapCases')]
    public function testWrapMakesAnArray(mixed $value, array $expected): void
    {
        self::assertSame($expected, Arr::wrap($value));
    }

    /** @return array<int|string, mixed> */
    private static function sample(): array
    {
        return [
            'user' => ['address' => ['city' => 'Tokyo'], 'name' => null],
            10 => 'ten',
            'dotted.key' => 'literal',
        ];
    }
}
