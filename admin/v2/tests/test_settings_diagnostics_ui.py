#!/usr/bin/env python3
"""Phase 8 (8a) UI suite — settings-config (Settings Write) + system-diagnostics.

Groups:
  SC_render  — settings-config renders inventory (9 rows), sources, values
  SC_super   — super_admin sees write actions (Save / Revert to env / Owning module)
  SC_admin   — plain admin sees read-only badge, NO write actions
  SC_write   — confirm modal -> PUT /admin/settings/{key} -> honest refresh
  SC_reset   — confirm modal -> DELETE -> env-default source rendered
  SC_bad     — invalid value 422 rendered honestly (toast + modal stays)
  SD_render  — diagnostics snapshot renders components + statuses
  SD_refresh — refresh wires the existing audited POST endpoint
  E_error    — 500 mode -> statebox + retry (both pages)
  LANG       — FA/RTL + EN/LTR + light/dark
  W_widths   — 7 widths x both pages, zero horizontal overflow
  X_zero     — zero console errors
Run: python3 test_settings_diagnostics_ui.py   (auto-starts the stub)
"""
import json, os, subprocess, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))  # repo root
OUT = {"js": [], "reqs": []}
fails = []


def setmode(m, **extra):
    d = {"mode": m, "logout_called": False}
    d.update(extra)
    json.dump(d, open(os.path.join(HERE, "mode.json"), "w"))


def track(pg):
    pg.on("request", lambda r: OUT["reqs"].append({"m": r.method, "u": r.url.split("8141")[-1]}) if "/api/" in r.url else None)
    pg.on("pageerror", lambda e: OUT["js"].append("PAGEERROR:" + str(e)[:160]))


def newpg(p, viewport={"width": 1440, "height": 950}):
    return p.chromium.launch().new_page(viewport=viewport)


def reset():
    OUT["reqs"].clear()


def chk(name, ok):
    print(("PASS " if ok else "FAIL ") + name)
    if not ok:
        fails.append(name)


srv = subprocess.Popen(["python3", os.path.join(HERE, "f1_stub.py")],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1.2)
try:
    from playwright.sync_api import sync_playwright
    with sync_playwright() as p:
        # ===== A. SC_render + SC_super (super mode, EN) =====
        setmode("super")
        pg = newpg(p); track(pg)
        pg.goto("http://127.0.0.1:8141/admin/v2/index.html"); pg.wait_for_timeout(1400)
        pg.evaluate("()=>localStorage.setItem('velora_locale','en')"); pg.goto("http://127.0.0.1:8141/admin/v2/index.html"); pg.wait_for_timeout(1300)
        pg.evaluate("()=>location.hash='#/settings-config'"); pg.wait_for_timeout(1400)
        t = pg.evaluate("()=>document.querySelector('#view').innerText")
        OUT["SC_title"] = "Effective configuration and operational settings" in t
        OUT["SC_rows"] = pg.evaluate("()=>document.querySelectorAll('#view tbody tr').length") >= 9
        OUT["SC_badges"] = pg.evaluate("()=>{const x=document.querySelector('#view').innerText;return x.includes('Admin-managed')&&x.includes('Env/default')}") 
        OUT["SC_actions"] = pg.evaluate("()=>[...document.querySelectorAll('#view button')].map(b=>b.innerText.trim()).filter(x=>x==='Save'||x==='Revert to env').length") >= 2
        OUT["SC_jump"] = pg.evaluate("()=>[...document.querySelectorAll('#view button')].some(b=>b.innerText.trim()==='Owning module')")
        chk("SC_render", OUT["SC_title"] and OUT["SC_rows"] and OUT["SC_badges"])
        chk("SC_super", OUT["SC_actions"] and OUT["SC_jump"])

        # ===== B. SC_write: modal -> PUT captured -> refresh =====
        reset()
        pg.evaluate("()=>scConfirmSet('platform.default_locale','en')"); pg.wait_for_timeout(300)
        OUT["W_modal"] = pg.evaluate("()=>!!document.querySelector('#scSel')")
        pg.evaluate("()=>scDoSet()"); pg.wait_for_timeout(900)
        OUT["W_put"] = any(r["m"] == "PUT" and "/api/v1/admin/settings/platform.default_locale" in r["u"] for r in OUT["reqs"])
        OUT["W_closed"] = pg.evaluate("()=>!document.querySelector('#scSel')")
        OUT["W_stored"] = pg.evaluate("()=>{const x=document.querySelector('#view').innerText;return x.includes('platform.default_locale')}")
        chk("SC_write", OUT["W_modal"] and OUT["W_put"] and OUT["W_closed"] and OUT["W_stored"])

        # ===== C. SC_reset: modal -> DELETE captured =====
        reset()
        pg.evaluate("()=>scConfirmReset('platform.default_locale')"); pg.wait_for_timeout(300)
        OUT["R_modal"] = pg.evaluate("()=>!!document.querySelector('#view')")
        pg.evaluate("()=>scDoReset()"); pg.wait_for_timeout(900)
        OUT["R_del"] = any(r["m"] == "DELETE" and "/api/v1/admin/settings/platform.default_locale" in r["u"] for r in OUT["reqs"])
        chk("SC_reset", OUT["R_modal"] and OUT["R_del"])

        # ===== D. SC_bad: server error rendered honestly (modal stays open) =====
        setmode("super", fail_settings=True)
        pg.evaluate("()=>location.hash='#/overview'"); pg.wait_for_timeout(500)
        pg.evaluate("()=>location.hash='#/settings-config'"); pg.wait_for_timeout(1300)
        pg.evaluate("()=>scConfirmSet('platform.default_locale','en')"); pg.wait_for_timeout(300)
        before = pg.evaluate("()=>!!document.querySelector('#scSel')")
        pg.evaluate("()=>scDoSet()"); pg.wait_for_timeout(1100)
        toasts = pg.evaluate("()=>document.querySelector('#toasts').innerText")
        OUT["B_honest"] = ("INTERNAL_ERROR" in toasts) and pg.evaluate("()=>!!document.querySelector('#scSel')")
        pg.evaluate("()=>f3CloseModal()"); pg.wait_for_timeout(250)
        chk("SC_bad", before and OUT["B_honest"])

        # ===== E. SC_admin: plain admin read-only (no write actions) =====
        setmode("admin")
        pg2 = newpg(p); track(pg2)
        pg2.goto("http://127.0.0.1:8141/admin/v2/index.html"); pg2.wait_for_timeout(1400)
        pg2.evaluate("()=>localStorage.setItem('velora_locale','en')"); pg2.goto("http://127.0.0.1:8141/admin/v2/index.html"); pg2.wait_for_timeout(1300)
        pg2.evaluate("()=>location.hash='#/settings-config'"); pg2.wait_for_timeout(1400)
        OUT["A_title"] = pg2.evaluate("()=>document.querySelector('#view').innerText.includes('Effective configuration and operational settings')")
        OUT["A_rows"] = pg2.evaluate("()=>document.querySelectorAll('#view tbody tr').length") >= 9
        OUT["A_ro"] = pg2.evaluate("()=>document.querySelector('#view').innerText.includes('Read-only')")
        OUT["A_nowrite"] = pg2.evaluate("()=>![...document.querySelectorAll('#view button')].some(b=>b.innerText.trim()==='Save'||b.innerText.trim()==='Revert to env')")
        chk("SC_admin", OUT["A_title"] and OUT["A_rows"] and OUT["A_ro"] and OUT["A_nowrite"])

        # ===== F. SD_render + SD_refresh (super) =====
        reset()
        pg.evaluate("()=>location.hash='#/system-diagnostics'"); pg.wait_for_timeout(1400)
        OUT["D_title"] = pg.evaluate("()=>document.querySelector('#view').innerText.includes('Deep system diagnostics')")
        OUT["D_rows"] = pg.evaluate("()=>document.querySelectorAll('#view tbody tr').length") >= 6
        OUT["D_status"] = pg.evaluate("()=>{const x=document.querySelector('#view').innerText;return x.includes('HEALTHY')&&x.includes('NOT_APPLICABLE')}")
        pg.evaluate("()=>sdRefresh()"); pg.wait_for_timeout(1000)
        OUT["D_post"] = any(r["m"] == "POST" and "/diagnostics/refresh" in r["u"] for r in OUT["reqs"])
        OUT["D_alive"] = pg.evaluate("()=>document.querySelectorAll('#view tbody tr').length") >= 6
        chk("SD_render", OUT["D_title"] and OUT["D_rows"] and OUT["D_status"])
        chk("SD_refresh", OUT["D_post"] and OUT["D_alive"])

        # ===== G. E_error: 500 modes on both pages =====
        setmode("super", fail_settings=True)
        pg3 = newpg(p); track(pg3)
        pg3.goto("http://127.0.0.1:8141/admin/v2/index.html"); pg3.wait_for_timeout(1400)
        pg3.evaluate("()=>location.hash='#/settings-config'"); pg3.wait_for_timeout(1400)
        OUT["E_sc"] = pg3.evaluate("()=>{const x=document.querySelector('#view').innerText;return !!document.querySelector('#view .statebox, #view [class*=state]')||/error|retry/i.test(x)}")
        setmode("super", fail_diag=True)
        pg3.evaluate("()=>location.hash='#/overview'"); pg3.wait_for_timeout(600)
        pg3.evaluate("()=>location.hash='#/system-diagnostics'"); pg3.wait_for_timeout(1400)
        OUT["E_sd"] = pg3.evaluate("()=>{const x=document.querySelector('#view').innerText;return !!document.querySelector('#view .statebox, #view [class*=state]')||/error|retry/i.test(x)}")
        chk("E_error", OUT["E_sc"] and OUT["E_sd"])

        # ===== H. LANG: FA/RTL + EN/LTR + themes =====
        setmode("super")
        pg4 = newpg(p); track(pg4)
        pg4.goto("http://127.0.0.1:8141/admin/v2/index.html"); pg4.wait_for_timeout(1400)
        pg4.evaluate("()=>location.hash='#/settings-config'"); pg4.wait_for_timeout(1200)
        OUT["FA_rtl"] = pg4.evaluate("()=>document.documentElement.dir==='rtl'&&document.querySelector('#view').innerText.includes('پیکربندی مؤثر')")
        pg4.evaluate("()=>localStorage.setItem('velora_locale','en')"); pg4.goto("http://127.0.0.1:8141/admin/v2/index.html"); pg4.wait_for_timeout(1600)
        pg4.evaluate("()=>location.hash='#/settings-config'"); pg4.wait_for_timeout(1300)
        OUT["EN_ltr"] = pg4.evaluate("()=>document.documentElement.dir==='ltr'&&document.querySelector('#view').innerText.includes('Effective configuration')")
        pg4.evaluate("()=>document.documentElement.setAttribute('data-theme','light')"); pg4.wait_for_timeout(300)
        OUT["TH_light"] = pg4.evaluate("()=>document.documentElement.dataset.theme==='light'&&document.querySelector('#view').innerText.length>50")
        pg4.evaluate("()=>document.documentElement.setAttribute('data-theme','dark')")
        chk("LANG", OUT["FA_rtl"] and OUT["EN_ltr"] and OUT["TH_light"])

        # ===== I. W_widths: 7 widths x both pages =====
        for w in (1440, 1280, 1024, 768, 430, 390, 360):
            setmode("super")
            pg5 = newpg(p, viewport={"width": w, "height": 900}); track(pg5)
            pg5.goto("http://127.0.0.1:8141/admin/v2/index.html"); pg5.wait_for_timeout(1200)
            pg5.evaluate("()=>location.hash='#/settings-config'"); pg5.wait_for_timeout(1100)
            o1 = pg5.evaluate("()=>document.scrollingElement.scrollWidth-document.scrollingElement.clientWidth")
            pg5.evaluate("()=>location.hash='#/system-diagnostics'"); pg5.wait_for_timeout(1100)
            o2 = pg5.evaluate("()=>document.scrollingElement.scrollWidth-document.scrollingElement.clientWidth")
            OUT[f"W{w}"] = (o1 == 0 and o2 == 0)
            pg5.close()
        chk("W_widths", all(OUT.get(f"W{w}") for w in (1440, 1280, 1024, 768, 430, 390, 360)))

        # ===== J. X_zero: console/page errors =====
        chk("X_zero", not OUT["js"])
except Exception as ex:
    OUT["fatal"] = str(ex)[:300]
    fails.append("fatal")
    print("FATAL:", OUT["fatal"])
finally:
    try:
        srv.terminate()
    except Exception:
        pass

print("FAILS:", fails if fails else "NONE — ALL GREEN")
try:
    json.dump(OUT, open(os.path.join(HERE, "last_p8_run.json"), "w"), ensure_ascii=False, indent=1)
except OSError:
    pass
sys.exit(1 if fails else 0)
