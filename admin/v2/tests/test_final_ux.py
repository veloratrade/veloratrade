#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Focused suite — FINAL SHELL/UX fixes (owner-approved corrections on the frozen design).

  UA  User Actions modal (Phase C): the Users list exposes exactly ONE
      "Actions" trigger per row (aria-haspopup=dialog, no inline action
      cluster); the modal lists ONLY the permitted, genuinely wired actions
      (verify / suspend|activate / change role / plan / revoke sessions) with
      the user's real identity; focus lands on the first action; Escape
      closes; an entry opens the SAME frozen confirm/form modal as before
      (same endpoints, same permissions); permission-limited admins see only
      their subset; admins with no action permissions keep the column hidden;
      a permitted-but-inapplicable case renders the honest "no actions"
      state (never an empty modal). Mobile: modal usable at 430/390/360 and
      the PR #123 dvh/scroll modal behavior is untouched.
  IU  Static-chrome localization: EN mode shows NO hard-coded Persian in the
      static chrome (skip link "Skip to content", conn chip "Connected",
      dev-mode "Developer mode", localized aria-labels for menu/user/lang/
      rail buttons, env tooltip) and FA mode restores Persian; both
      directions verified (no EN-only leftovers in FA either).
  XR  PR #124 regression spot-checks: Exit Admin control still present in
      the popover, no logout control, brand wordmark intact.
"""
import json, os, re, subprocess, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
BASE = "http://127.0.0.1:8141"

srv = subprocess.Popen(["python3", os.path.join(HERE, "f1_stub.py")],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1.2)
OUT = {"js": []}


def setmode(m):
    json.dump({"mode": m, "logout_called": False}, open(os.path.join(HERE, "mode.json"), "w"))


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

        # ================= UA — actions modal =================
        setmode("super")
        pg = newpg(); pg.goto(BASE + "/admin/v2/index.html"); pg.wait_for_timeout(1600)
        pg.evaluate("()=>location.hash='#/users'"); pg.wait_for_timeout(800)

        OUT["UA_row"] = pg.evaluate("""()=>{
          const tds=[...document.querySelector('#view tbody tr').querySelectorAll('td')];
          const last=tds[tds.length-1];
          const btns=[...last.querySelectorAll('button')];
          return {count:btns.length, label:btns[0]?btns[0].textContent.trim():'',
                  haspopup:btns[0]?btns[0].getAttribute('aria-haspopup'):null};
        }""")
        pg.evaluate("()=>usrActions(2)"); pg.wait_for_timeout(300)   # user 2: unverified, active, plan=pro
        OUT["UA_modal"] = pg.evaluate("""()=>{
          const m=document.querySelector('#modal-root .modal'); if(!m)return null;
          return {title:m.querySelector('.mh').textContent.trim(),
                  who:m.querySelector('.mb div').textContent.trim(),
                  entries:[...m.querySelectorAll('.mb .btn')].map(b=>b.textContent.trim()),
                  focusIn:document.activeElement===m.querySelector('.mb .btn'),
                  ariaModal:m.getAttribute('aria-modal')};
        }""")
        pg.keyboard.press("Escape"); pg.wait_for_timeout(200)
        OUT["UA_escape"] = pg.evaluate("()=>!document.querySelector('#modal-root .modal')")
        # entry click-through opens the SAME frozen role modal (wired action)
        pg.evaluate("()=>usrActions(3)"); pg.wait_for_timeout(250)
        pg.evaluate("""()=>{const bs=[...document.querySelectorAll('#modal-root .mb .btn')];
          const t=bs.find(x=>x.textContent.includes('نقش')); if(t)t.click();}"""); pg.wait_for_timeout(250)
        OUT["UA_role_wired"] = pg.evaluate("""()=>{
          const sel=document.querySelector('#f3roleSel');
          const dlg=document.querySelector('#modal-root .modal');
          return !!sel && !!dlg && dlg.getAttribute('role')==='dialog';
        }""")
        pg.keyboard.press("Escape"); pg.wait_for_timeout(150)

        # limited admin: no action permissions -> actions column hidden entirely
        setmode("limited")
        pg.reload(); pg.wait_for_timeout(1500)
        pg.evaluate("()=>location.hash='#/users'"); pg.wait_for_timeout(700)
        OUT["UA_limited_hidden"] = pg.evaluate("""()=>[...document.querySelectorAll('#view thead th')]
          .every(th=>!th.textContent.includes('اقدامات') && !th.textContent.includes('Actions'))""")

        # honest empty state: verify-only permission + already-verified user
        pg.evaluate("""()=>{ME.perms=new Set(['users.view','users.verify_email']);
          ME.ok=true;render();location.hash='#/users';}"""); pg.wait_for_timeout(800)
        pg.evaluate("()=>usrActions(4)"); pg.wait_for_timeout(250)   # user 4 is verified
        OUT["UA_honest_empty"] = pg.evaluate("""()=>{const m=document.querySelector('#modal-root .modal');
          return m?m.querySelector('.mb').innerText.includes('اقدامی برای این کاربر'):false}""")
        pg.keyboard.press("Escape"); pg.wait_for_timeout(150)

        # mobile: actions modal usable at 430/390/360 (in-view, scrollable body, PR#123 dvh rules intact)
        small = {}
        for w in (430, 390, 360):
            pg.set_viewport_size({"width": w, "height": 800}); pg.wait_for_timeout(250)
            pg.evaluate("()=>usrActions(2)"); pg.wait_for_timeout(300)
            small[str(w)] = pg.evaluate("""()=>{
              const w=document.querySelector('#modal-root .modalwrap'), m=document.querySelector('#modal-root .modal');
              const r=m.getBoundingClientRect(), cs=getComputedStyle(w);
              const mb=getComputedStyle(m.querySelector('.mb'));
              return {inView:r.left>=0&&r.right<=window.innerWidth&&r.top>=0,
                      wrapScroll:cs.overflowY==='auto'||cs.overflowY==='scroll',
                      mbScroll:mb.overflowY==='auto'||mb.overflowY==='scroll',
                      overflow:document.documentElement.scrollWidth-document.documentElement.clientWidth};
            }""")
            pg.keyboard.press("Escape"); pg.wait_for_timeout(150)
        OUT["UA_small"] = small
        pg.close()

        # ================= IU — static-chrome localization =================
        setmode("super")
        pg = newpg(); pg.goto(BASE + "/admin/v2/index.html"); pg.wait_for_timeout(1600)
        pg.click("#langBtn"); pg.wait_for_timeout(500)               # -> EN
        OUT["IU_en"] = pg.evaluate("""()=>({
          skip:document.querySelector('.skip').textContent.trim(),
          chip:document.querySelector('#v2mode').textContent.trim(),
          dev:(document.querySelector('[data-i18n="shell.dev.label"]')||{}).textContent||'',
          menuBtn:document.querySelector('#menuBtn').getAttribute('aria-label'),
          userBtn:document.querySelector('#userBtn').getAttribute('aria-label'),
          langBtn:document.querySelector('#langBtn').getAttribute('aria-label'),
          railBtn:document.querySelector('#railBtn').getAttribute('aria-label'),
          envTitle:document.querySelector('#envChip').getAttribute('title')})""")
        pg.click("#langBtn"); pg.wait_for_timeout(400)               # -> FA again
        OUT["IU_fa"] = pg.evaluate("""()=>({
          skip:document.querySelector('.skip').textContent.trim(),
          chip:document.querySelector('#v2mode').textContent.trim(),
          dev:(document.querySelector('[data-i18n="shell.dev.label"]')||{}).textContent||'',
          menuBtn:document.querySelector('#menuBtn').getAttribute('aria-label'),
          userBtn:document.querySelector('#userBtn').getAttribute('aria-label')})""")
        pg.close()

        # ================= XR — PR #124 regression spot-checks =================
        pg = newpg(); pg.goto(BASE + "/admin/v2/index.html"); pg.wait_for_timeout(1600)
        OUT["XR"] = pg.evaluate("""()=>({
          wordmark:document.querySelector('.brand .logo-txt b').textContent==='VELORA'
              && document.querySelector('.brand .logo-txt i').textContent==='TRADING OS',
          exitBtn:!!document.querySelector('#exitAdminBtn') || (()=>{  // inside popover: open it
            return false})(),
          noLogoutMarkup:!document.body.innerHTML.includes('id="logoutBtn"')});
        """)
        pg.click("#userBtn"); pg.wait_for_timeout(300)
        OUT["XR_menu"] = pg.evaluate("""()=>({exit:!!document.querySelector('#exitAdminBtn'),
          logout:!!document.querySelector('#logoutBtn'),
          av:(document.querySelector('.uav')||{}).textContent})""")
        pg.close()
        b.close()
finally:
    srv.terminate()

try:
    json.dump(OUT, open(os.path.join(HERE, "last_run_final_ux.json"), "w"), ensure_ascii=False, indent=1)
except OSError:
    pass

# ---------------- checks ----------------
chk("js", len(OUT["js"]) == 0)
chk("UA_row_one_trigger", OUT["UA_row"]["count"] == 1 and OUT["UA_row"]["label"] == "اقدامات"
    and OUT["UA_row"]["haspopup"] == "dialog")
chk("UA_modal_entries", OUT["UA_modal"] and OUT["UA_modal"]["title"] == "اقدامات"
    and OUT["UA_modal"]["who"] == "Sara Ahmadi"
    and OUT["UA_modal"]["entries"] == ["تأیید ایمیل", "مسدودسازی", "تغییر نقش", "پلن و اشتراک", "لغو نشست‌ها"]
    and OUT["UA_modal"]["ariaModal"] == "true")
chk("UA_focus", OUT["UA_modal"] and OUT["UA_modal"]["focusIn"])
chk("UA_escape", OUT["UA_escape"])
chk("UA_role_wired", OUT["UA_role_wired"])          # same frozen confirm modal as before
chk("UA_limited_hidden", OUT["UA_limited_hidden"])  # no action perms -> no column
chk("UA_honest_empty", OUT["UA_honest_empty"])      # permitted-but-inapplicable -> honest note, never empty
chk("UA_small_usable", all(v["inView"] and v["wrapScroll"] and v["mbScroll"] and v["overflow"] == 0
                           for v in OUT["UA_small"].values()))
chk("IU_en", OUT["IU_en"]["skip"] == "Skip to content" and OUT["IU_en"]["chip"] == "Connected"
    and OUT["IU_en"]["dev"] == "Developer mode" and OUT["IU_en"]["menuBtn"] == "Open navigation menu"
    and OUT["IU_en"]["userBtn"] == "Account menu" and OUT["IU_en"]["langBtn"] == "Language FA/EN"
    and OUT["IU_en"]["railBtn"] == "Compact sidebar mode" and OUT["IU_en"]["envTitle"] == "Environment")
chk("IU_fa", OUT["IU_fa"]["skip"] == "پرش به محتوا" and OUT["IU_fa"]["chip"] == "متصل"
    and OUT["IU_fa"]["dev"] == "حالت توسعه‌دهنده" and OUT["IU_fa"]["menuBtn"] == "باز کردن منوی ناوبری"
    and OUT["IU_fa"]["userBtn"] == "منوی کاربر")
chk("XR_brand", OUT["XR"]["wordmark"] and OUT["XR"]["noLogoutMarkup"])
chk("XR_menu", OUT["XR_menu"]["exit"] and not OUT["XR_menu"]["logout"] and OUT["XR_menu"]["av"] == "A")

print("FAILS:", fails if fails else "NONE — ALL GREEN")
print("UA_row =", OUT["UA_row"])
print("UA_small =", {k: (v["inView"], v["overflow"]) for k, v in OUT["UA_small"].items()})
sys.exit(1 if fails else 0)
