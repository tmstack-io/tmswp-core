<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\View;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\InvalidTemplateSlugException;
use TMSWP\Core\Exception\TemplateNotFoundException;
use TMSWP\Core\View\Template;
use TMSWP\Core\View\TemplateLocator;
use TMSWP\Testing\UnitTestCase;

final class TemplateTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Template::setLocator(null);

        parent::tearDown();
    }

    public function testTemplateLocatorResolvesThemeBeforeFallbackAndRejectsInvalidSlug(): void
    {
        $child = $this->createTempDirectory('template-child');
        $parent = $this->createTempDirectory('template-parent');
        $fallback = $this->createTempDirectory('template-fallback');
        $locator = new TemplateLocator();

        Functions\when('get_stylesheet_directory')->alias(static fn(): string => $child);
        Functions\when('get_template_directory')->alias(static fn(): string => $parent);

        $this->writeFile($fallback . '/parts/card.php', 'fallback');
        $this->writeFile($parent . '/parts/card.php', 'parent');
        $this->writeFile($child . '/parts/card.php', 'child');

        $locator->addFallbackDir($fallback);
        $locator->addFallbackDir($fallback);

        self::assertSame([$fallback], $locator->fallbackDirs());
        self::assertSame($child . '/parts/card.php', $locator->locate('parts/card'));

        unlink($child . '/parts/card.php');
        $locator->flush();
        self::assertSame($parent . '/parts/card.php', $locator->locate('parts/card'));

        unlink($parent . '/parts/card.php');
        $locator->flush();
        self::assertSame($fallback . '/parts/card.php', $locator->locate('parts/card'));

        $this->expectException(InvalidTemplateSlugException::class);
        $locator->locate('../card');
    }

    #[DataProvider('emptyFallbackDirProvider')]
    public function testTemplateLocatorRejectsFallbackDirThatIsEmptyAfterTrimmingSeparators(string $dir): void
    {
        $locator = new TemplateLocator();

        try {
            $locator->addFallbackDir($dir);
            self::fail('TemplateLocator::addFallbackDir() は空になるパスで例外を投げる必要があります。');
        } catch (InvalidArgumentException $exception) {
            self::assertSame("template fallback directory must not be empty: '{$dir}'", $exception->getMessage());
            self::assertSame([], $locator->fallbackDirs());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function emptyFallbackDirProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'slash' => ['/'];
        yield 'backslash' => ['\\'];
        yield 'mixed separators' => ['/\\/'];
    }

    public function testTemplateRenderIncludesOnlyArgsVariableAndCleansBufferOnFailure(): void
    {
        $child = $this->createTempDirectory('render-child');
        $parent = $this->createTempDirectory('render-parent');
        $fallback = $this->createTempDirectory('render-fallback');
        $locator = new TemplateLocator();

        Functions\when('get_stylesheet_directory')->alias(static fn(): string => $child);
        Functions\when('get_template_directory')->alias(static fn(): string => $parent);

        $this->writeFile(
            $fallback . '/parts/card.php',
            <<<'PHP'
<?php
echo $args['title'] . '|' . implode(',', array_keys(get_defined_vars()));
PHP,
        );
        $this->writeFile(
            $fallback . '/parts/failing.php',
            <<<'PHP'
<?php
echo 'dirty';
throw new RuntimeException('render failed');
PHP,
        );

        $locator->addFallbackDir($fallback);
        Template::setLocator($locator);

        self::assertSame('Card|args', Template::render('parts/card', ['title' => 'Card']));

        ob_start();

        try {
            Template::render('parts/failing');
            self::fail('Template::render() は例外を再送出する必要があります。');
        } catch (RuntimeException $exception) {
            self::assertSame('render failed', $exception->getMessage());
            self::assertSame('', (string) ob_get_clean());
        }
    }

    public function testTemplateIncludesFileOutsideTemplateClassScope(): void
    {
        $fallback = $this->createTempDirectory('scope-fallback');
        $locator = new TemplateLocator();

        Functions\when('get_stylesheet_directory')->alias(static fn(): string => $fallback . '/missing-child');
        Functions\when('get_template_directory')->alias(static fn(): string => $fallback . '/missing-parent');

        $this->writeFile(
            $fallback . '/parts/probe.php',
            <<<'PHP'
<?php
$results = [];

try {
    new \TMSWP\Core\View\Template();
    $results[] = 'constructor:accessible';
} catch (\Error $error) {
    $results[] = 'constructor:' . $error->getMessage();
}

try {
    \TMSWP\Core\View\Template::$locator;
    $results[] = 'property:accessible';
} catch (\Error $error) {
    $results[] = 'property:' . $error->getMessage();
}

$results[] = isset($this) ? 'this:bound' : 'this:unbound';
echo implode("\n", $results);
PHP,
        );

        $locator->addFallbackDir($fallback);
        Template::setLocator($locator);

        self::assertSame(
            implode("\n", [
                'constructor:Call to private TMSWP\Core\View\Template::__construct() from global scope',
                'property:Cannot access private property TMSWP\Core\View\Template::$locator',
                'this:unbound',
            ]),
            Template::render('parts/probe'),
        );
    }

    public function testTemplatePartFiresGetTemplatePartHooksBeforeIncludingTemplate(): void
    {
        $fallback = $this->createTempDirectory('hook-fallback');
        $locator = new TemplateLocator();
        $args = ['title' => 'Card'];

        Functions\when('get_stylesheet_directory')->alias(static fn(): string => $fallback . '/missing-child');
        Functions\when('get_template_directory')->alias(static fn(): string => $fallback . '/missing-parent');

        $this->writeFile($fallback . '/parts/card.php', '<?php echo $args[\'title\'];');

        $locator->addFallbackDir($fallback);
        Template::setLocator($locator);

        Actions\expectDone('get_template_part_parts/card')
            ->once()
            ->with('parts/card', null, $args)
            ->whenHappen(static function (): void {
                echo '[slug]';
            });
        Actions\expectDone('get_template_part')
            ->once()
            ->with('parts/card', '', ['parts/card.php'], $args)
            ->whenHappen(static function (): void {
                echo '[generic]';
            });

        self::assertSame('[slug][generic]Card', Template::render('parts/card', $args));
    }

    public function testTemplatePartLocatesTemplateAfterFiringHooks(): void
    {
        $child = $this->createTempDirectory('hook-created-child');

        Functions\when('get_stylesheet_directory')->alias(static fn(): string => $child);
        Functions\when('get_template_directory')->alias(static fn(): string => $child);

        Actions\expectDone('get_template_part_parts/created')
            ->once()
            ->whenHappen(function () use ($child): void {
                $this->writeFile($child . '/parts/created.php', '<?php echo \'created by hook\';');
            });

        self::assertSame('created by hook', Template::render('parts/created'));
    }

    public function testTemplatePartFiresGetTemplatePartHooksBeforeThrowingForMissingTemplate(): void
    {
        $child = $this->createTempDirectory('missing-hook-child');
        $fired = [];

        Functions\when('get_stylesheet_directory')->alias(static fn(): string => $child);
        Functions\when('get_template_directory')->alias(static fn(): string => $child);

        Actions\expectDone('get_template_part_parts/missing')
            ->once()
            ->with('parts/missing', null, [])
            ->whenHappen(static function () use (&$fired): void {
                $fired[] = 'slug';
            });
        Actions\expectDone('get_template_part')
            ->once()
            ->with('parts/missing', '', ['parts/missing.php'], [])
            ->whenHappen(static function () use (&$fired): void {
                $fired[] = 'generic';
            });

        try {
            Template::part('parts/missing');
            self::fail('Template::part() は存在しないテンプレートで例外を投げる必要があります。');
        } catch (TemplateNotFoundException) {
            self::assertSame(['slug', 'generic'], $fired);
        }
    }

    public function testTemplatePartRejectsInvalidSlugBeforeFiringHooks(): void
    {
        Actions\expectDone('get_template_part')->never();

        $this->expectException(InvalidTemplateSlugException::class);

        Template::part('../card');
    }

    public function testTemplatePartThrowsWhenTemplateIsMissing(): void
    {
        $child = $this->createTempDirectory('missing-child');
        $parent = $this->createTempDirectory('missing-parent');

        Functions\when('get_stylesheet_directory')->alias(static fn(): string => $child);
        Functions\when('get_template_directory')->alias(static fn(): string => $parent);

        $this->expectException(TemplateNotFoundException::class);
        $this->expectExceptionMessage('template \'parts/missing\' was not found.');

        Template::part('parts/missing');
    }

    /**
     * 同じslugの2回目以降の解決は、ファイルを探さずにキャッシュから返す(見つからなかった結果のnullも含む)。
     * is_file()の呼び出しを、数を記録するストリームラッパーのパスで数える。
     */
    public function testSecondResolutionOfSameSlugIsReturnedFromCacheWithoutIsFile(): void
    {
        ViewTestCountingStreamWrapper::register();
        ViewTestCountingStreamWrapper::$files = ['tmswp-view-test://plugin/parts/card.php'];
        $locator = new TemplateLocator();

        Functions\when('get_stylesheet_directory')->justReturn('tmswp-view-test://child');
        Functions\when('get_template_directory')->justReturn('tmswp-view-test://parent');
        $locator->addFallbackDir('tmswp-view-test://plugin');

        try {
            self::assertSame('tmswp-view-test://plugin/parts/card.php', $locator->locate('parts/card'));
            self::assertSame([
                'tmswp-view-test://child/parts/card.php',
                'tmswp-view-test://parent/parts/card.php',
                'tmswp-view-test://plugin/parts/card.php',
            ], ViewTestCountingStreamWrapper::$stats);
            self::assertNull($locator->locate('parts/missing'));
            self::assertCount(6, ViewTestCountingStreamWrapper::$stats);

            // 2回目以降(exists()を含む)はis_file()を呼ばない。PHPのstatのキャッシュに頼らないよう消してから呼ぶ
            clearstatcache();
            self::assertSame('tmswp-view-test://plugin/parts/card.php', $locator->locate('parts/card'));
            self::assertTrue($locator->exists('parts/card'));
            self::assertNull($locator->locate('parts/missing'));
            self::assertFalse($locator->exists('parts/missing'));
            self::assertCount(6, ViewTestCountingStreamWrapper::$stats);

            // flush()の後はもう一度探す
            $locator->flush();
            clearstatcache();
            self::assertSame('tmswp-view-test://plugin/parts/card.php', $locator->locate('parts/card'));
            self::assertCount(9, ViewTestCountingStreamWrapper::$stats);
        } finally {
            ViewTestCountingStreamWrapper::unregister();
        }
    }

    /**
     * §1.4の形式に合わないslug(`..`・先頭や末尾の`/`・`.php`付き・空文字等)は、locate()・exists()・candidatePaths()・
     * part()のどれでもInvalidTemplateSlugExceptionになり、メッセージにslugを含む。part()はフックを発火しない。
     */
    #[DataProvider('invalidSlugs')]
    public function testInvalidSlugIsRejectedByEveryEntryPoint(string $slug): void
    {
        $locator = new TemplateLocator();
        $calls = [
            'locate' => static fn(): mixed => $locator->locate($slug),
            'exists' => static fn(): mixed => $locator->exists($slug),
            'candidatePaths' => static fn(): mixed => $locator->candidatePaths($slug),
            'assertSlug' => static function () use ($locator, $slug): void {
                $locator->assertSlug($slug);
            },
            'Template::part' => static function () use ($slug): void {
                Template::part($slug);
            },
            'Template::render' => static fn(): string => Template::render($slug),
        ];
        $messages = [];

        Template::setLocator($locator);
        Actions\expectDone('get_template_part')->never();

        foreach ($calls as $label => $call) {
            try {
                $call();
                $messages[$label] = null;
            } catch (InvalidTemplateSlugException $exception) {
                $messages[$label] = $exception->getMessage();
            }
        }

        self::assertSame(array_fill_keys(array_keys($calls), "invalid template slug: {$slug}"), $messages);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSlugs(): iterable
    {
        yield 'parent segment' => ['../card'];
        yield 'parent segment in the middle' => ['parts/../card'];
        yield 'leading slash' => ['/parts/card'];
        yield 'trailing slash' => ['parts/card/'];
        yield 'with .php' => ['parts/card.php'];
        yield 'empty string' => [''];
        yield 'empty segment' => ['parts//card'];
        yield 'dot segment' => ['./card'];
        yield 'backslash' => ['parts\\card'];
        yield 'NUL byte' => ["parts/card\0"];
        yield 'space' => ['parts/my card'];
    }

    /** TemplateNotFoundExceptionのメッセージは、slugと探したすべての候補のパスを探した順に含む。 */
    public function testTemplateNotFoundMessageContainsSlugAndAllCandidatePaths(): void
    {
        $child = $this->createTempDirectory('candidates-child');
        $parent = $this->createTempDirectory('candidates-parent');
        $first = $this->createTempDirectory('candidates-first');
        $second = $this->createTempDirectory('candidates-second');
        $locator = new TemplateLocator();

        Functions\when('get_stylesheet_directory')->justReturn($child . '/');
        Functions\when('get_template_directory')->justReturn($parent);
        $locator->addFallbackDir($first);
        $locator->addFallbackDir($second . '/');
        Template::setLocator($locator);

        $candidates = [
            $child . DIRECTORY_SEPARATOR . 'parts/missing.php',
            $parent . DIRECTORY_SEPARATOR . 'parts/missing.php',
            $first . DIRECTORY_SEPARATOR . 'parts/missing.php',
            $second . DIRECTORY_SEPARATOR . 'parts/missing.php',
        ];
        self::assertSame($candidates, $locator->candidatePaths('parts/missing'));

        try {
            Template::part('parts/missing');
            self::fail('Template::part()は存在しないテンプレートで例外を投げる必要があります。');
        } catch (TemplateNotFoundException $exception) {
            self::assertSame("template 'parts/missing' was not found. Candidates: " . implode(', ', $candidates), $exception->getMessage());
        }
    }

    /** 子テーマと親テーマが同じなら、そのディレクトリは候補に1回だけ入る。 */
    public function testCandidatePathsListThemeDirectoryOnceWhenChildIsParent(): void
    {
        $theme = $this->createTempDirectory('candidates-theme');
        $fallback = $this->createTempDirectory('candidates-fallback');
        $locator = new TemplateLocator();

        Functions\when('get_stylesheet_directory')->justReturn($theme);
        Functions\when('get_template_directory')->justReturn($theme);
        $locator->addFallbackDir($fallback);

        self::assertSame([
            $theme . DIRECTORY_SEPARATOR . 'parts/card.php',
            $fallback . DIRECTORY_SEPARATOR . 'parts/card.php',
        ], $locator->candidatePaths('parts/card'));
    }

    /**
     * フォールバックのディレクトリが複数あるとき、同じslugは登録順で先のディレクトリのものに解決する。先のディレクトリに
     * 無ければ次のディレクトリを探す。テーマにあればテーマが先に勝つ。
     */
    public function testMultipleFallbackDirectoriesAreSearchedInRegistrationOrder(): void
    {
        $child = $this->createTempDirectory('order-child');
        $first = $this->createTempDirectory('order-first');
        $second = $this->createTempDirectory('order-second');
        $third = $this->createTempDirectory('order-third');
        $locator = new TemplateLocator();

        Functions\when('get_stylesheet_directory')->justReturn($child);
        Functions\when('get_template_directory')->justReturn($child);

        $this->writeFile($first . '/shared/card.php', '<?php echo \'first\';');
        $this->writeFile($second . '/shared/card.php', '<?php echo \'second\';');
        $this->writeFile($third . '/shared/card.php', '<?php echo \'third\';');
        $this->writeFile($third . '/shared/only-third.php', '<?php echo \'only third\';');

        $locator->addFallbackDir($second);
        $locator->addFallbackDir($first);
        $locator->addFallbackDir($third);
        Template::setLocator($locator);

        self::assertSame([$second, $first, $third], $locator->fallbackDirs());
        self::assertSame('second', Template::render('shared/card'));
        self::assertSame('only third', Template::render('shared/only-third'));

        unlink($second . '/shared/card.php');
        $locator->flush();
        self::assertSame('first', Template::render('shared/card'));

        $this->writeFile($child . '/shared/card.php', '<?php echo \'theme\';');
        $locator->flush();
        self::assertSame('theme', Template::render('shared/card'));
    }

    /** render()はテンプレートで例外が起きても出力バッファを残さない(ob_get_level()が変わらない)。 */
    public function testRenderKeepsOutputBufferLevelWhenTemplateThrows(): void
    {
        $fallback = $this->createTempDirectory('ob-fallback');
        $locator = new TemplateLocator();

        Functions\when('get_stylesheet_directory')->justReturn($fallback . '/missing-child');
        Functions\when('get_template_directory')->justReturn($fallback . '/missing-parent');
        $this->writeFile($fallback . '/parts/failing.php', '<?php echo \'dirty\'; throw new \RuntimeException(\'render failed\');');
        $locator->addFallbackDir($fallback);
        Template::setLocator($locator);
        $level = ob_get_level();

        try {
            Template::render('parts/failing');
            self::fail('Template::render()は例外を再送出する必要があります。');
        } catch (RuntimeException $exception) {
            self::assertSame('render failed', $exception->getMessage());
            self::assertSame($level, ob_get_level());
        }

        try {
            Template::render('parts/missing');
            self::fail('Template::render()は存在しないテンプレートで例外を投げる必要があります。');
        } catch (TemplateNotFoundException) {
            self::assertSame($level, ob_get_level());
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::fail("テストファイル用ディレクトリを作成できません: {$dir}");
        }

        if (file_put_contents($path, $contents) === false) {
            self::fail("テストファイルを書き込めません: {$path}");
        }
    }
}

/**
 * is_file()の呼び出しを数えるためのストリームラッパー。$filesにあるパスだけをファイルとして返し、問い合わせられた
 * パスを$statsに記録する。
 */
final class ViewTestCountingStreamWrapper
{
    private const string PROTOCOL = 'tmswp-view-test';

    /** @var resource|null PHPがストリームの文脈を設定する */
    public $context;

    /** @var list<string> ファイルとして扱うパス */
    public static array $files = [];

    /** @var list<string> url_stat()に渡されたパス(呼び出し順) */
    public static array $stats = [];

    public static function register(): void
    {
        self::$files = [];
        self::$stats = [];

        if (!stream_wrapper_register(self::PROTOCOL, self::class)) {
            throw new RuntimeException('failed to register the stream wrapper');
        }
    }

    public static function unregister(): void
    {
        stream_wrapper_unregister(self::PROTOCOL);
        clearstatcache();
    }

    /** @return array<string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        self::$stats[] = $path;

        return \in_array($path, self::$files, true) ? ['mode' => 0o100644, 'size' => 0] : false;
    }
}
