#!/usr/bin/env python3
"""PR-09 — orphan catalog-key report with optional blocking allowlist.

Problem this closes: ``validate_localization.py`` already detects *missing*
keys (a template/script references a catalog key that does not exist) but has
no inverse check — a key that exists in ``public/locales/{fa,en}.json`` and/or
in a feature chunk under ``public/locales/chunks/{fa,en}/*.json`` but is never
referenced by any template, script, or PHP source is otherwise invisible.

By default this script remains report-only and exits 0. When ``--fail`` is
passed, callers may provide an exact-key allowlist representing approved
baseline orphan debt; any *new* orphan key beyond that list, or any stale
allowlist entry that no longer needs to be exempted, fails the command.

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
  0  PASS (report printed; no unexpected/stale orphans, or --fail not requested)
  1  FAIL (--fail requested and at least one unexpected/stale orphan key found)
  2  usage or input error
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

DEFAULT_REPO_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_ALLOWLIST = DEFAULT_REPO_ROOT / 'tools' / 'localization' / 'catalog-quality-allowlist.json'

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

# Narrow dynamic-key form (Pattern-B): a *literal* dotted key prefix
# concatenated with an inline object literal whose values are *literal*
# suffix strings, immediately subscripted, e.g.
#
#     var key = 'admin.integrations.result.' + {
#       SUCCESS: 'success', AUTH_FAILED: 'authFailed'
#     }[status];
#
# This is statically resolvable with certainty: the produced key set is
# exactly {prefix + value for each literal value}. Nothing else is
# inferred — arbitrary concatenation with variables is deliberately NOT
# expanded, so a key that merely *looks* similar but is absent from the
# inline map is still reported as an orphan.
CONCAT_MAP_PATTERN = re.compile(
    r"(['\"])([a-zA-Z][a-zA-Z0-9_-]*(?:\.[a-zA-Z0-9_-]+)*\.)\1"
    r"\s*\+\s*\{(?P<body>[^{}]*)\}\s*\[",
    re.DOTALL,
)
CONCAT_MAP_VALUE_PATTERN = re.compile(
    r"[:]\s*(['\"])([a-zA-Z0-9_-]+)\1"
)


def _expand_concat_map_keys(text: str) -> set[str]:
    """Resolve the narrow ``'prefix.' + { ... }[expr]`` dynamic-key form.

    Only literal prefixes combined with literal object-literal values are
    expanded. Any other dynamic construction is left unresolved (and thus
    still capable of producing a genuine orphan report).
    """
    resolved: set[str] = set()
    for match in CONCAT_MAP_PATTERN.finditer(text):
        prefix = match.group(2)
        body = match.group("body")
        for value_match in CONCAT_MAP_VALUE_PATTERN.finditer(body):
            resolved.add(prefix + value_match.group(2))
    return resolved


def _load_messages(path: Path) -> dict[str, str]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    messages = payload.get("messages", {})
    return messages if isinstance(messages, dict) else {}


def _load_allowlist(path: Path | None) -> set[str]:
    if path is None:
        return set()
    payload = json.loads(path.read_text(encoding='utf-8'))
    items = payload.get('orphanKeys', [])
    if not isinstance(items, list) or any(not isinstance(item, str) for item in items):
        raise ValueError(f"{path}: orphanKeys must be an array of strings")
    return set(items)


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
        for key in _expand_concat_map_keys(text):
            if key.split(".", 1)[0] in known_prefixes:
                references.add(key)
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
        help="exit 1 if any non-allowlisted or stale orphan key is found",
    )
    parser.add_argument(
        "--allowlist",
        help="JSON file with orphanKeys allowlist (default: none)",
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = _parse_args(argv)
    root = (Path(args.root) if args.root else DEFAULT_REPO_ROOT).resolve()
    allowlist_path = Path(args.allowlist).resolve() if args.allowlist else None
    allowlist = _load_allowlist(allowlist_path)

    orphans = find_orphan_keys(root)
    union = set().union(*(set(keys) for keys in orphans.values()))
    stale = sorted(allowlist - union)

    print("VELORA orphan catalog-key report")
    total = 0
    unexpected_total = 0
    allowlisted_total = 0
    for locale in sorted(orphans):
        keys = orphans[locale]
        total += len(keys)
        unexpected = [key for key in keys if key not in allowlist]
        allowlisted = len(keys) - len(unexpected)
        unexpected_total += len(unexpected)
        allowlisted_total += allowlisted
        print(
            f"\n## {locale} — total={len(keys)} allowlisted={allowlisted} blocking={len(unexpected)} orphan key(s)"
        )
        for key in unexpected[: args.limit]:
            print(f"   {key}")
        if len(unexpected) > args.limit:
            print(f"   ... and {len(unexpected) - args.limit} more")

    if stale:
        print(f"\n## stale allowlist entries — {len(stale)}")
        for key in stale[: args.limit]:
            print(f"   {key}")
        if len(stale) > args.limit:
            print(f"   ... and {len(stale) - args.limit} more")

    print(
        f"\nSummary: total={total} allowlisted={allowlisted_total} blocking={unexpected_total} stale_allowlist={len(stale)}"
    )
    if args.fail and (unexpected_total or stale):
        if unexpected_total:
            print(f"BLOCKING: {unexpected_total} orphan key(s) are not allowlisted.", file=sys.stderr)
        if stale:
            print(f"BLOCKING: {len(stale)} allowlist key(s) are stale.", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
