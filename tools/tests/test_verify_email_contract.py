#!/usr/bin/env python3
"""Regression contract for email link fragment -> POST JSON body."""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FILES = [
    ROOT / "verify-email" / "index.html",
    ROOT / "localized" / "fa" / "verify-email" / "index.html",
    ROOT / "localized" / "en" / "verify-email" / "index.html",
]

for path in FILES:
    source = path.read_text(encoding="utf-8")
    rel = path.relative_to(ROOT)
    checks = {
        "reads URL fragment": "new URLSearchParams(location.hash.replace(/^#/, ''))" in source,
        "fragment token has priority": "hashParams.get('token') || queryParams.get('token')" in source,
        "removes token from query": "queryParams.delete('token')" in source,
        "clears browser fragment/history": "history.replaceState(null, document.title, cleanUrl)" in source,
        "uses canonical endpoint": "VeloraData.request('/api/v1/auth/verify-email'," in source,
        "uses POST": "method: 'POST'" in source,
        "sends real token in JSON body": "body: { token: token, notificationLocale: VeloraLocale.locale }" in source,
        "does not leak token in API URL": "/api/v1/auth/verify-email?token=" not in source,
        "does not send an empty body token": "{ token: '', cache: 'no-store' }" not in source,
    }
    failed = [name for name, ok in checks.items() if not ok]
    if failed:
        raise SystemExit(f"FAIL {rel}: {', '.join(failed)}")

print(f"Verify-email browser contract: PASS ({len(FILES)} files, 9 assertions each)")
