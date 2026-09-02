<?php

declare(strict_types=1);

/**
 * Phase 3G — Product Session Window Specification gating.
 *
 * Correctness rules this pins:
 *   - No authoritative product windows exist in the repo -> the engine ships
 *     UNCONFIGURED and production serialization reports 'unconfigured'.
 *   - MarketSessionSpec keeps an APPROVED=false proposal; activating real
 *     labels requires explicit approval (SPEC_VERSION bump). No fake labels.
 *   - The PROPOSED windows are deterministic, IANA-referenced, DST-aware,
 *     weekday-scoped, overlap-preserving, and tz/locale/calendar-independent —
 *     validated via the existing TradingSessionEngine on the proposal engine.
 *   - Engine-only: the proposal engine is never used by production wiring.
 *   - 'LATE' is NOT defined in the proposal (requires product definition).
 *
 * NO MetaApi/n8n/frontend/DB/backend-canonical change is exercised here.
 */

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? " :: $detail" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

$root = sys_get_temp_dir() . '/velora-p3g-' . bin2hex(random_bytes(5));
@mkdir($root . '/config', 0700, true);
@mkdir($root . '/data', 0700, true);
@mkdir($root . '/logs', 0700, true);
putenv('APP_ENV=local');
putenv('VELORA_PRIVATE_ROOT=' . $root);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
file_put_contents($root . '/config/velora.env', implode("\n", [
    'APP_ENV=local', 'APP_DEBUG=true', 'DB_DRIVER=sqlite', 'DB_DATABASE=' . $root . '/data/velora.sqlite',
    'JWT_SECRET=' . str_repeat('j', 48), 'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
    'CORS_ALLOWED_ORIGINS=http://localhost', 'FRONTEND_URL=http://localhost', 'MAIL_DRIVER=log',
]) . "\n");
require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Core\Database;
use Velora\Trades\MarketSessionSpec;
use Velora\Trades\SessionWindow;
use Velora\Trades\TradingSessionEngine;
use Velora\Trades\TradeService;

// ---------------------------------------------------------------------------
// G1/G2: production stays UNCONFIGURED — no active labels.
// ---------------------------------------------------------------------------
check('G1 spec is NOT approved (APPROVED flag false)', MarketSessionSpec::APPROVED === false);
check('G2 approvedWindows() is EMPTY (no activated rules)', MarketSessionSpec::approvedWindows() === []);

$prodEngine = MarketSessionSpec::productionEngine();
check('G3 production engine has no configured windows', $prodEngine->hasConfiguredWindows() === false && $prodEngine->windowCount() === 0);

$unset = $prodEngine->classify('2026-08-31 12:00:00'); // mid London/NY overlap
check('G4 production reports unconfigured even at a real overlap instant', $unset['status'] === 'unconfigured' && $unset['sessions'] === [], $unset['status']);
check('G5 production unresolved for null canonical', $prodEngine->classify(null)['status'] === 'unresolved');

// Production serialization (TradeService default) reports unconfigured.
$pdo = Database::connection();
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, timezone TEXT DEFAULT 'UTC', locale TEXT DEFAULT 'fa')");
$pdo->exec("CREATE TABLE trading_accounts (
    id INTEGER PRIMARY KEY, user_id INTEGER, provider TEXT NOT NULL, platform TEXT NOT NULL,
    broker TEXT, server TEXT, timezone TEXT, timezone_source TEXT NOT NULL DEFAULT 'unknown',
    mt_login TEXT, account_type TEXT NOT NULL DEFAULT 'STANDARD',
    metaapi_account_id TEXT, sync_status TEXT NOT NULL DEFAULT 'DISCONNECTED',
    last_synced_at TEXT, disconnected_at TEXT, connection_credentials_encrypted TEXT,
    connected_at TEXT, auto_sync_enabled INTEGER NOT NULL DEFAULT 1,
    last_incremental_at TEXT, connection_checked_at TEXT, consecutive_errors INTEGER NOT NULL DEFAULT 0,
    last_error TEXT, starting_balance TEXT NOT NULL DEFAULT '0.00', current_balance TEXT NOT NULL DEFAULT '0.00',
    label TEXT NOT NULL DEFAULT '', account_number_masked TEXT NOT NULL DEFAULT '',
    currency TEXT NOT NULL DEFAULT 'USD', leverage TEXT, status TEXT NOT NULL DEFAULT 'disconnected',
    balance TEXT NOT NULL DEFAULT '0.00', equity TEXT NOT NULL DEFAULT '0.00',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE trades (
    id INTEGER PRIMARY KEY, user_id INTEGER, account_id INTEGER, external_deal_id TEXT,
    symbol TEXT, direction TEXT, entry_price TEXT, exit_price TEXT, volume TEXT,
    contract_size TEXT DEFAULT '1', commission TEXT DEFAULT '0', swap TEXT DEFAULT '0',
    profit_loss TEXT, r_multiple TEXT, stop_loss TEXT, take_profit TEXT,
    open_time TEXT NOT NULL, close_time TEXT NOT NULL,
    occurred_open_at_utc TEXT, occurred_close_at_utc TEXT,
    time_status TEXT NOT NULL DEFAULT 'unresolved',
    source_timezone TEXT, source_timezone_source TEXT NOT NULL DEFAULT 'unknown',
    source_calendar TEXT NOT NULL DEFAULT 'unknown',
    raw_open_text TEXT, raw_close_text TEXT,
    strategy_tag TEXT, emotional_score INTEGER, notes TEXT, source TEXT NOT NULL DEFAULT 'manual',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO users (id, email) VALUES (1, 'g3@example.test')");

$svc = new TradeService();
$created = $svc->create([
    'symbol' => 'EURUSD', 'direction' => 'buy', 'entryPrice' => '1.08', 'exitPrice' => '1.09',
    'volume' => '0.1', 'openTime' => '2026-08-31T12:00', 'closeTime' => '2026-08-31T13:00',
    'rawOpenText' => '2026-08-31 12:00', 'sourceCalendar' => 'gregorian', 'dateFormat' => 'YYYY-MM-DD',
    'timezoneOffsetHintMinutes' => 0,
], 1);
$sess = $svc->serialize($created)['session'] ?? null;
check('G6 serialized trade.session is unconfigured by default', is_array($sess) && $sess['status'] === 'unconfigured', $sess['status'] ?? 'none');

// ---------------------------------------------------------------------------
// Proposal content & validation (NOT active).
// ---------------------------------------------------------------------------
$proposed = MarketSessionSpec::proposedWindows();
$ids = array_map(fn(SessionWindow $w) => $w->id, $proposed);
sort($ids);
check('P1 proposal defines tokyo, london, newyork only', $ids === ['london', 'newyork', 'tokyo'], implode(',', $ids));
check('P2 proposal does NOT define late (requires product definition)', !in_array('late', $ids, true));
foreach ($proposed as $w) {
    check("P3 window {$w->id} uses a valid IANA zone", in_array($w->timezone, ['Asia/Tokyo', 'Europe/London', 'America/New_York'], true), $w->timezone);
    check("P4 window {$w->id} is weekday-scoped (Mon-Fri)", $w->daysOfWeek === [1, 2, 3, 4, 5]);
    check("P5 window {$w->id} carries explicit priority", is_int($w->priority) && $w->priority >= 0, (string) $w->priority);
    check("P6 window {$w->id} does NOT cross midnight (day session)", $w->endMinute > $w->startMinute);
}
check('P7 spec version is a stable date label', (bool) preg_match('/\A\d{4}\.\d{2}\.\d{2}-proposed\.\d+\z/', MarketSessionSpec::SPEC_VERSION), MarketSessionSpec::SPEC_VERSION);

// ---------------------------------------------------------------------------
// Proposal ENGINE behaviour (review-only). DST, boundaries, weekday, overlap.
// ---------------------------------------------------------------------------
$engine = MarketSessionSpec::proposedEngine();
check('E1 proposal engine is configured (proposal only)', $engine->hasConfiguredWindows() && $engine->windowCount() === 3);

function idsFor(TradingSessionEngine $e, string $utc): array
{
    $r = $e->classify($utc);
    return array_map(fn($x) => $x['id'], $r['sessions']);
}

// Monday 2026-08-31. London 08:00 BST = 07:00Z; NY 08:00 EDT = 12:00Z; Tokyo 09:00 JST = 00:00Z.
// London summer window 07:00–16:00Z; NY summer 12:00–21:00Z; Tokyo 00:00–09:00Z.
// 07:30Z summer = Tokyo 16:30 JST (still open until 18:00 JST = 09:00Z) AND
// London 08:30 BST (open). Deterministic overlap — both labels returned.
check('E2 summer Tokyo–London overlap at 07:30Z (both, not collapsed)', idsFor($engine, '2026-08-31 07:30:00') === ['tokyo', 'london'], implode(',', idsFor($engine, '2026-08-31 07:30:00')));
check('E2b London-only once Tokyo closed 09:30Z', idsFor($engine, '2026-08-31 09:30:00') === ['london'], implode(',', idsFor($engine, '2026-08-31 09:30:00')));
check('E3 summer London+NY overlap at 13:00Z (both returned, not collapsed)', idsFor($engine, '2026-08-31 13:00:00') === ['london', 'newyork'], implode(',', idsFor($engine, '2026-08-31 13:00:00')));
check('E4 summer NY alone after London close 16:30Z', idsFor($engine, '2026-08-31 16:30:00') === ['newyork'], implode(',', idsFor($engine, '2026-08-31 16:30:00')));
check('E5 summer Tokyo alone 02:00Z', idsFor($engine, '2026-08-31 02:00:00') === ['tokyo'], implode(',', idsFor($engine, '2026-08-31 02:00:00')));
check('E6 outside all 22:00Z weekday', idsFor($engine, '2026-08-31 22:00:00') === [] && $engine->classify('2026-08-31 22:00:00')['status'] === 'outside');

// Boundaries (summer): London opens exactly 07:00Z; closed 06:59Z.
check('E7 London open boundary 07:00Z included', in_array('london', idsFor($engine, '2026-08-31 07:00:00'), true));
check('E8 one minute before London open 06:59Z excluded', !in_array('london', idsFor($engine, '2026-08-31 06:59:00'), true));
// NY opens exactly 12:00Z summer; closed 11:59Z.
check('E9 NY open boundary 12:00Z included', in_array('newyork', idsFor($engine, '2026-08-31 12:00:00'), true));
check('E10 one minute before NY open 11:59Z excluded', !in_array('newyork', idsFor($engine, '2026-08-31 11:59:00'), true));
// London close 17:00 local BST = 16:00Z end-exclusive: 16:00Z closed.
check('E11 London end 16:00Z excluded (end-exclusive)', !in_array('london', idsFor($engine, '2026-08-31 16:00:00'), true));

// DST WINTER: Monday 2026-01-05. London 08:00 GMT = 08:00Z; NY 08:00 EST = 13:00Z; Tokyo 09:00 JST = 00:00Z.
check('E12 winter London opens 08:00Z (DST dynamic, not 07:00)', in_array('london', idsFor($engine, '2026-01-05 08:00:00'), true));
check('E13 winter London closed 07:59Z', !in_array('london', idsFor($engine, '2026-01-05 07:59:00'), true));
check('E14 winter NY opens 13:00Z (EST)', in_array('newyork', idsFor($engine, '2026-01-05 13:00:00'), true));
check('E15 winter NY not open at summer-equivalent 12:00Z', !in_array('newyork', idsFor($engine, '2026-01-05 12:00:00'), true));

// Weekday rules: Sunday 2026-08-30 13:00Z (would be London+NY on a weekday).
check('E16 Sunday is outside (no weekday session)', $engine->classify('2026-08-30 13:00:00')['sessions'] === [], $engine->classify('2026-08-30 13:00:00')['status']);
// Sunday/Monday transition: 2026-08-31 00:30Z is Monday Tokyo (JST 09:30 Mon).
check('E17 Monday Tokyo open 00:30Z (Sunday→Monday transition in Z)', in_array('tokyo', idsFor($engine, '2026-08-31 00:30:00'), true));

// Cross-midnight capability still works in the engine (independent of proposal).
$cross = new TradingSessionEngine([
    ['id' => 'late-demo', 'label' => 'Late demo', 'timezone' => 'America/New_York', 'start' => '22:00', 'end' => '02:00'],
]);
check('E18 cross-midnight 03:00Z (NY 23:00) inside', in_array('late-demo', array_map(fn($x) => $x['id'], $cross->classify('2026-08-31 03:00:00')['sessions']), true));
check('E19 cross-midnight 07:30Z (NY 03:30) outside', !in_array('late-demo', array_map(fn($x) => $x['id'], $cross->classify('2026-08-31 07:30:00')['sessions']), true));

// Invalid/unsafe config fails loudly.
$bad = false;
try { new SessionWindow('x', 'X', 'EST', '08:00', '16:00'); } catch (\Throwable) { $bad = true; }
check('E20 EST reference zone rejected (must be IANA)', $bad);

// TZ-independence: same canonical instant -> same sessions under any PHP tz.
$ok = true; $base = null;
foreach (['UTC', 'Europe/London', 'America/New_York', 'Asia/Tehran', 'Asia/Tokyo'] as $z) {
    date_default_timezone_set($z);
    $r = $engine->classify('2026-08-31 13:00:00');
    $k = json_encode([$r['status'], array_map(fn($x) => $x['id'], $r['sessions'])]);
    if ($base === null) { $base = $k; } elseif ($k !== $base) { $ok = false; }
}
date_default_timezone_set('UTC');
check('E21 session result independent of PHP default timezone', $ok);

// Engine versions.
check('E22 engine exposes algorithm version', $engine->engineVersion() === TradingSessionEngine::ENGINE_VERSION);
check('E23 unconfigured classification carries engineVersion', isset($unset['engineVersion']) && $unset['engineVersion'] === TradingSessionEngine::ENGINE_VERSION);

echo "\n" . ($failures === 0 ? "ALL PASS" : "$failures FAIL") . "\n";
exit($failures === 0 ? 0 : 1);
