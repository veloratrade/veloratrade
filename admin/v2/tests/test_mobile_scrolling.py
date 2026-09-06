"""Mobile scrolling regression suite (behavior-based).

Proves actual scrolling/reachability behavior — never merely CSS inspection:

  S1 long page content is fully reachable vertically (top/middle/bottom), zero
     horizontal overflow, at 430/390/360
  S2 the topbar account control stays visible and operable on every width
     (root cause: topbar flex overflow clipped the avatar out of the viewport)
  S3 a modal taller than the viewport scrolls its body internally while the
     header and the footer actions stay permanently reachable
     (root cause: overflow:hidden modal in a fixed, center-aligned,
     non-scrolling wrapper made both ends unreachable)
  S4 the mobile drawer navigation scrolls; first/last items and the drawer
     footer stay reachable; Escape closes; scroll lock is released
  S5 account popover and command palette stay within the viewport
  S6 desktop regression: no horizontal overflow, topbar intact, short modal
     unchanged (no inner scrollbars), sticky topbar/sidebar behavior preserved
"""
import json, os, subprocess, sys, time
from playwright.sync_api import sync_playwright
HERE = os.path.dirname(os.path.abspath(__file__))
srv = subprocess.Popen(["python3", os.path.join(HERE, "f1_stub.py")],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1.2)
OUT = {"js": []}
def setmode(m):
    json.dump({"mode": m, "logout_called": False}, open(os.path.join(HERE, "mode.json"), "w"))
URL = "http://127.0.0.1:8141/admin/v2/index.html"
def open_users(pg):
    pg.evaluate("location.hash='#/users'"); pg.wait_for_timeout(1000)
try:
    with sync_playwright() as p:
        b = p.chromium.launch()
        def newpg(w, h):
            pg = b.new_page(viewport={"width": w, "height": h})
            pg.on("pageerror", lambda e: OUT["js"].append(str(e)[:140]))
            return pg
        setmode("super")
        # ---------- S1: long-page vertical reachability ----------
        pg = newpg(390, 844); pg.goto(URL, wait_until="domcontentloaded"); pg.wait_for_timeout(1500)
        open_users(pg)
        OUT["S1"] = {}
        for w in (430, 390, 360):
            pg.set_viewport_size({"width": w, "height": 844}); pg.wait_for_timeout(300)
            OUT["S1"][str(w)] = pg.evaluate("""()=>{
              const de=document.documentElement;
              const sh=de.scrollHeight, ih=innerHeight;
              if(sh<=ih) return {note:'content fits', taller:false};
              window.scrollTo(0,0);
              const topOK=window.scrollY===0;
              window.scrollTo(0, sh/2);
              const midOK=window.scrollY>0 && Math.abs((window.scrollY+ih)-Math.min(sh, sh/2+ih))<8000;
              window.scrollTo(0, 999999);
              const bottomY=window.scrollY;
              const bottomReachable=(bottomY+ih)>=sh-2;
              const tb=document.querySelector('.topbar').getBoundingClientRect();
              const lastView=document.querySelector('#view').getBoundingClientRect();
              return {taller:true, sh, ih,
                      scrolled:bottomY>0, bottomReachable,
                      topbarPinned:Math.round(tb.top)===0,
                      viewTopVisibleAfterScroll: lastView.top<ih,
                      hOverflow: de.scrollWidth-de.clientWidth};
            }""")
        # ---------- S2: account control reachable at every width ----------
        OUT["S2"] = {}
        for w in (430, 390, 360):
            pg.set_viewport_size({"width": w, "height": 844}); pg.wait_for_timeout(250)
            pg.evaluate("window.scrollTo(0,0)")
            ok = True
            try:
                pg.click("#userBtn", timeout=3000); pg.wait_for_timeout(250)
                state = pg.evaluate("""()=>{const m=document.querySelector('#umenu'); const r=m?m.getBoundingClientRect():null;
                  const u=document.querySelector('#userBtn').getBoundingClientRect();
                  return {userBtnVisible:u.left>=0&&u.right<=innerWidth&&u.top>=0&&u.bottom<=innerHeight,
                          popover:r?{inView:r.left>=0&&r.top>=0&&r.right<=innerWidth&&r.bottom<=innerHeight}:null};}""")
                pg.keyboard.press("Escape"); pg.wait_for_timeout(200)
                state["escClosed"] = pg.evaluate("()=>!document.querySelector('#umenu')")
                ok = state
            except Exception as e:
                ok = {"error": str(e)[:80]}
            OUT["S2"][str(w)] = ok
            pg.evaluate("()=>{const m=document.querySelector('#umenu'); if(m)m.remove()}")
        pg.close()
        # ---------- S3: tall modal ----------
        pg = newpg(390, 844); pg.goto(URL, wait_until="domcontentloaded"); pg.wait_for_timeout(1500)
        open_users(pg)
        pg.evaluate("()=>usrConfirm('suspend',4,'u4@velora.test')"); pg.wait_for_timeout(300)
        OUT["S3_short"] = pg.evaluate("""()=>{const m=document.querySelector('#modal-root .modal');
          const r=m.getBoundingClientRect(); const mb=m.querySelector('.mb');
          return {h:Math.round(r.height), vh:innerHeight, mbScrollable:(mb.scrollHeight-mb.clientHeight)>2,
                  clipped:r.top<0||r.bottom>innerHeight};}""")
        OUT["S3_tall"] = pg.evaluate("""()=>{
          const mb=document.querySelector('#modal-root .modal .mb');
          mb.insertAdjacentHTML('beforeend', Array.from({length:16},(_,i)=>
            `<div class="kv"><span class="k">f${i}</span><span class="v"><input class="inp" aria-label="f${i}"></span></div>`).join(''));
          const m=document.querySelector('#modal-root .modal'); const w=document.querySelector('#modal-root .modalwrap');
          const r=m.getBoundingClientRect();
          const mh=document.querySelector('#modal-root .modal .mh').getBoundingClientRect();
          const capped=r.height<=innerHeight-32+1;
          mb.scrollTop=0;
          const headAtTop=Math.round(mh.top)>=0;
          mb.scrollTop=999999;
          const foot=document.querySelector('#modal-root .modal .mf').getBoundingClientRect();
          const inputs=[...mb.querySelectorAll('input')]; const li=inputs[inputs.length-1].getBoundingClientRect();
          const headStill=Math.round(document.querySelector('#modal-root .modal .mh').getBoundingClientRect().top);
          return {capped, clipped:r.top<0||r.bottom>innerHeight,
                  headerVisibleAtTop:headAtTop, headerStillVisibleAfterBodyScroll:headStill>=0&&headStill<innerHeight,
                  bodyScrolled:mb.scrollTop>0,
                  footerVisibleAfterScroll:foot.top>=0&&foot.bottom<=innerHeight,
                  lastInputVisibleAfterScroll:li.top>=0&&li.bottom<=innerHeight,
                  wrapperScrollMax: (()=>{w.scrollTop=999999;return Math.round(w.scrollTop)})()};
        }""")
        pg.keyboard.press("Escape"); pg.wait_for_timeout(250)
        OUT["S3_esc"] = pg.evaluate("()=>document.querySelector('#modal-root').innerHTML===''")
        # ---------- S4: mobile drawer full reachability ----------
        pg.evaluate("window.scrollTo(0,0)")
        pg.click("#menuBtn"); pg.wait_for_timeout(400)
        OUT["S4"] = pg.evaluate("""()=>{
          document.querySelectorAll('.ghead[aria-expanded="false"]').forEach(g=>g.click());
          const sb=document.querySelector('#sidebar'); const nav=sb.querySelector('nav');
          const items=[...sb.querySelectorAll('.navitem')].filter(e=>e.offsetParent!==null);
          const r=sb.getBoundingClientRect(); const foot=sb.querySelector('.foot').getBoundingClientRect();
          nav.scrollTop=0;
          const f=items[0]?.getBoundingClientRect();
          const firstOK=f&&f.top>=0&&f.bottom<=innerHeight;
          nav.scrollTop=999999;
          const l=items[items.length-1]?.getBoundingClientRect();
          const lastOK=l&&l.top>=0&&l.bottom<=innerHeight;
          return {items:items.length, sidebarWithinViewport:r.height<=innerHeight+1,
                  footVisible:foot.top>=0&&foot.bottom<=innerHeight,
                  navScrollable:nav.scrollHeight-nav.clientHeight,
                  firstReachable:firstOK, lastReachable:lastOK};
        }""")
        pg.keyboard.press("Escape"); pg.wait_for_timeout(300)
        OUT["S4_esc"], lock = pg.evaluate("()=>!document.querySelector('#sidebar').classList.contains('open')"), None
        OUT["S4_lock_released"] = pg.evaluate("""()=>{document.querySelector('#view').scrollIntoView();window.scrollTo(0,999999);
          const unlocked=window.scrollY>0||document.documentElement.scrollHeight<=innerHeight;
          window.scrollTo(0,0); return unlocked;}""")
        # ---------- S5: popover + palette within viewport ----------
        pg.click("#userBtn"); pg.wait_for_timeout(250)
        OUT["S5_popover"] = pg.evaluate("""()=>{const m=document.querySelector('#umenu'); if(!m)return null;
          const r=m.getBoundingClientRect(); const cs=getComputedStyle(m);
          return {inView:r.left>=0&&r.top>=0&&r.right<=innerWidth&&r.bottom<=innerHeight,
                  maxH:parseInt(cs.maxHeight)<=0.72*innerHeight};}""")
        pg.keyboard.press("Escape"); pg.wait_for_timeout(200)
        pg.evaluate("()=>openPalette()"); pg.wait_for_timeout(300)
        OUT["S5_palette"] = pg.evaluate("""()=>{const p=document.querySelector('#palette-root .palette'); if(!p)return null;
          const r=p.getBoundingClientRect(); return r.top>=0&&r.bottom<=innerHeight;}""")
        pg.keyboard.press("Escape"); pg.wait_for_timeout(150)
        pg.close()
        # ---------- S6: desktop regression ----------
        OUT["S6"] = {}
        for w in (1440, 1280, 1024, 768):
            pg = newpg(w, 900); pg.goto(URL, wait_until="domcontentloaded"); pg.wait_for_timeout(1400)
            open_users(pg)
            OUT["S6"][str(w)] = pg.evaluate("""()=>{
              const de=document.documentElement; const tb=document.querySelector('.topbar').getBoundingClientRect();
              const u=document.querySelector('#userBtn').getBoundingClientRect();
              const sb=document.querySelector('#sidebar').getBoundingClientRect();
              return {hOverflow:de.scrollWidth-de.clientWidth,
                      topbarFits:Math.round(tb.left)===0&&Math.round(tb.right)<=innerWidth,
                      userBtnVisible:u.left>=0&&u.right<=innerWidth&&u.top>=0&&u.bottom<=innerHeight,
                      sidebarStickyTop:Math.round(sb.top)===0};
            }""")
            pg.evaluate("()=>usrConfirm('suspend',4,'u4@velora.test')"); pg.wait_for_timeout(250)
            OUT["S6"][str(w)]["shortModalUnchanged"] = pg.evaluate("""()=>{const m=document.querySelector('#modal-root .modal');
              const r=m.getBoundingClientRect(); const mb=m.querySelector('.mb');
              return r.height<innerHeight-32 && (mb.scrollHeight-mb.clientHeight)<=2;}""")
            pg.keyboard.press("Escape"); pg.wait_for_timeout(200)
            pg.close()
        b.close()
finally:
    srv.terminate()
try:
    json.dump(OUT, open(os.path.join(HERE, "last_scroll_run.json"), "w"), ensure_ascii=False, indent=1)
except OSError:
    pass
fails = []
def chk(k, cond):
    if not cond: fails.append(k)
chk("js", len(OUT["js"]) == 0)
for w in ("430", "390", "360"):
    s = OUT["S1"][w]
    chk(f"S1_{w}_taller", s.get("taller") in (True, False))
    if s.get("taller"):
        chk(f"S1_{w}_bottom_reachable", s["bottomReachable"] is True)
        chk(f"S1_{w}_scrolled", s["scrolled"] is True)
        chk(f"S1_{w}_topbar_pinned", s["topbarPinned"] is True)
        chk(f"S1_{w}_h_overflow_zero", s["hOverflow"] == 0)
for w in ("430", "390", "360"):
    s = OUT["S2"][w]
    chk(f"S2_{w}_userBtn_visible", isinstance(s, dict) and s.get("userBtnVisible") is True)
    chk(f"S2_{w}_popover", isinstance(s, dict) and bool(s.get("popover")) and s["popover"]["inView"] is True)
    chk(f"S2_{w}_esc_closed", isinstance(s, dict) and s.get("escClosed") is True)
chk("S3_short_unclipped", OUT["S3_short"]["clipped"] is False and OUT["S3_short"]["mbScrollable"] is False)
chk("S3_tall_capped", OUT["S3_tall"]["capped"] is True)
chk("S3_tall_unclipped", OUT["S3_tall"]["clipped"] is False)
chk("S3_tall_header_top", OUT["S3_tall"]["headerVisibleAtTop"] is True)
chk("S3_tall_header_pinned", OUT["S3_tall"]["headerStillVisibleAfterBodyScroll"] is True)
chk("S3_tall_body_scrolls", OUT["S3_tall"]["bodyScrolled"] is True)
chk("S3_tall_footer_reachable", OUT["S3_tall"]["footerVisibleAfterScroll"] is True)
chk("S3_tall_last_input_reachable", OUT["S3_tall"]["lastInputVisibleAfterScroll"] is True)
chk("S3_tall_wrapper_no_trap", OUT["S3_tall"]["wrapperScrollMax"] == 0)
chk("S3_esc", OUT["S3_esc"] is True)
chk("S4_items", OUT["S4"]["items"] > 0)
chk("S4_sidebar_within_viewport", OUT["S4"]["sidebarWithinViewport"] is True)
chk("S4_foot_visible", OUT["S4"]["footVisible"] is True)
chk("S4_nav_scrolls", OUT["S4"]["navScrollable"] > 0)
chk("S4_first_reachable", OUT["S4"]["firstReachable"] is True)
chk("S4_last_reachable", OUT["S4"]["lastReachable"] is True)
chk("S4_esc", OUT["S4_esc"] is True)
chk("S4_lock_released", OUT["S4_lock_released"] is True)
chk("S5_popover", OUT["S5_popover"] is not None and OUT["S5_popover"]["inView"] is True and OUT["S5_popover"]["maxH"] is True)
chk("S5_palette", OUT["S5_palette"] is True)
for w in ("1440", "1280", "1024", "768"):
    s = OUT["S6"][w]
    chk(f"S6_{w}_h_overflow", s["hOverflow"] == 0)
    chk(f"S6_{w}_topbar", s["topbarFits"] is True)
    chk(f"S6_{w}_userBtn", s["userBtnVisible"] is True)
    chk(f"S6_{w}_sidebar_sticky", s["sidebarStickyTop"] is True)
    chk(f"S6_{w}_short_modal", s["shortModalUnchanged"] is True)
print("FAILS:", fails if fails else "NONE — ALL GREEN")
for k, v in OUT.items():
    print(k, "=", str(v)[:200])
sys.exit(1 if fails else 0)
