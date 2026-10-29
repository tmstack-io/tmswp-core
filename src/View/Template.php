<?php

declare(strict_types=1);

namespace TMSWP\Core\View;

use Closure;
use Throwable;
use TMSWP\Core\Exception\TemplateNotFoundException;

final class Template
{
    private static ?TemplateLocator $locator = null;

    private function __construct() {}

    /** @param array<string, mixed> $args */
    public static function part(string $slug, array $args = []): void
    {
        $locator = self::locator();
        // 不正な slug からフックの名前を作らないよう、フックの前に slug だけを検証する。
        $locator->assertSlug($slug);

        // get_template_part() と同じフックを同じ引数・順序で発火する。$name を持たないため、
        // 1つ目には null、2つ目には get_template_part() が文字列に変換した後の '' を渡す。
        do_action("get_template_part_{$slug}", $slug, null, $args);
        do_action('get_template_part', $slug, '', [$slug . '.php'], $args);

        // get_template_part() と同じく、フックの後に解決する(フックが用意したテンプレートを読み込める)。
        $file = $locator->locate($slug);

        if ($file === null) {
            throw new TemplateNotFoundException(
                "template '{$slug}' was not found. Candidates: " . implode(', ', $locator->candidatePaths($slug)),
            );
        }

        self::includeFile($file, $args);
    }

    /** @param array<string, mixed> $args */
    public static function render(string $slug, array $args = []): string
    {
        ob_start();

        try {
            self::part($slug, $args);

            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }
    }

    public static function exists(string $slug): bool
    {
        return self::locator()->exists($slug);
    }

    public static function locate(string $slug): ?string
    {
        return self::locator()->locate($slug);
    }

    public static function locator(): TemplateLocator
    {
        if (!self::$locator instanceof TemplateLocator) {
            self::$locator = new TemplateLocator();
        }

        return self::$locator;
    }

    public static function setLocator(?TemplateLocator $locator): void
    {
        self::$locator = $locator;
    }

    /**
     * テンプレートをクラスのスコープの外で include する。
     *
     * クロージャをどのクラスのスコープにも束縛しないことで、テンプレートから Template の
     * private なプロパティ・メソッドに触れられないようにし、$this も束縛しない。
     *
     * @param array<string, mixed> $args
     */
    private static function includeFile(string $file, array $args): void
    {
        $include = Closure::bind(
            /** @param array{string, array<string, mixed>} $args */
            static function (array $args): void {
                // $args には [テンプレートのパス, テンプレートに渡す引数] が来る。分割代入で $args を
                // テンプレートに渡す引数に置き換え、代入式の値(元の配列)の [0] からパスを取り出す。
                // パスを変数に置かないため、テンプレートから見える変数は $args だけになる。
                include ([, $args] = $args)[0];
            },
            null,
            null,
        );

        $include([$file, $args]);
    }
}
