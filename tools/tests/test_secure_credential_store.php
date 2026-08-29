<?php

declare(strict_types=1);

/**
 * SecureCredentialStore tests: allowlist enforcement, status/replace/delete,
 * backup, permissions, fail-closed behavior, and secret hygiene (values never
 * appear in exceptions, logs, or return values).
 *
 * Run: php tools/tests/test_secure_credential_store.php
 */

$root = sys_get_temp_dir() . '/velora-credstore-test-' . bin2hex(random_bytes(5));
mkdir($root . '/config', 0700, true);
mkdir($root . '/data', 0700, true);
putenv('APP_ENV=local');
putenv('VELORA_PRIVATE_ROOT=' . $root);
putenv('VELORA_DOCUMENT_ROOT=' . dirname(__DIR__, 2));
putenv('DB_DRIVER=sqlite');
putenv('DB_DATABASE=' . $root . '/data/velora.sqlite');
file_put_contents($root . '/config/velora.env', implode("\n", [
    'APP_ENV=local', 'APP_DEBUG=true', 'DB_DRIVER=sqlite',
    'DB_DATABASE=' . $root . '/data/velora.sqlite',
    'JWT_SECRET=' . str_repeat('j', 48),
    'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
    'GEMINI_API_KEY=pre-existing-key-value',
    'CORS_ALLOWED_ORIGINS=http://localhost', 'FRONTEND_URL=http://localhost',
    'MAIL_DRIVER=log',
]) . "\n");

// Capture error_log output for hygiene assertions.
$logFile = $root . '/php-error.log';
ini_set('error_log', $logFile);

require dirname(__DIR__, 2) . '/api/src/bootstrap.php';

use Velora\Core\Config;
use Velora\Core\SecureCredentialStore;

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

$SECRET = 'sk-test-secret-value-DO-NOT-LEAK-0123456789';

echo "== Allowlist enforcement ==\n";
check(SecureCredentialStore::isManageable('GEMINI_API_KEY'), 'GEMINI_API_KEY is manageable');
check(SecureCredentialStore::isManageable('OPENAI_API_KEY'), 'OPENAI_API_KEY is manageable');
check(SecureCredentialStore::isManageable('ANTHROPIC_API_KEY'), 'ANTHROPIC_API_KEY is manageable');
check(!SecureCredentialStore::isManageable('JWT_SECRET'), 'JWT_SECRET is NOT manageable (non-credential)');
check(!SecureCredentialStore::isManageable('DB_PASS'), 'DB_PASS is NOT manageable');

$rejected = false;
try {
    SecureCredentialStore::replace('JWT_SECRET', 'x');
} catch (RuntimeException $e) {
    $rejected = true;
    check(strpos($e->getMessage(), 'x') === false, 'rejection message contains no value');
}
check($rejected, 'non-allowlisted key rejected');

echo "== Status (boolean only, fresh read) ==\n";
check(SecureCredentialStore::status('GEMINI_API_KEY') === true, 'existing key reports configured=true');
check(SecureCredentialStore::status('OPENAI_API_KEY') === false, 'absent key reports configured=false');

echo "== Replace: atomic + backup + permission + no echo ==\n";
$replaced = SecureCredentialStore::replace('OPENAI_API_KEY', $SECRET);
check($replaced === true, 'replace returns true');
check(SecureCredentialStore::status('OPENAI_API_KEY') === true, 'status flips to configured after replace');
$envContent = (string) file_get_contents($root . '/config/velora.env');
check(strpos($envContent, 'OPENAI_API_KEY=' . $SECRET) !== false, 'value persisted in the private env file only');
check(strpos($envContent, 'GEMINI_API_KEY=pre-existing-key-value') !== false, 'other keys preserved');
check(is_file($root . '/config/velora.env.bak'), 'backup created before replacement');
check((fileperms($root . '/config/velora.env') & 0o077) === 0, 'env file permissions tightened to 0600-class');
check(Config::env('OPENAI_API_KEY', '') === $SECRET, 'Config cache refreshed — runtime sees new credential');

echo "== Value format validation (fail-closed) ==\n";
foreach (['', "with\nnewline", 'has"quote', str_repeat('a', 5000)] as $bad) {
    $ok = false;
    try {
        SecureCredentialStore::replace('ANTHROPIC_API_KEY', $bad);
    } catch (RuntimeException) {
        $ok = true;
    }
    check($ok, 'malformed value rejected: ' . substr(json_encode($bad), 0, 20));
}
check(SecureCredentialStore::status('ANTHROPIC_API_KEY') === false, 'rejected values never persisted');

echo "== Delete ==\n";
$deleted = SecureCredentialStore::delete('OPENAI_API_KEY');
check($deleted === true, 'delete returns true');
check(SecureCredentialStore::status('OPENAI_API_KEY') === false, 'status flips to configured=false after delete');
$envContent = (string) file_get_contents($root . '/config/velora.env');
check(strpos($envContent, $SECRET) === false, 'value removed from env file after delete');
check(SecureCredentialStore::delete('OPENAI_API_KEY') === true, 'deleting an absent key is a safe no-op');

echo "== Secret hygiene: logs and exceptions never carry the value ==\n";
$logContent = is_file($logFile) ? (string) file_get_contents($logFile) : '';
check(strpos($logContent, $SECRET) === false, 'error_log contains no credential value');

$rm = static function (string $p) use (&$rm): void {
    if (!is_dir($p)) { @unlink($p); return; }
    foreach (scandir($p) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $rm($p . '/' . $f);
    }
    @rmdir($p);
};
$rm($root);

echo "\nsecure-credential-store: " . ($failures === 0 ? 'PASS' : 'FAIL') . " ($checks checks, $failures failures)\n";
exit($failures === 0 ? 0 : 1);
