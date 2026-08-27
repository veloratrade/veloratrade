#!/usr/bin/env python3
"""Synthetic validator cases for Phase 1 (no live n8n articles)."""
from __future__ import annotations

import hashlib
import json
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(Path(__file__).resolve().parent))
from validate_snapshot import main as validate_main  # noqa: E402

HTML = (
    "<h1>How to use an AI trading journal</h1>"
    "<p>" + ("Traders record process, not promised returns. " * 12) + "</p>"
    "<h2>Why journals matter</h2><p>Discipline and review.</p>"
    "<h2>What it is not</h2><p>Not a broker or signal service.</p>"
    "<h2>Next step</h2><p>Keep a structured journal with Velora.</p>"
)


def sha(html: str) -> str:
    return hashlib.sha256(html.encode("utf-8")).hexdigest()


def valid(**overrides):
    body = {
        "archive_id": "a-test_article_001-111",
        "draft_id": "d-test_article_001-111",
        "slug": "ai-trading-journal-process-guide",
        "title": "How to Use an AI Trading Journal",
        "language": "en",
        "article_html": HTML,
        "metadata": {
            "meta_title": "AI Trading Journal Guide",
            "meta_description": "Learn how an AI trading journal helps traders log process and review decisions.",
            "primary_keyword": "AI trading journal",
        },
        "faq": [{"q": "Is this financial advice?", "a": "No. Educational only."}],
        "approval_status": "approved",
        "archive_status": "archived",
        "publication_status": "not_published",
        "content_sha256": sha(HTML),
        "created_at": "2026-08-27T00:00:00Z",
        "archived_at": "2026-08-27T00:00:00Z",
    }
    body.update(overrides)
    if "article_html" in overrides and "content_sha256" not in overrides:
        body["content_sha256"] = sha(overrides["article_html"])
    return body


def write(tmpdir: Path, name: str, payload) -> Path:
    path = tmpdir / name
    if isinstance(payload, str):
        path.write_text(payload, encoding="utf-8")
    else:
        path.write_text(json.dumps(payload), encoding="utf-8")
    return path


def run_case(label: str, path: Path, expect_pass: bool) -> bool:
    code = validate_main([str(path)])
    ok = (code == 0) if expect_pass else (code != 0)
    print("%s expected_%s got_%s %s" % (
        label,
        "PASS" if expect_pass else "FAIL",
        "PASS" if code == 0 else "FAIL",
        "OK" if ok else "NOT_OK",
    ))
    return ok


def main() -> int:
    tmp = Path(tempfile.mkdtemp(prefix="n8n-archive-"))
    cases = []
    cases.append(("valid", write(tmp, "valid.json", valid()), True))
    cases.append(("unapproved", write(tmp, "unapproved.json", valid(approval_status="draft")), False))
    cases.append(("unarchived", write(tmp, "unarchived.json", valid(archive_status="pending")), False))
    cases.append(("malformed", write(tmp, "malformed.json", "{not-json"), False))
    secret_html = HTML + " token=github_pat_11AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"
    cases.append(("fake_secret", write(tmp, "secret.json", valid(article_html=secret_html)), False))
    cases.append(("invalid_slug", write(tmp, "slug.json", valid(slug="AI Trading Journal!")), False))

    failed = 0
    for label, path, exp in cases:
        if not run_case(label, path, exp):
            failed += 1
    print("SUMMARY failed=%s/%s" % (failed, len(cases)))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
