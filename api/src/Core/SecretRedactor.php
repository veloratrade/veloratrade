<?php

declare(strict_types=1);

namespace Velora\Core;

/**
 * Central secret redaction for anything rendered back to an administrator
 * (system logs, diagnostics, error strings).
 *
 * Single source of truth for "what can never be shown". It is intentionally
 * conservative: it strips/aliases credential-shaped values and replaces known
 * secret env keys with a marker. It never recovers a secret; it only removes
 * or masks it.
 */
final class SecretRedactor
{
    /** Known secret environment/credential key names (values must be masked). */
    private const SECRET_KEYS = [
        'password', 'pass', 'pwd', 'secret', 'token', 'access_token', 'refresh_token',
        'api_key', 'apikey', 'api-key', 'client_secret', 'jwt', 'bearer', 'private_key',
        'encryption_key', 'relay_token', 'broker_password', 'connection_credentials',
        'webhook_secret', 'smtp_password', 'resend_api_key', 'authorization',
        'authorization_header', 'session', 'cookie', 'db_password', 'db_username',
    ];

    /** Known redaction marker. Stable so tests can assert it (never a real value). */
    public const MARKER = '[REDACTED]';

    /**
     * Redact a human-readable string: mask quoted key:value pairs, bare
     * key=value pairs, and literal Bearer tokens. Never throws.
     */
    public static function text(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        // Bearer / token-shaped values.
        $text = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer ' . self::MARKER, $text) ?? $text;
        // key=value and "key":"value" where key is secret-shaped.
        $text = preg_replace_callback(
            '/(["\']?)([A-Za-z0-9_\-\.]{3,})\1(\s*[:=]\s*)(["\']?)([^"\',;\s}]{4,})/',
            static function (array $m): string {
                $key = strtolower($m[2]);
                foreach (self::SECRET_KEYS as $bad) {
                    if (str_contains($key, $bad)) {
                        return $m[1] . $m[2] . $m[1] . $m[3] . $m[4] . self::MARKER . $m[4];
                    }
                }
                return $m[0];
            },
            $text
        ) ?? $text;
        // Mask long token-ish runes standing alone (>=24 chars, no spaces).
        $text = preg_replace('/\b[A-Za-z0-9_-]{24,}\b/', self::MARKER, $text) ?? $text;
        return $text;
    }

    /**
     * Redact a metadata map: drop secret-shaped keys entirely and redact the
     * values of remaining keys. Returns a copy; never mutates input.
     *
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public static function metadata(array $metadata): array
    {
        $out = [];
        foreach ($metadata as $key => $value) {
            $lk = strtolower((string) $key);
            if (self::isSecretKey($lk)) {
                $out[$key] = self::MARKER;
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::metadata($value);
            } elseif (is_scalar($value)) {
                $out[$key] = self::text((string) $value);
            } else {
                $out[$key] = null;
            }
        }
        return $out;
    }

    private static function isSecretKey(string $key): bool
    {
        foreach (self::SECRET_KEYS as $bad) {
            if ($key === $bad || str_contains($key, $bad)) {
                return true;
            }
        }
        return false;
    }
}
