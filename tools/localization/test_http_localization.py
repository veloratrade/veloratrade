#!/usr/bin/env python3
"""HTTP contract regression checks against a running Velora API instance."""

from __future__ import annotations

import argparse
import json
import urllib.error
import urllib.request


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default="http://127.0.0.1:8080")
    args = parser.parse_args()
    base = args.base_url.rstrip("/")

    def request(path: str, method: str = "GET", body: object | None = None, language: str = "en") -> tuple[int, dict]:
        data = None if body is None else json.dumps(body).encode()
        req = urllib.request.Request(
            base + path,
            data=data,
            method=method,
            headers={"Content-Type": "application/json", "Accept-Language": language},
        )
        try:
            response = urllib.request.urlopen(req, timeout=5)
            status, raw = response.status, response.read()
        except urllib.error.HTTPError as error:
            status, raw = error.code, error.read()
        return status, json.loads(raw)

    def stable(payload: dict) -> dict:
        payload = dict(payload)
        payload.pop("timestamp", None)
        return payload

    lookup_fa = request(
        "/api/v1/content-translations/lookup", "POST", {"targetLocale": "en", "items": []}, "fa"
    )
    lookup_en = request(
        "/api/v1/content-translations/lookup", "POST", {"targetLocale": "en", "items": []}, "en"
    )
    assert lookup_fa[0] == lookup_en[0] == 200
    assert stable(lookup_fa[1]) == stable(lookup_en[1])
    assert lookup_en[1]["data"] == {
        "targetLocale": "en",
        "translations": [],
        "misses": 0,
        "cacheOnly": True,
    }

    trades_fa = request("/api/v1/trades", language="fa")
    trades_en = request("/api/v1/trades", language="en")
    assert trades_fa[0] == trades_en[0] == 401
    assert stable(trades_fa[1]) == stable(trades_en[1])
    assert trades_en[1]["error"]["code"] == "ACCESS_TOKEN_MISSING"
    assert trades_en[1]["error"]["messageKey"] == "errors.auth.accessTokenMissing"

    validation_fa = request("/api/v1/auth/resend-verification", "POST", {}, "fa")
    validation_en = request("/api/v1/auth/resend-verification", "POST", {}, "en")
    assert validation_fa[0] == validation_en[0] == 422
    assert stable(validation_fa[1]) == stable(validation_en[1])
    field = validation_en[1]["error"]["details"]["fields"]["email"]
    assert validation_en[1]["error"]["messageKey"] == "errors.validation"
    assert field["code"] == "REQUIRED"
    assert field["messageKey"] == "errors.validation.required"

    unsupported = request(
        "/api/v1/content-translations/lookup", "POST", {"targetLocale": "de", "items": []}, "de"
    )
    assert unsupported[0] == 422
    assert unsupported[1]["error"]["details"]["fields"]["targetLocale"]["code"] == "UNSUPPORTED_LOCALE"

    print(
        "HTTP_LOCALIZATION_TEST_OK cache_only=true accept_language_neutral=true "
        "auth_contract=true validation_contract=true unsupported_locale=true"
    )


if __name__ == "__main__":
    main()
