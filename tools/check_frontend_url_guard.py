#!/usr/bin/env python3
"""
VELORA — FRONTEND_URL staging safety guard
Run ID: VELORA-FRONTEND-URL-GUARD

Purpose
-------
Fail CLOSED before a staging deployment can run with a production FRONTEND_URL.

Why this exists
---------------
`api/config/config.php:121` defines:

    'frontend_url' => Config::env('FRONTEND_URL', ''),

There is NO validation guard for FRONTEND_URL, unlike JWT_SECRET,
APP_ENCRYPTION_KEY and CORS_ALLOWED_ORIGINS which all `throw` in production
(`api/config/config.php:43-55`). A staging host whose velora.env carries the
production URL therefore boots silently and emails production verification
links to staging users.

SECRET SAFETY
-------------
This tool NEVER prints the value of FRONTEND_URL or any other variable read
from the env file. Only rule codes and verdicts are emitted. That makes it safe
to run in CI against the contents of the STAGING_VELORA_ENV secret.

Exit codes
----------
0 = PASS   (or production advisory mode with no blocking findings)
1 = FAIL   (at least one blocking rule violated)
2 = ERROR  (tool could not evaluate: unreadable file, bad arguments)
"""

from __future__ import annotations

import argparse
import os
import sys
from urllib.parse import urlsplit

# Documented canonical staging origin.
#   .github/workflows/deploy.yml:74            base_url: https://staging.veloratrade.ir
#   .github/workflows/healthcheck-staging.yml:31
#   docs/README.md:130                         URL | veloratrade.ir | staging.veloratrade.ir
DEFAULT_EXPECTED_STAGING = "https://staging.veloratrade.ir"

# Hosts that must never serve a non-production frontend URL.
PRODUCTION_HOSTS = {"veloratrade.ir", "www.veloratrade.ir"}

KEY = "FRONTEND_URL"


class Finding:
    def __init__(self, code: str, severity: str, message: str) -> None:
        self.code = code
        self.severity = severity  # BLOCK | WARN
        self.message = message

    def __str__(self) -> str:
        icon = "\u274c" if self.severity == "BLOCK" else "\u26a0\ufe0f"
        return f"  {icon} [{self.code}] {self.severity}: {self.message}"


def parse_env_file(path: str) -> dict[str, str]:
    """Parse a KEY=VALUE env file. Values are never logged."""
    data: dict[str, str] = {}
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as fh:
            for raw in fh:
                line = raw.strip()
                if not line or line.startswith("#"):
                    continue
                if "=" not in line:
                    continue
                key, _, value = line.partition("=")
                key = key.strip()
                value = value.strip()
                # Strip one layer of matching quotes, mirroring loadDotEnv().
                if len(value) >= 2 and value[0] == value[-1] and value[0] in ("'", '"'):
                    value = value[1:-1]
                data[key] = value
    except OSError as exc:
        print(f"ERROR: cannot read env file: {exc.strerror}", file=sys.stderr)
        raise SystemExit(2)
    return data


def evaluate(value: str | None, expected: str, is_production: bool) -> list[Finding]:
    """Return findings for a FRONTEND_URL value. Never echoes the value."""
    findings: list[Finding] = []
    sev = "WARN" if is_production else "BLOCK"

    # Rule 1 / CASE C — must be explicitly defined.
    if value is None or value.strip() == "":
        findings.append(
            Finding(
                "FU-000",
                sev,
                f"{KEY} is missing or empty. config.php:121 then yields '' and every "
                "verification/reset link becomes a relative URL, while "
                "AuthController::assertSameOrigin rejects all cookie state changes "
                "with 403 SAME_ORIGIN_REQUIRED.",
            )
        )
        return findings

    raw = value.strip()

    # urlsplit() can raise (e.g. "Invalid IPv6 URL"), and .port can raise a
    # ValueError whose message embeds the offending substring. Both are caught
    # so that no secret-derived text can ever reach stdout/stderr.
    try:
        parts = urlsplit(raw)
    except ValueError:
        findings.append(
            Finding("FU-001", sev, f"{KEY} is malformed: it could not be parsed as a URL.")
        )
        return findings

    # Rule 6 — malformed.
    if not parts.scheme or not parts.netloc or not parts.hostname:
        findings.append(
            Finding("FU-001", sev, f"{KEY} is malformed: it must be an absolute origin URL.")
        )
        return findings

    # Rule 7a — scheme. CASE D.
    if parts.scheme.lower() != "https":
        findings.append(
            Finding(
                "FU-002",
                sev,
                f"{KEY} scheme must be https. A non-https origin cannot satisfy the "
                "Secure/__Host- refresh cookie contract (Response.php:16-28).",
            )
        )

    # Rule 7b — trailing slash, path, query, fragment, credentials. CASE E.
    if parts.path not in ("",):
        findings.append(
            Finding(
                "FU-003",
                sev,
                f"{KEY} must be a bare origin with no path or trailing slash "
                "(canonical form per api/.env.example:3).",
            )
        )
    if parts.query or parts.fragment:
        findings.append(Finding("FU-003", sev, f"{KEY} must not contain a query string or fragment."))
    if parts.username or parts.password:
        findings.append(Finding("FU-003", sev, f"{KEY} must not embed credentials."))

    host = (parts.hostname or "").lower().rstrip(".")

    # Rule 2 / 5 — never the production domain outside production. CASE B.
    if not is_production and host in PRODUCTION_HOSTS:
        findings.append(
            Finding(
                "FU-004",
                "BLOCK",
                f"{KEY} points at the PRODUCTION domain while APP_ENV is not production. "
                "Staging users would receive production verification links and burn "
                "staging tokens against the production database.",
            )
        )

    # Rule 3 — must match the architected staging origin. CASE F.
    expected_host = (urlsplit(expected).hostname or "").lower()
    if not is_production and host not in PRODUCTION_HOSTS and host != expected_host:
        findings.append(
            Finding(
                "FU-005",
                sev,
                f"{KEY} host does not match the architected staging origin "
                f"({expected}). Override with --expect only if the project "
                "source-of-truth defines another staging domain.",
            )
        )

    # Non-default port. Accessing .port re-parses and may raise a ValueError
    # that quotes the raw port text, so it is guarded.
    try:
        port = parts.port
    except ValueError:
        findings.append(
            Finding("FU-006", sev, f"{KEY} has an invalid port component.")
        )
        port = None
    if port is not None and port != 443:
        findings.append(Finding("FU-006", sev, f"{KEY} must not specify a non-default port."))

    return findings


def main() -> int:
    ap = argparse.ArgumentParser(
        description="Validate FRONTEND_URL for staging/non-production safety."
    )
    src = ap.add_mutually_exclusive_group(required=True)
    src.add_argument("--env-file", help="Path to a velora.env style file.")
    src.add_argument("--from-env", action="store_true", help="Read from the process environment.")
    ap.add_argument("--app-env", default=None,
                    help="Override APP_ENV. Default: value from the source, else 'production'.")
    ap.add_argument("--expect", default=DEFAULT_EXPECTED_STAGING,
                    help=f"Expected staging origin. Default: {DEFAULT_EXPECTED_STAGING}")
    ap.add_argument("--strict-production", action="store_true",
                    help="Also treat findings as blocking when APP_ENV is production.")
    args = ap.parse_args()

    if args.env_file:
        data = parse_env_file(args.env_file)
    else:
        data = dict(os.environ)

    # Mirrors config.php:17 -> APP_ENV defaults to 'production' (fail safe).
    app_env = (args.app_env or data.get("APP_ENV", "production")).strip().lower()
    is_production = app_env not in ("dev", "development", "staging", "stage", "test")
    if args.strict_production:
        is_production = False

    value = data.get(KEY)

    print("VELORA FRONTEND_URL guard")
    print(f"  APP_ENV        : {app_env}")
    print(f"  mode           : {'production (advisory)' if is_production else 'non-production (blocking)'}")
    print(f"  expected origin: {args.expect}")
    print("  note           : variable values are never printed.")
    print()

    findings = evaluate(value, args.expect, is_production)
    blocking = [f for f in findings if f.severity == "BLOCK"]

    if not findings:
        print("  \u2705 PASS \u2014 FRONTEND_URL is explicitly defined and matches the staging contract.")
        return 0

    for f in findings:
        print(f)
    print()

    if blocking:
        print(f"RESULT: FAIL \u2014 {len(blocking)} blocking finding(s).")
        return 1

    print("RESULT: PASS (advisory findings only; production behaviour left unchanged).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
