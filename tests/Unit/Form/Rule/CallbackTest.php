<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form\Rule;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Callback;
use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;
use TMSWP\Testing\UnitTestCase;

/**
 * 同梱の規則Callbackの仕様(docs/05 §同梱ルール一覧)。
 */
final class CallbackTest extends UnitTestCase
{
    /**
     * trueは合格、falseは指定の(無ければ既定の)メッセージ、文字列はそのメッセージ。どのメッセージも{label}を置き換える。
     *
     * @return iterable<string, array{Closure(mixed, ValidationContext): (bool|string), ?string, string, list<string>}>
     */
    public static function results(): iterable
    {
        $ok = static fn(mixed $value): bool|string => $value === 'ok' ? true : 'Note is not valid.';

        yield 'true' => [$ok, null, 'ok', []];
        yield 'string' => [$ok, null, 'ng', ['Note is not valid.']];
        yield 'string with a placeholder' => [static fn(mixed $value): string => '{label} is taken.', null, 'abc', ['Note is taken.']];
        yield 'false with the default message' => [static fn(mixed $value): bool => false, null, 'abc', ['Note is invalid.']];
        yield 'false with a custom message' => [static fn(mixed $value): bool => false, '{label} is wrong.', 'abc', ['Note is wrong.']];
        yield 'callback reading another field' => [
            static fn(mixed $value, ValidationContext $context): bool => $value === $context->value('other'),
            null,
            'abc',
            ['Note is invalid.'],
        ];
    }

    /**
     * @param Closure(mixed, ValidationContext): (bool|string) $callback
     * @param list<string> $errors
     */
    #[DataProvider('results')]
    public function testCallbackResultDecidesTheError(Closure $callback, ?string $message, string $value, array $errors): void
    {
        $definition = FormDefinition::create('contact')->text('other', 'Other')->text('note', 'Note', [new Callback($callback, $message)]);

        self::assertSame($errors === [] ? [] : ['note' => $errors], (new Validator())->validate($definition, ['other' => 'xyz', 'note' => $value]));
    }

    public function testCallbackReturningNeitherBoolNorStringThrows(): void
    {
        $callback = require __DIR__ . '/../../../fixtures/form-validation/callback-returning-null.php';
        self::assertInstanceOf(Callback::class, $callback);
        $definition = FormDefinition::create('contact')->text('note', 'Note', [$callback]);

        $this->expectException(FormConfigException::class);
        $this->expectExceptionMessage('the Callback rule must return bool or string, got null');

        (new Validator())->validate($definition, ['note' => 'text']);
    }
}
