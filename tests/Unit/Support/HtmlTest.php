<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Support;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Support\Html;
use TMSWP\Testing\UnitTestCase;

/**
 * Support\Htmlの属性の描画とエスケープ(docs/07 §4.3。docs/10 §8.2のSupport\Html)。
 */
final class HtmlTest extends UnitTestCase
{
    /**
     * attributes()の変換規則(§4.3)を1つずつ。最後の行は、規則を混ぜて入力順を保つことの確認。
     *
     * @return iterable<string, array{array<int|string, mixed>, string}>
     */
    public static function attributeRules(): iterable
    {
        yield 'no attributes' => [[], ''];
        yield 'class with conditions' => [['class' => ['btn', 'is-active' => true, 'is-hidden' => false]], ' class="btn is-active"'];
        yield 'empty class is omitted' => [['class' => ['is-hidden' => false]], ''];
        yield 'true is a bare attribute' => [['disabled' => true], ' disabled'];
        yield 'false is omitted' => [['hidden' => false], ''];
        yield 'aria false is rendered literally' => [['aria-pressed' => false], ' aria-pressed="false"'];
        yield 'aria true is rendered literally' => [['aria-expanded' => true], ' aria-expanded="true"'];
        yield 'empty string is kept' => [['value' => ''], ' value=""'];
        yield 'array is joined with spaces' => [['data-ids' => [1, 2]], ' data-ids="1 2"'];
        yield 'int key with a name' => [['checked'], ' checked'];
        yield 'int key without a string' => [[true], ''];
        yield 'value is escaped' => [['title' => '"quoted"'], ' title="&quot;quoted&quot;"'];
        yield 'null is omitted' => [['nullable' => null], ''];
        yield 'mixed rules in the input order' => [
            [
                'class' => ['btn', 'is-active' => true, 'is-hidden' => false],
                'disabled' => true,
                'hidden' => false,
                'aria-pressed' => false,
                'value' => '',
                'data-ids' => [1, 2],
                'checked',
                'title' => '"quoted"',
                'nullable' => null,
            ],
            ' class="btn is-active" disabled aria-pressed="false" value="" data-ids="1 2" checked title="&quot;quoted&quot;"',
        ];
    }

    /** @param array<int|string, mixed> $attributes */
    #[DataProvider('attributeRules')]
    public function testAttributesFollowTheRenderingRules(array $attributes, string $expected): void
    {
        Functions\when('esc_attr')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));

        self::assertSame($expected, Html::attributes($attributes));
    }

    /**
     * classes()は文字列・リスト・条件の配列を空白で連結し、空の要素を除いて初出を優先して重複を除く。
     *
     * @return iterable<string, array{list<string|array<int|string, string|bool>>, string}>
     */
    public static function classesCases(): iterable
    {
        yield 'string and conditions with a duplicate' => [['btn btn-lg', ['btn', 'is-active' => true]], 'btn btn-lg is-active'];
        yield 'false condition' => [['btn', ['is-active' => false]], 'btn'];
        yield 'surrounding and repeated spaces' => [['  btn   btn-lg  '], 'btn btn-lg'];
        yield 'nothing' => [[], ''];
    }

    /** @param list<string|array<int|string, string|bool>> $values */
    #[DataProvider('classesCases')]
    public function testClassesJoinsUniqueClassNames(array $values, string $expected): void
    {
        self::assertSame($expected, Html::classes(...$values));
    }

    public function testHtmlEscapesUrlAttributesWithEscUrl(): void
    {
        $this->stubUrlEscaping();

        self::assertSame(
            ' href="https://example.com/?a=1&amp;b=2" SRC="/img/a.png" action="" ping="https://a.example/p https://b.example/p" title="javascript:void(0)"',
            Html::attributes([
                'href' => 'https://example.com/?a=1&b=2',
                'SRC' => '/img/a.png',
                'action' => '',
                'ping' => ['https://a.example/p', 'https://b.example/p'],
                'title' => 'javascript:void(0)',
            ]),
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function disallowedUrlProvider(): iterable
    {
        yield 'href' => [['href' => 'javascript:alert(1)']];
        yield 'uppercase name' => [['FormAction' => 'javascript:alert(1)']];
        yield 'url in list' => [['ping' => 'https://a.example/p javascript:alert(1)']];
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('disallowedUrlProvider')]
    public function testHtmlRejectsUrlWithDisallowedProtocol(array $attributes): void
    {
        $this->stubUrlEscaping();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed protocol: javascript:alert(1)');

        Html::attributes($attributes);
    }

    /**
     * 属性名は/\A[a-zA-Z][a-zA-Z0-9:_.-]*\z/に一致しなければ例外(§4.3の2)。
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidAttributeNames(): iterable
    {
        yield 'space' => ['bad name'];
        yield 'leading digit' => ['1st'];
        yield 'leading hyphen' => ['-data'];
        yield 'quote' => ['a"b'];
    }

    #[DataProvider('invalidAttributeNames')]
    public function testHtmlRejectsInvalidAttributeName(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        Html::attributes([$name => 'x']);
    }

    /** @return iterable<string, array{string}> */
    public static function validAttributeNames(): iterable
    {
        yield 'single letter' => ['a'];
        yield 'namespaced' => ['xml:lang'];
        yield 'all allowed characters' => ['data-a_b.c:1'];
    }

    #[DataProvider('validAttributeNames')]
    public function testHtmlAcceptsValidAttributeName(string $name): void
    {
        Functions\when('esc_attr')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));

        self::assertSame(" {$name}=\"x\"", Html::attributes([$name => 'x']));
    }

    /** esc_url() の代わり。WordPress と同じく、許可されないプロトコルの URL を空文字にする */
    private function stubUrlEscaping(): void
    {
        Functions\when('esc_attr')->alias(static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_url')->alias(static fn(string $url): string => preg_match('/\A\s*javascript:/i', $url) === 1 ? '' : htmlspecialchars($url, ENT_QUOTES, 'UTF-8'));
    }
}
