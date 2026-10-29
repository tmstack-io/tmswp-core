<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Email;
use TMSWP\Core\Form\Validator;
use TMSWP\Testing\UnitTestCase;

/**
 * 同梱の規則Emailの仕様(docs/05 §同梱ルール一覧)。旧iwf2bのEmailRuleTestの検証の値を移植する(docs/10 §8.1の11)。
 * 旧 FW と結果が変わる値には、旧 FW の結果をコメントで残す。
 */
final class EmailTest extends UnitTestCase
{
    /**
     * 空値は検証せず、形式の違う値は違反。
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function emptyAndPlainValues(): iterable
    {
        yield 'empty value' => ['', true];
        yield 'plain word' => ['invalid', false];
    }

    #[DataProvider('emptyAndPlainValues')]
    public function testEmailSkipsEmptyValuesAndRejectsPlainWords(string $address, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('email', 'Email', [new Email()]);

        self::assertSame($valid ? [] : ['email' => ['Please enter a valid email address for Email.']], (new Validator())->validate($definition, ['email' => $address]));
    }

    /** @return iterable<array{string, bool}> 旧 EmailRuleTest の valid_emails と invalid_emails の全件 */
    public static function emailAddresses(): iterable
    {
        return [
            ['email@example.com', true],
            ['firstname.lastname@example.com', true],
            ['email@subdomain.example.com', true],
            ['firstname+lastname@example.com', true],
            ['"email"@example.com', true],
            ['1234567890@example.com', true],
            ['email@example-one.com', true],
            ['_______@example.com', true],
            ['email@example.name', true],
            ['email@example.museum', true],
            ['email@example.co.jp', true],
            ['firstname-lastname@example.com', true],
            ['much."more\\ unusual"@example.com', true],
            ['very.unusual."@".unusual.com@example.com', true],
            ['very."(),:;<>[]".VERY."very@\\\\\\ \\"very".unusual@strange.example.com', true],
            ['plainaddress', false],
            ['#@%^%#$@#$@#.com', false],
            ['@example.com', false],
            ['Joe Smith <email@example.com>', false],
            ['email.example.com', false],
            ['email@example@example.com', false],
            ['.email@example.com', false],
            ['email.@example.com', false],
            ['email..email@example.com', false],
            ['email@example.com (Joe Smith)', false],
            ['email@example', false],
            ['email@-example.com', false],
            ['email@123.123.123.123', false],
            ['email@[123.123.123.123]', true], // 旧: 不合格
            ['email@111.222.333.44444', false],
            ['email@example..com', false],
            ['Abc..123@example.com', false],
            ['"(),:;<>[\\]@example.com', false],
            ['just"not"right@example.com', false],
            ['this\\ is\\"really\\"not\\\\allowed@example.com', false],
        ];
    }

    #[DataProvider('emailAddresses')]
    public function testEmailFollowsFilterValidateEmail(string $address, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('email', 'Email', [new Email()]);

        self::assertSame($valid ? [] : ['email' => ['Please enter a valid email address for Email.']], (new Validator())->validate($definition, ['email' => $address]));
    }

    /**
     * Email は Url と違い、国際化ドメイン名を punycode に変換しない(docs/05 §同梱ルール一覧)。そのため、国際化ドメイン名と
     * ローカル部の非 ASCII は不合格になり、punycode の形のドメインは合格する。
     *
     * @return iterable<array{string, bool}>
     */
    public static function internationalizedEmailAddresses(): iterable
    {
        return [
            ['user@例え.jp', false],
            ['ユーザー@example.jp', false],
            ['user@xn--r8jz45g.jp', true],
        ];
    }

    #[DataProvider('internationalizedEmailAddresses')]
    public function testEmailDoesNotConvertInternationalizedDomainNames(string $address, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('email', 'Email', [new Email()]);

        self::assertSame($valid ? [] : ['email' => ['Please enter a valid email address for Email.']], (new Validator())->validate($definition, ['email' => $address]));
    }
}
