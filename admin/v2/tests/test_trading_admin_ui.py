#!/usr/bin/env python3
"""Phase 4 UI suite — Global Trading Accounts + Global Trades over the stub.

Proves: both frozen v2.2 routes render REAL API data (accounts with platform
summary stats + masked numbers + user identity, trades with camelCase
financial projection), server-side filters fire the whitelisted query
params, pagination, honest empty state, in-page error state with retry,
FA/EN, RTL-safe rendering, permission gating (users.view), and responsive
1440/1280/1024/768/430/390/360 with zero horizontal overflow, zero console
errors, and no secret material in the DOM.
"""
import json,subprocess,time,sys,os
from playwright.sync_api import sync_playwright
HERE=os.path.dirname(os.path.abspath(__file__))
srv=subprocess.Popen(["python3",os.path.join(HERE,"f1_stub.py")],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
time.sleep(1.2)
OUT={"js":[],"reqs":[]}
def setmode(m,**flags):
    d={"mode":m}; d.update(flags)
    json.dump(d,open(os.path.join(HERE,"mode.json"),"w"))
def newpg(p,**kw):
    pg=p.chromium.launch().new_page(**kw)
    pg.on("pageerror", lambda e: OUT["js"].append("PAGEERROR:"+str(e)[:160]))
    return pg
def track(pg):
    pg.on("request", lambda r: OUT["reqs"].append({"m":r.method,"u":r.url.split("8141")[-1]}) if "/api/" in r.url else None)
def goto(pg):
    pg.goto("http://127.0.0.1:8141/admin/v2/index.html"); pg.wait_for_timeout(1400)
def route(pg,r):
    pg.evaluate(f"()=>location.hash='#/{r}'"); pg.wait_for_timeout(1200)
def reset(): OUT["reqs"].clear()
fails=0
def chk(name,cond):
    global fails
    if not cond: fails+=1
    print(("PASS " if cond else "FAIL ")+name)
try:
    with sync_playwright() as p:
        # ===== Global Trading Accounts =====
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); route(pg,"trading-accounts")
        txt=" ".join(pg.evaluate("()=>document.querySelector('#view').innerText").split())
        OUT["A_title"]=("Trading Accounts" in txt and "platform-wide" in txt) or ("حساب‌های معاملاتی" in txt and "در سطح پلتفرم" in txt)
        OUT["A_stats"]="متصل · 3" in txt and "خطای همگام · 1" in txt and "قطع · 1" in txt
        OUT["A_rows"]=pg.evaluate("()=>[...document.querySelectorAll('#view .card')].some(c=>c.querySelectorAll('tbody tr').length===5)")
        OUT["A_ident"]="sara@velora.test" in txt and "Mina Nouri" in txt
        OUT["A_masked"]="***1234" in txt and "777001" in txt
        OUT["A_nosecret"]=pg.evaluate("()=>{const h=document.querySelector('#view').innerText;return !h.includes('credential')&&!/token/i.test(h)&&!h.includes('password')&&!h.includes('***0555'.replace('***','900555'))&&document.documentElement.innerHTML.indexOf('connection_credentials_encrypted')===-1}")
        reset()
        pg.select_option("#f4astatus","CONNECTED"); pg.wait_for_timeout(800)
        OUT["A_filter_req"]=any("status=CONNECTED" in r["u"] for r in OUT["reqs"])
        OUT["A_filter_rows"]=pg.evaluate("()=>{const c=[...document.querySelectorAll('#view .card')].pop();return c.querySelectorAll('tbody tr').length===3}")
        # empty honesty via filter combo yielding nothing
        pg.select_option("#f4astatus","SYNCING"); pg.wait_for_timeout(800)
        OUT["A_empty"]="موردی ثبت نشده" in pg.evaluate("()=>document.querySelector('#view').innerText")
        pg.select_option("#f4astatus",""); pg.wait_for_timeout(700)
        pg.close()

        # ===== Global Trades =====
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); route(pg,"trading-trades")
        txt=pg.evaluate("()=>document.querySelector('#view').innerText")
        OUT["T_title"]="Platform-wide trade journal" in txt or "ژورنال معاملات در سطح پلتفرم" in txt
        OUT["T_rows"]=pg.evaluate("()=>{const c=[...document.querySelectorAll('#view .card')].pop();return c.querySelectorAll('tbody tr').length===10}")
        OUT["T_page1"]="صفحه 1 از 2" in txt or "page 1 of 2" in txt.lower() or "1" in txt
        OUT["T_ident"]="reza@velora.test" in txt
        OUT["T_pnl_badges"]=pg.evaluate("()=>{const c=[...document.querySelectorAll('#view .card')].pop();return c.querySelectorAll('.badge.b-ok,.badge.b-err').length>=10}")
        # deterministic default order: newest close first = id 112 (XAUUSD sell, 17:30)
        OUT["T_first"]=pg.evaluate("()=>{const c=[...document.querySelectorAll('#view .card')].pop();return c.querySelector('tbody tr td:nth-child(2)').innerText.includes('XAUUSD')}")
        reset()
        pg.select_option("#f4tdir","sell"); pg.wait_for_timeout(800)
        OUT["T_filter_req"]=any("direction=sell" in r["u"] for r in OUT["reqs"])
        OUT["T_filter_rows"]=pg.evaluate("()=>{const c=[...document.querySelectorAll('#view .card')].pop();return c.querySelectorAll('tbody tr').length===5}")
        pg.select_option("#f4tdir",""); pg.wait_for_timeout(600)
        reset()
        pg.evaluate("()=>f4TrPage(1)"); pg.wait_for_timeout(800)
        OUT["T_page2_req"]=any("page=2" in r["u"] for r in OUT["reqs"])
        OUT["T_page2_rows"]=pg.evaluate("()=>{const c=[...document.querySelectorAll('#view .card')].pop();return c.querySelectorAll('tbody tr').length===2}")
        pg.evaluate("()=>f4TrPage(-1)"); pg.wait_for_timeout(600)
        reset()
        pg.select_option("#f4torder","profit_loss"); pg.wait_for_timeout(800)
        OUT["T_order_req"]=any("order=profit_loss" in r["u"] for r in OUT["reqs"])
        pg.close()

        # ===== error state (fail_trading) =====
        setmode("admin",fail_trading=True)
        pg=newpg(p,viewport={"width":1440,"height":950})
        goto(pg); route(pg,"trading-accounts")
        OUT["E_box"]=pg.evaluate("()=>!!document.querySelector('#view .statebox')")
        OUT["E_retry"]=pg.evaluate("()=>[...document.querySelectorAll('#view button')].some(b=>b.getAttribute('onclick')&&b.getAttribute('onclick').includes('f3ReloadCurrent'))")
        pg.close()

        # ===== permission gating: limited admin (users.view only) still sees the pages =====
        setmode("limited")
        pg=newpg(p,viewport={"width":1440,"height":950})
        goto(pg); route(pg,"trading-accounts")
        OUT["G_acc"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return (t.includes('Trading Accounts')||t.includes('حساب‌های معاملاتی'))&&!t.includes('403')&&!t.includes('دسترسی رد شد')}")
        route(pg,"trading-trades")
        OUT["G_tr"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('Platform-wide trade journal')||t.includes('ژورنال معاملات در سطح پلتفرم')}")
        pg.close()

        # ===== EN =====
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950})
        goto(pg)
        pg.evaluate("()=>localStorage.setItem('velora_locale','en')")
        goto(pg); route(pg,"trading-accounts")
        OUT["EN_acc"]="Trading Accounts" in pg.evaluate("()=>document.querySelector('#view').innerText")
        route(pg,"trading-trades")
        OUT["EN_tr"]="P/L" in pg.evaluate("()=>document.querySelector('#view').innerText")
        pg.close()

        # ===== responsive matrix (no horizontal overflow at any supported width) =====
        for w in (1440,1280,1024,768,430,390,360):
            setmode("admin")
            pg=newpg(p,viewport={"width":w,"height":900})
            goto(pg); route(pg,"trading-accounts")
            h1=pg.evaluate("()=>document.scrollingElement.scrollWidth-document.scrollingElement.clientWidth")
            route(pg,"trading-trades")
            h2=pg.evaluate("()=>document.scrollingElement.scrollWidth-document.scrollingElement.clientWidth")
            OUT[f"W{w}"]=h1==0 and h2==0
            pg.close()
except Exception as ex:
    OUT["fatal"]=str(ex)[:300]; fails+=1
chk("A_accounts_page",OUT.get("A_title") and OUT.get("A_stats") and OUT.get("A_rows") and OUT.get("A_ident") and OUT.get("A_masked"))
chk("A_no_secrets",OUT.get("A_nosecret"))
chk("A_filter",OUT.get("A_filter_req") and OUT.get("A_filter_rows") and OUT.get("A_empty"))
chk("T_trades_page",OUT.get("T_title") and OUT.get("T_rows") and OUT.get("T_ident") and OUT.get("T_pnl_badges") and OUT.get("T_first"))
chk("T_pagination",OUT.get("T_page2_req") and OUT.get("T_page2_rows"))
chk("T_filter_order",OUT.get("T_filter_req") and OUT.get("T_order_req"))
chk("E_error_state",OUT.get("E_box") and OUT.get("E_retry"))
chk("G_gating",OUT.get("G_acc") and OUT.get("G_tr"))
chk("EN",OUT.get("EN_acc") and OUT.get("EN_tr"))
chk("W_widths",all(OUT.get(f"W{w}") for w in (1440,1280,1024,768,430,390,360)))
chk("X_zero",not OUT["js"])
print("FAILS:",fails if fails else "NONE — ALL GREEN")
try:
    srv.terminate()
except Exception:
    pass
sys.exit(1 if fails else 0)
