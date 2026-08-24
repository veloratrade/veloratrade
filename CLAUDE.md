# CLAUDE.md — Velora i18n contributor contract

Velora is a **bilingual** product: **Persian (`fa`)** and **English (`en`)**.
This file is the mandatory i18n contract for AI assistants and code contributors.
It does **not** create a new translation system; it documents and reinforces the
existing Velora localization architecture.

## Non-negotiable rules

1. **No hardcoded user-facing strings.**
   Do not introduce Persian or English UI copy directly in HTML, JS, PHP, API
   responses, emails, tabs, buttons, modals, forms, settings, or page templates.

2. **Every new UI text must use the existing localization catalog system.**
   Use the current catalog/key flow (`data-i18n`, `data-i18n-*`, `t()`, `tr()`,
   `errorMessage()`, backend `messageKey`, and the existing localized build pipeline).

3. **Every new key must be added to both `fa` and `en` in the same change.**
   Never add a feature with only one locale complete.

4. **Placeholder variables must stay identical between languages.**
   If one locale uses `{name}`, `{count}`, `{year}`, etc., the paired locale must
   use the exact same placeholder set.

5. **One component, one route, one feature — not separate Persian/English copies.**
   Do not create separate FA and EN components/pages for the same feature.
   Use one canonical implementation with locale-based translations.

6. **RTL/LTR rules must be preserved.**
   Persian pages stay RTL, English pages stay LTR, and layout logic must remain
   language-independent.

7. **New routes/components/features must register localization keys before merge.**
   If you add a new page, dashboard tab, button, settings section, modal, or any
   new user-facing flow, its localization keys must exist before the change can merge.

8. **Avoid dynamic key construction where possible.**
   Prefer explicit translation keys so existing validators can statically verify
   usage and completeness.

9. **CI is the final authority.**
   Local success is helpful; CI decides whether the localization contract is satisfied.

10. **No new UI feature is complete until localization is complete.**
    For every new user-facing UI feature — including every button, tab, modal,
    form field, notification, empty state, error message, and settings item —
    all visible text MUST be implemented through the existing localization key
    system before the feature is considered complete.

11. **Every new UI element must use translation keys, never hardcoded copy.**
    Do not add Persian literals directly in UI code.
    Do not add English UI copy directly in UI code.
    Use the existing catalog/key flow (`data-i18n`, `data-i18n-*`, `t()`, `tr()`,
    `errorMessage()`, backend `messageKey`, and the existing localized build pipeline).

12. **Every new translation key must:**
    - exist in both `fa` and `en` catalogs in the same change;
    - preserve identical placeholder sets across languages;
    - follow the existing key naming conventions and namespace style.

13. **Do not bypass i18n enforcement with broad allowlist entries.**
    Any allowlist exception must be narrow, exact, technically justified, and
    consistent with the current repository policy. Broad regex or catch-all
    allowlist entries that hide normal user-facing UI copy are forbidden.

14. **Before marking a feature complete, run the relevant localization checks.**
    At minimum, contributors must verify:
    - no new hardcoded UI literals were introduced;
    - `fa` / `en` parity remains intact;
    - localization validation passes for the changed feature.

    Recommended local commands:
    ```bash
    python tools/localization/validate_localization.py
    python tools/localization/check_hardcoded_ui.py
    python tools/localization/check_frozen_hash_keys.py
    python tools/localization/report_catalog_anomalies.py \
      --allowlist tools/localization/catalog-quality-allowlist.json \
      --fail --fail-group en.empty --fail-group fa.en.identical
    python tools/localization/report_orphan_catalog_keys.py \
      --allowlist tools/localization/catalog-quality-allowlist.json --fail
    ```

## Local validation commands

Run these before committing any user-facing change:

```bash
python tools/localization/validate_localization.py
python tools/localization/check_hardcoded_ui.py
python tools/localization/check_frozen_hash_keys.py
python tools/localization/report_catalog_anomalies.py \
  --allowlist tools/localization/catalog-quality-allowlist.json \
  --fail --fail-group en.empty --fail-group fa.en.identical
python tools/localization/report_orphan_catalog_keys.py \
  --allowlist tools/localization/catalog-quality-allowlist.json --fail
python -m unittest tools.localization.test_report_catalog_anomalies -v
python -m unittest tools.localization.test_report_orphan_catalog_keys -v
```

## What CI already enforces

Velora already has existing localization validators and quality gates. Contributors
must reuse them, not duplicate them:

- `validate_localization.py`
- `check_hardcoded_ui.py`
- `check_frozen_hash_keys.py`
- `report_catalog_anomalies.py`
- `report_orphan_catalog_keys.py`
- `.github/workflows/quality-gate.yml`

If CI fails, fix the localization issue instead of bypassing the rule.
