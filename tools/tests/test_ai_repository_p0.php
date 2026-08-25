<?php

declare(strict_types=1);

/**
 * P0 verification: AIExtractionRepository + Quota + Logs with SQLite in-memory.
 * Tests: create extraction, findByHash deduplication, user ownership isolation, quota increment, logging.
 */

namespace Velora\Core {
    final class Config {
        public static array $values = [];
        public static function env(string $key, string $default = ''): string {
            return self::$values[$key] ?? $default;
        }
        public static function get(string $key, mixed $default = null): mixed { return $default; }
        public static function privatePath(string $p): string { return sys_get_temp_dir() . '/' . $p; }
        public static function isDevelopmentEnvironment(): bool { return false; }
    }

    final class Database {
        private static ?\PDO $pdo = null;
        public static function connection(): \PDO {
            if (self::$pdo === null) {
                self::$pdo = new \PDO('sqlite::memory:');
                self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                self::$pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
                // Create tables (SQLite compatible)
                self::$pdo->exec("
                    CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT);
                ");
                self::$pdo->exec("INSERT INTO users (id, email) VALUES (1, 'user1@test'), (2, 'user2@test');");
                self::$pdo->exec("
                    CREATE TABLE ai_extractions (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        provider VARCHAR(32) NOT NULL,
                        image_hash CHAR(64) NOT NULL,
                        original_result TEXT,
                        final_result TEXT,
                        confidence FLOAT NOT NULL DEFAULT 0.0,
                        latency_ms INTEGER NOT NULL DEFAULT 0,
                        status VARCHAR(20) NOT NULL DEFAULT 'success',
                        error_code VARCHAR(64),
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    );
                ");
                self::$pdo->exec("
                    CREATE TABLE ai_provider_quotas (
                        provider VARCHAR(32) NOT NULL PRIMARY KEY,
                        daily_used INTEGER NOT NULL DEFAULT 0,
                        quota_limit INTEGER NOT NULL DEFAULT 1500,
                        reset_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    );
                ");
                self::$pdo->exec("INSERT INTO ai_provider_quotas (provider, quota_limit) VALUES ('gemini', 1500), ('tesseract', 100000);");
                self::$pdo->exec("
                    CREATE TABLE ai_provider_logs (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        provider VARCHAR(32) NOT NULL,
                        status VARCHAR(32) NOT NULL,
                        latency_ms INTEGER NOT NULL DEFAULT 0,
                        error_code VARCHAR(64),
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    );
                ");
            }
            return self::$pdo;
        }
        public static function reset(): void { self::$pdo = null; }
    }
}

namespace {
    $base = dirname(__DIR__, 2) . '/api/src/AI';
    require $base . '/Repositories/AIExtractionRepository.php';
    require $base . '/Repositories/AIProviderQuotaRepository.php';
    require $base . '/Repositories/AIProviderLogRepository.php';

    $assertions = 0; $passed = 0;
    function expect(bool $cond, string $msg): void {
        global $assertions, $passed;
        $assertions++;
        if ($cond) { $passed++; echo "PASS: $msg\n"; }
        else { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
    }

    echo "=== P0: AI Repository Tests (SQLite) ===\n";

    $repo = new \Velora\AI\Repositories\AIExtractionRepository();
    $quotaRepo = new \Velora\AI\Repositories\AIProviderQuotaRepository();
    $logRepo = new \Velora\AI\Repositories\AIProviderLogRepository();

    // Test 1: create extraction
    $hash = hash('sha256', 'fake-image-1');
    $id1 = $repo->create([
        'user_id' => 1,
        'provider' => 'gemini',
        'image_hash' => $hash,
        'original_result' => ['symbol' => 'XAUUSD'],
        'final_result' => ['symbol' => 'XAUUSD', 'side' => 'buy'],
        'confidence' => 0.92,
        'latency_ms' => 1200,
        'status' => 'success',
        'error_code' => null,
    ]);
    expect($id1 > 0, "create extraction returns ID $id1");

    // Test 2: findByHash deduplication
    $found = $repo->findByHash($hash, 1);
    expect($found !== null, "findByHash returns cached extraction");
    expect($found['image_hash'] === $hash, "hash matches");
    expect((int)$found['user_id'] === 1, "user_id matches");

    // Test 3: user ownership isolation
    $foundOtherUser = $repo->findByHash($hash, 2);
    expect($foundOtherUser === null, "Other user cannot see cached extraction (ownership isolation)");

    $id2 = $repo->create([
        'user_id' => 2,
        'provider' => 'gemini',
        'image_hash' => $hash,
        'original_result' => ['symbol' => 'EURUSD'],
        'final_result' => ['symbol' => 'EURUSD'],
        'confidence' => 0.85,
        'latency_ms' => 900,
        'status' => 'success',
    ]);
    expect($id2 !== $id1, "Different user creates separate extraction");

    $foundUser2 = $repo->findByHash($hash, 2);
    expect($foundUser2 !== null && (int)$foundUser2['id'] === $id2, "User 2 finds own extraction");

    // Test 4: findOwned
    $owned = $repo->findOwned($id1, 1);
    expect($owned !== null && (int)$owned['id'] === $id1, "findOwned returns own record");
    $notOwned = $repo->findOwned($id1, 2);
    expect($notOwned === null, "findOwned fails for other user");

    // Test 5: recentForUser
    $recent = $repo->recentForUser(1, 10);
    expect(count($recent) >= 1, "recentForUser returns at least 1");

    // Test 6: quota increment logic (P0 fix)
    $quotaBefore = $quotaRepo->getQuota('gemini');
    expect($quotaBefore !== null && (int)$quotaBefore['daily_used'] === 0, "Initial quota 0");
    $quotaRepo->incrementUsage('gemini');
    $quotaAfter = $quotaRepo->getQuota('gemini');
    expect((int)$quotaAfter['daily_used'] === 1, "Quota increment to 1");
    $quotaRepo->incrementUsage('gemini');
    $quotaAfter2 = $quotaRepo->getQuota('gemini');
    expect((int)$quotaAfter2['daily_used'] === 2, "Quota increment to 2");
    expect($quotaRepo->hasQuota('gemini') === true, "hasQuota true when under limit");

    // Test 7: provider logging persistence (P0 fix)
    $logRepo->log('gemini', 'success', 1200, null);
    $logRepo->log('gemini', 'quota_exhausted', 0, 'QUOTA_EXHAUSTED');
    $logRepo->log('tesseract', 'success', 800, null);
    $recentLogs = $logRepo->recentForProvider('gemini', 10);
    expect(count($recentLogs) === 2, "Provider logs persisted (2 for gemini)");
    $failCount = $logRepo->failureCountLastMinutes('gemini', 10);
    expect($failCount === 1, "Failure count 1 for gemini in last 10 min");

    // Test 8: dedup cache prevents duplicate API calls (logic)
    $hash2 = hash('sha256', 'fake-image-2');
    $repo->create([
        'user_id' => 1,
        'provider' => 'gemini',
        'image_hash' => $hash2,
        'original_result' => ['symbol' => 'BTCUSD'],
        'final_result' => ['symbol' => 'BTCUSD'],
        'confidence' => 0.9,
        'latency_ms' => 1000,
        'status' => 'success',
    ]);
    $cached = $repo->findByHash($hash2, 1);
    expect($cached !== null, "Second image cached for dedup");

    echo "\n=== P0 Repository Tests: $passed/$assertions PASS ===\n";
    echo "AI_REPOSITORY_P0: PASS\n";
}
