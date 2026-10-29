<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\Rule\Url;
use TMSWP\Core\Form\Validator;
use TMSWP\Testing\UnitTestCase;

/**
 * 同梱の規則Urlの仕様(docs/05 §同梱ルール一覧)。旧iwf2bのUrlRuleTestの検証の値を移植する(docs/10 §8.1の16)。
 * 旧 FW と結果が変わる値には、旧 FW の結果をコメントで残す。
 */
final class UrlTest extends UnitTestCase
{
    /**
     * 既定のスキーム(http・https)の境界。空値は検証しない。
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function defaultProtocols(): iterable
    {
        yield 'https' => ['https://example.com', true];
        yield 'http' => ['http://example.com', true];
        yield 'ftp' => ['ftp://example.com', false];
        yield 'empty value' => ['', true];
    }

    #[DataProvider('defaultProtocols')]
    public function testUrlAcceptsHttpAndHttpsByDefault(string $url, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('url', 'URL', [new Url()]);

        self::assertSame($valid ? [] : ['url' => ['Please enter a valid URL for URL.']], (new Validator())->validate($definition, ['url' => $url]));
    }

    /** @return iterable<array{string, bool}> 旧 UrlRuleTest の valid_urls・invalid_urls の全件(既定の http・https) */
    public static function urls(): iterable
    {
        return [
            ['http://a.pl', true],
            ['http://www.example.com', true],
            ['http://www.example.com.', true],
            ['http://www.example.museum', true],
            ['https://example.com/', true],
            ['https://example.com:80/', true],
            ['http://examp_le.com', false], // 旧: 合格
            ['http://www.sub_domain.examp_le.com', false], // 旧: 合格
            ['http://www.example.coop/', true],
            ['http://www.test-example.com/', true],
            ['http://www.symfony.com/', true],
            ['http://symfony.fake/blog/', true],
            ['http://symfony.com/?', true],
            ['http://symfony.com/search?type=&q=url+validator', true],
            ['http://symfony.com/#', true],
            ['http://symfony.com/#?', true],
            ['http://www.symfony.com/doc/current/book/validation.html#supported-constraints', true],
            ['http://very.long.domain.name.com/', true],
            ['http://localhost/', true],
            ['http://myhost123/', true],
            ['http://127.0.0.1/', true],
            ['http://127.0.0.1:80/', true],
            ['http://[::1]/', true],
            ['http://[::1]:80/', true],
            ['http://[1:2:3::4:5:6:7]/', true],
            ['http://sãopaulo.com/', true],
            ['http://xn--sopaulo-xwa.com/', true],
            ['http://sãopaulo.com.br/', true],
            ['http://xn--sopaulo-xwa.com.br/', true],
            ['http://пример.испытание/', true],
            ['http://xn--e1afmkfd.xn--80akhbyknj4f/', true],
            ['http://مثال.إختبار/', true],
            ['http://xn--mgbh0fb.xn--kgbechtv/', true],
            ['http://例子.测试/', true],
            ['http://xn--fsqu00a.xn--0zwm56d/', true],
            ['http://例子.測試/', true],
            ['http://xn--fsqu00a.xn--g6w251d/', true],
            ['http://例え.テスト/', true],
            ['http://xn--r8jz45g.xn--zckzah/', true],
            ['http://مثال.آزمایشی/', true],
            ['http://xn--mgbh0fb.xn--hgbk6aj7f53bba/', true],
            ['http://실례.테스트/', true],
            ['http://xn--9n2bp8q.xn--9t4b11yi5a/', true],
            ['http://العربية.idn.icann.org/', true],
            ['http://xn--ogb.idn.icann.org/', true],
            ['http://xn--e1afmkfd.xn--80akhbyknj4f.xn--e1afmkfd/', true],
            ['http://xn--espaa-rta.xn--ca-ol-fsay5a/', true],
            ['http://xn--d1abbgf6aiiy.xn--p1ai/', true],
            ['http://☎.com/', true],
            ['http://username:password@symfony.com', true],
            ['http://user.name:password@symfony.com', true],
            ['http://user_name:pass_word@symfony.com', true],
            ['http://username:pass.word@symfony.com', true],
            ['http://user.name:pass.word@symfony.com', true],
            ['http://user-name@symfony.com', true],
            ['http://user_name@symfony.com', true],
            ['http://symfony.com?', true],
            ['http://symfony.com?query=1', true],
            ['http://symfony.com/?query=1', true],
            ['http://symfony.com#', true],
            ['http://symfony.com#fragment', true],
            ['http://symfony.com/#fragment', true],
            ['http://symfony.com/#one_more%20test', true],
            ['http://example.com/exploit.html?hello[0]=test', true],
            ['example.com', false],
            ['://example.com', false],
            ['http ://example.com', false],
            ['http:/example.com', false],
            ['http://example.com::aa', false],
            ['http://example.com:aa', false],
            ['ftp://example.fr', false],
            ['faked://example.fr', false],
            ['http://127.0.0.1:aa/', false],
            ['ftp://[::1]/', false],
            ['http://[::1', false],
            ['http://hello.☎/', true], // 旧: 不合格
            ['http://:password@symfony.com', true], // 旧: 不合格
            ['http://:password@@symfony.com', false],
            ['http://username:passwordsymfony.com', false],
            ['http://usern@me:password@symfony.com', false],
            ['http://example.com/exploit.html?<script>alert(1);</script>', true], // 旧: 不合格
            ['http://example.com/exploit.html?hel lo', false],
            ['http://example.com/exploit.html?not_a%hex', true], // 旧: 不合格
            ['http://', false],
        ];
    }

    /**
     * Url は国際化ドメイン名のホスト名を idn_to_ascii(UTS #46)で ASCII に変換してから、filter_var(FILTER_VALIDATE_URL)と
     * スキームで確かめる(旧 FW の Symfony 由来の正規表現は廃止。docs/05)。そのため、旧 FW と結果の違う値がある
     * (ホスト名の _ は不合格、UTS #46 で変換できる記号のホスト名は合格、クエリの未エスケープの文字は合格)。
     */
    #[DataProvider('urls')]
    public function testUrlFollowsFilterValidateUrlAndTheSchemes(string $url, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('url', 'URL', [new Url()]);

        self::assertSame($valid ? [] : ['url' => ['Please enter a valid URL for URL.']], (new Validator())->validate($definition, ['url' => $url]));
    }

    /**
     * 旧 UrlRuleTest の valid_relative_urls・invalid_relative_urls の全件。Url はスキームの無い URL を受け付けない
     * (旧 relative の指定は無い)。
     *
     * @return iterable<array{string, bool}>
     */
    public static function relativeUrls(): iterable
    {
        return [
            ['//example.com', false], // 旧: 合格
            ['//examp_le.com', false], // 旧: 合格
            ['//symfony.fake/blog/', false], // 旧: 合格
            ['//symfony.com/search?type=&q=url+validator', false], // 旧: 合格
            ['/example.com', false],
            ['//example.com::aa', false],
            ['//example.com:aa', false],
            ['//127.0.0.1:aa/', false],
            ['//[::1', false],
            ['//hello.☎/', false],
            ['//:password@symfony.com', false],
            ['//:password@@symfony.com', false],
            ['//username:passwordsymfony.com', false],
            ['//usern@me:password@symfony.com', false],
            ['//example.com/exploit.html?<script>alert(1);</script>', false],
            ['//example.com/exploit.html?hel lo', false],
            ['//example.com/exploit.html?not_a%hex', false],
            ['//', false],
        ];
    }

    #[DataProvider('relativeUrls')]
    public function testUrlRejectsUrlsWithoutAScheme(string $url, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('url', 'URL', [new Url()]);

        self::assertSame($valid ? [] : ['url' => ['Please enter a valid URL for URL.']], (new Validator())->validate($definition, ['url' => $url]));
    }

    /** @return iterable<array{string, bool}> 旧 UrlRuleTest の valid_custom_protocol_urls の全件 */
    public static function customProtocolUrls(): iterable
    {
        return [
            ['ftp://example.com', true],
            ['file://127.0.0.1', true],
            ['git://[::1]/', true],
        ];
    }

    #[DataProvider('customProtocolUrls')]
    public function testUrlAcceptsTheGivenProtocolsOnly(string $url, bool $valid): void
    {
        $definition = FormDefinition::create('form')
            ->text('custom', 'Custom', [new Url(['ftp', 'file', 'git'])])
            ->text('web', 'Web', [new Url()]);

        $errors = (new Validator())->validate($definition, ['custom' => $url, 'web' => $url]);

        self::assertSame($valid, !\array_key_exists('custom', $errors));
        // 既定(http・https)では不合格
        self::assertSame(['Please enter a valid URL for Web.'], $errors['web'] ?? null);
    }

    /**
     * 国際化ドメイン名のホスト名はUTS #46でASCIIに変換して確かめる。変換できないホスト名(_を含む・空のラベル・
     * 63オクテットを超えるラベル等)は不合格。パス・クエリの非ASCIIは変換しないため、filter_varの判定どおり不合格。
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function internationalizedDomainNameUrls(): iterable
    {
        yield 'Japanese host' => ['https://例え.jp/', true];
        yield 'Japanese host with a path and a query' => ['https://日本語.jp/path?q=1', true];
        yield 'userinfo and port' => ['https://user:pass@例え.jp:8080/', true];
        yield 'full-width and upper-case letters mapped by UTS #46' => ['https://ＥＸＡＭＰＬＥ.例え.JP/', true];
        yield 'underscore in an ASCII label' => ['https://exa_mple.例え.jp/', false];
        yield 'underscore in a non-ASCII label' => ['https://例_え.jp/', false];
        yield 'empty label' => ['https://a..例え.jp/', false];
        yield 'label starting with a hyphen' => ['https://-例え.jp/', false];
        yield 'label longer than 63 octets' => ['https://' . str_repeat('あ', 64) . '.jp/', false];
        yield 'invalid punycode label' => ['https://xn--a.例え.jp/', false];
        yield 'non-ASCII query' => ['https://例え.jp/?q=例え', false];
        yield 'percent-encoded path and query' => ['https://例え.jp/%E4%BE%8B?q=%E4%BE%8B', true];
    }

    #[DataProvider('internationalizedDomainNameUrls')]
    public function testUrlConvertsInternationalizedDomainNamesWithUts46(string $url, bool $valid): void
    {
        $definition = FormDefinition::create('form')->text('url', 'URL', [new Url()]);

        self::assertSame($valid ? [] : ['url' => ['Please enter a valid URL for URL.']], (new Validator())->validate($definition, ['url' => $url]));
    }

    /**
     * 変換するのはホスト名の部分だけ。パスやuserinfoに同じ文字列があっても置き換えないため、非ASCIIが残って不合格
     * (URLの全体を置き換えると、どちらも合格になる)。値そのものは書き換えない。
     */
    public function testUrlConvertsOnlyTheHostPart(): void
    {
        $definition = FormDefinition::create('form')
            ->text('path', 'Path', [new Url()])
            ->text('userinfo', 'Userinfo', [new Url()])
            ->text('converted', 'Converted', [new Url()]);
        $values = ['path' => 'https://例え.jp/例え.jp', 'userinfo' => 'https://例え.jp@例え.jp/', 'converted' => 'https://例え.jp/xn--r8jz45g.jp'];

        self::assertSame(
            ['path' => ['Please enter a valid URL for Path.'], 'userinfo' => ['Please enter a valid URL for Userinfo.']],
            (new Validator())->validate($definition, $values),
        );
    }

    /** 国際化ドメイン名のURLでも、スキームが$protocolsに無ければ不合格 */
    public function testUrlChecksTheSchemeOfInternationalizedDomainNameUrls(): void
    {
        $definition = FormDefinition::create('form')
            ->text('custom', 'Custom', [new Url(['ftp'])])
            ->text('web', 'Web', [new Url()]);

        self::assertSame(
            ['web' => ['Please enter a valid URL for Web.']],
            (new Validator())->validate($definition, ['custom' => 'ftp://例え.jp/', 'web' => 'ftp://例え.jp/']),
        );
    }

    /**
     * ext-intlが無ければ、規則を作る時点でFormConfigException(入力値に応じた実行時の例外にしない)。
     * idn_to_asciiをdisable_functionsで無くした別のPHPのプロセスで確かめる。このテストで消えるのは関数だけで、intlの定数は
     * 残るため、クラスの読み込みやコンストラクタの検査より前にintlの定数を評価する不具合は検出できない。
     */
    public function testUrlRequiresTheIntlExtension(): void
    {
        $code = <<<'PHP'
            require $argv[1];
            try {
                new TMSWP\Core\Form\Rule\Url();
                echo 'no exception';
            } catch (TMSWP\Core\Exception\Form\FormConfigException $exception) {
                echo $exception->getMessage();
            }
            PHP;
        $process = proc_open(
            [PHP_BINARY, '-d', 'disable_functions=idn_to_ascii', '-r', $code, self::composerAutoloadFile()],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), "script failed: {$stderr}");
        self::assertSame('Url requires the intl extension (ext-intl) to validate internationalized domain names', $stdout);
    }
}
