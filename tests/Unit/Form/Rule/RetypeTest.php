<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Exception\Form\UnknownFieldException;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Retype;
use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;
use TMSWP\Testing\UnitTestCase;

/**
 * 同梱の規則Retypeの仕様(docs/05 §同梱ルール一覧)。旧iwf2bのRetypeRuleTestの移植先(docs/10 §8.1の15)。
 */
final class RetypeTest extends UnitTestCase
{
    /**
     * 旧 RetypeRuleTest。もう一方の項目の値と、文字列として厳密に比べる(旧 strict の指定は無い)。
     *
     * @return iterable<string, array{mixed, mixed, bool}>
     */
    public static function retypeValues(): iterable
    {
        yield 'same value' => ['value', 'value', true];
        yield 'different value' => ['value', 'other value', false];
        yield 'int 10 and string 10' => ['10', 10, true]; // 旧 strict: 不合格
        yield '10 and 010' => ['10', '010', false];
        yield 'trailing space' => ['value ', 'value', false];
        yield 'case' => ['Value', 'value', false];
    }

    #[DataProvider('retypeValues')]
    public function testRetypeComparesWithTheOtherFieldAsStrings(mixed $value, mixed $other, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('email', 'Email')->text('email_confirmation', 'Email confirmation', [new Retype('email')]);

        self::assertSame(
            $valid ? [] : ['email_confirmation' => ['Email confirmation must match Email.']],
            (new Validator())->validate($definition, ['email' => $other, 'email_confirmation' => $value]),
        );
    }

    /**
     * パスワードの項目どうしでも比べ、{otherLabel}は参照する項目のラベル。
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function passwordConfirmations(): iterable
    {
        yield 'same password' => ['secret', true];
        yield 'different password' => ['different', false];
    }

    #[DataProvider('passwordConfirmations')]
    public function testRetypeComparesPasswordFields(string $confirmation, bool $valid): void
    {
        $definition = FormDefinition::create('account')
            ->password('password', 'Password')
            ->password('password_confirmation', 'Password confirmation', [new Retype('password')]);

        self::assertSame(
            $valid ? [] : ['password_confirmation' => ['Password confirmation must match Password.']],
            (new Validator())->validate($definition, ['password' => 'secret', 'password_confirmation' => $confirmation]),
        );
    }

    /** 旧 FW の「登録されていない項目の名前」の例外は、ValidationContext の UnknownFieldException になる */
    public function testReferringToAnUnknownFieldThrows(): void
    {
        $definition = FormDefinition::create('form')->text('value', 'Value');

        $this->expectException(UnknownFieldException::class);
        $this->expectExceptionMessage('unknown form field: unregistered_field');

        (new Retype('unregistered_field'))->validate('value', new ValidationContext($definition, ['value' => 'value'], 'value'));
    }
}
