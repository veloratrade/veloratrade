#!/usr/bin/env python3
"""Regression checks for the narrowly scoped, data-driven display-brand exception."""
from __future__ import annotations

import json
from pathlib import Path

from brand_policy import default_token, load_brand_policy, localized_message_keys, message_token
from validate_localization import invalid_display_brand

ROOT = Path(__file__).resolve().parents[2]
POLICY = load_brand_policy()
FA_KEYS = localized_message_keys(POLICY, "fa")
EXPECTED_KEYS = {
    "common.2026.velora.all.rights.reserved.6cdbc91f",
    "common.velora.is.a.trade.journaling.performance.analytics.64749ca5",
}
assert FA_KEYS == EXPECTED_KEYS
assert default_token(POLICY) == "VELORA"
assert message_token(POLICY, "fa", next(iter(FA_KEYS))) == "ولورا"
assert message_token(POLICY, "en", next(iter(FA_KEYS))) == "VELORA"
assert message_token(POLICY, "fa", "common.velora.2e043621") == "VELORA"
assert not invalid_display_brand("© ۲۰۲۶ ولورا.", "ولورا")
assert invalid_display_brand("© ۲۰۲۶ VELORA.", "ولورا")
assert invalid_display_brand("نام ولورا", "VELORA")
assert not invalid_display_brand("نام VELORA", "VELORA")

for locale in ("fa", "en"):
    messages = json.loads((ROOT / f"public/locales/{locale}.json").read_text(encoding="utf-8"))["messages"]
    for key in EXPECTED_KEYS:
        expected = message_token(POLICY, locale, key)
        assert expected in messages[key], (locale, key, expected, messages[key])
assert json.loads((ROOT / "public/locales/fa.json").read_text(encoding="utf-8"))["messages"]["common.velora.2e043621"] == "VELORA"

print("BRAND_POLICY_TEST_OK localized_locale=fa allowlisted_messages=2 default_brand=VELORA other_occurrences=VELORA")
