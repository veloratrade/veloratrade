#!/usr/bin/env python3
"""Shared, data-driven policy for locale-specific display-brand exceptions."""
from __future__ import annotations

import json
from pathlib import Path
from typing import Any

POLICY_PATH = Path(__file__).with_name("brand-policy.json")


def load_brand_policy() -> dict[str, Any]:
    return json.loads(POLICY_PATH.read_text(encoding="utf-8"))


def localized_message_keys(policy: dict[str, Any], locale: str) -> set[str]:
    values = policy.get("localizedMessages", {}).get(locale, [])
    return {str(value) for value in values}


def default_token(policy: dict[str, Any]) -> str:
    value = policy.get("defaultToken", "VELORA")
    return str(value) if isinstance(value, str) and value else "VELORA"


def localized_token(policy: dict[str, Any], locale: str) -> str | None:
    value = policy.get("localizedTokens", {}).get(locale)
    return str(value) if isinstance(value, str) and value else None


def is_localized_message(policy: dict[str, Any], locale: str, key: str) -> bool:
    return key in localized_message_keys(policy, locale)


def message_token(policy: dict[str, Any], locale: str | None, key: str | None) -> str:
    """Return the one valid display token for a locale/message context."""
    if locale and key and is_localized_message(policy, locale, key):
        return localized_token(policy, locale) or default_token(policy)
    return default_token(policy)


def display_tokens(policy: dict[str, Any]) -> set[str]:
    """Return every token validators must recognize, including future locale tokens."""
    tokens = {default_token(policy)}
    tokens.update(
        str(value) for value in policy.get("localizedTokens", {}).values()
        if isinstance(value, str) and value
    )
    return tokens
