<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Exception\Form\UnknownFieldException;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\RequiredIf;
use TMSWP\Core\Form\Rule\RuleInterface;
use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;
use TMSWP\Testing\UnitTestCase;

/**
 * 同梱の規則RequiredIfの仕様(docs/05 §同梱ルール一覧)。旧iwf2bのRequiredIfRuleTestの移植先(docs/10 §8.1の13)。
 */
final class RequiredIfTest extends UnitTestCase
{
    /**
     * 旧 RequiredIfRuleTest の真理値表(参照する項目の空・空でない × 自身の値の空・空でない、期待値の一致・不一致)。
     * 旧 RequiredUnlessRuleTest の裏条件は、RequiredUnless を同梱しない(docs/05 §同梱ルール一覧)ため移植しない。
     * 参照する項目は ValidationContext 経由で引く。
     *
     * @return iterable<string, array{RuleInterface, string, string, bool}>
     */
    public static function requiredIfTruthTable(): iterable
    {
        yield 'filled: empty other, empty value' => [RequiredIf::filled('other'), '', '', true];
        yield 'filled: empty other, value' => [RequiredIf::filled('other'), '', 'value', true];
        yield 'filled: other, empty value' => [RequiredIf::filled('other'), 'field value', '', false];
        yield 'filled: other, value' => [RequiredIf::filled('other'), 'field value', 'value', true];
        yield 'filled: other of 0, empty value' => [RequiredIf::filled('other'), '0', '', false];
        yield 'equals: not matched, empty value' => [RequiredIf::equals('other', 'invalid value'), 'field value', '', true];
        yield 'equals: matched, empty value' => [RequiredIf::equals('other', 'field value'), 'field value', '', false];
        yield 'equals: matched, value' => [RequiredIf::equals('other', 'field value'), 'field value', 'value', true];
        // 旧 strict の指定は無く、常に文字列として厳密に比べる
        yield 'equals: 10 and 10' => [RequiredIf::equals('other', '10'), '10', '', false];
        yield 'equals: 10 and 010' => [RequiredIf::equals('other', '10'), '010', '', true];
        yield 'equals: 10 and 10.0' => [RequiredIf::equals('other', '10'), '10.0', '', true];
    }

    #[DataProvider('requiredIfTruthTable')]
    public function testRequiredIfRequiresTheValueOnlyWhenTheConditionHolds(RuleInterface $rule, string $other, string $value, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('other', 'Other')->text('value', 'Value', [$rule]);

        self::assertSame($valid ? [] : ['value' => ['Value is required.']], (new Validator())->validate($definition, ['other' => $other, 'value' => $value]));
    }

    /**
     * equals()は、選択肢の項目(select)の値とも比べる。
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function valuesOfASelectField(): iterable
    {
        yield 'matched, empty value' => ['company', '', false];
        yield 'matched, value' => ['company', 'Acme', true];
        yield 'not matched, empty value' => ['personal', '', true];
    }

    #[DataProvider('valuesOfASelectField')]
    public function testEqualsComparesWithTheValueOfASelectField(string $type, string $company, bool $valid): void
    {
        $definition = FormDefinition::create('account')
            ->select('type', 'Type', ['personal' => 'Personal', 'company' => 'Company'])
            ->text('company', 'Company name', [RequiredIf::equals('type', 'company')]);

        self::assertSame(
            $valid ? [] : ['company' => ['Company name is required.']],
            (new Validator())->validate($definition, ['type' => $type, 'company' => $company]),
        );
    }

    /**
     * equals()は、配列の値(checkboxes)の要素のどれかが一致するとき必須。
     *
     * @return iterable<string, array{list<string>, string, bool}>
     */
    public static function valuesOfACheckboxesField(): iterable
    {
        yield 'one of the elements matches' => [['product', 'other'], '', false];
        yield 'no element matches' => [['product'], '', true];
        yield 'no element' => [[], '', true];
        yield 'matched and filled' => [['other'], 'Details', true];
    }

    /** @param list<string> $topics */
    #[DataProvider('valuesOfACheckboxesField')]
    public function testEqualsMatchesAnElementOfACheckboxesValue(array $topics, string $detail, bool $valid): void
    {
        $definition = FormDefinition::create('inquiry')
            ->checkboxes('topics', 'Topics', ['product' => 'Product', 'other' => 'Other'])
            ->text('other_detail', 'Other detail', [RequiredIf::equals('topics', 'other')]);

        self::assertSame(
            $valid ? [] : ['other_detail' => ['Other detail is required.']],
            (new Validator())->validate($definition, ['topics' => $topics, 'other_detail' => $detail]),
        );
    }

    /** @return iterable<string, array{RuleInterface}> */
    public static function rulesReferringToAnUnknownField(): iterable
    {
        yield 'filled' => [RequiredIf::filled('unregistered_field')];
        yield 'equals' => [RequiredIf::equals('unregistered_field', 'x')];
    }

    /** 旧 FW の「登録されていない項目の名前」の例外は、ValidationContext の UnknownFieldException になる */
    #[DataProvider('rulesReferringToAnUnknownField')]
    public function testReferringToAnUnknownFieldThrows(RuleInterface $rule): void
    {
        $definition = FormDefinition::create('form')->text('value', 'Value');

        $this->expectException(UnknownFieldException::class);
        $this->expectExceptionMessage('unknown form field: unregistered_field');

        $rule->validate('value', new ValidationContext($definition, ['value' => 'value'], 'value'));
    }

    /** RequiredIfは空値を検証する(空値スルーをしない) */
    public function testRequiredIfDoesNotSkipEmptyValues(): void
    {
        self::assertFalse(RequiredIf::filled('trigger')->skipIfEmpty());
    }
}
