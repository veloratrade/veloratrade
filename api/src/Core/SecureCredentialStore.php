<?php

declare(strict_types=1);

namespace Velora\Core;

use RuntimeException;

/**
 * Secure admin-side credential management for the private velora.env file.
 *
 * Hard rules:
 *   - Writes ONLY to {VELORA_PRIVATE_ROOT}/config/velora.env (outside the web
 *     document root; Config::privateRoot() validates that fail-closed).
 *   - Only allowlisted credential KEYS (ProviderCatalog) may be touched.
 *   - flock-serialized read-modify-write, atomic rename, backup before
 *     replacement, 0600 permissions enforced and verified after write.
 *   - Fail-closed: any inconsistency throws — never a partial/silent write.
 *   - Secret VALUES never appear in exceptions, logs, or return values.
 *     The only public status signal is a boolean: configured or not.
 */
final class SecureCredentialStore
{
    private const LOCK_FILE = 'velora.env.lock';
    private const BACKUP_FILE = 'velora.env.bak';
    private const MAX_VALUE_BYTES = 4096;

    public static function envPath(): string
    {
        return Config::privateRoot() . '/config/velora.env';
    }

    /**
     * Allowlisted keys this store may manage (credential env key names only).
     *
     * @return string[]
     */
    public static function manageableKeys(): array
    {
        $keys = [];
        foreach (\Velora\AI\Services\ProviderCatalog::providerNames() as $name) {
            foreach (\Velora\AI\Services\ProviderCatalog::credentialKeys($name) as $key) {
                $keys[] = $key;
            }
            foreach (\Velora\AI\Services\ProviderCatalog::relayKeys($name) as $key) {
                $keys[] = $key;
            }
        }
        // Phase C: platform-level integration credentials (MetaAPI, Email).
        // These are the SAME global secrets the runtime consumers read from
        // env today; allowing them here means a Super Admin can set them from
        // the Admin Panel and they are stored encrypted (never plaintext).
        foreach (self::integrationSecretKeys() as $key) {
            $keys[] = $key;
        }
        return array_values(array_unique($keys));
    }

    /** @return string[] env key names allowed for integration credentials. */
    public static function integrationSecretKeys(): array
    {
        return [
            \Velora\Core\IntegrationConfigResolver::SECRET_METAAPI_TOKEN,
            \Velora\Core\IntegrationConfigResolver::SECRET_METAAPI_WEBHOOK,
            \Velora\Core\IntegrationConfigResolver::SECRET_RESEND_API_KEY,
            \Velora\Core\IntegrationConfigResolver::SECRET_SMTP_PASSWORD,
        ];
    }

    public static function isManageable(string $key): bool
    {
        return in_array($key, self::manageableKeys(), true);
    }

    // ------------------------------------------------------------------
    // Encrypted at-rest secret values (Phase A: n8n Gemini relay).
    //
    // The relay URL/token must be settable from the Admin Panel WITHOUT a file
    // edit. They are persisted encrypted at rest using the existing Crypto
    // helper (AES-256-GCM, key = APP_ENCRYPTION_KEY — already bootstrap-required
    // in production) in a dedicated file under VELORA_PRIVATE_ROOT — the same
    // private-root trust boundary as plaintext velora.env. This keeps the
    // values out of the DB and out of plaintext env files while remaining fully
    // application-managed and surviving a fresh deploy (the private root
    // persists).
    //
    // File: {VELORA_PRIVATE_ROOT}/config/velora-secrets.json
    // Shape: {"version":1,"<KEY>":"<base64 AES-GCM payload>",...}
    // Perms: 0600, verified after write. Values are NEVER returned over HTTP:
    // only read() (runtime resolver, not an HTTP surface) and secretStatus()
    // (boolean) exist.
    // ------------------------------------------------------------------
    private const SECRETS_FILE = 'config/velora-secrets.json';

    /** Relay config secret keys (Phase A). */
    public const SECRET_GEMINI_RELAY_URL = 'GEMINI_RELAY_URL';
    public const SECRET_GEMINI_RELAY_TOKEN = 'GEMINI_RELAY_TOKEN';

    public static function secretsFilePresent(): bool
    {
        $path = Config::privateRoot() . '/' . self::SECRETS_FILE;
        return is_file($path) && is_readable($path);
    }

    /** @return array<string,string> raw key => encrypted-b64 payload */
    private static function readSecrets(): array
    {
        $path = Config::privateRoot() . '/' . self::SECRETS_FILE;
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || (int) ($decoded['version'] ?? 0) !== 1) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k) && $k !== 'version' && is_string($v)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** Persist an encrypted secret value. Returns status. Throws on failure (no value in message). */
    public static function encryptWrite(string $key, string $value): bool
    {
        if (!self::isManageable($key)) {
            throw new RuntimeException('Credential key is not manageable by this store.');
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > self::MAX_VALUE_BYTES
            || preg_match('/[\r\n\0]/', $value) === 1) {
            throw new RuntimeException('Credential value rejected by format validation.');
        }
        $payload = \Velora\Core\Crypto::encrypt($value);
        self::mutateSecrets(static fn (array $s): array => [...$s, $key => $payload]);
        Config::clearCache();
        return true;
    }

    /** Remove an encrypted secret. Returns true if a value was removed. */
    public static function encryptDelete(string $key): bool
    {
        if (!self::isManageable($key)) {
            throw new RuntimeException('Credential key is not manageable by this store.');
        }
        if (!array_key_exists($key, self::readSecrets())) {
            return false;
        }
        self::mutateSecrets(static function (array $s) use ($key): array {
            unset($s[$key]);
            return $s;
        });
        Config::clearCache();
        return true;
    }

    /** Decrypt a persisted secret value. Returns '' when absent/unreadable/corrupt. */
    public static function read(string $key): string
    {
        $secrets = self::readSecrets();
        $payload = $secrets[$key] ?? null;
        if ($payload === null || $payload === '') {
            return '';
        }
        try {
            return \Velora\Core\Crypto::decrypt($payload);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Boolean presence of an encrypted secret value. */
    public static function secretStatus(string $key): bool
    {
        return self::read($key) !== '';
    }

    /** @param callable(array<string,string>): array<string,string> $mutation */
    private static function mutateSecrets(callable $mutation): void
    {
        $dir = Config::privateRoot() . '/config';
        if (!is_dir($dir)) {
            throw new RuntimeException('Private config directory is unavailable.');
        }
        $path = $dir . '/' . basename(self::SECRETS_FILE);
        $lockPath = $dir . '/velora-secrets.lock';

        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Secret store lock could not be opened.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Secret store lock could not be acquired.');
            }
            try {
                $secrets = self::readSecrets();
                $new = $mutation($secrets);
                ksort($new);
                $body = json_encode(['version' => 1] + $new, JSON_UNESCAPED_SLASHES) . "\n";
                if ($body === false) {
                    throw new RuntimeException('Secret store serialization failed.');
                }

                if (is_file($path)) {
                    $backup = $dir . '/velora-secrets.json.bak';
                    if (copy($path, $backup)) {
                        chmod($backup, 0600);
                    }
                }
                $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
                if (file_put_contents($tmp, $body, LOCK_EX) === false) {
                    @unlink($tmp);
                    throw new RuntimeException('Secret temp write failed.');
                }
                if (!chmod($tmp, 0600)) {
                    @unlink($tmp);
                    throw new RuntimeException('Secret temp permission setting failed.');
                }
                if (!rename($tmp, $path)) {
                    @unlink($tmp);
                    throw new RuntimeException('Secret atomic replace failed.');
                }
                $perms = fileperms($path);
                if ($perms === false || ($perms & 0o077) !== 0) {
                    chmod($path, 0600);
                    $perms = fileperms($path);
                    if ($perms === false || ($perms & 0o077) !== 0) {
                        throw new RuntimeException('Secret file permissions failed validation.');
                    }
                }
            } finally {
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    /**
     * Fresh presence check read directly from the env file (bypasses the
     * Config cache so admin status reflects the file right now).
     * Booleans only — never the value.
     */
    public static function status(string $key): bool
    {
        if (!self::isManageable($key)) {
            return false;
        }
        $lines = self::readLines(self::envPath());
        return self::findValue($lines, $key) !== null;
    }

    /**
     * Replace (or set) a credential. Backup is taken first; write is atomic.
     * Returns the new boolean status. Throws RuntimeException on any failure —
     * messages never contain the value.
     */
    public static function replace(string $key, string $value): bool
    {
        self::assertManageable($key);
        $value = trim($value);
        if ($value === '' || strlen($value) > self::MAX_VALUE_BYTES
            || preg_match('/[\r\n\0]/', $value) === 1 || str_contains($value, '"')) {
            throw new RuntimeException('Credential value rejected by format validation.');
        }

        return self::mutate(function (array $lines) use ($key, $value): array {
            $found = false;
            foreach ($lines as $i => $line) {
                if (self::lineKey($line) === $key) {
                    $lines[$i] = $key . '=' . $value;
                    $found = true;
                }
            }
            if (!$found) {
                $lines[] = $key . '=' . $value;
            }
            return $lines;
        });
    }

    /**
     * Delete a credential line. Returns the new boolean status (false).
     */
    public static function delete(string $key): bool
    {
        self::assertManageable($key);
        return self::mutate(function (array $lines) use ($key): array {
            return array_values(array_filter(
                $lines,
                fn (string $line): bool => self::lineKey($line) !== $key
            ));
        });
    }

    // ------------------------------------------------------------------
    // internals
    // ------------------------------------------------------------------

    /** @throws RuntimeException */
    private static function assertManageable(string $key): void
    {
        if (!self::isManageable($key)) {
            throw new RuntimeException('Credential key is not manageable by this store.');
        }
    }

    /**
     * Serialized read-modify-write: flock on a sibling lock file, backup,
     * atomic tmp-write + rename, permission verification, Config cache reset.
     *
     * @param callable(array<int,string>): array<int,string> $mutation
     */
    private static function mutate(callable $mutation): bool
    {
        $dir = Config::privateRoot() . '/config';
        if (!is_dir($dir)) {
            throw new RuntimeException('Private config directory is unavailable.');
        }
        $envPath = $dir . '/velora.env';
        $lockPath = $dir . '/' . self::LOCK_FILE;

        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Credential store lock could not be opened.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Credential store lock could not be acquired.');
            }
            try {
                $lines = self::readLines($envPath);

                // Backup before any mutation (single rotating backup, 0600).
                if (is_file($envPath)) {
                    $backup = $dir . '/' . self::BACKUP_FILE;
                    if (!copy($envPath, $backup)) {
                        throw new RuntimeException('Credential backup failed; refusing to write.');
                    }
                    chmod($backup, 0600);
                }

                $newLines = $mutation($lines);
                $body = $newLines === [] ? '' : implode("\n", $newLines) . "\n";

                // Atomic write: tmp file in the same directory, then rename.
                $tmp = $envPath . '.tmp.' . bin2hex(random_bytes(4));
                if (file_put_contents($tmp, $body, LOCK_EX) === false) {
                    @unlink($tmp);
                    throw new RuntimeException('Credential temp write failed.');
                }
                if (!chmod($tmp, 0600)) {
                    @unlink($tmp);
                    throw new RuntimeException('Credential temp permission setting failed.');
                }
                if (!rename($tmp, $envPath)) {
                    @unlink($tmp);
                    throw new RuntimeException('Credential atomic replace failed.');
                }

                // Post-write permission validation — fail closed on drift.
                $perms = fileperms($envPath);
                if ($perms === false || ($perms & 0o077) !== 0) {
                    chmod($envPath, 0600);
                    $perms = fileperms($envPath);
                    if ($perms === false || ($perms & 0o077) !== 0) {
                        throw new RuntimeException('Credential file permissions failed validation.');
                    }
                }

                // Refresh the process-wide env cache so runtime sees the change.
                Config::clearCache();

                return true;
            } finally {
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    /** @return array<int,string> */
    private static function readLines(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Credential store could not read the env file.');
        }
        return array_values(array_map('trim', $lines));
    }

    /** Key part of a KEY=VALUE line, or null for comments/blank lines. */
    private static function lineKey(string $line): ?string
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            return null;
        }
        $key = trim(substr($line, 0, strpos($line, '=')));
        return $key === '' ? null : $key;
    }

    /** @param array<int,string> $lines */
    private static function findValue(array $lines, string $key): ?string
    {
        foreach ($lines as $line) {
            if (self::lineKey($line) === $key) {
                $value = trim(substr($line, strpos($line, '=') + 1));
                return $value === '' ? null : $value;
            }
        }
        return null;
    }
}
