#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Focused suite — REAL BRAND + REAL PROFILE + EXIT ADMIN (owner-approved shell refinement).

Covers, against the stub backend (f1_stub.py, same-env conventions as the other suites):

  BR  Real logo: the Admin brand is the REAL veloratrade.ir logo — the seven SVG
      shapes byte-equal to the live site (only the gradient-id prefix differs, by
      design), exact stop colors (lgA/lgC/lgD ≡ vl-gA/vl-gC/vl-gD), the real
      wordmark pair (VELORA / TRADING OS), the real glass-tile treatment, rendered
      in dark AND light, FA AND EN, expanded AND rail mode, no <img>/emoji,
      no horizontal overflow at 1440/1280/1024/768/430/390/360.
  PR  Real profile: the account control + popover use the REAL dashboard treatment —
      42px-style gold gradient circle (#ffb703→#d4af37, #fff0bd border, glow),
      single letter = first char of the session user's fullName (VeloraData.getUser(),
      fallback V), real name/email identity, role line, no <img>, no placeholder
      persona, no hostnames/raw URLs/internal routes in user-visible text,
      popover fully usable at 430/390/360 (both themes).
  EX  Exit Admin: with the FULL network log captured from first navigation through
      landing on the dashboard, NO request to the auth-logout endpoint is ever made;
      Exit Admin navigates to the canonical localized dashboard (/fa/dashboard/),
      the session stays valid (returning to the Admin needs no fresh login), and the
      real logout flow (dashboard-side call to the logout endpoint) still works and
      the Admin then fails closed to /login.
"""
import json, os, re, subprocess, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
ART = os.path.join(HERE, "..", "index.html")

# ---- exact reference: the seven logo shapes as served by https://veloratrade.ir/ ----
# (geometry + paint verbatim from the live site; gradient refs normalized to the site ids)
SITE_SHAPES = [
    ("path", "M8 10L21 10L32 52L43 10L56 10L32 58Z", "none", "#e8c45a", ".7", "", 1.0),
    ("path", "M8 10L21 10L32 52L43 10L56 10L32 58Z", "url(#lgD)", "", "", "", 1.0),
    ("path", "M21 10L32 52L32 58L8 10Z", "url(#lgD)", "", "", ".55", 1.0),
    ("path", "M43 10L32 52L32 58L56 10Z", "url(#lgA)", "", "", "", 1.0),
    ("path", "M32 14V24M32 37V52", "none", "url(#lgC)", "2.6", "", 1.0),
    ("rect", "x=28.5 y=24 w=7 h=13", "url(#lgC)", "", "", "", 1.0),
    ("path", "M32 6.5L33.3 9.7L36.5 11L33.3 12.3L32 15.5L30.7 12.3L27.5 11L30.7 9.7Z", "#f7e3a1", "", "", "", 1.0),
]
SITE_STOPS = {
    "lgA": [[0, "#f9e6a8"], [.55, "#e8c45a"], [1, "#b8862a"]],
    "lgC": [[0, "#fdf3cd"], [1, "#d9a936"]],
    "lgD": [[0, "#9a741f"], [1, "#5f4510"]],
}

srv = subprocess.Popen(["python3", os.path.join(HERE, "f1_stub.py")],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1.2)
OUT = {"js": []}
BASE = "http://127.0.0.1:8141"


def setmode(m):
    json.dump({"mode": m, "logout_called": False}, open(os.path.join(HERE, "mode.json"), "w"))


def norm(grad_ref):
    return grad_ref.replace("vl-g", "lg") if isinstance(grad_ref, str) else grad_ref


fails = []
def chk(k, cond):
    if not cond: fails.append(k)

try:
    from playwright.sync_api import sync_playwright
    with sync_playwright() as p:
        b = p.chromium.launch()

        def newpg(w=1440, h=950):
            pg = b.new_page(viewport={"width": w, "height": h})
            pg.on("pageerror", lambda e: OUT["js"].append(str(e)[:120]))
            return pg

        # ================= BR — real logo =================
        setmode("admin")
        pg = newpg(); pg.goto(BASE + "/admin/v2/index.html"); pg.wait_for_timeout(1500)

        OUT["BR_shapes"] = pg.evaluate("""()=>{
          const svg=document.querySelector('.brand .logo-mark svg'); if(!svg)return null;
          const nr=v=>v==null?'':String(v).replace(/vl-g/g,'lg');
          return [...svg.children].map(el=>{
            const t=el.tagName.toLowerCase();
            if(t==='rect') return ['rect','x='+el.getAttribute('x')+' y='+el.getAttribute('y')
              +' w='+el.getAttribute('width')+' h='+el.getAttribute('height'),nr(el.getAttribute('fill')),'','','',1.0];
            return ['path',el.getAttribute('d'),nr(el.getAttribute('fill')||'none'),nr(el.getAttribute('stroke')||''),
              el.getAttribute('stroke-width')||'',el.getAttribute('opacity')||'',1.0];
          });
        }""")
        OUT["BR_stop_check"] = pg.evaluate("""()=>{
          const gs={};
          document.querySelectorAll('linearGradient[id^=vl-g]').forEach(g=>{
            gs[g.id.replace('vl-g','lg')]=[...g.querySelectorAll('stop')].map(s=>[
              parseFloat(s.getAttribute('offset')), s.getAttribute('stop-color')]);
          });
          return gs;
        }""")
        OUT["BR_texts"] = pg.evaluate("""()=>{
          const b=document.querySelector('.brand .logo-txt');
          const svg=document.querySelector('.brand .logo-mark svg');
          return {b:b?b.querySelector('b').textContent:'', i:b?b.querySelector('i').textContent:'',
                  viewBox:svg?svg.getAttribute('viewBox'):'', ariaHidden:svg?svg.getAttribute('aria-hidden'):'',
                  imgs:document.querySelectorAll('.brand img').length,
                  brandText:(document.querySelector('.brand')||{}).textContent||''};
        }""")
        OUT["BR_tile_dark"] = pg.evaluate("""()=>{
          const t=document.querySelector('.brand .logo-mark'); if(!t)return null;
          const cs=getComputedStyle(t), s=getComputedStyle(t.querySelector('svg'));
          const r=t.getBoundingClientRect();
          return {w:r.width,h:r.height, bg:cs.backgroundImage, border:cs.borderColor,
                  svgW:s.width, visible:!!t.offsetParent};
        }""")
        OUT["BR_overflow_dark"] = pg.evaluate("()=>document.documentElement.scrollWidth-document.documentElement.clientWidth")

        # light theme
        pg.click("#themeBtn"); pg.wait_for_timeout(250)
        OUT["BR_tile_light"] = pg.evaluate("""()=>{
          const t=document.querySelector('.brand .logo-mark'); if(!t)return null;
          const cs=getComputedStyle(t);
          return {bg:cs.backgroundImage, visible:!!t.offsetParent,
                  wordmarkColor:getComputedStyle(document.querySelector('.brand .logo-txt b')).color,
                  taglineGrad:getComputedStyle(document.querySelector('.brand .logo-txt i')).backgroundImage};
        }""")
        pg.click("#themeBtn"); pg.wait_for_timeout(200)

        # EN locale (brand stays latin like the real site)
        pg.click("#langBtn"); pg.wait_for_timeout(400)
        OUT["BR_texts_en"] = pg.evaluate("""()=>({b:document.querySelector('.brand .logo-txt b').textContent,
          i:document.querySelector('.brand .logo-txt i').textContent})""")
        pg.click("#langBtn"); pg.wait_for_timeout(300)

        # rail mode
        OUT["BR_rail"] = pg.evaluate("""()=>{
          document.querySelector('.app').classList.add('rail');
          const txt=document.querySelector('.brand .logo-txt'), mk=document.querySelector('.brand .logo-mark');
          const r1={txt:mk&&!!mk.offsetParent,
                    txtHidden:!txt||getComputedStyle(txt).display==='none'||txt.getBoundingClientRect().width<2};
          document.querySelector('.app').classList.remove('rail');
          return r1;
        }""")

        # overflow at all widths, both themes+langs (brand present everywhere)
        ov = {}
        for th, lg in [("dark", "fa"), ("light", "en")]:
            pg.evaluate(f"""()=>{{localStorage.setItem('velora_admin_theme','{th}');
              localStorage.setItem('velora_admin_lang','{lg}')}}""")
            pg.reload(); pg.wait_for_timeout(900)
            for w in [1440, 1280, 1024, 768, 430, 390, 360]:
                pg.set_viewport_size({"width": w, "height": 900}); pg.wait_for_timeout(200)
                ov[f"{th}/{lg}/{w}"] = pg.evaluate("()=>document.documentElement.scrollWidth-document.documentElement.clientWidth")
        OUT["BR_overflow_all"] = ov
        OUT["BR_overflow_all_zero"] = all(v == 0 for v in ov.values())
        pg.close()

        # ================= PR — real profile =================
        pg = newpg(); pg.goto(BASE + "/admin/v2/index.html"); pg.wait_for_timeout(1500)
        OUT["PR_btn"] = pg.evaluate("""()=>{
          const ub=document.querySelector('#userBtn'), ini=document.querySelector('#userInit');
          const cb=getComputedStyle(ub), ct=getComputedStyle(ini);   // treatment on the button, letter on the span
          return {letter:ini?ini.textContent.trim():'', imgs:document.querySelectorAll('#userBtn img').length,
                  grad:(cb.backgroundImage||'').includes('linear-gradient(135deg, rgb(255, 183, 3)'),
                  border:cb.borderColor, weight:ct.fontWeight, color:ct.color};
        }""")
        pg.click("#userBtn"); pg.wait_for_timeout(300)
        OUT["PR_pop"] = pg.evaluate("""()=>{
          const m=document.querySelector('#umenu'); if(!m)return null;
          return {name:(m.querySelector('.uname')||{}).textContent||'',
                  email:(m.querySelector('.uemail')||{}).textContent||'',
                  role:(m.querySelector('.urole')||{}).textContent||'',
                  av:(m.querySelector('.uav')||{}).textContent||'',
                  imgs:m.querySelectorAll('img').length,
                  exitTxt:((m.querySelector('#exitAdminBtn')||{}).textContent||'').trim(),
                  exitVisible:!!(m.querySelector('#exitAdminBtn')&&m.querySelector('#exitAdminBtn').offsetParent)};
        }""")
        OUT["PR_no_placeholder_persona"] = pg.evaluate("()=>!document.body.innerText.includes('ali.rezaei@velora.app')")
        OUT["PR_no_hostnames_paths"] = pg.evaluate("""()=>{
          const m=document.querySelector('#umenu'); if(!m)return false;
          const env=document.querySelector('#envChip')||{};
          const blob=[m.innerText,(env.textContent||'')].join('\\n');
          return !blob.includes('http://') && !blob.includes('https://') && !blob.includes('/admin/v2/')
              && !blob.includes('127.0.0.1') && !blob.includes('staging') && !blob.includes('localhost');
        }""")
        pg.close()

        # popover usable on small widths, both themes
        small = {}
        for th in ("dark", "light"):
            for w in (430, 390, 360):
                pg = newpg(w, 800)
                pg.goto(BASE + "/admin/v2/index.html"); pg.wait_for_timeout(1300)
                pg.evaluate(f"()=>localStorage.setItem('velora_admin_theme','{th}')")
                pg.reload(); pg.wait_for_timeout(1000)
                pg.click("#userBtn"); pg.wait_for_timeout(300)
                small[f"{th}/{w}"] = pg.evaluate("""()=>{
                  const m=document.querySelector('#umenu'); if(!m)return null;
                  const r=m.getBoundingClientRect(), eb=document.querySelector('#exitAdminBtn').getBoundingClientRect();
                  return {inView:r.left>=0&&r.top>=0&&r.right<=window.innerWidth&&r.bottom<=window.innerHeight,
                          exitH:eb.height, exitW:eb.width, usable:eb.width>=120&&eb.height>=28};
                }""")
                pg.close()
        OUT["PR_small"] = small

        # ================= EX — Exit Admin (network-proof) =================
        setmode("admin")
        ctx = b.new_context()
        ex = ctx.new_page()
        reqs = []
        ex.on("request", lambda r: reqs.append(r.url))
        ex.goto(BASE + "/admin/v2/index.html"); ex.wait_for_timeout(1500)
        ex.click("#userBtn"); ex.wait_for_timeout(300)
        ex.click("#exitAdminBtn")
        try: ex.wait_for_url("**/dashboard/", timeout=6000)
        except: pass
        ex.wait_for_timeout(500)
        OUT["EX_url"] = ex.evaluate("()=>location.pathname")
        OUT["EX_dashboard_page"] = ex.evaluate("()=>!!document.querySelector('#dashboardPage')")
        OUT["EX_logout_requests"] = [u for u in reqs if "auth/logout" in u]
        OUT["EX_total_requests"] = len(reqs)

        # session preserved: straight back into the Admin, no fresh login
        ex.goto(BASE + "/admin/v2/index.html"); ex.wait_for_timeout(1500)
        OUT["EX_back_conn"] = ex.evaluate("()=>document.querySelector('#v2mode').textContent")
        OUT["EX_back_path"] = ex.evaluate("()=>location.pathname")

        # real logout (dashboard-side semantics) still works → endpoint called
        setmode("admin")
        ex.evaluate("()=>fetch('/api/v1/auth/logout',{method:'POST'}).catch(()=>{})")
        ex.wait_for_timeout(400)
        OUT["EX_real_logout_called"] = json.load(open(os.path.join(HERE, "mode.json")))["logout_called"]
        # ...and the Admin fails closed once the session is really gone
        setmode("noauth")
        ex.goto(BASE + "/admin/v2/index.html")
        try: ex.wait_for_url("**/login", timeout=6000); OUT["EX_failclosed"] = True
        except: OUT["EX_failclosed"] = ex.evaluate("()=>location.pathname")
        ctx.close()
        b.close()
finally:
    srv.terminate()

try:
    json.dump(OUT, open(os.path.join(HERE, "last_run_brand_exit.json"), "w"), ensure_ascii=False, indent=1)
except OSError:
    pass

# ---------------- checks ----------------
chk("js", len(OUT["js"]) == 0)

# BR
chk("BR_shapes_exact", OUT["BR_shapes"] == [list(x) for x in SITE_SHAPES])  # byte-equal to veloratrade.ir (grad ids normalized)
chk("BR_stops_exact", OUT["BR_stop_check"] == SITE_STOPS)                   # lgA/lgC/lgD stop colors byte-equal
chk("BR_viewBox", OUT["BR_texts"]["viewBox"] == "0 0 64 64" and OUT["BR_texts"]["ariaHidden"] == "true")
chk("BR_wordmark", OUT["BR_texts"]["b"] == "VELORA" and OUT["BR_texts"]["i"] == "TRADING OS")
chk("BR_no_img_no_emoji", OUT["BR_texts"]["imgs"] == 0
    and not re.search(r"[\U0001F000-\U0001FAFF\u2600-\u27BF]", OUT["BR_texts"]["brandText"]))
chk("BR_tile_dark", OUT["BR_tile_dark"] and abs(OUT["BR_tile_dark"]["w"] - 40) < 1.5 and abs(OUT["BR_tile_dark"]["h"] - 40) < 1.5
    and "linear-gradient" in OUT["BR_tile_dark"]["bg"] and OUT["BR_tile_dark"]["visible"])
chk("BR_tile_svg26", OUT["BR_tile_dark"] and abs(float(OUT["BR_tile_dark"]["svgW"].replace("px", "")) - 26) < 1.5)
chk("BR_tile_light", OUT["BR_tile_light"] and "linear-gradient" in OUT["BR_tile_light"]["bg"]
    and OUT["BR_tile_light"]["visible"] and "linear-gradient" in OUT["BR_tile_light"]["taglineGrad"])
chk("BR_texts_en", OUT["BR_texts_en"]["b"] == "VELORA" and OUT["BR_texts_en"]["i"] == "TRADING OS")
chk("BR_rail", OUT["BR_rail"]["txt"] is True and OUT["BR_rail"]["txtHidden"] is True)
chk("BR_overflow_all", OUT["BR_overflow_all_zero"])

# PR
chk("PR_letter", OUT["PR_btn"]["letter"] == "S" and OUT["PR_btn"]["imgs"] == 0)   # first char of fullName (dashboard rule)
chk("PR_avatar_treatment", OUT["PR_btn"]["grad"] and OUT["PR_btn"]["border"] == "rgb(255, 240, 189)"
    and OUT["PR_btn"]["weight"] == "900" and OUT["PR_btn"]["color"] == "rgb(6, 10, 20)")  # real dashboard avatar: gold gradient, #fff0bd ring, dark 900 letter
chk("PR_identity", OUT["PR_pop"] and OUT["PR_pop"]["name"] == "Sahar Rahimi"
    and OUT["PR_pop"]["email"] == "s.rahimi@veloratrade.ir"
    and "admin" in OUT["PR_pop"]["role"] and OUT["PR_pop"]["av"] == "S" and OUT["PR_pop"]["imgs"] == 0)
chk("PR_exit_control", OUT["PR_pop"]["exitTxt"] == "خروج از پنل مدیریت" and OUT["PR_pop"]["exitVisible"])
chk("PR_no_placeholder_persona", OUT["PR_no_placeholder_persona"])
chk("PR_no_hostnames_paths", OUT["PR_no_hostnames_paths"])
chk("PR_small_usable", all(v and v["inView"] and v["usable"] for v in OUT["PR_small"].values()))

# EX
chk("EX_lands_dashboard", OUT["EX_url"].endswith("/dashboard/") and OUT["EX_dashboard_page"])
chk("EX_no_logout_requests", OUT["EX_logout_requests"] == [])      # network-proof across the whole session
chk("EX_session_kept", OUT["EX_back_conn"] == "متصل" and OUT["EX_back_path"].endswith("/admin/v2/index.html"))
chk("EX_real_logout_works", OUT["EX_real_logout_called"] is True)
chk("EX_admin_failclosed", OUT["EX_failclosed"] is True)

print("FAILS:", fails if fails else "NONE — ALL GREEN")
for k in ("BR_overflow_all_zero", "EX_url", "EX_logout_requests", "EX_total_requests",
          "EX_back_conn", "EX_real_logout_called", "EX_failclosed"):
    print(k, "=", str(OUT.get(k))[:90])
sys.exit(1 if fails else 0)
