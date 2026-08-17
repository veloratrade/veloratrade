#!/usr/bin/env python3
"""Idempotently migrate static HTML to Velora's key-based localization runtime.

This does not perform source-text translation at runtime. Legacy phrase pairs are used only
at build time to create deterministic semantic-ish keys and static catalogs.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import sys
from collections import Counter
from pathlib import Path
from typing import Iterable

from bs4 import BeautifulSoup, Comment, NavigableString, Tag

try:
    from tools.localization.route_contract import (
        ROOT,
        RouteContract,
        RouteContractError,
        RouteDefinition,
        load_route_contract,
    )
except ModuleNotFoundError:  # Direct execution: python3 tools/localization/migrate_static.py
    from route_contract import (  # type: ignore[no-redef]
        ROOT,
        RouteContract,
        RouteContractError,
        RouteDefinition,
        load_route_contract,
    )

SKIP_PARENTS = {"script", "style", "noscript", "svg", "code", "pre", "template"}
ATTRIBUTES = ("title", "placeholder", "aria-label", "alt")
META_KEYS = {"description", "twitter:title", "twitter:description"}
META_PROPERTIES = {"og:title", "og:description"}

RUNTIME_MESSAGES = {
    "common.language": ("زبان", "Language"),
    "common.loading": ("در حال بارگذاری…", "Loading…"),
    "common.save": ("ذخیره", "Save"),
    "common.cancel": ("انصراف", "Cancel"),
    "common.close": ("بستن", "Close"),
    "common.delete": ("حذف", "Delete"),
    "common.edit": ("ویرایش", "Edit"),
    "common.retry": ("تلاش مجدد", "Retry"),
    "common.confirm": ("تأیید", "Confirm"),
    "common.search": ("جستجو", "Search"),
    "common.noResults": ("نتیجه‌ای یافت نشد.", "No results found."),
    "common.optional": ("اختیاری", "Optional"),
    "common.unknown": ("نامشخص", "Unknown"),
    "common.user": ("کاربر", "User"),
    "common.trader": ("معامله‌گر", "Trader"),
    "common.account": ("حساب {number}", "Account {number}"),
    "common.notRecorded": ("ثبت نشده", "Not recorded"),
    "common.copyFailed": ("کپی ناموفق بود.", "Copy failed."),
    "common.copied": ("کپی شد.", "Copied."),
    "nav.dashboard": ("داشبورد", "Dashboard"),
    "nav.trades": ("معاملات", "Trades"),
    "nav.accounts": ("حساب‌ها", "Accounts"),
    "nav.performance": ("عملکرد", "Performance"),
    "nav.news": ("اخبار", "News"),
    "nav.profile": ("پروفایل", "Profile"),
    "nav.logout": ("خروج", "Log out"),
    "auth.signingIn": ("در حال ورود…", "Signing in…"),
    "auth.creatingAccount": ("در حال ساخت حساب…", "Creating account…"),
    "auth.sendingReset": ("در حال ارسال لینک…", "Sending reset link…"),
    "auth.resetSent": ("اگر حسابی با این ایمیل وجود داشته باشد، لینک بازیابی ارسال شد.", "If an account exists for this email, a recovery link has been sent."),
    "auth.passwordUpdated": ("رمز عبور با موفقیت تغییر کرد.", "Your password was updated successfully."),
    "auth.verifyEmail": ("لطفاً ابتدا ایمیل خود را تأیید کنید.", "Please verify your email first."),
    "auth.emailRequired": ("لطفاً ایمیل را وارد کنید.", "Please enter your email."),
    "auth.emailInvalid": ("فرمت ایمیل صحیح نیست.", "Enter a valid email address."),
    "auth.credentialsRequired": ("لطفاً ایمیل و رمز عبور را وارد کنید.", "Please enter your email and password."),
    "auth.passwordInvalid": ("رمز عبور باید حداقل ۸ کاراکتر و شامل یک حرف انگلیسی و یک عدد باشد.", "The password must be at least 8 characters and include one Latin letter and one number."),
    "auth.passwordStrong": ("✓ رمز عبور قوی است", "✓ Strong password"),
    "auth.passwordIncomplete": ("حداقل ۸ کاراکتر، شامل یک حرف انگلیسی و یک عدد{suffix}", "At least 8 characters, including one Latin letter and one number{suffix}"),
    "auth.passwordIncompleteSuffix": (" — هنوز کامل نیست", " — not complete yet"),
    "auth.passwordMismatch": ("تکرار رمز با رمز جدید یکسان نیست.", "The password confirmation does not match."),
    "auth.loginSuccess": ("✓ ورود موفق — در حال انتقال…", "✓ Signed in — redirecting…"),
    "auth.loginButton": ("ورود به حساب", "Sign in"),
    "auth.registerButton": ("ساخت حساب", "Create account"),
    "auth.registrationFailed": ("ساخت حساب ناموفق بود. دوباره تلاش کنید.", "Account creation failed. Please try again."),
    "auth.resendVerification": ("ارسال مجدد ایمیل تأیید", "Resend verification email"),
    "auth.resendCountdown": ("ارسال مجدد ایمیل تأیید ({time})", "Resend verification email ({time})"),
    "auth.sending": ("در حال ارسال…", "Sending…"),
    "auth.verificationSent": ("✓ لینک تأیید جدید ارسال شد. پوشه‌های Inbox و Spam را بررسی کنید.", "✓ A new verification link was sent. Check your inbox and spam folder."),
    "auth.alreadyVerified": ("✓ حساب شما قبلاً تأیید شده است — می‌توانید وارد شوید.", "✓ Your account is already verified — you can sign in."),
    "auth.accountVerified": ("حساب تأیید شده است", "Account verified"),
    "auth.resendFailed": ("ارسال مجدد ایمیل ناموفق بود.", "The verification email could not be resent."),
    "auth.resendLimited": ("محدودیت امنیتی: حداکثر ۳ بار در ۲۴ ساعت.", "Security limit: no more than 3 attempts in 24 hours."),
    "auth.sendRecovery": ("ارسال لینک بازیابی", "Send recovery link"),
    "auth.recoveryFailed": ("ارسال لینک بازیابی ناموفق بود.", "The recovery link could not be sent."),
    "auth.resetTokenMissing": ("توکن بازیابی در لینک وجود ندارد یا لینک ناقص است.", "The recovery token is missing or the link is incomplete."),
    "auth.savingPassword": ("در حال ثبت…", "Saving…"),
    "auth.savePassword": ("ثبت رمز جدید", "Save new password"),
    "auth.resetComplete": ("رمز عبور با موفقیت بازنشانی شد. اکنون می‌توانید وارد شوید.", "Your password was reset successfully. You can now sign in."),
    "auth.resetLinkInvalid": ("لینک بازیابی نامعتبر یا منقضی شده است.", "The recovery link is invalid or has expired."),
    "auth.verifyLinkInvalidTitle": ("لینک تأیید نامعتبر است", "Invalid verification link"),
    "auth.verifyTokenMissing": ("توکن تأیید در لینک وجود ندارد.", "The verification token is missing from the link."),
    "auth.emailVerifiedTitle": ("ایمیل تأیید شد", "Email verified"),
    "auth.emailVerified": ("ایمیل شما با موفقیت تأیید شد.", "Your email was verified successfully."),
    "auth.verificationFailedTitle": ("تأیید ایمیل ناموفق بود", "Email verification failed"),
    "auth.verificationLinkExpired": ("لینک تأیید نامعتبر یا منقضی شده است.", "The verification link is invalid or has expired."),
    "auth.serverErrorTitle": ("خطا در ارتباط با سرور", "Server connection error"),
    "auth.tryAgainOrResend": ("دوباره تلاش کنید یا لینک جدید بگیرید.", "Try again or request a new link."),
    "dashboard.empty": ("هنوز داده‌ای برای نمایش وجود ندارد.", "There is no data to display yet."),
    "dashboard.firstTrade": ("اولین معامله خود را ثبت کنید.", "Record your first trade."),
    "dashboard.tradeCount": ("{count} معامله", "{count} trades"),
    "dashboard.winRate": ("نرخ برد {rate}", "Win rate {rate}"),
    "dashboard.noStrategy": ("بدون استراتژی", "No strategy"),
    "dashboard.noAccountsTitle": ("هیچ حساب متاتریدری متصل نیست", "No MetaTrader account is connected"),
    "notFound.title": ("صفحه پیدا نشد", "Page not found"),
    "notFound.description": ("صفحه‌ای که به دنبال آن هستید وجود ندارد یا منتقل شده است.", "The page you are looking for does not exist or has moved."),
    "notFound.returnHome": ("بازگشت به صفحه اصلی", "Return to home"),
    "dashboard.noAccountsBody": ("با اتصال حساب MT4 یا MT5 از طریق پل ابری MetaApi، تاریخچه و معاملات زنده خودکار همگام‌سازی می‌شوند.", "Connect an MT4 or MT5 account through the MetaApi cloud bridge to synchronise history and live trades automatically."),
    "dashboard.connectMetaTrader": ("+ اتصال حساب متاتریدر (MT4 / MT5)", "+ Connect MetaTrader account (MT4 / MT5)"),
    "dashboard.disconnectAccount": ("قطع اتصال حساب", "Disconnect account"),
    "dashboard.details": ("جزئیات", "Details"),
    "dashboard.syncFailed": ("همگام‌سازی حساب ناموفق بود.", "Account synchronisation failed."),
    "dashboard.accountNotFound": ("اطلاعات حساب یافت نشد.", "Account information was not found."),
    "dashboard.accountDetails": ("{provider} · {number} | موجودی: {balance} | اکویتی: {equity}", "{provider} · {number} | Balance: {balance} | Equity: {equity}"),
    "trades.empty": ("هنوز معامله‌ای ثبت نشده است.", "No trades have been recorded yet."),
    "trades.setupMissing": ("ستاپ ثبت نشده", "No setup recorded"),
    "trades.entrySummary": ("جهت {direction} · قیمت ورود {price} · حجم {volume}", "Direction {direction} · Entry price {price} · Volume {volume}"),
    "trades.positiveOutcome": ("نتیجه مثبت: {notes}", "Positive result: {notes}"),
    "trades.negativeOutcome": ("نتیجه منفی: {notes}", "Negative result: {notes}"),
    "trades.noExitNotes": ("یادداشت خروج برای این معامله ثبت نشده است.", "No exit note has been recorded for this trade."),
    "trades.symbolRequired": ("نماد را وارد کنید.", "Enter a symbol."),
    "trades.coreFieldsRequired": ("قیمت ورود، خروج و حجم الزامی است.", "Entry price, exit price and volume are required."),
    "trades.timesRequired": ("زمان باز و بسته الزامی است.", "Open and close times are required."),
    "trades.saving": ("در حال ثبت…", "Saving…"),
    "trades.save": ("ثبت معامله در ژورنال", "Save trade to journal"),
    "trades.created": ("معامله ثبت شد!", "Trade saved!"),
    "trades.createFailed": ("ثبت معامله ناموفق بود.", "The trade could not be saved."),
    "trades.loadFailed": ("بارگذاری معاملات ناموفق بود.", "Trades could not be loaded."),
    "trades.deleteConfirm": ("آیا از حذف این معامله مطمئن هستید؟", "Are you sure you want to delete this trade?"),
    "trades.deleted": ("معامله حذف شد.", "Trade deleted."),
    "accounts.empty": ("هنوز حساب معاملاتی متصل نشده است.", "No trading account has been connected yet."),
    "accounts.connecting": ("در حال اتصال حساب…", "Connecting account…"),
    "accounts.connected": ("حساب با موفقیت متصل شد.", "Account connected successfully."),
    "accounts.sync": ("همگام‌سازی", "Synchronise"),
    "accounts.syncing": ("در حال همگام‌سازی…", "Synchronising…"),
    "accounts.synced": ("همگام‌سازی کامل شد.", "Synchronisation complete."),
    "accounts.loginRequired": ("ابتدا شماره حساب را وارد کنید.", "Enter the account number first."),
    "accounts.investorPasswordRequired": ("پسورد فقط‌خواندنی سرمایه‌گذار الزامی است.", "The read-only investor password is required."),
    "accounts.detecting": ("در حال شناسایی…", "Detecting…"),
    "accounts.detectServer": ("شناسایی خودکار سرور", "Auto-detect server"),
    "accounts.suggestionsReady": ("سرورهای پیشنهادی آماده‌اند؛ یک سرور را انتخاب کنید و سپس اتصال را بزنید.", "Suggested servers are ready. Choose one, then select Connect."),
    "accounts.selectDetectedServer": ("ابتدا سرور را شناسایی و یکی از پیشنهادها را انتخاب کنید.", "Detect the server and choose one of the suggestions first."),
    "accounts.requiredFields": ("سرور، شماره حساب و پسورد سرمایه‌گذار الزامی است.", "Server, account number and investor password are required."),
    "accounts.balance": ("موجودی", "Balance"),
    "accounts.equity": ("اکویتی", "Equity"),
    "accounts.leverage": ("اهرم", "Leverage"),
    "accounts.currency": ("ارز", "Currency"),
    "accounts.lastSync": ("آخرین همگام‌سازی: {date}", "Last synchronised: {date}"),
    "accounts.syncAgain": ("بررسی و همگام‌سازی مجدد", "Check and synchronise again"),
    "accounts.syncSubmitted": ("درخواست همگام‌سازی ثبت شد. وضعیت: {status}، آخرین همگام‌سازی: {date}، کارها: {jobs}", "Synchronisation requested. Status: {status}, last synchronised: {date}, jobs: {jobs}"),
    "accounts.encrypting": ("در حال رمزگذاری و ساخت حساب MetaApi…", "Encrypting credentials and creating the MetaApi account…"),
    "accounts.created": ("✓ حساب ساخته شد — شناسه: {id}. تاریخچه ۱۲ ماهه در صف همگام‌سازی قرار گرفت.", "✓ Account created — ID: {id}. Twelve months of history were queued for synchronisation."),
    "accounts.connectAndSync": ("اتصال و شروع همگام‌سازی", "Connect and start synchronisation"),
    "accounts.detectAndExtract": ("در حال شناسایی سرور و دریافت اطلاعات…", "Detecting server and retrieving account details…"),
    "accounts.connectDetails": ("اتصال و دریافت مشخصات حساب", "Connect and retrieve account details"),
    "accounts.connectionSuccess": ("اتصال و شناسایی موفق بود. سرور، بروکر، موجودی، اکویتی، اهرم و ارز حساب خودکار دریافت شد.", "Connection and detection succeeded. Server, broker, balance, equity, leverage and account currency were retrieved automatically."),
    "accounts.connectFailed": ("اتصال حساب ناموفق بود.", "Account connection failed."),
    "accounts.disconnectConfirm": ("آیا از قطع اتصال این حساب معاملاتی مطمئن هستید؟", "Are you sure you want to disconnect this trading account?"),
    "accounts.deleteFailed": ("حذف حساب ناموفق بود.", "The account could not be removed."),
    "accounts.loadFailed": ("بارگذاری حساب‌ها ناموفق بود.", "Accounts could not be loaded."),
    "profile.saved": ("تغییرات ذخیره شد.", "Changes saved."),
    "profile.role.admin": ("مدیر سیستم", "Administrator"),
    "profile.role.user": ("کاربر", "User"),
    "profile.passwordFieldsRequired": ("هر دو فیلد را پر کنید.", "Complete both password fields."),
    "profile.passwordTooShort": ("رمز جدید باید حداقل ۸ کاراکتر باشد.", "The new password must be at least 8 characters."),
    "profile.changingPassword": ("در حال تغییر…", "Changing…"),
    "profile.changePassword": ("تغییر رمز عبور", "Change password"),
    "profile.passwordChanged": ("✓ رمز عبور تغییر کرد و همه نشست‌ها باطل شدند. دوباره وارد شوید.", "✓ Your password was changed and all sessions were revoked. Please sign in again."),
    "admin.loadingUsers": ("در حال بارگذاری کاربران…", "Loading users…"),
    "admin.userUpdated": ("اطلاعات کاربر به‌روزرسانی شد.", "User updated."),
    "admin.totalUsers": ("کل کاربران", "Total users"),
    "admin.regularUsers": ("کاربران عادی", "Regular users"),
    "admin.totalTrades": ("کل معاملات", "Total trades"),
    "admin.suspended": ("مسدود", "Suspended"),
    "admin.adminCount": ("+{count} مدیر", "+{count} admin"),
    "admin.onPlatform": ("در پلتفرم", "On the platform"),
    "admin.needsReview": ("نیاز به بررسی", "Needs review"),
    "admin.userCount": ("{count} کاربر", "{count} users"),
    "admin.usersShown": ("{count} کاربر نمایش داده می‌شود", "Showing {count} users"),
    "admin.noUsers": ("کاربری یافت نشد", "No users found"),
    "admin.role.admin": ("مدیر", "Admin"),
    "admin.role.user": ("کاربر", "User"),
    "admin.status.suspended": ("مسدود", "Suspended"),
    "admin.previous": ("قبلی", "Previous"),
    "admin.next": ("بعدی", "Next"),
    "admin.forbidden": ("این صفحه فقط برای مدیران است و شما دسترسی لازم را ندارید.", "This page is restricted to administrators and you do not have access."),
    "admin.loadFailed": ("بارگذاری اطلاعات مدیریت ناموفق بود.", "Admin data could not be loaded."),
    "status.active": ("فعال", "Active"),
    "status.inactive": ("غیرفعال", "Inactive"),
    "status.pending": ("در انتظار", "Pending"),
    "status.connected": ("متصل", "Connected"),
    "status.disconnected": ("قطع", "Disconnected"),
    "status.syncing": ("در حال همگام‌سازی", "Synchronising"),
    "status.failed": ("ناموفق", "Failed"),
    "status.completed": ("تکمیل‌شده", "Completed"),
    "status.buy": ("خرید", "Buy"),
    "status.sell": ("فروش", "Sell"),
    "errors.api": ("ارتباط با سرور ناموفق بود. دوباره تلاش کنید.", "The server request failed. Please try again."),
    "errors.network": ("اتصال شبکه برقرار نیست.", "Network connection is unavailable."),
    "errors.unknown": ("خطای پیش‌بینی‌نشده‌ای رخ داد.", "An unexpected error occurred."),
    "errors.validation": ("اطلاعات واردشده معتبر نیست.", "The submitted information is invalid."),
    "errors.unauthorized": ("برای ادامه وارد حساب خود شوید.", "Sign in to continue."),
    "errors.forbidden": ("اجازه انجام این عملیات را ندارید.", "You do not have permission to perform this action."),
    "errors.notFound": ("مورد درخواستی پیدا نشد.", "The requested item was not found."),
    "errors.conflict": ("این تغییر با وضعیت فعلی سازگار نیست.", "This change conflicts with the current state."),
    "errors.rateLimited": ("درخواست‌ها بیش از حد مجاز است. کمی بعد تلاش کنید.", "Too many requests. Please try again shortly."),
    "errors.http.400": ("درخواست نامعتبر است.", "The request is invalid."),
    "errors.http.401": ("نشست شما پایان یافته است. دوباره وارد شوید.", "Your session has expired. Please sign in again."),
    "errors.http.403": ("دسترسی به این بخش مجاز نیست.", "Access to this section is not permitted."),
    "errors.http.404": ("مورد درخواستی پیدا نشد.", "The requested item was not found."),
    "errors.http.405": ("این روش درخواست مجاز نیست.", "This request method is not allowed."),
    "errors.http.409": ("این عملیات با وضعیت فعلی تداخل دارد.", "This operation conflicts with the current state."),
    "errors.http.422": ("اطلاعات واردشده معتبر نیست.", "The submitted information is invalid."),
    "errors.http.429": ("درخواست‌ها بیش از حد مجاز است. کمی بعد تلاش کنید.", "Too many requests. Please try again shortly."),
    "errors.http.500": ("خطای داخلی سرور رخ داد.", "An internal server error occurred."),
    "errors.http.502": ("سرویس بالادستی در دسترس نیست.", "The upstream service is unavailable."),
    "errors.http.503": ("سرویس موقتاً در دسترس نیست.", "The service is temporarily unavailable."),
    "errors.http.504": ("پاسخ سرویس بیش از حد طول کشید.", "The service took too long to respond."),
}


def compact(value: str) -> str:
    return " ".join(value.split())


class MigrationSafetyError(RuntimeError):
    """Raised when migration safety preconditions are not satisfied."""


def _validate_targets(
    files: Iterable[Path],
    contract: RouteContract,
) -> list[Path]:
    allowed = {path.resolve() for path in contract.canonical_paths}
    validated: list[Path] = []
    for path in files:
        resolved = path.resolve()
        if resolved not in allowed:
            raise MigrationSafetyError(
                f"migration target is outside canonical route contract: {path}"
            )
        relative = resolved.relative_to(contract.root)
        if relative.parts and relative.parts[0] == "en":
            raise MigrationSafetyError(f"migration target inside en/** is forbidden: {relative}")
        if relative.parts and relative.parts[0] == "localized":
            raise MigrationSafetyError(
                f"migration target inside localized/** is forbidden: {relative}"
            )
        validated.append(resolved)
    if len(validated) != len(allowed):
        raise MigrationSafetyError(
            "migration target count differs from canonical template count: "
            f"targets={len(validated)} canonical={len(allowed)}"
        )
    return sorted(validated, key=lambda path: path.relative_to(contract.root).as_posix())


def canonical_template_files(contract: RouteContract) -> list[Path]:
    """Return only canonical templates declared by the shared route contract."""
    return _validate_targets(contract.canonical_paths, contract)


def route_scope(route: RouteDefinition) -> str:
    """Derive a deterministic key scope from the canonical route declaration."""
    output = route.outputs[0]
    if output == "index.html":
        return "landing"
    route_name = output[: -len("/index.html")] if output.endswith("/index.html") else output
    route_name = route_name.rsplit(".", 1)[0]
    value = route_name.replace("/", ".")
    return re.sub(r"[^a-zA-Z0-9.]+", "_", value).strip("._") or "page"


def route_scopes(contract: RouteContract) -> dict[str, str]:
    return {route.template: route_scope(route) for route in contract.routes}


def _repository_is_dirty(root: Path) -> bool:
    result = subprocess.run(
        ["git", "-C", str(root), "status", "--porcelain", "--untracked-files=normal"],
        check=False,
        capture_output=True,
        text=True,
    )
    if result.returncode != 0:
        raise MigrationSafetyError(
            f"unable to read git working tree status: {result.stderr.strip()}"
        )
    return bool(result.stdout.strip())


def _require_clean_working_tree(root: Path) -> None:
    if _repository_is_dirty(root):
        raise MigrationSafetyError("working tree is dirty; --apply is blocked")


def _locale_settings(root: Path) -> tuple[str, str]:
    path = root / "public/locales/manifest.json"
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise MigrationSafetyError(f"unable to load locale manifest: {exc}") from exc
    version = payload.get("version")
    default_locale = payload.get("defaultLocale")
    if not isinstance(version, str) or not version:
        raise MigrationSafetyError("locale manifest version is missing")
    if not isinstance(default_locale, str) or not default_locale:
        raise MigrationSafetyError("locale manifest defaultLocale is missing")
    return version, default_locale


def slug(value: str) -> str:
    ascii_words = re.findall(r"[a-z0-9]+", value.lower())
    base = ".".join(ascii_words[:7])[:58].strip(".") or "message"
    return base


def load_pairs() -> tuple[dict[str, str], dict[str, str]]:
    fa_to_en = json.loads((Path(__file__).parent / "legacy-phrase-pairs.json").read_text("utf-8"))
    en_to_fa = {en: fa for fa, en in fa_to_en.items() if en and fa != en}
    manual_reverse = json.loads((Path(__file__).parent / "manual-english-to-persian.json").read_text("utf-8"))
    en_to_fa.update(manual_reverse)
    for en, fa in manual_reverse.items():
        fa_to_en.setdefault(fa, en)
    return fa_to_en, en_to_fa


def pair_for(value: str, fa_to_en: dict[str, str], en_to_fa: dict[str, str]) -> tuple[str, str] | None:
    value = compact(value)
    if not value or len(value) < 2 or re.fullmatch(r"[\d۰-۹٠-٩\W_]+", value):
        return None
    if re.search(r"[\u0600-\u06ff]", value):
        english = fa_to_en.get(value)
        return (value, compact(english)) if english and english != value else None
    persian = en_to_fa.get(value)
    return (compact(persian), value) if persian else None


def collect_pair_counts(files: list[Path], fa_to_en: dict[str, str], en_to_fa: dict[str, str]) -> Counter:
    counts: Counter = Counter()
    for path in files:
        soup = BeautifulSoup(path.read_text("utf-8", errors="ignore"), "html.parser")
        for node in soup.find_all(string=True):
            if isinstance(node, Comment) or not node.parent or node.parent.name in SKIP_PARENTS or node.parent.name == "[document]":
                continue
            pair = pair_for(str(node), fa_to_en, en_to_fa)
            if pair:
                counts[pair] += 1
        for tag in soup.find_all(True):
            for attribute in ATTRIBUTES:
                if tag.has_attr(attribute):
                    pair = pair_for(str(tag[attribute]), fa_to_en, en_to_fa)
                    if pair:
                        counts[pair] += 1
            if tag.name == "meta" and (tag.get("name") in META_KEYS or tag.get("property") in META_PROPERTIES):
                pair = pair_for(str(tag.get("content", "")), fa_to_en, en_to_fa)
                if pair:
                    counts[pair] += 1
    return counts


def key_for(pair: tuple[str, str], scope: str, counts: Counter, registry: dict[tuple[str, str], str]) -> str:
    if pair in registry:
        return registry[pair]
    fa, en = pair
    namespace = "common" if counts[pair] > 1 else "pages." + scope
    digest = hashlib.sha1((fa + "\0" + en).encode("utf-8")).hexdigest()[:8]
    key = f"{namespace}.{slug(en)}.{digest}"
    registry[pair] = key
    return key


def remove_legacy(soup: BeautifulSoup) -> None:
    # Clean an early migration artefact that could annotate the HTML doctype as text.
    for child in list(soup.contents):
        if isinstance(child, Tag) and child.get("data-i18n") == "common.html.cddaefda":
            child.decompose()
    for node in soup.select(
        'script[data-velora-i18n], script[data-velora-lang-redirect], '
        'script[src^="/public/assets/velora-i18n.js"], '
        'script[src^="/public/assets/velora-locale-registry.js"], '
        'script[src^="/public/assets/velora-locale-bootstrap.js"], '
        'script[src^="/public/assets/velora-localization.js"], '
        'script[src^="/public/assets/velora-data.js"], '
        'script[src^="/public/assets/velora-dynamic-content.js"], '
        'link[href^="/public/assets/velora-localization.css"]'
    ):
        node.decompose()
    for node in list(soup.find_all("script")):
        body = node.string or ""
        # Remove only small legacy locale redirect/detection shims, never application scripts.
        if len(body) < 5000 and (
            "const navLang" in body
            or ("navigator.language" in body and "window.location.replace" in body)
            or ("htmlEl.setAttribute('lang'" in body and "localStorage.getItem('velora_language'" in body)
        ):
            node.decompose()


def inject_foundation(
    soup: BeautifulSoup,
    route_locale: str,
    version: str,
) -> None:
    html = soup.html
    if html:
        html["data-route-locale"] = route_locale
    head = soup.head
    if not head:
        head = soup.new_tag("head")
        if html:
            html.insert(0, head)
        else:
            soup.insert(0, head)
    cache_buster = f"?v={version}"
    assets = [
        ("script", {"src": "/public/assets/velora-locale-registry.js" + cache_buster}),
        ("script", {"src": "/public/assets/velora-locale-bootstrap.js" + cache_buster}),
        ("link", {"rel": "stylesheet", "href": "/public/assets/velora-localization.css" + cache_buster}),
        ("script", {"src": "/public/assets/velora-localization.js" + cache_buster}),
        ("script", {"src": "/public/assets/velora-data.js" + cache_buster}),
        ("script", {"src": "/public/assets/velora-dynamic-content.js" + cache_buster, "defer": ""}),
    ]
    anchor = head.find("meta", attrs={"charset": True})
    for name, attributes in assets:
        node = soup.new_tag(name, **attributes)
        if anchor:
            anchor.insert_after(node)
            anchor = node
        else:
            head.append(node)


def annotate_text_node(
    soup: BeautifulSoup,
    node: NavigableString,
    key: str,
) -> None:
    parent = node.parent
    if not parent:
        return
    substantive = [child for child in parent.children if isinstance(child, Tag) or (isinstance(child, NavigableString) and compact(str(child)))]
    if len(substantive) == 1 and substantive[0] is node:
        parent["data-i18n"] = key
        return
    original = str(node)
    leading = original[: len(original) - len(original.lstrip())]
    trailing = original[len(original.rstrip()):]
    span = soup.new_tag("span")
    span["data-i18n"] = key
    span.string = compact(original)
    replacements = []
    if leading:
        replacements.append(NavigableString(leading))
    replacements.append(span)
    if trailing:
        replacements.append(NavigableString(trailing))
    node.replace_with(*replacements)


def migrate_file(
    path: Path,
    fa_to_en: dict[str, str],
    en_to_fa: dict[str, str],
    counts: Counter,
    registry: dict[tuple[str, str], str],
    catalogs: dict[str, dict[str, str]],
    *,
    source_locale: str,
    scope: str,
    version: str,
) -> tuple[int, int]:
    source = path.read_text("utf-8", errors="ignore")
    soup = BeautifulSoup(source, "html.parser")
    remove_legacy(soup)
    inject_foundation(soup, source_locale, version)
    text_count = attribute_count = 0

    # Snapshot first: wrapping a mixed text node creates a translated span that must not be revisited.
    for node in list(soup.find_all(string=True)):
        if isinstance(node, Comment) or not node.parent or node.parent.name in SKIP_PARENTS or node.parent.name == "[document]":
            continue
        if node.parent.has_attr("data-i18n") or node.find_parent(attrs={"data-no-translate": True}):
            continue
        pair = pair_for(str(node), fa_to_en, en_to_fa)
        if not pair:
            continue
        key = key_for(pair, scope, counts, registry)
        catalogs["fa"][key], catalogs["en"][key] = pair
        annotate_text_node(soup, node, key)
        text_count += 1

    for tag in soup.find_all(True):
        if tag.find_parent(attrs={"data-no-translate": True}):
            continue
        for attribute in ATTRIBUTES:
            if not tag.has_attr(attribute):
                continue
            pair = pair_for(str(tag[attribute]), fa_to_en, en_to_fa)
            if not pair:
                continue
            key = key_for(pair, scope, counts, registry)
            catalogs["fa"][key], catalogs["en"][key] = pair
            tag[f"data-i18n-{attribute}"] = key
            attribute_count += 1
        if tag.name == "meta" and (tag.get("name") in META_KEYS or tag.get("property") in META_PROPERTIES):
            pair = pair_for(str(tag.get("content", "")), fa_to_en, en_to_fa)
            if pair:
                key = key_for(pair, scope, counts, registry)
                catalogs["fa"][key], catalogs["en"][key] = pair
                tag["data-i18n-content"] = key
                attribute_count += 1

    output = str(soup)
    if soup.html:
        # BeautifulSoup preserves whitespace after the doctype and would add one
        # blank line on every run. Normalize it so repeated apply runs are stable.
        output = re.sub(r"^\s*<!doctype\s+html\s*>\s*", "", output, flags=re.IGNORECASE)
        output = "<!DOCTYPE html>\n" + output.lstrip()
    path.write_text(output, "utf-8")
    return text_count, attribute_count


def write_catalog(
    root: Path,
    locale: str,
    messages: dict[str, str],
    version: str,
) -> None:
    output = {
        "$schema": "./catalog.schema.json",
        "_meta": {
            "locale": locale,
            "version": version,
            "source": "tools/localization/migrate_static.py"
        },
        "messages": dict(sorted(messages.items()))
    }
    target = root / "public" / "locales" / f"{locale}.json"
    target.write_text(json.dumps(output, ensure_ascii=False, indent=2) + "\n", "utf-8")


def _parse_args(argv: Iterable[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Migrate only canonical route templates to localization keys."
    )
    parser.add_argument(
        "--root",
        default=str(ROOT),
        help="Repository root (default: inferred from route_contract.py).",
    )
    parser.add_argument(
        "--apply",
        action="store_true",
        help="Apply migration changes. Without this flag the command is read-only.",
    )
    return parser.parse_args(argv)


def _scope_payload(files: list[Path], root: Path, *, apply: bool) -> dict[str, object]:
    targets = [path.relative_to(root).as_posix() for path in files]
    return {
        "mode": "apply" if apply else "dry-run",
        "readOnly": not apply,
        "canonicalTemplates": len(files),
        "targets": targets,
        "collisions": 0,
        "outsideContract": 0,
        "enTargets": sum(path == "en" or path.startswith("en/") for path in targets),
        "localizedTargets": sum(
            path == "localized" or path.startswith("localized/") for path in targets
        ),
    }


def main(argv: Iterable[str] | None = None) -> int:
    args = _parse_args(argv)
    root = Path(args.root).resolve()
    try:
        contract = load_route_contract(root)
        files = canonical_template_files(contract)
        scopes = route_scopes(contract)
        version, source_locale = _locale_settings(root)
        if source_locale not in contract.locales:
            raise MigrationSafetyError(
                f"default locale is not enabled by route contract: {source_locale}"
            )

        scope_report = _scope_payload(files, root, apply=args.apply)
        print(f"MIGRATION_SCOPE_OK canonical_templates={len(files)}")
        print(json.dumps(scope_report, ensure_ascii=False, indent=2))

        if args.apply:
            _require_clean_working_tree(root)

        fa_to_en, en_to_fa = load_pairs()
        counts = collect_pair_counts(files, fa_to_en, en_to_fa)
        if not args.apply:
            print(json.dumps({
                "mode": "dry-run",
                "readOnly": True,
                "canonicalTemplates": len(files),
                "translatablePairs": len(counts),
                "occurrences": sum(counts.values()),
            }, ensure_ascii=False, indent=2))
            return 0

        catalogs = {"fa": {}, "en": {}}
        # Preserve already-generated keys on repeated runs. The migration is intentionally
        # append-only unless a catalog is explicitly removed before regeneration.
        for locale in ("fa", "en"):
            existing_path = root / "public" / "locales" / f"{locale}.json"
            if existing_path.exists():
                try:
                    existing = json.loads(existing_path.read_text("utf-8"))
                    catalogs[locale].update(existing.get("messages", existing))
                except (json.JSONDecodeError, OSError):
                    pass
        for key, (fa, en) in RUNTIME_MESSAGES.items():
            catalogs["fa"][key] = fa
            catalogs["en"][key] = en

        registry: dict[tuple[str, str], str] = {}
        totals = Counter()
        for path in files:
            relative = path.relative_to(root).as_posix()
            text_count, attribute_count = migrate_file(
                path,
                fa_to_en,
                en_to_fa,
                counts,
                registry,
                catalogs,
                source_locale=source_locale,
                scope=scopes[relative],
                version=version,
            )
            totals["text"] += text_count
            totals["attributes"] += attribute_count
        for locale in ("fa", "en"):
            write_catalog(root, locale, catalogs[locale], version)
        print(json.dumps({
            "mode": "apply",
            "readOnly": False,
            "canonicalTemplates": len(files),
            "textNodes": totals["text"],
            "attributes": totals["attributes"],
            "catalogKeys": len(catalogs["en"]),
            "reusedPairs": sum(1 for count in counts.values() if count > 1),
        }, ensure_ascii=False, indent=2))
        return 0
    except (MigrationSafetyError, RouteContractError, OSError, UnicodeError) as exc:
        print(f"MIGRATION_SAFETY_FAILED {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
