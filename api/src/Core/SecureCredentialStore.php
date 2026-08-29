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
        return array_values(array_unique($keys));
    }

    public static function isManageable(string $key): bool
    {
        return in_array($key, self::manageableKeys(), true);
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
