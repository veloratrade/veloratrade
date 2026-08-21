<?php

declare(strict_types=1);

/**
 * TEST-25 — Security Headers Contract.
 *
 * Repo-verifiable (deterministic, offline) layer of the security-header story:
 *   1. API JSON responses must carry X-Content-Type-Options: nosniff.
 *   2. The locale-router denial path must send a restrictive CSP
 *      (default-src 'none'; frame-ancestors 'none' — framing/embedding dead).
 *   3. The CSP guard tooling must exist and be wired: deterministic checker +
 *      csp-guard workflow callable from deploy pipelines (workflow_call).
 *   4. Secret-scan job must exist in the guard workflow.
 *
 * HSTS and X-Frame-Options for HTML pages are enforced at the edge/web-server
 * layer (verified live during the Phase-1 audit on prod+staging) and are out
 * of reach of an offline repo test — recorded here as INFO, not asserted.
 *
 * GREEN pin: everything asserted holds today — this test blocks regressions.
 * Deterministic: static source contract only. No services, no DB, no secrets.
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

// 1. nosniff on API JSON responses (both success and error paths).
$response = (string) file_get_contents($repoRoot . '/api/src/Core/Response.php');
$check(
    substr_count($response, "X-Content-Type-Options: nosniff") >= 2,
    'Response.php must emit X-Content-Type-Options: nosniff on both success and error JSON paths',
);

// 2. Restrictive CSP on the router denial path.
$router = (string) file_get_contents($repoRoot . '/locale-router.php');
$check(
    str_contains($router, "Content-Security-Policy: default-src 'none'"),
    "locale-router denial path must send a restrictive CSP (default-src 'none')",
);
$check(
    str_contains($router, "frame-ancestors 'none'"),
    "denial responses must forbid framing (frame-ancestors 'none')",
);

// 3. CSP guard toolchain: checker + workflow with deploy-callable trigger.
$check(
    is_file($repoRoot . '/tools/localization/build_csp_artifacts.py'),
    'deterministic CSP checker (tools/localization/build_csp_artifacts.py) must exist',
);
$guard = (string) @file_get_contents($repoRoot . '/.github/workflows/csp-guard.yml');
$check(
    $guard !== '' && str_contains($guard, 'workflow_call'),
    'csp-guard workflow must exist and be callable from deploy pipelines (workflow_call)',
);
$check(
    str_contains($guard, 'build_csp_artifacts.py --check'),
    'csp-guard workflow must run the deterministic CSP consistency check',
);

// 4. Secret-scan job in the guard workflow.
$check(
    str_contains($guard, 'secret-scan'),
    'csp-guard workflow must include the secret-scan job',
);

fwrite(STDOUT, "INFO: HSTS + X-Frame-Options for HTML pages are edge-enforced (live-verified in Phase-1 audit); "
    . "they are covered by the Release Checklist smoke probe, not by this offline test.\n");

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
if ($failures !== []) {
    fwrite(STDERR, 'TEST-25 FAILED: ' . count($failures) . "/{$assertions} assertions failed\n");
    exit(1);
}
echo "TEST-25 PASS ({$assertions} assertions)\n";
exit(0);
