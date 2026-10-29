<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Kernel;

use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use TMSWP\Core\Container\Container;
use TMSWP\Core\Kernel\Discovery;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Testing\UnitTestCase;

/**
 * Kernel・Discovery・Container・DiscoveryFile・KernelConfig が、__callStatic・extract()・グローバルな可変状態(許可していない
 * static プロパティ、関数・メソッドの static 変数、global 文、許可した形以外での $GLOBALS の使用)を持たないことを、
 * Reflection とソースのトークンで確かめる。
 */
final class KernelGlobalStateTest extends UnitTestCase
{
    /**
     * クラスごとに、`$x = $GLOBALS['<名前>'] ?? null;` の形で読むことを許可したグローバル変数の名前(昇順)。
     *
     * グローバル変数への書き込みは、書き込みの形(代入・unset()・分解代入・参照・参照渡し・foreach 等)を列挙して探すと
     * PHP の構文の組み合わせを網羅できないため、探さない。代わりに、$GLOBALS を使ってよい形と名前をここに限定し、
     * それ以外の $GLOBALS の使用をすべて失敗にする。WordPress のグローバル変数を新たに読む必要が出たら、
     * 対象のクラスのリストへ名前を明示的に足す(使わなくなった名前は testItUsesGlobalsOnlyInTheAllowedForm が
     * 失敗するので、リストから消す)。
     *
     * @var array<class-string, list<string>>
     */
    private const ALLOWED_GLOBALS = [
        Kernel::class => ['wp_actions', 'wp_version'],
        Discovery::class => [],
        Container::class => [],
        DiscoveryFile::class => [],
        KernelConfig::class => [],
    ];

    /**
     * 許可した読み取りの形(`$x = $GLOBALS['<名前>'] ?? null;`)の代入先にしてはならない変数。代入するとグローバルな状態
     * (スーパーグローバル)やオブジェクトを書き換えるため。PHP の変数名は大文字小文字を区別するので、そのまま比べる。
     *
     * @var list<string>
     */
    private const NON_LOCAL_TARGETS = [
        '$GLOBALS', '$_GET', '$_POST', '$_COOKIE', '$_FILES', '$_SERVER', '$_ENV', '$_REQUEST', '$_SESSION', '$this',
    ];

    /**
     * クラスと、許可した static プロパティ。Kernel のレジストリと、CLI コマンド・翻訳の読み込みを1回だけ登録するためのフラグ、
     * boot の進行中の印(booting)。booting は、boot の途中(logChannels・拡張の register()・キャッチアップの初期化)から
     * 別の Kernel を boot すること(まだレジストリに無い外側の Kernel との競合の検査をすり抜けうる)を禁止するために、
     * プロセス全体で1つ持つ必要がある(boot の finally で必ず null に戻る)。
     *
     * @return iterable<string, array{class-string, list<string>}>
     */
    public static function classes(): iterable
    {
        yield 'Kernel' => [Kernel::class, ['booting', 'cliRegistered', 'kernels', 'textdomainRegistered']];
        yield 'Discovery' => [Discovery::class, []];
        yield 'Container' => [Container::class, []];
        yield 'DiscoveryFile' => [DiscoveryFile::class, []];
        yield 'KernelConfig' => [KernelConfig::class, []];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('classes')]
    public function testItHasNoCallStatic(string $class): void
    {
        self::assertFalse((new ReflectionClass($class))->hasMethod('__callStatic'));
    }

    /**
     * @param class-string $class
     * @param list<string> $allowed
     */
    #[DataProvider('classes')]
    public function testItHasOnlyAllowedStaticProperties(string $class, array $allowed): void
    {
        $properties = array_map(
            static fn(ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass($class))->getProperties(ReflectionProperty::IS_STATIC),
        );
        sort($properties);

        self::assertSame($allowed, $properties);
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('classes')]
    public function testItDoesNotCallExtract(string $class): void
    {
        $file = (new ReflectionClass($class))->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        self::assertSame([], self::extractCallLines($source), "extract() is called in {$file}");
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('classes')]
    public function testItHasNoStaticVariables(string $class): void
    {
        $file = self::sourceFile($class);

        self::assertSame([], self::staticVariableLines(self::source($file)), "a static variable is declared in {$file}");
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('classes')]
    public function testItHasNoGlobalStatements(string $class): void
    {
        $file = self::sourceFile($class);

        self::assertSame([], self::globalStatementLines(self::source($file)), "a global statement is used in {$file}");
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('classes')]
    public function testItUsesGlobalsOnlyInTheAllowedForm(string $class): void
    {
        $file = self::sourceFile($class);
        self::assertArrayHasKey($class, self::ALLOWED_GLOBALS);
        $allowed = self::ALLOWED_GLOBALS[$class];
        $source = self::source($file);

        self::assertSame([], self::disallowedGlobalsLines($source, $allowed), "\$GLOBALS is used in a form that is not allowed in {$file}");

        // 許可リストに使っていない名前が残っていないこと(リストを実際の使用と一致させ、緩いまま放置しない)
        $used = array_values(array_unique(array_filter(array_column(self::globalsUsages($source), 'name'), 'is_string')));
        sort($used);
        self::assertSame($allowed, $used);
    }

    /** 検出の確認: 関数としての extract() の呼び出しだけを数え、同名のメソッドの呼び出し・宣言は数えない */
    public function testExtractCallDetectionFindsOnlyFunctionCalls(): void
    {
        $source = <<<'PHP'
<?php
extract($a);
\extract ($b);
EXTRACT($c);
$object->extract($d);
$object?->extract($e);
Foo::extract($f);
function extract($i) {}
new extract();
$extract = 1;
// extract($g);
'extract($h)';
PHP;

        self::assertSame([2, 3, 4], self::extractCallLines($source));
    }

    /** 検出の確認: 関数・メソッドの static 変数だけを数え、static のメソッド・クロージャ・プロパティ・型・static:: は数えない */
    public function testStaticVariableDetectionFindsOnlyStaticVariables(): void
    {
        $source = <<<'PHP'
<?php
function counter() { static $count = 0; }
function pair() { static $a, $b = 1; }
final class Foo
{
    private static array $items = [];
    public static ?int $value = null;
    static private $legacy;
    public static function create(): static
    {
        $fn = static fn(): int => 1;
        $closure = static function (): void {
            STATIC $calls = 0;
        };
        static::bar();
        return new static();
    }
    public function check(object $other): bool { return $other instanceof static; }
}
// static $comment;
'static $string';
PHP;

        self::assertSame([2, 3, 13], self::staticVariableLines($source));
    }

    /** 検出の確認: 置き場所(関数・if ブロック・クロージャ)に関係なく global 文をすべて数え、文字列・コメント・メソッド名は数えない */
    public function testGlobalStatementDetectionFindsEveryGlobalStatement(): void
    {
        $source = <<<'PHP'
<?php
function f(): void { global $x; }
function g(): void { if (true) { global $x; } $x = 1; }
$closure = function (): void { global $x; };
GLOBAL $y, $z;
$message = 'cannot determine the WordPress version: global $wp_version is not set';
$object->global();
// global $comment;
/* global $block; */
PHP;

        self::assertSame([2, 3, 4, 5], self::globalStatementLines($source));
    }

    /**
     * 検出の確認: `$x = $GLOBALS['<許可した名前>'] ?? null;` の文だけを通し、ほかの $GLOBALS の使用(書き込み・参照・
     * 引数・許可していない名前・違う形の読み出し・文字列への埋め込み・スーパーグローバルや $this への代入)はすべて数える。
     * 文字列・コメントの中と、参照で作った別名への代入(検査の対象外)は数えない。
     */
    public function testGlobalsDetectionAllowsOnlyTheAllowedReadForm(): void
    {
        $source = <<<'PHP'
<?php
$GLOBALS['x'] = 1;
$GLOBALS['x'][] = 1;
$GLOBALS['x'] ??= 1;
++$GLOBALS['x'];
unset($a, $GLOBALS['x']);
[$GLOBALS['x']] = [1];
list($GLOBALS['x']) = [1];
$r = &$GLOBALS['x'];
sort($GLOBALS['x']);
foreach ($a as $GLOBALS['x']) {}
$v = $GLOBALS['wpdb'] ?? null;
$v = $GLOBALS['wp_version'];
f($GLOBALS['wp_version']);
$GLOBALS['wp_version']->x;
$v = $GLOBALS["wp_version"] ?? null;
$v = $GLOBALS['wp_version'] ?? null ?: 1;
$v = $w = $GLOBALS['wp_version'] ?? null;
$object->v = $GLOBALS['wp_version'] ?? null;
$v[] = $GLOBALS['wp_version'] ?? null;
$$name = $GLOBALS['wp_version'] ?? null;
$v = "{$GLOBALS['x']}";
$_GET = $GLOBALS['wp_actions'] ?? null;
$_POST = $GLOBALS['wp_actions'] ?? null;
$_COOKIE = $GLOBALS['wp_actions'] ?? null;
$_FILES = $GLOBALS['wp_actions'] ?? null;
$_SERVER = $GLOBALS['wp_actions'] ?? null;
$_ENV = $GLOBALS['wp_actions'] ?? null;
$_REQUEST = $GLOBALS['wp_actions'] ?? null;
$_SESSION = $GLOBALS['wp_actions'] ?? null;
$this = $GLOBALS['wp_actions'] ?? null;
$version = $GLOBALS['wp_version'] ?? null;
function reads(): void
{
    $version = $GLOBALS['wp_version'] ?? null;
    $actions	=	/* 読むだけ */ $GLOBALS [ 'wp_actions' ] // 改行を挟む
        ?? null ;
}
$_get = $GLOBALS['wp_actions'] ?? null;
// 参照による別名(わざと作った $_GET の別名への代入)は対象外なので、検出しない
$r =& $_GET;
$r = $GLOBALS['wp_actions'] ?? null;
$message = 'cannot determine the WordPress version: global $wp_version is not set';
$string = '$GLOBALS["x"] = 1';
// $GLOBALS['comment'] = 1;
/* unset($GLOBALS['block']); */
PHP;

        self::assertSame(
            [2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31],
            self::disallowedGlobalsLines($source, ['wp_actions', 'wp_version']),
        );
        // 形が合うものだけが名前を持つ(12行目は形が合うが、許可リストに無い名前なので上で数えている)。
        // 39行目の $_get は、大文字小文字の違う別のローカル変数なので許可した形になる
        self::assertSame(
            [
                ['line' => 12, 'name' => 'wpdb'],
                ['line' => 32, 'name' => 'wp_version'],
                ['line' => 35, 'name' => 'wp_version'],
                ['line' => 36, 'name' => 'wp_actions'],
                ['line' => 39, 'name' => 'wp_actions'],
                ['line' => 42, 'name' => 'wp_actions'],
            ],
            array_values(array_filter(self::globalsUsages($source), static fn(array $usage): bool => $usage['name'] !== null)),
        );
    }

    /**
     * @param class-string $class
     */
    private static function sourceFile(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        self::assertIsString($file);

        return $file;
    }

    private static function source(string $file): string
    {
        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    /**
     * 空白・コメントを除いたトークンを返す。
     *
     * @return list<PhpToken>
     */
    private static function significantTokens(string $source): array
    {
        return array_values(array_filter(
            PhpToken::tokenize($source),
            static fn(PhpToken $token): bool => !$token->isIgnorable(),
        ));
    }

    /**
     * ソースの中で、関数・メソッド・クロージャの static 変数(`static $x;`)を宣言している行を返す。
     *
     * static の直後が変数であるものを数える。static のメソッド・クロージャ(直後が function・fn)、`static::`・`new static`・
     * 型の static(直後が変数でない)は数えない。直前に可視性等の修飾子がある static プロパティの宣言も数えない
     * (static プロパティは testItHasOnlyAllowedStaticProperties が Reflection で確かめる)。
     *
     * @return list<int>
     */
    private static function staticVariableLines(string $source): array
    {
        $tokens = self::significantTokens($source);
        $lines = [];

        foreach ($tokens as $index => $token) {
            if (!$token->is(T_STATIC) || ($tokens[$index + 1] ?? null)?->is(T_VARIABLE) !== true) {
                continue;
            }

            if (($tokens[$index - 1] ?? null)?->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY, T_VAR]) === true) {
                continue;
            }

            $lines[] = $token->line;
        }

        return $lines;
    }

    /**
     * ソースの中で、global 文(`global $x;`)を使っている行を返す。
     *
     * global の効く範囲(関数のスコープ)と、宣言した変数への書き込みの形は追わず、global 文そのものを禁止する。
     * `$object->global()` のようなメンバー名の global は T_STRING になるので数えない。
     *
     * @return list<int>
     */
    private static function globalStatementLines(string $source): array
    {
        return array_values(array_map(
            static fn(PhpToken $token): int => $token->line,
            array_filter(self::significantTokens($source), static fn(PhpToken $token): bool => $token->is(T_GLOBAL)),
        ));
    }

    /**
     * ソースの中で、許可した形でない $GLOBALS の使用(許可していない名前の読み出しを含む)の行を返す。
     *
     * @param list<string> $allowedNames
     *
     * @return list<int>
     */
    private static function disallowedGlobalsLines(string $source, array $allowedNames): array
    {
        $lines = [];

        foreach (self::globalsUsages($source) as $usage) {
            if ($usage['name'] === null || !\in_array($usage['name'], $allowedNames, true)) {
                $lines[] = $usage['line'];
            }
        }

        return $lines;
    }

    /**
     * ソースの中の $GLOBALS のトークンごとに、行と、許可した形の文で読んでいるグローバル変数の名前を返す。
     *
     * 許可した形は、空白・コメントを除いたトークンの列が、文の先頭から厳密に
     * `T_VARIABLE` `=` `$GLOBALS` `[` `'<名前>'` `]` `??` `null` `;` である文だけ(文の先頭は、直前が `;`・`{`・`}`
     * またはソースの先頭であること)。代入先が `$GLOBALS`・スーパーグローバル・`$this` の文は許可しない
     * (NON_LOCAL_TARGETS)。それ以外の形の name は null とする。文字列・コメントの中の `$GLOBALS` はトークンにならない
     * ので対象外になる(文字列へ埋め込んだ `"{$GLOBALS['x']}"` は変数のトークンになるので、許可しない形として数える)。
     *
     * この検査は、普通に書いたコードにグローバルな共有状態が紛れ込むのを防ぐためのもので、変数のデータの流れは追わない。
     * 参照(`$r =& $_GET;` 等)でわざと別名を作ってから許可した形で代入する書き方と、可変変数による `${'GLOBALS'}` は
     * 対象外とする。
     *
     * @return list<array{line: int, name: string|null}>
     */
    private static function globalsUsages(string $source): array
    {
        $tokens = self::significantTokens($source);
        $usages = [];

        foreach ($tokens as $index => $token) {
            if (!$token->is(T_VARIABLE) || $token->text !== '$GLOBALS') {
                continue;
            }

            $usages[] = ['line' => $token->line, 'name' => self::allowedGlobalsReadName($tokens, $index)];
        }

        return $usages;
    }

    /**
     * $tokens[$index] の $GLOBALS が `$x = $GLOBALS['<名前>'] ?? null;` の文の中にあれば、その名前を返す。違えば null。
     *
     * @param list<PhpToken> $tokens
     */
    private static function allowedGlobalsReadName(array $tokens, int $index): ?string
    {
        // 文の先頭の直前。T_CURLY_OPEN 等の text も '{' なので、id で比べる
        $boundary = $tokens[$index - 3] ?? null;

        if ($boundary !== null && !\in_array($boundary->id, [\ord(';'), \ord('{'), \ord('}')], true)) {
            return null;
        }

        $target = $tokens[$index - 2] ?? null;
        $name = $tokens[$index + 2] ?? null;

        $matches = $target?->is(T_VARIABLE) === true && !\in_array($target->text, self::NON_LOCAL_TARGETS, true)
            && ($tokens[$index - 1] ?? null)?->id === \ord('=')
            && ($tokens[$index + 1] ?? null)?->id === \ord('[')
            && $name?->is(T_CONSTANT_ENCAPSED_STRING) === true && preg_match("/\\A'[a-z_][a-z0-9_]*'\\z/", $name->text) === 1
            && ($tokens[$index + 3] ?? null)?->id === \ord(']')
            && ($tokens[$index + 4] ?? null)?->is(T_COALESCE) === true
            && ($tokens[$index + 5] ?? null)?->is(T_STRING) === true && $tokens[$index + 5]->text === 'null'
            && ($tokens[$index + 6] ?? null)?->id === \ord(';');

        return $matches && $name !== null ? substr($name->text, 1, -1) : null;
    }

    /**
     * ソースの中で、関数としての extract() を呼んでいる行を返す。
     *
     * @return list<int>
     */
    private static function extractCallLines(string $source): array
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn(PhpToken $token): bool => !$token->isIgnorable(),
        ));
        $lines = [];

        foreach ($tokens as $index => $token) {
            if (!$token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) || strtolower(ltrim($token->text, '\\')) !== 'extract') {
                continue;
            }

            $previous = $tokens[$index - 1] ?? null;
            $next = $tokens[$index + 1] ?? null;

            if ($next?->text !== '(' || $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW]) === true) {
                continue;
            }

            $lines[] = $token->line;
        }

        return $lines;
    }
}
