<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Closure;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Form\Composite\CompositeField;
use TMSWP\Core\Form\Field;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormRequest;
use TMSWP\Core\Form\FormStep;
use TMSWP\Core\Form\Rule\Callback;
use TMSWP\Core\Form\Rule\Choice;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\Form\Rule\Regex;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\Form\Rule\Retype;
use TMSWP\Core\Form\Rule\RuleInterface;
use TMSWP\Core\Form\SubmitToken;
use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;
use TMSWP\Testing\UnitTestCase;
use TMSWP\Testing\WpdbTestDouble;
use WP;

/**
 * フォームの項目の種類・メッセージのフィルタ・複合フィールドと組み合わせた検証(docs/05 §Validator・§Messages・
 * §CompositeField)。同梱の規則それぞれの境界はRule/の各規則のテストで確かめる。
 */
final class FormValidationTest extends UnitTestCase
{
    public function testRuleMessagesPassThroughFilterBeforePlaceholderReplacement(): void
    {
        Functions\expect('apply_filters')
            ->once()
            ->with('tmswp/form/rule_message', '{label} must be {length} characters or fewer.', Mockery::type(MaxLength::class), Mockery::any())
            ->andReturn('{label}: max {length} chars');

        $errors = (new Validator())->validate(
            FormDefinition::create('contact')->text('name', 'Name', [new MaxLength(3)]),
            ['name' => 'abcd'],
        );

        self::assertSame('Name: max 3 chars', $errors['name'][0]);
    }

    /**
     * 単一のチェックボックスは、チェックしたときの値だけを受け付ける(Requiredの有無によらない)。
     *
     * @return iterable<string, array{string, string, array<string, list<string>>}>
     */
    public static function valuesOfSingleCheckboxes(): iterable
    {
        yield 'checked values' => ['agree', '1', []];
        yield 'unchecked optional checkbox' => ['agree', '', []];
        yield 'values other than the checked values' => [
            'any string',
            'agree',
            ['privacy' => ['Privacy policy has an invalid choice.'], 'newsletter' => ['Newsletter has an invalid choice.']],
        ];
        yield 'unchecked required checkbox' => ['', '', ['privacy' => ['Privacy policy is required.']]];
    }

    /** @param array<string, list<string>> $errors */
    #[DataProvider('valuesOfSingleCheckboxes')]
    public function testSingleCheckboxAcceptsOnlyItsCheckedValue(string $privacy, string $newsletter, array $errors): void
    {
        $definition = FormDefinition::create('signup')
            ->checkbox('privacy', 'Privacy policy', 'agree', [new Required()])
            ->checkbox('newsletter', 'Newsletter');

        self::assertSame($errors, (new Validator())->validate($definition, ['privacy' => $privacy, 'newsletter' => $newsletter]));
    }

    /**
     * 複数選択の項目への配列でない値は、1要素の配列(空値なら空の配列)として検証する。
     *
     * @return iterable<string, array{array<string, ?string>, array<string, list<string>>}>
     */
    public static function scalarValuesOfMultipleFields(): iterable
    {
        yield 'violations' => [['length' => 'long', 'format' => 'a1', 'required' => ''], ['length' => ['long'], 'format' => ['a1'], 'required' => []]];
        yield 'choices' => [['length' => 'ok', 'format' => 'abc', 'required' => 'a'], ['length' => ['ok'], 'format' => ['abc'], 'required' => ['a']]];
        yield 'outside the choices and nulls' => [['length' => 'other', 'format' => null, 'required' => null], ['length' => ['other'], 'format' => [], 'required' => []]];
    }

    /**
     * @param array<string, ?string> $scalars
     * @param array<string, list<string>> $arrays
     */
    #[DataProvider('scalarValuesOfMultipleFields')]
    public function testScalarValueOfAMultipleFieldIsValidatedAsASingleElementArray(array $scalars, array $arrays): void
    {
        $definition = self::multipleFieldsDefinition();
        $validator = new Validator();

        self::assertSame($validator->validate($definition, $arrays), $validator->validate($definition, $scalars));
    }

    /** 1要素の配列として検証した結果の、各項目の最初の違反 */
    public function testScalarValuesOfMultipleFieldsReportTheViolationsOfTheirElements(): void
    {
        self::assertSame(
            [
                'length' => ['Length must be 3 characters or fewer.'],
                'format' => ['Format has an invalid format.'],
                'required' => ['Required is required.'],
            ],
            (new Validator())->validate(self::multipleFieldsDefinition(), ['length' => 'long', 'format' => 'a1', 'required' => '']),
        );
    }

    /**
     * 規則のメッセージは、規則ごとに1回tmswp/form/rule_messageのフィルタを通ってから{label}を置き換える(Callbackの
     * 既定と戻り値のメッセージ、Retype、暗黙のChoice、個別のメッセージを指定したRequired)。
     *
     * @return iterable<string, array{Closure(FormDefinition): FormDefinition, array<string, string>, string, class-string<RuleInterface>, string}>
     */
    public static function rulesWithFilteredMessages(): iterable
    {
        yield 'Callback returning false' => [
            static fn(FormDefinition $definition): FormDefinition => $definition->text('nickname', 'Nickname', [new Callback(static fn(mixed $value): bool => false)]),
            ['nickname' => 'taro'],
            'nickname',
            Callback::class,
            '[Nickname] Nickname is invalid.',
        ];
        yield 'Callback returning a message' => [
            static fn(FormDefinition $definition): FormDefinition => $definition->text('code', 'Code', [new Callback(static fn(mixed $value): string => '{label} is taken.')]),
            ['code' => 'abc'],
            'code',
            Callback::class,
            '[Code] Code is taken.',
        ];
        yield 'Retype' => [
            static fn(FormDefinition $definition): FormDefinition => $definition->text('email', 'Email')->text('email_confirmation', 'Email confirmation', [new Retype('email')]),
            ['email' => 'a@example.com', 'email_confirmation' => 'b@example.com'],
            'email_confirmation',
            Retype::class,
            '[Email confirmation] Email confirmation must match Email.',
        ];
        yield 'implicit Choice of a select' => [
            static fn(FormDefinition $definition): FormDefinition => $definition->select('plan', 'Plan', ['free' => 'Free']),
            ['plan' => 'paid'],
            'plan',
            Choice::class,
            '[Plan] Plan has an invalid choice.',
        ];
        yield 'implicit Choice of a checkbox' => [
            static fn(FormDefinition $definition): FormDefinition => $definition->checkbox('privacy', 'Privacy policy'),
            ['privacy' => 'yes'],
            'privacy',
            Choice::class,
            '[Privacy policy] Privacy policy has an invalid choice.',
        ];
        yield 'Required with a custom message' => [
            static fn(FormDefinition $definition): FormDefinition => $definition->text('name', 'Name', [new Required(message: '{label} please.')]),
            ['name' => ''],
            'name',
            Required::class,
            '[Name] Name please.',
        ];
    }

    /**
     * @param Closure(FormDefinition): FormDefinition $define
     * @param array<string, string> $values
     * @param class-string<RuleInterface> $ruleClass
     */
    #[DataProvider('rulesWithFilteredMessages')]
    public function testEveryRuleMessageIsResolvedThroughTheFilterBeforePlaceholderReplacement(
        Closure $define,
        array $values,
        string $field,
        string $ruleClass,
        string $error,
    ): void {
        $filtered = [];
        Filters\expectApplied('tmswp/form/rule_message')
            ->once()
            ->andReturnUsing(static function (string $message, RuleInterface $rule, ValidationContext $context) use (&$filtered): string {
                $filtered[] = [$context->currentField, $rule::class];

                return "[{label}] {$message}";
            });

        $errors = (new Validator())->validate($define(FormDefinition::create('account')), $values);

        self::assertSame([$field => [$error]], $errors);
        self::assertSame([[$field, $ruleClass]], $filtered);
    }

    public function testRuleMessageFilterReturningANonStringThrows(): void
    {
        Filters\expectApplied('tmswp/form/rule_message')->once()->andReturn(null);

        $this->expectException(FormConfigException::class);
        $this->expectExceptionMessage('the filter tmswp/form/rule_message must return a string, got null');

        (new Validator())->validate(FormDefinition::create('contact')->text('name', 'Name', [new Required()]), ['name' => '']);
    }

    public function testCompositeDateValidationAndCanonicalFormValue(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('wp_salt')->justReturn('secret');

        $definition = FormDefinition::create('profile')
            ->add(new ValidationTestDateField('birth', 'Birth date', [new Required()], minYear: 2000, maxYear: 2026));
        $validator = new Validator();

        self::assertSame(
            'Please complete all parts of Birth date.',
            $validator->validate($definition, ['birth' => ['year' => '2026', 'month' => '', 'day' => '1']])['birth'][0],
        );
        self::assertSame(
            'Birth date is not a valid date.',
            $validator->validate($definition, ['birth' => ['year' => '2026', 'month' => '2', 'day' => '31']])['birth'][0],
        );

        Functions\when('nocache_headers')->justReturn();
        // フロントの要求。Formはhandle()で、POSTで送る名前を公開のクエリ変数と照らす(照合はFormRuntimeTest)
        Functions\when('is_admin')->justReturn(false);
        $wp = Mockery::mock(WP::class);
        self::assertInstanceOf(WP::class, $wp);
        $wp->public_query_vars = [];
        $GLOBALS['wp'] = $wp;
        $GLOBALS['wpdb'] = WpdbTestDouble::create();

        try {
            $form = new Form($definition);
            $result = $form->handle(new FormRequest('POST', [
                Form::FIELD_FORM_ID => 'profile',
                Form::FIELD_NONCE => 'nonce',
                Form::FIELD_STEP => 'submit',
                Form::FIELD_SUBMIT_TOKEN => SubmitToken::issue('profile'),
                'birth' => ['year' => '2026', 'month' => '2', 'day' => '3'],
            ]));
        } finally {
            unset($GLOBALS['wpdb'], $GLOBALS['wp']);
        }

        self::assertSame(FormStep::Complete, $result->step);
        self::assertSame('2026-02-03', $result->values['birth']);
    }

    /**
     * app側の複合フィールド(生年月日)。正準値はcompose(decompose($v)) === $vで往復する(うるう年の2/29を含む)。
     *
     * @return iterable<string, array{string}>
     */
    public static function canonicalDates(): iterable
    {
        yield 'ordinary date' => ['2026-02-03'];
        yield 'last day of the max year range' => ['2000-12-31'];
        yield 'leap day' => ['2024-02-29'];
    }

    #[DataProvider('canonicalDates')]
    public function testCompositeDateRoundTripsItsCanonicalValue(string $value): void
    {
        $field = new ValidationTestDateField('birth', 'Birth date', [new Required()], minYear: 2000, maxYear: 2026);
        $definition = FormDefinition::create('profile')->add($field);

        self::assertSame($value, $field->compose($field->decompose($value)));
        self::assertSame([], (new Validator())->validate($definition, ['birth' => $field->decompose($value)]));
    }

    /**
     * 部分入力と存在しない日付(2/30・平年の2/29)は項目のエラーになる。
     *
     * @return iterable<string, array{array{year: string, month: string, day: string}, string}>
     */
    public static function invalidCompositeDates(): iterable
    {
        yield 'February 30' => [['year' => '2026', 'month' => '2', 'day' => '30'], 'Birth date is not a valid date.'];
        yield 'February 29 of a common year' => [['year' => '2025', 'month' => '2', 'day' => '29'], 'Birth date is not a valid date.'];
        yield 'partial input' => [['year' => '2026', 'month' => '', 'day' => ''], 'Please complete all parts of Birth date.'];
    }

    /** @param array{year: string, month: string, day: string} $parts */
    #[DataProvider('invalidCompositeDates')]
    public function testCompositeDateRejectsPartialAndNonexistentDates(array $parts, string $error): void
    {
        $definition = FormDefinition::create('profile')
            ->add(new ValidationTestDateField('birth', 'Birth date', [new Required()], minYear: 2000, maxYear: 2026));

        self::assertSame(['birth' => [$error]], (new Validator())->validate($definition, ['birth' => $parts]));
    }

    /**
     * 複合フィールドの部分は、それぞれの選択肢(年は2000〜2026、月は1〜12、日は1〜31)で確かめる。
     *
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function compositeDateParts(): iterable
    {
        yield 'year below the range' => ['1999', '1', '1', false];
        yield 'year above the range' => ['2027', '1', '1', false];
        yield 'month 13' => ['2020', '13', '1', false];
        yield 'day 0' => ['2020', '1', '0', false];
        yield 'every part at the boundary' => ['2000', '12', '31', true];
    }

    #[DataProvider('compositeDateParts')]
    public function testCompositePartsAreCheckedAgainstTheirChoices(string $year, string $month, string $day, bool $valid): void
    {
        $definition = FormDefinition::create('profile')
            ->add(new ValidationTestDateField('birth', 'Birth date', [new Required()], minYear: 2000, maxYear: 2026));

        self::assertSame(
            $valid ? [] : ['birth' => ['Birth date has an invalid choice.']],
            (new Validator())->validate($definition, ['birth' => ['year' => $year, 'month' => $month, 'day' => $day]]),
        );
    }

    /**
     * 部分の規則は、選択肢の検査の後に実行する。
     *
     * @return iterable<string, array{array{area: string, number: string}, list<string>}>
     */
    public static function compositeCodeParts(): iterable
    {
        yield 'outside the choices' => [['area' => 'x', 'number' => '12'], ['Code has an invalid choice.']];
        yield 'violating the rule of a part' => [['area' => 'a', 'number' => '1x'], ['Code has an invalid format.']];
        yield 'valid parts' => [['area' => 'a', 'number' => '12'], []];
    }

    /**
     * @param array{area: string, number: string} $parts
     * @param list<string> $errors
     */
    #[DataProvider('compositeCodeParts')]
    public function testCompositePartsRunTheirOwnRulesAfterTheChoiceCheck(array $parts, array $errors): void
    {
        $definition = FormDefinition::create('profile')->add(new ValidationTestCodeField('code', 'Code'));

        self::assertSame($errors === [] ? [] : ['code' => $errors], (new Validator())->validate($definition, ['code' => $parts]));
    }

    /**
     * 複合フィールドの名前は、定義の時点で確かめる(大文字・部分の名前と重なる接尾辞・予約の名前)。
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidCompositeFieldNames(): iterable
    {
        yield 'upper case' => ['Birth'];
        yield 'token suffix' => ['birth__token'];
        yield 'reserved name' => ['_tmswp_form'];
    }

    #[DataProvider('invalidCompositeFieldNames')]
    public function testCompositeFieldNameIsCheckedAtDefinition(string $name): void
    {
        $this->expectException(FormConfigException::class);
        $this->expectExceptionMessage($name);

        new ValidationTestCodeField($name, 'Code');
    }

    public function testCompositeFieldRulesAreCheckedAtDefinition(): void
    {
        $this->expectException(FormConfigException::class);
        $this->expectExceptionMessage('field rules must implement RuleInterface');

        $define = require __DIR__ . '/../../fixtures/form-validation/composite-with-invalid-rules.php';
        self::assertInstanceOf(Closure::class, $define);
        $define();
    }

    public function testTrimFilterThrowsForInvalidUtf8InsteadOfReturningTheValueUntrimmed(): void
    {
        $trim = Field::text('name', 'Name')->filters[0];

        self::assertSame('Taro', $trim("\u{3000} Taro \u{3000}"));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the trim filter of a form field failed: Malformed UTF-8 characters, possibly incorrectly encoded');

        $trim(" a\xff ");
    }

    /** 複数選択の項目に、長さ・形式・必須の規則を1つずつ付けた定義 */
    private static function multipleFieldsDefinition(): FormDefinition
    {
        return FormDefinition::create('inquiry')
            ->checkboxes('length', 'Length', ['long' => 'Long', 'ok' => 'OK'], [new Required(), new MaxLength(3)])
            ->checkboxes('format', 'Format', ['abc' => 'ABC', 'a1' => 'A1'], [new Regex('/\A[a-z]+\z/')])
            ->checkboxes('required', 'Required', ['a' => 'A'], [new Required()]);
    }
}

final class ValidationTestDateField extends CompositeField
{
    /** @param list<RuleInterface> $rules */
    public function __construct(
        string $name,
        string $label,
        array $rules = [],
        private readonly int $minYear = 1940,
        private readonly ?int $maxYear = null,
    ) {
        parent::__construct($name, $label, $rules);
    }

    public function parts(): array
    {
        return [
            'year' => Field::select($this->name . '_year', 'Year', $this->yearChoices()),
            'month' => Field::select($this->name . '_month', 'Month', $this->rangeChoices(1, 12)),
            'day' => Field::select($this->name . '_day', 'Day', $this->rangeChoices(1, 31)),
        ];
    }

    public function compose(array $partValues): string
    {
        return \sprintf('%04d-%02d-%02d', (int) $partValues['year'], (int) $partValues['month'], (int) $partValues['day']);
    }

    public function decompose(string $value): array
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $matches) !== 1) {
            return ['year' => '', 'month' => '', 'day' => ''];
        }

        return ['year' => (string) (int) $matches[1], 'month' => (string) (int) $matches[2], 'day' => (string) (int) $matches[3]];
    }

    public function validateParts(array $partValues): bool
    {
        return checkdate((int) $partValues['month'], (int) $partValues['day'], (int) $partValues['year']);
    }

    public function invalidPartsMessage(): string
    {
        return __('{label} is not a valid date.', 'tmswp-tests');
    }

    /** @return array<array-key, string> */
    private function yearChoices(): array
    {
        $choices = ['' => '----'];
        $maxYear = $this->maxYear ?? (int) date('Y');

        for ($year = $maxYear; $year >= $this->minYear; --$year) {
            $choices[(string) $year] = (string) $year;
        }

        return $choices;
    }

    /** @return array<array-key, string> */
    private function rangeChoices(int $min, int $max): array
    {
        $choices = ['' => '--'];

        for ($value = $min; $value <= $max; ++$value) {
            $choices[(string) $value] = (string) $value;
        }

        return $choices;
    }
}

final class ValidationTestCodeField extends CompositeField
{
    public function parts(): array
    {
        return [
            'area' => Field::select($this->name . '_area', 'Area', ['a' => 'A', 'b' => 'B']),
            'number' => Field::text($this->name . '_number', 'Number', [new Regex('/\\A\\d+\\z/')]),
        ];
    }

    public function compose(array $partValues): string
    {
        return $partValues['area'] . '-' . $partValues['number'];
    }

    public function decompose(string $value): array
    {
        $parts = explode('-', $value, 2);

        return ['area' => $parts[0], 'number' => $parts[1] ?? ''];
    }
}
