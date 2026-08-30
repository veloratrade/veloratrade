<?php

declare(strict_types=1);

namespace Velora\Core;

use RuntimeException;

/**
 * Configuration loader.
 *
 * Production secrets and runtime state live below VELORA_PRIVATE_ROOT, outside
 * the document root. Real process variables take precedence over velora.env.
 * A legacy in-tree .env is accepted only when APP_ENV is explicitly set to a
 * development/test value in the process environment.
 */
final class Config
{
    private static ?array $data = null;
    private static array $envVars = [];
    private static ?string $privateRoot = null;

    public static function load(): array
    {
        if (self::$data === null) {
            self::loadDotEnv(self::findEnvFile());
            $file = dirname(__DIR__, 2) . '/config/config.php';
            self::$data = require $file;
        }
        return self::$data;
    }

    /** Read a value with process environment -> private velora.env -> default priority. */
    public static function env(string $key, string $default = ''): string
    {
        if (self::$data === null && self::$envVars === []) {
            self::loadDotEnv(self::findEnvFile());
        }

        $real = getenv($key);
        if ($real !== false && $real !== '') {
            return $real;
        }
        if (isset(self::$envVars[$key]) && self::$envVars[$key] !== '') {
            return self::$envVars[$key];
        }
        return $default;
    }

    /**
     * Drop cached env/config so subsequent env() calls re-read the private
     * velora.env (used by SecureCredentialStore after admin credential
     * mutations). Process environment still wins — by design.
     */
    public static function clearCache(): void
    {
        self::$envVars = [];
        self::$data = null;
    }

    /**
     * Return the validated private runtime root.
     *
     * The root must already exist, be absolute, and resolve outside the web
     * document root. This intentionally fails closed before secrets are read.
     */
    public static function privateRoot(): string
    {
        if (self::$privateRoot !== null) {
            return self::$privateRoot;
        }

        $configured = trim((string) (getenv('VELORA_PRIVATE_ROOT') ?: ''));
        if ($configured === '') {
            if (self::processEnvironmentIsDevelopment()) {
                // Local-only compatibility. Production can never select this path.
                $legacy = dirname(__DIR__, 2);
                self::$privateRoot = self::canonicalExistingDirectory($legacy, 'legacy development runtime root');
                return self::$privateRoot;
            }
            throw new RuntimeException('VELORA_PRIVATE_ROOT must be configured outside the document root.');
        }
        if (!self::isAbsolutePath($configured)) {
            throw new RuntimeException('VELORA_PRIVATE_ROOT must be an absolute path.');
        }

        $root = self::canonicalExistingDirectory($configured, 'VELORA_PRIVATE_ROOT');
        $documentRoot = self::documentRoot();
        self::assertOutsideDocumentRoot($root, $documentRoot, 'VELORA_PRIVATE_ROOT');
        self::$privateRoot = $root;
        return $root;
    }

    /** Resolve a private runtime path and reject traversal or web-root exposure. */
    public static function privatePath(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', trim($relativePath));
        if ($relativePath === '' || str_starts_with($relativePath, '/') || preg_match('~(^|/)\.\.(/|$)~', $relativePath)) {
            throw new RuntimeException('Invalid private runtime relative path.');
        }

        $root = self::privateRoot();
        $path = $root . '/' . ltrim($relativePath, '/');
        $resolved = realpath($path);
        if ($resolved !== false) {
            self::assertOutsideDocumentRoot($resolved, self::documentRoot(), 'private runtime path');
            if (!self::pathIsInside($resolved, $root)) {
                throw new RuntimeException('Private runtime path escapes VELORA_PRIVATE_ROOT.');
            }
            return $resolved;
        }

        // The validated root is outside the document root. A non-existent child
        // is safe provided no existing parent symlink escapes that root.
        $parent = dirname($path);
        while ($parent !== $root && !file_exists($parent)) {
            $parent = dirname($parent);
        }
        $resolvedParent = realpath($parent);
        if ($resolvedParent === false || !self::pathIsInside($resolvedParent, $root)) {
            throw new RuntimeException('Private runtime path parent escapes VELORA_PRIVATE_ROOT.');
        }
        return $path;
    }

    private static function findEnvFile(): string
    {
        if (self::processEnvironmentIsDevelopment() && trim((string) (getenv('VELORA_PRIVATE_ROOT') ?: '')) === '') {
            return dirname(__DIR__, 2) . '/.env';
        }

        $path = self::privatePath('config/velora.env');
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Private configuration file is missing or unreadable.');
        }
        return $path;
    }

    private static function loadDotEnv(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException('Configuration file could not be read.');
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (strlen($value) >= 2 &&
                (($value[0] === '"' && str_ends_with($value, '"')) ||
                 ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            if ($key !== '') {
                self::$envVars[$key] = $value;
            }
        }
    }

    private static function documentRoot(): string
    {
        $configured = trim((string) (getenv('VELORA_DOCUMENT_ROOT') ?: ($_SERVER['DOCUMENT_ROOT'] ?? '')));
        if ($configured === '') {
            // CLI/cron fallback: this repository root is the deployable web root.
            $configured = dirname(__DIR__, 3);
        }
        return self::canonicalExistingDirectory($configured, 'document root');
    }

    private static function canonicalExistingDirectory(string $path, string $label): string
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException($label . ' must be an existing directory.');
        }
        return rtrim(str_replace('\\', '/', $resolved), '/');
    }

    private static function assertOutsideDocumentRoot(string $path, string $documentRoot, string $label): void
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        if (self::pathIsInside($normalized, $documentRoot)) {
            throw new RuntimeException($label . ' resolves inside the document root.');
        }
    }

    private static function pathIsInside(string $path, string $parent): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $parent = rtrim(str_replace('\\', '/', $parent), '/');
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }

    private static function processEnvironmentIsDevelopment(): bool
    {
        $environment = strtolower(trim((string) (getenv('APP_ENV') ?: '')));
        return in_array($environment, ['dev', 'development', 'local', 'test', 'testing'], true);
    }

    public static function isDevelopmentEnvironment(): bool
    {
        $environment = strtolower(self::env('APP_ENV', 'production'));
        return in_array($environment, ['dev', 'development', 'local', 'test', 'testing'], true);
    }

    public static function isProductionEnvironment(): bool
    {
        return !self::isDevelopmentEnvironment();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::load();
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
