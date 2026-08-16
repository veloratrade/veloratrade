<?php

declare(strict_types=1);

namespace Velora\Core;

/**
 * Field-level AES-256-GCM encryption for broker secrets (roadmap v0.2 spec,
 * foundation built now). Format of stored value:
 *   base64(nonce . ciphertext . tag)
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_LEN = 12;
    private const TAG_LEN = 16;

    private static ?string $key = null;

    private static function key(): string
    {
        if (self::$key === null) {
            $b64 = (string) Config::get('encryption_key_b64');
            $key = base64_decode($b64, true);
            if ($key === false || strlen($key) !== 32) {
                throw new \RuntimeException('APP_ENCRYPTION_KEY must be base64 of exactly 32 bytes.');
            }
            self::$key = $key;
        }
        return self::$key;
    }

    public static function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_LEN);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return base64_encode($nonce . $ciphertext . $tag);
    }

    public static function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < self::NONCE_LEN + self::TAG_LEN) {
            throw new \RuntimeException('Invalid encrypted payload.');
        }
        $nonce = substr($raw, 0, self::NONCE_LEN);
        $tag = substr($raw, -self::TAG_LEN);
        $ciphertext = substr($raw, self::NONCE_LEN, -self::TAG_LEN);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed (tampered data or wrong key).');
        }
        return $plaintext;
    }
}
