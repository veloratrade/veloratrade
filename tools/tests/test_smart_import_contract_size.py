#!/usr/bin/env python3
"""Regression test for the screenshot-import contract-size bug (2026-08-30).

Failure it pins: Gemini read the broker card correctly (XAUUSD buy 0.20,
3342.15 -> 3348.72, P/L 131.40) but applyToForm() never touched the
``contract`` input, so the manual form and the server's canonical
PnlCalculator recomputed pnl with contractSize=1 and saved 1.31 instead of
131.40. The broker-authoritative P/L was displayed on the review card and
then discarded.

The fix derives contractSize from the authoritative extracted P/L
(ratio = pnl / (delta * volume)) and snaps it ONLY to the standard specs
already documented in api/src/Trades/PnlCalculator.php (FX 100000,
metals 100, indices/crypto 1, ...). This test statically pins:

  1. inferContractSize() exists in velora-smart-import.js and is called
     from applyToForm() BEFORE the field inputs are applied;
  2. the spec list matches PnlCalculator's documented standard sizes and
     contains no other (magic) values;
  3. a sign contradiction or non-standard ratio falls back to null
     (conservative default), never to an arbitrary multiplier;
  4. the asset referenced by all three trades/new pages is >= 2026.08.30.4
     (the first build carrying the fix).

Numeric behaviour itself is exercised by the node harness in CI review;
this test keeps the structural contract from regressing.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
JS = ROOT / "public" / "assets" / "velora-smart-import.js"
PAGES = [
    ROOT / "trades" / "new" / "index.html",
    ROOT / "localized" / "fa" / "trades" / "new" / "index.html",
    ROOT / "localized" / "en" / "trades" / "new" / "index.html",
]
MIN_VERSION = (2026, 8, 30, 4)
# Standard contract sizes documented in api/src/Trades/PnlCalculator.php.
EXPECTED_SPECS = "[0.01, 0.1, 1, 10, 100, 1000, 10000, 100000]"

failures = []

src = JS.read_text(encoding="utf-8")

# 1. function exists and applyToForm calls it before setInput('volume', ...)
if "function inferContractSize(" not in src:
    failures.append("inferContractSize() missing from velora-smart-import.js")
apply_pos = src.find("function applyToForm()")
call_pos = src.find("inferContractSize(merged)", apply_pos)
volume_pos = src.find("setInput('volume'", apply_pos)
if apply_pos == -1 or call_pos == -1 or volume_pos == -1 or not (apply_pos < call_pos < volume_pos):
    failures.append("applyToForm() must call inferContractSize(merged) before applying field inputs")

# 2. spec list is exactly the canonical standard sizes (no magic values)
m = re.search(r"var CONTRACT_SPECS = (\[[^\]]*\]);", src)
if not m:
    failures.append("CONTRACT_SPECS declaration missing")
elif m.group(1).replace(" ", "") != EXPECTED_SPECS.replace(" ", ""):
    failures.append(f"CONTRACT_SPECS drifted from PnlCalculator standard sizes: {m.group(1)}")

# 3. conservative fallbacks stay in place
for marker in ("if (!isFinite(ratio) || ratio <= 0) return null;", "return null; // non-standard contract"):
    if marker not in src:
        failures.append(f"conservative fallback missing: {marker!r}")

# 4. pages reference the fixed asset version
ver_re = re.compile(r"velora-smart-import\.js\?v=(\d{4})\.(\d{2})\.(\d{2})\.(\d+)")
for page in PAGES:
    html = page.read_text(encoding="utf-8")
    m = ver_re.search(html)
    if not m:
        failures.append(f"{page}: no versioned smart-import reference")
        continue
    if tuple(int(g) for g in m.groups()) < MIN_VERSION:
        failures.append(f"{page}: smart-import version {m.group(0)} predates the contract-size fix")

if failures:
    for f in failures:
        print("FAIL:", f)
    sys.exit(1)
print(f"SMART_IMPORT_CONTRACT_SIZE: PASS ({len(PAGES)} page(s) + asset checked)")
