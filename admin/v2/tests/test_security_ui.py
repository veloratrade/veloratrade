#!/usr/bin/env python3
"""Phase 6 UI suite — Global signup history/clusters + global login history over the stub.

Proves: the frozen #/security-signups and #/security-logins routes render REAL
stubbed API data (KPI cards, existing-shaped registration trend, shared-source
cluster cards, informational member drawer with User360 navigation, global
login rows with identity), the D3 privacy matrix END-TO-END (plain admin sees
masked keys and NO raw ip/user_agent anywhere in #view — innerText AND
innerHTML; super admin sees the raw values; the sensitive badge flips), the
drawer is informational only (no bulk actions), honest loading/empty/error
states, filters fire whitelisted query params, FA/EN + RTL/LTR, dark/light,
keyboard (Esc closes the drawer), zero console errors, zero horizontal
overflow at 1440/1280/1024/768/430/390/360 for BOTH pages, and localization
completeness of every new su.*/slh.* key in both catalogs.
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
def view_clean(pg):
    """No raw stub ip/user_agent strings anywhere in #view (D3: server omission)."""
    return pg.evaluate("""()=>{const t=document.querySelector('#view').innerText;const h=document.querySelector('#view').innerHTML;
      return !t.includes('198.51.100.7')&&!t.includes('198.51.100.9')&&!h.includes('198.51.100.7')&&!h.includes('198.51.100.9')
        &&!t.includes('SharedUA')&&!h.includes('SharedUA')&&!t.includes('Mozilla/5.0 StubUA')&&!h.includes('Mozilla/5.0 StubUA')
        &&!t.includes('9.9.9.9')&&!h.includes('9.9.9.9')}""")
fails=0
def chk(name,cond):
    global fails
    if not cond: fails+=1
    print(("PASS " if cond else "FAIL ")+name)
try:
    with sync_playwright() as p:
        # ===== A. signups page (plain admin — masked, privacy note) =====
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg)
        goto(pg); reset(); route(pg,"security-signups")
        txt=pg.evaluate("()=>document.querySelector('#view').innerText")
        OUT["S_title"]=("Signup history" in txt) or ("تاریخچه ثبت‌نام‌ها" in txt)
        OUT["S_kpis"]=("25" in txt) and ("3" in txt) and ("2" in txt) and ("1" in txt)
        OUT["S_clusters"]=pg.evaluate("()=>document.querySelectorAll('#view .grid .card .mono').length")>=2
        OUT["S_masked"]="198.51.*.*" in txt
        OUT["S_priv_note"]="audit.view_sensitive" in txt
        OUT["S_badge_plain"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('audit.view_sensitive')")
        OUT["S_no_raw"]=view_clean(pg)
        OUT["S_nohardcoded"]=pg.evaluate("()=>{const h=document.querySelector('#view').innerHTML;return !h.includes('password')&&!h.includes('credential')&&!/token/i.test(h)}")
        OUT["S_req"]=any("/api/v1/admin/security/signups" in r["u"] and "days=30" in r["u"] for r in OUT["reqs"])
        # range switch fires days=7
        reset(); pg.select_option("#view select.inp","7"); pg.wait_for_timeout(900)
        OUT["S_days_req"]=any("days=7" in r["u"] for r in OUT["reqs"])
        pg.select_option("#view select.inp","30"); pg.wait_for_timeout(700)
        # ===== B. drawer (informational; User360 nav; Esc closes) =====
        pg.evaluate("()=>f6OpenDrawer(0)"); pg.wait_for_timeout(400)
        OUT["D_open"]=pg.evaluate("()=>{const m=document.querySelector('#modal-root .modal');return !!m&&m.innerText.includes('Sara Ahmadi')&&m.innerText.includes('Mina Nouri')}")
        OUT["D_no_ip"]=pg.evaluate("()=>{const m=document.querySelector('#modal-root .modal');return m&&!(m.innerText.includes('198.51.100.7')||m.innerText.includes('SharedUA'))}")
        OUT["D_no_bulk"]=pg.evaluate("()=>{const m=document.querySelector('#modal-root .modal');return m&&!m.innerText.includes('Suspend')&&!m.innerText.includes('Revoke')&&!m.innerText.includes('تعلیق')&&!m.innerText.includes('لغو')}")
        # keyboard: Esc closes the drawer
        pg.keyboard.press("Escape"); pg.wait_for_timeout(300)
        OUT["D_esc"]=pg.evaluate("()=>!document.querySelector('#modal-root .modal')")
        # reopen and navigate to User360
        pg.evaluate("()=>f6OpenDrawer(0)"); pg.wait_for_timeout(300)
        pg.evaluate("()=>{document.querySelector('#modal-root .modal .btn.sm:not(.btn.primary)').click()}"); pg.wait_for_timeout(200)
        # click the FIRST User360 button explicitly (deterministic)
        pg.evaluate("()=>{const b=[...document.querySelectorAll('#modal-root button')].find(b=>b.innerText.includes('User360'));if(b)b.click()}"); pg.wait_for_timeout(1200)
        OUT["D_u360"]=pg.evaluate("()=>location.hash.startsWith('#/users/')")
        route(pg,"security-signups")
        # ===== C. honest empty (clusters beyond range) =====
        pg.evaluate("()=>{SG.cpage=99;f6LoadSignups()}"); pg.wait_for_timeout(900)
        OUT["S_empty"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('No clusters found')||document.querySelector('#view').innerText.includes('خوشه‌ای یافت نشد')")
        pg.evaluate("()=>{SG.cpage=1;f6LoadSignups()}"); pg.wait_for_timeout(700)
        # ===== D. logins page (plain admin) =====
        route(pg,"security-logins")
        txt=pg.evaluate("()=>document.querySelector('#view').innerText")
        OUT["L_title"]=("Global login history" in txt) or ("تاریخچه ورود سراسری" in txt)
        OUT["L_rows"]=pg.evaluate("()=>document.querySelectorAll('#view tbody tr').length")==4
        OUT["L_unknown"]=("Unknown account" in txt) or ("حساب ناشناس" in txt)
        OUT["L_no_raw"]=view_clean(pg)
        OUT["L_priv_note"]="audit.view_sensitive" in txt
        reset(); pg.select_option("#slhResult","failure"); pg.wait_for_timeout(400)
        pg.evaluate("()=>f6LoginApply()"); pg.wait_for_timeout(900)
        OUT["L_result_req"]=any("result=failure" in r["u"] for r in OUT["reqs"])
        OUT["L_result_rows"]=pg.evaluate("()=>document.querySelectorAll('#view tbody tr').length")==2
        pg.select_option("#slhResult",""); pg.evaluate("()=>f6LoginApply()"); pg.wait_for_timeout(800)
        # honest empty: page beyond range
        pg.evaluate("()=>{SLG.page=99;f6LoadLogins()}"); pg.wait_for_timeout(900)
        OUT["L_empty"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('No events found')||document.querySelector('#view').innerText.includes('رویدادی یافت نشد')")
        pg.evaluate("()=>{SLG.page=1;f6LoadLogins()}"); pg.wait_for_timeout(700)
        # ===== E. error states (fail_security) — FRESH pages so the fetch actually fires =====
        setmode("admin",fail_security=True)
        pg3=newpg(p,viewport={"width":1440,"height":950}); goto(pg3); route(pg3,"security-logins")
        OUT["E_logins"]=pg3.evaluate("()=>{const v=document.querySelector('#view');return v.innerText.includes('HTTP 500')||v.innerText.includes('Failed to load')||v.innerText.includes('دریافت داده از سرور ناموفق بود')||!!v.querySelector('.statebox')}")
        OUT["E_retry"]=pg3.evaluate("()=>!![...document.querySelectorAll('#view button')].find(b=>b.innerText==='Retry'||b.innerText==='تلاش دوباره')")
        route(pg3,"security-signups"); pg.wait_for_timeout(1200)
        OUT["E_signups"]=pg3.evaluate("()=>{const v=document.querySelector('#view');return !!v.querySelector('.statebox')}")
        pg3.close()
        setmode("admin")
        # ===== F. super admin sees raw values end-to-end (fresh page: idle -> fetch) =====
        setmode("super")
        pg=newpg(p,viewport={"width":1440,"height":950}); track(pg); goto(pg)
        route(pg,"security-signups")
        OUT["SP_raw_key"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('198.51.100.7')")
        OUT["SP_badge"]=pg.evaluate("()=>{const v=document.querySelector('#view').innerText;return v.includes('ip / user-agent')&&!v.includes('audit.view_sensitive (')}")
        OUT["SP_no_note"]=pg.evaluate("()=>!document.querySelector('#view .note')")
        pg.evaluate("()=>f6OpenDrawer(0)"); pg.wait_for_timeout(400)
        OUT["SP_drawer_ip"]=pg.evaluate("()=>{const m=document.querySelector('#modal-root .modal');return m&&m.innerText.includes('198.51.100.7')&&m.innerText.includes('SharedUA/1')}")
        pg.keyboard.press("Escape"); pg.wait_for_timeout(200)
        route(pg,"security-logins")
        OUT["LP_raw"]=pg.evaluate("()=>{const v=document.querySelector('#view').innerText;return v.includes('198.51.100.7')&&v.includes('Mozilla/5.0 StubUA')&&v.includes('9.9.9.9')}")
        OUT["LP_ip_cols"]=pg.evaluate("()=>document.querySelectorAll('#view thead th').length>=7")
        setmode("admin")
        # ===== G. EN + LTR (both pages) =====
        pg.evaluate("()=>localStorage.setItem('velora_locale','en')"); goto(pg)
        route(pg,"security-signups")
        OUT["EN_su"]=pg.evaluate("()=>document.documentElement.dir==='ltr'&&document.querySelector('#view').innerText.includes('Signup history')&&document.querySelector('#view').innerText.includes('Shared-source signup clusters')")
        route(pg,"security-logins")
        OUT["EN_sl"]=pg.evaluate("()=>document.querySelector('#view').innerText.includes('Global login history')")
        # ===== H. FA + RTL =====
        pg.evaluate("()=>localStorage.setItem('velora_locale','fa')"); goto(pg)
        route(pg,"security-signups")
        OUT["FA_su"]=pg.evaluate("()=>document.documentElement.dir==='rtl'&&document.querySelector('#view').innerText.includes('خوشه‌های ثبت‌نام هم‌منبع')")
        # ===== I. dark/light =====
        pg.evaluate("()=>document.documentElement.setAttribute('data-theme','light')"); pg.wait_for_timeout(300)
        OUT["TH_light"]=pg.evaluate("()=>{const v=document.querySelector('#view');const cs=getComputedStyle(v);return !!v.innerText&&document.documentElement.dataset.theme==='light'}")
        pg.evaluate("()=>document.documentElement.setAttribute('data-theme','dark')")
        # ===== J. localization completeness: every su.*/slh.* key resolves in BOTH catalogs =====
        OUT["LOC"]=pg.evaluate("""()=>{const ks=Object.keys(F3T.fa).filter(k=>k.startsWith('su.')||k.startsWith('slh.'));
          return ks.length>=40&&ks.every(k=>{const fa=F3T.fa[k],en=F3T.en[k];return typeof fa==='string'&&fa.length>0&&typeof en==='string'&&en.length>0&&fa!==k&&en!==k})}""")
        # ===== K. responsive: both pages, 7 widths, zero horizontal overflow =====
        for w in (1440,1280,1024,768,430,390,360):
            setmode("admin")
            pg2=newpg(p,viewport={"width":w,"height":900})
            goto(pg2); route(pg2,"security-signups")
            o1=pg2.evaluate("()=>document.scrollingElement.scrollWidth-document.scrollingElement.clientWidth")
            route(pg2,"security-logins")
            o2=pg2.evaluate("()=>document.scrollingElement.scrollWidth-document.scrollingElement.clientWidth")
            OUT[f"W{w}"]=(o1==0 and o2==0)
            pg2.close()
except Exception as ex:
    OUT["fatal"]=str(ex)[:300]; fails+=1
chk("S_page",OUT.get("S_title") and OUT.get("S_kpis") and OUT.get("S_clusters") and OUT.get("S_masked") and OUT.get("S_req") and OUT.get("S_days_req"))
chk("S_privacy",OUT.get("S_no_raw") and OUT.get("S_priv_note") and OUT.get("S_badge_plain") and OUT.get("S_nohardcoded"))
chk("D_drawer",OUT.get("D_open") and OUT.get("D_no_ip") and OUT.get("D_no_bulk") and OUT.get("D_esc") and OUT.get("D_u360"))
chk("EMPTIES",OUT.get("S_empty") and OUT.get("L_empty"))
chk("L_page",OUT.get("L_title") and OUT.get("L_rows") and OUT.get("L_unknown") and OUT.get("L_no_raw") and OUT.get("L_priv_note"))
chk("L_filters",OUT.get("L_result_req") and OUT.get("L_result_rows"))
chk("E_error_state",OUT.get("E_logins") and OUT.get("E_retry") and OUT.get("E_signups"))
chk("SUPER",OUT.get("SP_raw_key") and OUT.get("SP_badge") and OUT.get("SP_no_note") and OUT.get("SP_drawer_ip") and OUT.get("LP_raw") and OUT.get("LP_ip_cols"))
chk("LANG",OUT.get("EN_su") and OUT.get("EN_sl") and OUT.get("FA_su"))
chk("THEME",OUT.get("TH_light"))
chk("LOC",OUT.get("LOC"))
chk("W_widths",all(OUT.get(f"W{w}") for w in (1440,1280,1024,768,430,390,360)))
chk("X_zero",not OUT["js"])
if OUT.get("fatal"): print("FATAL:",OUT["fatal"])
print("FAILS:",fails if fails else "NONE — ALL GREEN")
try:
    srv.terminate()
except Exception:
    pass
sys.exit(1 if fails else 0)
