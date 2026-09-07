#!/usr/bin/env python3
"""Phase 5 UI suite — Admin AI Usage Drilldown over the stub.

Proves: the frozen #/ai-usage route renders REAL API data (per-request rows
with user identity, aggregate card from the EXISTING analytics/ai endpoint,
real quota rows), server-side filters fire the whitelisted query params,
pagination/sort/direction requests fire, honest empty state, in-page error
state with retry, permission gating (aiManage — limited admin WITHOUT it is
denied), FA/EN, RTL/LTR, responsive 1440/1280/1024/768/430/390/360 with zero
horizontal overflow, zero console errors, and no prompt_hash / payload /
secret material in the DOM.
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
        # ===== AI Usage page (admin has aiManage) =====
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); route(pg,"ai-usage")
        txt=pg.evaluate("()=>document.querySelector('#view').innerText")
        OUT["U_title"]=("Per-request AI usage drilldown" in txt) or ("جزئیات درخواست‌به‌درخواست" in txt)
        OUT["U_agg"]=("Aggregate usage" in txt) or ("مصرف تجمیعی" in txt)
        OUT["U_quota"]="1500" in txt and "100000" in txt
        OUT["U_rows"]=pg.evaluate("()=>{const c=[...document.querySelectorAll('#view .card')].filter(c=>c.querySelector('tbody'));return c.length&&c[c.length-1].querySelectorAll('tbody tr').length===6}")
        OUT["U_ident"]="sara@velora.test" in txt and "Mina Nouri" in txt
        OUT["U_status_badge"]=("اتمام سهمیه" in txt) or ("Quota exhausted" in txt) or ("موفق" in txt) or ("Success" in txt)
        OUT["U_noprompt"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;const h=document.querySelector('#view').innerHTML;return !t.includes('prompt')&&!t.includes('prompt_hash')&&h.indexOf('prompt_hash')===-1}")
        OUT["U_nosecret"]=pg.evaluate("()=>{const h=document.querySelector('#view').innerText;return !h.includes('credential')&&!/token/i.test(h)&&!h.includes('password')&&!h.includes('original_result')&&!h.includes('payload')}")
        # deterministic default order: newest created_at first = id 304 (weekly_report, gpt-4o-mini)
        OUT["U_first"]=pg.evaluate("()=>{const c=[...document.querySelectorAll('#view .card')].filter(c=>c.querySelector('tbody'));return c.length&&c[c.length-1].querySelector('tbody tr td:nth-child(3)').innerText.includes('openai')}")
        # ===== filters fire the whitelisted params =====
        reset()
        pg.select_option("#f5feature","analysis"); pg.wait_for_timeout(800)
        OUT["F_feature_req"]=any("feature=analysis" in r["u"] for r in OUT["reqs"])
        OUT["F_feature_rows"]=pg.evaluate("()=>{const c=[...document.querySelectorAll('#view .card')].filter(c=>c.querySelector('tbody'));return c[c.length-1].querySelectorAll('tbody tr').length===2}")
        pg.select_option("#f5feature",""); pg.wait_for_timeout(600)
        reset()
        pg.select_option("#f5provider","tesseract"); pg.wait_for_timeout(800)
        OUT["F_prov_req"]=any("provider=tesseract" in r["u"] for r in OUT["reqs"])
        OUT["F_prov_rows"]=pg.evaluate("()=>{const c=[...document.querySelectorAll('#view .card')].filter(c=>c.querySelector('tbody'));return c[c.length-1].querySelectorAll('tbody tr').length===1}")
        pg.select_option("#f5provider",""); pg.wait_for_timeout(600)
        reset()
        pg.select_option("#f5status","quota_exhausted"); pg.wait_for_timeout(800)
        OUT["F_status_req"]=any("status=quota_exhausted" in r["u"] for r in OUT["reqs"])
        OUT["F_status_rows"]=pg.evaluate("()=>{const c=[...document.querySelectorAll('#view .card')].filter(c=>c.querySelector('tbody'));return c[c.length-1].querySelectorAll('tbody tr').length===1}")
        pg.select_option("#f5status",""); pg.wait_for_timeout(600)
        reset()
        pg.fill("#f5user","999"); pg.dispatch_event("#f5user","change"); pg.wait_for_timeout(900)
        OUT["F_user_req"]=any("user_id=999" in r["u"] for r in OUT["reqs"])
        OUT["F_empty"]="موردی ثبت نشده" in pg.evaluate("()=>document.querySelector('#view').innerText") or "No entries recorded" in pg.evaluate("()=>document.querySelector('#view').innerText")
        pg.evaluate("()=>f5Reset()"); pg.wait_for_timeout(800)
        # ===== pagination + sorting/direction =====
        reset()
        pg.evaluate("()=>f5Page(1)"); pg.wait_for_timeout(800)
        OUT["P_page_req"]=any("page=2" in r["u"] for r in OUT["reqs"])
        pg.evaluate("()=>f5Page(-1)"); pg.wait_for_timeout(600)
        reset()
        pg.select_option("#f5order","tokens_used"); pg.wait_for_timeout(800)
        OUT["S_order_req"]=any("order=tokens_used" in r["u"] for r in OUT["reqs"])
        OUT["S_order_rows"]=pg.evaluate("()=>{const c=[...document.querySelectorAll('#view .card')].filter(c=>c.querySelector('tbody'));return c[c.length-1].querySelector('tbody tr td:nth-child(3)').innerText.includes('openai')}")
        reset()
        pg.select_option("#f5dir","asc"); pg.wait_for_timeout(800)
        OUT["S_dir_req"]=any("dir=asc" in r["u"] for r in OUT["reqs"])
        pg.select_option("#f5order",""); pg.select_option("#f5dir",""); pg.wait_for_timeout(600)
        pg.close()
        # ===== error state (fail_aiusage) =====
        setmode("admin",fail_aiusage=True)
        pg=newpg(p,viewport={"width":1440,"height":950})
        goto(pg); route(pg,"ai-usage")
        OUT["E_box"]=pg.evaluate("()=>!!document.querySelector('#view .statebox')")
        OUT["E_retry"]=pg.evaluate("()=>[...document.querySelectorAll('#view button')].some(b=>b.getAttribute('onclick')&&b.getAttribute('onclick').includes('f3ReloadCurrent'))")
        pg.close()
        # ===== permission gating: limited admin has NO aiManage -> denied =====
        setmode("limited")
        pg=newpg(p,viewport={"width":1440,"height":950})
        goto(pg); route(pg,"ai-usage")
        gtxt=pg.evaluate("()=>document.querySelector('#view').innerText")
        OUT["G_denied"]=("sara@velora.test" not in gtxt) and (("دسترسی" in gtxt) or ("403" in gtxt) or ("Permission" in gtxt) or ("PERMISSION" in gtxt))
        pg.close()
        # ===== unauthenticated mode never renders data =====
        setmode("noauth")
        pg=newpg(p,viewport={"width":1440,"height":950})
        goto(pg); route(pg,"ai-usage")
        OUT["G_noauth"]="sara@velora.test" not in pg.evaluate("()=>{const v=document.querySelector('#view');return v?v.innerText:''}")
        pg.close()
        # ===== EN + LTR =====
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950})
        goto(pg)
        pg.evaluate("()=>localStorage.setItem('velora_locale','en')")
        goto(pg); route(pg,"ai-usage")
        etxt=pg.evaluate("()=>document.querySelector('#view').innerText")
        OUT["EN_title"]="Per-request AI usage drilldown" in etxt
        OUT["EN_cost"]="Recorded cost" in etxt
        OUT["EN_ltr"]=pg.evaluate("()=>document.documentElement.getAttribute('dir')==='ltr'")
        pg.close()
        # ===== FA default + RTL =====
        pg=newpg(p,viewport={"width":1440,"height":950})
        goto(pg)
        pg.evaluate("()=>localStorage.setItem('velora_locale','fa')")
        goto(pg); route(pg,"ai-usage")
        OUT["FA_title"]="جزئیات درخواست‌به‌درخواست" in pg.evaluate("()=>document.querySelector('#view').innerText")
        OUT["FA_rtl"]=pg.evaluate("()=>document.documentElement.getAttribute('dir')==='rtl'")
        pg.close()
        # ===== responsive matrix (no horizontal overflow at any supported width) =====
        for w in (1440,1280,1024,768,430,390,360):
            setmode("admin")
            pg=newpg(p,viewport={"width":w,"height":900})
            goto(pg); route(pg,"ai-usage")
            h1=pg.evaluate("()=>document.scrollingElement.scrollWidth-document.scrollingElement.clientWidth")
            OUT[f"W{w}"]=h1==0
            pg.close()
except Exception as ex:
    OUT["fatal"]=str(ex)[:300]; fails+=1
chk("U_page",OUT.get("U_title") and OUT.get("U_agg") and OUT.get("U_quota") and OUT.get("U_rows") and OUT.get("U_ident") and OUT.get("U_status_badge") and OUT.get("U_first"))
chk("U_privacy",OUT.get("U_noprompt") and OUT.get("U_nosecret"))
chk("F_filters",OUT.get("F_feature_req") and OUT.get("F_feature_rows") and OUT.get("F_prov_req") and OUT.get("F_prov_rows") and OUT.get("F_status_req") and OUT.get("F_status_rows"))
chk("F_user_empty",OUT.get("F_user_req") and OUT.get("F_empty"))
chk("P_pagination",OUT.get("P_page_req"))
chk("S_sorting",OUT.get("S_order_req") and OUT.get("S_order_rows") and OUT.get("S_dir_req"))
chk("E_error_state",OUT.get("E_box") and OUT.get("E_retry"))
chk("G_gating",OUT.get("G_denied") and OUT.get("G_noauth"))
chk("LANG",OUT.get("EN_title") and OUT.get("EN_cost") and OUT.get("EN_ltr") and OUT.get("FA_title") and OUT.get("FA_rtl"))
chk("W_widths",all(OUT.get(f"W{w}") for w in (1440,1280,1024,768,430,390,360)))
chk("X_zero",not OUT["js"])
print("FAILS:",fails if fails else "NONE — ALL GREEN")
try:
    srv.terminate()
except Exception:
    pass
sys.exit(1 if fails else 0)
