<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

/**
 * 確認画面の状態を運ぶ署名付きトークン。検証済みの正準値・formId・発行時刻・使い捨ての ID(jti)を署名する。
 *
 * 確認画面ありのフォームでは、この jti が送信トークンを兼ねる(Submit の時に JtiStore で claim する)。
 */
final class ConfirmToken
{
    public const VERSION = 1;

    /** 鍵の派生に使う用途の名前(TokenSigner) */
    private const PURPOSE = 'confirm-token';

    private function __construct() {}

    /**
     * @param array<string, string|list<string>|array{__tmsfile: string}> $values 正準値
     * @param ?string $secret マスターの秘密。null なら wp_salt('auth')
     * @param ?int $now null なら time()
     */
    public static function issue(string $formId, array $values, ?string $secret = null, ?int $now = null): string
    {
        return TokenSigner::sign(self::PURPOSE, [
            'ver' => self::VERSION,
            'f' => $formId,
            'j' => bin2hex(random_bytes(16)),
            't' => $now ?? time(),
            'v' => $values,
        ], $secret);
    }

    public static function verify(string $formId, string $token, int $ttl = 1800, ?string $secret = null, ?int $now = null): ConfirmTokenResult
    {
        $payload = TokenSigner::verify(self::PURPOSE, $token, $secret);

        if ($payload === null || ($payload['ver'] ?? null) !== self::VERSION || ($payload['f'] ?? null) !== $formId) {
            return new ConfirmTokenResult(TokenStatus::Invalid);
        }

        $jti = $payload['j'] ?? null;
        $issuedAt = $payload['t'] ?? null;

        if (!\is_string($jti) || preg_match('/\A[a-f0-9]{32}\z/', $jti) !== 1 || !\is_int($issuedAt) || !\is_array($payload['v'] ?? null)) {
            return new ConfirmTokenResult(TokenStatus::Invalid);
        }

        $values = self::values($payload['v']);

        if ($values === null) {
            return new ConfirmTokenResult(TokenStatus::Invalid);
        }

        $status = ($issuedAt + $ttl) < ($now ?? time()) ? TokenStatus::Expired : TokenStatus::Valid;

        return new ConfirmTokenResult($status, $values, $jti, $issuedAt);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return null|array<string, string|list<string>|array{__tmsfile: string}>
     */
    private static function values(array $values): ?array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            if (!\is_string($key)) {
                return null;
            }

            if (\is_string($value)) {
                $normalized[$key] = $value;
                continue;
            }

            if (\is_array($value) && array_is_list($value)) {
                $items = [];

                foreach ($value as $item) {
                    if (!\is_string($item)) {
                        return null;
                    }

                    $items[] = $item;
                }

                $normalized[$key] = $items;
                continue;
            }

            if (\is_array($value) && \array_key_exists('__tmsfile', $value) && \is_string($value['__tmsfile'])) {
                $normalized[$key] = ['__tmsfile' => $value['__tmsfile']];
                continue;
            }

            return null;
        }

        return $normalized;
    }
}
