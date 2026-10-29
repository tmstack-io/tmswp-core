<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\Core\Form\Validator;
use TMSWP\Testing\UnitTestCase;

/**
 * 同梱の規則Requiredの仕様(docs/05 §同梱ルール一覧・§空値スルーセマンティクス)。
 */
final class RequiredTest extends UnitTestCase
{
    /**
     * 空値(null・''・[])だけが違反。'0'・0・false・空白は空ではない。
     *
     * @return iterable<string, array{mixed, bool}>
     */
    public static function values(): iterable
    {
        yield 'null' => [null, false];
        yield 'empty string' => ['', false];
        yield 'empty array' => [[], false];
        yield 'string zero' => ['0', true];
        yield 'int zero' => [0, true];
        yield 'false' => [false, true];
        yield 'space only' => [' ', true];
        yield 'text' => ['a', true];
    }

    #[DataProvider('values')]
    public function testRequiredRejectsOnlyEmptyValues(mixed $value, bool $valid): void
    {
        $definition = FormDefinition::create('contact')->text('name', 'Name', [new Required()]);

        self::assertSame($valid ? [] : ['name' => ['Name is required.']], (new Validator())->validate($definition, ['name' => $value]));
    }

    /** Requiredは空値を検証する(空値スルーをしない)。配列全体を受け取る */
    public function testRequiredDoesNotSkipEmptyValues(): void
    {
        self::assertFalse((new Required())->skipIfEmpty());
        self::assertFalse((new Required())->eachElement());
    }
}
