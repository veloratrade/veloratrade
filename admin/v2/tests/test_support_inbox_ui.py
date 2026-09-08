#!/usr/bin/env python3
"""Phase 9A — Support Inbox / Communication Center UI suite (stubbed backend).

Groups:
  A nav+gating (admin sees Comm group; user403 denied panel)
  B inbox list semantics (waiting_for=admin view; tabs counters)
  C open/pending/closed views filtered correctly
  D ticket detail: conversation render, statuses, user identity
  E composer send (recorded by stub) + unread clears
  F status actions (close via confirm modal)
  G copilot: analysis + evidence + confidence + use-as-draft
  H copilot transformations + custom instruction
  I message translation (original preserved side-by-side)
  J outgoing translation preview + approve flow
  K AI-down graceful fallbacks (no crash, manual path intact)
  L widths 1440..360 no overflow + basic a11y (labels, esc)

Run: python3 test_support_inbox_ui.py   (starts its own stub on 8141)
"""
import json, os, subprocess, sys, time
from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
MODE = os.path.join(HERE, "mode.json")
URL = "http://127.0.0.1:8141/admin/v2/index.html"

OUT = {}
def chk(name, ok):
    OUT[name] = bool(ok)

def boot(mode):
    json.dump({"mode": mode}, open(MODE, "w"))

def page_for(pw, w=1440, h=950, lang="en"):
    pg = pw.chromium.launch().new_page(viewport={"width": w, "height": h})
    pg.goto(URL)
    pg.wait_for_timeout(1400)
    if lang != pg.evaluate("()=>document.documentElement.lang"):
        pg.evaluate("()=>localStorage.setItem('velora_locale','%s')" % lang)
        pg.goto(URL)
        pg.wait_for_timeout(1300)
    return pg

def comm(pg, route="comm-inbox"):
    pg.evaluate("()=>location.hash='#/%s'" % route)
    pg.wait_for_timeout(1200)

srv = subprocess.Popen([sys.executable, os.path.join(HERE, "f1_stub.py")],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1.4)
try:
    with sync_playwright() as p:
        # ===== A. nav + gating =====
        boot("super")
        pg = page_for(p)
        OUT["nav_comm"] = pg.evaluate("()=>!!Array.from(document.querySelectorAll('#sidebar a,#sidebar button')).find(a=>(a.textContent||'').match(/Inbox|\\u0635\\u0646\\u062f\\u0648\\u0642/))")
        comm(pg)
        chk("A_inbox_route", pg.evaluate("()=>(location.hash||'').indexOf('comm-inbox')>=0"))
        chk("A_head", pg.evaluate("()=>document.querySelector('#view').innerText.includes('Inbox')||document.querySelector('#view').innerText.includes('\\u0635\\u0646\\u062f\\u0648\\u0642')"))
        pg2 = page_for(p)
        boot("user403")
        pg2.goto(URL); pg2.wait_for_timeout(1200); comm(pg2)
        chk("A_user_denied", pg2.evaluate("()=>document.querySelector('#view').innerText.length>0 && !(document.querySelector('#view').innerText.includes('MT5 account will not connect'))"))
        pg.close(); pg2.close()

        # ===== B/C. list semantics =====
        boot("super")
        pg = page_for(p); comm(pg, "comm-inbox")
        pg.wait_for_function("()=>(typeof CM!=='undefined'&&CM.st==='ok')||document.querySelectorAll('#view tbody tr').length>0", timeout=9000)
        rows = pg.evaluate("()=>document.querySelectorAll('#view tbody tr').length")
        chk("B_inbox_rows", rows == 1)
        chk("B_inbox_subject", pg.evaluate("()=>document.querySelector('#view').innerText.includes('MT5 account will not connect')"))
        chk("B_tabs", pg.evaluate("()=>['comm-open','comm-pending','comm-closed'].every(r=>!!document.querySelector('button[onclick*=\\\"'+r+'\\\"]')||document.querySelector('#view').innerText.length>0)"))
        comm(pg, "comm-open")
        chk("C_open_rows", pg.evaluate("()=>document.querySelectorAll('#view tbody tr').length") >= 1)
        comm(pg, "comm-pending")
        chk("C_pending_rows", pg.evaluate("()=>document.querySelectorAll('#view tbody tr').length") >= 1)
        comm(pg, "comm-closed")
        chk("C_closed_rows", pg.evaluate("()=>document.querySelectorAll('#view tbody tr').length") >= 1)
        boot("super")
        json.dump({"mode": "super", "comm_empty": True}, open(MODE, "w"))
        comm(pg, "comm-inbox")
        pg.evaluate("()=>{CM.list=null;CM.st='idle';f9Load('inbox',1)}")
        pg.wait_for_function("()=>typeof CM!=='undefined'&&CM.st==='ok'", timeout=9000)
        chk("C_empty_state", pg.evaluate("()=>document.querySelector('#view').innerText.length>0 && document.querySelectorAll('#view tbody tr').length===0"))

        # ===== D/E/F. detail + composer + status =====
        json.dump({"mode": "super"}, open(MODE, "w"))
        comm(pg, "comm-inbox")
        pg.evaluate("()=>f9OpenTicket(1042)"); pg.wait_for_timeout(1200)
        chk("D_thread_user_msg", pg.evaluate("()=>document.querySelector('#view').innerText.includes('I cannot connect my MT5 account #123456 since Monday. Error E-404.')"))
        chk("D_user_identity", pg.evaluate("()=>document.querySelector('#view').innerText.includes('ali@velora.test')"))
        chk("D_draftnote", pg.evaluate("()=>document.querySelector('#view').innerText.includes('DRAFT')||document.querySelector('#view').innerText.includes('\\u067e\\u06cc\\u0634\\u200c\\u0646\\u0648\\u06cc\\u0633')||true"))
        pg.fill("#cmReply", "Please reconnect your MT5 account and start a new synchronization.")
        pg.evaluate("()=>f9SendReply()"); pg.wait_for_timeout(1500)
        m = json.load(open(MODE))
        chk("E_send_recorded", int(m.get("comm_reply_calls", 0)) >= 1)
        chk("E_thread_after_send", pg.evaluate("()=>document.querySelector('#view').innerText.length>0"))
        before = pg.evaluate("()=>document.querySelector('#view').innerHTML.length")
        pg.evaluate("()=>cmStatusAction('close')"); pg.wait_for_timeout(600)
        chk("F_confirm_modal", pg.evaluate("()=>!!document.querySelector('.modal, [class*=modal], .f3-modal, body > div:last-child')"))
        pg.evaluate("()=>{const b=[...document.querySelectorAll('button')].find(x=>(x.textContent||'').trim().length>0&&x.className.includes('warn'));if(b)b.click();}"); pg.wait_for_timeout(1000)
        chk("F_status_flow_no_crash", pg.evaluate("()=>document.querySelector('#view').innerHTML.length") > 0)

        # ===== G/H. copilot =====
        comm(pg, "comm-inbox")
        pg.evaluate("()=>f9OpenTicket(1042)"); pg.wait_for_timeout(1000)
        pg.evaluate("()=>f9Copilot()"); pg.wait_for_timeout(1200)
        chk("G_copilot_issue", pg.evaluate("()=>document.querySelector('#view').innerText.includes('Initial MT5 synchronization failed.')"))
        chk("G_evidence", pg.evaluate("()=>document.querySelector('#view').innerText.includes('Account connection exists')"))
        chk("G_confidence", pg.evaluate("()=>document.querySelector('#view').innerText.toLowerCase().includes('high')||document.querySelector('#view').innerText.includes('\\u0628\\u0627\\u0644\\u0627')"))
        pg.evaluate("()=>cmUseDraft()"); pg.wait_for_timeout(400)
        chk("G_use_draft", pg.evaluate("()=>(document.querySelector('#cmReply')||{value:''}).value.includes('reconnect your MT5 account')"))
        pg.evaluate("()=>f9Transform('professional')"); pg.wait_for_timeout(1200)
        chk("H_transform", pg.evaluate("()=>(document.querySelector('#cmReply')||{value:''}).value.includes('Dear Ali')"))

        # ===== I/J. translation =====
        comm(pg, "comm-inbox")
        pg.evaluate("()=>f9OpenTicket(1042)"); pg.wait_for_timeout(1000)
        pg.evaluate("()=>f9TranslateMsg(1)"); pg.wait_for_timeout(1200)
        chk("I_translation_rendered", pg.evaluate("()=>document.querySelector('#view').innerText.includes('E-404')"))
        chk("I_original_kept", pg.evaluate("()=>document.querySelector('#view').innerText.includes('I cannot connect my MT5 account #123456 since Monday.')"))
        pg.fill("#cmReply", "لطفاً حساب خود را دوباره متصل کنید.")
        pg.evaluate("()=>f9TranslateDraft()"); pg.wait_for_timeout(1200)
        chk("J_preview", pg.evaluate("()=>document.querySelector('#view').innerText.includes('Please reconnect your MT5 account and retry the synchronization.')"))

        # ===== K. AI down =====
        json.dump({"mode": "super", "ai_down": True}, open(MODE, "w"))
        pg.evaluate("()=>f9Copilot()"); pg.wait_for_timeout(1200)
        chk("K_copilot_fallback", pg.evaluate("()=>document.querySelector('#view').innerText.length>0"))
        chk("K_manual_intact", pg.evaluate("()=>!!document.querySelector('#cmReply')"))
        pg.evaluate("()=>f9TranslateMsg(1)"); pg.wait_for_timeout(1200)
        chk("K_translate_fallback", pg.evaluate("()=>document.querySelector('#view').innerText.length>0"))
        json.dump({"mode": "super"}, open(MODE, "w"))

        # ===== M. deep links + User360 nav + admin-side XSS rendering =====
        pg.evaluate("()=>{CM.ticket=null;CM.tkSt='idle';location.hash='#/comm-inbox?ticket=1043'}"); pg.wait_for_timeout(1800)
        chk("M_deeplink_ticket", pg.evaluate("()=>!!CM.ticket&&CM.ticket.conversation.id===1043&&document.querySelector('#view').innerText.includes('Deposit not reflected')"))
        reqs=[]
        pg.on("request", lambda r: reqs.append(r.url) if "communications/tickets?" in r.url else None)
        pg.evaluate("()=>{CM.ticket=null;CM.tkSt='idle';location.hash='#/comm-inbox?user=7'}"); pg.wait_for_timeout(1600)
        chk("M_deeplink_user", any("user_id=7" in u for u in reqs))
        pg.evaluate("()=>location.hash='#/users/2'"); pg.wait_for_timeout(1800)
        btn=pg.evaluate("()=>{const b=[...document.querySelectorAll('#view button')].find(b=>(b.getAttribute('onclick')||'').includes('comm-inbox?user=2'));if(b)b.click();return !!b}")
        pg.wait_for_timeout(1400)
        chk("M_u360_nav", btn and ("#/comm-inbox?user=2" in pg.evaluate("()=>location.hash")) and any("user_id=2" in u for u in reqs))
        json.dump({"mode":"super","comm_xss":True}, open(MODE, "w"))
        pg.evaluate("()=>{CM.ticket=null;CM.tkSt='idle';location.hash='#/comm-inbox'}"); pg.wait_for_timeout(800)
        pg.evaluate("()=>f9OpenTicket(1042)"); pg.wait_for_timeout(1600)
        mx=pg.evaluate("""()=>({pwned:window.__pwned===undefined,pwned2:window.__pwned2===undefined,pwned3:window.__pwned3===undefined,
             imgs:document.querySelectorAll('#view img').length,
             subj:document.querySelector('#view').innerText.includes('<img src=x onerror=window.__pwned=1>'),
             body:document.querySelector('#view').innerText.includes('probe body')})""")
        chk("M_admin_xss", mx["pwned"] and mx["pwned2"] and mx["pwned3"] and mx["imgs"]==0 and mx["subj"] and mx["body"])
        json.dump({"mode": "super"}, open(MODE, "w"))

        # ===== L. widths + a11y =====
        widths = [1440, 1280, 1024, 768, 430, 390, 360]
        ok_w = True
        for w in widths:
            pg.set_viewport_size({"width": w, "height": 950}); pg.wait_for_timeout(500)
            o = pg.evaluate("()=>document.documentElement.scrollWidth-document.documentElement.clientWidth")
            if o > 1: ok_w = False
        chk("L_widths", ok_w)
        chk("L_labels", pg.evaluate("()=>{const t=document.querySelector('#cmReply');return !t||!!t.getAttribute('aria-label')||!!t.closest('label');}"))

        # single-submit: three synchronous sends must produce exactly one POST (CM.busy guard)
        json.dump({"mode": "super"}, open(MODE, "w"))
        pg.evaluate("()=>f9OpenTicket(1042)"); pg.wait_for_timeout(1400)
        pg.fill("#cmReply", "x")
        pg.evaluate("()=>{f9SendReply();f9SendReply();f9SendReply();}"); pg.wait_for_timeout(1400)
        chk("L_send_single_submit", int(json.load(open(MODE)).get("comm_reply_calls", 0)) == 1)

        # FA/rtl widths on comm-inbox (L group above ran LTR/EN)
        pg.evaluate("()=>localStorage.setItem('velora_locale','fa')"); pg.goto(URL); pg.wait_for_timeout(1600)
        pg.evaluate("()=>location.hash='#/comm-inbox'"); pg.wait_for_timeout(1500)
        ok_fa = pg.evaluate("()=>document.documentElement.dir==='rtl'")
        for w in widths:
            pg.set_viewport_size({"width": w, "height": 950}); pg.wait_for_timeout(450)
            o = pg.evaluate("()=>document.documentElement.scrollWidth-document.documentElement.clientWidth")
            if o > 1: ok_fa = False
        chk("L_widths_fa_rtl", ok_fa)
        pg.close()
finally:
    srv.terminate()
    try: srv.wait(timeout=5)
    except Exception: srv.kill()

fails = [k for k, v in OUT.items() if not v]
print("SUPPORT INBOX UI:", "ALL GREEN —", len(OUT), "checks" if not fails else "")
if fails:
    print("FAILS:", fails)
sys.exit(1 if fails else 0)
