<?php

declare(strict_types=1);

namespace TMSWP\Core\Form\Rule;

use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\ValidationContext;
use TMSWP\Core\Form\Validator;

/**
 * URLの形式とスキームを確かめる。ホスト名が国際化ドメイン名なら、idn_to_ascii(UTS #46)でASCIIに変換してから
 * filter_var(FILTER_VALIDATE_URL)で確かめる。パス・クエリの非ASCIIは変換しない(filter_varの判定のまま)。
 * 入力値は書き換えず、合否だけを返す。
 */
final class Url extends Rule
{
    /**
     * @param list<string> $protocols
     *
     * @throws FormConfigException $protocolsが空の場合と、ext-intlが無く国際化ドメイン名を変換できない場合
     */
    public function __construct(private readonly array $protocols = ['http', 'https'], ?string $message = null)
    {
        if ($protocols === []) {
            throw new FormConfigException('Url protocols must not be empty');
        }

        // 入力値に応じて実行時に例外を出さないよう、環境の不足は規則を作る時点で例外にする
        if (!\function_exists('idn_to_ascii')) {
            throw new FormConfigException('Url requires the intl extension (ext-intl) to validate internationalized domain names');
        }

        parent::__construct($message);
    }

    protected function passes(mixed $value, ValidationContext $context): bool
    {
        $url = Validator::stringValue($value);

        if ($url === null) {
            return false;
        }

        $url = self::asciiHost($url);

        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return \is_string($scheme) && \in_array(strtolower($scheme), $this->protocols, true);
    }

    public function defaultMessage(): string
    {
        return __('Please enter a valid URL for {label}.', 'tmswp');
    }

    /**
     * ホスト名に非ASCIIが含まれれば、ホスト名の部分だけをASCII(punycode)に置き換えたURLを返す。
     * ホスト名はparse_urlではなくRFC 3986付録Bの正規表現で取り出す。parse_urlはロケールによってUTF-8の
     * バイト(0x80〜0x9F)を制御文字とみなして_に置き換え、ホスト名を壊すため。
     *
     * @return string|null 変換できないホスト名ならnull
     */
    private static function asciiHost(string $url): ?string
    {
        if (preg_match('~\A[^:/?#]+://([^/?#]*)~', $url, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $url;
        }

        [$authority, $offset] = $matches[1];
        // userinfoは最後の@まで、ポートはホスト名の後の:から(角括弧のIPv6はASCIIなので変換の対象外)
        $at = strrpos($authority, '@');
        $hostStart = $at === false ? 0 : $at + 1;
        $colon = strpos($authority, ':', $hostStart);
        $host = substr($authority, $hostStart, $colon === false ? null : $colon - $hostStart);

        if (preg_match('/[^\x00-\x7F]/', $host) !== 1) {
            return $url;
        }

        // intlの定数は、コンストラクタでext-intlを確かめた後のここでだけ評価する(クラス定数にすると、intlの無い
        // 環境ではnew Url()の時点で未定義の定数のErrorになり、FormConfigExceptionに届かない)。
        // STD3の規則(ホスト名の_等を不合格にする)と、双方向文字・結合子の検査を有効にし、非移行処理(ß等を写像しない)で変換する
        $ascii = idn_to_ascii(
            $host,
            IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ | IDNA_NONTRANSITIONAL_TO_ASCII,
            INTL_IDNA_VARIANT_UTS46,
        );

        if ($ascii === false) {
            return null;
        }

        return substr_replace($url, $ascii, $offset + $hostStart, \strlen($host));
    }
}
