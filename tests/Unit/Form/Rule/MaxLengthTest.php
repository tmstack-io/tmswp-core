<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\Form\Validator;
use TMSWP\Testing\UnitTestCase;

/**
 * 同梱の規則MaxLengthの仕様(docs/05 §同梱ルール一覧)。文字数の境界値は旧iwf2bのExactLengthRuleTestから移植する
 * (docs/10 §8.1の12)。
 */
final class MaxLengthTest extends UnitTestCase
{
    /**
     * mb_strlenで数えた文字数が上限以下なら合格。空値は検証しない。
     *
     * @return iterable<string, array{int, string, bool}>
     */
    public static function lengthBoundaries(): iterable
    {
        yield '9 characters' => [10, 'abcdefghi', true];
        yield '10 characters' => [10, 'abcdefghij', true];
        yield '11 characters' => [10, 'abcdefghijk', false];
        yield '9 multibyte characters' => [10, 'あいうえおかきくけ', true];
        yield '10 multibyte characters' => [10, 'あいうえおかきくけこ', true];
        yield '11 multibyte characters' => [10, 'あいうえおかきくけこさ', false];
        yield '3 characters for the length 3' => [3, 'abc', true];
        yield '4 characters for the length 3' => [3, 'abcd', false];
        yield 'empty value' => [3, '', true];
    }

    #[DataProvider('lengthBoundaries')]
    public function testMaxLengthCountsMultibyteCharacters(int $length, string $value, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('name', 'Name', [new MaxLength($length)]);

        self::assertSame(
            $valid ? [] : ['name' => ["Name must be {$length} characters or fewer."]],
            (new Validator())->validate($definition, ['name' => $value]),
        );
    }

    /**
     * 複数選択の項目への MaxLength は、先頭だけでなく全要素に適用する。先頭は正しく、2番目以降だけが長さの違反になる
     * 値(どの要素も選択肢の中)で確かめる。
     *
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function elementsOfAMultipleField(): iterable
    {
        yield 'every element within the length' => [['ok', 'yes'], true];
        yield 'second element over the length' => [['ok', 'long'], false];
        yield 'last element over the length' => [['ok', 'yes', 'long'], false];
    }

    /** @param list<string> $value */
    #[DataProvider('elementsOfAMultipleField')]
    public function testMaxLengthOfAMultipleFieldChecksEveryElementNotOnlyTheFirst(array $value, bool $valid): void
    {
        $definition = FormDefinition::create('inquiry')
            ->checkboxes('length', 'Length', ['ok' => 'OK', 'yes' => 'Yes', 'long' => 'Long'], [new MaxLength(3)]);

        self::assertSame($valid ? [] : ['length' => ['Length must be 3 characters or fewer.']], (new Validator())->validate($definition, ['length' => $value]));
    }
}
