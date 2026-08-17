#!/usr/bin/env python3
"""Shared, read-only route contract loader for Velora localization tooling.

The loader makes ``tools/localization/routes.json`` and
``public/locales/manifest.json`` the only routing inputs. It validates canonical
source templates and computes the localized output contract without reading or
writing generated HTML.
"""
from __future__ import annotations

import json
import re
from dataclasses import dataclass
from pathlib import Path, PurePosixPath
from types import MappingProxyType
from typing import Any, Mapping, Sequence

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_ROUTES_PATH = Path("tools/localization/routes.json")
DEFAULT_MANIFEST_PATH = Path("public/locales/manifest.json")
_WINDOWS_ABSOLUTE = re.compile(r"^[A-Za-z]:[\\/]")


class RouteContractError(ValueError):
    """Raised when route or locale data violates the localization contract."""


@dataclass(frozen=True)
class RouteDefinition:
    """One normalized route declaration from routes.json."""

    template: str
    outputs: tuple[str, ...]
    locale_outputs: Mapping[str, tuple[str, ...]]

    def outputs_for(self, locale: str) -> tuple[str, ...]:
        """Return base outputs plus locale-specific aliases, preserving order."""
        combined = self.outputs + self.locale_outputs.get(locale, ())
        return tuple(dict.fromkeys(combined))


@dataclass(frozen=True)
class RouteContract:
    """Immutable normalized contract consumed by localization tools.

    Paths in ``canonical_templates``, ``expected_outputs`` and
    ``output_to_template`` are POSIX paths relative to the repository root.
    Expected output paths therefore have the form
    ``localized/{locale}/{route-output}``.
    """

    root: Path
    routes: tuple[RouteDefinition, ...]
    locales: tuple[str, ...]
    canonical_templates: tuple[str, ...]
    expected_outputs: Mapping[str, tuple[str, ...]]
    output_to_template: Mapping[str, str]

    @property
    def canonical_paths(self) -> tuple[Path, ...]:
        """Return absolute canonical template paths."""
        return tuple(self.root / template for template in self.canonical_templates)

    @property
    def expected_output_paths(self) -> tuple[Path, ...]:
        """Return all absolute expected output paths in deterministic order."""
        return tuple(
            self.root / output
            for locale in self.locales
            for output in self.expected_outputs[locale]
        )


def _duplicate_safe_object(pairs: Sequence[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise RouteContractError(f"duplicate JSON key: {key}")
        result[key] = value
    return result


def _read_json(path: Path, label: str) -> dict[str, Any]:
    if not path.is_file():
        raise RouteContractError(f"missing {label}: {path}")
    try:
        payload = json.loads(
            path.read_text(encoding="utf-8"),
            object_pairs_hook=_duplicate_safe_object,
        )
    except RouteContractError:
        raise
    except (OSError, json.JSONDecodeError) as exc:
        raise RouteContractError(f"invalid {label}: {path}: {exc}") from exc
    if not isinstance(payload, dict):
        raise RouteContractError(f"{label} must be a JSON object: {path}")
    return payload


def _normalize_relative_path(value: Any, label: str) -> str:
    if not isinstance(value, str) or not value.strip():
        raise RouteContractError(f"{label} must be a non-empty string")
    if value != value.strip():
        raise RouteContractError(f"{label} contains surrounding whitespace: {value!r}")
    if "\\" in value:
        raise RouteContractError(f"{label} must use POSIX separators: {value!r}")
    if value.startswith("/") or _WINDOWS_ABSOLUTE.match(value):
        raise RouteContractError(f"absolute path is forbidden for {label}: {value!r}")

    path = PurePosixPath(value)
    if path.is_absolute():
        raise RouteContractError(f"absolute path is forbidden for {label}: {value!r}")
    if any(part == ".." for part in path.parts):
        raise RouteContractError(f"path traversal is forbidden for {label}: {value!r}")
    if any(part in {"", "."} for part in path.parts):
        raise RouteContractError(f"non-canonical path is forbidden for {label}: {value!r}")
    if value.endswith("/"):
        raise RouteContractError(f"{label} must name a file, not a directory: {value!r}")

    normalized = path.as_posix()
    if normalized != value:
        raise RouteContractError(
            f"{label} must already be normalized: {value!r} -> {normalized!r}"
        )
    return normalized


def _normalize_path_list(value: Any, label: str) -> tuple[str, ...]:
    if not isinstance(value, list) or not value:
        raise RouteContractError(f"{label} must be a non-empty array")
    normalized = tuple(
        _normalize_relative_path(item, f"{label}[{index}]")
        for index, item in enumerate(value)
    )
    if len(set(normalized)) != len(normalized):
        raise RouteContractError(f"duplicate output in {label}")
    return normalized


def _resolve_input_path(root: Path, configured: str | Path, label: str) -> Path:
    path = Path(configured)
    if not path.is_absolute():
        path = root / path
    path = path.resolve()
    if not path.is_relative_to(root):
        raise RouteContractError(f"{label} is outside repository root: {path}")
    return path


def _enabled_locales(manifest: Mapping[str, Any]) -> tuple[str, ...]:
    locale_data = manifest.get("locales")
    if not isinstance(locale_data, dict) or not locale_data:
        raise RouteContractError("locale manifest must define a non-empty locales object")

    locales: list[str] = []
    for raw_code, metadata in locale_data.items():
        code = _normalize_relative_path(raw_code, "locale code")
        if "/" in code:
            raise RouteContractError(f"locale code must be one path segment: {raw_code!r}")
        if not isinstance(metadata, dict):
            raise RouteContractError(f"locale metadata must be an object: {raw_code}")
        if metadata.get("enabled", True) is not False:
            locales.append(code)

    if not locales:
        raise RouteContractError("locale manifest has no enabled locales")
    return tuple(locales)


def load_route_contract(
    root: str | Path = ROOT,
    *,
    routes_path: str | Path = DEFAULT_ROUTES_PATH,
    manifest_path: str | Path = DEFAULT_MANIFEST_PATH,
) -> RouteContract:
    """Load and validate Velora's route/localization contract.

    The function is read-only. It never requires generated localized files to
    exist because ``expected_outputs`` is also used before a build. Canonical
    source templates, however, must already exist on disk.
    """
    repository_root = Path(root).resolve()
    if not repository_root.is_dir():
        raise RouteContractError(f"repository root does not exist: {repository_root}")

    routes_file = _resolve_input_path(repository_root, routes_path, "routes path")
    manifest_file = _resolve_input_path(repository_root, manifest_path, "manifest path")
    routes_payload = _read_json(routes_file, "routes manifest")
    manifest = _read_json(manifest_file, "locale manifest")
    locales = _enabled_locales(manifest)

    raw_routes = routes_payload.get("routes")
    if not isinstance(raw_routes, list) or not raw_routes:
        raise RouteContractError("routes manifest must define a non-empty routes array")

    root_resolved = repository_root.resolve()
    seen_templates: set[str] = set()
    routes: list[RouteDefinition] = []

    for route_index, raw_route in enumerate(raw_routes):
        route_label = f"routes[{route_index}]"
        if not isinstance(raw_route, dict):
            raise RouteContractError(f"{route_label} must be an object")

        template = _normalize_relative_path(
            raw_route.get("template"), f"{route_label}.template"
        )
        first_part = PurePosixPath(template).parts[0]
        if first_part == "en":
            raise RouteContractError(
                f"canonical template inside en/** is forbidden: {template}"
            )
        if first_part == "localized":
            raise RouteContractError(
                f"canonical template inside localized/** is forbidden: {template}"
            )
        if template in seen_templates:
            raise RouteContractError(f"duplicate canonical template: {template}")

        template_path = (repository_root / template).resolve()
        if not template_path.is_relative_to(root_resolved):
            raise RouteContractError(f"template escapes repository root: {template}")
        if not template_path.is_file():
            raise RouteContractError(f"missing canonical template: {template}")
        seen_templates.add(template)

        outputs = _normalize_path_list(
            raw_route.get("outputs"), f"{route_label}.outputs"
        )
        raw_locale_outputs = raw_route.get("localeOutputs", {})
        if not isinstance(raw_locale_outputs, dict):
            raise RouteContractError(f"{route_label}.localeOutputs must be an object")

        unknown_locales = sorted(set(raw_locale_outputs) - set(locales))
        if unknown_locales:
            raise RouteContractError(
                f"unknown locale in {route_label}.localeOutputs: {unknown_locales[0]}"
            )

        locale_outputs: dict[str, tuple[str, ...]] = {}
        for locale, raw_outputs in raw_locale_outputs.items():
            locale_outputs[locale] = _normalize_path_list(
                raw_outputs, f"{route_label}.localeOutputs.{locale}"
            )
            overlap = set(outputs) & set(locale_outputs[locale])
            if overlap:
                raise RouteContractError(
                    f"duplicate output in {route_label} for locale {locale}: "
                    f"{sorted(overlap)[0]}"
                )

        routes.append(
            RouteDefinition(
                template=template,
                outputs=outputs,
                locale_outputs=MappingProxyType(dict(locale_outputs)),
            )
        )

    expected_by_locale: dict[str, list[str]] = {locale: [] for locale in locales}
    output_to_template: dict[str, str] = {}

    for route in routes:
        for locale in locales:
            localized_base = (repository_root / "localized" / locale).resolve()
            for relative_output in route.outputs_for(locale):
                candidate = (localized_base / relative_output).resolve()
                if not candidate.is_relative_to(localized_base):
                    raise RouteContractError(
                        "localized output escapes locale root: "
                        f"locale={locale} output={relative_output}"
                    )
                output = candidate.relative_to(repository_root).as_posix()
                expected_prefix = f"localized/{locale}/"
                if not output.startswith(expected_prefix):
                    raise RouteContractError(
                        "localized output is outside expected locale root: "
                        f"locale={locale} output={output}"
                    )
                previous = output_to_template.get(output)
                if previous is not None:
                    raise RouteContractError(
                        f"output collision: {output} maps to both "
                        f"{previous} and {route.template}"
                    )
                output_to_template[output] = route.template
                expected_by_locale[locale].append(output)

    canonical_templates = tuple(sorted(seen_templates))
    expected_outputs = MappingProxyType(
        {
            locale: tuple(sorted(expected_by_locale[locale]))
            for locale in locales
        }
    )

    return RouteContract(
        root=repository_root,
        routes=tuple(routes),
        locales=locales,
        canonical_templates=canonical_templates,
        expected_outputs=expected_outputs,
        output_to_template=MappingProxyType(dict(sorted(output_to_template.items()))),
    )


__all__ = [
    "RouteContract",
    "RouteContractError",
    "RouteDefinition",
    "load_route_contract",
]
