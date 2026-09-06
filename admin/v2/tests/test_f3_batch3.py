#!/usr/bin/env python3
"""F-3 Batch 3 suite — System (health/logs/audit/flags) + Analytics ×4 + Billing.

Proves all nine routes are real backend wiring: correct endpoints, displayed
values == API payloads, permission gating (no protected calls without the
permission), server-side audit sensitive-field stripping, flags PATCH with
server refresh + honest failures, revenue honesty (unavailable ≠ zero),
sparklines computed only from backend trend arrays, overflow matrix and zero
console errors. NOTE: the mission suggested a .spec.js file; this repository's
CI executes Python Playwright suites (established convention), so the suite
follows that convention and is registered in ci.yml like batches 1–2.
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
JS_NTXT="function ntxtJS(){const v=document.querySelector('#view');return v?v.innerText.replace(/[\\s\\u200c]+/g,''):''}"
def newpg(p,**kw):
    pg=p.chromium.launch().new_page(**kw)
    pg.add_init_script(JS_NTXT)
    pg.on("pageerror", lambda e: OUT["js"].append("PAGEERROR:"+str(e)[:160]))
    return pg
def track(pg):
    pg.on("request", lambda r: OUT["reqs"].append({"m":r.method,"u":r.url.split("8141")[-1],"b":(r.post_data or "")[:160]}) if "/api/" in r.url else None)
def goto(pg):
    pg.goto("http://127.0.0.1:8141/admin/v2/index.html"); pg.wait_for_timeout(1400)
def reset(): OUT["reqs"].clear()
def has_req(m,u): return any(r["m"]==m and r["u"]==u for r in OUT["reqs"])
def ntxt(pg): return pg.evaluate("()=>document.querySelector('#view').innerText.replace(/[\\s\\u200c]+/g,'')")
def rtxt(pg): return pg.evaluate("()=>document.querySelector('#view').innerText")
def nav(pg,route): pg.evaluate(f"()=>location.hash='#/{route}'"); pg.wait_for_timeout(900)
try:
    with sync_playwright() as p:
        # ================= SYSTEM HEALTH =================
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); nav(pg,"system-health")
        OUT["SH_req"]=has_req("GET","/api/v1/admin/system/health") and has_req("GET","/api/v1/admin/system/diagnostics")
        OUT["SH_data"]=('HEALTHY' in (t:=ntxt(pg))) and ('3ms' in t)
        OUT["SH_provs"]=('gemini' in (t:=ntxt(pg))) and ('VALID' in t)
        OUT["SH_refresh_req"]=False
        reset()
        pg.evaluate("()=>{f3SHRefresh();}"); pg.wait_for_timeout(1100)
        OUT["SH_refresh_req"]=has_req("POST","/api/v1/admin/system/diagnostics/refresh") and has_req("GET","/api/v1/admin/system/diagnostics")
        pg.close(); reset()
        # limited: no protected calls, permission named
        setmode("limited")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); nav(pg,"system-health")
        OUT["SH_limited"]=not any("system/health" in r["u"] or "system/diagnostics" in r["u"] for r in OUT["reqs"]) and 'system.health.view' in ntxt(pg)
        pg.close(); reset()

        # ================= SYSTEM LOGS =================
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); nav(pg,"system-logs")
        OUT["SL_req"]=has_req("GET","/api/v1/admin/logs/system?page=1&per_page=25")
        OUT["SL_rows"]=(('MetaAPI sync failed' in (t:=rtxt(pg))) and ('SYNC_FAILED' in t) and ('ERROR' in t) and ('DEBUG' in t))
        OUT["SL_redacted"]=("password" not in t and "sk-" not in t)
        # severity filter → server-side query
        reset()
        pg.select_option("#view select[aria-label]","ERROR") if pg.evaluate("()=>document.querySelectorAll('#view select').length")>0 else None
        pg.evaluate("()=>f3LogsSev('ERROR')"); pg.wait_for_timeout(700)
        OUT["SL_sev_q"]=any("severity=ERROR" in r["u"] for r in OUT["reqs"])
        OUT["SL_sev_rows"]=('MetaAPI sync failed' in (t:=rtxt(pg))) and ('worker heartbeat' not in t)
        # search → server q param
        reset()
        pg.evaluate("()=>f3LogsSearch('resend')"); pg.wait_for_timeout(800)
        OUT["SL_q"]=any("q=resend" in r["u"] for r in OUT["reqs"])
        # empty state honest
        reset()
        pg.evaluate("()=>f3LogsSearch('zzzznope')"); pg.wait_for_timeout(800)
        OUT["SL_empty"]=pg.evaluate("()=>!!document.querySelector('#view .statebox')")
        # pagination
        reset()
        pg.evaluate("()=>{f3LogsSearch('');f3LogsPage(1);}"); pg.wait_for_timeout(700)
        OUT["SL_page2"]=any("page=2" in r["u"] for r in OUT["reqs"])
        # error state honest
        setmode("admin",read500=True)
        reset()
        pg.evaluate("()=>{SL.st='idle';SL.err=null;f3LoadLogs();}"); pg.wait_for_timeout(800)
        OUT["SL_err"]=pg.evaluate("()=>!!document.querySelector('#view .statebox') && !!document.querySelector('#view .statebox .btn')")
        setmode("admin")
        pg.close(); reset()

        # ================= SECURITY AUDIT =================
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); nav(pg,"security-audit")
        OUT["SA_req"]=has_req("GET","/api/v1/admin/logs/audit?page=1&per_page=25")
        OUT["SA_rows"]=(('user.verify_email' in (t:=ntxt(pg))) and ('user#2' in t or 'user #2' in t or 'user' in t))
        # admin (no audit.view_sensitive): backend strips IP/context → not rendered, note shown
        OUT["SA_nosens"]=('203.0.113' not in t and 'ctx_25' not in t and 'audit.view_sensitive' in t)
        # result filter
        reset()
        pg.evaluate("()=>f3AuditResult('denied')"); pg.wait_for_timeout(700)
        OUT["SA_filter"]=any("result=denied" in r["u"] for r in OUT["reqs"])
        OUT["SA_denied"]=('denied' in ntxt(pg).lower() or 'ردشده' in ntxt(pg))
        pg.close(); reset()
        # super: sensitive columns present (backend includes them)
        setmode("super")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); nav(pg,"security-audit")
        OUT["SA_super_sens"]=('203.0.113.24' in ntxt(pg)) and ('ctx_25' in ntxt(pg))
        pg.close(); reset()

        # ================= SYSTEM FLAGS =================
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); nav(pg,"system-flags")
        OUT["SF_req"]=has_req("GET","/api/v1/admin/feature-flags")
        OUT["SF_rows"]=(('ai_assistant' in (t:=ntxt(pg))) and ('rollout:40' in t) and ('ai_weekly_report' in t))
        OUT["SF_readonly"]=pg.evaluate("()=>!document.querySelector('#sfRollout')&&!document.querySelector('#view .btn.primary')") and 'feature_flags.edit' in t
        OUT["SF_nomutate"]=not any(r["m"]=="PATCH" for r in OUT["reqs"])
        pg.close(); reset()
        # super: PATCH + server refresh + honest failures
        setmode("super")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); nav(pg,"system-flags")
        reset()
        pg.evaluate("()=>{f3FlagPick('ai_weekly_report');f3FlagEnabled('1');f3FlagRollout('60');}")
        pg.evaluate("()=>{f3FlagSave();}"); pg.wait_for_timeout(900)
        patch=[r for r in OUT["reqs"] if r["m"]=="PATCH" and "feature-flags/ai_weekly_report" in r["u"]]
        OUT["SF_patch"]=len(patch)==1 and '"rollout"' in (patch[0]["b"] or "") and "60" in (patch[0]["b"] or "")
        OUT["SF_toast"]=pg.evaluate("()=>document.querySelector('#toasts').innerText.includes('به‌روزرسانی شد')")
        OUT["SF_refresh"]=pg.evaluate("()=>{const row=document.querySelector('#flagrow-ai_weekly_report');return !!row&&row.innerText.includes('60%')}")
        # invalid rollout → client guard + real code path (set via state to bypass UI guard)
        reset()
        pg.evaluate("()=>{SF.sel='ai_assistant';SF.rollout='250';}")
        pg.evaluate("()=>{f3FlagSave();}"); pg.wait_for_timeout(700)
        OUT["SF_range"]=pg.evaluate("()=>document.querySelector('#toasts').innerText.includes('ROLLOUT_RANGE')")
        OUT["SF_range_noreq"]=not any(r["m"]=="PATCH" for r in OUT["reqs"])  # guarded before network
        # persist failure honest
        setmode("super",fail_actions=True)
        reset()
        pg.evaluate("()=>{SF.sel='ai_assistant';SF.rollout='50';f3FlagSave();}"); pg.wait_for_timeout(900)
        OUT["SF_fail"]=pg.evaluate("()=>document.querySelector('#toasts').innerText.includes('FEATURE_FLAG_PERSIST_FAILED')")
        setmode("super")
        pg.close(); reset()

        # ================= ANALYTICS: USERS =================
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); nav(pg,"analytics-users")
        OUT["ANU_req"]=any(r["m"]=="GET" and r["u"]=="/api/v1/admin/analytics/users?range=30d" for r in OUT["reqs"])
        OUT["ANU_data"]=(('128' in (t:=ntxt(pg))) and ('17' in t) and ('super_admin' in t) and ('101' in t))
        OUT["ANU_spark"]=pg.evaluate("()=>{const s=document.querySelector('#view .spark polyline');return !!s&&(s.getAttribute('points').split(' ').length===7)}")
        # range switch → new request with query
        reset()
        pg.evaluate("()=>f3AnRange('users','7d')"); pg.wait_for_timeout(800)
        OUT["ANU_range"]=any("analytics/users?range=7d" in r["u"] for r in OUT["reqs"])
        pg.close(); reset()
        # ================= ANALYTICS: TRADING =================
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); nav(pg,"analytics-trading")
        OUT["ANT_req"]=has_req("GET","/api/v1/admin/analytics/trading?range=30d")
        OUT["ANT_data"]=(('212' in (t:=ntxt(pg))) and ('3421.50' in t) and ('15234.75' in t) and ('XAUUSD' in t))
        OUT["ANT_note"]=('NOT platform revenue' in rtxt(pg))
        pg.close(); reset()
        # ================= ANALYTICS: AI =================
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); nav(pg,"analytics-ai")
        OUT["ANAI_req"]=has_req("GET","/api/v1/admin/analytics/ai?range=30d")
        OUT["ANAI_data"]=(('4210' in (t:=ntxt(pg))) and ('388' in t) and ('91234' in t) and ('18.4231' in t) and ('gemini-2.5-flash' in t))
        OUT["ANAI_nodrill"]=('anai.nodrill' in pg.evaluate("()=>F3T.fa")) and pg.evaluate("()=>!document.querySelector('#view .modalwrap')")
        pg.close(); reset()
        # ================= ANALYTICS: REVENUE (honesty) =================
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); nav(pg,"analytics-revenue")
        OUT["ANR_req"]=has_req("GET","/api/v1/admin/analytics/revenue")
        t=ntxt(pg)
        OUT["ANR_state"]=('NO_BILLING_SOURCE' in (t:=rtxt(pg))) and ('در دسترس نیست' in t or 'unavailable' in t.lower())
        OUT["ANR_notzero"]=not pg.evaluate("()=>{const k=document.querySelectorAll('#view .kpi');return k.length>0}") and not pg.evaluate("()=>!!document.querySelector('#view .spark')")
        OUT["ANR_metrics"]=('mrr' in t and 'ltv' in t)
        pg.close(); reset()

        # ================= BILLING OVERVIEW =================
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); nav(pg,"billing-overview")
        OUT["BO_req"]=has_req("GET","/api/v1/admin/billing")
        OUT["BO_provider"]=(('در دسترس نیست' in (t:=rtxt(pg))) and ('no provider client' in t or 'webhook' in t))
        OUT["BO_plans"]=(('Pro' in (t:=rtxt(pg))) and ('Free' in t) and ('قیمت معتبر نیست' in t or 'not authoritative' in t))
        OUT["BO_dist"]=(('96' in t) and ('32' in t) and ('past_due' in t or 'Past due' in t))
        OUT["BO_ent"]=(('10' in t) and ('config:metaapi.max_accounts_per_user' in t))
        OUT["BO_hist"]=('No invoice/payment history exists' in rtxt(pg))
        pg.close(); reset()

        # ================= responsive matrix (9 routes × 7 widths × dark/light × FA/EN) =================
        pgp=newpg(p,viewport={"width":1440,"height":950})
        pgp.on("pageerror", lambda e: OUT["js"].append("PAGEERROR:"+str(e)[:160]))
        goto(pgp)
        ov={}
        for w,hh in [(1440,950),(1280,900),(1024,800),(768,900),(430,900),(390,844),(360,780)]:
            pgp.set_viewport_size({"width":w,"height":hh})
            for rt in ("system-health","system-logs","security-audit","system-flags","analytics-users","analytics-trading","analytics-ai","analytics-revenue","billing-overview"):
                for th,lg in (("dark","fa"),("light","en")):
                    pgp.evaluate(f"()=>{{setTheme('{th}');setLang('{lg}');location.hash='#/{rt}'}}")
                    pgp.wait_for_timeout(240)
                    ovv=pgp.evaluate("()=>document.documentElement.scrollWidth-document.documentElement.clientWidth")
                    ov[f"{w}-{rt}-{th}-{lg}"]=ovv
        pgp.set_viewport_size({"width":1440,"height":950})
        OUT["X_bad"]=[k for k,v in ov.items() if v!=0]
        OUT["X_zero"]=len(OUT["X_bad"])==0
        # mobile stacked tables + touch + labels
        pgp.set_viewport_size({"width":390,"height":844})
        pgp.evaluate("()=>{setLang('fa');location.hash='#/system-logs'}"); pgp.wait_for_timeout(800)
        OUT["SL_mstack"]=pgp.evaluate("()=>{const tb=document.querySelector('#view table.tbl');return !!tb&&tb.getAttribute('data-mstack')!==null&&getComputedStyle(tb.querySelector('thead')).display==='none'}")
        pgp.evaluate("()=>location.hash='#/system-flags'"); pgp.wait_for_timeout(700)
        OUT["Y_touch"]=pgp.evaluate("()=>{const vis=e=>e.offsetParent!==null&&e.getBoundingClientRect().height>0;return [...document.querySelectorAll('.navitem')].filter(e=>vis(e)&&e.getBoundingClientRect().height<46).length===0}")
        pgp.evaluate("()=>location.hash='#/system-logs'"); pgp.wait_for_timeout(700)
        OUT["Y_labels"]=pgp.evaluate("()=>[...document.querySelectorAll('#view .inp')].every(i=>i.getAttribute('aria-label'))")
        pgp.keyboard.press("Tab"); pgp.wait_for_timeout(120)
        OUT["Y_kbd"]=pgp.evaluate("()=>document.activeElement&&document.activeElement!==document.body")
        # secret safety across pages
        for rt in ("system-health","security-audit","system-flags","analytics-ai","billing-overview"):
            pgp.evaluate(f"()=>location.hash='#/{rt}'"); pgp.wait_for_timeout(500)
        OUT["SEC_dom"]=pgp.evaluate("()=>!/(sk-[A-Za-z0-9]{8}|AIza[A-Za-z0-9_\\-]{10}|tok_live|rk_live|whsec|password\\s*[:=]\\s*\\S)/.test(document.body.innerText)")
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
chk("SH_load",OUT["SH_req"] and OUT["SH_data"] and OUT["SH_provs"])
chk("SH_refresh",OUT["SH_refresh_req"])
chk("SH_limited",OUT["SH_limited"])
chk("SL_load",OUT["SL_req"] and OUT["SL_rows"] and OUT["SL_redacted"])
chk("SL_filter",OUT["SL_sev_q"] and OUT["SL_sev_rows"])
chk("SL_search",OUT["SL_q"])
chk("SL_empty",OUT["SL_empty"])
chk("SL_pages",OUT["SL_page2"])
chk("SL_err",OUT["SL_err"])
chk("SA_load",OUT["SA_req"] and OUT["SA_rows"])
chk("SA_sens",OUT["SA_nosens"])
chk("SA_filter",OUT["SA_filter"] and OUT["SA_denied"])
chk("SA_super",OUT["SA_super_sens"])
chk("SF_read",OUT["SF_req"] and OUT["SF_rows"])
chk("SF_gate",OUT["SF_readonly"] and OUT["SF_nomutate"])
chk("SF_patch",OUT["SF_patch"] and OUT["SF_toast"] and OUT["SF_refresh"])
chk("SF_range",OUT["SF_range"] and OUT["SF_range_noreq"])
chk("SF_fail",OUT["SF_fail"])
chk("ANU",OUT["ANU_req"] and OUT["ANU_data"] and OUT["ANU_spark"] and OUT["ANU_range"])
chk("ANT",OUT["ANT_req"] and OUT["ANT_data"] and OUT["ANT_note"])
chk("ANAI",OUT["ANAI_req"] and OUT["ANAI_data"] and OUT["ANAI_nodrill"])
chk("ANR",OUT["ANR_req"] and OUT["ANR_state"] and OUT["ANR_notzero"] and OUT["ANR_metrics"])
chk("BO",OUT["BO_req"] and OUT["BO_provider"] and OUT["BO_plans"] and OUT["BO_dist"] and OUT["BO_ent"] and OUT["BO_hist"])
chk("X_zero",OUT["X_zero"]); chk("SL_mstack",OUT["SL_mstack"])
chk("Y_a11y",OUT["Y_touch"] and OUT["Y_labels"] and OUT["Y_kbd"])
chk("SEC_dom",OUT["SEC_dom"])
print("FAILS:",fails if fails else "NONE — ALL GREEN")
for k,v in OUT.items():
    if k!="X_bad": print(k,"=",str(v)[:110])
try:
    json.dump(OUT,open(os.path.join(HERE,"last_f3b3_run.json"),"w"),ensure_ascii=False,indent=1,default=str)
except OSError: pass
sys.exit(1 if fails else 0)
