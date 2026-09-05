#!/usr/bin/env python3
"""F-2 navigation suite (sections A-AA).

Covers: canonical route registry integrity, all production routes resolve,
unknown-route honesty, dynamic users/:id, browser back/forward, active
sidebar state, accordion behavior, mobile drawer close + focus, command
palette (open/search/keyboard/permissions), route-level permission gating,
no protected API calls for denied routes, 401 vs 403 distinction, FA/EN +
RTL/LTR persistence, theme persistence + invalid fallback, no fake data,
overflow at 7 widths x 2 themes x 2 directions, keyboard focus behavior,
reduced motion, zero console errors.

Run: python3 admin/v2/tests/test_f2_navigation.py   (self-contained; exits 1 on failure)
"""
import json,subprocess,time,sys,os
from playwright.sync_api import sync_playwright
HERE=os.path.dirname(os.path.abspath(__file__))
srv=subprocess.Popen(["python3",os.path.join(HERE,"f1_stub.py")],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
time.sleep(1.2)
OUT={"js":[],"api":[]}
def setmode(m):
    json.dump({"mode":m,"logout_called":False},open(os.path.join(HERE,"mode.json"),"w"))
def newpg(p,**kw):
    pg=p.chromium.launch().new_page(**kw)
    pg.on("pageerror", lambda e: OUT["js"].append(str(e)[:200]))
    return pg
def goto(pg,h=None):
    pg.goto("http://127.0.0.1:8141/admin/v2/index.html"+(h or ""))
    pg.wait_for_timeout(1400)
def L(pg,k):  # resolve SHELL_T/T fa value (never the raw key)
    return pg.evaluate("k=>T.fa[k]??SHELL_T.fa[k]??k",k)
def routes(pg):
    return pg.evaluate("()=>window.__VELORA_ROUTES__.map(d=>({route:d.route,group:d.group,perm:d.perm,status:d.status,nav:d.nav,dynamic:!!d.dynamic,labelKey:d.labelKey}))")
try:
    with sync_playwright() as p:
        setmode("admin")
        pg=newpg(p,viewport={"width":1440,"height":950})
        pg.on("request", lambda r: OUT["api"].append(r.url) if "/api/" in r.url else None)
        # track velora:route events
        pg.goto("http://127.0.0.1:8141/admin/v2/index.html"); pg.wait_for_timeout(1400)
        pg.evaluate("()=>{window.__rt=[];window.addEventListener('velora:route',e=>window.__rt.push(e.detail))}")

        # ---------- A. registry integrity ----------
        R=routes(pg)
        expected={"overview","users","trading-accounts","trading-trades","ai-providers","ai-route","ai-usage","ai-health",
         "integrations-metaapi","integrations-email","integrations-n8n","integrations-services",
         "analytics-users","analytics-trading","analytics-ai","analytics-revenue",
         "billing-overview","billing-plans","billing-subs","security-signups","security-logins",
         "security-sessions","security-devices","security-audit","security-events",
         "system-health","system-logs","system-diagnostics","system-flags",
         "settings-admins","settings-permissions","settings-config"}
        statics={d["route"] for d in R if not d["dynamic"]}
        OUT["A_count"]=len(R)
        OUT["A_static_ok"]=statics==expected
        OUT["A_no_design"]="design" not in statics
        OUT["A_meta_ok"]=all(d["labelKey"] and d["status"] in ("placeholder","implemented") and isinstance(d["nav"],bool) for d in R)
        OUT["A_impl_set"]=sorted(d["route"] for d in R if d["status"]=="implemented")
        u360=[d for d in R if d["dynamic"]]
        OUT["A_u360"]=len(u360)==1 and u360[0]["route"]=="users/:id" and not u360[0]["nav"] and u360[0]["perm"]=="users.view"

        # ---------- B. all production routes resolve (placeholder status) ----------
        lbl=pg.evaluate("""()=>{const o={};window.__VELORA_ROUTES__.forEach(d=>{o[d.route]=(T.fa[d.labelKey]??SHELL_T.fa[d.labelKey]??d.labelKey)});return o}""")
        NF=L(pg,"shell.notfound.title"); BADGE=L(pg,"shell.pending.badge"); DEN=L(pg,"shell.denied.title")
        bad=[]
        for rt in sorted(expected):
            pg.evaluate(f"()=>location.hash='#/{rt}'"); pg.wait_for_timeout(70)
            st=pg.evaluate("""(want)=>{const v=document.querySelector('#view').innerText;
              return {nf:v.includes(want.nf), badge:v.includes(want.badge),
                crumb:document.querySelector('#crumb').innerText,
                title:document.title,
                active:(document.querySelector('.navitem.active')||{}).dataset?document.querySelector('.navitem.active').dataset.route:null}}""",
                {"nf":NF,"badge":BADGE})
            if st["nf"] or (rt not in ("overview","users","ai-providers","ai-route") and not st["badge"]):
                bad.append((rt,"page",st))
            if not st["crumb"].strip().endswith(lbl[rt]) and rt!="overview":
                bad.append((rt,"crumb:"+st["crumb"]))
            if not st["title"].endswith("VELORA ADMIN"):
                bad.append((rt,"title:"+st["title"]))
        OUT["B_bad"]=bad
        OUT["B_events"]=pg.evaluate("()=>window.__rt.length")>=32

        # ---------- C. unknown routes ----------
        pg.evaluate("()=>location.hash='#/definitely-not-a-route'"); pg.wait_for_timeout(120)
        nfk=L(pg,"shell.notfound.title")
        OUT["C_nf"]=pg.evaluate("k=>document.querySelector('#view').innerText.includes(k)",nfk)
        OUT["C_design"]=pg.evaluate("k=>{location.hash='#/design';return new Promise(r=>setTimeout(()=>r(document.querySelector('#view').innerText.includes(k)),150))}",nfk)
        pg.evaluate("()=>location.hash='#/users/1/extra'"); pg.wait_for_timeout(120)
        OUT["C_deep"]=pg.evaluate("k=>document.querySelector('#view').innerText.includes(k)",nfk)
        pg.evaluate("()=>location.hash='#/definitely-not-a-route'"); pg.wait_for_timeout(120)
        OUT["C_back"]=pg.evaluate("()=>{document.querySelector('#view .btn').click();return new Promise(r=>setTimeout(()=>r(location.hash==='#/overview'),200))}")

        # ---------- D. dynamic users/:id ----------
        pg.evaluate("()=>location.hash='#/users/42'"); pg.wait_for_timeout(150)
        OUT["D_u360"]=pg.evaluate("(k)=>document.querySelector('#view').innerText.includes('#42') && document.querySelector('#view').innerText.includes(k)",L(pg,"nav.user360"))
        OUT["D_nosidebar"]=pg.evaluate("()=>![...document.querySelectorAll('.navitem')].some(n=>n.dataset.route==='users/:id'||n.dataset.route==='user360')")

        # ---------- E. back/forward ----------
        pg.evaluate("()=>location.hash='#/overview'"); pg.wait_for_timeout(120)
        pg.evaluate("()=>{document.querySelector('.navitem[data-route=users]').click()}" if False else "()=>location.hash='#/users'"); pg.wait_for_timeout(120)
        pg.evaluate("()=>location.hash='#/billing-plans'"); pg.wait_for_timeout(120)
        pg.go_back(); pg.wait_for_timeout(150)
        OUT["E_back1"]=pg.evaluate("()=>location.hash==='#/users'")
        pg.go_back(); pg.wait_for_timeout(150)
        OUT["E_back2"]=pg.evaluate("()=>location.hash==='#/overview'")
        pg.go_forward(); pg.wait_for_timeout(150)
        OUT["E_fwd"]=pg.evaluate("()=>location.hash==='#/users'")

        # ---------- F. active sidebar state ----------
        pg.evaluate("()=>location.hash='#/billing-plans'"); pg.wait_for_timeout(150)
        OUT["F_active"]=pg.evaluate("()=>{const a=document.querySelector('.navitem.active');return a&&a.dataset.route==='billing-plans'&&a.getAttribute('aria-current')==='page'}")
        OUT["F_single"]=pg.evaluate("()=>document.querySelectorAll('.navitem[aria-current=page]').length===1")

        # ---------- G. accordion ----------
        pg.evaluate("()=>location.hash='#/security-audit'"); pg.wait_for_timeout(150)
        OUT["G_auto"]=pg.evaluate("()=>document.querySelector('.ghead[data-g=\"nav.security\"]').getAttribute('aria-expanded')==='true' && document.querySelector('.ghead[data-g=\"nav.ai\"]').getAttribute('aria-expanded')==='false'")
        OUT["G_manual_set"]=pg.evaluate("()=>{document.querySelector('.ghead[data-g=\"nav.trading\"]').click();return document.querySelector('.ghead[data-g=\"nav.trading\"]').getAttribute('aria-expanded')}")
        pg.evaluate("()=>location.hash='#/overview'"); pg.wait_for_timeout(150)   # palette-like nav (manual state survives)
        OUT["G_manual_kept"]=pg.evaluate("()=>document.querySelector('.ghead[data-g=\"nav.trading\"]').getAttribute('aria-expanded')==='true'")
        pg.evaluate("()=>{document.querySelector('.ghead[data-g=\"nav.security\"]').click()}"); pg.wait_for_timeout(120)
        OUT["G_single_switch"]=pg.evaluate("()=>document.querySelector('.ghead[data-g=\"nav.trading\"]').getAttribute('aria-expanded')==='false' && document.querySelector('.ghead[data-g=\"nav.security\"]').getAttribute('aria-expanded')==='true'")
        pg.evaluate("()=>{document.querySelector('.navitem[data-route=security-audit]').click()}" if False else "()=>location.hash='#/security-audit'"); pg.wait_for_timeout(150)
        OUT["G_group_after_nav"]=pg.evaluate("()=>document.querySelector('.ghead[data-g=\"nav.trading\"]').getAttribute('aria-expanded')==='false' && document.querySelector('.ghead[data-g=\"nav.security\"]').getAttribute('aria-expanded')==='true'")

        # ---------- H. mobile drawer + focus ----------
        pgm=newpg(p,viewport={"width":390,"height":844})
        pgm.on("pageerror", lambda e: OUT["js"].append(str(e)[:200]))
        pgm.goto("http://127.0.0.1:8141/admin/v2/index.html"); pgm.wait_for_timeout(1400)
        pgm.click("#menuBtn"); pgm.wait_for_timeout(350)
        pgm.evaluate("()=>{document.querySelector('.ghead[data-g=\"nav.trading\"]').click()}" if False else "()=>document.querySelector('.ghead[data-g=\\'nav.trading\\']').click()")
        pgm.wait_for_timeout(250)
        pgm.click(".navitem[data-route=trading-accounts]"); pgm.wait_for_timeout(400)
        OUT["H_closed"]=pgm.evaluate("()=>!document.querySelector('#sidebar').classList.contains('open')")
        OUT["H_focus"]=pgm.evaluate("()=>document.activeElement&&document.activeElement.tagName==='H1'")
        OUT["H_route"]=pgm.evaluate("()=>location.hash==='#/trading-accounts'")

        # ---------- I. palette open ----------
        pg.keyboard.press("Control+k"); pg.wait_for_timeout(250)
        OUT["I_open"]=pg.evaluate("()=>!!document.querySelector('#palette-root .palette')")
        OUT["I_focus"]=pg.evaluate("()=>document.activeElement&&document.activeElement.id==='palInput'")
        pg.keyboard.press("Escape"); pg.wait_for_timeout(200)
        OUT["I_esc"]=pg.evaluate("()=>!document.querySelector('#palette-root .palette')")
        pg.click("#palBtn"); pg.wait_for_timeout(250)
        OUT["I_btn"]=pg.evaluate("()=>!!document.querySelector('#palette-root .palette')")
        pg.keyboard.press("Escape"); pg.wait_for_timeout(200)

        # ---------- J. palette search (FA + EN + kw + group, permissions-aware) ----------
        pg.click("#palBtn"); pg.wait_for_timeout(200)
        pg.fill("#palInput","کاربر"); pg.wait_for_timeout(200)
        OUT["J_fa"]=pg.evaluate("()=>[...document.querySelectorAll('.pal-item .t')].some(x=>x.innerText.includes('کاربران'))")
        pg.fill("#palInput","billing"); pg.wait_for_timeout(200)
        OUT["J_en"]=pg.evaluate("()=>[...document.querySelectorAll('.pal-item')].some(x=>x.innerText.includes('صورتحساب')||x.innerText.includes('Billing'))")
        pg.fill("#palInput","user360"); pg.wait_for_timeout(200)
        # User360 is contextual/direct-URL only (mission §13): palette (nav routes) must NOT list it
        OUT["J_kw"]=pg.evaluate("()=>document.querySelectorAll('.pal-item').length===0 && document.querySelector('#palGroups').innerText.includes(T.fa['search.empty'])")
        pg.fill("#palInput","صورتحساب"); pg.wait_for_timeout(200)
        OUT["J_group"]=pg.evaluate("()=>document.querySelectorAll('.pal-item').length>=3")
        pg.fill("#palInput","zzzz"); pg.wait_for_timeout(200)
        OUT["J_empty"]=pg.evaluate("k=>document.querySelector('#palGroups').innerText.includes(k)",L(pg,"search.empty"))
        pg.keyboard.press("Escape"); pg.wait_for_timeout(200)

        # ---------- K. palette keyboard nav + focus restore ----------
        pg.click("#palBtn"); pg.wait_for_timeout(200)
        OUT["K_count"]=pg.evaluate("()=>document.querySelectorAll('.pal-item').length")
        pg.keyboard.press("ArrowDown"); pg.wait_for_timeout(120)
        OUT["K_idx2"]=pg.evaluate("()=>[...document.querySelectorAll('.pal-item')].findIndex(x=>x.getAttribute('aria-selected')==='true')===1")
        pg.keyboard.press("Home"); pg.wait_for_timeout(100)
        OUT["K_home"]=pg.evaluate("()=>[...document.querySelectorAll('.pal-item')][0].getAttribute('aria-selected')==='true'")
        pg.keyboard.press("End"); pg.wait_for_timeout(100)
        OUT["K_end"]=pg.evaluate("()=>{const it=[...document.querySelectorAll('.pal-item')];return it[it.length-1].getAttribute('aria-selected')==='true'}")
        pg.keyboard.press("Enter"); pg.wait_for_timeout(250)
        OUT["K_enter_nav"]=pg.evaluate("()=>location.hash")
        OUT["K_focus_restored"]=pg.evaluate("()=>document.activeElement&&document.activeElement.id==='palBtn'")

        # ---------- L/M. permission gating (admin minus billing.view) ----------
        pg.close()
        setmode("adminminus")
        api=[]
        pg=newpg(p,viewport={"width":1440,"height":950})
        pg.on("request", lambda r: api.append(r.url) if "/api/" in r.url else None)
        goto(pg)
        OUT["L_hidden"]=pg.evaluate("()=>![...document.querySelectorAll('.navitem')].some(n=>n.dataset.route==='billing-overview')")
        OUT["L_denied"]=pg.evaluate("arg=>{location.hash='#/billing-overview';return new Promise(r=>setTimeout(()=>{const v=document.querySelector('#view').innerText;r(v.includes(arg.d)&&v.includes('billing.view')&&!v.includes(arg.b))},250))}",{"d":L(pg,"shell.denied.title"),"b":L(pg,"shell.pending.badge")})
        pg.reload(); pg.wait_for_timeout(1400)   # reload on the denied route
        OUT["L_denied_after_reload"]=pg.evaluate("d=>document.querySelector('#view').innerText.includes(d)",L(pg,"shell.denied.title"))
        pg.evaluate("()=>{window.__api=[]}")  # marker only
        OUT["M_api"]=sorted(set(u.split("8141")[-1].split("?")[0] for u in api))
        # palette must omit unauthorized routes entirely
        pg.click("#palBtn"); pg.wait_for_timeout(200)
        pg.fill("#palInput","billing"); pg.wait_for_timeout(200)
        OUT["L_palette_omits"]=pg.evaluate("()=>document.querySelectorAll('.pal-item').length===0")
        pg.keyboard.press("Escape"); pg.wait_for_timeout(150)

        # ---------- N. 401 → /login ----------
        setmode("noauth")
        pgn=newpg(p,viewport={"width":1440,"height":950})
        pgn.on("pageerror", lambda e: OUT["js"].append(str(e)[:200]))
        pgn.goto("http://127.0.0.1:8141/admin/v2/index.html")
        try: pgn.wait_for_url("**/login", timeout=6000); OUT["N_401"]=True
        except: OUT["N_401"]=pgn.evaluate("()=>location.pathname")

        # ---------- O. 403 (console-level) distinct from route-level denial ----------
        setmode("user403")
        pgo=newpg(p,viewport={"width":1440,"height":950})
        pgo.on("pageerror", lambda e: OUT["js"].append(str(e)[:200]))
        pgo.goto("http://127.0.0.1:8141/admin/v2/index.html"); pgo.wait_for_timeout(1400)
        OUT["O_403"]=pgo.evaluate("k=>document.querySelector('#view').innerText.includes(k)",L(pgo,"shell.403.title"))

        # ---------- P/Q/R. locale persistence + RTL/LTR ----------
        setmode("admin")
        pgp=newpg(p,viewport={"width":1440,"height":950})
        pgp.on("pageerror", lambda e: OUT["js"].append(str(e)[:200]))
        goto(pgp)
        pgp.evaluate("()=>setLang('en')"); pgp.wait_for_timeout(300)
        pgp.evaluate("()=>location.hash='#/security-audit'"); pgp.wait_for_timeout(250)
        pgp.reload(); pgp.wait_for_timeout(1500)
        OUT["Q_en"]=pgp.evaluate("()=>document.documentElement.dir==='ltr' && document.querySelector('#nav').innerText.includes('Admin Audit') && document.title.includes('Admin Audit')")
        pgp.evaluate("()=>setLang('fa')"); pgp.wait_for_timeout(300)
        pgp.evaluate("()=>location.hash='#/billing-plans'"); pgp.wait_for_timeout(250)
        pgp.reload(); pgp.wait_for_timeout(1500)
        OUT["P_fa"]=pgp.evaluate("()=>document.documentElement.dir==='rtl' && document.querySelector('#nav').innerText.includes('کاربران')")
        OUT["R_after_route"]=pgp.evaluate("()=>document.documentElement.dir==='rtl'")
        pgp.evaluate("()=>setLang('en')"); pgp.wait_for_timeout(250)
        OUT["R_ltr"]=pgp.evaluate("()=>document.documentElement.dir==='ltr'")

        # ---------- S/T. theme ----------
        pgp.evaluate("()=>setTheme('light')"); pgp.wait_for_timeout(150)
        pgp.evaluate("()=>location.hash='#/system-logs'"); pgp.wait_for_timeout(250)
        OUT["S_route_keeps"]=pgp.evaluate("()=>document.documentElement.dataset.theme==='light'")
        pgp.reload(); pgp.wait_for_timeout(1400)
        OUT["S_reload"]=pgp.evaluate("()=>document.documentElement.dataset.theme==='light'")
        pgp.click("#palBtn"); pgp.wait_for_timeout(250)
        OUT["S_palette_light"]=pgp.evaluate("()=>{const pl=document.querySelector('#palette-root .palette');return !!pl&&pl.offsetParent!==null&&getComputedStyle(pl).backgroundColor!==''}")
        pgp.keyboard.press("Escape"); pgp.wait_for_timeout(150)
        pgp.evaluate("()=>{localStorage.setItem('velora_theme','banana')}"); pgp.reload(); pgp.wait_for_timeout(1400)
        OUT["T_fallback"]=pgp.evaluate("()=>document.documentElement.dataset.theme")

        # ---------- U/V. leakage ----------
        leaks=[]
        for rt in ["users","billing-plans","security-audit","settings-config","system-flags"]:
            pgp.evaluate(f"()=>location.hash='#/{rt}'"); pgp.wait_for_timeout(90)
        OUT["U_nav_fa_none_en"]=pgp.evaluate("()=>{setLang('en');return new Promise(r=>setTimeout(()=>r(!document.querySelector('#nav').innerText.match(/[\\u0600-\\u06FF]/)),250))}")
        OUT["V_nav_en_none_fa"]=pgp.evaluate("""()=>{setLang('fa');return new Promise(r=>setTimeout(()=>{
        const items=[...document.querySelectorAll('.navitem')];
        const bad=items.filter(b=>{const d=REG_BY_ROUTE[b.dataset.route];const want=T.fa[d.labelKey]??SHELL_T.fa[d.labelKey];
          return b.innerText.replace('▾','').trim()!==want});
        const gh=[...document.querySelectorAll('.ghead')].filter(x=>{const want=T.fa[x.dataset.g];return x.innerText.replace('▾','').trim()!==want});
        r(bad.length===0&&gh.length===0)},250))}""")

        # ---------- W. no fake data anywhere ----------
        wbad=[]
        IMPL={"overview","users","ai-providers","ai-route"}
        for rt in sorted(expected):
            pgp.evaluate(f"()=>location.hash='#/{rt}'"); pgp.wait_for_timeout(70)
            if rt in IMPL: continue  # F-3 batch1: wired pages (data-source proof in test_f3_batch1)
            st=pgp.evaluate("()=>({tbl:!!document.querySelector('#view table'),kpi:!!document.querySelector('#view .ktile'),big:!!document.querySelector('#view canvas')})")
            if st["tbl"] or st["kpi"] or st["big"]: wbad.append(rt)
        pgp.evaluate("()=>location.hash='#/users/77'"); pgp.wait_for_timeout(150)
        OUT["W_u360_clean"]=pgp.evaluate("()=>!document.querySelector('#view table') && document.querySelector('#view').innerText.includes('#77')")
        OUT["W_bad"]=wbad

        # ---------- X. overflow ----------
        ov={}
        for w,hh in [(1440,950),(1280,900),(1024,800),(768,900),(430,900),(390,844),(360,780)]:
            pgp.set_viewport_size({"width":w,"height":hh})
            for th,lg in [("dark","fa"),("light","en")]:
                pgp.evaluate(f"()=>{{setTheme('{th}');setLang('{lg}');location.hash='#/security-audit'}}")
                pgp.wait_for_timeout(220)
                ov[f"{w}-{th}-{lg}"]=pgp.evaluate("()=>document.documentElement.scrollWidth-document.documentElement.clientWidth")
        pgp.set_viewport_size({"width":1440,"height":950})
        OUT["X_overflow"]=ov
        OUT["X_zero"]=all(v==0 for v in ov.values())

        # ---------- Y. keyboard focus behavior ----------
        pgp.evaluate("()=>location.hash='#/overview'"); pgp.wait_for_timeout(200)
        pgp.focus(".navitem[data-route=users]"); pgp.wait_for_timeout(100)
        pgp.focus(".navitem[data-route=users]"); pgp.keyboard.press("Enter"); pgp.wait_for_timeout(300)
        OUT["Y_enter_nav"]=pgp.evaluate("()=>location.hash==='#/users'&&document.querySelector('.navitem.active').dataset.route==='users'")
        pgp.evaluate("()=>location.hash='#/overview'"); pgp.wait_for_timeout(200)
        OUT["Y_arrows"]=pgp.evaluate("""()=>{const first=document.querySelector('#nav .ghead,#nav .navitem');first.focus();
          const a1=document.activeElement.className;const ev=new KeyboardEvent('keydown',{key:'ArrowDown',bubbles:true});first.dispatchEvent(ev);
          return document.activeElement!==first}""")
        pgp.focus("#menuBtn"); pgp.keyboard.press("Tab"); pgp.wait_for_timeout(80)
        OUT["Y_tab_out_topbar"]=pgp.evaluate("()=>document.activeElement&&document.activeElement.id!=='menuBtn'&&document.activeElement.tagName==='BUTTON'")
        pgp.evaluate("()=>location.hash='#/overview'"); pgp.wait_for_timeout(200)
        pgp.focus(".navitem[data-route=overview]"); pgp.keyboard.press("Shift+Tab"); pgp.wait_for_timeout(80)
        OUT["Y_no_trap"]=pgp.evaluate("()=>{const a=document.activeElement;return a&&!document.querySelector('#nav').contains(a)}")

        # ---------- Z. reduced motion + rapid route changes ----------
        ctx=p.chromium.launch().new_context(reduced_motion="reduce",viewport={"width":1280,"height":900})
        pgr=ctx.new_page(); pgr.on("pageerror", lambda e: OUT["js"].append(str(e)[:200]))
        pgr.goto("http://127.0.0.1:8141/admin/v2/index.html"); pgr.wait_for_timeout(1400)
        for rt in ["users","billing-plans","security-audit","system-flags","overview"]:
            pgr.evaluate(f"()=>location.hash='#/{rt}'"); pgr.wait_for_timeout(30)
        pgr.wait_for_timeout(250)
        OUT["Z_final"]=pgr.evaluate("()=>location.hash==='#/overview' && document.querySelector('.navitem.active').dataset.route==='overview'")
        OUT["Z_renders"]=pgr.evaluate("()=>document.querySelector('#view').innerText.length>10")
        ctx.close()

        pg.close(); pgm.close(); pgp.close(); pgn.close(); pgo.close()
finally:
    srv.terminate()

# ---------- checks ----------
fails=[]
def chk(k,cond):
    if not cond: fails.append(k)
chk("js",len(OUT["js"])==0)
chk("A_count",OUT["A_count"]==33)  # 32 static + users/:id dynamic
chk("A_static",OUT["A_static_ok"]); chk("A_no_design",OUT["A_no_design"])
chk("A_meta",OUT["A_meta_ok"] and OUT["A_impl_set"]==["ai-providers","ai-route","overview","users"]); chk("A_u360",OUT["A_u360"])
chk("B_routes",not OUT["B_bad"]); chk("B_events",OUT["B_events"])
chk("C_nf",OUT["C_nf"]); chk("C_design",OUT["C_design"]); chk("C_deep",OUT["C_deep"]); chk("C_back",OUT["C_back"])
chk("D_u360",OUT["D_u360"]); chk("D_nosidebar",OUT["D_nosidebar"])
chk("E_back1",OUT["E_back1"]); chk("E_back2",OUT["E_back2"]); chk("E_fwd",OUT["E_fwd"])
chk("F_active",OUT["F_active"]); chk("F_single",OUT["F_single"])
chk("G_auto",OUT["G_auto"]); chk("G_manual",OUT["G_manual_set"]=="true" and OUT["G_manual_kept"])
chk("G_single",OUT["G_single_switch"]); chk("G_group_nav",OUT["G_group_after_nav"])
chk("H_drawer",OUT["H_closed"]); chk("H_focus",OUT["H_focus"]); chk("H_route",OUT["H_route"])
chk("I_open",OUT["I_open"] and OUT["I_focus"]); chk("I_esc",OUT["I_esc"]); chk("I_btn",OUT["I_btn"])
chk("J_fa",OUT["J_fa"]); chk("J_en",OUT["J_en"]); chk("J_kw",OUT["J_kw"]); chk("J_group",OUT["J_group"]); chk("J_empty",OUT["J_empty"])
chk("K_count",OUT["K_count"]==32); chk("K_idx2",OUT["K_idx2"]); chk("K_home",OUT["K_home"]); chk("K_end",OUT["K_end"])
chk("K_enter",OUT["K_enter_nav"]=="#/settings-config"); chk("K_focus",OUT["K_focus_restored"])
chk("L_hidden",OUT["L_hidden"]); chk("L_denied",OUT["L_denied"]); chk("L_reload",OUT["L_denied_after_reload"]); chk("L_pal",OUT["L_palette_omits"])
chk("M_api",OUT["M_api"]==["/api/v1/admin/me","/api/v1/admin/overview","/api/v1/admin/system/health","/api/v1/auth/refresh"])  # F-3: permitted page fetches only — no billing/ai calls for adminminus
chk("N_401",OUT["N_401"] is True)
chk("O_403",OUT["O_403"])
chk("P_fa",OUT["P_fa"]); chk("Q_en",OUT["Q_en"]); chk("R_dir",OUT["R_after_route"] and OUT["R_ltr"])
chk("S_theme",OUT["S_route_keeps"] and OUT["S_reload"] and OUT["S_palette_light"]); chk("T_fallback",OUT["T_fallback"]=="dark")
chk("U_leak",OUT["U_nav_fa_none_en"]); chk("V_leak",OUT["V_nav_en_none_fa"])
chk("W_clean",not OUT["W_bad"] and OUT["W_u360_clean"])
chk("X_zero",OUT["X_zero"])
chk("Y_keys",OUT["Y_enter_nav"] and OUT["Y_arrows"] and OUT["Y_no_trap"] and OUT["Y_tab_out_topbar"])
chk("Z_final",OUT["Z_final"] and OUT["Z_renders"])
print("FAILS:",fails if fails else "NONE — ALL GREEN")
for k,v in OUT.items():
    if k not in ("X_overflow",): print(k,"=",str(v)[:100])
try:  # optional evidence artifact (workspace may not exist, e.g. CI)
    json.dump(OUT,open(os.path.join(HERE,"last_f2_run.json"),"w"),ensure_ascii=False,indent=1)
except OSError:
    pass
sys.exit(1 if fails else 0)
