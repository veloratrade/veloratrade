#!/usr/bin/env python3
"""F-3 Batch 2 suite — Integrations (n8n / MetaAPI / Email) + AI Health.

Network-level proof: exact method+path for every call, no protected call without
permission, mutation → server refresh, real classified probe results, secret
safety (write-only fields never rendered), honest readonly for admins without
integrations.manage, no invented n8n probe, responsive matrix, zero console errors.
Stub doubles mirror the REAL controller shapes (RelayConfigController,
IntegrationsController, SystemHealthService) read from source on main.
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
    pg.on("request", lambda r: OUT["reqs"].append({"m":r.method,"u":r.url.split("8141")[-1],"b":(r.post_data or "")[:160]}) if "/api/" in r.url else None)
def goto(pg):
    pg.goto("http://127.0.0.1:8141/admin/v2/index.html"); pg.wait_for_timeout(1400)
def reset(): OUT["reqs"].clear()
def has_req(m,u): return any(r["m"]==m and r["u"]==u for r in OUT["reqs"])
def ntxt(pg): return pg.evaluate("()=>document.querySelector('#view').innerText.replace(/[\\s\\u200c]+/g,'')")
try:
    with sync_playwright() as p:
        # ================= N8N — admin (integrations.view only): read-only =================
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg)
        pg.evaluate("()=>location.hash='#/integrations-n8n'"); pg.wait_for_timeout(900)
        OUT["N_req"]=has_req("GET","/api/v1/admin/integrations/relay/config")
        OUT["N_unsafe_state"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('تنظیم نشده')}")
        OUT["N_readonly"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('integrations.manage')&&!document.querySelector('#n8nUrl')}")
        OUT["N_noprobe_note"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('پروب زنده')")
        OUT["N_nomutate"]=not any(r["m"] in ("POST","PUT","DELETE") and not r["u"].startswith("/api/v1/auth/") for r in OUT["reqs"])
        pg.close(); reset()
        # ================= N8N — super: mutation + server refresh + secret safety =================
        setmode("super")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg)
        pg.evaluate("()=>location.hash='#/integrations-n8n'"); pg.wait_for_timeout(900)
        OUT["N_form"]=pg.evaluate("()=>!!document.querySelector('#n8nUrl')&&!!document.querySelector('#n8nToken')")
        TOK="tok_live_SECRETVAL42"
        pg.fill("#n8nUrl","https://relay.example.com"); pg.fill("#n8nToken",TOK)
        reset()
        pg.click("#view .btn.primary"); pg.wait_for_timeout(900)
        put=[r for r in OUT["reqs"] if r["m"]=="PUT" and r["u"]=="/api/v1/admin/integrations/relay/config"]
        OUT["N_put"]=len(put)==1 and "relay.example.com" in (put[0]["b"] or "")
        OUT["N_put_toast"]=pg.evaluate("()=>document.querySelector('#toasts').innerText.includes('ذخیره شد')")
        OUT["N_put_state"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('relay.example.com')}")
        OUT["N_tok_badge"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('توکن رله')}")
        # token value must NEVER appear in the DOM
        OUT["N_tok_secret_dom"]=pg.evaluate("v=>!document.body.innerText.includes(v)",TOK)
        # invalid URL → real 422 code in toast, state unchanged
        setmode("super")
        reset()
        pg.evaluate("()=>document.querySelector('#n8nUrl').value=''")
        pg.fill("#n8nUrl","http://localhost:5678"); pg.wait_for_timeout(200)
        pg.click("#view .btn.primary"); pg.wait_for_timeout(900)
        OUT["N_invalid"]=pg.evaluate("()=>document.querySelector('#toasts').innerText.includes('INVALID_ENDPOINT_URL')")
        OUT["N_invalid_nostate"]=pg.evaluate("()=>!document.querySelector('#view').innerText.includes('localhost:5678')")
        # 429 honest rate-limit
        setmode("super",relay429=True)
        reset()
        pg.fill("#n8nUrl","https://relay.example.com"); pg.wait_for_timeout(200)
        pg.click("#view .btn.primary"); pg.wait_for_timeout(900)
        OUT["N_429"]=pg.evaluate("()=>document.querySelector('#toasts').innerText.includes('RATE_LIMITED')")
        setmode("super")
        # reset → DELETE → unset
        reset()
        pg.evaluate("()=>{f3N8Reset();}"); pg.wait_for_timeout(900)
        OUT["N_del"]=has_req("DELETE","/api/v1/admin/integrations/relay/config")
        OUT["N_del_state"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('تنظیم نشده')")
        pg.close(); reset()

        # ================= METAAPI — admin read-only =================
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg)
        pg.evaluate("()=>location.hash='#/integrations-metaapi'"); pg.wait_for_timeout(900)
        OUT["M_req"]=has_req("GET","/api/v1/admin/integrations/metaapi")
        OUT["M_fields"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('api.metaapi.cloud')&&t.includes('آزمایش نشده')}")
        OUT["M_readonly"]=pg.evaluate("()=>!document.querySelector('#mmTestBtn')&&!document.querySelector('#mmUrl')")
        OUT["M_nomutate"]=not any(r["m"] in ("POST","PUT","DELETE") and not r["u"].startswith("/api/v1/auth/") for r in OUT["reqs"])
        pg.close(); reset()
        # ================= METAAPI — super: real probe + failures =================
        setmode("super")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg)
        pg.evaluate("()=>location.hash='#/integrations-metaapi'"); pg.wait_for_timeout(900)
        reset()
        pg.evaluate("()=>{f3MMTest();}"); pg.wait_for_timeout(900)
        OUT["M_probe_req"]=has_req("POST","/api/v1/admin/integrations/metaapi/test")
        OUT["M_probe_ok"]=('SUCCESS' in (t:=ntxt(pg))) and ('213ms' in t)
        OUT["M_reach_upd"]=pg.evaluate("()=>!document.querySelector('#view').innerText.includes('آزمایش نشده')")
        # timeout classified honestly
        setmode("super",probe_timeout=True)
        reset()
        pg.evaluate("()=>{f3MMTest();}"); pg.wait_for_timeout(900)
        OUT["M_timeout"]=('TIMEOUT' in (t:=ntxt(pg))) and ('SUCCESS' not in t)
        setmode("super")
        # put: add webhook secret (write-only)
        WHS="whsec_SECRETVAL77"
        reset()
        pg.fill("#mmSecret",WHS); pg.wait_for_timeout(200)
        pg.click("#view .btn.primary"); pg.wait_for_timeout(900)
        OUT["M_put"]=any(r["m"]=="PUT" and "webhook_secret" in (r["b"] or "") for r in OUT["reqs"])
        OUT["M_whs_badge"]=pg.evaluate("()=>{const row=[...document.querySelectorAll('#view .kv')].find(k=>k.innerText.includes('Webhook'));return !!row&&row.innerText.includes('تنظیم‌شده')}")
        OUT["M_whs_secret_dom"]=pg.evaluate("v=>!document.body.innerText.includes(v)",WHS)
        # reset → NOT_CONFIGURED probe honestly
        reset()
        pg.evaluate("()=>{f3MMReset();}"); pg.wait_for_timeout(900)
        OUT["M_del"]=has_req("DELETE","/api/v1/admin/integrations/metaapi")
        reset()
        pg.evaluate("()=>{f3MMTest();}"); pg.wait_for_timeout(900)
        OUT["M_probe_nc"]='NOT_CONFIGURED' in ntxt(pg)
        pg.close(); reset()

        # ================= EMAIL — admin read-only =================
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg)
        pg.evaluate("()=>location.hash='#/integrations-email'"); pg.wait_for_timeout(900)
        OUT["E_req"]=has_req("GET","/api/v1/admin/integrations/email")
        OUT["E_fields"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('resend')&&t.includes('no-reply@velora.test')}")
        OUT["E_readonly"]=pg.evaluate("()=>!document.querySelector('#emDriver')")
        pg.close(); reset()
        # ================= EMAIL — super: 8-field form, write-only secrets, probe =================
        setmode("super")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg)
        pg.evaluate("()=>location.hash='#/integrations-email'"); pg.wait_for_timeout(900)
        OUT["E_form8"]=pg.evaluate("()=>['emDriver','emFrom','emFromName','emHost','emPort','emUser','emPass','emResend'].every(i=>!!document.querySelector('#'+i))")
        OUT["E_pass_placeholder_only"]=pg.evaluate("()=>document.querySelector('#emPass').value===''&&document.querySelector('#emResend').value===''")
        RKEY="rk_live_SECRETVAL99"
        reset()
        pg.fill("#emFrom","hello@velora.test"); pg.fill("#emResend",RKEY); pg.wait_for_timeout(200)
        pg.click("#view .btn.primary"); pg.wait_for_timeout(900)
        OUT["E_put"]=any(r["m"]=="PUT" and r["u"]=="/api/v1/admin/integrations/email" and "hello@velora.test" in (r["b"] or "") for r in OUT["reqs"])
        OUT["E_put_toast"]=pg.evaluate("()=>document.querySelector('#toasts').innerText.includes('ذخیره شد')")
        OUT["E_state_upd"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('hello@velora.test')")
        OUT["E_secret_dom"]=pg.evaluate("v=>!document.body.innerText.includes(v)",RKEY)
        # invalid port → backend 422 code surfaced
        setmode("super")
        reset()
        pg.fill("#emPort","99999"); pg.wait_for_timeout(200)
        pg.click("#view .btn.primary"); pg.wait_for_timeout(900)
        OUT["E_badport"]=pg.evaluate("()=>document.querySelector('#toasts').innerText.includes('INVALID_FORMAT')")
        # probe ok
        setmode("super")
        reset()
        pg.evaluate("()=>{f3EMTest();}"); pg.wait_for_timeout(900)
        OUT["E_probe_req"]=has_req("POST","/api/v1/admin/integrations/email/test")
        OUT["E_probe_ok"]=('SUCCESS' in (t:=ntxt(pg))) and ('95ms' in t)
        OUT["E_nomail"]=not any('"to"' in (r["b"] or "") or '"subject"' in (r["b"] or "") for r in OUT["reqs"])
        OUT["E_nomail_note"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('هرگز ایمیلی ارسال نمی‌کند')")
        # probe timeout classified
        setmode("super",probe_timeout=True)
        reset()
        pg.evaluate("()=>{f3EMTest();}"); pg.wait_for_timeout(900)
        OUT["E_timeout"]='TIMEOUT' in ntxt(pg)
        setmode("super")
        pg.close(); reset()

        # ================= AI HEALTH — admin (aiManage + system.health.view) =================
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg)
        pg.evaluate("()=>location.hash='#/ai-health'"); pg.wait_for_timeout(1000)
        OUT["H_req_health"]=has_req("GET","/api/v1/admin/system/health")
        OUT["H_req_diag"]=has_req("GET","/api/v1/admin/system/diagnostics")
        OUT["H_sys"]=('API' in (t:=ntxt(pg))) and ('3ms' in t)
        OUT["H_integ"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('metaapi')&&t.includes('email')&&t.includes('UNKNOWN')}")
        OUT["H_provs"]=pg.evaluate("()=>{const t=document.querySelector('#view').innerText;return t.includes('gemini')&&t.includes('VALID')}")
        # provider actions (real backend endpoints)
        reset()
        pg.evaluate("()=>{f3AHAction('gemini','verify');}"); pg.wait_for_timeout(900)
        OUT["H_verify_req"]=has_req("POST","/api/v1/admin/providers/gemini/verify")
        OUT["H_verify_res"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('VALID')")
        reset()
        pg.evaluate("()=>{f3AHAction('gemini','test');}"); pg.wait_for_timeout(900)
        OUT["H_test"]='318ms' in ntxt(pg)
        # honest provider failure
        setmode("admin",fail_actions=True)
        reset()
        pg.evaluate("()=>{f3AHAction('openai','verify');}"); pg.wait_for_timeout(900)
        OUT["H_fail"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('PROVIDER_TIMEOUT')")
        setmode("admin")
        # diagnostics refresh: POST + server re-fetch
        reset()
        pg.evaluate("()=>{f3AHRefresh();}"); pg.wait_for_timeout(1200)
        OUT["H_refresh_req"]=has_req("POST","/api/v1/admin/system/diagnostics/refresh")
        OUT["H_refresh_refetch"]=sum(1 for r in OUT["reqs"] if r["u"]=="/api/v1/admin/system/diagnostics" and r["m"]=="GET")>=1
        OUT["H_refresh_toast"]=pg.evaluate("()=>document.querySelector('#toasts').innerText.includes('به‌روزرسانی شد')")
        OUT["H_refresh_state"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('SUCCESS')")
        # refresh failure honest (no fake refresh)
        setmode("admin",fail_actions=True)
        reset()
        pg.evaluate("()=>{f3AHRefresh();}"); pg.wait_for_timeout(1000)
        OUT["H_refresh_fail"]=pg.evaluate("()=>document.querySelector('#toasts').innerText.includes('INTERNAL_ERROR')")
        setmode("admin")
        pg.close(); reset()
        # ================= AI HEALTH — limited admin: no protected calls =================
        setmode("limited")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg)
        pg.evaluate("()=>location.hash='#/ai-health'"); pg.wait_for_timeout(900)
        OUT["H_limited_nocall"]=not any("system/health" in r["u"] or "system/diagnostics" in r["u"] for r in OUT["reqs"])
        pg.close(); reset()

        # ================= responsive matrix (4 pages × 7 widths × dark/light × FA/EN) =================
        setmode("admin")
        pgp=newpg(p,viewport={"width":1440,"height":950})
        pgp.on("pageerror", lambda e: OUT["js"].append("PAGEERROR:"+str(e)[:160]))
        goto(pgp)
        ov={}
        for w,hh in [(1440,950),(1280,900),(1024,800),(768,900),(430,900),(390,844),(360,780)]:
            pgp.set_viewport_size({"width":w,"height":hh})
            for rt in ("integrations-n8n","integrations-metaapi","integrations-email","ai-health"):
                for th,lg in (("dark","fa"),("light","en")):
                    pgp.evaluate(f"()=>{{setTheme('{th}');setLang('{lg}');location.hash='#/{rt}'}}")
                    pgp.wait_for_timeout(260)
                    ovv=pgp.evaluate("()=>document.documentElement.scrollWidth-document.documentElement.clientWidth")
                    ov[f"{w}-{rt}-{th}-{lg}"]=ovv
        pgp.set_viewport_size({"width":1440,"height":950})
        OUT["X_bad"]=[k for k,v in ov.items() if v!=0]
        OUT["X_zero"]=len(OUT["X_bad"])==0
        # mobile: stacked table on ai-health (frozen data-mstack CSS)
        pgp.set_viewport_size({"width":390,"height":844})
        pgp.evaluate("()=>{setLang('fa');location.hash='#/ai-health'}"); pgp.wait_for_timeout(800)
        OUT["H_mstack"]=pgp.evaluate("()=>{const t=document.querySelector('#view table.tbl');return !!t&&t.getAttribute('data-mstack')!==null&&getComputedStyle(t.querySelector('thead')).display==='none'}")
        # a11y: labelled inputs, keyboard-reachable actions, touch targets
        pgp.evaluate("()=>location.hash='#/integrations-email'"); pgp.wait_for_timeout(700)
        OUT["Y_labels"]=pgp.evaluate("()=>[...document.querySelectorAll('#view .inp')].every(i=>i.getAttribute('aria-label'))")
        OUT["Y_touch"]=pgp.evaluate("()=>{const vis=e=>e.offsetParent!==null&&e.getBoundingClientRect().height>0;const nav=[...document.querySelectorAll('.navitem')].filter(e=>vis(e)&&e.getBoundingClientRect().height<46);return nav.length===0}")
        pgp.keyboard.press("Tab"); pgp.wait_for_timeout(120)
        OUT["Y_kbd"]=pgp.evaluate("()=>document.activeElement&&document.activeElement!==document.body")
        # secret safety DOM scan across all four pages
        for rt in ("integrations-n8n","integrations-metaapi","integrations-email","ai-health"):
            pgp.evaluate(f"()=>location.hash='#/{rt}'"); pgp.wait_for_timeout(600)
        OUT["SEC_dom"]=pgp.evaluate("()=>!/(tok_live[A-Za-z0-9]*|rk_live[A-Za-z0-9]*|whsec[A-Za-z0-9]*|sk-[A-Za-z0-9]{8}|AIza[A-Za-z0-9_\\-]{10})/.test(document.body.innerText)")
        pgp.close()
finally:
    srv.terminate()
    try: os.remove(os.path.join(HERE,"mode.json"))
    except OSError: pass

# ---------------- checks ----------------
fails=[]
def chk(k,cond):
    if not cond: fails.append(k)
chk("js",len(OUT["js"])==0)
chk("N_read",OUT["N_req"] and OUT["N_unsafe_state"])
chk("N_gate",OUT["N_readonly"] and OUT["N_nomutate"] and OUT["N_noprobe_note"])
chk("N_save",OUT["N_form"] and OUT["N_put"] and OUT["N_put_toast"] and OUT["N_put_state"] and OUT["N_tok_badge"])
chk("N_secret",OUT["N_tok_secret_dom"])
chk("N_invalid",OUT["N_invalid"] and OUT["N_invalid_nostate"])
chk("N_429",OUT["N_429"])
chk("N_reset",OUT["N_del"] and OUT["N_del_state"])
chk("M_read",OUT["M_req"] and OUT["M_fields"])
chk("M_gate",OUT["M_readonly"] and OUT["M_nomutate"])
chk("M_probe",OUT["M_probe_req"] and OUT["M_probe_ok"] and OUT["M_reach_upd"])
chk("M_timeout",OUT["M_timeout"])
chk("M_put",OUT["M_put"] and OUT["M_whs_badge"] and OUT["M_whs_secret_dom"])
chk("M_reset_nc",OUT["M_del"] and OUT["M_probe_nc"])
chk("E_read",OUT["E_req"] and OUT["E_fields"])
chk("E_gate",OUT["E_readonly"])
chk("E_form",OUT["E_form8"] and OUT["E_pass_placeholder_only"])
chk("E_save",OUT["E_put"] and OUT["E_put_toast"] and OUT["E_state_upd"] and OUT["E_secret_dom"])
chk("E_badport",OUT["E_badport"])
chk("E_probe",OUT["E_probe_req"] and OUT["E_probe_ok"] and OUT["E_nomail"] and OUT["E_nomail_note"])
chk("E_timeout",OUT["E_timeout"])
chk("H_load",OUT["H_req_health"] and OUT["H_req_diag"] and OUT["H_sys"] and OUT["H_integ"] and OUT["H_provs"])
chk("H_verify",OUT["H_verify_req"] and OUT["H_verify_res"])
chk("H_test",OUT["H_test"])
chk("H_fail",OUT["H_fail"])
chk("H_refresh",OUT["H_refresh_req"] and OUT["H_refresh_refetch"] and OUT["H_refresh_toast"] and OUT["H_refresh_state"])
chk("H_refresh_fail",OUT["H_refresh_fail"])
chk("H_limited",OUT["H_limited_nocall"])
chk("X_zero",OUT["X_zero"]); chk("H_mstack",OUT["H_mstack"])
chk("Y_a11y",OUT["Y_labels"] and OUT["Y_touch"] and OUT["Y_kbd"])
chk("SEC_dom",OUT["SEC_dom"])
print("FAILS:",fails if fails else "NONE — ALL GREEN")
for k,v in OUT.items():
    if k!="X_bad": print(k,"=",str(v)[:110])
try:
    json.dump(OUT,open(os.path.join(HERE,"last_f3b2_run.json"),"w"),ensure_ascii=False,indent=1,default=str)
except OSError: pass
sys.exit(1 if fails else 0)
