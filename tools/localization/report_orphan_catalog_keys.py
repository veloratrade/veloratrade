#!/usr/bin/env python3
"""PR-09 (chunk validation gap) — orphan catalog-key report (REPORT-ONLY).

Problem this closes: ``validate_localization.py`` already detects *missing*
keys (a template/script references a catalog key that does not exist) but
has no inverse check — a key that exists in ``public/locales/{fa,en}.json``
and/or a feature chunk under ``public/locales/chunks/{fa,en}/*.json`` but is
never referenced by any template, script, or PHP source is invisible today.

This script reports such orphan keys. It deliberately follows the same
posture as the existing ``report_catalog_anomalies.py`` (PR-01 V-7):
report-only, always exits 0 unless ``--fail`` is passed. Orphan detection
is inherently more prone to false positives than missing-key detection
(dynamic key construction, keys used only from contexts this scanner does
not walk, etc.), so it is intentionally NOT wired as a hard-blocking gate
in this change — it can be promoted to blocking later, after a triage
pass, exactly like V-7 was designed to be.

Scanned reference sources (mirrors validate_localization.py's own scan,
kept intentionally simpler/self-contained to avoid coupling to that
module's internal closures):
  * every ``localized/**/*.html`` output: ``data-i18n*`` attributes and
    inline ``t()``/``tr()``/``errorMessage()``/``VeloraLocale.t()`` calls;
  * every canonical template under the route contract's canonical paths
    (covers server-rendered / not-yet-built templates too);
  * every ``public/assets/*.js`` file (excluding the generated locale
    registry, which intentionally does not reference catalog keys);
  * every ``api/src/**/*.php``, ``api/workers/**/*.php``, and
    ``api/index.php`` file.

A key is reported as an orphan only when it appears in NEITHER the fa nor
the en catalog's reference set, so a key used only in one locale's HTML
(e.g. locale-specific copy variants) is not a false positive.

Exit codes:
  0  PASS (report printed; no orphans, or --fail not requested)
  1  FAIL (--fail requested and at least one orphan key found)
  2  usage or input error
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

DEFAULT_REPO_ROOT = Path(__file__).resolve().parents[2]

# Mirrors validate_localization.py's own reference-scanning patterns
# exactly, so orphan detection agrees with that module's "missing key"
# detection about what counts as a reference (t()/tr()/errorMessage()
# calls, plus dotted-key string literals gated by a known top-level
# catalog prefix — the same technique that already prevents PHP code
# like ``Config::get('foo.bar')`` from being misread as a catalog key).
CALL_PATTERN = re.compile(
    r"\b(?:VeloraLocale\.)?(?:t|tr|errorMessage)\(\s*"
    r"(?:[^,()'\"]+\s*,\s*)?(['\"])([a-zA-Z0-9_.-]+)\1"
)
QUOTED_KEY_PATTERN = re.compile(
    r"(['\"])([a-zA-Z][a-zA-Z0-9_-]*(?:\.[a-zA-Z0-9_-]+)+)\1"
)
I18N_KEY_ATTR_PATTERN = re.compile(
    r'data-i18n(?:-[a-z-]+)?="([a-zA-Z0-9_.-]+)"'
)


def _load_messages(path: Path) -> dict[str, str]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    messages = payload.get("messages", {})
    return messages if isinstance(messages, dict) else {}


def _collect_all_keys(root: Path) -> dict[str, set[str]]:
    """Return {locale: set(keys)} across both the flat catalog and chunks."""
    keys: dict[str, set[str]] = {"fa": set(), "en": set()}
    locales_dir = root / "public" / "locales"
    for locale in keys:
        flat = locales_dir / f"{locale}.json"
        if flat.is_file():
            keys[locale] |= set(_load_messages(flat))
        chunk_dir = locales_dir / "chunks" / locale
        if chunk_dir.is_dir():
            for chunk_file in sorted(chunk_dir.glob("*.json")):
                keys[locale] |= set(_load_messages(chunk_file))
    return keys


def _collect_references(root: Path, *, known_prefixes: set[str]) -> set[str]:
    references: set[str] = set()

    def scan_text(text: str, *, apply_prefix_filter: bool) -> None:
        for match in CALL_PATTERN.finditer(text):
            references.add(match.group(2))
        for match in I18N_KEY_ATTR_PATTERN.finditer(text):
            references.add(match.group(1))
        if not apply_prefix_filter:
            return
        for match in QUOTED_KEY_PATTERN.finditer(text):
            key = match.group(2)
            line_start = text.rfind("\n", 0, match.start()) + 1
            line_end = text.find("\n", match.end())
            line = text[line_start: None if line_end == -1 else line_end]
            if "Config::get" in line:
                continue
            if key.split(".", 1)[0] in known_prefixes:
                references.add(key)

    localized_dir = root / "localized"
    if localized_dir.is_dir():
        for path in localized_dir.rglob("*.html"):
            scan_text(
                path.read_text(encoding="utf-8", errors="ignore"),
                apply_prefix_filter=True,
            )

    routes_path = root / "tools" / "localization" / "routes.json"
    if routes_path.is_file():
        payload = json.loads(routes_path.read_text(encoding="utf-8"))
        for route in payload.get("routes", []):
            template = route.get("template")
            if isinstance(template, str):
                template_path = root / template
                if template_path.is_file():
                    scan_text(
                        template_path.read_text(encoding="utf-8", errors="ignore"),
                        apply_prefix_filter=True,
                    )

    assets_dir = root / "public" / "assets"
    if assets_dir.is_dir():
        for path in sorted(assets_dir.glob("*.js")):
            if path.name == "velora-locale-registry.js":
                continue
            scan_text(
                path.read_text(encoding="utf-8", errors="ignore"),
                apply_prefix_filter=True,
            )

    php_sources = (
        sorted((root / "api" / "src").rglob("*.php"))
        + sorted((root / "api" / "workers").rglob("*.php"))
        + ([root / "api" / "index.php"] if (root / "api" / "index.php").is_file() else [])
    )
    for path in php_sources:
        scan_text(
            path.read_text(encoding="utf-8", errors="ignore"),
            apply_prefix_filter=True,
        )

    return references


def find_orphan_keys(root: Path) -> dict[str, list[str]]:
    """Return {locale: sorted [orphan keys]} for keys referenced nowhere."""
    all_keys = _collect_all_keys(root)
    known_prefixes = {key.split(".", 1)[0] for keys in all_keys.values() for key in keys}
    references = _collect_references(root, known_prefixes=known_prefixes)
    orphans: dict[str, list[str]] = {}
    for locale, keys in all_keys.items():
        orphans[locale] = sorted(key for key in keys if key not in references)
    return orphans


def _parse_args(argv: list[str] | None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", help="repo root (default: derive from script location)")
    parser.add_argument(
        "--limit", type=int, default=25, help="max examples printed per locale (default 25)"
    )
    parser.add_argument(
        "--fail",
        action="store_true",
        help="exit 1 if any orphan key is found (off by default, report-only)",
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = _parse_args(argv)
    root = (Path(args.root) if args.root else DEFAULT_REPO_ROOT).resolve()

    orphans = find_orphan_keys(root)

    print("VELORA orphan catalog-key report (report-only)")
    total = 0
    for locale in sorted(orphans):
        keys = orphans[locale]
        total += len(keys)
        print(f"\n## {locale} — {len(keys)} orphan key(s) (defined, never referenced)")
        for key in keys[: args.limit]:
            print(f"   {key}")
        if len(keys) > args.limit:
            print(f"   ... and {len(keys) - args.limit} more")

    print(f"\nSummary: {total} total orphan key(s) across all locales (report-only, not blocking).")
    if args.fail and total:
        print("--fail requested and orphan keys present.", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
