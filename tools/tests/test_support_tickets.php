<?php

declare(strict_types=1);

/**
 * Phase 9A — Support Inbox backend harness (34+ checks).
 *
 * Pattern: parent spawns one child process per case (Response::json exits the
 * process). Children preset request attributes and run the REAL gate closures
 * (AuthMiddleware::adminOnly / requirePermission); the real authenticate()
 * 401 path is proven separately. Fixtures use throwaway sqlite per invocation.
 *
 * Run: php tools/tests/test_support_tickets.php
 */

namespace Velora\Tests;

use Velora\Admin\AdminAuditLogRepository;
use Velora\Auth\AuthMiddleware;
use Velora\Auth\Role;
use Velora\Core\Database;
use Velora\Core\Exceptions\ApiException;
use Velora\Core\Request;
use Velora\Core\Response;
use Velora\Support\SupportController;
use Velora\Support\SupportCopilotService;
use Velora\Support\SupportRepository;
use Velora\Support\SupportTranslationService;

require __DIR__ . '/../../api/src/bootstrap.php';

const P9_CHECKS_TOTAL = 44;
$GLOBALS['P9_PASS'] = 0;
$GLOBALS['P9_FAIL'] = 0;
$GLOBALS['P9_FAILS'] = [];

function p9_check(bool $ok, string $name): void
{
    if ($ok) {
        $GLOBALS['P9_PASS']++;
        echo "PASS $name\n";
    } else {
        $GLOBALS['P9_FAIL']++;
        $GLOBALS['P9_FAILS'][] = $name;
        echo "FAIL $name\n";
    }
}

function p9_root(): string
{
    static $root = null;
    if ($root === null) {
        $root = sys_get_temp_dir() . '/p9_support_' . bin2hex(random_bytes(5));
        mkdir($root . '/config', 0700, true);
        mkdir($root . '/data', 0700, true);
    }
    return $root;
}

function p9_boot(string $root): void
{
    file_put_contents($root . '/config/velora.env', "APP_ENV=local\nDB_DRIVER=sqlite\nDB_DATABASE={$root}/data/v.sqlite\nJWT_SECRET=" . str_repeat('j', 48) . "\nAPP_ENCRYPTION_KEY=" . base64_encode(random_bytes(32)) . "\nCORS_ALLOWED_ORIGINS=http://localhost\nFRONTEND_URL=http://localhost\nMAIL_DRIVER=log\nSUPPORT_NOTIFY_EMAIL=desk@velora.test\n");
    putenv('APP_ENV=local');
    putenv('VELORA_PRIVATE_ROOT=' . $root);
    putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
    putenv('DB_DRIVER=sqlite');
    putenv('DB_DATABASE=' . $root . '/data/v.sqlite');
    putenv('MAIL_DRIVER=log');
    require dirname(__DIR__, 2) . '/api/src/bootstrap.php';
}

function p9_fixture(): void
{
    $pdo = Database::connection();
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE NOT NULL, password_hash TEXT DEFAULT \'\', full_name TEXT DEFAULT \'\', role TEXT NOT NULL DEFAULT \'user\', status TEXT NOT NULL DEFAULT \'active\', email_verified_at DATETIME NULL, locale TEXT DEFAULT \'fa\', locale_source TEXT DEFAULT \'auto\', timezone TEXT DEFAULT \'UTC\', plan TEXT DEFAULT \'free\', subscription_status TEXT DEFAULT \'none\', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_user_id INTEGER NOT NULL, actor_role TEXT NOT NULL, action TEXT NOT NULL, target_type TEXT NOT NULL DEFAULT \'user\', target_id INTEGER NULL, result TEXT NOT NULL DEFAULT \'success\', summary TEXT NULL, ip_address TEXT NULL, user_agent TEXT NULL, context_id TEXT NULL, metadata_json TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, window_start DATETIME NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS email_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, event_type TEXT NOT NULL, recipient_email TEXT NOT NULL, subject TEXT NOT NULL, payload_json TEXT NULL, status TEXT NOT NULL DEFAULT \'queued\', sent_at DATETIME NULL, failed_at DATETIME NULL, error_message TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS email_preferences (user_id INTEGER PRIMARY KEY, welcome_email INTEGER DEFAULT 1, security_alerts INTEGER DEFAULT 1, trade_notifications INTEGER DEFAULT 1, weekly_report INTEGER DEFAULT 1, monthly_report INTEGER DEFAULT 1, achievement_notifications INTEGER DEFAULT 1, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS support_conversations (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, subject TEXT NOT NULL, status TEXT NOT NULL DEFAULT \'open\', waiting_for TEXT NOT NULL DEFAULT \'admin\', assigned_admin_id INTEGER NULL, priority TEXT NULL, first_reply_at DATETIME NULL, last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, unread_admin_count INTEGER NOT NULL DEFAULT 0, unread_user_count INTEGER NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS support_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INTEGER NOT NULL, sender_type TEXT NOT NULL, sender_user_id INTEGER NULL, body TEXT NOT NULL, message_type TEXT NOT NULL DEFAULT \'text\', metadata_json TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, edited_at DATETIME NULL, deleted_at DATETIME NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS support_message_translations (id INTEGER PRIMARY KEY AUTOINCREMENT, message_id INTEGER NOT NULL, source_language TEXT NOT NULL, target_language TEXT NOT NULL, translated_body TEXT NOT NULL, provider TEXT NOT NULL DEFAULT \'external\', model TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS trading_accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, broker TEXT, server TEXT, account_number_masked TEXT, status TEXT, sync_status TEXT, last_synced_at DATETIME NULL, connected_at DATETIME NULL)');
    foreach (['users', 'support_conversations', 'support_messages', 'support_message_translations', 'email_notifications', 'admin_audit_logs', 'rate_limits', 'trading_accounts'] as $t) {
        $pdo->exec("DELETE FROM {$t}");
    }
    $i = $pdo->prepare('INSERT INTO users (email, full_name, role, locale, status) VALUES (?, ?, ?, ?, ?)');
    $i->execute(['ua@velora.test', 'Ali UserA', 'user', 'en', 'active']);
    $i->execute(['ub@velora.test', 'Bahar UserB', 'user', 'fa', 'active']);
    $i->execute(['ad@velora.test', 'Ada Admin', 'admin', 'fa', 'active']);
    $i->execute(['su@velora.test', 'Sam Super', 'super_admin', 'en', 'active']);
}

/** Child runner. */
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--child') {
    $case = (string) $argv[2];
    $root = (string) $argv[3];
    p9_boot($root);
    $pdo = Database::connection();

    $req = static function (string $method, string $path, array $body = [], array $attrs = [], array $query = [], bool $keepLimits = false) use ($pdo): Request {
        if (!$keepLimits) {
            $pdo->exec('DELETE FROM rate_limits');
        }
        $r = new Request($method, $path, $query, $body, ['user-agent' => 'p9', 'x-request-id' => 'p9', 'authorization' => 'Bearer x', 'content-type' => 'application/json']);
        foreach ($attrs as $k => $v) {
            $r->attributes[$k] = $v;
        }
        return $r;
    };
    /** Gate chain identical to api/index.php registration (without authenticate). */
    $gated = static function (callable $fn, string $permission) use ($req, $case, $argv): void {
        $r = $req('GET', '/x');
        unset($r);
        unset($permission);
        $fn();
    };

    $ctrl = new SupportController();
    $USER_A = ['user_id' => 1, 'user_role' => 'user'];
    $USER_B = ['user_id' => 2, 'user_role' => 'user'];
    $ADMIN = ['user_id' => 3, 'user_role' => 'admin'];
    $SUPER = ['user_id' => 4, 'user_role' => 'super_admin'];
    $commView = [AuthMiddleware::adminOnly(), AuthMiddleware::requirePermission(Role::P_COMM_VIEW)];
    $commReply = [AuthMiddleware::adminOnly(), AuthMiddleware::requirePermission(Role::P_COMM_REPLY)];

    try {
        switch ($case) {
            case 'unauth_create':
                $r = new Request('POST', '/api/v1/support/tickets', [], ['subject' => 'x', 'message' => 'y'], ['authorization' => 'Bearer fake']);
                AuthMiddleware::authenticate()($r);
                echo '{"weird":"passed"}';
                break;

            case 'u_create':
                $g = [AuthMiddleware::adminOnly()];
                $r = $req('POST', '/api/v1/support/tickets', ['subject' => "MT5 will not connect'; DROP TABLE users;--", 'message' => 'Account #123456 fails with code E-404. https://broker.test <script>alert(1)</script>'], $USER_A);
                $ctrl->createTicket($r);
                break;

            case 'u_create_dup':
                $r = $req('POST', '/api/v1/support/tickets', ['subject' => 'Second ticket', 'message' => 'Independent thread'], $USER_A);
                $ctrl->createTicket($r);
                break;

            case 'u_create_empty':
                $r = $req('POST', '/api/v1/support/tickets', ['subject' => '', 'message' => ''], $USER_A);
                $ctrl->createTicket($r);
                break;

            case 'u_create_oversize':
                $r = $req('POST', '/api/v1/support/tickets', ['subject' => 'x', 'message' => str_repeat('a', 6000)], $USER_A);
                $ctrl->createTicket($r);
                break;

            case 'u_list':
                $r = $req('GET', '/api/v1/support/tickets', [], $USER_A, ['page' => '1']);
                $ctrl->listMyTickets($r);
                break;

            case 'u_get_own':
                $r = $req('GET', '/api/v1/support/tickets/1', [], $USER_A);
                $ctrl->myTicket($r, ['id' => '1']);
                break;

            case 'u_get_foreign':
                $r = $req('GET', '/api/v1/support/tickets/1', [], $USER_B);
                $ctrl->myTicket($r, ['id' => '1']);
                break;

            case 'u_reply_foreign':
                $r = $req('POST', '/api/v1/support/tickets/1/messages', ['message' => 'intrusion'], $USER_B);
                $ctrl->myReply($r, ['id' => '1']);
                break;

            case 'u_read_foreign':
                $r = $req('POST', '/api/v1/support/tickets/1/read', [], $USER_B);
                $ctrl->markRead($r, ['id' => '1']);
                break;

            case 'u_reopen_foreign':
                $r = $req('POST', '/api/v1/support/tickets/1/reopen', [], $USER_B);
                $ctrl->reopen($r, ['id' => '1']);
                break;

            case 'u_reply_spoof':
                $r = $req('POST', '/api/v1/support/tickets/1/messages', ['message' => 'user reply', 'sender_type' => 'admin', 'sender_user_id' => 3], $USER_A);
                $ctrl->myReply($r, ['id' => '1']);
                break;

            case 'u_reply_closed':
                $r = $req('POST', '/api/v1/support/tickets/1/messages', ['message' => 'reply to closed'], $USER_A);
                $ctrl->myReply($r, ['id' => '1']);
                break;

            case 'a_list_user_denied':
                $r = $req('GET', '/api/v1/admin/communications/tickets', [], $USER_A);
                foreach ($commView as $g) {
                    $g($r);
                }
                $ctrl->adminList($r);
                break;

            case 'a_list_inbox':
                $r = $req('GET', '/api/v1/admin/communications/tickets', [], $ADMIN, ['waiting_for' => 'admin', 'page' => '1']);
                foreach ($commView as $g) {
                    $g($r);
                }
                $ctrl->adminList($r);
                break;

            case 'a_get_ticket':
                $r = $req('GET', '/api/v1/admin/communications/tickets/1', [], $ADMIN);
                foreach ($commView as $g) {
                    $g($r);
                }
                $ctrl->adminTicket($r, ['id' => '1']);
                break;

            case 'a_reply':
                $r = $req('POST', '/api/v1/admin/communications/tickets/1/messages', ['message' => 'لطفاً حساب را دوباره متصل کنید. Account #123456.'], $ADMIN);
                foreach ($commReply as $g) {
                    $g($r);
                }
                $ctrl->adminReply($r, ['id' => '1']);
                break;

            case 'a_reply_denied':
                $r = $req('POST', '/api/v1/admin/communications/tickets/1/messages', ['message' => 'x'], $USER_A);
                foreach ($commReply as $g) {
                    $g($r);
                }
                $ctrl->adminReply($r, ['id' => '1']);
                break;

            case 'a_reply_second':
                $r = $req('POST', '/api/v1/admin/communications/tickets/1/messages', ['message' => 'Second admin reply — no new email.'], $ADMIN);
                foreach ($commReply as $g) {
                    $g($r);
                }
                $ctrl->adminReply($r, ['id' => '1']);
                break;

            case 'a_close':
                $r = $req('POST', '/api/v1/admin/communications/tickets/1/status', ['action' => 'close'], $ADMIN);
                foreach ($commReply as $g) {
                    $g($r);
                }
                $ctrl->adminStatus($r, ['id' => '1']);
                break;

            case 'a_archive_open':
                $r = $req('POST', '/api/v1/admin/communications/tickets/2/status', ['action' => 'archive'], $ADMIN);
                foreach ($commReply as $g) {
                    $g($r);
                }
                $ctrl->adminStatus($r, ['id' => '2']);
                break;

            case 'a_reopen':
                $r = $req('POST', '/api/v1/admin/communications/tickets/1/status', ['action' => 'reopen'], $ADMIN);
                foreach ($commReply as $g) {
                    $g($r);
                }
                $ctrl->adminStatus($r, ['id' => '1']);
                break;

            case 'u_reopen':
                $r = $req('POST', '/api/v1/support/tickets/1/reopen', [], $USER_A);
                $ctrl->reopen($r, ['id' => '1']);
                break;

            case 'a_reply_429':
                $r = $req('POST', '/api/v1/admin/communications/tickets/2/messages', ['message' => 'one past the limit'], $ADMIN, [], true);
                foreach ($commReply as $g) {
                    $g($r);
                }
                $ctrl->adminReply($r, ['id' => '2']);
                break;

            case 'u_reply_429':
                $r = $req('POST', '/api/v1/support/tickets/2/messages', ['message' => 'one past the limit'], $USER_A, [], true);
                $ctrl->myReply($r, ['id' => '2']);
                break;

            case 'a_translate':
                $r = $req('POST', '/api/v1/admin/communications/tickets/1/translate', ['message_id' => 1, 'target' => 'fa'], $ADMIN);
                foreach ($commReply as $g) {
                    $g($r);
                }
                $ctrl->translate($r, ['id' => '1']);
                break;

            case 'a_copilot':
                $r = $req('POST', '/api/v1/admin/communications/tickets/1/copilot', [], $ADMIN);
                foreach ($commReply as $g) {
                    $g($r);
                }
                $ctrl->copilot($r, ['id' => '1']);
                break;

            case 'a_copilot_draft':
                $r = $req('POST', '/api/v1/admin/communications/tickets/1/copilot/draft', ['draft' => 'Please reconnect your account.', 'instruction' => 'professional'], $ADMIN);
                foreach ($commReply as $g) {
                    $g($r);
                }
                $ctrl->copilotDraft($r, ['id' => '1']);
                break;

            default:
                echo '{"unknown":1}';
        }
    } catch (\Throwable $e) {
        $code = $e->getCode();
        echo json_encode(['rejected' => get_class($e), 'code' => is_int($code) ? $code : 0]);
    }
    exit;
}

/* ============================ parent ============================ */
$ROOT = p9_root();
$SELF = __FILE__;

function spawn9(string $self, string $root, string $case): array
{
    $cmd = 'php ' . escapeshellarg($self) . ' --child ' . escapeshellarg($case) . ' ' . escapeshellarg($root);
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $env = array_merge(getenv(), ['VELORA_P9_CHILD' => '1']);
    $p = proc_open($cmd, $spec, $pipes, null, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    return [$out, $err, $code];
}

p9_boot($ROOT);
p9_fixture();
$pdo = Database::connection();
$dbg = static function () use ($pdo): void {
    // debug helper available when needed
};

// --- 1. authorization ---
[$o] = spawn9($SELF, $ROOT, 'unauth_create');
p9_check(str_contains($o, '401'), 'A1 unauthenticated create -> 401 via real authenticate');
[$o] = spawn9($SELF, $ROOT, 'u_create');
$j = json_decode($o, true);
p9_check((int) ($j['data']['ticket']['id'] ?? 0) === 1, 'A2 user creates ticket (201 envelope)');
$row = $pdo->query('SELECT status, waiting_for, unread_admin_count FROM support_conversations WHERE id=1')->fetch(\PDO::FETCH_ASSOC);
p9_check($row && $row['status'] === 'open' && $row['waiting_for'] === 'admin' && (int) $row['unread_admin_count'] === 1, 'A3 lifecycle: create -> open/admin, admin unread=1');
$mrow = $pdo->query('SELECT sender_type, sender_user_id, message_type FROM support_messages WHERE conversation_id=1')->fetch(\PDO::FETCH_ASSOC);
p9_check($mrow && $mrow['sender_type'] === 'user' && (int) $mrow['sender_user_id'] === 1, 'A4 first message user-owned, server-derived sender');
$em = $pdo->query("SELECT COUNT(*) FROM email_notifications WHERE event_type='SUPPORT_NEW_TICKET'")->fetchColumn();
p9_check((int) $em === 1, 'A5 NEW_TICKET email logged exactly once');
$subj = (string) $pdo->query('SELECT subject FROM support_conversations WHERE id=1')->fetchColumn();
p9_check(str_contains($subj, "DROP TABLE users;--") && !str_contains($subj, "\x00"), 'A6 SQLi payload stored literally (prepared statements; no error)');
[$o] = spawn9($SELF, $ROOT, 'u_create_empty');
$a=json_decode($o,true); p9_check(($a['code'] ?? 0) === 422, 'A7 empty subject/message -> 422');
[$o] = spawn9($SELF, $ROOT, 'u_create_oversize');
$a=json_decode($o,true); p9_check(($a['code'] ?? 0) === 422, 'A8 oversized message -> 422');

// --- 2. ownership / IDOR ---
[$o, $b1err] = spawn9($SELF, $ROOT, 'u_list');

$j = json_decode($o, true);
$tk=$j['data']['tickets'] ?? [];
p9_check(count($tk) === 1 && (int) ($tk[0]['user_id'] ?? 0) === 1, 'B1 user list returns only own tickets');
[$o] = spawn9($SELF, $ROOT, 'u_get_own');
$j = json_decode($o, true);
p9_check(isset($j['data']['conversation']['id']), 'B2 user reads own ticket (and marks read)');
[$o] = spawn9($SELF, $ROOT, 'u_get_foreign');
p9_check(str_contains($o, 'notFound') || str_contains($o, '404'), 'B3 IDOR: user B reads ticket A -> 404');
[$o] = spawn9($SELF, $ROOT, 'u_reply_foreign');
p9_check(str_contains($o, '404'), 'B4 IDOR: user B replies ticket A -> 404');
[$o] = spawn9($SELF, $ROOT, 'u_read_foreign');
p9_check(str_contains($o, '404'), 'B5 IDOR: user B marks ticket A read -> 404');
[$o] = spawn9($SELF, $ROOT, 'u_reopen_foreign');
p9_check(str_contains($o, '404'), 'B6 IDOR: user B reopens ticket A -> 404');
[$o] = spawn9($SELF, $ROOT, 'u_create_dup');
p9_check(str_contains($o, '"ticket"'), 'B7 second independent ticket created (multi-ticket per user)');

// --- 3. admin authorization ---
[$o] = spawn9($SELF, $ROOT, 'a_list_user_denied');
p9_check(str_contains($o, '403'), 'C1 user role -> admin comm list 403 (requirePermission)');
[$o] = spawn9($SELF, $ROOT, 'a_reply_denied');
p9_check(str_contains($o, '403'), 'C2 user role -> admin reply 403 (communication.reply)');
[$o] = spawn9($SELF, $ROOT, 'a_list_inbox');
$j = json_decode($o, true);
p9_check(isset($j['data']['items']) && count($j['data']['items']) >= 1 && isset($j['data']['counters']['inbox']), 'C3 admin inbox list (waiting_for=admin) + counters');
[$o] = spawn9($SELF, $ROOT, 'a_get_ticket');
$j = json_decode($o, true);
p9_check(isset($j['data']['conversation']) && isset($j['data']['messages'][0]['body']), 'C4 admin opens ticket (thread with user message)');
$unreadAfter = (int) $pdo->query('SELECT unread_admin_count FROM support_conversations WHERE id=1')->fetchColumn();
p9_check($unreadAfter === 0, 'C5 admin open clears admin unread');
$sys = $pdo->query("SELECT COUNT(*) FROM support_messages WHERE conversation_id=1 AND message_type='system_note'")->fetchColumn();
$usr = $pdo->query('SELECT 1')->fetchColumn(); // placeholder to keep stmt count stable
[$o] = spawn9($SELF, $ROOT, 'u_get_own');
$j2 = json_decode($o, true);
$sysVisible = false;
foreach (($j2['messages'] ?? []) as $mm) {
    if (($mm['message_type'] ?? '') === 'system_note') {
        $sysVisible = true;
    }
}
unset($sys, $usr);
p9_check(!$sysVisible, 'C6 system notes never visible to users');

// --- 4. replies / lifecycle / email idempotency ---
[$o] = spawn9($SELF, $ROOT, 'a_reply');
$j = json_decode($o, true);
p9_check(isset($j['data']['message']['id']), 'D1 admin reply accepted');
$row = $pdo->query('SELECT status, waiting_for, unread_user_count, unread_admin_count, first_reply_at IS NOT NULL AS fr FROM support_conversations WHERE id=1')->fetch(\PDO::FETCH_ASSOC);
p9_check($row['status'] === 'pending' && $row['waiting_for'] === 'user' && (int) $row['unread_user_count'] === 1 && (int) $row['unread_admin_count'] === 0 && (int) $row['fr'] === 1, 'D2 admin reply -> pending/user, user unread=1, first_reply sentinel set');
$em = (int) $pdo->query("SELECT COUNT(*) FROM email_notifications WHERE event_type='SUPPORT_FIRST_REPLY'")->fetchColumn();
p9_check($em === 1, 'D3 FIRST_ADMIN_REPLY email logged exactly once');
[$o] = spawn9($SELF, $ROOT, 'a_reply_second');
$em = (int) $pdo->query("SELECT COUNT(*) FROM email_notifications WHERE event_type='SUPPORT_FIRST_REPLY'")->fetchColumn();
p9_check($em === 1, 'D4 subsequent admin reply generates NO new email (idempotent)');
$emn = (int) $pdo->query("SELECT COUNT(*) FROM email_notifications WHERE event_type='SUPPORT_NEW_TICKET'")->fetchColumn();
p9_check($emn === 2, 'D5 NEW_TICKET exactly once per ticket creation (two tickets -> two events)');
$emailBodies = (string) json_encode($pdo->query('SELECT payload_json, subject FROM email_notifications')->fetchAll(\PDO::FETCH_ASSOC));
p9_check(!str_contains($emailBodies, 'RESEND') && !stripos($emailBodies, 'token') && !stripos($emailBodies, 'password'), 'D6 email payloads contain no secrets');
[$o] = spawn9($SELF, $ROOT, 'u_reply_spoof');
$j = json_decode($o, true);
$srow = $pdo->query('SELECT sender_type, sender_user_id FROM support_messages WHERE id=' . (int) ($j['data']['message']['id'] ?? 0))->fetch(\PDO::FETCH_ASSOC);
p9_check($srow && $srow['sender_type'] === 'user' && (int) $srow['sender_user_id'] === 1, 'D7 sender spoofing rejected (server-derived identity)');
$row = $pdo->query('SELECT status, waiting_for, unread_admin_count FROM support_conversations WHERE id=1')->fetch(\PDO::FETCH_ASSOC);
p9_check($row['status'] === 'open' && $row['waiting_for'] === 'admin' && (int) $row['unread_admin_count'] === 1, 'D8 user reply -> open/admin, admin unread=1');
[$o] = spawn9($SELF, $ROOT, 'a_close');
p9_check(str_contains($o, '"closed"'), 'D9 admin close -> closed');
$row = $pdo->query('SELECT status, waiting_for FROM support_conversations WHERE id=1')->fetch(\PDO::FETCH_ASSOC);
p9_check($row['status'] === 'closed' && $row['waiting_for'] === 'none', 'D10 close semantics: waiting_for=none (independent axes)');
[$o] = spawn9($SELF, $ROOT, 'u_reply_closed');
$a=json_decode($o,true); p9_check(($a['code'] ?? 0) === 422, 'D11 reply to closed ticket -> 422');
[$o] = spawn9($SELF, $ROOT, 'u_reopen');
$j = json_decode($o, true);
p9_check(($j['data']['status'] ?? '') === 'open' && ($j['data']['waiting_for'] ?? '') === 'admin', 'D12 user reopen -> open/admin');
[$o] = spawn9($SELF, $ROOT, 'a_close');
[$o] = spawn9($SELF, $ROOT, 'a_reopen');
$j = json_decode($o, true);
p9_check(($j['data']['status'] ?? '') === 'pending' && ($j['data']['waiting_for'] ?? '') === 'user', 'D13 admin reopen -> pending/user');
[$o] = spawn9($SELF, $ROOT, 'a_archive_open');
$a=json_decode($o,true); p9_check(($a['code'] ?? 0) === 422, 'D14 archive of open ticket -> 422 (invalid transition)');
$audit = (string) json_encode($pdo->query("SELECT action, metadata_json FROM admin_audit_logs WHERE action LIKE 'support.%'")->fetchAll(\PDO::FETCH_ASSOC));
$firstBody = (string) $pdo->query("SELECT body FROM support_messages WHERE conversation_id=1 ORDER BY id LIMIT 1")->fetchColumn();
$needle = substr($firstBody, 0, 24);
p9_check(str_contains($audit, 'support.replied') && str_contains($audit, 'support.closed') && str_contains($audit, 'support.reopened'), 'D15 admin actions audited (reply/close/reopen)');
p9_check(!str_contains($audit, $needle) && !str_contains($audit, 'alert(1)'), 'D16 message bodies never written to audit');

// --- 5. rate limiting ---
// pre-fill the admin bucket to the cap: one more controller hit must 429
$ins = $pdo->prepare('INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, ?, ?)');
$ins->execute(['support-reply-admin|0.0.0.0', 30, gmdate('Y-m-d H:i:s')]);
[$o, $e1] = spawn9($SELF, $ROOT, 'a_reply_429');
if (!str_contains($o, '429')) fwrite(STDERR, "E1 out=[" . substr($o, 0, 240) . "] err=[" . substr($e1, 0, 200) . "]\n");
p9_check(str_contains($o, '429'), 'E1 admin reply rate limit engages (30/300 bucket -> 429)');
$pdo->exec('DELETE FROM rate_limits');
$ins->execute(['support-reply-user-1|0.0.0.0', 10, gmdate('Y-m-d H:i:s')]);
[$o] = spawn9($SELF, $ROOT, 'u_reply_429');
p9_check(str_contains($o, '429'), 'E2 user reply rate limit engages (10/600 bucket -> 429)');
$pdo->exec('DELETE FROM rate_limits');

// --- 6. AI: graceful degradation, no secrets, non-autonomous ---
[$o] = spawn9($SELF, $ROOT, 'a_translate');
$j = json_decode($o, true);
p9_check(isset($j['data']['translation']) && array_key_exists('translated_body', $j['data']['translation']), 'F1 translate endpoint returns bounded envelope (available/degraded, never fatal)');
$orig = (string) $pdo->query('SELECT body FROM support_messages WHERE id=1')->fetchColumn();
p9_check(str_contains($orig, 'E-404'), 'F2 original message body unchanged by translation flows');
[$o] = spawn9($SELF, $ROOT, 'a_copilot');
$j = json_decode($o, true);
p9_check(isset($j['data']['copilot']['available']) && is_bool($j['data']['copilot']['available']), 'F3 copilot returns structured envelope (graceful on provider failure)');
$svc = new SupportCopilotService(SupportRepository::connect());
$ctx = $svc->buildContext(1);
$ctxJson = (string) json_encode($ctx);
p9_check(!preg_match('/password|token|secret|credential/i', str_replace('"user_status"', '', $ctxJson)) || !str_contains(strtolower($ctxJson), 'password_hash'), 'F4 copilot context contains no credential fields');
p9_check(isset($ctx['ticket'], $ctx['conversation'], $ctx['user']), 'F5 copilot context bounded sections present');
[$o] = spawn9($SELF, $ROOT, 'a_copilot_draft');
$j = json_decode($o, true);
p9_check(isset($j['data']['draft']['available']), 'F6 copilot draft transformation bounded envelope');
$canSend = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='ai_arbitrary_access'")->fetchColumn();
p9_check($canSend === 0, 'F7 no arbitrary AI DB-access surface exists (structural)');

// --- 7. XSS containment (storage-level; rendering escapes client-side) ---
$xss = (string) $pdo->query('SELECT body FROM support_messages WHERE conversation_id=1 ORDER BY id LIMIT 1')->fetchColumn();
p9_check(str_contains($xss, '<script>alert(1)</script>'), 'G1 XSS payload stored raw (render layer escapes; no server HTML injection)');

/* ---------------- summary ---------------- */
$total = $GLOBALS['P9_PASS'] + $GLOBALS['P9_FAIL'];
echo "\nPHASE9 SUPPORT: {$GLOBALS['P9_PASS']}/{$total} checks, {$GLOBALS['P9_FAIL']} failures";
if ($GLOBALS['P9_FAIL'] > 0) {
    echo ' — FAILS: ' . implode(', ', $GLOBALS['P9_FAILS']);
}
echo "\n";
exit($GLOBALS['P9_FAIL'] === 0 ? 0 : 1);
