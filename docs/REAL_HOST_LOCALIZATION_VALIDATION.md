# VELORA — Real Host Localization Validation Checklist

> **Purpose:** manual, human-run verification on the actual production (or a
> production-equivalent) host, after the FA/EN localization closure work has
> been committed and deployed. This checklist is deliberately **not
> automatable** — it exists to catch anything a static scan or unit test
> cannot: real browser rendering, real cookies, real email delivery, and real
> cross-device behavior.
>
> Run this checklist against the live/staging host, not the local workspace.
> Record PASS/FAIL and any notes per row before signing off a release.
>
> **Reference facts** (from `public/locales/manifest.json`): default locale =
> `fa`, fallback locale = `en`, locale cookie name = `velora_locale`, locale
> priority = URL prefix → saved user locale (`users.locale`) → `velora_locale`
> cookie → `Accept-Language` header → default (`fa`).

---

## 1. Language switching

| # | Check | Steps | Expected result | Result |
|---|---|---|---|---|
| 1.1 | Persian selection | On any page, use the language switcher to select فارسی | Page reloads/re-renders fully in Persian; `<html lang="fa" dir="rtl">` | ☐ |
| 1.2 | English selection | Use the language switcher to select English | Page reloads/re-renders fully in English; `<html lang="en" dir="ltr">` | ☐ |
| 1.3 | URL locale handling — explicit prefix wins | Visit `/en/...` while cookie/browser is set to Persian | Page renders in English regardless of cookie/browser (explicit prefix is authoritative) | ☐ |
| 1.4 | URL locale handling — unprefixed respects prior choice | After 1.3, visit an unprefixed path (e.g. `/`) | Page resolves via saved cookie/browser, not forced back to the prefix you last visited | ☐ |
| 1.5 | Cookie persistence — same browser, new tab | Pick a language, open a new tab to the site | New tab opens in the same chosen language | ☐ |
| 1.6 | Cookie persistence — browser restart | Pick a language, fully close and reopen the browser, revisit the site | Language choice still applied (cookie survives restart; manifest specifies 1-year cookie lifetime) | ☐ |
| 1.7 | First-visit browser detection | Clear cookies, set browser/OS language to Persian, visit unprefixed URL | Site defaults to Persian | ☐ |
| 1.8 | First-visit browser detection — other language | Clear cookies, set browser/OS language to something else (e.g. German, French), visit unprefixed URL | Site defaults to English (manifest fallback), not Persian | ☐ |
| 1.9 | Signed-in saved preference outranks cookie | Log in as a user whose saved `locale` differs from the current cookie | Page renders in the user's saved locale; cookie is resynced to match | ☐ |

## 2. Authentication

| # | Check | Steps | Expected result | Result |
|---|---|---|---|---|
| 2.1 | Register — FA | Complete registration on the Persian site | All labels, placeholders, validation errors, and success messages are in Persian; no stray English/Persian mix | ☐ |
| 2.2 | Register — EN | Complete registration on the English site | All labels, placeholders, validation errors, and success messages are in English | ☐ |
| 2.3 | Register — saved locale set | After registering, check that the new account's locale matches the language used at signup | `users.locale` reflects the signup-time UI language (or explicit selection if offered) | ☐ |
| 2.4 | Login — FA/EN | Log in on both locales | Success/error messages (wrong password, unknown email, etc.) render in the active locale via `messageKey`, not a hardcoded string | ☐ |
| 2.5 | Forgot password — FA/EN | Submit the forgot-password form on both locales | Confirmation message language-correct; no leaked raw `messageKey` string (e.g. `auth.passwordResetSentIfRegistered`) shown instead of translated text | ☐ |
| 2.6 | Reset password — FA/EN | Follow a real reset link and set a new password on both locales | Form, validation errors, and success confirmation all correctly localized | ☐ |
| 2.7 | Email verification — FA/EN | Trigger verification, click the real link, observe the result page | Verified/already-verified/invalid-link states all correctly localized | ☐ |
| 2.8 | Auth error edge case | Trigger a rate-limited request (rapid repeated login attempts) | "Too many requests" message renders translated (`errors.rateLimited`), not raw English fallback, on the Persian site | ☐ |

## 3. Core pages

| # | Check | Steps | Expected result | Result |
|---|---|---|---|---|
| 3.1 | Dashboard — FA | Load `/fa/dashboard/` (or equivalent) as a logged-in user | All summary cards, chart labels, empty states fully in Persian | ☐ |
| 3.2 | Dashboard — EN | Load the English dashboard | All summary cards, chart labels, empty states fully in English | ☐ |
| 3.3 | Trades (list) — FA/EN | Load the trades list on both locales | Column headers, filters, empty-state copy, pagination controls localized | ☐ |
| 3.4 | Trade creation — FA/EN | Open the new-trade form on both locales | Field labels, dropdown options (direction/source/symbol type), validation messages localized | ☐ |
| 3.5 | Trade creation — submit error | Submit an invalid trade (e.g. negative volume) on the Persian site | Validation error renders in Persian, not the raw `messageKey` or English fallback | ☐ |
| 3.6 | Wallet — FA/EN | Load the wallet page on both locales | Balance labels, currency formatting, transaction history all localized | ☐ |
| 3.7 | Profile — FA/EN | Load the profile/settings page on both locales | Account fields, timezone/locale selectors, save-confirmation toast localized | ☐ |
| 3.8 | Admin — FA/EN | Load the admin page as an admin user on both locales | Tables, action buttons, status labels localized; no leftover hardcoded strings | ☐ |
| 3.9 | Checkout — FA/EN | Load the checkout page on both locales | Pricing copy, CTA buttons, legal/consent text localized | ☐ |

## 4. UI checks

| # | Check | Steps | Expected result | Result |
|---|---|---|---|---|
| 4.1 | RTL layout — Persian | Sweep dashboard, trades, admin, wallet, profile, landing on Persian | No mirrored-icon glitches, no clipped/overflowing text, nav/sidebar correctly mirrored | ☐ |
| 4.2 | LTR layout — English | Sweep the same pages on English | Standard LTR layout, no leftover RTL artifacts (e.g. mirrored icons) | ☐ |
| 4.3 | LTR exceptions inside RTL | On the Persian site, inspect ticker symbols, currency codes, numeric values | These stay LTR and visually isolated even inside RTL paragraphs (no bidi scrambling, e.g. "USD 1,250.00" reads left-to-right) | ☐ |
| 4.4 | Dates — FA | Check trade timestamps, "last updated," relative time ("3 hours ago") on Persian pages | Dates use Persian formatting conventions consistent with the rest of the app (not raw ISO strings) | ☐ |
| 4.5 | Dates — EN | Same check on English pages | Dates render in English formatting conventions | ☐ |
| 4.6 | Numbers — Latin digits in FA | Inspect balances, win rates, trade counts on the Persian site | All numbers use Latin digits (۰۱۲... must NOT appear), per `numberingSystem: latn` | ☐ |
| 4.7 | Error messages — no raw keys | Deliberately trigger a few different error states (invalid form, 404, network failure) on both locales | User never sees a raw dotted key (e.g. `errors.validation.required`) or `undefined`/`null` instead of translated text | ☐ |
| 4.8 | 404 page — FA/EN | Visit a nonexistent path under both locale prefixes | 404 page renders fully localized, correct `dir`/`lang` | ☐ |

## 5. Email checks

| # | Check | Steps | Expected result | Result |
|---|---|---|---|---|
| 5.1 | Verification email — FA | Register a Persian-locale account, inspect the real received email | Subject and body fully in Persian, RTL-appropriate formatting, correct CTA link | ☐ |
| 5.2 | Verification email — EN | Register an English-locale account, inspect the real received email | Subject and body fully in English | ☐ |
| 5.3 | Password reset email — FA/EN | Trigger forgot-password on both locales, inspect received emails | Subject/body/CTA correctly localized per account's resolved locale | ☐ |
| 5.4 | Notification emails — FA/EN | Trigger any other transactional notification the product sends (e.g. welcome email, security alert) | Subject/body correctly localized; no mixed-language email | ☐ |
| 5.5 | Email locale resolution priority | Verify (via test account) that email language follows saved user locale first, request/client-hint locale second, default last | Behavior matches `NotificationService::resolveEmailLocale()` priority | ☐ |

## 6. Browser checks

| # | Check | Steps | Expected result | Result |
|---|---|---|---|---|
| 6.1 | Desktop — Chrome | Run through switching, auth, and core pages on desktop Chrome | No layout/localization regressions | ☐ |
| 6.2 | Desktop — Firefox | Same sweep on desktop Firefox | No layout/localization regressions | ☐ |
| 6.3 | Desktop — Safari | Same sweep on desktop Safari (if applicable) | No layout/localization regressions | ☐ |
| 6.4 | Mobile — iOS Safari | Same sweep on an iOS device/simulator | Responsive layout holds in both RTL and LTR; language switcher reachable and usable | ☐ |
| 6.5 | Mobile — Android Chrome | Same sweep on an Android device/emulator | Responsive layout holds in both RTL and LTR | ☐ |
| 6.6 | Cache behavior — back/forward | Log out, then use browser back button to return to a previously loaded dashboard page | No stale logged-in dashboard shell is shown from cache (per the back/forward-cache guard in `locale-router.php`) | ☐ |
| 6.7 | Cache behavior — hard refresh after locale switch | Switch language, then hard-refresh (Ctrl+Shift+R / Cmd+Shift+R) | Page still renders in the newly chosen language, not a stale cached version in the old language | ☐ |
| 6.8 | Cache behavior — CDN/edge cache (if applicable) | If the host sits behind a CDN, verify localized HTML isn't served cross-locale from a shared cache key | Persian and English visitors on the same path never receive each other's cached HTML | ☐ |

---

## Sign-off

| Field | Value |
|---|---|
| Host tested | _____________________ |
| Tester | _____________________ |
| Date | _____________________ |
| Total checks | 44 |
| Passed | ___ |
| Failed | ___ |
| Blocking failures found | ☐ Yes ☐ No |

*If any row fails, record the specific page/locale/browser combination and
file it against the relevant closure report before signing off real-host
readiness. This checklist does not replace the automated suite
(`validate_localization.py`, `check_hardcoded_ui.py`,
`check_frozen_hash_keys.py`, `build_localized_static.py --check`,
`pytest tools/localization/`, `localization_gate.py`) — it is the final
human gate that runs only against a real, deployed host.*
