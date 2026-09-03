<?php

declare(strict_types=1);

namespace Velora\Admin;

use Velora\Core\Config;
use Velora\Core\SecureCredentialStore;

/**
 * Phase A — Runtime relay configuration resolver.
 *
 * This is the SINGLE place the runtime reads relay URL/token from, and the
 * reason "save from the Admin Panel" actually takes effect without a file edit.
 *
 * Precedence (highest wins) — verified against the existing architecture:
 *   1. Admin-managed encrypted secret  (SecureCredentialStore encrypted store,
 *      written by the Admin Panel relay endpoint, survives a fresh deploy via
 *      {VELORA_PRIVATE_ROOT}/config/velora-secrets.json, 0600, AES-256-GCM).
 *   2. Process ENV                     (real `getenv`, infra override).
 *   3. velora.env private file         (existing plaintext fallback for
 *      backward compatibility with already-deployed hosts).
 *   4. Unavailable                     (empty string; the relay reports not
 *      configured and Velora behaves exactly as today when unset).
 *
 * Process ENV sits ABOVE velora.env (not below the encrypted store) because
 * that mirrors the existing Config::env() tenet that "real process variables
 * take precedence over velora.env". The encrypted store is above BOTH only
 * because it is the application-managed value intentionally set from the Admin
 * Panel; an operator may still override it by exporting the env var.
 *
 * All values are consumed internally. No method here returns a secret over an
 * HTTP surface.
 */
final class RelayConfigResolver
{
    /** Effective relay URL ('' when not configured). */
    public static function url(): string
    {
        return self::resolve(SecureCredentialStore::SECRET_GEMINI_RELAY_URL);
    }

    /** Effective relay token ('' when not configured). Never exposed over HTTP. */
    public static function token(): string
    {
        return self::resolve(SecureCredentialStore::SECRET_GEMINI_RELAY_TOKEN);
    }

    public static function isConfigured(): bool
    {
        $url = self::url();
        return $url !== '' && str_starts_with($url, 'https://') && self::token() !== '';
    }

    public static function hasUrl(): bool
    {
        return self::url() !== '';
    }

    public static function hasToken(): bool
    {
        return self::token() !== '';
    }

    /** Safe public status: booleans + a host-only URL representation, never the token. */
    public static function safeStatus(): array
    {
        return [
            'configured' => self::isConfigured(),
            'urlConfigured' => self::hasUrl(),
            'tokenConfigured' => self::hasToken(),
            'urlHost' => self::safeUrlHost(),
        ];
    }

    /** Host-only string for display (never path/query, which may embed secrets). */
    public static function safeUrlHost(): string
    {
        $url = self::url();
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return '';
        }
        return (string) $parts['host'];
    }

    /**
     * Encrypted store > process ENV > private velora.env > ''.
     */
    private static function resolve(string $key): string
    {
        $managed = SecureCredentialStore::read($key);
        if ($managed !== '') {
            return $managed;
        }

        // Process ENV (real operating-environment variable, infra override).
        $env = getenv($key);
        if ($env !== false && trim((string) $env) !== '') {
            return trim((string) $env);
        }

        // Private velora.env (existing plaintext fallback).
        $file = Config::env($key, '');
        return trim($file);
    }
}
