#!/usr/bin/env python3
"""Structure sync — scan the whole project and keep the machine-readable
structure index (inside docs/03_PROJECT_STRUCTURE_BASELINE.md) in sync.

Owner-approved model:
  - `Structure.pdf` is the permanent human source, updated per release.
    THIS SCRIPT NEVER TOUCHES THE PDF.
  - `docs/03_PROJECT_STRUCTURE_BASELINE.md` holds a machine-readable index block
    (between VELORA_STRUCTURE_INDEX markers). This script reads it and, with
    approval, rewrites THAT SAME BLOCK in place. No new file is created.

Modes:
  --report   (default)  scan project, compare to the index, print drift (exit 0)
  --check              same as --report but exit 1 on drift (CI enforcement)
  --update             rewrite the index block to match the live scan

Drift = top-level dirs present in the repo but not indexed (NEW), or indexed but
no longer in the repo (REMOVED). No network, no build, no secret access.
"""
from __future__ import annotations

import json
import pathlib
import re
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent  # tools/ -> repo root
BASELINE = ROOT / "docs" / "03_PROJECT_STRUCTURE_BASELINE.md"
BEGIN = "<!-- VELORA_STRUCTURE_INDEX_BEGIN -->"
END = "<!-- VELORA_STRUCTURE_INDEX_END -->"


def scanned_top_level_dirs() -> list[str]:
    out = subprocess.run(
        ["git", "ls-files"], cwd=ROOT, capture_output=True, text=True, timeout=20
    )
    dirs = {p.split("/", 1)[0] for p in out.stdout.splitlines() if "/" in p}
    return sorted(dirs)


def read_index() -> list[str]:
    text = BASELINE.read_text(encoding="utf-8")
    m = re.search(re.escape(BEGIN) + r"(.*?)" + re.escape(END), text, re.S)
    if not m:
        raise SystemExit("ERROR: structure index block not found in 03_...md")
    jm = re.search(r"```json\s*(\{.*?\})\s*```", m.group(1), re.S)
    if not jm:
        raise SystemExit("ERROR: JSON not found inside the index block")
    return json.loads(jm.group(1)).get("top_level_dirs", [])


def write_index(dirs: list[str]) -> None:
    text = BASELINE.read_text(encoding="utf-8")
    data = {"top_level_dirs": dirs}
    block = "\n```json\n" + json.dumps(data, ensure_ascii=False, indent=2) + "\n```\n"
    text = re.sub(
        re.escape(BEGIN) + r".*?" + re.escape(END),
        BEGIN + block + END,
        text,
        flags=re.S,
    )
    BASELINE.write_text(text, encoding="utf-8")


def main() -> int:
    mode = sys.argv[1] if len(sys.argv) > 1 else "--report"
    live = scanned_top_level_dirs()
    documented = read_index()
    live_set, doc_set = set(live), set(documented)

    new = [d for d in live if d not in doc_set]
    removed = [d for d in documented if d not in live_set]

    print("=" * 60)
    print("STRUCTURE SYNC  —  mode:", mode)
    print("=" * 60)
    print("live top-level dirs  :", len(live))
    print("indexed in 03_...md  :", len(documented))
    print("NEW (in repo, not indexed)    :", new or "none")
    print("REMOVED (indexed, not in repo):", removed or "none")

    drift = bool(new) or bool(removed)

    if mode == "--update":
        write_index(live)
        print(">> index block rewritten in docs/03_...md to match the live scan")
        print(">> (PDF untouched; no new file created)")
        return 0
    if mode == "--check":
        if drift:
            print(">> FAIL: structure drift — run `python tools/structure_sync.py --update`")
            print("          and commit docs/03_PROJECT_STRUCTURE_BASELINE.md with your change")
            return 1
        print(">> OK: structure index is in sync with the repo")
        return 0
    if mode == "--report":
        print("(report mode — exit 0; run --update to sync)")
        return 0
    raise SystemExit("ERROR: unknown mode '%s' (use --report / --check / --update)" % mode)


if __name__ == "__main__":
    raise SystemExit(main())
