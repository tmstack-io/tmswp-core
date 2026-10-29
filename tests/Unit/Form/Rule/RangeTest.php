<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Range;
use TMSWP\Core\Form\Validator;
use TMSWP\Testing\UnitTestCase;

/**
 * 同梱の規則Rangeの仕様(docs/05 §同梱ルール一覧)。
 */
final class RangeTest extends UnitTestCase
{
    /**
     * 数値の文字列でなければ違反し、数値なら下限と上限を含む範囲の中で合格。空値は検証しない。
     *
     * @return iterable<string, array{int|float, int|float, string, bool}>
     */
    public static function values(): iterable
    {
        yield 'inside' => [18, 65, '20', true];
        yield 'above the max' => [18, 65, '80', false];
        yield 'just below the min' => [18, 65, '17', false];
        yield 'the min' => [18, 65, '18', true];
        yield 'the max' => [18, 65, '65', true];
        yield 'just above the max' => [18, 65, '66', false];
        yield 'fraction below the min' => [18, 65, '17.9', false];
        yield 'max written as a float' => [18, 65, '65.0', true];
        yield 'exponent below the min' => [18, 65, '1e1', false];
        yield 'not numeric' => [18, 65, 'abc', false];
        yield 'empty value' => [18, 65, '', true];
        yield 'below the float min' => [0.5, 1.5, '0.4', false];
        yield 'the float min' => [0.5, 1.5, '0.5', true];
        yield 'the float max' => [0.5, 1.5, '1.5', true];
        yield 'above the float max' => [0.5, 1.5, '1.6', false];
    }

    #[DataProvider('values')]
    public function testRangeAcceptsNumbersBetweenTheMinAndTheMax(int|float $min, int|float $max, string $value, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('age', 'Age', [new Range($min, $max)]);

        self::assertSame(
            $valid ? [] : ['age' => ["Age must be between {$min} and {$max}."]],
            (new Validator())->validate($definition, ['age' => $value]),
        );
    }
}
