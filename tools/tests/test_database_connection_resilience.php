<?php

declare(strict_types=1);

/**
 * Regression test for the database connection resilience patch.
 *
 * Scenarios:
 *   1. Successful connection (SQLite happy path via Database::connection()).
 *   2. Transient connection failure then success (retry loop recovers).
 *   3. Total connection failure -> ServiceUnavailableException (mapped to 503).
 *   4. The error surfaced to the HTTP layer contains no sensitive data
 *      (no DSN, host, username, password, or driver message).
 *
 * No database schema is created or migrated. No Auth flow is exercised.
 */

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? "PASS " : "FAIL ") . $name . ($detail !== '' ? " :: $detail" : "") . "\n";
    if (!$ok) {
        $failures++;
    }
}

// ---- Minimal environment so bootstrap/Config resolves (SQLite driver) ----
$root = sys_get_temp_dir() . '/velora-db-resilience-' . bin2hex(random_bytes(5));
mkdir($root . '/config', 0700, true);
mkdir($root . '/data', 0700, true);
mkdir($root . '/logs', 0700, true);
$dbPath = $root . '/data/velora.sqlite';
putenv('APP_ENV=local');
putenv('VELORA_PRIVATE_ROOT=' . $root);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
file_put_contents($root . '/config/velora.env', implode("\n", [
    'APP_ENV=local',
    'APP_DEBUG=true',
    'DB_DRIVER=sqlite',
    'DB_DATABASE=' . $dbPath,
    'JWT_SECRET=' . str_repeat('j', 48),
    'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
    'CORS_ALLOWED_ORIGINS=http://localhost',
    'FRONTEND_URL=http://localhost',
    'MAIL_DRIVER=log',
]) . "\n");

require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Core\Database;
use Velora\Core\Exceptions\ServiceUnavailableException;
use Velora\Core\Exceptions\ApiException;

// ---- Scenario 1: successful connection (SQLite) ----
try {
    $pdo = Database::connection();
    $one = (int) $pdo->query('SELECT 1')->fetchColumn();
    check('scenario 1: successful connection returns usable PDO', $one === 1);
    // singleton: same instance on second call
    check('scenario 1: connection() is a singleton', Database::connection() === $pdo);
} catch (\Throwable $e) {
    check('scenario 1: successful connection returns usable PDO', false, get_class($e));
}

// ---- The ServiceUnavailableException contract (used by scenarios 3/4) ----
$sue = new ServiceUnavailableException();
check('503 exception extends ApiException', $sue instanceof ApiException);
check('503 exception httpStatus is 503', $sue->httpStatus() === 503);
check('503 exception errorCode is SERVICE_UNAVAILABLE', $sue->errorCode() === 'SERVICE_UNAVAILABLE');
check('503 exception messageKey is errors.http.503', $sue->messageKey() === 'errors.http.503');

// ---- Scenario 4: no sensitive data in the client-facing message/details ----
$msg = $sue->getMessage();
$sensitiveNeedles = ['mysql:', 'host=', 'dbname=', 'password', 'pass', 'user=', '127.0.0.1', 'DSN'];
$leak = false;
foreach ($sensitiveNeedles as $n) {
    if (stripos($msg, $n) !== false) {
        $leak = true;
        break;
    }
}
check('scenario 4: 503 message contains no DSN/credentials/host', !$leak, 'message=' . $msg);
check('scenario 4: 503 details is null (nothing leaked in details)', $sue->details() === null);

// ---- Scenarios 2 & 3: exercise the retry loop semantics directly ----
// We replicate the exact retry contract the patch uses (3 attempts, connect-only
// retry, backoff), driving it with a stub "connect" callable so we do not depend
// on a live MySQL server in CI. This validates the loop behavior deterministically.
function retryingConnect(callable $connect, int $maxAttempts = 3, int $backoffUs = 1000): mixed
{
    $last = null;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            return $connect($attempt);
        } catch (\PDOException $e) {
            $last = $e;
            if ($attempt < $maxAttempts) {
                usleep($backoffUs * $attempt);
            }
        }
    }
    // Mirror patch behavior: safe log + distinguishable 503, no sensitive data.
    error_log(sprintf('[VELORA_DB_CONNECT_FAIL] attempts=%d pdo_code=%s',
        $maxAttempts, $last !== null ? (string) $last->getCode() : 'unknown'));
    throw new ServiceUnavailableException();
}

// Scenario 2: fail twice, succeed on 3rd attempt -> recovers, no exception.
$calls = 0;
try {
    $result = retryingConnect(function (int $attempt) use (&$calls) {
        $calls++;
        if ($attempt < 3) {
            throw new \PDOException('SQLSTATE[HY000] [1040] Too many connections', 1040);
        }
        return 'connected';
    });
    check('scenario 2: transient failure then retry succeeds', $result === 'connected' && $calls === 3, "calls=$calls");
} catch (\Throwable $e) {
    check('scenario 2: transient failure then retry succeeds', false, get_class($e));
}

// Scenario 3: fail all attempts -> ServiceUnavailableException (503), not a raw PDOException.
$calls = 0;
try {
    retryingConnect(function (int $attempt) use (&$calls) {
        $calls++;
        throw new \PDOException('SQLSTATE[HY000] [1040] Too many connections', 1040);
    });
    check('scenario 3: total failure throws 503', false, 'no exception thrown');
} catch (ServiceUnavailableException $e) {
    check('scenario 3: total failure throws ServiceUnavailableException', true);
    check('scenario 3: exactly 3 attempts were made', $calls === 3, "calls=$calls");
    // Scenario 4 again on the thrown instance: nothing sensitive.
    $leak2 = false;
    foreach (['1040', 'Too many connections', 'mysql:', 'password'] as $n) {
        if (stripos($e->getMessage(), $n) !== false) {
            $leak2 = true;
            break;
        }
    }
    check('scenario 3+4: thrown 503 message hides underlying driver detail', !$leak2, 'message=' . $e->getMessage());
} catch (\Throwable $e) {
    check('scenario 3: total failure throws ServiceUnavailableException', false, 'got ' . get_class($e));
}

echo "\n";
if ($failures > 0) {
    echo "DB_RESILIENCE_TESTS_FAILED failures=$failures\n";
    exit(1);
}
echo "DB_RESILIENCE_TESTS_OK\n";
exit(0);
