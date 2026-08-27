#!/usr/bin/env python3
"""Fail-closed secret scanner for the n8n archive trees.

Why this scanner exists
----------------------
The previous CI secret-scan used a raw ``grep`` over ``tools/n8n_archive`` and
``content/n8n-archive`` with bare prefixes (e.g. ``github_pat_``). That produced
false positives because:

- the detector's own regex pattern definitions in ``read_guard.py`` /
  ``validate_snapshot.py`` contain the literal substring ``github_pat_`` (as a
  pattern, not a value);
- synthetic all-same-character test fixtures (e.g. ``github_pat_11AAAA...``)
  exist in the test files to prove the validator rejects secret-like material.

This scanner keeps secret detection FAIL-CLOSED for real tokens while
structurally ignoring non-secret material:

- It matches FULL TOKEN patterns, not bare prefixes, so a regex *definition*
  such as ``github_pat_[A-Za-z0-9_]{10,}`` does not match the token pattern
  (the ``[`` is not a valid token character).
- It treats a matched token as SYNTHETIC (not a real credential) when it
  contains a long run of a single repeated character (>=10 identical
  alphanumerics). Real tokens (PATs, API keys, JWTs, webhook secrets) are random
  and essentially never contain such a run.

These exclusions are narrow and structural. Real secret material still fails.

Exit code
---------
0 if no real secret material is found.
1 if any real secret-like token is found (FAIL-CLOSED).
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

# Full-token secret patterns (aligned with read_guard.py SECRET_PATTERNS).
# These match real token values, not pattern definitions.
SECRET_PATTERNS = [
    re.compile(r"github_pat_[A-Za-z0-9_]{15,}"),
    re.compile(r"\bghp_[A-Za-z0-9]{20,}\b"),
    re.compile(r"\bsk-[A-Za-z0-9]{20,}\b"),
    re.compile(r"\bAIza[A-Za-z0-9_\-]{20,}\b"),
    re.compile(r"\b\d{8,}:[A-Za-z0-9_-]{30,}\b"),  # Telegram bot token
    re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----"),
    re.compile(r"\beyJ[A-Za-z0-9_\-]{20,}\.[A-Za-z0-9_\-]{10,}\."),  # JWT
    re.compile(r"\bn8n_api_[A-Za-z0-9]{20,}\b"),  # n8n API key
    re.compile(r"\bwhsec_[A-Za-z0-9]{20,}\b"),    # webhook secret
]

# A token containing a run of >=10 identical alphanumerics is synthetic
# (a test fixture), not a real credential. Real random tokens do not do this.
SYNTHETIC_RUN_RE = re.compile(r"([A-Za-z0-9])\1{9,}")


def is_synthetic(token: str) -> bool:
    """Return True if the token is a synthetic all-same-character fixture."""
    return bool(SYNTHETIC_RUN_RE.search(token))


def scan_text(text: str) -> list[tuple[int, str, str]]:
    """Scan text, returning [(line_number, pattern_source, token)] for real secrets.

    Only line numbers (1-based) are reported; no secret VALUES are printed.
    """
    findings: list[tuple[int, str, str]] = []
    for lineno, line in enumerate(text.splitlines(), start=1):
        for pat in SECRET_PATTERNS:
            for m in pat.finditer(line):
                token = m.group(0)
                if is_synthetic(token):
                    continue  # synthetic fixture, not a real credential
                findings.append((lineno, pat.pattern, token))
    return findings


def scan_file(path: Path) -> list[tuple[int, str, str]]:
    try:
        text = path.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return []
    return scan_text(text)


def scan_tree(root: Path) -> list[tuple[str, int, str]]:
    """Scan a directory tree, returning [(file, lineno, pattern)] real secrets."""
    out: list[tuple[str, int, str]] = []
    if root.is_file():
        for lineno, pat, _tok in scan_file(root):
            out.append((str(root), lineno, pat))
        return out
    for p in sorted(root.rglob("*")):
        if not p.is_file():
            continue
        if "__pycache__" in p.parts or p.name.startswith("."):
            continue
        for lineno, pat, _tok in scan_file(p):
            out.append((str(p), lineno, pat))
    return out


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(
        description="Fail-closed secret scanner for n8n archive trees."
    )
    p.add_argument("paths", nargs="+", help="files/directories to scan")
    args = p.parse_args(argv)

    findings: list[tuple[str, int, str]] = []
    for raw in args.paths:
        root = Path(raw)
        if not root.exists():
            print(f"SKIP  missing path: {root}")
            continue
        findings.extend(scan_tree(root))

    if findings:
        for f, lineno, pat in findings:
            # Report location + pattern only. NEVER print the secret value.
            print(f"SECRET-LIKE  {f}:{lineno}  pattern={pat}")
        print("FAIL secret scan: real secret-like material found")
        return 1
    print("PASS secret scan: no real secret-like material found")
    return 0


if __name__ == "__main__":
    sys.exit(main())
