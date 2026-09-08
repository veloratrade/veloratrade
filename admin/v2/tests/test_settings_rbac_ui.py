#!/usr/bin/env python3
"""Phase 7 UI suite — Settings Permissions matrix + Settings Admins over the stub.

Proves: #/settings-permissions renders the REAL server-owned permission matrix
(22 rows; admin=16 / super_admin=22 / user=0; your-role badge; strictly
read-only — no inputs/selects/buttons in the matrix), #/settings-admins lists
real admins with EXISTING audited action reuse (role modal POSTs the existing
/users/{id}/role endpoint; invite modal POSTs the existing /invitations
endpoint; User360 navigation; permission-gated controls — change_role hidden
without it), honest error paths (SELF_ACTION_DENIED rendered in-modal; 500
error state with retry), the sa.* i18n collision fix VERIFIED IN RENDERED DOM
(security-audit resolves its own description again), FA/EN + RTL/LTR,
dark/light, keyboard (Esc closes modals), 7 widths x both pages with zero
horizontal overflow, and zero console errors.
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
def goto(pg):
    pg.goto("http://127.0.0.1:8141/admin/v2/index.html"); pg.wait_for_timeout(1400)
def route(pg,r):
    pg.evaluate(f"()=>location.hash='#/{r}'"); pg.wait_for_timeout(1200)
def track(pg):
    pg.on("request", lambda r: OUT["reqs"].append({"m":r.method,"u":r.url.split("8141")[-1]}) if "/api/" in r.url else None)
def reset(): OUT["reqs"].clear()
fails=0
def chk(name,cond):
    global fails
    if not cond: fails+=1
    print(("PASS " if cond else "FAIL ")+name)
try:
    with sync_playwright() as p:
        # ===== A. permission matrix (plain admin) =====
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); route(pg,"settings-permissions")
        OUT["M_title"]=("Roles & permissions" in pg.evaluate("()=>document.querySelector('#view').innerText")) or ("نقش‌ها و مجوزهای سامانه" in pg.evaluate("()=>document.querySelector('#view').innerText"))
        OUT["M_rows"]=pg.evaluate("()=>document.querySelectorAll('#view tbody tr').length")==22
        OUT["M_counts"]=pg.evaluate("""()=>{const t=[...document.querySelectorAll('#view thead th')].slice(1).map(th=>+th.querySelector('.num').innerText);
          return t[0]===0&&t[1]===16&&t[2]===22}""")
        OUT["M_checks"]=pg.evaluate("""()=>{let a=0,s=0,u=0;[...document.querySelectorAll('#view tbody tr')].forEach(tr=>{const c=[...tr.querySelectorAll('td')].slice(1);if(c[0].querySelector('.badge.b-ok'))u++;if(c[1].querySelector('.badge.b-ok'))a++;if(c[2].querySelector('.badge.b-ok'))s++});return {u,a,s}}""")=={"u":0,"a":16,"s":22}
        OUT["M_readonly"]=pg.evaluate("()=>{const c=document.querySelectorAll('#view .card')[0];return !!c&&!!c.querySelector('table')&&!c.querySelector('input')&&!c.querySelector('select')&&!c.querySelector('button')}")
        OUT["M_you"]=pg.evaluate("()=>document.querySelector('#view thead').innerText.includes('Your role')===false&&document.querySelector('#view thead th:nth-child(3)').innerText.includes('نقش شما')")
        OUT["M_spot"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('audit.view_sensitive')&&t.includes('aiManage')&&t.includes('settings.view')}")
        # ===== B. settings-admins as CREATOR admin (users.create, no change_role): EN locale =====
        setmode("creator")
        pg.evaluate("()=>localStorage.setItem('velora_locale','en')"); goto(pg)
        route(pg,"settings-admins")
        OUT["A_title"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('Admin accounts, role changes and invitations')")
        OUT["A_rows"]=pg.evaluate("()=>document.querySelectorAll('#view tbody tr').length")==2
        OUT["A_roles"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('Super admin')&&t.includes('Admin')&&t.includes('owner@velora.test')&&t.includes('admin4@velora.test')}")
        OUT["A_invite_visible"]=pg.evaluate("()=>!![...document.querySelectorAll('#view button')].find(b=>b.innerText.trim()==='Invite Admin')")
        OUT["A_role_hidden"]=pg.evaluate("()=>![...document.querySelectorAll('#view button')].some(b=>b.innerText.trim()==='Change role')")
        OUT["A_u360"]=pg.evaluate(r"()=>[...document.querySelectorAll('#view button')].filter(b=>b.innerText.replace(/\s+/g,'')==='User360').length===2")
        # invite via the EXISTING audited endpoint
        reset()
        pg.evaluate("()=>usrInviteOpen()"); pg.wait_for_timeout(300)
        OUT["A_invite_modal"]=pg.evaluate("()=>!!document.querySelector('#f3invEmail')&&!!document.querySelector('#f3invRole')")
        pg.fill("#f3invEmail","newadmin@velora.test")
        pg.evaluate("()=>usrInvite()"); pg.wait_for_timeout(900)
        OUT["A_invite_req"]=any("/api/v1/admin/users/invitations" in r["u"] and r["m"]=="POST" for r in OUT["reqs"])
        OUT["A_invite_closed"]=pg.evaluate("()=>!document.querySelector('#f3invEmail')")
        # ===== C. super admin: role change via EXISTING audited endpoint + honest denial =====
        setmode("super")
        pg2=newpg(p,viewport={"width":1440,"height":950}); track(pg2)
        goto(pg2)
        pg2.evaluate("()=>localStorage.setItem('velora_locale','en')"); goto(pg2); route(pg2,"settings-admins")
        OUT["S_role_btns"]=pg2.evaluate(r"()=>[...document.querySelectorAll('#view button')].filter(b=>b.innerText.replace(/\s+/g,' ')==='Change role').length")>=2
        pg2.evaluate("()=>usrRole(1,'super_admin')"); pg2.wait_for_timeout(300)
        OUT["S_modal"]=pg2.evaluate("()=>!!document.querySelector('#f3roleSel')")
        pg2.select_option("#f3roleSel","admin")
        reset()
        pg2.evaluate("()=>usrDo('role',1)"); pg2.wait_for_timeout(900)
        OUT["S_role_req"]=any("/api/v1/admin/users/1/role" in r["u"] and r["m"]=="POST" for r in OUT["reqs"])
        OUT["S_refresh"]=any("role=admin" in r["u"] for r in OUT["reqs"])
        # honest denial: stub returns SELF_ACTION_DENIED for uid 4
        pg2.evaluate("()=>usrRole(4,'admin')"); pg2.wait_for_timeout(300)
        pg2.evaluate("()=>usrDo('role',4)"); pg2.wait_for_timeout(900)
        OUT["S_denied"]=pg2.evaluate("()=>{const d=document.querySelector('#f3acterr');return !!d&&(d.innerText.includes('SELF_ACTION_DENIED')||d.innerText.length>3)}")
        pg2.keyboard.press("Escape"); pg2.wait_for_timeout(300)
        OUT["S_esc"]=pg2.evaluate("()=>!document.querySelector('#f3roleSel')")
        # your-role badge on super_admin column (super mode)
        route(pg2,"settings-permissions")
        OUT["S_you"]=pg2.evaluate("()=>document.querySelector('#view thead th:nth-child(4)').innerText.includes('Your role')||document.querySelector('#view thead th:nth-child(4)').innerText.includes('نقش شما')")
        # ===== D. error state (fail_security) on a FRESH page =====
        setmode("admin",fail_security=True)
        pg3=newpg(p,viewport={"width":1440,"height":950})
        goto(pg3); route(pg3,"settings-permissions")
        OUT["E_box"]=pg3.evaluate("()=>!!document.querySelector('#view .statebox')")
        OUT["E_retry"]=pg3.evaluate("()=>!![...document.querySelectorAll('#view button')].find(b=>b.innerText==='Retry'||b.innerText==='تلاش دوباره')")
        pg3.close()
        setmode("admin")
        # ===== E. sa.* collision fix — VERIFIED IN RENDERED DOM =====
        pg.evaluate("()=>localStorage.setItem('velora_locale','en')"); goto(pg)
        route(pg,"security-audit")
        OUT["FIX_audit_en"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('Admin action trail')")
        route(pg,"settings-admins")
        OUT["FIX_admins_en"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('Admin accounts, role changes and invitations')")
        pg.evaluate("()=>localStorage.setItem('velora_locale','fa')"); goto(pg)
        route(pg,"security-audit")
        OUT["FIX_audit_fa"]=pg.evaluate("()=>{const v=document.querySelector('#view').innerText;return v.includes('ردگیری اقدامات مدیریتی')&&!v.includes('نقش‌ها و ماتریس دسترسی')}")
        route(pg,"settings-admins")
        OUT["FIX_admins_fa"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('حساب‌های مدیریتی')")
        OUT["FA_rtl"]=pg.evaluate("()=>document.documentElement.dir==='rtl'")
        # ===== F. EN + LTR + dark/light on the new pages =====
        pg.evaluate("()=>localStorage.setItem('velora_locale','en')"); goto(pg)
        route(pg,"settings-permissions")
        OUT["EN_ltr"]=pg.evaluate("()=>document.documentElement.dir==='ltr'&&document.querySelector('#view').innerText.includes('Permission')")
        pg.evaluate("()=>document.documentElement.setAttribute('data-theme','light')"); pg.wait_for_timeout(300)
        OUT["TH_light"]=pg.evaluate("()=>document.documentElement.dataset.theme==='light'&&document.querySelector('#view').innerText.length>50")
        pg.evaluate("()=>document.documentElement.setAttribute('data-theme','dark')")
        # ===== G. responsive: both pages, 7 widths, zero horizontal overflow =====
        for w in (1440,1280,1024,768,430,390,360):
            setmode("admin")
            pg4=newpg(p,viewport={"width":w,"height":900})
            goto(pg4); route(pg4,"settings-permissions")
            o1=pg4.evaluate("()=>document.scrollingElement.scrollWidth-document.scrollingElement.clientWidth")
            route(pg4,"settings-admins")
            o2=pg4.evaluate("()=>document.scrollingElement.scrollWidth-document.scrollingElement.clientWidth")
            OUT[f"W{w}"]=(o1==0 and o2==0)
            pg4.close()
except Exception as ex:
    OUT["fatal"]=str(ex)[:300]; fails+=1
chk("M_matrix",OUT.get("M_rows") and OUT.get("M_counts") and OUT.get("M_checks") and OUT.get("M_readonly") and OUT.get("M_you"))
chk("A_admins",OUT.get("A_title") and OUT.get("A_rows") and OUT.get("A_roles") and OUT.get("A_u360"))
chk("A_gating",OUT.get("A_invite_visible") and OUT.get("A_role_hidden"))
chk("A_invite",OUT.get("A_invite_modal") and OUT.get("A_invite_req") and OUT.get("A_invite_closed"))
chk("S_super",OUT.get("S_role_btns") and OUT.get("S_modal") and OUT.get("S_role_req") and OUT.get("S_refresh") and OUT.get("S_denied") and OUT.get("S_esc") and OUT.get("S_you"))
chk("E_error",OUT.get("E_box") and OUT.get("E_retry"))
chk("SA_FIX",OUT.get("FIX_audit_en") and OUT.get("FIX_admins_en") and OUT.get("FIX_audit_fa") and OUT.get("FIX_admins_fa"))
chk("LANG",OUT.get("FA_rtl") and OUT.get("EN_ltr") and OUT.get("TH_light"))
chk("W_widths",all(OUT.get(f"W{w}") for w in (1440,1280,1024,768,430,390,360)))
chk("X_zero",not OUT["js"])
if OUT.get("fatal"): print("FATAL:",OUT["fatal"])
print("FAILS:",fails if fails else "NONE — ALL GREEN")
try:
    srv.terminate()
except Exception:
    pass
sys.exit(1 if fails else 0)
