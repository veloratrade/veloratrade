#!/usr/bin/env python3
"""Validate a public n8n archive snapshot (no extra dependencies)."""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path
from typing import Any

SLUG_RE = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")
ARCHIVE_ID_RE = re.compile(r"^a-[A-Za-z0-9_-]{1,64}$")
DRAFT_ID_RE = re.compile(r"^[A-Za-z0-9_-]{1,80}$")
SHA_RE = re.compile(r"^[a-f0-9]{64}$")
ISOISH_RE = re.compile(r"^\d{4}-\d{2}-\d{2}")

FORBIDDEN_KEYS = {
    "telegram_chat_id",
    "telegram_thread_id",
    "telegram_user_id",
    "telegram_chat",
    "callback_query_id",
    "bot_token",
    "jwt",
    "n8n_jwt",
    "password",
    "api_key",
    "apikey",
    "authorization",
    "oauth_token",
    "access_token",
    "private_key",
    "ftp_password",
    "db_pass",
}

SECRET_PATTERNS = [
    re.compile(r"github_pat_[A-Za-z0-9_]{10,}"),
    re.compile(r"ghp_[A-Za-z0-9]{20,}"),
    re.compile(r"\beyJ[A-Za-z0-9_\-]{20,}\.[A-Za-z0-9_\-]{10,}\."),
    re.compile(r"sk-[A-Za-z0-9]{20,}"),
    re.compile(r"AIza[A-Za-z0-9_\-]{20,}"),
    re.compile(r"\b\d{8,}:[A-Za-z0-9_-]{30,}\b"),  # Telegram bot token
    re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----"),
]

TELEGRAM_CHAT_RE = re.compile(r"(?<!\d)-100\d{8,14}(?!\d)")
USER_ID_FIELD_RE = re.compile(r"(telegram|operator|user)_id", re.I)

REQUIRED = [
    "archive_id",
    "draft_id",
    "slug",
    "title",
    "language",
    "article_html",
    "metadata",
    "faq",
    "approval_status",
    "archive_status",
    "publication_status",
    "content_sha256",
    "created_at",
    "archived_at",
]


def walk_keys(obj: Any, acc: list[str]) -> None:
    if isinstance(obj, dict):
        for k, v in obj.items():
            acc.append(str(k))
            walk_keys(v, acc)
    elif isinstance(obj, list):
        for item in obj:
            walk_keys(item, acc)


def walk_strings(obj: Any, acc: list[str]) -> None:
    if isinstance(obj, dict):
        for v in obj.values():
            walk_strings(v, acc)
    elif isinstance(obj, list):
        for item in obj:
            walk_strings(item, acc)
    elif isinstance(obj, str):
        acc.append(obj)


def validate(data: Any) -> list[str]:
    errors: list[str] = []
    if not isinstance(data, dict):
        return ["root must be a JSON object"]

    extra = set(data.keys()) - {
        "archive_id",
        "draft_id",
        "slug",
        "title",
        "language",
        "article_html",
        "metadata",
        "faq",
        "approval_status",
        "archive_status",
        "publication_status",
        "content_sha256",
        "created_at",
        "archived_at",
        "opportunity_id",
        "archive_version",
    }
    if extra:
        errors.append("unexpected fields: " + ", ".join(sorted(extra)))

    keys: list[str] = []
    walk_keys(data, keys)
    for k in keys:
        kl = k.lower()
        if kl in FORBIDDEN_KEYS or kl.startswith("telegram"):
            errors.append("forbidden identifier field: " + k)
        if USER_ID_FIELD_RE.search(k):
            errors.append("forbidden identifier field: " + k)

    strings: list[str] = []
    walk_strings(data, strings)
    blob = "\n".join(strings)
    for pat in SECRET_PATTERNS:
        if pat.search(blob):
            errors.append("possible secret/token material in snapshot")
            break
    if TELEGRAM_CHAT_RE.search(blob):
        errors.append("possible Telegram chat identifier in snapshot")

    for field in REQUIRED:
        if field not in data:
            errors.append("missing required field: " + field)

    if errors:
        return errors

    aid = data["archive_id"]
    if not isinstance(aid, str) or not ARCHIVE_ID_RE.match(aid):
        errors.append("invalid archive_id")
    did = data["draft_id"]
    if not isinstance(did, str) or not DRAFT_ID_RE.match(did):
        errors.append("invalid draft_id")
    slug = data["slug"]
    if not isinstance(slug, str) or not SLUG_RE.match(slug):
        errors.append("invalid slug")
    title = data["title"]
    if not isinstance(title, str) or not (8 <= len(title) <= 200):
        errors.append("invalid title")
    if data.get("language") not in ("fa", "en"):
        errors.append("language must be fa or en")
    html = data.get("article_html")
    if not isinstance(html, str) or len(html) < 200:
        errors.append("article_html missing or too short")
    if data.get("approval_status") != "approved":
        errors.append("approval_status must be approved")
    if data.get("archive_status") != "archived":
        errors.append("archive_status must be archived")
    if data.get("publication_status") not in ("not_published", "published", "failed"):
        errors.append("invalid publication_status")
    sha = data.get("content_sha256")
    if not isinstance(sha, str) or not SHA_RE.match(sha):
        errors.append("invalid content_sha256")
    elif isinstance(html, str):
        digest = hashlib.sha256(html.encode("utf-8")).hexdigest()
        if digest != sha:
            errors.append("content_sha256 does not match article_html")
    for ts in ("created_at", "archived_at"):
        v = data.get(ts)
        if not isinstance(v, str) or not ISOISH_RE.match(v):
            errors.append("invalid " + ts)

    meta = data.get("metadata")
    if not isinstance(meta, dict):
        errors.append("metadata must be an object")
    else:
        mt = meta.get("meta_title")
        md = meta.get("meta_description")
        if not isinstance(mt, str) or not (8 <= len(mt) <= 70):
            errors.append("invalid metadata.meta_title")
        if not isinstance(md, str) or not (20 <= len(md) <= 180):
            errors.append("invalid metadata.meta_description")
        extra_m = set(meta.keys()) - {
            "meta_title",
            "meta_description",
            "excerpt",
            "primary_keyword",
            "canonical_path",
        }
        if extra_m:
            errors.append("unexpected metadata fields: " + ", ".join(sorted(extra_m)))

    faq = data.get("faq")
    if not isinstance(faq, list):
        errors.append("faq must be an array")
    else:
        if len(faq) > 12:
            errors.append("faq too long")
        for i, item in enumerate(faq):
            if not isinstance(item, dict) or "q" not in item or "a" not in item:
                errors.append("faq[%s] must have q and a" % i)
                break

    return errors


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Validate n8n archive snapshot JSON")
    parser.add_argument("path", type=Path)
    args = parser.parse_args(argv)
    raw = args.path.read_text(encoding="utf-8")
    try:
        data = json.loads(raw)
    except json.JSONDecodeError as exc:
        print("FAIL invalid_json:", exc)
        return 1
    errors = validate(data)
    if errors:
        print("FAIL")
        for err in errors:
            print("-", err)
        return 1
    print("PASS", args.path.name)
    return 0


if __name__ == "__main__":
    sys.exit(main())
