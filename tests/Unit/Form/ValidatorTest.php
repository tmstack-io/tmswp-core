<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form;

use ArrayObject;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Callback;
use TMSWP\Core\Form\Rule\Choice;
use TMSWP\Core\Form\Rule\Email;
use TMSWP\Core\Form\Rule\FileExtension;
use TMSWP\Core\Form\Rule\MaxFileSize;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\Form\Rule\MinLength;
use TMSWP\Core\Form\Rule\Range;
use TMSWP\Core\Form\Rule\Regex;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\Form\Rule\RequiredIf;
use TMSWP\Core\Form\Rule\Retype;
use TMSWP\Core\Form\Rule\RuleInterface;
use TMSWP\Core\Form\Rule\Url;
use TMSWP\Core\Form\Validator;
use TMSWP\Testing\UnitTestCase;

/**
 * Validatorの空値の判定と、検証の手順(空値スルー・選択肢整合検証・最初の違反での停止。docs/05 §Validator)。
 * 旧iwf2bのUtilTestのis_emptyとRuleTraitTestの空値スルーの移植先(docs/10 §8.1の5・9)。
 */
final class ValidatorTest extends UnitTestCase
{
    /**
     * 旧 Util::is_empty・RuleTrait::is_empty の境界値。旧 FW は false・空白だけの文字列・要素数0の Countable も空と
     * したが、新しい空値は null・''・[] だけ(チェックボックスの false が検証をすり抜けた罠の修正)。
     *
     * @return iterable<string, array{mixed, bool}>
     */
    public static function emptinessBoundaries(): iterable
    {
        yield 'null' => [null, true];
        yield 'empty string' => ['', true];
        yield 'empty array' => [[], true];
        yield 'false' => [false, false]; // 旧: 空
        yield 'space only' => [' ', false]; // 旧: 空(trim する指定のとき)
        yield 'Countable with no element' => [new ArrayObject(), false]; // 旧: 空
        yield 'zero' => [0, false];
        yield 'string zero' => ['0', false];
        yield 'true' => [true, false];
        yield 'text' => ['test', false];
        yield 'array with an empty string' => [[''], false];
        yield 'Countable with an element' => [new ArrayObject(['a']), false];
    }

    #[DataProvider('emptinessBoundaries')]
    public function testOnlyNullTheEmptyStringAndTheEmptyArrayAreEmpty(mixed $value, bool $empty): void
    {
        self::assertSame($empty, Validator::isEmpty($value));
    }

    /**
     * Required系以外の全規則と、空でないが違反する値。
     *
     * @return iterable<string, array{RuleInterface, string}>
     */
    public static function rulesSkippingEmptyValues(): iterable
    {
        yield 'MaxLength' => [new MaxLength(1), '00'];
        yield 'MinLength' => [new MinLength(2), '0'];
        yield 'Email' => [new Email(), '0'];
        yield 'Url' => [new Url(), '0'];
        yield 'Regex' => [new Regex('/\A[a-z]+\z/'), '0'];
        yield 'Choice' => [new Choice(['a']), '0'];
        yield 'Callback' => [new Callback(static fn(mixed $value): bool => false), '0'];
        yield 'Retype' => [new Retype('other'), '0'];
        yield 'Range' => [new Range(1, 2), '0'];
        yield 'FileExtension' => [new FileExtension(['pdf']), '0'];
        yield 'MaxFileSize' => [new MaxFileSize(1), '0'];
    }

    /**
     * Required系以外の全規則と、空値(''・null・[])の組。
     *
     * @return iterable<string, array{RuleInterface, mixed}>
     */
    public static function rulesAndEmptyValues(): iterable
    {
        $empties = ['empty string' => '', 'null' => null, 'empty array' => []];

        foreach (self::rulesSkippingEmptyValues() as $name => [$rule]) {
            foreach ($empties as $emptyName => $empty) {
                yield "{$name}: {$emptyName}" => [$rule, $empty];
            }
        }
    }

    /**
     * 空値スルー(旧RuleTraitのthrough_if_empty)。Required系以外の規則は空値を検証せずに通す。
     */
    #[DataProvider('rulesAndEmptyValues')]
    public function testRulesOtherThanTheRequiredOnesSkipEmptyValues(RuleInterface $rule, mixed $empty): void
    {
        $definition = FormDefinition::create('form')->text('value', 'Value', [$rule])->text('other', 'Other');

        self::assertTrue($rule->skipIfEmpty());
        self::assertSame([], (new Validator())->validate($definition, ['value' => $empty, 'other' => 'x']));
    }

    /**
     * 空値スルーの対照。'0'のような空でない値は、同じ規則で検証して違反になる。
     */
    #[DataProvider('rulesSkippingEmptyValues')]
    public function testRulesOtherThanTheRequiredOnesCheckNonEmptyValues(RuleInterface $rule, string $violation): void
    {
        Functions\when('size_format')->justReturn('1 B');
        $definition = FormDefinition::create('form')->text('value', 'Value', [$rule])->text('other', 'Other');

        self::assertArrayHasKey('value', (new Validator())->validate($definition, ['value' => $violation, 'other' => 'x']));
    }

    /**
     * Required・RequiredIfだけが空値を検証する。空値と一緒に並べた形式の規則は実行されず、Required系のエラーだけが
     * 出る。'0'は空ではないため、形式の規則が実行される。
     *
     * @return iterable<string, array{list<RuleInterface>, string, string, list<string>}>
     */
    public static function emptyValuesWithFormatRules(): iterable
    {
        yield 'Required before the format rules' => [[new Required(), new Email(), new MaxLength(3)], '', '', ['Value is required.']];
        yield 'format rules only' => [[new Email(), new MaxLength(3)], '', '', []];
        yield 'RequiredIf before a format rule' => [[RequiredIf::filled('trigger'), new Email()], 'x', '', ['Value is required.']];
        yield 'RequiredIf whose condition does not hold' => [[RequiredIf::filled('trigger'), new Email()], '', '', []];
        yield 'string zero is not empty' => [[new Required(), new Email(), new MaxLength(3)], '', '0', ['Please enter a valid email address for Value.']];
    }

    /**
     * @param list<RuleInterface> $rules
     * @param list<string> $errors
     */
    #[DataProvider('emptyValuesWithFormatRules')]
    public function testOnlyTheRequiredRulesCheckEmptyValues(array $rules, string $trigger, string $value, array $errors): void
    {
        $definition = FormDefinition::create('form')->text('trigger', 'Trigger')->text('value', 'Value', $rules);

        self::assertSame($errors === [] ? [] : ['value' => $errors], (new Validator())->validate($definition, ['trigger' => $trigger, 'value' => $value]));
    }

    /**
     * 規則は定義の順に実行し、項目ごとに最初の違反で止める(エラーは1件だけ)。
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function valuesForTwoRules(): iterable
    {
        yield 'violates both rules' => ['a', ['Code must be at least 2 characters.']];
        yield 'violates the second rule only' => ['ab', ['Code has an invalid format.']];
        yield 'satisfies both rules' => ['AB', []];
    }

    /** @param list<string> $errors */
    #[DataProvider('valuesForTwoRules')]
    public function testRulesStopAtTheFirstViolation(string $value, array $errors): void
    {
        $definition = FormDefinition::create('contact')->text('code', 'Code', [new MinLength(2), new Regex('/\A[A-Z]+\z/u')]);

        self::assertSame($errors === [] ? [] : ['code' => $errors], (new Validator())->validate($definition, ['code' => $value]));
    }

    /**
     * 旧 ExactLengthRuleTest の境界値(ASCII とマルチバイトの10文字)。ExactLength は同梱しない(docs/05 §同梱ルール一覧)
     * ため、同じ長さのMinLengthとMaxLengthの組で確かめる(docs/10 §8.1の12。それぞれの規則の境界はMinLengthTest・
     * MaxLengthTest)。
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function exactLengthBoundaries(): iterable
    {
        yield '9 characters' => ['abcdefghi', false];
        yield '10 characters' => ['abcdefghij', true];
        yield '11 characters' => ['abcdefghijk', false];
        yield '9 multibyte characters' => ['あいうえおかきくけ', false];
        yield '10 multibyte characters' => ['あいうえおかきくけこ', true];
        yield '11 multibyte characters' => ['あいうえおかきくけこさ', false];
    }

    #[DataProvider('exactLengthBoundaries')]
    public function testMinLengthAndMaxLengthOfTheSameLengthAcceptOnlyThatLength(string $value, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('exact', 'Exact', [new MinLength(10), new MaxLength(10)]);

        self::assertSame($valid, !\array_key_exists('exact', (new Validator())->validate($definition, ['exact' => $value])));
    }

    /**
     * choices を持つ項目(select・radios・checkboxes)は、Choice を書かなくても選択肢の外の値と、単一の値の項目への
     * 配列を、例外ではなく項目のエラーにする(選択肢整合検証)。
     *
     * @return iterable<string, array{'select'|'radios'|'checkboxes', mixed, bool}>
     */
    public static function valuesOfChoiceFields(): iterable
    {
        yield 'select: a choice' => ['select', 'basic', true];
        yield 'select: outside the choices' => ['select', 'premium', false];
        yield 'select: an array' => ['select', ['basic'], false];
        yield 'radios: a choice' => ['radios', 'red', true];
        yield 'radios: outside the choices' => ['radios', 'blue', false];
        yield 'radios: an array' => ['radios', ['red'], false];
        yield 'checkboxes: a choice' => ['checkboxes', ['news'], true];
        yield 'checkboxes: one element outside the choices' => ['checkboxes', ['news', 'sports'], false];
        yield 'checkboxes: a nested array' => ['checkboxes', [['news']], false];
    }

    /** @param 'select'|'radios'|'checkboxes' $type */
    #[DataProvider('valuesOfChoiceFields')]
    public function testChoiceFieldsRejectValuesOutsideTheirChoicesAndArraysAsFieldErrors(string $type, mixed $value, bool $valid): void
    {
        $definition = match ($type) {
            'select' => FormDefinition::create('form')->select('field', 'Field', ['basic' => 'Basic']),
            'radios' => FormDefinition::create('form')->radios('field', 'Field', ['red' => 'Red']),
            'checkboxes' => FormDefinition::create('form')->checkboxes('field', 'Field', ['news' => 'News']),
        };

        self::assertSame($valid ? [] : ['field' => ['Field has an invalid choice.']], (new Validator())->validate($definition, ['field' => $value]));
    }

    /** 選択肢整合検証は、項目の規則(Required)より先に行う */
    public function testChoiceCheckOfACheckboxesFieldPrecedesItsRules(): void
    {
        $definition = FormDefinition::create('account')->checkboxes('topics', 'Topics', ['news' => 'News', 'docs' => 'Docs'], [new Required()]);

        self::assertSame(['topics' => ['Topics has an invalid choice.']], (new Validator())->validate($definition, ['topics' => ['news', 'bad']]));
    }
}
