<?php

declare(strict_types=1);

/**
 * ISSUE 2 regression — AI consent has a real, server-authoritative flow:
 *   - PATCH /api/v1/auth/me/preferences {ai_consent:bool} persists users.ai_consent_at,
 *   - GET /api/v1/auth/me exposes the persisted state as a boolean (aiConsent),
 *   - invalid payloads -> 422 without persisting,
 *   - consent=false keeps AI extraction fail-closed (AIConsentRequiredException),
 *   - profile UI toggle is wired to PATCH + authoritative re-fetch (no optimistic state).
 *
 * Response methods exit, so each HTTP case runs in a spawned child sharing one
 * temp SQLite DB (same harness pattern as test_admin_ai_config.php).
 *
 * Run: php tools/tests/test_issue2_ai_consent.php
 */

$SELF = __FILE__;
$ROOT = sys_get_temp_dir() . '/velora-issue2-' . bin2hex(random_bytes(5));

function spawn(string $self, string $root, string $case): array
{
    $cmd = 'php ' . escapeshellarg($self) . ' --child ' . escapeshellarg($case) . ' ' . escapeshellarg($root);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['VELORA_TEST_CHILD' => '1']));
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
}
function decode(array $r): array
{
    $j = json_decode($r['out'], true);
    return is_array($j) ? $j : [];
}

if (!in_array(getenv('VELORA_TEST_CHILD'), ['1', 'true'], true)) {
    $failures = 0;
    $checks = 0;
    function check(bool $cond, string $label): void
    {
        global $failures, $checks;
        $checks++;
        echo ($cond ? '  PASS: ' : '  FAIL: ') . $label . "\n";
        if (!$cond) {
            $failures++;
        }
    }

    mkdir($ROOT . '/config', 0700, true);
    mkdir($ROOT . '/data', 0700, true);
    spawn($SELF, $ROOT, 'setup');

    echo "== API flow (authoritative server state) ==\n";
    $r = spawn($SELF, $ROOT, 'me');
    $u = decode($r)['data']['user'] ?? [];
    check(($u['aiConsent'] ?? null) === false, 'fresh account: GET /auth/me -> aiConsent=false (boolean, real DB state)');
    check(!isset($u['ai_consent_at']), 'raw timestamp is NOT exposed in the public user payload');

    $r = spawn($SELF, $ROOT, 'patch_true');
    $b = decode($r);
    check(($b['data']['updated'] ?? null) === true && ($b['data']['ai_consent'] ?? null) === true, 'PATCH {ai_consent:true} -> 200 {updated:true, ai_consent:true}');
    check(spawn($SELF, $ROOT, 'db_state')['out'] === 'NOT_NULL', 'users.ai_consent_at persisted NOT NULL');

    $r = spawn($SELF, $ROOT, 'me');
    check(((decode($r)['data']['user'] ?? [])['aiConsent'] ?? null) === true, 're-fetch reflects server truth: aiConsent=true');

    $r = spawn($SELF, $ROOT, 'patch_false');
    $b = decode($r);
    check(($b['data']['ai_consent'] ?? null) === false, 'PATCH {ai_consent:false} -> ai_consent=false');
    check(spawn($SELF, $ROOT, 'db_state')['out'] === 'NULL', 'users.ai_consent_at cleared (NULL) — consent revoked');
    $r = spawn($SELF, $ROOT, 'me');
    check(((decode($r)['data']['user'] ?? [])['aiConsent'] ?? null) === false, 're-fetch: aiConsent=false');

    $before = spawn($SELF, $ROOT, 'db_state')['out'];
    $r = spawn($SELF, $ROOT, 'patch_invalid');
    check((decode($r)['error']['code'] ?? '') === 'VALIDATION_FAILED', 'non-boolean ai_consent -> 422 VALIDATION_FAILED');
    check(spawn($SELF, $ROOT, 'db_state')['out'] === $before, 'invalid payload persisted nothing');

    echo "== Enforcement stays fail-closed ==\n";
    $r = spawn($SELF, $ROOT, 'extract_no_consent');
    check((decode($r)['error']['code'] ?? '') === 'AI_CONSENT_REQUIRED', 'extraction with consent=false -> 403 AI_CONSENT_REQUIRED (fail-closed, no silent fallback to external AI)');

    echo "== Profile UI wiring ==\n";
    $html = (string) file_get_contents(dirname(__DIR__, 2) . '/profile/index.html');
    check(strpos($html, 'id="aiConsentToggle"') !== false && strpos($html, 'role="switch"') !== false, 'toggle present with switch semantics');
    check(strpos($html, " VeloraData.request('/api/v1/auth/me/preferences'") !== false, 'saves through the existing preferences API');
    check(substr_count($html, "VeloraData.request('/api/v1/auth/me')") >= 2, 're-fetches /auth/me after mutation (no optimistic state)');
    check(strpos($html, 'renderAiConsent(typeof (me.user || {}).aiConsent') !== false, 'rendering uses ONLY the re-fetched server value');
    check(strpos($html, "t('profile.aiConsent.error')") !== false, 'API errors surface (no fake success)');
    $en = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/public/locales/en.json'), true)['messages'];
    $fa = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/public/locales/fa.json'), true)['messages'];
    preg_match_all('/data-i18n(?:-[a-z-]+)?="(profile\.aiConsent\.[A-Za-z0-9_.]+)"/', $html, $mm);
    check(count(array_unique($mm[1])) === 3, 'profile.aiConsent data-i18n attribute keys used (' . count(array_unique($mm[1])) . ')');
    check(strpos($html, "'profile.aiConsent.stateOn'") !== false && strpos($html, "'profile.aiConsent.stateOff'") !== false, 'state labels resolved via catalog keys in the page script (chunk coverage source)');
    $missing = array_filter(array_unique($mm[1]), fn ($k) => !isset($en[$k]) || !isset($fa[$k]));
    check($missing === [], 'all aiConsent keys resolve in BOTH catalogs');
    $identical = array_filter(array_keys($en), fn ($k) => str_starts_with($k, 'profile.aiConsent.') && $en[$k] === $fa[$k]);
    check($identical === [], 'no identical EN/FA values (fa.en.identical group clean)');
    $chunk = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/public/locales/chunks/fa/profile.json'), true)['messages'] ?? [];
    check(isset($chunk['profile.aiConsent.title']), 'profile feature chunk covers the new keys (template literal presence)');

    echo "\nissue2-ai-consent: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
    $rm = static function (string $p) use (&$rm): void {
        if (!is_dir($p)) { @unlink($p); return; }
        foreach (scandir($p) ?: [] as $f) { if ($f !== '.' && $f !== '..') $rm($p . '/' . $f); }
        @rmdir($p);
    };
    $rm($ROOT);
    exit($failures === 0 ? 0 : 1);
}

// ------------------------------------------------------------------ child
$case = $argv[2] ?? '';
$ROOT = $argv[3] ?? '';
putenv('APP_ENV=local');
putenv('VELORA_PRIVATE_ROOT=' . $ROOT);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
putenv('DB_DRIVER=sqlite');
putenv('DB_DATABASE=' . $ROOT . '/data/velora.sqlite');
if (!is_file($ROOT . '/config/velora.env')) {
    file_put_contents($ROOT . '/config/velora.env', "APP_ENV=local\nDB_DRIVER=sqlite\nDB_DATABASE={$ROOT}/data/velora.sqlite\nJWT_SECRET=" . str_repeat('j', 48) . "\nAPP_ENCRYPTION_KEY=" . base64_encode(random_bytes(32)) . "\nCORS_ALLOWED_ORIGINS=http://localhost\nFRONTEND_URL=http://localhost\nMAIL_DRIVER=log\nGEMINI_API_KEY=test-not-real\n");
}
require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\AI\Services\AIManager;
use Velora\AI\DTOs\AIResponseDTO;
use Velora\AI\Extraction\ExtractedTradeData;
use Velora\AI\Providers\AIProviderInterface;
use Velora\AI\Repositories\UserAIConsentRepository;
use Velora\AI\Services\FeatureRouter;
use Velora\Auth\AuthController;
use Velora\Core\Database;
use Velora\Core\Request;

$pdo = new PDO('sqlite:' . $ROOT . '/data/velora.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$USER_ID = 1;

if ($case === 'setup') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, email_verified_at DATETIME NULL, password_hash TEXT NULL, full_name TEXT DEFAULT '', role TEXT DEFAULT 'user', timezone TEXT DEFAULT 'UTC', locale TEXT DEFAULT 'fa', locale_source TEXT DEFAULT 'cookie', status TEXT DEFAULT 'active', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, ai_consent_at DATETIME NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, refresh_token_hash TEXT, revoked_at DATETIME NULL, expires_at DATETIME, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("INSERT OR IGNORE INTO users (id, email) VALUES (1, 'issue2@test.local')");
    echo 'SETUP_OK';
    exit(0);
}

ob_start();
register_shutdown_function(function (): void { $o = ob_get_clean(); if ($o !== null && $o !== '') echo $o; });

switch ($case) {
    case 'me':
        $req = new Request('GET', '/api/v1/auth/me', [], [], []);
        $req->attributes['user'] = ['id' => 1, 'email' => 'issue2@test.local', 'fullName' => 'T', 'role' => 'user', 'timezone' => 'UTC', 'locale' => 'fa', 'createdAt' => '2026-01-01', 'aiConsent' => (new UserAIConsentRepository())->getConsentAt($USER_ID) !== null];
        (new AuthController())->me($req);
        exit(0);
    case 'patch_true':
    case 'patch_false':
    case 'patch_invalid':
        $body = ['ai_consent' => $case === 'patch_true' ? true : ($case === 'patch_false' ? false : 'yes')];
        $req = new Request('PATCH', '/api/v1/auth/me/preferences', [], $body, []);
        $req->attributes['user_id'] = $USER_ID;
        (new AuthController())->updatePreferences($req);
        exit(0);
    case 'db_state':
        $v = $pdo->query('SELECT ai_consent_at FROM users WHERE id=1')->fetchColumn();
        echo $v === null || $v === false ? 'NULL' : 'NOT_NULL';
        exit(0);
    case 'extract_no_consent':
        // Direct AIManager call with consent=false — mirrors ScreenshotExtractor path.
        $repo = new class implements AIProviderInterface {
            public function getName(): string { return 'gemini'; }
            public function getCapabilities(): array { return ['vision', 'text', 'extraction']; }
            public function getCostTier(): int { return 0; }
            public function isAvailable(): bool { return true; }
            public function generate(string $p, array $c = [], array $o = []): AIResponseDTO { throw new \RuntimeException('must not be called'); }
            public function extract(string $imageRaw, float $deadline): ExtractedTradeData { throw new \RuntimeException('must not be called'); }
        };
        try {
            $router = new FeatureRouter(null, null, ['gemini' => $repo]);
            $manager = new AIManager(providers: [$repo], router: $router);
            $manager->extract('image', microtime(true) + 5, $USER_ID);
            echo json_encode(['status' => 'error', 'error' => ['code' => 'NOT_BLOCKED']]);
        } catch (\Velora\AI\Exceptions\AIConsentRequiredException $e) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'error' => ['code' => $e->errorCode()]]);
        } catch (\Throwable $dbg) {
            fwrite(STDERR, 'DBG ' . get_class($dbg) . ' :: ' . $dbg->getMessage() . "\n");
            http_response_code(500);
            echo json_encode(['status' => 'error', 'error' => ['code' => 'EXTRACTION_SETUP_ERROR']]);
        }
        exit(0);
    default:
        echo json_encode(['error' => ['code' => 'UNKNOWN_CASE']]);
        exit(1);
}
