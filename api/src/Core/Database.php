<?php

declare(strict_types=1);

namespace Velora\Core;

use PDO;
use PDOException;
use Velora\Core\Exceptions\ServiceUnavailableException;

/**
 * PDO connection manager (singleton).
 * All queries must use prepared statements (roadmap v0.1 security spec).
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** Number of attempts to establish the initial MySQL connection. */
    private const CONNECT_MAX_ATTEMPTS = 3;

    /** Base backoff between connection attempts, in microseconds (100ms). */
    private const CONNECT_BACKOFF_US = 100000;

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $config = Config::get('db', []);
        $driver = $config['driver'] ?? 'mysql';

        if ($driver === 'sqlite') {
            $dbPath = (string) ($config['database'] ?? Config::privatePath('data/velora.sqlite'));
            if ($dbPath === '' || !str_starts_with(str_replace('\\', '/', $dbPath), Config::privateRoot() . '/')) {
                throw new PDOException('SQLite database must resolve below VELORA_PRIVATE_ROOT/data.');
            }
            $expectedDataRoot = Config::privatePath('data');
            if (!str_starts_with(str_replace('\\', '/', $dbPath), rtrim($expectedDataRoot, '/') . '/')) {
                throw new PDOException('SQLite database must resolve below VELORA_PRIVATE_ROOT/data.');
            }
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                // Runtime database material must never be created world-readable
                // on shared hosting.
                @mkdir($dir, 0700, true);
            }
            // If the directory still doesn't exist and can't be created, let PDO throw the error.
            if (!is_dir($dir)) {
                throw new PDOException('SQLite storage directory is not writable: ' . $dir);
            }
            @chmod($dir, 0700);
            try {
                self::$pdo = new PDO('sqlite:' . $dbPath, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                @chmod($dbPath, 0600);
                self::$pdo->exec('PRAGMA foreign_keys = ON;');
            } catch (PDOException $e) {
                throw new PDOException('SQLite connection failed: ' . $e->getMessage(), (int) $e->getCode());
            }
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['name'],
            $config['charset']
        );

        // Establishing the connection (not running queries) can fail transiently
        // on shared hosting — e.g. "Too many connections" or "server has gone
        // away" during load spikes. Retry the CONNECT step only, with a short
        // linear backoff. Query execution is never retried here (it happens
        // outside this method), so this cannot re-run a mutating statement or
        // leak connections: a successful attempt returns immediately and only
        // one PDO handle is ever assigned to the singleton.
        $lastError = null;
        for ($attempt = 1; $attempt <= self::CONNECT_MAX_ATTEMPTS; $attempt++) {
            try {
                self::$pdo = new PDO($dsn, $config['user'], $config['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false, // real prepared statements
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]);

                return self::$pdo;
            } catch (PDOException $e) {
                $lastError = $e;
                if ($attempt < self::CONNECT_MAX_ATTEMPTS) {
                    // Linear backoff: 100ms, 200ms, ... Keeps total added latency
                    // small (<= ~300ms) while smoothing over brief spikes.
                    usleep(self::CONNECT_BACKOFF_US * $attempt);
                }
            }
        }

        // All attempts failed. Emit safe operational evidence only — never the
        // DSN, host, username, password, or driver message (which may embed
        // connection details). Only the PDO error code and attempt count.
        error_log(sprintf(
            '[VELORA_DB_CONNECT_FAIL] attempts=%d pdo_code=%s',
            self::CONNECT_MAX_ATTEMPTS,
            $lastError !== null ? (string) $lastError->getCode() : 'unknown',
        ));

        // Surface a distinguishable, retryable 503 to the HTTP layer instead of
        // a generic 500. The message is generic and safe to render to clients.
        throw new ServiceUnavailableException(
            'Service temporarily unavailable.',
            'SERVICE_UNAVAILABLE',
            'errors.http.503',
        );
    }

    /**
     * Run a callback inside a DB transaction (CTO checklist #3:
     * multi-row mutations must be wrapped in transactions).
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
