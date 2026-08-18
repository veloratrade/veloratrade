#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
VELORA — Verify-Email Contract Guard  (F-A2 regression protection)
==================================================================

Run ID: VELORA-F-A2-REMEDIATION

این گارد از بازگشت سه ناسازگاری زنجیره‌ای F-A2 جلوگیری می‌کند:

  M-1  لینک تأیید ایمیل در بک‌اند با «#token=» ساخته می‌شد. مرورگر
       fragment را به سرور نمی‌فرستد و در location.search هم ظاهر
       نمی‌شود ⇒ کاربر واقعی هرگز توکن را دریافت نمی‌کرد.
  M-2  فرانت GET می‌فرستد ولی مسیر فقط POST است ⇒ 404.
  M-3  فرانت توکن را در query می‌گذارد ولی کنترلر از body می‌خواند ⇒ 422.

این ابزار فقط فایل‌ها را می‌خواند: بدون شبکه، بدون دیتابیس، بدون PHP.

قرارداد کد خروج
---------------
    0 = PASS — بدون یافتهٔ مسدودکننده
    1 = نقض قرارداد یا انحراف ساختاری (استقرار باید متوقف شود)
    2 = خطای ابزار — فایل موردنیاز یافت نشد

اصل «بدون PASS خاموش»: اگر ساختار مورد انتظار پیدا نشود، ابزار به‌جای
عبور بی‌صدا، STRUCTURAL DRIFT اعلام می‌کند و با کد ۱ خارج می‌شود.

استفاده:
    python3 tools/check_verify_email_contract.py [repo_root]
"""

import re
import sys
from pathlib import Path

# سورس بک‌اندی که لینک تأیید را می‌سازد
BACKEND_SOURCES = [
    "api/src/Auth/AuthService.php",
]

# فقط قالب مرجع بررسی می‌شود. localized/** خروجی build است (NP-5) و
# در گارد ۳ به‌صورت جداگانه برای همخوانی با قالب سنجیده می‌شود.
FRONTEND_TEMPLATE = "verify-email/index.html"

GENERATED_MIRRORS = [
    "localized/fa/verify-email/index.html",
    "localized/en/verify-email/index.html",
]

ENDPOINT = "/api/v1/auth/verify-email"

findings = []


def add(sev, rule, path, line, msg, fix):
    findings.append({"sev": sev, "rule": rule, "path": path,
                     "line": line, "msg": msg, "fix": fix})


def strip_php_comments(src: str) -> str:
    """کامنت‌ها را با فاصله جایگزین می‌کند تا شمارهٔ خط حفظ شود."""
    out = []
    i = 0
    n = len(src)
    in_s = in_d = False
    while i < n:
        c = src[i]
        nxt = src[i + 1] if i + 1 < n else ""
        if in_s:
            out.append(c)
            if c == "\\":
                if i + 1 < n:
                    out.append(nxt)
                    i += 1
            elif c == "'":
                in_s = False
        elif in_d:
            out.append(c)
            if c == "\\":
                if i + 1 < n:
                    out.append(nxt)
                    i += 1
            elif c == '"':
                in_d = False
        elif c == "'":
            in_s = True
            out.append(c)
        elif c == '"':
            in_d = True
            out.append(c)
        elif c == "/" and nxt == "/":
            while i < n and src[i] != "\n":
                i += 1
            continue
        elif c == "#":
            # ممکن است شروع کامنت باشد؛ اما «#token=» داخل رشته است و
            # در شاخهٔ رشته‌ای بالا مصرف می‌شود، پس اینجا امن است.
            while i < n and src[i] != "\n":
                i += 1
            continue
        elif c == "/" and nxt == "*":
            i += 2
            while i < n - 1 and not (src[i] == "*" and src[i + 1] == "/"):
                if src[i] == "\n":
                    out.append("\n")
                i += 1
            i += 2
            continue
        else:
            out.append(c)
        i += 1
    return "".join(out)


# ══════════════════════════════════════════════════════════════════
# گارد ۱ — هیچ لینک تأیید فعالی نباید «#token=» بسازد
# ══════════════════════════════════════════════════════════════════
def guard_backend_link(root: Path) -> bool:
    """
    برای اجتناب از grep کور و مثبت کاذب، فقط خطوطی بررسی می‌شوند که
    واقعاً یک URL تأیید ایمیل می‌سازند: خط باید هم مسیر verify-email
    را داشته باشد و هم یک الحاق توکن. کامنت‌ها حذف می‌شوند تا مستندات
    یا مثال‌های توضیحی باعث خطای کاذب نشوند.
    """
    seen_any = False
    for rel in BACKEND_SOURCES:
        p = root / rel
        if not p.is_file():
            add("P0", "VERIFY-LINK-000", rel, 0,
                "فایل سورس تولیدکنندهٔ لینک تأیید یافت نشد؛ گارد نمی‌تواند "
                "چیزی را تأیید کند.",
                "مسیر را در BACKEND_SOURCES به‌روزرسانی کنید.")
            continue

        raw = p.read_text(encoding="utf-8", errors="replace")
        code = strip_php_comments(raw)

        for idx, line in enumerate(code.splitlines(), start=1):
            if "verify-email" not in line:
                continue
            # فقط خطوطی که واقعاً توکن را به URL می‌چسبانند
            if not re.search(r'token=', line):
                continue
            seen_any = True
            if "#token=" in line:
                add("P0", "VERIFY-LINK-001", rel, idx,
                    "لینک تأیید ایمیل با fragment ساخته می‌شود "
                    "('#token='). مرورگر fragment را به سرور نمی‌فرستد و "
                    "در location.search هم دیده نمی‌شود ⇒ کاربر واقعی "
                    "هرگز توکن را دریافت نمی‌کند (M-1).",
                    "به '?token=' تغییر دهید (همان الگوی "
                    "PasswordService.php:76 برای reset-password).")

    if not seen_any and not any(f["rule"] == "VERIFY-LINK-000" for f in findings):
        add("P0", "VERIFY-LINK-000", BACKEND_SOURCES[0], 0,
            "هیچ محل تولید لینک تأیید ایمیل پیدا نشد. ساختار تغییر کرده و "
            "این گارد دیگر M-1 را پوشش نمی‌دهد (STRUCTURAL DRIFT).",
            "گارد را با ساختار جدید هماهنگ کنید؛ عبور خاموش مجاز نیست.")
    return True


# ══════════════════════════════════════════════════════════════════
# گارد ۲ — قرارداد فرانت: POST + endpoint + توکن در JSON body
# ══════════════════════════════════════════════════════════════════
def guard_frontend_contract(root: Path):
    p = root / FRONTEND_TEMPLATE
    if not p.is_file():
        add("P0", "VERIFY-FE-000", FRONTEND_TEMPLATE, 0,
            "قالب مرجع verify-email یافت نشد؛ گارد نمی‌تواند قرارداد "
            "فرانت را تأیید کند.",
            "مسیر FRONTEND_TEMPLATE را به‌روزرسانی کنید.")
        return

    src = p.read_text(encoding="utf-8", errors="replace")

    # فراخوانی مربوط به endpoint را پیدا کن (تا انتهای همان عبارت).
    call = None
    call_line = 0
    for idx, line in enumerate(src.splitlines(), start=1):
        if "VeloraData.request" in line and ENDPOINT in line:
            call = line
            call_line = idx
            break

    if call is None:
        add("P0", "VERIFY-FE-000", FRONTEND_TEMPLATE, 0,
            f"هیچ فراخوانی VeloraData.request به {ENDPOINT} پیدا نشد. "
            "ساختار صفحه تغییر کرده و این گارد دیگر M-2/M-3 را پوشش "
            "نمی‌دهد (STRUCTURAL DRIFT).",
            "گارد را با ساختار جدید هماهنگ کنید؛ عبور خاموش مجاز نیست.")
        return

    # ۱) متد باید صریحاً POST باشد. velora-data.js:123 در نبود body متد
    #    را GET می‌گذارد، پس نبودِ method صریح = بازگشت M-2.
    if not re.search(r"method\s*:\s*'POST'", call):
        add("P0", "VERIFY-FE-001", FRONTEND_TEMPLATE, call_line,
            "فراخوانی verify-email متد POST صریح ندارد. طبق "
            "public/assets/velora-data.js:123 در نبود body متد به GET "
            "پیش‌فرض می‌رود و مسیر فقط POST است ⇒ 404 (M-2).",
            "method: 'POST' را اضافه کنید.")

    # ۲) توکن نباید در query string باشد.
    if re.search(r"verify-email\?[^']*token=", call):
        add("P0", "VERIFY-FE-002", FRONTEND_TEMPLATE, call_line,
            "توکن در query string ارسال می‌شود، اما "
            "AuthController::verifyEmail آن را از $request->body "
            "می‌خواند و Request::fromGlobals این دو را ادغام نمی‌کند "
            "⇒ 422 (M-3).",
            "توکن را به JSON body منتقل کنید.")

    # ۳) توکن باید در body باشد.
    if not re.search(r"body\s*:\s*\{[^}]*\btoken\s*:", call):
        add("P0", "VERIFY-FE-003", FRONTEND_TEMPLATE, call_line,
            "توکن در JSON body ارسال نمی‌شود؛ کنترلر آن را از body "
            "می‌خواند (AuthController.php:49-55).",
            "body: { token: token, … } را اضافه کنید.")


# ══════════════════════════════════════════════════════════════════
# گارد ۳ — همخوانی FA/EN با قالب مرجع
# ══════════════════════════════════════════════════════════════════
def guard_localized_consistency(root: Path):
    """
    localized/** خروجی build است (NP-5). این گارد آن‌ها را ویرایش
    نمی‌کند، فقط تأیید می‌کند که پس از build همان قرارداد را دارند.
    ناهمخوانی یعنی build اجرا نشده است.
    """
    for rel in GENERATED_MIRRORS:
        p = root / rel
        if not p.is_file():
            add("P1", "VERIFY-L10N-000", rel, 0,
                "خروجی localized برای verify-email یافت نشد.",
                "build را اجرا کنید.")
            continue
        src = p.read_text(encoding="utf-8", errors="replace")
        line_no = 0
        found = None
        for idx, line in enumerate(src.splitlines(), start=1):
            if "VeloraData.request" in line and ENDPOINT in line:
                found, line_no = line, idx
                break
        if found is None:
            add("P1", "VERIFY-L10N-001", rel, 0,
                "فراخوانی verify-email در خروجی localized پیدا نشد.",
                "build را دوباره اجرا کنید.")
            continue
        if not re.search(r"method\s*:\s*'POST'", found) or \
           not re.search(r"body\s*:\s*\{[^}]*\btoken\s*:", found):
            add("P0", "VERIFY-L10N-002", rel, line_no,
                "خروجی localized با قرارداد قالب مرجع همخوان نیست "
                "(POST + توکن در body). یعنی build پس از اصلاح سورس "
                "اجرا نشده است.",
                "python3 tools/localization/build_localized_static.py "
                "--release-id <id>")


def main() -> int:
    root = Path(sys.argv[1] if len(sys.argv) > 1 else ".").resolve()
    if not (root / "api").is_dir():
        print(f"❌ ریشهٔ مخزن معتبر نیست: {root}", file=sys.stderr)
        return 2

    guard_backend_link(root)
    guard_frontend_contract(root)
    guard_localized_consistency(root)

    print("=" * 72)
    print("VELORA — Verify-Email Contract Guard (F-A2)")
    print(f"ریشه: {root}")
    print("=" * 72)

    if not findings:
        print("✅ PASS — قرارداد verify-email سالم است:")
        print("   • بک‌اند لینک را با '?token=' می‌سازد (M-1 بسته)")
        print("   • فرانت POST می‌فرستد (M-2 بسته)")
        print("   • توکن در JSON body است (M-3 بسته)")
        print("   • خروجی FA/EN با قالب مرجع همخوان است")
        print("=" * 72)
        return 0

    order = {"P0": 0, "P1": 1, "P2": 2}
    findings.sort(key=lambda f: (order[f["sev"]], f["path"], f["line"]))
    icon = {"P0": "🔴", "P1": "🟠", "P2": "🟡"}
    for f in findings:
        print(f"\n{icon[f['sev']]} [{f['sev']}] {f['rule']}")
        print(f"   محل  : {f['path']}:{f['line']}")
        print(f"   مسئله: {f['msg']}")
        print(f"   اصلاح: {f['fix']}")

    # انحراف ساختاری حتی وقتی P1 است مسدودکننده محسوب می‌شود.
    blocking = [f for f in findings
                if f["sev"] == "P0" or f["rule"].endswith("-000")]
    print("\n" + "=" * 72)
    if blocking:
        print(f"❌ FAIL — {len(findings)} یافته ({len(blocking)} مسدودکننده)")
        print("=" * 72)
        return 1
    print(f"⚠️ PASS با هشدار — {len(findings)} یافتهٔ غیرمسدودکننده")
    print("=" * 72)
    return 0


if __name__ == "__main__":
    sys.exit(main())
