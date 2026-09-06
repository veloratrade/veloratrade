"""Admin v2.2 shell-refinement regression suite.

Focused contracts for the owner-ordered shell refinement (brand identity,
header controls, account control, theme control, footer), asserted against a
served artifact with a stubbed data layer:

  R1  real Velora logo (site SVG asset + gradient defs), no letter "V" block
  R2  header shows NO hostname/URL/route — environment = real backend state
  R3  watermark = clean "VELORA ADMIN" (no build tag for users, "[object Object]"
      impossible), dev-only build tag from real provenance token
  R4  theme control: sun/moon SVG, localized accessible label, persistence,
      keyboard operation
  R5  account control: real /admin/me identity (initials/name/email/role),
      popover, real logout via /api/v1/auth/logout, keyboard + Escape/focus
  R6  no console errors, no overflow (fa/en × dark/light × 1440/390)
"""
import json, os, subprocess, sys, time, re
from playwright.sync_api import sync_playwright
HERE = os.path.dirname(os.path.abspath(__file__))
srv = subprocess.Popen(["python3", os.path.join(HERE, "f1_stub.py")],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1.2)
OUT = {"js": []}
def setmode(m):
    json.dump({"mode": m, "logout_called": False}, open(os.path.join(HERE, "mode.json"), "w"))
URL = "http://127.0.0.1:8141/admin/v2/index.html"
try:
    with sync_playwright() as p:
        b = p.chromium.launch()
        def newpg(w=1440, h=950):
            pg = b.new_page(viewport={"width": w, "height": h})
            pg.on("pageerror", lambda e: OUT["js"].append(str(e)[:140]))
            return pg
        # ---------- A) brand: real logo ----------
        setmode("admin")
        pg = newpg(); pg.goto(URL); pg.wait_for_timeout(1500)
        OUT["R1_svg_paths"] = pg.evaluate("()=>document.querySelectorAll('.brand .logo svg path, .brand .logo svg rect').length")
        OUT["R1_grad"] = pg.evaluate("()=>{const s=document.querySelector('.brand .logo svg'); return s? (s.outerHTML.match(/url\\(#vl-g[A-Z]\\)/g)||[]).length : 0}")
        OUT["R1_defs"] = pg.evaluate("()=>!!document.querySelector('#vl-gA')&&!!document.querySelector('#vl-gC')&&!!document.querySelector('#vl-gD')")
        OUT["R1_no_text_V"] = pg.evaluate("()=>document.querySelector('.brand .logo').textContent.trim()===''")
        OUT["R1_no_img"] = pg.evaluate("()=>document.querySelectorAll('.brand .logo img').length===0")
        pg.close()
        # ---------- B) header environment: no hostnames/URLs ----------
        pg = newpg(); pg.goto(URL); pg.wait_for_timeout(1500)
        OUT["R2_env"] = pg.evaluate("""()=>({host:document.querySelector('#envHost').textContent,
          sub:document.querySelector('#envSub').textContent, chip:document.querySelector('#envChipTxt').textContent})""")
        OUT["R2_demo"] = pg.evaluate("""()=>{setConn('demo');const o={host:document.querySelector('#envHost').textContent,
          chip:document.querySelector('#envChipTxt').textContent};setConn('ready');return o}""")
        OUT["R2_scan"] = pg.evaluate("""()=>{const bad=[];const rx=/(https?:\\/\\/|www\\.|veloratrade\\.ir|staging\\.|\\/admin\\/|\\/api\\/v1)/i;
          const walk=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);
          let n;while(n=walk.nextNode()){const t=(n.textContent||'').trim();if(!t)continue;
            const el=n.parentElement;let dev=false,skip=false,e=el;
            while(e){if(e.classList&&(e.classList.contains('devonly'))){dev=true;break}
              if(/^(SCRIPT|STYLE|NOSCRIPT)$/.test(e.tagName||'')){skip=true;break}
              e=e.parentElement}
            if(dev||skip)continue; if(rx.test(t))bad.push(t.slice(0,60));}return bad}""")
        OUT["R3_wm"] = pg.evaluate("()=>document.querySelector('#wmtext').textContent.replace(/\\s+/g,' ').trim()")
        OUT["R3_devonly_empty"] = pg.evaluate("()=>({sb:document.querySelector('#sbBuildId').textContent,wm:document.querySelector('#wmBuild').textContent})")
        OUT["R3_no_object"] = pg.evaluate("()=>document.body.innerText.includes('[object')")
        pg.close()
        # ---------- C) theme control ----------
        pg = newpg(); pg.goto(URL); pg.wait_for_timeout(1500)
        OUT["R4_initial_label"] = pg.evaluate("()=>document.querySelector('#themeBtn').getAttribute('aria-label')")
        OUT["R4_initial_icon"] = pg.evaluate("()=>({moon:document.querySelector('#vl-moon').style.display,sun:document.querySelector('#vl-sun').style.display})")
        pg.click("#themeBtn"); pg.wait_for_timeout(150)
        OUT["R4_light"] = pg.evaluate("()=>({th:document.documentElement.dataset.theme,moon:document.querySelector('#vl-moon').style.display,sun:document.querySelector('#vl-sun').style.display,label:document.querySelector('#themeBtn').getAttribute('aria-label')})")
        pg.reload(); pg.wait_for_timeout(1200)
        OUT["R4_persist"] = pg.evaluate("()=>document.documentElement.dataset.theme")
        pg.click("#themeBtn"); pg.wait_for_timeout(150)   # back to dark
        OUT["R4_dark_label"] = pg.evaluate("()=>document.querySelector('#themeBtn').getAttribute('aria-label')")
        pg.evaluate("()=>document.querySelector('#themeBtn').focus()")
        pg.keyboard.press("Enter"); pg.wait_for_timeout(150)
        OUT["R4_kbd"] = pg.evaluate("()=>document.documentElement.dataset.theme")
        pg.keyboard.press("Enter"); pg.wait_for_timeout(150)  # back to dark
        pg.click("#langBtn"); pg.wait_for_timeout(500)        # switch to EN
        OUT["R4_en_label"] = pg.evaluate("()=>document.querySelector('#themeBtn').getAttribute('aria-label')")
        pg.click("#langBtn"); pg.wait_for_timeout(400)        # back to FA
        pg.close()
        # ---------- D) account control + real logout ----------
        setmode("admin")
        pg = newpg(); pg.goto(URL); pg.wait_for_timeout(1500)
        OUT["R5_initials"] = pg.evaluate("()=>{const u=document.querySelector('#userInit'); return u?u.textContent:''}")
        OUT["R5_no_img_avatar"] = pg.evaluate("()=>document.querySelectorAll('#userBtn img').length===0")
        pg.click("#userBtn"); pg.wait_for_timeout(250)
        OUT["R5_menu_bounds"] = pg.evaluate("""()=>{const m=document.querySelector('#umenu'); if(!m)return null;
          const r=m.getBoundingClientRect();
          return {inView:r.left>=0&&r.top>=0&&r.right<=window.innerWidth&&r.bottom<=window.innerHeight, rect:[r.left,r.top,r.right,r.bottom].map(Math.round)} }""")
        OUT["R5_menu"] = pg.evaluate("""()=>{const m=document.querySelector('#umenu'); if(!m)return null;
          return {name:(m.querySelector('.uname')||{}).textContent, email:(m.querySelector('.uemail')||{}).textContent,
                  role:(m.querySelector('.urole')||{}).textContent, av:(m.querySelector('.uav')||{}).textContent,
                  noImg:m.querySelectorAll('img').length===0,
                  logoutTxt:((m.querySelector('#logoutBtn')||{}).textContent||'').trim(),
                  logoutSvg:!!(m.querySelector('#logoutBtn svg')),
                  menuRole:m.getAttribute('role'), expanded:document.querySelector('#userBtn').getAttribute('aria-expanded')} }""")
        pg.click("#logoutBtn"); pg.wait_for_timeout(800)
        OUT["R5_logout_path"] = pg.evaluate("()=>location.pathname")
        OUT["R5_logout_api"] = json.load(open(os.path.join(HERE, "mode.json")))["logout_called"]
        pg.close()
        # keyboard: open via Enter, focus lands in menu, Escape closes + restores focus
        pg = newpg(); pg.goto(URL); pg.wait_for_timeout(1500)
        pg.evaluate("()=>document.querySelector('#userBtn').focus()")
        pg.keyboard.press("Enter"); pg.wait_for_timeout(250)
        OUT["R5_kbd_open"] = pg.evaluate("()=>({menu:!!document.querySelector('#umenu'),focusInMenu:document.activeElement&&!!document.activeElement.closest('#umenu')})")
        pg.keyboard.press("Escape"); pg.wait_for_timeout(250)
        OUT["R5_kbd_escape"] = pg.evaluate("()=>({menuGone:!document.querySelector('#umenu'),focusBack:document.activeElement&&document.activeElement.id==='userBtn'})")
        pg.close()
        # different persona → different real initials
        setmode("limited")
        pg = newpg(); pg.goto(URL); pg.wait_for_timeout(1400)
        OUT["R5_limited_initials"] = pg.evaluate("()=>{const u=document.querySelector('#userInit'); return u?u.textContent:''}")
        pg.close()
        # ---------- E) release provenance (real token only) ----------
        setmode("super")
        pg = newpg()
        pg.add_init_script("window.__VELORA_RELEASE__='velora-shellref-TEST'")
        pg.goto(URL); pg.wait_for_timeout(1500)
        OUT["R3_release_tag"] = pg.evaluate("()=>({sb:document.querySelector('#sbBuildId').textContent,wm:document.querySelector('#wmBuild').textContent})")
        pg.click("#userBtn"); pg.wait_for_timeout(250)
        OUT["R3_status_row"] = pg.evaluate("()=>{const u=document.querySelector('#umenu .umeta'); return u?u.textContent.trim():null}")
        pg.close()
        # ---------- F) hygiene: console/overflow, fa+en × dark+light ----------
        setmode("admin")
        pg = newpg(); pg.goto(URL); pg.wait_for_timeout(1400)
        ovs = {}
        for th in ("dark", "light"):
            pg.evaluate(f"()=>setTheme('{th}')")
            for lg in ("fa", "en"):
                pg.evaluate(f"()=>setLang('{lg}')"); pg.wait_for_timeout(250)
                for w in (1440, 390):
                    pg.set_viewport_size({"width": w, "height": 900}); pg.wait_for_timeout(200)
                    ovs[f"{th}/{lg}/{w}"] = pg.evaluate("()=>document.documentElement.scrollWidth-document.documentElement.clientWidth")
        pg.set_viewport_size({"width": 1440, "height": 950})
        OUT["R6_overflow"] = ovs
        OUT["R6_all_zero"] = all(v == 0 for v in ovs.values())
        OUT["R6_no_object_final"] = pg.evaluate("()=>document.body.innerText.includes('[object')")
        pg.close()
        b.close()
finally:
    srv.terminate()
try:
    json.dump(OUT, open(os.path.join(HERE, "last_shell_run.json"), "w"), ensure_ascii=False, indent=1)
except OSError:
    pass
fails = []
def chk(k, cond):
    if not cond: fails.append(k)
chk("js", len(OUT["js"]) == 0)
chk("R1_logo_paths", OUT["R1_svg_paths"] >= 7)
chk("R1_grad_refs", OUT["R1_grad"] >= 3)
chk("R1_defs", OUT["R1_defs"] is True)
chk("R1_no_text_V", OUT["R1_no_text_V"] is True)
chk("R1_no_img", OUT["R1_no_img"] is True)
chk("R2_ready_env", OUT["R2_env"]["chip"] == "LIVE" and OUT["R2_env"]["host"] == "متصل به بک‌اند" and OUT["R2_env"]["sub"] == "زنده · پاسخ‌گوی واقعی")
chk("R2_demo_env", OUT["R2_demo"]["chip"] == "LOCAL" and OUT["R2_demo"]["host"] == "بدون لایهٔ داده")
chk("R2_no_urls", OUT["R2_scan"] == [])
chk("R3_wm_clean", OUT["R3_wm"] == "VELORA ADMIN")
chk("R3_devonly_empty", OUT["R3_devonly_empty"]["sb"] == "" and OUT["R3_devonly_empty"]["wm"] == "")
chk("R3_no_object", OUT["R3_no_object"] is False)
chk("R3_release_tag", OUT["R3_release_tag"]["sb"] == "velora-shellref-TEST" and OUT["R3_release_tag"]["wm"] == "velora-shellref-TEST")
chk("R3_release_popover", OUT["R3_status_row"] == "velora-shellref-TEST")  # release shown in the account popover from real provenance
chk("R4_theme_label_dark", OUT["R4_initial_label"] == "تم روشن")
chk("R4_theme_icon_dark", OUT["R4_initial_icon"]["moon"] == "block" and OUT["R4_initial_icon"]["sun"] == "none")
chk("R4_light_toggle", OUT["R4_light"]["th"] == "light" and OUT["R4_light"]["sun"] == "block" and OUT["R4_light"]["moon"] == "none" and OUT["R4_light"]["label"] == "تم تاریک")
chk("R4_persist", OUT["R4_persist"] == "light")
chk("R4_dark_label", OUT["R4_dark_label"] == "تم روشن")
chk("R4_kbd", OUT["R4_kbd"] == "light")
chk("R4_en_label", OUT["R4_en_label"] == "Light theme")  # label = the action the toggle performs (state was dark)
chk("R5_initials_real", OUT["R5_initials"] == "SR")
chk("R5_no_img_avatar", OUT["R5_no_img_avatar"] is True)
chk("R5_menu_identity", OUT["R5_menu"] and OUT["R5_menu"]["name"] == "Sahar Rahimi" and OUT["R5_menu"]["email"] == "s.rahimi@veloratrade.ir"
    and "admin" in (OUT["R5_menu"]["role"] or "") and "#4" in (OUT["R5_menu"]["role"] or "") and OUT["R5_menu"]["av"] == "SR")
chk("R5_menu_no_img", OUT["R5_menu"]["noImg"] is True)
chk("R5_logout_control", OUT["R5_menu"]["logoutTxt"] == "خروج از حساب" and OUT["R5_menu"]["logoutSvg"] is True)
chk("R5_menu_aria", OUT["R5_menu"]["menuRole"] == "menu" and OUT["R5_menu"]["expanded"] == "true")
chk("R5_logout_real", OUT["R5_logout_path"] == "/login" and OUT["R5_logout_api"] is True)
chk("R5_popover_in_view", OUT["R5_menu_bounds"] is not None and OUT["R5_menu_bounds"]["inView"] is True)  # direction-aware anchor (RTL bug fixed)
chk("R5_kbd_open", OUT["R5_kbd_open"]["menu"] is True and OUT["R5_kbd_open"]["focusInMenu"] is True)
chk("R5_kbd_escape", OUT["R5_kbd_escape"]["menuGone"] is True and OUT["R5_kbd_escape"]["focusBack"] is True)
chk("R5_limited_initials", OUT["R5_limited_initials"] == "NK")
chk("R6_overflow_zero", OUT["R6_all_zero"] is True)
chk("R6_no_object_final", OUT["R6_no_object_final"] is False)
print("FAILS:", fails if fails else "NONE — ALL GREEN")
for k, v in OUT.items():
    if k not in ("R6_overflow",): print(k, "=", str(v)[:110])
print("R6_all_zero:", OUT["R6_all_zero"])
sys.exit(1 if fails else 0)
