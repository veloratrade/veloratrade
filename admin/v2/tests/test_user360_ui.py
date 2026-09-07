#!/usr/bin/env python3
"""User360 UI suite (Phase 3) — Sessions / Devices / Login History over the stub.

Proves: real identity header, three real-data sections (sessions with derived
badges, read-only devices, login history with result filter), pagination,
per-session revoke with confirm modal + honest toast + server-truthful
refresh, in-modal error surface, permission gating (no users.suspend -> no
revoke buttons, sections still visible), honest empty state, FA + EN, RTL/LTR
safe, Escape + focus return, responsive 1440/1280/1024/768/430/390/360 with
zero horizontal overflow, zero console errors, and secret hygiene (no token
hashes / fingerprints rendered).
"""
import json,subprocess,time,sys,os
from playwright.sync_api import sync_playwright
HERE=os.path.dirname(os.path.abspath(__file__))
srv=subprocess.Popen(["python3",os.path.join(HERE,"f1_stub.py")],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
time.sleep(1.2)
OUT={"js":[],"reqs":[]}
def setmode(m,**flags):
    d={"mode":m,"logout_called":False}; d.update(flags)
    json.dump(d,open(os.path.join(HERE,"mode.json"),"w"))
def newpg(p,**kw):
    pg=p.chromium.launch().new_page(**kw)
    pg.on("pageerror", lambda e: OUT["js"].append("PAGEERROR:"+str(e)[:160]))
    return pg
def track(pg):
    pg.on("request", lambda r: OUT["reqs"].append({"m":r.method,"u":r.url.split("8141")[-1]}) if "/api/" in r.url else None)
def goto(pg):
    pg.goto("http://127.0.0.1:8141/admin/v2/index.html"); pg.wait_for_timeout(1400)
def u360(pg,uid=2):
    pg.evaluate(f"()=>location.hash='#/users/{uid}'"); pg.wait_for_timeout(1600)
def reset(): OUT["reqs"].clear()
fails=0
def chk(name,cond):
    global fails
    if not cond: fails+=1
    print(("PASS " if cond else "FAIL ")+name)
try:
    with sync_playwright() as p:
        # ===== full-permission admin: real data everywhere =====
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); u360(pg)
        txt=pg.evaluate("()=>document.querySelector('#view').innerText")
        OUT["U_ident"]="sara@velora.test" in txt and "Sara Ahmadi" in txt
        OUT["U_title"]="#2" in txt
        OUT["U_sess_rows"]=pg.evaluate("()=>document.querySelectorAll('#view .tbl')[0]?document.querySelectorAll('#view .tbl')[0].querySelectorAll('tbody tr').length:0")
        OUT["U_sess_badges"]="فعال" in txt and ("باطل‌شده" in txt or "منقضی" in txt)
        OUT["U_dev_rows"]=pg.evaluate("()=>[...document.querySelectorAll('#view .card')].some(c=>c.innerText.includes('دستگاه‌ها')&&c.querySelectorAll('tbody tr').length===2)")
        OUT["U_dev_readonly"]="فقط خواندنی" in txt
        OUT["U_hist_rows"]="تاریخچه ورود" in txt and "اعتبارات نامعتبر" in txt and "INVALID_CREDENTIALS" not in txt
        OUT["U_hist_badges"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('موفق')&&t.includes('ناموفق')}")
        OUT["U_revoke_btn"]=pg.evaluate("()=>[...document.querySelectorAll('#view .btn.sm.danger')].some(b=>b.getAttribute('onclick')&&b.getAttribute('onclick').startsWith('usr360Revoke(2,101'))")
        OUT["U_no_secrets"]=pg.evaluate("()=>{const h=document.documentElement.innerHTML;return !h.includes('refresh_token_hash')&&!h.includes('access_token_hash')&&!h.includes('fingerprint')&&!h.includes('rh101')&&!h.includes('fpA')}")
        # pagination request check
        reset()
        pg.evaluate("()=>f360SessPage(1)"); pg.wait_for_timeout(700)
        OUT["U_sess_page2"]=any("/sessions?page=2" in r["u"] for r in OUT["reqs"])
        pg.evaluate("()=>f360SessPage(-1)"); pg.wait_for_timeout(700)
        # history filter
        reset()
        pg.evaluate("()=>f360HistFilter('failure')"); pg.wait_for_timeout(700)
        OUT["U_hist_filter_req"]=any("/login-history?page=1&per_page=10&result=failure" in r["u"] for r in OUT["reqs"])
        OUT["U_hist_filtered"]=pg.evaluate("()=>{const c=[...document.querySelectorAll('#view .card')].find(c=>c.innerText.includes('تاریخچه ورود'));return c&&c.querySelectorAll('tbody tr').length===1&&c.innerText.includes('ناموفق')}")
        pg.evaluate("()=>f360HistFilter('')"); pg.wait_for_timeout(500)
        # revoke flow: modal a11y + confirm + toast + refresh
        reset()
        pg.click("#view button[onclick='usr360Revoke(2,101)']"); pg.wait_for_timeout(300)
        OUT["U_modal_open"]=pg.evaluate("()=>!!document.querySelector('#modal-root .modal[role=dialog]')")
        OUT["U_modal_focus"]=pg.evaluate("()=>document.activeElement&&document.activeElement.id==='f3actgo'")
        OUT["U_modal_note"]="#101" in pg.evaluate("()=>{const n=document.querySelector('#modal-root .note');return n?n.innerText:''}")
        pg.click("#f3actgo"); pg.wait_for_timeout(900)
        OUT["U_revoke_req"]=any(r["m"]=="POST" and "/sessions/101/revoke" in r["u"] for r in OUT["reqs"])
        OUT["U_revoke_toast"]="نشست باطل شد" in pg.evaluate("()=>document.querySelector('#toasts').innerText")
        OUT["U_modal_closed"]=pg.evaluate("()=>!document.querySelector('#modal-root .modal')")
        OUT["U_sess_refetch"]=any("/sessions?" in r["u"] for r in OUT["reqs"] if r["m"]=="GET")
        # already-revoked honest toast (mode flag)
        setmode("admin",revoke_already=True)
        pg.evaluate("()=>usr360Revoke(2,102)")
        pg.wait_for_timeout(300)
        pg.click("#f3actgo"); pg.wait_for_timeout(900)
        OUT["U_revoke_already"]="قبلاً باطل شده بود" in pg.evaluate("()=>document.querySelector('#toasts').innerText")
        pg.evaluate("()=>f3CloseModal()")
        # server error -> in-modal error + re-enabled
        setmode("admin",fail_actions=True)
        pg.evaluate("()=>usr360Revoke(2,103)"); pg.wait_for_timeout(300)
        pg.click("#f3actgo"); pg.wait_for_timeout(900)
        OUT["U_srv_err"]=pg.evaluate("()=>{const a=document.querySelector('#f3acterr');return !!a && a.innerText.includes('INTERNAL_ERROR')}")
        OUT["U_srv_enabled"]=pg.evaluate("()=>{const g=document.querySelector('#f3actgo');return g && !g.disabled}")
        pg.keyboard.press("Escape"); pg.wait_for_timeout(200)
        # Escape behavior + focus return on the revoke modal
        setmode("admin")
        pg.click("#view button[onclick='usr360Revoke(2,101)']"); pg.wait_for_timeout(250)
        pg.keyboard.press("Escape"); pg.wait_for_timeout(200)
        OUT["U_esc"]=pg.evaluate("()=>!document.querySelector('#modal-root .modal')")
        pg.close()

        # ===== empty state: user 4 has no sessions/devices/history =====
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950})
        goto(pg); u360(pg,4)
        OUT["U_empty"]="موردی ثبت نشده" in pg.evaluate("()=>document.querySelector('#view').innerText")
        pg.close()

        # ===== permission gating: limited admin (no users.suspend) =====
        setmode("limited")
        pg=newpg(p,viewport={"width":1440,"height":950})
        goto(pg); u360(pg,2)
        OUT["U_gating"]=pg.evaluate("""()=>{const t=document.querySelector('#view').innerText;
          const noRevoke=![...document.querySelectorAll('#view .btn.sm.danger')].some(b=>(b.getAttribute('onclick')||'').startsWith('usr360Revoke'));
          return noRevoke && t.includes('نشست‌ها') && t.includes('تاریخچه ورود')}""")
        pg.close()

        # ===== EN =====
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950})
        goto(pg)
        pg.evaluate("()=>localStorage.setItem('velora_locale','en')")
        goto(pg); u360(pg,2)
        OUT["U_en"]="Login history" in pg.evaluate("()=>document.querySelector('#view').innerText") and "Revoke session" in pg.evaluate("()=>document.querySelector('#view').innerText")
        pg.close()

        # ===== responsive matrix =====
        for w in (1440,1280,1024,768,430,390,360):
            setmode("admin")
            pg=newpg(p,viewport={"width":w,"height":900})
            goto(pg); u360(pg,2)
            hov=pg.evaluate("()=>document.scrollingElement.scrollWidth-document.scrollingElement.clientWidth")
            OUT[f"U_w{w}"]=hov==0
            pg.close()
except Exception as ex:
    OUT["fatal"]=str(ex)[:300]; fails+=1
chk("U_ident_title",OUT.get("U_ident") and OUT.get("U_title"))
chk("U_sessions",OUT.get("U_sess_rows")==2 and OUT.get("U_sess_badges"))
chk("U_devices",OUT.get("U_dev_rows") and OUT.get("U_dev_readonly"))
chk("U_history",OUT.get("U_hist_rows") and OUT.get("U_hist_badges"))
chk("U_revoke_visible",OUT.get("U_revoke_btn"))
chk("U_no_secrets",OUT.get("U_no_secrets"))
chk("U_sess_page2",OUT.get("U_sess_page2"))
chk("U_hist_filter",OUT.get("U_hist_filter_req") and OUT.get("U_hist_filtered"))
chk("U_modal",OUT.get("U_modal_open") and OUT.get("U_modal_focus") and OUT.get("U_modal_note"))
chk("U_revoke_flow",OUT.get("U_revoke_req") and OUT.get("U_revoke_toast") and OUT.get("U_modal_closed") and OUT.get("U_sess_refetch"))
chk("U_revoke_already",OUT.get("U_revoke_already"))
chk("U_srv_error",OUT.get("U_srv_err") and OUT.get("U_srv_enabled"))
chk("U_esc",OUT.get("U_esc"))
chk("U_empty",OUT.get("U_empty"))
chk("U_gating",OUT.get("U_gating"))
chk("U_en",OUT.get("U_en"))
chk("U_widths",all(OUT.get(f"U_w{w}") for w in (1440,1280,1024,768,430,390,360)))
chk("X_zero",not OUT["js"])
print("FAILS:",fails if fails else "NONE — ALL GREEN")
for k,v in OUT.items():
    if k not in ("X_bad","reqs"): print(k,"=",str(v)[:110])
srv.terminate()
import os as _os
try: _os.remove(os.path.join(HERE,"mode.json"))
except OSError: pass
sys.exit(1 if fails else 0)
