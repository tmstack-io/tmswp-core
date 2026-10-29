<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Unit\Form;

use Brain\Monkey\Functions;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Form\ConfirmToken;
use TMSWP\Core\Form\SubmitToken;
use TMSWP\Core\Form\TokenSigner;
use TMSWP\Core\Form\TokenStatus;
use TMSWP\Testing\UnitTestCase;

/**
 * ConfirmToken・SubmitTokenの署名・検証・期限切れと、それらが使うTokenSigner(docs/05 §ConfirmToken・§SubmitToken。
 * docs/10 §8.2のConfirmToken)。
 */
final class ConfirmTokenTest extends UnitTestCase
{
    private const string JTI = '0123456789abcdef0123456789abcdef';

    public function testCanonicalJsonIsStableRegardlessOfInputOrder(): void
    {
        $left = TokenSigner::canonicalJson([
            'v' => ['name' => 'Taro', 'topics' => ['b', 'a']],
            'f' => 'contact',
            'ver' => 1,
        ]);
        $right = TokenSigner::canonicalJson([
            'ver' => 1,
            'f' => 'contact',
            'v' => ['topics' => ['b', 'a'], 'name' => 'Taro'],
        ]);

        self::assertSame($left, $right);
    }

    public function testIssueAndVerifyRoundTrip(): void
    {
        $token = ConfirmToken::issue('contact', [
            'name' => 'Taro',
            'topics' => ['news', 'docs'],
            'file' => ['__tmsfile' => '0123456789abcdef0123456789abcdef'],
        ], 'secret', 1000);

        $result = ConfirmToken::verify('contact', $token, 1800, 'secret', 1200);

        self::assertSame(TokenStatus::Valid, $result->status);
        self::assertSame('Taro', $result->values['name']);
        self::assertSame(['news', 'docs'], $result->values['topics']);
        self::assertSame(['__tmsfile' => '0123456789abcdef0123456789abcdef'], $result->values['file']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $result->jti);
        self::assertSame(1000, $result->issuedAt);
    }

    /**
     * 署名の改ざん・切り詰め・本文の改ざん・フォームの違い・秘密の違いは、どれも無効。
     *
     * @return iterable<string, array{Closure(string, string, string): array{string, string, string}}>
     */
    public static function tamperedTokens(): iterable
    {
        yield 'replaced signature' => [static fn(string $token, string $body, string $signature): array => ['contact', $body . '.deadbeef', 'secret']];
        yield 'truncated signature' => [static fn(string $token, string $body, string $signature): array => ['contact', $body . '.' . substr($signature, 0, 20), 'secret']];
        yield 'tampered body' => [
            static fn(string $token, string $body, string $signature): array => ['contact', self::base64Url(str_replace('Taro', 'Jiro', self::decodeBody($body))) . '.' . $signature, 'secret'],
        ];
        yield 'other form' => [static fn(string $token, string $body, string $signature): array => ['other', $token, 'secret']];
        yield 'other secret' => [static fn(string $token, string $body, string $signature): array => ['contact', $token, 'another secret']];
    }

    /** @param Closure(string, string, string): array{string, string, string} $tamper 発行したトークン・本文・署名から、検証するフォーム・トークン・秘密を作る */
    #[DataProvider('tamperedTokens')]
    public function testVerifyRejectsTamperingTruncationAndFormMismatch(Closure $tamper): void
    {
        $token = ConfirmToken::issue('contact', ['name' => 'Taro'], 'secret', 1000);
        [$body, $signature] = explode('.', $token, 2);
        [$formId, $tampered, $secret] = $tamper($token, $body, $signature);

        self::assertSame(TokenStatus::Invalid, ConfirmToken::verify($formId, $tampered, secret: $secret)->status);
    }

    /**
     * 発行の時刻に有効期間を足した時刻までは有効で、それを過ぎると期限切れ。期限切れでも署名した値は返す(確認画面から
     * 戻るため)。
     *
     * @return iterable<string, array{int, TokenStatus}>
     */
    public static function verificationTimes(): iterable
    {
        yield 'the last valid second' => [1010, TokenStatus::Valid];
        yield 'one second after the expiry' => [1011, TokenStatus::Expired];
    }

    #[DataProvider('verificationTimes')]
    public function testExpiredTokenKeepsSignedValuesForBackNavigation(int $now, TokenStatus $status): void
    {
        $token = ConfirmToken::issue('contact', ['name' => 'Taro'], 'secret', 1000);
        $result = ConfirmToken::verify('contact', $token, 10, 'secret', $now);

        self::assertSame($status, $result->status);
        self::assertSame(['name' => 'Taro'], $result->values);
    }

    /**
     * 正しく署名しても、値の形(リストの要素が文字列でない)やjtiの形が違うペイロードは無効。同じ形の正しいペイロードは
     * 受け付ける(拒否が署名ではなく内容によることの対照)。
     *
     * @return iterable<string, array{array<string, mixed>, TokenStatus}>
     */
    public static function signedPayloads(): iterable
    {
        yield 'non-string element in a list' => [['ver' => ConfirmToken::VERSION, 'f' => 'contact', 'j' => self::JTI, 't' => 1000, 'v' => ['topics' => ['news', 123]]], TokenStatus::Invalid];
        yield 'jti not of 32 hex digits' => [['ver' => ConfirmToken::VERSION, 'f' => 'contact', 'j' => '../not-a-jti', 't' => 1000, 'v' => []], TokenStatus::Invalid];
        yield 'valid payload of the same shape' => [['ver' => ConfirmToken::VERSION, 'f' => 'contact', 'j' => self::JTI, 't' => 1000, 'v' => ['topics' => ['news']]], TokenStatus::Valid];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('signedPayloads')]
    public function testVerifyRejectsInvalidPayloadValueShapeAndJti(array $payload, TokenStatus $status): void
    {
        self::assertSame($status, ConfirmToken::verify('contact', $this->sign($payload, 'confirm-token'), 1800, 'secret', 1000)->status);
    }

    public function testSignatureUsesAKeyDerivedPerPurposeInsteadOfTheRawSecret(): void
    {
        $payload = ['ver' => ConfirmToken::VERSION, 'f' => 'contact', 'j' => self::JTI, 't' => 1000, 'v' => []];
        $body = self::base64Url(TokenSigner::canonicalJson($payload));

        // wp_salt('auth') をそのまま鍵にした署名は受け付けない
        self::assertSame(TokenStatus::Invalid, ConfirmToken::verify('contact', $body . '.' . hash_hmac('sha256', $body, 'secret'), 1800, 'secret', 1000)->status);
        // HKDF-SHA256(secret, 'tmswp/form/confirm-token') で派生させた鍵の署名を受け付ける
        $derivedKey = hash_hkdf('sha256', 'secret', 32, 'tmswp/form/confirm-token');
        self::assertSame(TokenStatus::Valid, ConfirmToken::verify('contact', $body . '.' . hash_hmac('sha256', $body, $derivedKey), 1800, 'secret', 1000)->status);
    }

    public function testDefaultSecretIsTheAuthSaltOfWordPress(): void
    {
        Functions\expect('wp_salt')->with('auth')->andReturn('salt from wordpress');

        $token = ConfirmToken::issue('contact', ['name' => 'Taro'], now: 1000);

        self::assertSame(TokenStatus::Valid, ConfirmToken::verify('contact', $token, 1800, 'salt from wordpress', 1000)->status);
    }

    public function testSubmitTokenRoundTripsItsJtiAndIssuedTime(): void
    {
        $token = SubmitToken::issue('contact', 'secret', 1000);
        $result = SubmitToken::verify('contact', $token, 'secret', 1000);

        self::assertSame(TokenStatus::Valid, $result->status);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $result->jti);
        self::assertSame(1000, $result->issuedAt);
        self::assertNotSame($result->jti, SubmitToken::verify('contact', SubmitToken::issue('contact', 'secret', 1000), 'secret', 1000)->jti);
    }

    /**
     * 送信トークンはTTLの秒数まで有効で、それを過ぎると期限切れ。フォームの違い・空のトークンは無効。
     *
     * @return iterable<string, array{string, bool, int, TokenStatus}>
     */
    public static function submitTokenVerifications(): iterable
    {
        yield 'the last valid second' => ['contact', true, 1000 + SubmitToken::TTL, TokenStatus::Valid];
        yield 'one second after the expiry' => ['contact', true, 1001 + SubmitToken::TTL, TokenStatus::Expired];
        yield 'other form' => ['other', true, 1000, TokenStatus::Invalid];
        yield 'empty token' => ['contact', false, 1000, TokenStatus::Invalid];
    }

    #[DataProvider('submitTokenVerifications')]
    public function testSubmitTokenExpiryAndRejection(string $formId, bool $issued, int $now, TokenStatus $status): void
    {
        $token = $issued ? SubmitToken::issue('contact', 'secret', 1000) : '';

        self::assertSame($status, SubmitToken::verify($formId, $token, 'secret', $now)->status);
    }

    public function testTokensOfOnePurposeAreNotAcceptedAsTheOther(): void
    {
        $confirm = ConfirmToken::issue('contact', [], 'secret', 1000);
        $submit = SubmitToken::issue('contact', 'secret', 1000);

        self::assertSame(TokenStatus::Invalid, SubmitToken::verify('contact', $confirm, 'secret', 1000)->status);
        self::assertSame(TokenStatus::Invalid, ConfirmToken::verify('contact', $submit, 1800, 'secret', 1000)->status);
    }

    /** @param array<array-key, mixed> $payload */
    private function sign(array $payload, string $purpose): string
    {
        $body = self::base64Url(TokenSigner::canonicalJson($payload));

        return $body . '.' . hash_hmac('sha256', $body, hash_hkdf('sha256', 'secret', 32, 'tmswp/form/' . $purpose));
    }

    private static function base64Url(string $json): string
    {
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private static function decodeBody(string $body): string
    {
        $decoded = base64_decode(strtr($body, '-_', '+/'), true);
        self::assertIsString($decoded);

        return $decoded;
    }
}
