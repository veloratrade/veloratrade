#!/usr/bin/env python3
"""Regression tests for the F2 fix: symbol icon inline handlers removal.

Background (production root-cause audit, 2026-09-01): the symbol icon
templates in public/assets/symbol-icons.js used to emit inline
``onload``/``onerror`` attributes. Every icon load/error executed an inline
event handler and was blocked + logged by the CSP ``script-src-attr``
directive (~56 console violations per authenticated /trades/new/ load).

The fix removes those inline attributes and installs one delegated
capture-phase ``error`` listener instead (image ``error`` events do not
bubble). These tests pin the source-level guarantees:

  1. symbol-icons.js never emits inline event-handler attributes;
  2. the delegated capture-phase fallback listener exists;
  3. every trades/new HTML variant references the same, bumped asset
     version so the fixed asset is actually served (immutable caching);
  4. the trades/new HTML variants keep ONLY the intentionally
     CSP-authorized inline handlers (the two sidebar ``toggleMobileSidebar``
     onclick attributes) — no new inline handlers may appear.

The CSP policy itself (hashes, manifest policy fields, locale-router.php)
is intentionally NOT touched by the fix and must stay unchanged.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ASSET = ROOT / "public" / "assets" / "symbol-icons.js"
HTML_VARIANTS = (
    ROOT / "trades" / "new" / "index.html",
    ROOT / "localized" / "en" / "trades" / "new" / "index.html",
    ROOT / "localized" / "fa" / "trades" / "new" / "index.html",
)
VERSION_RE = re.compile(r"symbol-icons\.js\?v=(?P<version>\d{4}\.\d{2}\.\d{2}\.\d+)")
INLINE_HANDLER_RE = re.compile(r"on(?:error|load)\s*=")
# The only inline handlers allowed in trades/new HTML (CSP-authorized via
# 'unsafe-hashes' sha256-V/addRRU… in the route manifest).
AUTHORIZED_HANDLER = "toggleMobileSidebar()"


class SymbolIconsCspTests(unittest.TestCase):
    def test_asset_emits_no_inline_event_handler_attributes(self) -> None:
        source = ASSET.read_text(encoding="utf-8")
        offenders = INLINE_HANDLER_RE.findall(source)
        self.assertEqual(
            offenders,
            [],
            "symbol-icons.js must not emit inline onload=/onerror= attributes "
            f"(found {len(offenders)}); use the delegated capture-phase listener",
        )

    def test_delegated_capture_phase_error_listener_present(self) -> None:
        source = ASSET.read_text(encoding="utf-8")
        self.assertIn(
            "document.addEventListener('error'",
            source,
            "delegated document-level error listener is required",
        )
        self.assertIn(
            "matches('.velora-sym img')",
            source,
            "listener must scope the fallback to .velora-sym img only",
        )
        self.assertRegex(
            source,
            r"\},\s*true\);",
            "image error events do not bubble; the listener must use the capture phase",
        )

    def test_html_variants_reference_identical_bumped_asset_version(self) -> None:
        versions = set()
        for html in HTML_VARIANTS:
            text = html.read_text(encoding="utf-8")
            matches = VERSION_RE.findall(text)
            self.assertEqual(
                len(matches),
                1,
                f"{html.relative_to(ROOT)} must reference symbol-icons.js exactly once",
            )
            versions.add(matches[0])
        self.assertEqual(
            len(versions),
            1,
            f"all trades/new HTML variants must pin the same symbol-icons.js version, got {versions}",
        )
        (version,) = versions
        self.assertNotEqual(
            version,
            "2026.08.13.36",
            "asset version must be bumped past the inline-handler revision "
            "(immutable one-year caching would otherwise serve the old asset)",
        )

    def test_trades_new_html_keeps_only_authorized_inline_handlers(self) -> None:
        handler_re = re.compile(r'on(?:error|load|click|input|change)\s*=\s*"([^"]*)"')
        for html in HTML_VARIANTS:
            text = html.read_text(encoding="utf-8")
            handlers = set(handler_re.findall(text))
            self.assertEqual(
                handlers,
                {AUTHORIZED_HANDLER},
                f"{html.relative_to(ROOT)} inline handlers must stay exactly "
                f"{{{AUTHORIZED_HANDLER!r}}} (CSP-authorized), found {handlers}",
            )


if __name__ == "__main__":
    unittest.main(verbosity=2)
