#!/usr/bin/env python3
"""Contract test for OC-14 / BR-9: the structure baseline is the authoritative
source locator.

Root cause this guards (2026-08-23): an agent declared the frontend "has no
source / unfixable" because no package.json/.tsx existed. But this project is
vanilla JS + generated HTML with NO bundler, and its source (templates under the
repo root + vanilla JS in public/assets/) IS tracked in the repository. The
conclusion was drawn WITHOUT consulting docs/03_PROJECT_STRUCTURE_BASELINE.md.

This test machine-proves the foundation of BR-9 so the mistake cannot recur:
  1. The P1 structure baseline exists and documents the Frontend map + the
     no-bundler architecture (so the map an agent must consult is reliable).
  2. The documented frontend SOURCE files are physically tracked in the repo
     (so "no source" is provably FALSE for this codebase).

No network, no build, no secret access. Uses `git ls-files` so it is correct in
both sparse and full checkouts.
"""
from __future__ import annotations

import pathlib
import re
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
BASELINE = ROOT / "docs" / "03_PROJECT_STRUCTURE_BASELINE.md"


def tracked_files() -> set[str]:
    out = subprocess.run(
        ["git", "ls-files"], cwd=ROOT, capture_output=True, text=True, timeout=15
    )
    return {line for line in out.stdout.splitlines() if line}


class StructureSourceLocatorTest(unittest.TestCase):
    def test_baseline_documents_frontend_and_architecture(self) -> None:
        """The P1 map an agent must consult exists and states the architecture."""
        self.assertTrue(BASELINE.is_file(), "docs/03_PROJECT_STRUCTURE_BASELINE.md (P1) must exist")
        text = BASELINE.read_text(encoding="utf-8")
        self.assertIn("Frontend", text, "baseline must contain the Frontend map")
        self.assertIn("Localization", text, "baseline must contain the Localization map")
        # The architecture is explicitly no-bundler / vanilla JS / generated HTML.
        self.assertTrue(
            re.search(r"[Vv]anilla|[Nn]o.*bundler|[Gg]enerated HTML|no @/ alias", text),
            "baseline must state the no-bundler / vanilla-JS architecture",
        )

    def test_frontend_source_files_are_tracked(self) -> None:
        """The editable frontend SOURCE must be tracked in the repo. Absence here
        would make 'no source' TRUE — so this is the hard guard (OC-14)."""
        tracked = tracked_files()
        must_be_tracked = [
            "trades/index.html",                    # source template — the 'journal'/trade-log page
            "trades/new/index.html",                # new-trade source template (screenshot import)
            "dashboard/index.html",                 # source template
            "public/assets/velora-data.js",         # shared auth / API / token layer
            "public/assets/velora-smart-import.js", # screenshot OCR extraction + parsing
        ]
        missing = [p for p in must_be_tracked if p not in tracked]
        self.assertEqual(
            missing, [],
            "frontend source not tracked: %s — OC-14: consult "
            "docs/03_PROJECT_STRUCTURE_BASELINE.md before declaring 'no source'" % missing,
        )


if __name__ == "__main__":
    unittest.main()
