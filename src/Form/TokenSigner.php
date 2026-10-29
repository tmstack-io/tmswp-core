<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use JsonException;
use TMSWP\Core\Exception\Form\FormConfigException;

/**
 * フォームの署名付きトークンの署名と検証。トークンは `base64url(正準JSON) . '.' . HMAC-SHA256(16進数)`。
 *
 * HMAC の鍵は、マスターの秘密(既定は wp_salt('auth'))をそのまま使わず、用途ごとに HKDF-SHA256 で派生させる。
 * auth Cookie と同じ鍵を使わないため、また、ある用途のトークン(送信トークン)を別の用途(確認トークン)として
 * 受け付けないためである。
 *
 * @internal ConfirmToken と SubmitToken だけが使う。
 */
final class TokenSigner
{
    private function __construct() {}

    /**
     * @param string $purpose 鍵の派生に使う用途の名前
     * @param array<array-key, mixed> $payload
     * @param ?string $secret マスターの秘密。null なら wp_salt('auth')
     */
    public static function sign(string $purpose, array $payload, ?string $secret): string
    {
        $body = self::base64UrlEncode(self::canonicalJson($payload));

        return $body . '.' . hash_hmac('sha256', $body, self::key($purpose, $secret));
    }

    /**
     * 署名を検証し、ペイロードを返す。署名の比較は hash_equals() で行う。
     *
     * @param ?string $secret マスターの秘密。null なら wp_salt('auth')
     *
     * @return null|array<array-key, mixed> 形式の不正・署名の不一致・JSON の不正なら null
     */
    public static function verify(string $purpose, string $token, ?string $secret): ?array
    {
        $parts = explode('.', $token, 2);

        if (\count($parts) !== 2 || preg_match('/\A[A-Za-z0-9_-]+\z/', $parts[0]) !== 1 || preg_match('/\A[a-f0-9]{64}\z/', $parts[1]) !== 1) {
            return null;
        }

        [$body, $sig] = $parts;

        if (!hash_equals(hash_hmac('sha256', $body, self::key($purpose, $secret)), $sig)) {
            return null;
        }

        $json = self::base64UrlDecode($body);

        if ($json === null) {
            return null;
        }

        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return \is_array($payload) ? $payload : null;
    }

    /**
     * 決定的な JSON 化。連想配列は再帰的にキーの文字列順に並べ、リストは順序を保つ。
     *
     * @param array<array-key, mixed> $payload
     */
    public static function canonicalJson(array $payload): string
    {
        return json_encode(self::sortRecursive($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function key(string $purpose, ?string $secret): string
    {
        return hash_hkdf('sha256', self::secret($secret), 32, 'tmswp/form/' . $purpose);
    }

    private static function secret(?string $secret): string
    {
        if ($secret !== null) {
            return $secret;
        }

        if (!\function_exists('wp_salt')) {
            throw new FormConfigException('form tokens require a secret outside WordPress');
        }

        return wp_salt('auth');
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function sortRecursive(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(static fn(mixed $item): mixed => \is_array($item) ? self::sortRecursive($item) : $item, $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = self::sortRecursive($item);
            }
        }

        return $value;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $base64 = strtr($value, '-_', '+/');
        $padding = \strlen($base64) % 4;

        if ($padding !== 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($base64, true);

        return \is_string($decoded) ? $decoded : null;
    }
}
