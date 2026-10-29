<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Choice;
use TMSWP\Core\Form\Validator;
use TMSWP\Testing\UnitTestCase;

/**
 * 同梱の規則Choiceの仕様(docs/05 §同梱ルール一覧)。旧iwf2bのChoiceRuleTestの移植先(docs/10 §8.1の10)。
 * choicesを持つ項目への暗黙の選択肢整合検証はValidatorTestで確かめる。
 */
final class ChoiceTest extends UnitTestCase
{
    /**
     * 旧 ChoiceRuleTest。選べる値と、文字列にした値を厳密に比べる(旧 strict の指定は無く、常に文字列で比べる)。
     *
     * @return iterable<string, array{mixed, bool}>
     */
    public static function choiceValues(): iterable
    {
        yield 'choice_1' => ['choice_1', true];
        yield 'choice_2' => ['choice_2', true];
        yield 'string 10 for the int choice 10' => ['10', true];
        yield 'int 10' => [10, true];
        yield 'string 0.01 for the float choice 0.01' => ['0.01', true]; // 旧 strict: 不合格
        yield 'float 0.01' => [0.01, true];
        yield 'invalid choice' => ['invalid_choice', false];
        yield 'list in one string' => ['choice_1 choice_2, 10', false];
        yield 'numeric string of another form' => ['10.0', false];
        yield 'leading space' => [' 10', false];
        yield 'array' => [['choice_1'], false];
    }

    #[DataProvider('choiceValues')]
    public function testChoiceAcceptsOnlyTheChoicesComparedAsStrings(mixed $value, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('value', 'Value', [new Choice(['choice_1', 'choice_2', 10, 0.01])]);

        self::assertSame($valid ? [] : ['value' => ['Value has an invalid choice.']], (new Validator())->validate($definition, ['value' => $value]));
    }

    /**
     * 文字列の選択肢は、大文字と小文字を区別して比べる。空値は検証しない。
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function valuesForStringChoices(): iterable
    {
        yield 'first choice' => ['a', true];
        yield 'second choice' => ['b', true];
        yield 'outside the choices' => ['x', false];
        yield 'other case' => ['A', false];
        yield 'empty value' => ['', true];
    }

    #[DataProvider('valuesForStringChoices')]
    public function testChoiceComparesStringChoicesStrictly(string $value, bool $valid): void
    {
        $definition = FormDefinition::create('contact')->text('kind', 'Type', [new Choice(['a', 'b'])]);

        self::assertSame($valid ? [] : ['kind' => ['Type has an invalid choice.']], (new Validator())->validate($definition, ['kind' => $value]));
    }

    /**
     * 旧ChoiceRuleTestのmultiple。複数選択の項目は要素ごとに確かめ、1つでも選べない値があれば違反。旧FWはmultipleの
     * 指定で配列でない値を例外にした。新しいFWは1つの要素として確かめる。
     *
     * @return iterable<string, array{string|list<string>, bool}>
     */
    public static function valuesOfAMultipleField(): iterable
    {
        yield 'one choice' => [['choice'], true];
        yield 'every choice' => [['choice', '10', '0.01'], true];
        yield 'one element outside the choices' => [['choice', 'invalid_choice'], false];
        yield 'scalar choice' => ['choice', true];
        yield 'scalar outside the choices' => ['invalid_choice', false];
    }

    /** @param string|list<string> $value */
    #[DataProvider('valuesOfAMultipleField')]
    public function testChoiceChecksEachElementOfAMultipleField(string|array $value, bool $valid): void
    {
        $definition = FormDefinition::create('form')
            ->checkboxes('topics', 'Topics', ['choice' => 'Choice', '10' => 'Ten', '0.01' => 'Hundredth'], [new Choice(['choice', 10, 0.01])]);

        self::assertSame($valid ? [] : ['topics' => ['Topics has an invalid choice.']], (new Validator())->validate($definition, ['topics' => $value]));
    }
}
