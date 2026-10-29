<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\MinLength;
use TMSWP\Core\Form\Validator;
use TMSWP\Testing\UnitTestCase;

/**
 * 同梱の規則MinLengthの仕様(docs/05 §同梱ルール一覧)。文字数の境界値は旧iwf2bのExactLengthRuleTestから移植する
 * (docs/10 §8.1の12)。
 */
final class MinLengthTest extends UnitTestCase
{
    /**
     * mb_strlenで数えた文字数が下限以上なら合格。空値は検証しない。
     *
     * @return iterable<string, array{int, string, bool}>
     */
    public static function lengthBoundaries(): iterable
    {
        yield '9 characters' => [10, 'abcdefghi', false];
        yield '10 characters' => [10, 'abcdefghij', true];
        yield '11 characters' => [10, 'abcdefghijk', true];
        yield '9 multibyte characters' => [10, 'あいうえおかきくけ', false];
        yield '10 multibyte characters' => [10, 'あいうえおかきくけこ', true];
        yield '11 multibyte characters' => [10, 'あいうえおかきくけこさ', true];
        yield '1 character for the length 2' => [2, 'a', false];
        yield '2 characters for the length 2' => [2, 'AB', true];
        yield 'empty value' => [2, '', true];
    }

    #[DataProvider('lengthBoundaries')]
    public function testMinLengthCountsMultibyteCharacters(int $length, string $value, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('code', 'Code', [new MinLength($length)]);

        self::assertSame(
            $valid ? [] : ['code' => ["Code must be at least {$length} characters."]],
            (new Validator())->validate($definition, ['code' => $value]),
        );
    }
}
