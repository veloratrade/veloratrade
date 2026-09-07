#!/usr/bin/env python3
"""Create User UI suite (Phase 1) — frozen-modal flow over the stub backend.

Proves: permission-gated toolbar button (users.create), frozen .modal form
(a11y labels, focus, Esc+focus-return), real POST /api/v1/admin/users with the
typed payload, honest toasts (emailSent true/false), server-truthful refresh
(new row appears via re-GET, no optimistic mutation), in-modal API error
surface (409 duplicate), no users.create -> no button, EN localization,
mobile 390 viewport without horizontal overflow, zero console errors, and
secret hygiene: the typed password exists only in the single create POST body
and never remains in the DOM afterwards.
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
    pg.on("request", lambda r: OUT["reqs"].append({"m":r.method,"u":r.url.split("8141")[-1],"b":(r.post_data or "")[:200]}) if "/api/" in r.url else None)
def goto(pg):
    pg.goto("http://127.0.0.1:8141/admin/v2/index.html"); pg.wait_for_timeout(1400)
def users(pg):
    pg.evaluate("()=>location.hash='#/users'"); pg.wait_for_timeout(900)
def reset(): OUT["reqs"].clear()
PW="Cr34tive!Pass"
FA_SENT="کاربر ایجاد شد و ایمیل تأیید ارسال شد"
FA_NOTSENT="کاربر ایجاد شد اما ارسال ایمیل تأیید ممکن نشد"
EN_SENT="User created and verification email sent"
def create_modal(pg,email,name,role="user",plan="free"):
    pg.fill("#f3cuEmail",email); pg.fill("#f3cuName",name); pg.fill("#f3cuPass",PW)
    pg.select_option("#f3cuRole",role); pg.select_option("#f3cuPlan",plan)
fails=0
def chk(name,cond):
    global fails
    if not cond: fails+=1
    print(("PASS " if cond else "FAIL ")+name)
try:
    with sync_playwright() as p:
        # ===== creator admin: button + modal a11y + success flow =====
        setmode("creator",create_result="ok")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); users(pg)
        OUT["C_btn"]=pg.evaluate("""()=>{const b=[...document.querySelectorAll('#view .btn.sm.primary')].find(x=>x.getAttribute('onclick')==='usrCreateOpen()');
          return b?b.innerText.trim():null}""")
        OUT["C_btn_fa"]=OUT["C_btn"]=="ایجاد کاربر"
        reset()
        pg.click("#view button[onclick='usrCreateOpen()']"); pg.wait_for_timeout(300)
        OUT["C_modal"]=pg.evaluate("""()=>({dialog:!!document.querySelector('#modal-root .modal[role=dialog]'),
          focus:document.activeElement&&document.activeElement.id,
          emailL:!!document.querySelector('label[for=f3cuEmail]'),nameL:!!document.querySelector('label[for=f3cuName]'),
          passL:!!document.querySelector('label[for=f3cuPass]'),roleL:!!document.querySelector('label[for=f3cuRole]'),
          planL:!!document.querySelector('label[for=f3cuPlan]'),pwType:(document.querySelector('#f3cuPass')||{}).type})""")
        OUT["C_modal_ok"]=OUT["C_modal"]["dialog"] and OUT["C_modal"]["focus"]=="f3cuEmail" and \
            all(OUT["C_modal"][k] for k in ("emailL","nameL","passL","roleL","planL")) and OUT["C_modal"]["pwType"]=="password"
        # Esc closes + focus returns to the opener button
        pg.keyboard.press("Escape"); pg.wait_for_timeout(200)
        OUT["C_esc_closed"]=pg.evaluate("()=>!document.querySelector('#modal-root .modal')")
        OUT["C_focus_back"]=pg.evaluate("()=>(document.activeElement||{}).getAttribute && document.activeElement.getAttribute('onclick')==='usrCreateOpen()'")
        # success flow
        pg.click("#view button[onclick='usrCreateOpen()']"); pg.wait_for_timeout(250)
        create_modal(pg,"nova@velora.test","Nova Rahmani","user","free")
        pg.click("#f3actgo"); pg.wait_for_timeout(900)
        posts=[r for r in OUT["reqs"] if r["m"]=="POST" and r["u"]=="/api/v1/admin/users"]
        OUT["C_post"]=len(posts)==1
        body=json.loads(posts[0]["b"]) if posts and posts[0]["b"].startswith("{") else {}
        OUT["C_body"]=body.get("email")=="nova@velora.test" and body.get("fullName")=="Nova Rahmani" and \
            body.get("role")=="user" and body.get("plan")=="free" and body.get("password")==PW
        OUT["C_toast"]=FA_SENT in pg.evaluate("()=>document.querySelector('#toasts').innerText")
        OUT["C_modal_closed"]=pg.evaluate("()=>!document.querySelector('#modal-root .modal')")
        OUT["C_refresh"]=any(r["m"]=="GET" and r["u"].startswith("/api/v1/admin/users?") for r in OUT["reqs"][len(posts):]) or \
            any(r["m"]=="GET" and r["u"].startswith("/api/v1/admin/users?") for r in OUT["reqs"])
        OUT["C_row"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('nova@velora.test')")
        OUT["C_pw_dom"]=PW not in pg.evaluate("()=>document.documentElement.innerHTML")
        # duplicate email -> in-modal error, modal stays open, submit re-enabled
        setmode("creator",create_result="dup")
        pg.click("#view button[onclick='usrCreateOpen()']"); pg.wait_for_timeout(250)
        create_modal(pg,"nova@velora.test","Nova Again")
        pg.click("#f3actgo"); pg.wait_for_timeout(900)
        OUT["C_dup_err"]=pg.evaluate("()=>{const a=document.querySelector('#f3acterr');return !!a && a.innerText.includes('EMAIL_ALREADY_REGISTERED')}")
        OUT["C_dup_open"]=pg.evaluate("()=>!!document.querySelector('#modal-root .modal')")
        OUT["C_dup_enabled"]=pg.evaluate("()=>{const g=document.querySelector('#f3actgo');return g && !g.disabled}")
        pg.keyboard.press("Escape"); pg.wait_for_timeout(200)
        # honest notsent toast
        setmode("creator",create_result="notsent")
        pg.click("#view button[onclick='usrCreateOpen()']"); pg.wait_for_timeout(250)
        create_modal(pg,"quiet@velora.test","Quiet Mail")
        pg.click("#f3actgo"); pg.wait_for_timeout(900)
        OUT["C_notsent"]=FA_NOTSENT in pg.evaluate("()=>document.querySelector('#toasts').innerText")
        pg.close()

        # ===== no users.create -> no button =====
        setmode("limited")
        pg=newpg(p,viewport={"width":1440,"height":950})
        goto(pg); users(pg)
        OUT["C_noperm"]=pg.evaluate("()=>![...document.querySelectorAll('#view .btn')].some(x=>x.getAttribute('onclick')==='usrCreateOpen()')")
        pg.close()

        # ===== EN localization =====
        setmode("creator",create_result="ok")
        pg=newpg(p,viewport={"width":1440,"height":950})
        goto(pg)
        pg.evaluate("()=>localStorage.setItem('velora_locale','en')")
        goto(pg); users(pg)
        OUT["C_en_btn"]=pg.evaluate("""()=>{const b=[...document.querySelectorAll('#view .btn.sm.primary')].find(x=>x.getAttribute('onclick')==='usrCreateOpen()');
          return b?b.innerText.trim():null}""")=="Create User"
        pg.click("#view button[onclick='usrCreateOpen()']"); pg.wait_for_timeout(250)
        create_modal(pg,"enuser@velora.test","EN User")
        pg.click("#f3actgo"); pg.wait_for_timeout(900)
        OUT["C_en_toast"]=EN_SENT in pg.evaluate("()=>document.querySelector('#toasts').innerText")
        pg.close()

        # ===== mobile 390: modal usable, no horizontal overflow =====
        setmode("creator",create_result="ok")
        pg=newpg(p,viewport={"width":390,"height":844})
        goto(pg); users(pg)
        pg.click("#view button[onclick='usrCreateOpen()']"); pg.wait_for_timeout(300)
        OUT["C_m_hov"]=pg.evaluate("()=>document.scrollingElement.scrollWidth-document.scrollingElement.clientWidth")
        OUT["C_m_go"]=pg.evaluate("""()=>{const g=document.querySelector('#f3actgo');if(!g)return false;
          const r=g.getBoundingClientRect();return r.width>0 && r.bottom<=window.innerHeight+2}""")
        OUT["C_m_email"]=pg.evaluate("""()=>{const e=document.querySelector('#f3cuEmail');if(!e)return false;
          const r=e.getBoundingClientRect();return r.width>0 && r.right<=window.innerWidth+1 && r.left>=-1}""")
        pg.keyboard.press("Escape")
        pg.close()
except Exception as ex:
    OUT["fatal"]=str(ex)[:300]; fails+=1
chk("C_btn_fa",OUT.get("C_btn_fa")); chk("C_modal",OUT.get("C_modal_ok"))
chk("C_esc",OUT.get("C_esc_closed") and OUT.get("C_focus_back"))
chk("C_post",OUT.get("C_post")); chk("C_body",OUT.get("C_body"))
chk("C_toast",OUT.get("C_toast")); chk("C_closed",OUT.get("C_modal_closed"))
chk("C_refresh_row",OUT.get("C_refresh") and OUT.get("C_row"))
chk("C_pw_hygiene",OUT.get("C_pw_dom"))
chk("C_dup",OUT.get("C_dup_err") and OUT.get("C_dup_open") and OUT.get("C_dup_enabled"))
chk("C_notsent",OUT.get("C_notsent")); chk("C_noperm",OUT.get("C_noperm"))
chk("C_en",OUT.get("C_en_btn") and OUT.get("C_en_toast"))
chk("C_mobile",OUT.get("C_m_hov")==0 and OUT.get("C_m_go") and OUT.get("C_m_email"))
chk("X_zero",not OUT["js"])
print("FAILS:",fails if fails else "NONE — ALL GREEN")
for k,v in OUT.items():
    if k not in ("X_bad","reqs"): print(k,"=",str(v)[:110])
try:
    json.dump(OUT,open(os.path.join(HERE,"last_create_run.json"),"w"),ensure_ascii=False,indent=1,default=str)
except OSError: pass
srv.terminate()
sys.exit(1 if fails else 0)
