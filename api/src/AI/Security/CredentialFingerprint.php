<?php

declare(strict_types=1);

namespace Velora\AI\Security;

use Velora\Core\Config;

/**
 * Non-reversible credential fingerprint.
 *
 * HMAC-SHA256(key = APP_ENCRYPTION_KEY, data = credential value). The result is
 * a short, deterministic identifier that lets the system recognise a key the
 * operator has seen before WITHOUT storing or leaking the secret, and it is
 * not reversible without the encryption key. It is persisted as metadata only
 * and is never returned to the client.
 */
final class CredentialFingerprint
{
    public static function of(string $value, ?string $key = null): string
    {
        if (trim($value) === '') {
            return '';
        }
        $macKey = $key ?? Config::env('APP_ENCRYPTION_KEY', '');
        if ($macKey === '') {
            // Fail closed: without a mac key a fingerprint would be trivially
            // brute-forceable. Prefer an empty fingerprint over an insecure one.
            return '';
        }
        return 'hmac:' . substr(hash_hmac('sha256', $value, $macKey), 0, 32);
    }
}
