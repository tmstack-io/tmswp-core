<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Regex;
use TMSWP\Core\Form\Validator;
use TMSWP\Testing\UnitTestCase;

/**
 * 同梱の規則Regexの仕様(docs/05 §同梱ルール一覧)。
 */
final class RegexTest extends UnitTestCase
{
    /**
     * preg_matchが1なら合格。空値は検証しない。
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function values(): iterable
    {
        yield 'matches' => ['AB', true];
        yield 'one character' => ['A', true];
        yield 'lower case' => ['a', false];
        yield 'trailing digit' => ['AB1', false];
        yield 'trailing new line' => ["AB\n", false];
        yield 'empty value' => ['', true];
    }

    #[DataProvider('values')]
    public function testRegexAcceptsOnlyMatchingValues(string $value, bool $valid): void
    {
        $definition = FormDefinition::create('contact')->text('code', 'Code', [new Regex('/\A[A-Z]+\z/u')]);

        self::assertSame($valid ? [] : ['code' => ['Code has an invalid format.']], (new Validator())->validate($definition, ['code' => $value]));
    }

    /** パターンの誤りは入力ではなく定義の誤りのため、違反ではなくFormConfigException */
    public function testInvalidRegexPatternThrowsConfigException(): void
    {
        $this->expectException(FormConfigException::class);

        (new Validator())->validate(
            FormDefinition::create('search')->text('keyword', 'Keyword', [new Regex('/[/')]),
            ['keyword' => 'text'],
        );
    }
}
