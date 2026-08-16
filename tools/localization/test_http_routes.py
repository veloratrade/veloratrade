#!/usr/bin/env python3
"""Exercise every negotiated/explicit localized route plus localized 404 and ETag behavior."""
from __future__ import annotations

import argparse
import json
import re
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
VERSION = json.loads((ROOT / "public/locales/manifest.json").read_text(encoding="utf-8"))["version"]
ROUTES = json.loads((ROOT / "tools/localization/routes.json").read_text(encoding="utf-8"))["routes"]


def route_path(output: str) -> str:
    if output == "index.html":
        return "/"
    if output.endswith("/index.html"):
        return "/" + output[: -len("index.html")]
    return "/" + output


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default="http://127.0.0.1:4174")
    args = parser.parse_args()
    base = args.base_url.rstrip("/")
    requests = 0

    def fetch(path: str, language: str, expected_status: int = 200, etag: str | None = None):
        nonlocal requests
        headers = {"Accept-Language": language}
        if etag:
            headers["If-None-Match"] = etag
        request = urllib.request.Request(base + path, headers=headers)
        try:
            response = urllib.request.urlopen(request, timeout=10)
            status, body, response_headers = response.status, response.read().decode("utf-8"), response.headers
        except urllib.error.HTTPError as error:
            status, body, response_headers = error.code, error.read().decode("utf-8"), error.headers
        requests += 1
        assert status == expected_status, (path, language, status, expected_status)
        return body, response_headers

    def assert_page(body: str, headers, locale: str, template: str) -> None:
        direction = "rtl" if locale == "fa" else "ltr"
        assert headers.get("X-VELORA-Locale") == locale
        assert f'data-velora-prelocalized="{locale}"' in body
        assert re.search(rf"<html\b[^>]*\bdir=[\"']{direction}[\"']", body, re.IGNORECASE)
        assert f"velora-localization.js?v={VERSION}" in body
        expected_localized_brand = 2 if locale == "fa" and template == "index.html" else 0
        assert body.count("ولورا") == expected_localized_brand, (
            template,
            locale,
            body.count("ولورا"),
            expected_localized_brand,
        )

    browser_cases = {
        "fa-IR": "fa",
        "en-GB": "en",
        "de-DE": "en",
        "ar-SA": "en",
    }
    for route in ROUTES:
        path = route_path(route["outputs"][0])
        for language, expected_locale in browser_cases.items():
            body, headers = fetch(path, language)
            assert_page(body, headers, expected_locale, route["template"])

    for route in ROUTES:
        for locale in ("fa", "en"):
            outputs = route.get("localeOutputs", {}).get(locale, route["outputs"])
            path = f"/{locale}" + route_path(outputs[0])
            body, headers = fetch(path, "de-DE")
            assert_page(body, headers, locale, route["template"])

    for language, expected_locale in (("fa-IR", "fa"), ("en-GB", "en"), ("de-DE", "en")):
        body, headers = fetch(f"/missing-localized-route-{language.lower()}/", language, 404)
        assert_page(body, headers, expected_locale, "404.html")

    # Revalidation must preserve negotiated cache correctness.
    _, first_headers = fetch("/", "en-GB")
    etag = first_headers.get("ETag")
    assert etag
    _, second_headers = fetch("/", "en-GB", 304, etag)
    assert second_headers.get("ETag") == etag

    route_requests = requests - 2
    assert route_requests == 171, route_requests
    print(
        "HTTP_ROUTE_MATRIX_OK "
        f"requests={route_requests} templates={len(ROUTES)} browser_languages=fa-IR,en-GB,de-DE,ar-SA "
        "explicit_locales=fa,en localized_brand_scope=2 etag=304"
    )


if __name__ == "__main__":
    main()
