#!/usr/bin/env python3
"""Synthetic ingest tests — no live n8n, no Production, no real GitHub App."""
from __future__ import annotations

import hashlib
import hmac
import json
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT))

from github_writer import GitHubAppWriter  # noqa: E402
from ingest import MAX_BODY_BYTES, ingest  # noqa: E402

SECRET = "test-hmac-secret-not-for-production"
NOW = 1_700_000_000
HTML = "<h1>How to use an AI trading journal</h1><p>" + ("Process over promises. " * 16) + "</p>"
FA_HTML = "<h1>ژورنال معاملاتی</h1><p>" + ("متن معتبر برای طول کافی. " * 16) + "</p>"


def sign(body: bytes, ts: int = NOW) -> tuple[str, str]:
    message = f"{ts}.".encode("ascii") + body
    sig = hmac.new(SECRET.encode(), message, hashlib.sha256).hexdigest()
    return str(ts), sig


def n8n_row(**over) -> dict:
    row = {
        "archive_id": "a-test_article_001-111",
        "draft_id": "d-test_article_001-111",
        "slug": "ai-trading-journal-process-guide",
        "title": "How to Use an AI Trading Journal",
        "language": "en",
        "article_html": HTML,
        "meta_title": "AI Trading Journal Guide",
        "meta_description": "Learn how an AI trading journal helps traders log process and review decisions.",
        "faq_json": json.dumps([{"q": "Advice?", "a": "No. Educational only."}]),
        "approval_status": "APPROVED",
        "archive_status": "ARCHIVED",
        "publication_status": "NOT_PUBLISHED",
        "approved_at": "2026-08-27T00:00:00Z",
        "archived_at": "2026-08-27T00:01:00Z",
    }
    row.update(over)
    return row


def run(label, body, *, expect_ok, expect_code=None, extra=None, github=None, replay_dir=None, snapshots=None):
    extra = extra or {}
    tmp = Path(tempfile.mkdtemp(prefix="ingest-"))
    snapshots = snapshots or (tmp / "snapshots")
    replay = replay_dir or (tmp / "replay")
    if isinstance(body, dict):
        raw = json.dumps(body).encode("utf-8")
    elif isinstance(body, str):
        raw = body.encode("utf-8")
    else:
        raw = body
    ts, sig = sign(raw, extra.get("ts", NOW))
    if extra.get("sig") is not None:
        sig = extra["sig"]
    if extra.get("ts_header") is not None:
        ts = extra["ts_header"]
    result = ingest(
        raw_body=raw,
        signature=sig,
        timestamp=str(ts),
        hmac_secret=extra.get("secret", SECRET),
        snapshots_dir=snapshots,
        replay_dir=replay,
        github=github,
        now=extra.get("now", NOW),
    )
    ok = result.get("ok") is True
    passed = ok == expect_ok
    if expect_code and result.get("code") != expect_code:
        passed = False
    print(
        f"{label}: ok={result.get('ok')} code={result.get('code')} "
        f"expect_ok={expect_ok} {'OK' if passed else 'NOT_OK'}"
    )
    if not passed:
        print("  detail", {k: result.get(k) for k in ("code", "error", "http_status")})
    return passed, result, snapshots, replay


class MockGitHub(GitHubAppWriter):
    def __init__(self):
        self.calls: list[tuple[str, str]] = []
        super().__init__(owner="veloratrade", repo="veloratrade", token="t", http=self._http)
        self.files: dict[str, str] = {}
        self.branches = {"main": "abc123"}
        self.prs: list[dict] = []

    def _http(self, url, method, headers, body):
        self.calls.append((method, url))
        path = url.split("github.com")[-1]
        payload = json.loads(body.decode()) if body else {}
        if method == "GET" and path.endswith("/veloratrade/veloratrade"):
            return 200, {"default_branch": "main"}
        if method == "GET" and "/git/ref/heads/main" in path:
            return 200, {"object": {"sha": "abc123"}}
        if method == "GET" and "/git/ref/heads/n8n-archive/" in path:
            return 404, {}
        if method == "POST" and path.endswith("/git/refs"):
            return 201, {"ref": payload.get("ref")}
        if method == "PUT" and "/contents/" in path:
            self.files[path] = payload.get("content", "")
            return 201, {"content": {"path": "ok"}}
        if method == "POST" and path.endswith("/pulls"):
            pr = {"html_url": "https://github.com/veloratrade/veloratrade/pull/999", "number": 999}
            self.prs.append(pr)
            return 201, pr
        return 500, {"message": "unhandled " + method + path}


def main() -> int:
    failed = 0

    def need(ok: bool) -> None:
        nonlocal failed
        if not ok:
            failed += 1

    ok, res, snaps, replay = run("valid_uppercase", n8n_row(), expect_ok=True)
    need(ok)
    if ok:
        stored = json.loads((snaps / "a-test_article_001-111.json").read_text())
        if stored["approval_status"] != "approved" or stored["archive_status"] != "archived":
            print("normalization mapping NOT_OK", stored["approval_status"], stored["archive_status"])
            failed += 1
        else:
            print("normalization APPROVED/ARCHIVED -> lowercase OK")

    ok, _, _, _ = run("invalid_hmac", n8n_row(), expect_ok=False, expect_code="HMAC_FAILED", extra={"sig": "0" * 64})
    need(ok)
    ok, _, _, _ = run(
        "expired_ts",
        n8n_row(),
        expect_ok=False,
        expect_code="TIMESTAMP_EXPIRED",
        extra={"ts": NOW - 1000, "now": NOW},
    )
    need(ok)

    ok1, _, snaps, replay = run("replay_first", n8n_row(archive_id="a-replay-1"), expect_ok=True)
    need(ok1)
    ok2, res2, _, _ = run(
        "replay_second",
        n8n_row(archive_id="a-replay-1"),
        expect_ok=False,
        expect_code="REPLAY",
        extra={},
        snapshots=snaps,
        replay_dir=replay,
    )
    # second call is identical HMAC of identical body -> replay
    need(ok2)

    ok, _, snaps, _ = run("unapproved", n8n_row(approval_status="draft"), expect_ok=False)
    need(ok)
    ok, _, _, _ = run("unarchived", n8n_row(archive_status="pending"), expect_ok=False)
    need(ok)
    ok, _, _, _ = run("missing_archive_id", n8n_row(archive_id=""), expect_ok=False)
    need(ok)
    ok, _, _, _ = run("malformed_json", "{", expect_ok=False, expect_code="INVALID_JSON")
    need(ok)
    ok, _, _, _ = run("oversized", b"x" * (MAX_BODY_BYTES + 1), expect_ok=False, expect_code="PAYLOAD_TOO_LARGE")
    need(ok)
    ok, _, _, _ = run(
        "telegram_field",
        n8n_row(telegram_chat_id="-1003960311602"),
        expect_ok=False,
        expect_code="FORBIDDEN_FIELD",
    )
    need(ok)
    ok, _, _, _ = run(
        "jwt_secret",
        n8n_row(article_html=HTML + " eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.aaaaaaaaaa.bbbbbbbbbb"),
        expect_ok=False,
    )
    need(ok)

    # invalid sha in incoming payload is ignored; ingest recomputes. Should PASS.
    ok, _, _, _ = run("incoming_wrong_sha_recomputed", n8n_row(content_sha256="0" * 64), expect_ok=True)
    need(ok)

    ok, first, snaps, replay = run("dup_first", n8n_row(archive_id="a-dup-1"), expect_ok=True)
    need(ok)
    # identical duplicate is noop (new HMAC because... same body same ts -> REPLAY)
    # use different timestamp so HMAC differs but body same
    raw = json.dumps(n8n_row(archive_id="a-dup-1")).encode()
    ts, sig = sign(raw, NOW + 1)
    res = ingest(
        raw_body=raw,
        signature=sig,
        timestamp=ts,
        hmac_secret=SECRET,
        snapshots_dir=snaps,
        replay_dir=replay,
        now=NOW + 1,
    )
    print(f"duplicate_identical: ok={res.get('ok')} noop={res.get('noop')} code={res.get('code')}")
    if not (res.get("ok") and res.get("noop")):
        failed += 1
        print("  NOT_OK expected noop")

    changed = n8n_row(archive_id="a-dup-1", article_html=HTML + "<p>changed</p>")
    raw2 = json.dumps(changed).encode()
    ts2, sig2 = sign(raw2, NOW + 2)
    res = ingest(
        raw_body=raw2,
        signature=sig2,
        timestamp=ts2,
        hmac_secret=SECRET,
        snapshots_dir=snaps,
        replay_dir=replay,
        now=NOW + 2,
    )
    print(f"duplicate_different_content: ok={res.get('ok')} code={res.get('code')}")
    if res.get("ok") or res.get("code") != "ARCHIVE_ID_CONFLICT":
        failed += 1
        print("  NOT_OK expected ARCHIVE_ID_CONFLICT")

    ok, res, snaps, _ = run(
        "persian_unicode",
        n8n_row(
            archive_id="a-fa-1",
            language="fa",
            title="ژورنال معاملاتی هوش مصنوعی برای تریدرها",
            article_html=FA_HTML,
            slug="zhornal-moamelati",
        ),
        expect_ok=True,
    )
    need(ok)

    mock = MockGitHub()
    ok, res, _, _ = run(
        "github_mock_pr",
        n8n_row(archive_id="a-gh-1"),
        expect_ok=True,
        github=mock,
    )
    need(ok)
    methods = [c[0] for c in mock.calls]
    print("github_calls", methods)
    if not mock.prs or "PUT" not in methods or "POST" not in methods:
        print("github mock PR NOT_OK")
        failed += 1
    else:
        print("github mock branch+snapshot+PR OK (main not used as write target)")

    print("SUMMARY failed=%s" % failed)
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
