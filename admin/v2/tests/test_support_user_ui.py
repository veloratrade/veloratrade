#!/usr/bin/env python3
"""Phase 9A — user Support Center interactive UI suite (stubbed backend).

Exercises deliverable A end-to-end in a real browser against
localized/en/support/index.html (baked-EN artifact) + FA canonical:

  A render: rows, KPI labels, no catalog-key echo
  B KPI numbers (open/closed/unread)
  C status + unread badges on rows
  D empty states (open + closed lists)
  E create validation (empty form -> localized error, no request)
  F create success (recorded by stub, fields cleared)
  G create 422 (validation envelope -> visible error)
  H thread open (subject, status/waiting badges, unread chip, messages, labels)
  I reply send (recorded by stub, textarea cleared)
  J keyboard access (role=button + Enter opens thread)
  K reopen flow on closed ticket (composer hidden, recorded by stub)
  L deep link ?ticket=ID opens the thread directly
  M XSS-safe rendering of malicious subject/body (no script/img execution)
  N list load failure -> localized error box
  O FA canonical page: rtl + Persian + no key echo
  P widths 1440/768/390/360 no horizontal overflow

Run: python3 test_support_user_ui.py   (starts its own stub on 8141)
"""
import json, os, subprocess, sys, time
from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
MODE = os.path.join(HERE, "mode.json")
EN = "http://127.0.0.1:8141/localized/en/support/index.html"
FA = "http://127.0.0.1:8141/support/index.html"

OUT = {}
def chk(name, ok):
    OUT[name] = bool(ok)

def boot(**kw):
    json.dump(kw, open(MODE, "w"))

def mode_read():
    try:
        return json.load(open(MODE))
    except Exception:
        return {}

def rows_state(pg):
    return pg.evaluate("""()=>({
      openRows:[...document.querySelectorAll('#cmOpenList .cm-row')].map(e=>e.getAttribute('data-ticket')),
      closedRows:[...document.querySelectorAll('#cmClosedList .cm-row')].map(e=>e.getAttribute('data-ticket')),
      kpiOpen:(document.getElementById('cmKpiOpen')||{}).textContent,
      kpiClosed:(document.getElementById('cmKpiClosed')||{}).textContent,
      kpiUnread:(document.getElementById('cmKpiUnread')||{}).textContent,
      body:document.body.innerText})""")

def open_row(pg, tid):
    pg.click('#cmOpenList .cm-row[data-ticket="%s"], #cmClosedList .cm-row[data-ticket="%s"]' % (tid, tid))
    pg.wait_for_timeout(900)

try:
    srv = subprocess.Popen([sys.executable, os.path.join(HERE, "f1_stub.py")],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    time.sleep(1.5)
    with sync_playwright() as p:
        pg = p.chromium.launch().new_page(viewport={"width": 1280, "height": 900})

        # ===== A/B/C. render + KPIs + badges =====
        boot(mode="super", user_locale="en")
        pg.goto(EN); pg.wait_for_timeout(2200)
        st = rows_state(pg)
        chk("A_rows", st["openRows"] == ["1042", "1043"] and st["closedRows"] == ["1044"])
        chk("A_labels", all(k in st["body"] for k in ("Open tickets", "Unread", "Closed tickets")))
        chk("A_no_echo", "pages.support." not in st["body"])
        chk("B_kpis", (st["kpiOpen"], st["kpiClosed"], st["kpiUnread"]) == ("2", "1", "1"))
        chk("C_unread_badge", "1" in pg.inner_text('#cmOpenList .cm-row[data-ticket="1042"]'))
        chk("C_pending_badge", "Awaiting your reply" in pg.inner_text('#cmOpenList .cm-row[data-ticket="1043"]'))

        # ===== D. empty states =====
        boot(mode="super", sup_empty=True)
        pg.reload(); pg.wait_for_timeout(1500)
        st = rows_state(pg)
        chk("D_empty", "You have no open tickets yet" in st["body"] and "No closed tickets." in st["body"])

        # ===== E. create validation =====
        boot(mode="super", user_locale="en")
        pg.reload(); pg.wait_for_timeout(1800)
        pg.click("#cmSubmit"); pg.wait_for_timeout(400)
        err = pg.evaluate("()=>({v:(document.getElementById('cmFormErr')||{}).style.display,t:(document.getElementById('cmFormErr')||{}).textContent})")
        chk("E_validation", err["v"] == "block" and "Subject and message are required." in err["t"] and "sup_create_calls" not in mode_read())

        # ===== F. create success =====
        pg.fill("#cmSubject", "Hello")
        pg.fill("#cmBody", "World")
        pg.click("#cmSubmit"); pg.wait_for_timeout(1000)
        m = mode_read()
        chk("F_create_recorded", int(m.get("sup_create_calls", 0)) == 1 and (m.get("sup_create_body") or {}).get("subject") == "Hello")
        chk("F_fields_cleared", pg.evaluate("()=>document.getElementById('cmSubject').value===''&&document.getElementById('cmBody').value===''"))

        # ===== G. create 422 =====
        boot(mode="super", user_locale="en", sup_create_result="422")
        pg.reload(); pg.wait_for_timeout(1800)
        pg.fill("#cmSubject", "Hello")
        pg.fill("#cmBody", "World")
        pg.click("#cmSubmit"); pg.wait_for_timeout(900)
        g = pg.evaluate("()=>({v:(document.getElementById('cmFormErr')||{}).style.display,t:(document.getElementById('cmFormErr')||{}).textContent})")
        chk("G_422_visible", g["v"] == "block" and "Validation failed." in g["t"])

        # ===== H/I. thread + reply =====
        boot(mode="super", user_locale="en")
        pg.reload(); pg.wait_for_timeout(1800)
        open_row(pg, 1042)
        h = pg.evaluate("""()=>({
          thread:(document.getElementById('cmThreadCard')||{}).style.display,
          subj:(document.getElementById('cmThreadSubject')||{}).textContent,
          status:(document.getElementById('cmThreadStatus')||{}).innerText,
          wf:(document.getElementById('cmThreadWf')||{}).innerText,
          unread:(document.getElementById('cmThreadUnread')||{}).innerText,
          reopen:(document.getElementById('cmReopenBtn')||{}).style.display,
          composer:(document.getElementById('cmReplyWrap')||{}).style.display,
          msgs:(document.getElementById('cmMsgs')||{}).innerText})""")
        chk("H_thread_open", h["thread"] == "block" and "#1042" in h["subj"] and "MT5 account will not connect" in h["subj"])
        chk("H_badges", "Open" in h["status"] and "Awaiting support" in h["wf"] and "Unread" in h["unread"])
        chk("H_reopen_hidden_composer", h["reopen"] == "none" and h["composer"] == "grid")
        chk("H_messages", "E-404" in h["msgs"] and "reconnect your MT5 account" in h["msgs"] and "You" in h["msgs"] and "Support" in h["msgs"])
        pg.fill("#cmReply", "Thanks, trying now.")
        pg.click("#cmReplyBtn"); pg.wait_for_timeout(1100)
        m = mode_read()
        chk("I_reply_recorded", int(m.get("sup_reply_calls", 0)) == 1)
        chk("I_reply_cleared", pg.evaluate("()=>document.getElementById('cmReply').value===''"))

        # ===== J. keyboard access =====
        pg.keyboard.press("Escape")
        pg.evaluate("()=>window.cmShowList&&cmShowList()"); pg.wait_for_timeout(900)
        kb = pg.evaluate("""()=>{const r=document.querySelector('#cmOpenList .cm-row');
          if(!r)return false;r.focus();
          const ev=new KeyboardEvent('keydown',{key:'Enter',bubbles:true});r.dispatchEvent(ev);
          return r.getAttribute('role')==='button'&&r.getAttribute('tabindex')==='0';}""")
        pg.wait_for_timeout(800)
        chk("J_keyboard", kb and pg.evaluate("()=>document.getElementById('cmThreadCard').style.display==='block'"))
        pg.evaluate("()=>window.cmShowList&&cmShowList()"); pg.wait_for_timeout(700)

        # ===== K. reopen on closed ticket =====
        open_row(pg, 1044)
        k = pg.evaluate("""()=>({composer:(document.getElementById('cmReplyWrap')||{}).style.display,
          reopen:(document.getElementById('cmReopenBtn')||{}).style.display})""")
        chk("K_closed_ui", k["composer"] == "none" and k["reopen"] == "inline-block")
        pg.click("#cmReopenBtn"); pg.wait_for_timeout(1000)
        chk("K_reopen_recorded", int(mode_read().get("sup_reopen_calls", 0)) == 1)
        pg.evaluate("()=>window.cmShowList&&cmShowList()"); pg.wait_for_timeout(700)

        # ===== L. deep link =====
        pg.goto(EN + "?ticket=1043"); pg.wait_for_timeout(2000)
        chk("L_deeplink", pg.evaluate("()=>document.getElementById('cmThreadCard').style.display==='block'&&document.getElementById('cmThreadSubject').textContent.includes('#1043')"))

        # ===== M. XSS-safe rendering =====
        boot(mode="super", sup_xss=True, user_locale="en")
        pg.goto(EN); pg.wait_for_timeout(1800)
        pg.evaluate("()=>window.openTicket&&openTicket(1045)"); pg.wait_for_timeout(900)
        mx = pg.evaluate("""()=>({pwned:window.__pwned===undefined,
          subj:document.getElementById('cmThreadSubject').textContent,
          imgs:document.getElementById('cmThreadSubject').querySelectorAll('img').length,
          scriptExec:window.__pwned2===undefined})""")
        chk("M_no_xss", mx["pwned"] and mx["scriptExec"] and mx["imgs"] == 0 and "<img" in mx["subj"])

        # ===== N. list load failure =====
        boot(mode="super", sup_fail=True)
        pg.reload(); pg.wait_for_timeout(1500)
        n = pg.evaluate("()=>({v:(document.getElementById('cmListErr')||{}).style.display,t:(document.getElementById('cmListErr')||{}).textContent})")
        chk("N_fail_localized", n["v"] == "block" and "Load failed; please retry." in n["t"])

        # ===== O. FA canonical page =====
        boot(mode="super")
        pg.goto(FA); pg.wait_for_timeout(2200)
        o = pg.evaluate("""()=>({dir:document.documentElement.dir,
          btn:(document.getElementById('cmReplyBtn')||{}).textContent,
          body:document.body.innerText})""")
        chk("O_fa_rtl", o["dir"] == "rtl" and "ارسال پاسخ" in o["btn"] and "تیکت جدید" in o["body"] and "pages.support." not in o["body"])
        pg.close()

        # ===== P. widths on EN artifact =====
        pg2 = p.chromium.launch().new_page(viewport={"width": 1440, "height": 950})
        pg2.goto(EN); pg2.wait_for_timeout(1600)
        ok_w = True
        for w in (1440, 768, 390, 360):
            pg2.set_viewport_size({"width": w, "height": 950}); pg2.wait_for_timeout(450)
            ov = pg2.evaluate("()=>document.documentElement.scrollWidth-document.documentElement.clientWidth")
            if ov > 1: ok_w = False
        chk("P_widths", ok_w)
        pg2.close()
finally:
    try:
        srv.terminate()
        srv.wait(timeout=5)
    except Exception:
        try: srv.kill()
        except Exception: pass
    for f in ("mode.json",):
        try: os.remove(os.path.join(HERE, f))
        except Exception: pass

fails = [k for k, v in OUT.items() if not v]
print("SUPPORT USER UI:", "ALL GREEN —", len(OUT), "checks" if not fails else "")
if fails:
    print("FAILS:", fails)
sys.exit(1 if fails else 0)
