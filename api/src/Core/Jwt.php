<?php

declare(strict_types=1);

namespace Velora\Core;

/**
 * Minimal dependency-free HS256 JWT implementation.
 *
 * Access tokens are stateless and short-lived; refresh tokens are opaque
 * random strings hashed in DB (dual-token strategy from the roadmap).
 */
final class Jwt
{
    /**
     * @param array<string,mixed> $claims
     */
    public static function encode(array $claims, int $ttlSeconds, string $secret): string
    {
        $now = time();
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
            'jti' => bin2hex(random_bytes(16)),
        ]);

        $segments = [
            self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);

        return $signingInput . '.' . self::base64UrlEncode($signature);
    }

    /**
     * Decode and verify. Returns claims array or null when invalid/expired.
     *
     * @return array<string,mixed>|null
     */
    public static function decode(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headB64, $payloadB64, $sigB64] = $parts;

        $signingInput = $headB64 . '.' . $payloadB64;
        $expected = hash_hmac('sha256', $signingInput, $secret, true);

        if (!hash_equals($expected, self::base64UrlDecode($sigB64))) {
            return null; // signature mismatch
        }

        $payload = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!is_array($payload)) {
            return null;
        }

        $now = time();
        if (isset($payload['exp']) && (int) $payload['exp'] < $now) {
            return null; // expired
        }
        if (isset($payload['nbf']) && (int) $payload['nbf'] > $now) {
            return null; // not yet valid
        }

        return $payload;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'), true) ?: '';
    }
}
