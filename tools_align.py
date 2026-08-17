#!/usr/bin/env python3
"""VELORA catalog alignment reporter (shadow mode, read-only).

The reporter joins translation-key references from canonical templates to the
central Persian and English catalogs. It never reads locale-specific source
pages, generates translations, or writes files.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any, Iterable, Sequence

from bs4 import BeautifulSoup

from tools.localization.route_contract import (
    ROOT,
    RouteContractError,
    RouteDefinition,
    load_route_contract,
)

CATALOG_PATHS = {
    "fa": Path("public/locales/fa.json"),
    "en": Path("public/locales/en.json"),
}
I18N_KEY_ATTRIBUTES = {
    "data-i18n",
    "data-i18n-title",
    "data-i18n-placeholder",
    "data-i18n-aria-label",
    "data-i18n-alt",
    "data-i18n-value",
    "data-i18n-content",
}
KEY_PATTERN = re.compile(r"^[a-zA-Z0-9_.-]+$")
PLACEHOLDER_PATTERN = re.compile(r"\{([a-zA-Z0-9_]+)\}")
CALL_PATTERN = re.compile(
    r"\b(?:VeloraLocale\.)?(?:t|tr|errorMessage)\(\s*"
    r"(?:[^,()'\"]+\s*,\s*)?"
    r"(['\"])([a-zA-Z0-9_.-]+)\1"
)


class AlignmentReportError(ValueError):
    """Raised when a central catalog cannot be loaded safely."""


def _duplicate_safe_object(pairs: Sequence[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise AlignmentReportError(f"duplicate JSON key: {key}")
        result[key] = value
    return result


def _load_catalog(root: Path, locale: str) -> dict[str, str]:
    relative = CATALOG_PATHS[locale]
    path = (root / relative).resolve()
    if not path.is_relative_to(root) or not path.is_file():
        raise AlignmentReportError(f"missing {locale} catalog: {relative.as_posix()}")
    try:
        payload = json.loads(
            path.read_text(encoding="utf-8"),
            object_pairs_hook=_duplicate_safe_object,
        )
    except AlignmentReportError:
        raise
    except (OSError, json.JSONDecodeError) as exc:
        raise AlignmentReportError(f"invalid {locale} catalog: {exc}") from exc
    if not isinstance(payload, dict):
        raise AlignmentReportError(f"{locale} catalog must be a JSON object")
    messages = payload.get("messages")
    if not isinstance(messages, dict):
        raise AlignmentReportError(f"{locale} catalog has no messages object")
    if any(not isinstance(key, str) or not isinstance(value, str) for key, value in messages.items()):
        raise AlignmentReportError(f"{locale} catalog messages must map strings to strings")
    return dict(messages)


def _route_url(route: RouteDefinition) -> str:
    output = route.outputs[0]
    if output == "index.html":
        return "/"
    if output.endswith("/index.html"):
        return "/" + output[: -len("index.html")]
    return "/" + output


def _line_number(source: str, offset: int) -> int:
    return source.count("\n", 0, offset) + 1


def _record_reference(
    references: dict[str, list[dict[str, Any]]],
    key: str,
    *,
    kind: str,
    line: int,
) -> None:
    if not key or key.endswith("."):
        return
    references[key].append({"kind": kind, "line": line})


def _extract_template_references(path: Path) -> dict[str, list[dict[str, Any]]]:
    source = path.read_text(encoding="utf-8", errors="strict")
    soup = BeautifulSoup(source, "html.parser")
    references: dict[str, list[dict[str, Any]]] = defaultdict(list)

    for tag in soup.find_all(True):
        line = int(getattr(tag, "sourceline", None) or 1)
        for attribute in sorted(I18N_KEY_ATTRIBUTES):
            key = tag.get(attribute)
            if isinstance(key, str) and key:
                _record_reference(references, key, kind=attribute, line=line)

    for match in CALL_PATTERN.finditer(source):
        _record_reference(
            references,
            match.group(2),
            kind="translation-call",
            line=_line_number(source, match.start()),
        )

    normalized: dict[str, list[dict[str, Any]]] = {}
    for key, entries in references.items():
        unique = {
            (str(entry["kind"]), int(entry["line"]))
            for entry in entries
        }
        normalized[key] = [
            {"kind": kind, "line": line}
            for kind, line in sorted(unique, key=lambda item: (item[1], item[0]))
        ]
    return normalized


def _placeholder_counts(value: str) -> Counter[str]:
    return Counter(PLACEHOLDER_PATTERN.findall(value))


def _placeholder_payload(value: str | None) -> dict[str, int] | None:
    if value is None:
        return None
    return dict(sorted(_placeholder_counts(value).items()))


def _placeholder_status(fa_value: str | None, en_value: str | None) -> str:
    if fa_value is None or en_value is None:
        return "missing"
    return "match" if _placeholder_counts(fa_value) == _placeholder_counts(en_value) else "mismatch"


def _missing_locales(fa_value: str | None, en_value: str | None) -> list[str]:
    missing: list[str] = []
    if fa_value is None:
        missing.append("fa")
    if en_value is None:
        missing.append("en")
    return missing


def build_alignment_report(root: str | Path = ROOT) -> dict[str, Any]:
    """Build a deterministic, read-only alignment report for canonical routes."""
    repository_root = Path(root).resolve()
    contract = load_route_contract(repository_root)
    if "fa" not in contract.locales or "en" not in contract.locales:
        raise AlignmentReportError("shadow alignment requires enabled fa and en locales")

    catalogs = {
        locale: _load_catalog(repository_root, locale)
        for locale in ("fa", "en")
    }
    records: list[dict[str, Any]] = []
    issues: list[dict[str, Any]] = []
    covered_templates: list[str] = []
    referenced_keys: set[str] = set()

    for route in contract.routes:
        template_path = repository_root / route.template
        route_references = _extract_template_references(template_path)
        covered_templates.append(route.template)

        for key in sorted(route_references):
            referenced_keys.add(key)
            fa_value = catalogs["fa"].get(key)
            en_value = catalogs["en"].get(key)
            placeholder_status = _placeholder_status(fa_value, en_value)
            record = {
                "route": _route_url(route),
                "template": route.template,
                "key": key,
                "fa": fa_value,
                "en": en_value,
                "references": route_references[key],
                "placeholderStatus": placeholder_status,
                "placeholders": {
                    "fa": _placeholder_payload(fa_value),
                    "en": _placeholder_payload(en_value),
                },
            }
            records.append(record)

            missing = _missing_locales(fa_value, en_value)
            if missing:
                issues.append(
                    {
                        "type": "missing-key",
                        "route": record["route"],
                        "template": route.template,
                        "key": key,
                        "missingLocales": missing,
                    }
                )
            elif placeholder_status == "mismatch":
                issues.append(
                    {
                        "type": "placeholder-mismatch",
                        "route": record["route"],
                        "template": route.template,
                        "key": key,
                        "faPlaceholders": record["placeholders"]["fa"],
                        "enPlaceholders": record["placeholders"]["en"],
                    }
                )
            if not KEY_PATTERN.fullmatch(key):
                issues.append(
                    {
                        "type": "invalid-key",
                        "route": record["route"],
                        "template": route.template,
                        "key": key,
                    }
                )

    records.sort(key=lambda item: (item["template"], item["key"]))
    issues.sort(
        key=lambda item: (
            str(item.get("template", "")),
            str(item.get("key", "")),
            str(item.get("type", "")),
        )
    )
    missing_count = sum(issue["type"] == "missing-key" for issue in issues)
    placeholder_mismatch_count = sum(
        issue["type"] == "placeholder-mismatch" for issue in issues
    )

    return {
        "mode": "catalog-alignment-shadow",
        "readOnly": True,
        "status": "ok" if not issues else "issues",
        "summary": {
            "routes": len(contract.routes),
            "canonicalTemplates": len(contract.canonical_templates),
            "coveredTemplates": len(set(covered_templates)),
            "locales": list(contract.locales),
            "catalogKeys": {
                "fa": len(catalogs["fa"]),
                "en": len(catalogs["en"]),
            },
            "referencedKeys": len(referenced_keys),
            "records": len(records),
            "missingKeys": missing_count,
            "placeholderMismatches": placeholder_mismatch_count,
            "issues": len(issues),
        },
        "records": records,
        "issues": issues,
    }


def _parse_args(argv: Iterable[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Report FA/EN catalog alignment for canonical route templates."
    )
    parser.add_argument(
        "--root",
        default=str(ROOT),
        help="Repository root (default: inferred from route_contract.py).",
    )
    parser.add_argument(
        "--compact",
        action="store_true",
        help="Emit compact JSON instead of indented JSON.",
    )
    return parser.parse_args(argv)


def main(argv: Iterable[str] | None = None) -> int:
    args = _parse_args(argv)
    try:
        report = build_alignment_report(args.root)
    except (AlignmentReportError, RouteContractError, OSError, UnicodeError) as exc:
        report = {
            "mode": "catalog-alignment-shadow",
            "readOnly": True,
            "status": "error",
            "summary": {"issues": 1},
            "records": [],
            "issues": [{"type": "contract-error", "message": str(exc)}],
        }
        exit_code = 2
    else:
        exit_code = 0 if not report["issues"] else 1

    json.dump(
        report,
        sys.stdout,
        ensure_ascii=False,
        indent=None if args.compact else 2,
        sort_keys=False,
    )
    sys.stdout.write("\n")
    return exit_code


if __name__ == "__main__":
    raise SystemExit(main())
