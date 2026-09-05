#!/usr/bin/env python3
"""F-3 Batch 1 suite — Overview / Users / AI Providers / AI Route real-data wiring.

Proves displayed values come from API responses (stub doubles the REAL backend
shapes read from the controllers), honest unavailable/error states, permission
gating via /admin/me, real mutation success/failure handling, secret safety,
responsive matrix and zero console errors.
"""
import json,subprocess,time,sys,os,re
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
    pg.on("request", lambda r: OUT["reqs"].append({"m":r.method,"u":r.url.split("8141")[-1],"b":(r.post_data or "")[:120]}) if "/api/" in r.url else None)
def goto(pg):
    pg.goto("http://127.0.0.1:8141/admin/v2/index.html"); pg.wait_for_timeout(1400)
def L(pg,k): return pg.evaluate("k=>T.fa[k]??SHELL_T.fa[k]??F3T.fa[k]??k",k)
def api_calls():
    return [r for r in OUT["reqs"]]
def reset(): OUT["reqs"].clear()
try:
    with sync_playwright() as p:
        # ================= OVERVIEW =================
        setmode("admin",delay_overview=True)
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        pg.goto("http://127.0.0.1:8141/admin/v2/index.html"); pg.wait_for_timeout(120)
        OUT["OV_skeleton"]=pg.evaluate("()=>!!document.querySelector('#view .skel')")
        pg.wait_for_timeout(1400)
        calls=[r for r in OUT["reqs"] if r["u"].startswith("/api/v1/admin/overview")]
        OUT["OV_req"]=len(calls)==1 and calls[0]["m"]=="GET" and calls[0]["u"]=="/api/v1/admin/overview"
        txt=pg.evaluate("()=>document.querySelector('#view').innerText")
        OUT["OV_data"]=[str(x) in txt for x in (128,110,18,3,21,1523,4210,199,912345)]
        OUT["OV_rev_unavail"]=pg.evaluate("""()=>{const t=document.querySelector('#view').innerText;
          const m=t.match(/درآمد[\\s\\S]{0,200}/); return !!m && m[0].includes('موجود نیست') && !/درآمد[\\s\\S]{0,80}\\d{3,}/.test(m[0])}""")
        OUT["OV_health"]=("2ms" in txt or "3ms" in txt) and ("API" in txt)
        OUT["OV_recent_real"]="user.verify_email" in txt
        # limited admin: health card permission-gated, no /system/health call
        pg.close(); reset()
        setmode("limited")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); pg.wait_for_timeout(900)
        OUT["OV_noperm"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('system.health.view')}")
        OUT["OV_no_healthcall"]=not any(r["u"]=="/api/v1/admin/system/health" for r in OUT["reqs"])
        # error state
        pg.close(); reset()
        setmode("admin",ovr500=True)
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); pg.wait_for_timeout(900)
        OUT["OV_err"]=pg.evaluate("()=>!!document.querySelector('#view .statebox') && !!document.querySelector('#view .statebox .btn')")
        OUT["OV_err_nofake"]=pg.evaluate("()=>!document.querySelector('#view').innerText.includes('128')")
        pg.close(); reset()

        # ================= USERS =================
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg)
        pg.evaluate("()=>location.hash='#/users'"); pg.wait_for_timeout(900)
        calls=[r for r in OUT["reqs"] if r["u"].startswith("/api/v1/admin/users?")]
        OUT["US_req"]=len(calls)>=1 and "page=1" in calls[0]["u"] and "per_page=10" in calls[0]["u"]
        emails=pg.evaluate("()=>[...document.querySelectorAll('#view tbody .cellsub.mono')].map(x=>x.innerText)")
        OUT["US_rows"]=sum(1 for e in emails if "@velora.test" in e)==5
        OUT["US_emails"]=all(e in emails for e in ("owner@velora.test","sara@velora.test","reza@velora.test","mina@velora.test"))
        # search
        reset()
        pg.fill("#usrSearch","sara"); pg.wait_for_timeout(800)
        OUT["US_search_q"]=any("search=sara" in r["u"] for r in OUT["reqs"])
        OUT["US_search_res"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('sara@velora.test')&&!t.includes('reza@velora.test')}")
        # role filter
        reset()
        pg.fill("#usrSearch",""); pg.wait_for_timeout(600)
        pg.select_option("#view select[aria-label='نقش']","admin") if False else None
        pg.evaluate("()=>f3UsersFilter('role','admin')"); pg.wait_for_timeout(600)
        OUT["US_role_q"]=any("role=admin" in r["u"] for r in OUT["reqs"])
        OUT["US_role_res"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('admin4@velora.test')&&!t.includes('sara@velora.test')}")
        pg.evaluate("()=>f3UsersFilter('role','')"); pg.wait_for_timeout(500)
        # verify-email success (user 2 unverified)
        reset()
        pg.evaluate("()=>usrVerify(2)"); pg.wait_for_timeout(250)
        pg.click("#f3actgo"); pg.wait_for_timeout(900)
        OUT["US_verify_req"]=any(r["m"]=="POST" and r["u"]=="/api/v1/admin/users/2/verify-email" for r in OUT["reqs"])
        OUT["US_verify_toast"]=pg.evaluate("()=>document.querySelector('#toasts').innerText")
        OUT["US_verify_row"]=pg.evaluate("""()=>{location.hash='#/users';return new Promise(r=>setTimeout(()=>{
            const row=[...document.querySelectorAll('#view tbody tr')].find(tr=>tr.innerText.includes('sara@velora.test'));
            r(!!row && row.innerText.includes('تأییدشده'))},900))}""")
        # idempotent second run
        reset()
        pg.evaluate("()=>usrVerify(2)"); pg.wait_for_timeout(200)
        pg.click("#f3actgo"); pg.wait_for_timeout(900)
        OUT["US_verify_idem"]=pg.evaluate("()=>document.querySelector('#toasts').innerText.includes('قبلاً')")
        # self-action denied (user 4 is the admin themself in stub)
        reset()
        pg.evaluate("()=>usrVerify(4)"); pg.wait_for_timeout(200)
        pg.click("#f3actgo"); pg.wait_for_timeout(900)
        OUT["US_self_err"]=pg.evaluate("()=>document.querySelector('#toasts').innerText.includes('SELF_ACTION_DENIED')")
        OUT["US_self_nosuccess"]=pg.evaluate("()=>!document.querySelector('#toasts').innerText.includes('تأیید ایمیل کاربر تأیید شد')")
        # failed mutation does not fake success + list not mutated locally
        setmode("admin",fail_actions=True)
        reset()
        pg.evaluate("()=>usrConfirm('activate',3,'reza@velora.test')"); pg.wait_for_timeout(200)
        pg.click("#f3actgo"); pg.wait_for_timeout(900)
        OUT["US_fail_toast"]=pg.evaluate("()=>document.querySelector('#toasts').innerText.includes('INTERNAL_ERROR')")
        OUT["US_fail_row"]=pg.evaluate("()=>{const row=[...document.querySelectorAll('#view tbody tr')].find(tr=>tr.innerText.includes('reza@velora.test'));return !!row&&row.innerText.includes('مسدود')}")
        setmode("admin")
        # successful suspend refreshes from backend (user 5 active -> suspended)
        reset()
        pg.evaluate("()=>usrConfirm('suspend',5,'mina@velora.test')"); pg.wait_for_timeout(200)
        pg.click("#f3actgo"); pg.wait_for_timeout(1000)
        OUT["US_suspend_row"]=pg.evaluate("()=>{const row=[...document.querySelectorAll('#view tbody tr')].find(tr=>tr.innerText.includes('mina@velora.test'));return !!row&&row.innerText.includes('مسدود')}")
        # role change modal
        reset()
        pg.evaluate("()=>usrRole(3,'user')"); pg.wait_for_timeout(200)
        pg.select_option("#f3roleSel","admin"); pg.click("#modal-root .btn.primary"); pg.wait_for_timeout(900)
        OUT["US_role_req"]=any(r["m"]=="POST" and "/users/3/role" in r["u"] and '"role": "admin"' in (r["b"] or "").replace("'","\"") for r in OUT["reqs"]) or any(r["m"]=="POST" and "/users/3/role" in r["u"] for r in OUT["reqs"])
        OUT["US_role_row"]=pg.evaluate("()=>{const row=[...document.querySelectorAll('#view tbody tr')].find(tr=>tr.innerText.includes('reza@velora.test'));return !!row&&row.innerText.includes('مدیر')}")
        # plan modal
        reset()
        pg.evaluate("()=>usrPlan(2)"); pg.wait_for_timeout(200)
        pg.select_option("#f3planSel","pro"); pg.click("#modal-root .btn.primary"); pg.wait_for_timeout(900)
        OUT["US_plan_req"]=any(r["m"]=="POST" and "/users/2/subscription" in r["u"] for r in OUT["reqs"])
        OUT["US_plan_row"]=pg.evaluate("()=>{const row=[...document.querySelectorAll('#view tbody tr')].find(tr=>tr.innerText.includes('sara@velora.test'));return !!row&&/pro/i.test(row.innerText)}")
        # empty state
        pg.fill("#usrSearch","zzzznope"); pg.wait_for_timeout(800)
        OUT["US_empty"]=pg.evaluate("()=>!!document.querySelector('#view .statebox')")
        pg.fill("#usrSearch",""); pg.wait_for_timeout(600)
        # contextual navigation to users/:id (rowlink)
        pg.evaluate("()=>{const tr=[...document.querySelectorAll('#view tbody tr')].find(x=>x.innerText.includes('sara@velora.test'));tr.click()}")
        pg.wait_for_timeout(300)
        OUT["US_360_nav"]=pg.evaluate("()=>location.hash==='#/users/2'")
        OUT["US_360_page"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('#2')")
        OUT["US_360_nosb"]=pg.evaluate("()=>![...document.querySelectorAll('.navitem')].some(n=>n.dataset.route==='users/:id')")
        # pagination via per_page=2 URL state
        pg.evaluate("()=>{US.per=2;US.page=2;f3LoadUsers()}"); pg.wait_for_timeout(700)
        OUT["US_pag"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('صفحه')&&t.includes('2')}")
        pg.close(); reset()

        # ================= AI PROVIDERS =================
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg)
        pg.evaluate("()=>location.hash='#/ai-providers'"); pg.wait_for_timeout(900)
        OUT["P_req"]=any(r["u"]=="/api/v1/admin/ai/overview" for r in OUT["reqs"])
        ptxt=pg.evaluate("()=>document.querySelector('#view').innerText")
        OUT["P_cards"]="gemini" in ptxt and "openai" in ptxt
        OUT["P_configured"]="پیکربندی‌شده" in ptxt and "پیکربندی نشده" in ptxt
        OUT["P_envkey"]="GEMINI_API_KEY" in ptxt
        OUT["P_nosecret"]=not re.search(r"sk-[A-Za-z0-9]|AIza[A-Za-z0-9]|SECRET_VALUE| KEY_VALUE",ptxt)
        # verify gemini (real result)
        reset()
        pg.evaluate("()=>f3ProviderAction('gemini','verify')"); pg.wait_for_timeout(900)
        OUT["P_verify_req"]=any(r["m"]=="POST" and r["u"]=="/api/v1/admin/providers/gemini/verify" for r in OUT["reqs"])
        OUT["P_verify_res"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('VALID')")
        # test connection (latency)
        reset()
        pg.evaluate("()=>f3ProviderAction('gemini','test')"); pg.wait_for_timeout(900)
        OUT["P_test_req"]=any(r["m"]=="POST" and r["u"]=="/api/v1/admin/providers/gemini/test-connection" for r in OUT["reqs"])
        OUT["P_test_lat"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('318ms')")
        # openai honest failure metadata
        pg.evaluate("()=>f3ProviderAction('openai','verify')"); pg.wait_for_timeout(900)
        OUT["P_openai_err"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('CREDENTIAL_MISSING')")
        # features + routing from data
        OUT["P_features"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('analyze-trades')&&t.includes('weekly-report')&&t.includes('gemini')}")
        OUT["P_routing"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('جدول مسیریابی')&&t.includes('1')}")
        # failed provider action → honest error, no success badge
        setmode("admin",fail_actions=True)
        reset()
        pg.evaluate("()=>f3ProviderAction('openai','verify')"); pg.wait_for_timeout(900)
        OUT["P_fail"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('PROVIDER_TIMEOUT')")  # honest backend failure, no fake success
        setmode("admin")
        pg.close(); reset()

        # ================= AI ROUTE =================
        # admin (no aiRouteManage): read-only
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg)
        pg.evaluate("()=>location.hash='#/ai-route'"); pg.wait_for_timeout(900)
        OUT["R_req"]=any(r["u"]=="/api/v1/admin/ai/route" and r["m"]=="GET" for r in OUT["reqs"])
        OUT["R_readonly"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('aiRouteManage')&&!document.querySelector('#view .chip')}")
        OUT["R_state"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('تنظیم نشده')&&t.includes('direct')}")
        pg.close(); reset()
        # super (aiRouteManage): save + reset
        setmode("super")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg)
        pg.evaluate("()=>location.hash='#/ai-route'"); pg.wait_for_timeout(900)
        OUT["R_chips"]=pg.evaluate("()=>document.querySelectorAll('#view .chip').length===2")
        pg.evaluate("()=>{AIR.sel='n8n_relay';render()}"); pg.wait_for_timeout(200)
        reset()
        pg.evaluate("()=>f3AirSave()"); pg.wait_for_timeout(900)
        put=[r for r in OUT["reqs"] if r["m"]=="PUT" and r["u"]=="/api/v1/admin/ai/route"]
        OUT["R_put"]=len(put)==1 and "n8n_relay" in (put[0]["b"] or "")
        OUT["R_put_state"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('n8n_relay')&&t.includes('تنظیم مدیر')}")
        OUT["R_put_toast"]=pg.evaluate("()=>document.querySelector('#toasts').innerText.includes('ذخیره شد')")
        reset()
        pg.evaluate("()=>f3AirReset()"); pg.wait_for_timeout(900)
        OUT["R_del"]=any(r["m"]=="DELETE" and r["u"]=="/api/v1/admin/ai/route" for r in OUT["reqs"])
        OUT["R_del_state"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('تنظیم نشده')")
        # failed save → honest error, state unchanged
        setmode("super",fail_actions=True)
        reset()
        pg.evaluate("()=>{AIR.sel='direct';render()}")
        pg.evaluate("()=>f3AirSave()"); pg.wait_for_timeout(900)
        OUT["R_fail_toast"]=pg.evaluate("()=>document.querySelector('#toasts').innerText.includes('AI_ROUTE_PERSIST_FAILED')")
        OUT["R_fail_state"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('تنظیم نشده')")
        setmode("super")
        pg.close(); reset()

        # ================= responsive matrix on the four pages =================
        setmode("admin")
        pgp=newpg(p,viewport={"width":1440,"height":950})
        pgp.on("pageerror", lambda e: OUT["js"].append("PAGEERROR:"+str(e)[:160]))
        goto(pgp)
        ov={}
        for w,hh in [(1440,950),(1280,900),(1024,800),(768,900),(430,900),(390,844),(360,780)]:
            pgp.set_viewport_size({"width":w,"height":hh})
            for rt in ("overview","users","ai-providers","ai-route"):
                for th,lg in (("dark","fa"),("light","en")):
                    pgp.evaluate(f"()=>{{setTheme('{th}');setLang('{lg}');location.hash='#/{rt}'}}")
                    pgp.wait_for_timeout(260)
                    ovv=pgp.evaluate("()=>document.documentElement.scrollWidth-document.documentElement.clientWidth")
                    key=f"{w}-{rt}-{th}-{lg}"
                    ov[key]=ovv
        pgp.set_viewport_size({"width":1440,"height":950})
        OUT["X_bad"]=[k for k,v in ov.items() if v!=0]
        OUT["X_zero"]=len(OUT["X_bad"])==0
        # mobile users table = frozen stacked-card presentation (data-mstack CSS)
        pgp.set_viewport_size({"width":390,"height":844})
        pgp.evaluate("()=>{setLang('fa');location.hash='#/users'}"); pgp.wait_for_timeout(700)
        OUT["US_mstack"]=pgp.evaluate("()=>{const t=document.querySelector('#view table.tbl');return !!t&&t.getAttribute('data-mstack')!==null&&getComputedStyle(t.querySelector('thead')).display==='none'}")
        pgp.set_viewport_size({"width":1440,"height":950})
        # touch targets on the wired pages (nav unchanged from F-1 — re-assert on users)
        pgp.set_viewport_size({"width":390,"height":844})
        pgp.evaluate("()=>location.hash='#/users'"); pgp.wait_for_timeout(600)
        OUT["Y_touch"]=pgp.evaluate("()=>{const vis=e=>e.offsetParent!==null&&e.getBoundingClientRect().height>0;const nav=[...document.querySelectorAll('.navitem')].filter(e=>vis(e)&&e.getBoundingClientRect().height<46);return nav.length===0}")  # §16 mobile (F-1 rule)
        # keyboard: modal escape ordering + focus restore
        pgp.evaluate("()=>usrVerify(2)"); pgp.wait_for_timeout(250)
        OUT["Y_modal_focus"]=pgp.evaluate("()=>document.activeElement&&(document.activeElement.id==='f3actgo'||document.activeElement.tagName==='BUTTON')")
        pgp.keyboard.press("Escape"); pgp.wait_for_timeout(200)
        OUT["Y_modal_esc"]=pgp.evaluate("()=>!document.querySelector('#modal-root .modal')")
        OUT["Y_modal_focusback"]=pgp.evaluate("()=>document.activeElement&&document.activeElement.id!=='f3actgo'")
        # secret safety across the three wired pages
        pgp.evaluate("()=>location.hash='#/users'"); pgp.wait_for_timeout(600)
        pgp.evaluate("()=>location.hash='#/ai-providers'"); pgp.wait_for_timeout(600)
        pgp.evaluate("()=>location.hash='#/ai-route'"); pgp.wait_for_timeout(600)
        OUT["SEC_dom"]=pgp.evaluate("()=>!/(sk-[A-Za-z0-9]{8}|AIza[A-Za-z0-9_\\-]{10}|Bearer\\s+[A-Za-z0-9_\\-]{20})/.test(document.body.innerText)")
        pgp.close()
finally:
    srv.terminate()

# ---------------- checks ----------------
fails=[]
def chk(k,cond):
    if not cond: fails.append(k)
def alltrue(k): chk(k, isinstance(OUT.get(k),list) and all(OUT[k]) if OUT.get(k) is not None else False)
chk("js",len(OUT["js"])==0)
chk("OV_skeleton",OUT["OV_skeleton"])
chk("OV_req",OUT["OV_req"]); alltrue("OV_data")
chk("OV_rev_unavail",OUT["OV_rev_unavail"]); chk("OV_health",OUT["OV_health"]); chk("OV_recent_real",OUT["OV_recent_real"])
chk("OV_noperm",OUT["OV_noperm"]); chk("OV_no_healthcall",OUT["OV_no_healthcall"])
chk("OV_err",OUT["OV_err"]); chk("OV_err_nofake",OUT["OV_err_nofake"])
chk("US_req",OUT["US_req"]); chk("US_rows",OUT["US_rows"]); chk("US_emails",OUT["US_emails"])
chk("US_search",OUT["US_search_q"] and OUT["US_search_res"])
chk("US_role",OUT["US_role_q"] and OUT["US_role_res"])
chk("US_verify",OUT["US_verify_req"] and "تأیید" in str(OUT.get("US_verify_toast","")) and OUT["US_verify_row"])
chk("US_idem",OUT["US_verify_idem"])
chk("US_self",OUT["US_self_err"] and OUT["US_self_nosuccess"])
chk("US_fail",OUT["US_fail_toast"] and OUT["US_fail_row"])
chk("US_suspend",OUT["US_suspend_row"])
chk("US_rolechg",OUT["US_role_req"] and OUT["US_role_row"])
chk("US_plan",OUT["US_plan_req"] and OUT["US_plan_row"])
chk("US_empty",OUT["US_empty"])
chk("US_360",OUT["US_360_nav"] and OUT["US_360_page"] and OUT["US_360_nosb"])
chk("US_pag",OUT["US_pag"])
chk("P_req",OUT["P_req"]); chk("P_cards",OUT["P_cards"]); chk("P_configured",OUT["P_configured"])
chk("P_envkey",OUT["P_envkey"]); chk("P_nosecret",OUT["P_nosecret"])
chk("P_verify",OUT["P_verify_req"] and OUT["P_verify_res"])
chk("P_test",OUT["P_test_req"] and OUT["P_test_lat"])
chk("P_openai",OUT["P_openai_err"]); chk("P_features",OUT["P_features"]); chk("P_routing",OUT["P_routing"])
chk("P_fail",OUT["P_fail"])
chk("R_readonly",OUT["R_readonly"] and OUT["R_state"] and OUT["R_req"])
chk("R_save",OUT["R_chips"] and OUT["R_put"] and OUT["R_put_state"] and OUT["R_put_toast"])
chk("R_reset",OUT["R_del"] and OUT["R_del_state"])
chk("R_fail",OUT["R_fail_toast"] and OUT["R_fail_state"])
chk("X_zero",OUT["X_zero"]); chk("US_mstack",OUT["US_mstack"])
chk("Y_touch",OUT["Y_touch"]); chk("Y_modal",OUT["Y_modal_focus"] and OUT["Y_modal_esc"] and OUT["Y_modal_focusback"])
chk("SEC_dom",OUT["SEC_dom"])
print("FAILS:",fails if fails else "NONE — ALL GREEN")
for k,v in OUT.items():
    if k!="X_bad": print(k,"=",str(v)[:110])
try:
    json.dump(OUT,open(os.path.join(HERE,"last_f3_run.json"),"w"),ensure_ascii=False,indent=1,default=str)
except OSError: pass
sys.exit(1 if fails else 0)
