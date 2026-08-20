<?php

declare(strict_types=1);

/**
 * TEST-11 (Audit BUG-A2) — Reset Password Token Security Contract.
 *
 * A password-reset capability token must NEVER travel in the URL query string:
 * query parameters leak into web-server access logs, proxy logs, browser
 * history, and Referer headers. The verify-email flow already uses the URL
 * fragment plus a history.replaceState scrub; the reset flow must match.
 *
 * Contract under test (static, deterministic, no services, no secrets):
 *   1. PasswordService must build the reset link with #token= (fragment).
 *   2. reset-password/index.html must read the token from location.hash.
 *   3. reset-password/index.html must scrub the URL via history.replaceState.
 *   4. Control group: verify-email page already complies (must stay green).
 *   5. Token hygiene in PasswordService: random_bytes(32) + sha256-only storage.
 *
 * Pins BUG-A2: assertions 1-3 are RED until the fix lands.
 */

$repoRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = [];

$check = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$passwordService = (string) file_get_contents($repoRoot . '/api/src/Auth/PasswordService.php');
$resetPage = (string) file_get_contents($repoRoot . '/reset-password/index.html');
$verifyPage = (string) file_get_contents($repoRoot . '/verify-email/index.html');

// --- BUG-A2 pins (red until fixed) ----------------------------------------
$check(
    str_contains($passwordService, "/reset-password#token="),
    'PasswordService must deliver the reset token in the URL fragment (/reset-password#token=), not the query string (BUG-A2)',
);
$check(
    !str_contains($passwordService, 'reset-password?token='),
    'PasswordService must not place the reset token in the URL query (?token= leaks to logs/history)',
);
$check(
    str_contains($resetPage, 'location.hash'),
    'reset-password page must read the token from location.hash (fragment), not location.search',
);
$check(
    str_contains($resetPage, 'history.replaceState'),
    'reset-password page must scrub the token from the URL via history.replaceState (like verify-email does)',
);

// --- Control group: verify-email compliance (must stay green) --------------
$check(
    str_contains($verifyPage, 'location.hash'),
    'control: verify-email page reads the token from the fragment',
);
$check(
    str_contains($verifyPage, 'history.replaceState'),
    'control: verify-email page scrubs the token via history.replaceState',
);

// --- Token hygiene (green pins) --------------------------------------------
$check(
    str_contains($passwordService, 'random_bytes(32)'),
    'reset token must be generated from random_bytes(32) (256-bit entropy)',
);
$check(
    str_contains($passwordService, "hash('sha256'"),
    'only the sha256 hash of the reset token may be persisted',
);

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    fwrite(STDERR, 'TEST-11 FAILED: ' . count($failures) . "/{$assertions} assertions failed\n");
    exit(1);
}
echo "TEST-11 PASS ({$assertions} assertions)\n";
exit(0);
