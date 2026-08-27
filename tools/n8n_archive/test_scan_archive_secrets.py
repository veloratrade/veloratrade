#!/usr/bin/env python3
"""Behavioral tests for scan_archive_secrets.py.

These prove the scanner is FAIL-CLOSED for real secret material while NOT
flagging the detector's own regex definitions or synthetic test fixtures.

IMPORTANT: realistic-looking tokens are built at RUNTIME (concatenated from
parts) so that the committed test file never contains a full high-entropy token
as literal text. Otherwise the scanner would flag this file. The scanner is
still exercised on the full, concatenated token via scan_text().

Synthetic fixtures use repeated single characters (all-'A', all-'a'/'b').
Never prints real secret values.
"""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT))

from scan_archive_secrets import is_synthetic, scan_text, SECRET_PATTERNS  # noqa: E402


def need(passed: bool, label: str, failed: list[str]) -> None:
    print(f"{label}: {'OK' if passed else 'NOT_OK'}")
    if not passed:
        failed.append(label)


def main() -> int:
    failed: list[str] = []

    # ── MUST FAIL: real-looking secret material (built at runtime) ──────
    # Realistic GitHub PAT (high entropy, no repeated-run).
    real_pat = "github_pat_" + "11CIEUQLA0YYKMXNoY70ed_VQ2vrt9O8W73wexWgxhGFcdxsrB77ozycM5Bjr2yEWXPMYWUDM3ZUy1CLVg"
    need(bool(scan_text("token=" + real_pat)), "catches_real_pat", failed)

    # Realistic n8n API key.
    real_n8n = "n8n_api_" + "Kf7xQ2rT9wYbN4mZ6cL1hJ3sV8gP5dA0uE7iR2oXvYwZ9aB1c"
    need(bool(scan_text("key=" + real_n8n)), "catches_real_n8n_key", failed)

    # Realistic webhook secret.
    real_wh = "whsec_" + "kD8xNv3pQ5rT7wZ9aB1cE4fG6hJ2kL0mO8pR5sUxW7vY3"
    need(bool(scan_text("sig=" + real_wh)), "catches_real_webhook_secret", failed)

    # Realistic JWT-like secret (three high-entropy segments).
    real_jwt = ("eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9."
                "eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4ifQ."
                "K7xQ2rT9wYbN4mZ6cL1hJ3sV8gP5dA0uE7iR2oXvYwZ9aB1c")
    need(bool(scan_text("jwt=" + real_jwt)), "catches_real_jwt", failed)

    # Realistic Telegram bot token.
    real_tg = "1234567890:" + "AAHf9xK2qR8wT5yU1iE3oP7aD4sG6jL0mN2bV4cXZ"
    need(bool(scan_text("tg=" + real_tg)), "catches_real_telegram", failed)

    # Private key header (split so the file never holds the contiguous literal).
    real_pk = "-----BEGIN PRI" + "VATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASC"
    need(bool(scan_text(real_pk)), "catches_real_private_key", failed)

    # ── MUST PASS: non-secret detector/test/comment material ────────────
    # The detector's own regex definition (a pattern, not a value).
    detector_def = 'SECRET_PATTERNS = [ re.compile(r"github_pat_[A-Za-z0-9_]{10,}") ]'
    need(not scan_text(detector_def), "ignores_detector_regex_definition", failed)

    # Synthetic all-'A' fixture (single repeated char => synthetic).
    synthetic_allA = " token=github_pat_11AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    need(not scan_text(synthetic_allA), "ignores_synthetic_allA_fixture", failed)

    # Synthetic all-'a'/'b' JWT-like fixture used in tests.
    synthetic_jwt = " eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.aaaaaaaaaa.bbbbbbbbbb"
    need(not scan_text(synthetic_jwt), "ignores_synthetic_jwt_fixture", failed)

    # Synthetic all-'A' n8n/webhook fixtures.
    synthetic_n8n = " n8n_api_" + "A" * 40
    need(not scan_text(synthetic_n8n), "ignores_synthetic_n8n_fixture", failed)
    synthetic_wh = " whsec_" + "B" * 40
    need(not scan_text(synthetic_wh), "ignores_synthetic_webhook_fixture", failed)

    # Comments documenting the detector (mention pattern, not a value).
    comment = "# github_pat_ is a GitHub PAT prefix; the scanner uses it as a pattern."
    need(not scan_text(comment), "ignores_documentation_comment", failed)

    # Ordinary archive code without secret material.
    ordinary = 'article_html = "<h1>Hello</h1>"\napproval_status = "approved"'
    need(not scan_text(ordinary), "ignores_ordinary_code", failed)

    # is_synthetic unit checks.
    need(is_synthetic("github_pat_11AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"),
         "synthetic_detector_allA", failed)
    need(is_synthetic("eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.aaaaaaaaaa.bbbbbbbbbb"),
         "synthetic_detector_jwt", failed)
    need(not is_synthetic(real_pat), "real_pat_not_synthetic", failed)

    # Sanity: the scanner actually has the secret patterns defined (non-trivial).
    need(len(SECRET_PATTERNS) >= 8, "scanner_has_secret_patterns", failed)

    print("SUMMARY failed=%s %s" % (len(failed), failed))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
