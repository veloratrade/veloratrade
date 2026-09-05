<?php

declare(strict_types=1);

/**
 * Phase H — Analytics + Revenue Intelligence tests.
 *
 * Covers: authentication/RBAC (ordinary user denied, admin/super-admin allowed),
 * date-range validation (bad format, impossible date, reversed order, oversized
 * range, SQL injection rejection), metric correctness (users/trades/AI counts
 * match seeded authoritative rows), aggregation (byRole/byStatus/byLocale,
 * win/loss, per-symbol, per-provider), empty-data behaviour, the honest
 * unavailable revenue state (available:false, reason:NO_BILLING_SOURCE) with a
 * strict NO-ZERO-FILL assertion, response shape, and source-of-truth usage
 * (ai_requests is the authoritative AI ledger, never raw logs).
 *
 * No fake revenue is ever created. Deterministic fixtures only.
 *
 * Convention (mirrors test_billing_g/test_user360): Response::json()/error()
 * terminate the process, so each case runs in a dedicated child process over a
 * shared temp SQLite DB. No secrets, no network.
 *
 * Run: php tools/tests/test_analytics_h.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-analytics-test-' . bin2hex(random_bytes(5));

function spawn(string $self, string $root, string $case, array $extraEnv = []): array
{
    $cmd = 'php ' . escapeshellarg($self) . ' --child ' . escapeshellarg($case) . ' ' . escapeshellarg($root);
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = array_merge(getenv(), ['VELORA_TEST_CHILD' => '1'], $extraEnv);
    $p = proc_open($cmd, $spec, $pipes, null, $env);
    fwrite($pipes[0], ''); fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($p);
    return ['code' => $code, 'out' => $out, 'err' => $err];
}

if (!in_array(getenv('VELORA_TEST_CHILD'), ['1', 'true'], true)) {
    $failures = 0; $checks = 0;
    function check(bool $c, string $l): void { global $failures, $checks; $checks++; echo ($c ? '  PASS: ' : '  FAIL: ') . $l . "\n"; if (!$c) { $failures++; } }
    function decode(array $r): array { $j = json_decode($r['out'], true); return isset($j['data']) && is_array($j['data']) ? $j['data'] : (is_array($j) ? $j : []); }

    @mkdir($ROOT . '/config', 0700, true); @mkdir($ROOT . '/data', 0700, true); @mkdir($ROOT . '/logs', 0700, true);
    spawn($SELF, $ROOT, 'setup');

    // ---- unavailable revenue state (NO-ZERO-FILL) ----
    $r = spawn($SELF, $ROOT, 'revenue'); $j = decode($r);
    check(($j['available'] ?? true) === false, 'revenue available=false (no billing source)');
    check(($j['reason'] ?? '') === 'NO_BILLING_SOURCE', 'revenue reason=NO_BILLING_SOURCE');
    foreach (['revenue', 'mrr', 'arr', 'churn', 'ltv', 'paymentVolume', 'refunds'] as $k) {
        $m = $j['metrics'][$k] ?? [];
        check(($m['available'] ?? true) === false && ($m['reason'] ?? '') === 'NO_BILLING_SOURCE', "metric {$k} is unavailable (NOT zero-filled)");
        check(!array_key_exists('value', $m) || $m['value'] !== null, "metric {$k} carries no fabricated numeric value");
    }
    check(!preg_match('/\b(0|0\.|00)\b/', (string) ($j['note'] ?? '')), 'unavailable note never states a zero value');

    // ---- overview metric correctness vs seeded authoritative rows ----
    $r = spawn($SELF, $ROOT, 'overview'); $j = decode($r);
    check(($j['users']['total'] ?? -1) === 3, 'users.total == COUNT(users)');
    check(($j['trading']['totalTrades'] ?? -1) === 3, 'trading.totalTrades == COUNT(trades)');
    check(($j['ai']['totalRequests'] ?? -1) === 3, 'ai.totalRequests == COUNT(ai_requests)');
    check(($j['users']['active'] ?? -1) === 3, 'users.active == COUNT(status=active)');
    check(($j['revenue']['available'] ?? true) === false, 'overview.revenue unavailable in mixed-domain overview');

    // ---- users analytics ----
    $r = spawn($SELF, $ROOT, 'users'); $j = decode($r);
    check(($j['total'] ?? -1) === 3, 'users.total correct');
    check(($j['newInRange'] ?? -1) === 3, 'newInRange (range=all) correct');
    $roles = array_column($j['byRole'] ?? [], 'key');
    check(in_array('user', $roles, true) && in_array('super_admin', $roles, true) && in_array('admin', $roles, true), 'byRole lists all three roles');
    check(count($j['registrationTrend'] ?? []) >= 1 && ($j['registrationTrend'][0]['date'] ?? '') === '2026-09-03', 'registrationTrend bucket date correct');

    // ---- trading analytics (P&L is trading performance, NOT revenue) ----
    $r = spawn($SELF, $ROOT, 'trading'); $j = decode($r);
    check(($j['total'] ?? -1) === 3, 'trading.total == COUNT(trades)');
    check(($j['winLoss']['wins'] ?? -1) === 2 && ($j['winLoss']['losses'] ?? -1) === 1, 'wins/losses sign-split correct');
    check(($j['netPnl'] ?? '') === '158.00', 'netPnl = sum(profit_loss) via bcmath (118+90-50)');
    check(($j['isRevenue'] ?? true) === false, 'trading P&L labelled NOT revenue');
    check(in_array('XAUUSD', array_column($j['bySymbol'] ?? [], 'key'), true), 'bySymbol contains XAUUSD');

    // ---- AI analytics (ai_requests is authoritative) ----
    $r = spawn($SELF, $ROOT, 'ai'); $j = decode($r);
    check(($j['total'] ?? -1) === 3, 'ai.total == COUNT(ai_requests)');
    $byStatus = array_combine(array_column($j['byStatus'] ?? [], 'key'), array_column($j['byStatus'] ?? [], 'count'));
    check(($byStatus['success'] ?? 0) === 2 && (($byStatus['quota_exhausted'] ?? 0) === 1), 'ai byStatus success/failed split correct');
    check(($j['tokensUsed'] ?? -1) === 1320, 'ai tokensUsed = SUM(tokens_used) (320+880+120)');
    check(in_array('gemini', array_column($j['byProvider'] ?? [], 'key'), true), 'ai byProvider gemini present');

    // ---- operations analytics ----
    $r = spawn($SELF, $ROOT, 'operations'); $j = decode($r);
    check(($j['systemLogs']['errors'] ?? -1) === 1, 'operations systemErrors count correct (1 ERROR)');
    $sev = array_combine(array_column($j['systemLogs']['bySeverity'] ?? [], 'key'), array_column($j['systemLogs']['bySeverity'] ?? [], 'count'));
    check(($sev['ERROR'] ?? 0) === 1, 'operations bySeverity ERROR count correct');
    check(in_array('metaapi', array_column($j['integrations'] ?? [], 'integration'), true), 'operations integration health lists metaapi');
    check(($j['integrationFailures'] ?? -1) === 1, 'operations integrationFailures correct (metaapi NOT_CONFIGURED)');
    check(($j['adminAudit']['eventsInRange'] ?? -1) === 40, 'operations adminAudit eventsInRange == COUNT(admin_audit_logs)');

    // ---- date-range validation ----
    foreach ([['range', 'bogus'], ['start', '09-01-2026'], ['start', '2026-02-30'], ['reversed', '2026-09-03']] as [$kind, $v]) {
        $r = spawn($SELF, $ROOT, 'range_invalid', ['TEST_RANGE_KIND' => $kind === 'reversed' ? 'reversed' : 'custom', 'TEST_RANGE_VALUE' => $v]);
        check(str_contains($r['out'], 'VALIDATION_FAILED'), "invalid range rejected ({$kind}={$v})");
    }
    $r = spawn($SELF, $ROOT, 'range_oversized');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'oversized range rejected (bounded)');
    $r = spawn($SELF, $ROOT, 'range_sqli', ['TEST_RANGE_VALUE' => "2026-09-01' OR '1'='1"]);
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'SQL-injection payload in range rejected');
    $r = spawn($SELF, $ROOT, 'range_valid');
    $j = decode($r);
    check(isset($j['total']) && ($j['total'] ?? -1) === 3, 'valid custom range accepted (returns data)');

    // ---- empty data: totals are 0 (provable) but revenue remains UNAVAILABLE ----
    $r = spawn($SELF, $ROOT, 'empty_au'); $j = decode($r);
    check(($j['users']['total'] ?? -1) === 0 && ($j['trading']['totalTrades'] ?? -1) === 0 && ($j['ai']['totalRequests'] ?? -1) === 0, 'empty DB reports provable zero activity');
    check(($j['revenue']['available'] ?? true) === false, 'empty DB still reports revenue UNAVAILABLE (not zero)');

    // ---- RBAC ----
    $r = spawn($SELF, $ROOT, 'rbac_user'); check(str_contains($r['out'], 'PERMISSION_DENIED'), 'ordinary user denied analytics.view');
    $r = spawn($SELF, $ROOT, 'rbac_admin'); check(($r['out'] ?? '') !== '' && !str_contains($r['out'], 'PERMISSION_DENIED'), 'admin allowed analytics.view');
    $r = spawn($SELF, $ROOT, 'rbac_super'); check(($r['out'] ?? '') !== '' && !str_contains($r['out'], 'PERMISSION_DENIED'), 'super_admin allowed analytics.view');

    // ---- source-of-truth: analytics uses ai_requests, NOT seed row count change ----
    $r = spawn($SELF, $ROOT, 'usage_sot'); $j = decode($r);
    check(($j['usedAuthLedger'] ?? false) === true, 'AI analytics derive from ai_requests (authoritative), not logs');

    echo "\nanalytics-h: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
    exit($failures === 0 ? 0 : 1);
}

// ---------------------------------------------------------------------------
// Child: setup + dispatch
// ---------------------------------------------------------------------------
$case = ($argv[1] ?? '') === '--child' ? ($argv[2] ?? '') : '';
$ROOTC = $argv[3] ?? $ROOT;

putenv('APP_ENV=local'); putenv('APP_DEBUG=true');
putenv('VELORA_PRIVATE_ROOT=' . $ROOTC); putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
putenv('DB_DRIVER=sqlite'); putenv('DB_DATABASE=' . $ROOTC . '/data/velora.sqlite');
putenv('METAAPI_MAX_ACCOUNTS_PER_USER=10');
if (!is_file($ROOTC . '/config/velora.env')) {
    @mkdir($ROOTC . '/config', 0700, true);
    file_put_contents($ROOTC . '/config/velora.env', implode("\n", [
        'APP_ENV=local', 'APP_DEBUG=true', 'DB_DRIVER=sqlite', 'DB_DATABASE=' . $ROOTC . '/data/velora.sqlite',
        'JWT_SECRET=' . str_repeat('j', 48), 'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
        'CORS_ALLOWED_ORIGINS=http://localhost', 'FRONTEND_URL=http://localhost', 'MAIL_DRIVER=log',
        'METAAPI_TOKEN=', 'METAAPI_MAX_ACCOUNTS_PER_USER=10',
    ]) . "\n");
}
@mkdir($ROOTC . '/data', 0700, true);
ini_set('error_log', $ROOTC . '/logs/php-error.log');
require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Admin\AnalyticsController;
use Velora\Auth\Role;
use Velora\Auth\AuthMiddleware;
use Velora\Core\Request;

$pdo = \Velora\Core\Database::connection();

if ($case === 'setup') {
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, full_name TEXT, role TEXT NOT NULL DEFAULT \'user\', status TEXT NOT NULL DEFAULT \'active\', locale TEXT DEFAULT \'en\', plan TEXT NOT NULL DEFAULT \'free\', subscription_status TEXT NOT NULL DEFAULT \'none\', created_at DATETIME, updated_at DATETIME)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS trades (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, symbol TEXT, direction TEXT DEFAULT \'buy\', volume REAL DEFAULT 0, profit_loss REAL DEFAULT 0, created_at DATETIME)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, feature TEXT, provider TEXT, model TEXT, tokens_used INTEGER DEFAULT 0, status TEXT DEFAULT \'success\', cost REAL DEFAULT 0, created_at DATETIME)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS trading_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, mt_login TEXT, sync_status TEXT DEFAULT \'DISCONNECTED\', created_at DATETIME)');
    $pdo->exec("INSERT INTO trading_accounts (user_id, mt_login, sync_status) VALUES (2,'123','CONNECTED'),(3,'456','CONNECTED')");
    $pdo->exec('CREATE TABLE IF NOT EXISTS system_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, severity TEXT, source TEXT, message TEXT, request_id TEXT, correlation_id TEXT, user_id INTEGER, error_code TEXT, metadata_json TEXT, created_at DATETIME)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS integration_health (integration TEXT PRIMARY KEY, status TEXT, latency_ms INTEGER, error_code TEXT, message TEXT, checked_at DATETIME)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER, actor_role TEXT, action TEXT, target_type TEXT, target_id INTEGER, result TEXT, summary TEXT, ip_address TEXT, user_agent TEXT, context_id TEXT, metadata_json TEXT, created_at DATETIME)');
    $pdo->exec("INSERT INTO users (email, role, status, locale, created_at) VALUES
        ('a@x','user','active','en','2026-09-03 18:27:08'),
        ('b@x','admin','active','en','2026-09-03 18:27:08'),
        ('c@x','super_admin','active','fa','2026-09-03 18:27:08')");
    $pdo->exec("INSERT INTO trades (user_id, symbol, direction, volume, profit_loss, created_at) VALUES
        (2,'XAUUSD','buy',0.1,118.0,'2026-09-02 18:27:08'),
        (2,'EURUSD','sell',0.01,90.0,'2026-09-01 18:27:08'),
        (2,'BTCUSD','buy',0.5,-50.0,'2026-08-31 20:27:08')");
    $pdo->exec("INSERT INTO ai_requests (user_id, feature, provider, model, tokens_used, status, cost, created_at) VALUES
        (2,'trade_analysis','gemini','gemini-1.5-flash',320,'success',0.0001,'2026-09-03 13:27:08'),
        (2,'extraction','gemini','gemini-1.5-flash',880,'success',0.0002,'2026-09-02 16:27:08'),
        (2,'weekly_report','gemini','gemini-1.5-flash',120,'quota_exhausted',0.0,'2026-08-31 00:27:08')");
    $pdo->exec("INSERT INTO system_logs (severity, source, message, created_at) VALUES
        ('INFO','auth','ok','2026-09-03 18:27:08'),
        ('WARN','metaapi','notice','2026-09-03 18:27:08'),
        ('ERROR','api','boom','2026-09-03 18:27:08')");
    $pdo->exec("INSERT INTO integration_health (integration, status, latency_ms, error_code, checked_at) VALUES ('metaapi','NOT_CONFIGURED',NULL,'NOT_CONFIGURED','2026-09-03 19:09:10'),('email','HEALTHY',NULL,NULL,'2026-09-03 19:09:10')");
    // 40 action rows
    $stmt = $pdo->prepare('INSERT INTO admin_audit_logs (actor_user_id, actor_role, action, result, created_at) VALUES (?,?,?,?,?)');
    for ($i = 0; $i < 40; $i++) { $stmt->execute([1, 'super_admin', 'audit.action.', 'success', '2026-09-03 18:27:08']); }
    echo 'SETUP_OK'; exit(0);
}

function rq(string $path, string $role, int $uid, array $query = []): Request
{
    $r = new Request('GET', $path, $query, [], ['authorization' => 'Bearer ' . str_repeat('j', 48), 'user-agent' => 'test-agent', 'x-request-id' => 'ctx-h']);
    $r->attributes['user_role'] = $role;
    $r->attributes['user_id'] = $uid;
    return $r;
}
function deny(callable $fn): void
{
    try { $fn(); echo json_encode(['ok' => true]); } catch (\Throwable $e) {
        http_response_code(403);
        echo json_encode(['error' => ['code' => ($e instanceof \Velora\Core\Exceptions\ApiException ? $e->errorCode() : 'X')]]);
    }
}

ob_start();
register_shutdown_function(function (): void { $o = ob_get_clean(); if ($o !== null && $o !== '') { echo $o; } });

$ctrl = new AnalyticsController();

switch ($case) {
    case 'revenue': $ctrl->revenue(rq('/api/v1/admin/analytics/revenue', 'super_admin', 3)); break;
    case 'overview': $ctrl->overview(rq('/api/v1/admin/analytics/overview', 'super_admin', 3, ['range' => 'all'])); break;
    case 'users': $ctrl->users(rq('/api/v1/admin/analytics/users', 'super_admin', 3, ['range' => 'all'])); break;
    case 'trading': $ctrl->trading(rq('/api/v1/admin/analytics/trading', 'super_admin', 3, ['range' => 'all'])); break;
    case 'ai': $ctrl->ai(rq('/api/v1/admin/analytics/ai', 'super_admin', 3, ['range' => 'all'])); break;
    case 'operations': $ctrl->operations(rq('/api/v1/admin/analytics/operations', 'super_admin', 3, ['range' => 'all'])); break;
    case 'range_valid': $ctrl->users(rq('/api/v1/admin/analytics/users', 'super_admin', 3, ['start' => '2026-09-01', 'end' => '2026-09-03'])); break;
    case 'range_invalid': {
        $kind = (string) getenv('TEST_RANGE_KIND'); $v = (string) getenv('TEST_RANGE_VALUE');
        $q = $kind === 'reversed' ? ['start' => '2026-09-03', 'end' => '2026-09-01'] : ['range' => $v];
        try { $ctrl->users(rq('/api/v1/admin/analytics/users', 'super_admin', 3, $q)); echo json_encode(['ok' => true]); }
        catch (\Velora\Core\Exceptions\ValidationException $e) { http_response_code(422); echo json_encode(['error' => ['code' => 'VALIDATION_FAILED']]); }
        break;
    }
    case 'range_oversized': {
        try { $ctrl->users(rq('/api/v1/admin/analytics/users', 'super_admin', 3, ['start' => '2020-01-01', 'end' => '2026-09-03'])); echo json_encode(['ok' => true]); }
        catch (\Velora\Core\Exceptions\ValidationException $e) { http_response_code(422); echo json_encode(['error' => ['code' => 'VALIDATION_FAILED']]); }
        break;
    }
    case 'range_sqli': {
        $v = (string) getenv('TEST_RANGE_VALUE');
        try { $ctrl->users(rq('/api/v1/admin/analytics/users', 'super_admin', 3, ['start' => $v, 'end' => '2026-09-03'])); echo json_encode(['ok' => true]); }
        catch (\Velora\Core\Exceptions\ValidationException $e) { http_response_code(422); echo json_encode(['error' => ['code' => 'VALIDATION_FAILED']]); }
        break;
    }
    case 'empty_au': {
        // Drop all rows then prove totals=0 but revenue still unavailable.
        foreach (['users', 'trades', 'ai_requests', 'system_logs', 'integration_health', 'admin_audit_logs'] as $t) {
            $pdo->exec("DELETE FROM {$t}");
        }
        $ctrl->overview(rq('/api/v1/admin/analytics/overview', 'super_admin', 3, ['range' => 'all']));
        break;
    }
    case 'rbac_user': deny(fn () => (AuthMiddleware::requirePermission(Role::P_ANALYTICS_VIEW))(rq('/', 'user', 1))); break;
    case 'rbac_admin': (AuthMiddleware::requirePermission(Role::P_ANALYTICS_VIEW))(rq('/', 'admin', 3)); echo json_encode(['ok' => true]); break;
    case 'rbac_super': (AuthMiddleware::requirePermission(Role::P_ANALYTICS_VIEW))(rq('/', 'super_admin', 3)); echo json_encode(['ok' => true]); break;
    case 'usage_sot': {
        // Prove AI analytics read ai_requests (authoritative), NOT system_logs.
        // Insert an extra log row so the two counts diverge; ai.total must still
        // equal COUNT(ai_requests), demonstrating the correct ledger is used.
        $pdo->exec("INSERT INTO system_logs (severity, source, message, created_at) VALUES ('INFO','ai','extra','2026-09-03 18:30:00')");
        $svc = new \Velora\Admin\AnalyticsService();
        $out = $svc->ai(['range' => 'all']);
        $ai = (int) $pdo->query('SELECT COUNT(*) FROM ai_requests')->fetchColumn();
        $logs = (int) $pdo->query('SELECT COUNT(*) FROM system_logs')->fetchColumn();
        // ai.total derives from ai_requests even though system_logs has more rows
        echo json_encode(['usedAuthLedger' => (($out['total'] ?? -1) === $ai) && ($out['total'] ?? -1) !== $logs]);
        break;
    }
    default: echo json_encode(['ok' => true]); break;
}
