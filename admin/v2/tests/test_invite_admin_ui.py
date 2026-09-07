#!/usr/bin/env python3
"""Invite Admin UI suite (Phase 2) — frozen-modal flow over the stub backend.

Proves: permission-gated Invite Admin button (users.change_role), frozen
.modal form (labels, focus, Esc + focus-return, no password field), real POST
/api/v1/admin/users/invitations with the typed payload, honest toasts
(emailSent true/false), server-truthful refresh, in-modal API error surface
(500 / 409 duplicate), no-perm -> no button, FA + EN, responsive widths
1440/1280/1024/768/430/390/360 without horizontal overflow, mobile-safe
modal, zero console errors, and secret hygiene: no password field exists and
no token is ever rendered.
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
PW_NOTE=None
FA_SENT="دعوت ایجاد شد و ایمیل ارسال شد"
FA_NOTSENT="دعوت ایجاد شد اما ارسال ایمیل ممکن نشد"
EN_SENT="Invitation created and email sent"
def fill_modal(pg,email,name,role="admin"):
    pg.fill("#f3invEmail",email); pg.fill("#f3invName",name); pg.select_option("#f3invRole",role)
fails=0
def chk(name,cond):
    global fails
    if not cond: fails+=1
    print(("PASS " if cond else "FAIL ")+name)
try:
    with sync_playwright() as p:
        # ===== inviter (super_admin): button + modal a11y + success flow =====
        setmode("inviter",invite_result="ok")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); users(pg)
        OUT["I_btn"]=pg.evaluate("""()=>{const b=[...document.querySelectorAll('#view .btn.sm.primary')].find(x=>x.getAttribute('onclick')==='usrInviteOpen()');
          return b?b.innerText.trim():null}""")
        OUT["I_btn_fa"]=OUT["I_btn"]=="دعوت مدیر"
        reset()
        pg.click("#view button[onclick='usrInviteOpen()']"); pg.wait_for_timeout(300)
        OUT["I_modal"]=pg.evaluate("""()=>({dialog:!!document.querySelector('#modal-root .modal[role=dialog]'),
          focus:document.activeElement&&document.activeElement.id,
          emailL:!!document.querySelector('label[for=f3invEmail]'),nameL:!!document.querySelector('label[for=f3invName]'),
          roleL:!!document.querySelector('label[for=f3invRole]'),note:!!document.querySelector('#modal-root .note'),
          noPw:!(document.querySelector('#modal-root input[type=password]')),
          roles:[...(document.querySelector('#f3invRole')||{options:[]}).options].map(o=>o.value)})""")
        mm=OUT["I_modal"]
        OUT["I_modal_ok"]=mm["dialog"] and mm["focus"]=="f3invEmail" and mm["emailL"] and mm["nameL"] and mm["roleL"] and mm["note"] and mm["noPw"] and mm["roles"]==["admin","super_admin"]
        # Esc closes + focus returns to opener
        pg.keyboard.press("Escape"); pg.wait_for_timeout(200)
        OUT["I_esc_closed"]=pg.evaluate("()=>!document.querySelector('#modal-root .modal')")
        OUT["I_focus_back"]=pg.evaluate("()=>(document.activeElement||{}).getAttribute && document.activeElement.getAttribute('onclick')==='usrInviteOpen()'")
        # success flow
        pg.click("#view button[onclick='usrInviteOpen()']"); pg.wait_for_timeout(250)
        fill_modal(pg,"nima@velora.test","Nima Invited","admin")
        pg.click("#f3actgo"); pg.wait_for_timeout(900)
        posts=[r for r in OUT["reqs"] if r["m"]=="POST" and r["u"]=="/api/v1/admin/users/invitations"]
        OUT["I_post"]=len(posts)==1
        body=json.loads(posts[0]["b"]) if posts and posts[0]["b"].startswith("{") else {}
        OUT["I_body"]=body.get("email")=="nima@velora.test" and body.get("fullName")=="Nima Invited" and body.get("role")=="admin" and "password" not in body
        OUT["I_toast"]=FA_SENT in pg.evaluate("()=>document.querySelector('#toasts').innerText")
        OUT["I_closed"]=pg.evaluate("()=>!document.querySelector('#modal-root .modal')")
        OUT["I_refresh"]=any(r["m"]=="GET" and r["u"].startswith("/api/v1/admin/users?") for r in OUT["reqs"])
        OUT["I_row"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('nima@velora.test')")
        OUT["I_no_token_dom"]=pg.evaluate("()=>!document.documentElement.innerHTML.includes('reset-password#token=')")
        # duplicate 409 -> in-modal error, modal stays open, submit re-enabled
        setmode("inviter",invite_result="dup")
        pg.click("#view button[onclick='usrInviteOpen()']"); pg.wait_for_timeout(250)
        fill_modal(pg,"nima@velora.test","Dup Invite")
        pg.click("#f3actgo"); pg.wait_for_timeout(900)
        OUT["I_dup_err"]=pg.evaluate("()=>{const a=document.querySelector('#f3acterr');return !!a && a.innerText.includes('EMAIL_ALREADY_REGISTERED')}")
        OUT["I_dup_open"]=pg.evaluate("()=>!!document.querySelector('#modal-root .modal')")
        OUT["I_dup_enabled"]=pg.evaluate("()=>{const g=document.querySelector('#f3actgo');return g && !g.disabled}")
        pg.keyboard.press("Escape"); pg.wait_for_timeout(200)
        # server error 500 -> in-modal error + re-enabled
        setmode("inviter",invite_result="serverfail")
        pg.click("#view button[onclick='usrInviteOpen()']"); pg.wait_for_timeout(250)
        fill_modal(pg,"boom@velora.test","Boom")
        pg.click("#f3actgo"); pg.wait_for_timeout(900)
        OUT["I_srv_err"]=pg.evaluate("()=>{const a=document.querySelector('#f3acterr');return !!a && a.innerText.includes('INTERNAL_ERROR')}")
        OUT["I_srv_enabled"]=pg.evaluate("()=>{const g=document.querySelector('#f3actgo');return g && !g.disabled}")
        pg.keyboard.press("Escape"); pg.wait_for_timeout(200)
        # honest notsent toast
        setmode("inviter",invite_result="notsent")
        pg.click("#view button[onclick='usrInviteOpen()']"); pg.wait_for_timeout(250)
        fill_modal(pg,"quiet@velora.test","Quiet Mail")
        pg.click("#f3actgo"); pg.wait_for_timeout(900)
        OUT["I_notsent"]=FA_NOTSENT in pg.evaluate("()=>document.querySelector('#toasts').innerText")
        pg.close()

        # ===== admin WITHOUT users.change_role -> no invite button (Create User still gated separately) =====
        setmode("creator")
        pg=newpg(p,viewport={"width":1440,"height":950})
        goto(pg); users(pg)
        OUT["I_noperm"]=pg.evaluate("()=>![...document.querySelectorAll('#view .btn')].some(x=>x.getAttribute('onclick')==='usrInviteOpen()')")
        pg.close()

        # ===== EN localization =====
        setmode("inviter",invite_result="ok")
        pg=newpg(p,viewport={"width":1440,"height":950})
        goto(pg)
        pg.evaluate("()=>localStorage.setItem('velora_locale','en')")
        goto(pg); users(pg)
        OUT["I_en_btn"]=pg.evaluate("""()=>{const b=[...document.querySelectorAll('#view .btn.sm.primary')].find(x=>x.getAttribute('onclick')==='usrInviteOpen()');
          return b?b.innerText.trim():null}""")=="Invite Admin"
        pg.click("#view button[onclick='usrInviteOpen()']"); pg.wait_for_timeout(250)
        fill_modal(pg,"eninvite@velora.test","EN Invite")
        pg.click("#f3actgo"); pg.wait_for_timeout(900)
        OUT["I_en_toast"]=EN_SENT in pg.evaluate("()=>document.querySelector('#toasts').innerText")
        pg.close()

        # ===== responsive matrix: no horizontal overflow, modal usable =====
        for w in (1440,1280,1024,768,430,390,360):
            setmode("inviter",invite_result="ok")
            pg=newpg(p,viewport={"width":w,"height":900})
            goto(pg); users(pg)
            hov_page=pg.evaluate("()=>document.scrollingElement.scrollWidth-document.scrollingElement.clientWidth")
            pg.click("#view button[onclick='usrInviteOpen()']"); pg.wait_for_timeout(300)
            hov_modal=pg.evaluate("()=>document.scrollingElement.scrollWidth-document.scrollingElement.clientWidth")
            usable=pg.evaluate("""()=>{const g=document.querySelector('#f3actgo');const e=document.querySelector('#f3invEmail');
              if(!g||!e)return false;const rg=g.getBoundingClientRect();const re=e.getBoundingClientRect();
              return rg.width>0 && rg.bottom<=window.innerHeight+2 && re.width>0 && re.right<=window.innerWidth+1 && re.left>=-1}""")
            OUT[f"I_w{w}"]=(hov_page==0 and hov_modal==0 and usable)
            pg.keyboard.press("Escape"); pg.close()
except Exception as ex:
    OUT["fatal"]=str(ex)[:300]; fails+=1
chk("I_btn_fa",OUT.get("I_btn_fa")); chk("I_modal",OUT.get("I_modal_ok"))
chk("I_esc",OUT.get("I_esc_closed") and OUT.get("I_focus_back"))
chk("I_post",OUT.get("I_post")); chk("I_body",OUT.get("I_body"))
chk("I_toast",OUT.get("I_toast")); chk("I_closed",OUT.get("I_closed"))
chk("I_refresh_row",OUT.get("I_refresh") and OUT.get("I_row"))
chk("I_no_token_dom",OUT.get("I_no_token_dom"))
chk("I_dup",OUT.get("I_dup_err") and OUT.get("I_dup_open") and OUT.get("I_dup_enabled"))
chk("I_srv",OUT.get("I_srv_err") and OUT.get("I_srv_enabled"))
chk("I_notsent",OUT.get("I_notsent")); chk("I_noperm",OUT.get("I_noperm"))
chk("I_en",OUT.get("I_en_btn") and OUT.get("I_en_toast"))
chk("I_widths",all(OUT.get(f"I_w{w}") for w in (1440,1280,1024,768,430,390,360)))
chk("X_zero",not OUT["js"])
print("FAILS:",fails if fails else "NONE — ALL GREEN")
for k,v in OUT.items():
    if k not in ("X_bad","reqs"): print(k,"=",str(v)[:110])
try:
    json.dump(OUT,open(os.path.join(HERE,"last_invite_run.json"),"w"),ensure_ascii=False,indent=1,default=str)
except OSError: pass
srv.terminate()
sys.exit(1 if fails else 0)
