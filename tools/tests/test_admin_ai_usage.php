<?php

declare(strict_types=1);

use Velora\Admin\AiUsageController;
use Velora\Auth\AuthMiddleware;
use Velora\Auth\Role;
use Velora\Core\Request;

/**
 * Phase 5 — Admin AI Usage Drilldown: backend, authorization, filters,
 * pagination, determinism, secret exclusion and per-user behavior
 * preservation tests.
 *
 * Covers: the global AI-usage listing over the existing `ai_requests` ledger
 * (real stored rows, safe projection WITHOUT `prompt_hash`, per-page user
 * identity via ONE query, real quota rows), whitelisted filters
 * (user/feature/provider/model/status/date bounds), bounded pagination +
 * clamps, deterministic ordering with the `id` tiebreaker and whitelisted
 * direction, honest empty results, unauth/RBAC/plain-admin authorization
 * (`aiManage`, the frozen #/ai-usage gate), SQL-injection attempts (never
 * interpolated; non-whitelisted input is rejected), and preservation of the
 * existing per-user repository semantics. Every case runs in a dedicated
 * child process over one shared temp SQLite DB with the real services —
 * no fake data, no network.
 *
 * Run: php tools/tests/test_admin_ai_usage.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-p5-test-' . bin2hex(random_bytes(5));

function spawn(string $self, string $root, string $case): array
{
    $cmd = 'php ' . escapeshellarg($self) . ' --child ' . escapeshellarg($case) . ' ' . escapeshellarg($root);
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = array_merge(getenv(), ['VELORA_TEST_CHILD' => '1']);
    $p = proc_open($cmd, $spec, $pipes, null, $env);
    fwrite($pipes[0], '');
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    return ['code' => $code, 'out' => $out, 'err' => $err];
}

if (!in_array(getenv('VELORA_TEST_CHILD'), ['1', 'true'], true)) {
    $failures = 0;
    $checks = 0;
    function check(bool $c, string $l): void
    {
        global $failures, $checks;
        $checks++;
        echo ($c ? '  PASS: ' : '  FAIL: ') . $l . "\n";
        if (!$c) {
            $failures++;
        }
    }

    $r = spawn($SELF, $ROOT, 'setup');
    check($r['code'] === 0 && str_contains($r['out'], '"ok"'), 'test setup child completed cleanly');

    // ===== Global listing: projection, identity, determinism, quotas =====
    $r = spawn($SELF, $ROOT, 'usage_list');
    $j = json_decode($r['out'], true) ?: [];
    $rows = $j['data']['requests'] ?? [];
    check(($j['data']['pagination']['total'] ?? 0) === 6, 'global ai-usage reports the real total (6 seeded)');
    check(($rows[0]['id'] ?? 0) === 304, 'deterministic default order (created_at DESC, id DESC): first = newest request');
    check(($rows[0]['userEmail'] ?? '') === 'target@example.com' && ($rows[0]['userFullName'] ?? '') === 'Tara Target', 'per-page user identity resolved (email + full name)');
    check(($rows[0]['feature'] ?? '') === 'weekly_report' && ($rows[0]['provider'] ?? '') === 'openai' && ($rows[0]['model'] ?? '') === 'gpt-4o-mini', 'row carries the real feature/provider/model');
    check(($rows[0]['tokensUsed'] ?? 0) === 2600 && ($rows[0]['latencyMs'] ?? 0) === 5400, 'camelCase projection carries the real tokens/latency');
    check((float) ($rows[0]['cost'] ?? '-1') === 0.0026, 'recorded cost value passes through (paid-provider placeholder row)');
    $first = $rows[0] ?? [];
    check(array_is_list($rows) && !array_key_exists('prompt_hash', $first) && !array_key_exists('promptHash', $first), 'projection NEVER includes prompt_hash');
    $quotas = $j['data']['quotas'] ?? [];
    $qGem = [];
    foreach ($quotas as $q) {
        $qGem[$q['provider']] = $q;
    }
    check(($qGem['gemini']['quotaLimit'] ?? 0) === 1500 && ($qGem['tesseract']['quotaLimit'] ?? 0) === 100000, 'real quota rows (ai_provider_quotas) returned for catalog providers');

    // ===== Filters =====
    $r = spawn($SELF, $ROOT, 'usage_user');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 2, 'user filter returns only that user\'s 2 requests');
    $r = spawn($SELF, $ROOT, 'usage_provider');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 4, 'provider filter (gemini) returns the 4 gemini requests');
    $r = spawn($SELF, $ROOT, 'usage_model');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 4, 'model filter (exact match) returns the 4 matching requests');
    $r = spawn($SELF, $ROOT, 'usage_feature');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 3, 'feature filter (extraction) returns the 3 extraction requests');
    $r = spawn($SELF, $ROOT, 'usage_status');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 1, 'status filter (quota_exhausted) returns the 1 recorded row');
    $r = spawn($SELF, $ROOT, 'usage_status_none');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? -1) === 0 && ($j['data']['requests'] ?? null) === [], 'status=failed -> honest empty (ledger records successes; no fabrication)');
    $r = spawn($SELF, $ROOT, 'usage_window');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? 0) === 3, 'date window (date_from/date_to on created_at) returns 3 requests');

    // ===== Pagination =====
    $r = spawn($SELF, $ROOT, 'usage_page2');
    $j = json_decode($r['out'], true) ?: [];
    check(count($j['data']['requests'] ?? []) === 2 && ($j['data']['pagination']['has_more'] ?? true) === false, 'pagination page=2/per_page=4: remainder + has_more=false');
    $r = spawn($SELF, $ROOT, 'usage_clamp');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['per_page'] ?? 0) === 100 && ($j['data']['pagination']['page'] ?? 0) === 1, 'per_page clamped to 100, page floor at 1');

    // ===== Sorting (whitelisted field + direction, id tiebreaker) =====
    $r = spawn($SELF, $ROOT, 'usage_order_tokens');
    $j = json_decode($r['out'], true) ?: [];
    check((int) ($j['data']['requests'][0]['tokensUsed'] ?? 0) === 2600, 'whitelisted order=tokens_used sorts by real tokens DESC');
    $r = spawn($SELF, $ROOT, 'usage_order_latency_asc');
    $j = json_decode($r['out'], true) ?: [];
    check((int) ($j['data']['requests'][0]['latencyMs'] ?? 0) === 900 && ($j['data']['requests'][0]['id'] ?? 0) === 303, 'order=latency_ms&dir=asc sorts by real latency ASC');
    $r = spawn($SELF, $ROOT, 'usage_order_cost');
    $j = json_decode($r['out'], true) ?: [];
    check((float) ($j['data']['requests'][0]['cost'] ?? 0) === 0.0026, 'whitelisted order=cost sorts by recorded cost DESC');

    // ===== Validation whitelist / errors =====
    $r = spawn($SELF, $ROOT, 'usage_bad_status');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'invalid status rejected (ENUM whitelist)');
    $r = spawn($SELF, $ROOT, 'usage_bad_feature');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'invalid feature rejected (vocabulary whitelist)');
    $r = spawn($SELF, $ROOT, 'usage_bad_provider');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'provider not in ProviderCatalog rejected');
    $r = spawn($SELF, $ROOT, 'usage_bad_order');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'invalid sort field rejected (whitelist enforced)');
    $r = spawn($SELF, $ROOT, 'usage_bad_dir');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'invalid sort direction rejected');
    $r = spawn($SELF, $ROOT, 'usage_bad_date');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'malformed date_from rejected (INVALID_DATE)');
    $r = spawn($SELF, $ROOT, 'usage_bad_user');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'non-numeric user_id rejected');

    // ===== SQL-injection attempts (bound params + whitelists — never interpolated) =====
    $r = spawn($SELF, $ROOT, 'usage_inject_user');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'user_id injection ("1 OR 1=1") rejected');
    $r = spawn($SELF, $ROOT, 'usage_inject_provider');
    check(str_contains($r['out'], 'VALIDATION_FAILED'), 'provider injection ("gemini\'--") rejected (catalog whitelist)');
    $r = spawn($SELF, $ROOT, 'usage_inject_model');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? -1) === 0, 'model injection (LIKE metacharacters) is inert: exact-match bound param matches nothing');

    // ===== Authorization =====
    $r = spawn($SELF, $ROOT, 'usage_unauth');
    check(str_contains($r['out'], 'ACCESS_TOKEN_MISSING') || str_contains($r['out'], 'UNAUTHORIZED'), 'unauthenticated ai-usage -> 401');
    $r = spawn($SELF, $ROOT, 'usage_rbac');
    check(str_contains($r['out'], 'PERMISSION_DENIED'), 'ordinary user denied ai-usage (RBAC)');
    $r = spawn($SELF, $ROOT, 'usage_admin_view');
    check(str_contains($r['out'], '"requests"'), 'plain admin (aiManage) can list global ai-usage');

    // ===== Honest emptiness for filter ids that match nothing =====
    $r = spawn($SELF, $ROOT, 'usage_ghost_user');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['data']['pagination']['total'] ?? -1) === 0 && ($j['data']['requests'] ?? null) === [], 'nonexistent user_id filter -> honest empty (no fabrication, no 404)');

    // ===== Secret / privacy exclusion (full payload sweep) =====
    $r = spawn($SELF, $ROOT, 'usage_list');
    check(
        !str_contains($r['out'], 'prompt_hash') && !str_contains($r['out'], 'promptHash')
        && !str_contains($r['out'], 'original_result') && !str_contains($r['out'], 'corrected_result')
        && !str_contains($r['out'], 'payload') && !str_contains($r['out'], 'credential')
        && !str_contains($r['out'], 'password') && !str_contains($r['out'], 'api_key')
        && !str_contains($r['out'], 'SECRET'),
        'ai-usage exposes NO prompt hash / extraction payload / credential material'
    );

    // ===== Existing per-user behavior preserved (canonical methods untouched) =====
    $r = spawn($SELF, $ROOT, 'legacy_per_user');
    $j = json_decode($r['out'], true) ?: [];
    check(($j['recentTotal'] ?? 0) === 2 && ($j['allOwned'] ?? false) === true, 'existing per-user recentForUser semantics preserved after the extension');

    echo "\nai-usage: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
    exit($failures === 0 ? 0 : 1);
}

// ============================== child ==============================
$ROOTC = $argv[3];
mkdir($ROOTC . '/config', 0700, true);
mkdir($ROOTC . '/data', 0700, true);
file_put_contents($ROOTC . '/config/velora.env', "APP_ENV=local\nDB_DRIVER=sqlite\nDB_DATABASE={$ROOTC}/data/velora.sqlite\nJWT_SECRET=" . str_repeat('j', 48) . "\nAPP_ENCRYPTION_KEY=" . base64_encode(random_bytes(32)) . "\nCORS_ALLOWED_ORIGINS=http://localhost\nFRONTEND_URL=http://localhost\nMAIL_DRIVER=log\n");
putenv('APP_ENV=local');
putenv('VELORA_PRIVATE_ROOT=' . $ROOTC);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
putenv('DB_DRIVER=sqlite');
putenv('DB_DATABASE=' . $ROOTC . '/data/velora.sqlite');
require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

$pdo = \Velora\Core\Database::connection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function deny(callable $fn): void
{
    try {
        $fn();
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        http_response_code($e instanceof \Velora\Core\Exceptions\ApiException ? $e->httpStatus() : 500);
        echo json_encode(['error' => [
            'code' => $e instanceof \Velora\Core\Exceptions\ApiException ? $e->errorCode() : 'X',
            'class' => get_class($e),
        ]]);
    }
}

function mkRequest(string $path, array $query, string $role, int $uid, string $method = 'GET'): Request
{
    $headers = ['user-agent' => 'test-agent', 'x-request-id' => 'ctx-123', 'authorization' => 'Bearer ' . str_repeat('j', 48)];
    if ($role === 'guest') {
        unset($headers['authorization']);
    }
    $rq = new Request($method, $path, $query, [], $headers);
    $rq->attributes['user_role'] = $role === 'guest' ? '' : $role;
    $rq->attributes['user_id'] = $uid;
    return $rq;
}

$case = $argv[2];

if ($case === 'setup') {
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, password_hash TEXT DEFAULT \'\', full_name TEXT DEFAULT \'\', role TEXT NOT NULL DEFAULT \'user\', status TEXT NOT NULL DEFAULT \'active\', email_verified_at DATETIME NULL, locale TEXT DEFAULT \'fa\', locale_source TEXT DEFAULT \'auto\', timezone TEXT DEFAULT \'UTC\', plan TEXT NOT NULL DEFAULT \'free\', subscription_status TEXT NOT NULL DEFAULT \'none\', plan_started_at DATETIME NULL, plan_expires_at DATETIME NULL, plan_updated_at DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
        feature TEXT NOT NULL DEFAULT 'extraction', provider TEXT NOT NULL DEFAULT 'gemini',
        model TEXT NOT NULL DEFAULT 'gemini-1.5-flash', prompt_hash TEXT NOT NULL,
        tokens_used INTEGER NOT NULL DEFAULT 0, latency_ms INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'success', cost REAL NOT NULL DEFAULT 0.000000,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_provider_quotas (
        provider TEXT PRIMARY KEY, daily_used INTEGER NOT NULL DEFAULT 0,
        quota_limit INTEGER NOT NULL DEFAULT 1500,
        reset_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");

    $ins = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, role, status, email_verified_at) VALUES (?, ?, ?, ?, ?, ?)');
    $cost = 4;
    $ins->execute(['super@velora.test', password_hash('SuperAdmin!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Arman Kaveh', 'super_admin', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute(['user@velora.test', password_hash('PlainUser!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Plain User', 'user', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute(['admin@velora.test', password_hash('AdminUser!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Ada Admin', 'admin', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute(['carol@example.com', password_hash('CarolPass!1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Carol Verified', 'user', 'active', gmdate('Y-m-d H:i:s')]);
    $ins->execute(['target@example.com', password_hash('Target!Pass1', PASSWORD_BCRYPT, ['cost' => $cost]), 'Tara Target', 'user', 'active', gmdate('Y-m-d H:i:s')]);

    // 6 AI request rows across 4 users — mirrored to the real writer's shape
    // (AIManager writes prompt_hash=sha256(prompt), never the prompt itself).
    // created_at DESC => 304,302,301,303,305,306
    $pdo->exec("INSERT INTO ai_requests (id, user_id, feature, provider, model, prompt_hash, tokens_used, latency_ms, status, cost, created_at) VALUES
        (301, 4, 'extraction',    'gemini',    'gemini-2.5-flash', '" . str_repeat('a', 64) . "', 1200, 3200, 'success',         0.000000, '2026-09-05 08:10:00'),
        (302, 4, 'analysis',      'gemini',    'gemini-2.5-flash', '" . str_repeat('b', 64) . "',  800, 2100, 'success',         0.000000, '2026-09-06 09:30:00'),
        (303, 5, 'extraction',    'tesseract', 'tesseract-5',      '" . str_repeat('c', 64) . "',    0,  900, 'success',         0.000000, '2026-09-04 11:00:00'),
        (304, 5, 'weekly_report', 'openai',    'gpt-4o-mini',      '" . str_repeat('d', 64) . "', 2600, 5400, 'success',         0.002600, '2026-09-06 18:45:00'),
        (305, 2, 'extraction',    'gemini',    'gemini-2.5-flash', '" . str_repeat('e', 64) . "', 1500, 2900, 'success',         0.000000, '2026-09-03 07:15:00'),
        (306, 3, 'analysis',      'gemini',    'gemini-2.5-flash', '" . str_repeat('f', 64) . "',  640, 1800, 'quota_exhausted', 0.000000, '2026-09-02 21:40:00')");

    // Real quota seeds (mirror v0.4/v0.9 conventions)
    $pdo->exec("INSERT INTO ai_provider_quotas (provider, daily_used, quota_limit) VALUES
        ('gemini', 6, 1500), ('tesseract', 0, 100000)");
    echo json_encode(['ok' => true]);
    exit(0);
}

$ctrl = new AiUsageController();

switch ($case) {
    case 'usage_list': $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', [], 'super_admin', 1)); break;
    case 'usage_page2': $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['page' => 2, 'per_page' => 4], 'super_admin', 1)); break;
    case 'usage_clamp': $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['page' => -3, 'per_page' => 500], 'super_admin', 1)); break;
    case 'usage_user': $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['user_id' => 4], 'super_admin', 1)); break;
    case 'usage_provider': $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['provider' => 'gemini'], 'super_admin', 1)); break;
    case 'usage_model': $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['model' => 'gemini-2.5-flash'], 'super_admin', 1)); break;
    case 'usage_feature': $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['feature' => 'extraction'], 'super_admin', 1)); break;
    case 'usage_status': $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['status' => 'quota_exhausted'], 'super_admin', 1)); break;
    case 'usage_status_none': $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['status' => 'failed'], 'super_admin', 1)); break;
    case 'usage_window': $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['date_from' => '2026-09-05', 'date_to' => '2026-09-06'], 'super_admin', 1)); break;
    case 'usage_order_tokens': $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['order' => 'tokens_used'], 'super_admin', 1)); break;
    case 'usage_order_latency_asc': $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['order' => 'latency_ms', 'dir' => 'asc'], 'super_admin', 1)); break;
    case 'usage_order_cost': $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['order' => 'cost'], 'super_admin', 1)); break;
    case 'usage_bad_status': deny(fn () => $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['status' => 'bogus'], 'super_admin', 1))); break;
    case 'usage_bad_feature': deny(fn () => $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['feature' => 'teleportation'], 'super_admin', 1))); break;
    case 'usage_bad_provider': deny(fn () => $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['provider' => 'ctrader'], 'super_admin', 1))); break;
    case 'usage_bad_order': deny(fn () => $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['order' => 'prompt_hash'], 'super_admin', 1))); break;
    case 'usage_bad_dir': deny(fn () => $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['dir' => 'sideways'], 'super_admin', 1))); break;
    case 'usage_bad_date': deny(fn () => $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['date_from' => '09/01/2026'], 'super_admin', 1))); break;
    case 'usage_bad_user': deny(fn () => $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['user_id' => 'abc'], 'super_admin', 1))); break;
    case 'usage_inject_user': deny(fn () => $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['user_id' => '1 OR 1=1'], 'super_admin', 1))); break;
    case 'usage_inject_provider': deny(fn () => $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['provider' => "gemini'--"], 'super_admin', 1))); break;
    case 'usage_inject_model': $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['model' => "x%' OR 1=1--"], 'super_admin', 1)); break;
    case 'usage_unauth': deny(fn () => (AuthMiddleware::authenticate())(mkRequest('/api/v1/admin/ai-usage', [], 'guest', 0))); break;
    case 'usage_rbac': deny(fn () => (AuthMiddleware::requirePermission(Role::P_AI_MANAGE))(mkRequest('/api/v1/admin/ai-usage', [], 'user', 2))); break;
    case 'usage_admin_view': $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', [], 'admin', 3)); break;
    case 'usage_ghost_user': $ctrl->usage(mkRequest('/api/v1/admin/ai-usage', ['user_id' => 9999], 'super_admin', 1)); break;
    case 'legacy_per_user': {
        $repo = new \Velora\AI\Repositories\AIRequestRepository();
        $recent = $repo->recentForUser(4, 20);
        $allOwned = true;
        foreach ($recent as $row) {
            $allOwned = $allOwned && (int) $row['user_id'] === 4;
        }
        echo json_encode(['recentTotal' => count($recent), 'allOwned' => $allOwned]);
        break;
    }
    default: echo json_encode(['error' => 'unknown case']); exit(1);
}
