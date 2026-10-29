<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

/**
 * 確認画面の無いフォームの、使い捨ての送信トークン。formId・発行時刻・使い捨ての ID(jti)を署名する。
 *
 * `Form::open()` が埋め込み、Submit の時に `Form::handle()` が検証して jti を JtiStore で claim する。
 * 確認画面ありのフォームでは使わない(ConfirmToken の jti が送信トークンを兼ねる)。
 */
final class SubmitToken
{
    public const VERSION = 1;

    /** 有効期限の秒数。WordPress の nonce の最長の有効期間(1日)に合わせる */
    public const TTL = 86400;

    /** 鍵の派生に使う用途の名前(TokenSigner) */
    private const PURPOSE = 'submit-token';

    private function __construct() {}

    /**
     * @param ?string $secret マスターの秘密。null なら wp_salt('auth')
     * @param ?int $now null なら time()
     */
    public static function issue(string $formId, ?string $secret = null, ?int $now = null): string
    {
        return TokenSigner::sign(self::PURPOSE, [
            'ver' => self::VERSION,
            'f' => $formId,
            'j' => bin2hex(random_bytes(16)),
            't' => $now ?? time(),
        ], $secret);
    }

    public static function verify(string $formId, string $token, ?string $secret = null, ?int $now = null): SubmitTokenResult
    {
        $payload = TokenSigner::verify(self::PURPOSE, $token, $secret);

        if ($payload === null || ($payload['ver'] ?? null) !== self::VERSION || ($payload['f'] ?? null) !== $formId) {
            return new SubmitTokenResult(TokenStatus::Invalid);
        }

        $jti = $payload['j'] ?? null;
        $issuedAt = $payload['t'] ?? null;

        if (!\is_string($jti) || preg_match('/\A[a-f0-9]{32}\z/', $jti) !== 1 || !\is_int($issuedAt)) {
            return new SubmitTokenResult(TokenStatus::Invalid);
        }

        $status = ($issuedAt + self::TTL) < ($now ?? time()) ? TokenStatus::Expired : TokenStatus::Valid;

        return new SubmitTokenResult($status, $jti, $issuedAt);
    }
}
