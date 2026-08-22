# Route E2E specs

Playwright smoke specs for routes declared `"e2eCategory": "critical"` in
`tools/localization/routes.json` live here, one file per route:

```
tools/e2e/<slug>.spec.js
```

`<slug>` = the route's template path with a trailing `/index.html` or
`.html` stripped and remaining `/` replaced with `-`
(see `tools/localization/route_e2e_contract.py::slug_for_template`).

Each spec must contain, at minimum:

- a page-load assertion — a Playwright navigation/wait call such as
  `page.goto(...)`, `page.waitForURL(...)`, `page.waitForSelector(...)`,
  or `page.waitForLoadState(...)`;
- a basic interactive-element assertion — a Playwright interaction call
  such as `page.click(...)`, `page.fill(...)`, `page.check(...)`,
  `page.selectOption(...)`, `page.press(...)`, or `page.type(...)`.

`tools/localization/route_e2e_contract.py` enforces this structurally
(pattern-based, no browser launch) as part of `gate-static` in
`.github/workflows/quality-gate.yml`. It does not replace running the
actual specs in a real browser — that remains the job of
`.github/scripts/dashboard_e2e.js` (wired into `gate-e2e` /
`ci.yml`'s "Dashboard browser test") and any future per-route runner.

## Known baseline gap (tracked, non-blocking)

The following critical routes do not yet have a spec file here. This is
a documented, intentional gap recorded in
`route_e2e_contract.py::KNOWN_MISSING_CRITICAL_SPECS` — it does not fail
CI today, but any *new* critical route must ship with a spec, and this
list should shrink over time as specs are backfilled:

- `login/index.html`
- `register/index.html`
- `forgot-password/index.html`
- `reset-password/index.html`
- `verify-email/index.html`
- `dashboard/index.html`
- `trades/index.html`
- `trades/new/index.html`
- `wallet/index.html`
- `profile/index.html`
- `accounts/connect/index.html`
- `checkout/index.html`
- `admin/index.html`
