import json, os, time
from http.server import HTTPServer, SimpleHTTPRequestHandler
import os
REPO=os.path.abspath(os.path.join(os.path.dirname(__file__),"..","..",".."))
ROOT=os.path.dirname(os.path.abspath(__file__))
def loadmode(): return json.load(open(os.path.join(ROOT,"mode.json")))
PERMS_SUPER=["overview.view","users.view","users.suspend","users.activate","users.manage_subscription","users.verify_email","audit.view","audit.view_sensitive","system.health.view","system.logs.view","settings.view","feature_flags.view","billing.view","integrations.view","aiManage","analytics.view","users.change_role","system.settings.manage","feature_flags.edit","integrations.manage","aiRouteManage"]
PERMS_ADMIN=[p for p in PERMS_SUPER if p not in ("users.change_role","audit.view_sensitive","system.settings.manage","feature_flags.edit","integrations.manage","aiRouteManage")]
PERMS_LIMITED=["overview.view","users.view"]
PERMS_ADMIN_MINUS=[p for p in PERMS_ADMIN if p!="billing.view"]
PERMS_CREATOR=PERMS_ADMIN+["users.create"]
PERMS_INVITER=PERMS_SUPER
class H(SimpleHTTPRequestHandler):
    def __init__(self,*a,**kw): super().__init__(*a,directory=REPO,**kw)
    def log_message(self,*a): pass
    def _j(self,code,obj):
        b=json.dumps(obj).encode(); self.send_response(code)
        self.send_header("Content-Type","application/json"); self.send_header("Content-Length",str(len(b))); self.end_headers(); self.wfile.write(b)
    def _users(self,qs):
        from urllib.parse import parse_qs,unquote
        q=parse_qs(qs)
        DB=self.server.db_users
        def keep(u):
            if q.get("search"):
                s=q["search"][0].lower()
                if s not in u["email"].lower() and s not in (u["fullName"] or "").lower(): return False
            if q.get("role") and u["role"]!=q["role"][0]: return False
            if q.get("status") and u["status"]!=q["status"][0]: return False
            if q.get("plan") and u["plan"]!=q["plan"][0]: return False
            if q.get("verified"):
                want=q["verified"][0]=="1"
                if bool(u["emailVerified"])!=want: return False
            return True
        rows=[u for u in DB if keep(u)]
        total=len(rows)
        try: page=int(q.get("page",["1"])[0])
        except: page=1
        try: per=int(q.get("per_page",["10"])[0])
        except: per=10
        chunk=rows[(page-1)*per:page*per]
        return {"users":chunk,"pagination":{"total":total,"page":page,"per_page":per,"has_more":page*per<total}}
    def _find(self,uid):
        return next((u for u in self.server.db_users if u["id"]==uid),None)
    def do_GET(self):
        from urllib.parse import urlparse
        up=urlparse(self.path)
        if up.path=="/api/v1/admin/users":
            m=loadmode()
            if m["mode"]=="noauth": self._j(401,{"status":"error","error":{"code":"UNAUTHORIZED"}}); return
            if m["mode"]=="users403": self._j(403,{"status":"error","error":{"code":"PERMISSION_DENIED"}}); return
            self._j(200,self._users(up.query)); return
        import re as _re
        mm=_re.match(r"^/api/v1/admin/users/(\d+)$",up.path)
        if mm:
            u=self._find(int(mm.group(1)))
            if not u: self._j(404,{"status":"error","error":{"code":"USER_NOT_FOUND"}}); return
            self._j(200,{"user":u}); return
        if up.path=="/api/v1/admin/overview":
            m=loadmode()
            if m.get("delay_overview"): time.sleep(0.8)
            if m.get("ovr500"): self._j(500,{"status":"error","error":{"code":"INTERNAL_ERROR"}}); return
            self._j(200,{"overview":{
              "users":{"total":128,"active":110,"suspended":18,"newLast24h":3,"free":96,"pro":32,"admins":4,"planDistributionAvailable":True},
              "trading":{"connectedAccounts":21,"metaapiConnected":17,"totalTrades":1523,"recentActivity":[{"symbol":"XAUUSD","direction":"BUY","profitLoss":12.4,"openTime":"2026-09-05 19:02:11"}]},
              "ai":{"available":True,"providerStatus":[{"provider":"gemini","status":"VALID"},{"provider":"openai","status":"UNCONFIGURED"}],
                    "enabledProviders":[{"provider":"gemini"}],"verifiedProviders":["gemini"],"blockedProviders":[],
                    "requests":{"total":4210,"succeeded":4011,"failed":199,"tokensUsed":912345},
                    "rateLimitEvents":7,"activeLimiterBuckets":2,"internalUsageLabel":"internal usage"},
              "system":{"api":{"status":"ok","uptimeNote":"API responding"},
                        "database":{"status":"ok","latencyMs":2,"lastCheck":"2026-09-05T21:00:00Z"},
                        "metaapi":{"configured":True,"source":"db","status":"configured_only","note":"live connectivity is probed only via the Test Connection action"},
                        "aiProviders":[{"provider":"gemini","status":"VALID","last_checked_at":"2026-09-05 20:58:00","error_code":None}],
                        "email":{"driver":"resend","configured":True,"source":"db","sentLast24h":12,"failedLast24h":0},
                        "workers":{"jobsPending":0,"jobsFailed":1,"syncFailed":0}},
              "billing":{"available":True,"activeSubscriptions":32,
                         "planDistribution":[{"plan":"free","count":96},{"plan":"pro","count":32}],
                         "revenue":{"available":False,"reason":"No external payment/billing integration exists; revenue is not auditable/data-backed."}}
            }}); return
        if up.path=="/api/v1/admin/system/health":
            self._j(200,{"health":{
              "api":{"status":"ok","uptimeNote":"API responding"},
              "database":{"status":"ok","latencyMs":3,"lastCheck":"2026-09-05T21:00:00Z"},
              "metaapi":{"configured":True,"source":"db","status":"configured_only"},
              "aiProviders":[{"provider":"gemini","status":"VALID","last_checked_at":"2026-09-05 20:58:00","error_code":None}],
              "email":{"driver":"resend","configured":True,"source":"db","sentLast24h":12,"failedLast24h":0},
              "workers":{"jobsPending":0,"jobsFailed":1,"syncFailed":0}}}); return
        if up.path=="/api/v1/admin/ai/overview":
            self._j(200,{
             "providers":[
               {"provider":"gemini","registered":True,"capabilities":["analyze-trades","weekly-report"],"available":True,
                "credentialStatus":{"required":True,"configured":True,"envKey":"GEMINI_API_KEY"},
                "relay":{"urlConfigured":True,"tokenConfigured":True},"effectiveRoute":"direct","quota":{"daily":1500,"used":420}},
               {"provider":"openai","registered":True,"capabilities":[],"available":False,
                "credentialStatus":{"required":True,"configured":False,"envKey":"OPENAI_API_KEY"}}],
             "features":[{"feature":"analyze-trades","capability":"analyze","flag":{"enabled":True,"rolloutPercentage":100},
                          "source":"admin","chain":["gemini"],"rows":[{"id":1,"provider":"gemini","enabled":True}]},
                         {"feature":"weekly-report","capability":"report","flag":None,"source":"default","chain":["gemini"],"rows":[]}],
             "routingTableExists":True,"routingRowCount":1}); return
        if up.path=="/api/v1/admin/ai/route":
            m=loadmode()
            if m["mode"]=="noauth": self._j(401,{"status":"error","error":{"code":"UNAUTHORIZED"}}); return
            self._j(200,{"route":{
              "configured":self.server.ai_route,"effective":self.server.ai_route or "direct",
              "source":("admin" if self.server.ai_route else "default"),
              "allowed":["direct","n8n_relay"],"providerEffective":(self.server.ai_route or "direct")}}); return
        if up.path=="/api/v1/admin/integrations/relay/config":
            I=self.server.integ
            self._j(200,{"config":dict(I["relay"])}); return
        if up.path=="/api/v1/admin/integrations/metaapi":
            I=self.server.integ
            self._j(200,{"integration":dict(I["metaapi"])}); return
        if up.path=="/api/v1/admin/integrations/email":
            I=self.server.integ
            self._j(200,{"integration":dict(I["email"])}); return
        if up.path=="/api/v1/admin/system/diagnostics":
            m=loadmode()
            if m["mode"] in ("noauth","user403","panel_false"): self._j(403,{"status":"error","error":{"code":"PERMISSION_DENIED"}}); return
            lr=getattr(self.server,"last_refresh",None)
            integ_row=lambda name:({"status":"SUCCESS","latencyMs":120 if name=="metaapi" else 95,"errorCode":None,"message":None,"checkedAt":lr} if lr else {"status":"UNKNOWN","latencyMs":None,"errorCode":None,"message":None,"checkedAt":None})
            self._j(200,{"health":{
              "api":{"status":"HEALTHY","latencyMs":4,"message":"API responding"},
              "database":{"status":"HEALTHY","latencyMs":3,"message":"Database reachable"},
              "metaapi":integ_row("metaapi"),"email":integ_row("email"),
              "ai":{"status":"HEALTHY","configured":True,"verifiedProviders":1,
                    "providers":[{"provider":"gemini","status":"VALID","verified":True,"last_checked_at":"2026-09-05 20:58:00","error_code":None}]}}}); return
        if up.path in ("/api/v1/admin/analytics/users","/api/v1/admin/analytics/trading","/api/v1/admin/analytics/ai","/api/v1/admin/analytics/revenue"):
            m=loadmode()
            if m["mode"] in ("noauth","user403","panel_false"): self._j(403,{"status":"error","error":{"code":"PERMISSION_DENIED"}}); return
            which=up.path.rsplit("/",1)[-1]
            rng=(up.query.split("range=")[-1].split("&")[0] if "range=" in up.query else "30d")
            rngdesc={"start":"2026-08-07 00:00:00","end":"2026-09-06 23:59:59","label":rng,"presentation":rng,"timezone":"UTC"}
            if which=="users":
                self._j(200,{"range":rngdesc,"total":128,"newInRange":17,
                    "byRole":[{"key":"user","count":121},{"key":"admin","count":5},{"key":"super_admin","count":2}],
                    "byStatus":[{"key":"active","count":110},{"key":"suspended","count":18}],
                    "byLocale":[{"key":"fa","count":101},{"key":"en","count":27}],
                    "registrationTrend":[{"date":"2026-08-31","count":4},{"date":"2026-09-01","count":9},{"date":"2026-09-02","count":2},
                                          {"date":"2026-09-03","count":11},{"date":"2026-09-04","count":7},{"date":"2026-09-05","count":14},{"date":"2026-09-06","count":5}]}); return
            if which=="trading":
                self._j(200,{"range":rngdesc,"total":1523,"tradesInRange":212,
                    "bySymbol":[{"key":"XAUUSD","count":137},{"key":"EURUSD","count":75}],
                    "byDirection":[{"key":"BUY","count":128},{"key":"SELL","count":84}],
                    "winLoss":{"wins":118,"losses":76,"breakeven":18},
                    "netPnl":"3421.50","totalVolume":"15234.75",
                    "trend":[{"date":"2026-09-01","count":33},{"date":"2026-09-02","count":41},{"date":"2026-09-03","count":12},
                              {"date":"2026-09-04","count":56},{"date":"2026-09-05","count":70}],
                    "isRevenue":False,
                    "note":"Aggregate trading P&L is trading performance, NOT platform revenue."}); return
            if which=="ai":
                self._j(200,{"range":rngdesc,"total":4210,"inRange":388,
                    "byStatus":[{"key":"success","count":371},{"key":"failed","count":17}],
                    "byProvider":[{"key":"gemini","count":380},{"key":"openai","count":8}],
                    "byFeature":[{"key":"analyze-trades","count":301},{"key":"weekly-report","count":87}],
                    "byModel":[{"key":"gemini-2.5-flash","count":380}],
                    "tokensUsed":91234,"cost":"18.4231",
                    "trend":[{"date":"2026-09-02","count":47},{"date":"2026-09-03","count":61},{"date":"2026-09-04","count":88},
                              {"date":"2026-09-05","count":102},{"date":"2026-09-06","count":90}]}); return
            un=lambda:{"available":False,"reason":"NO_BILLING_SOURCE"}
            self._j(200,{"available":False,"reason":"NO_BILLING_SOURCE",
                "note":"No authoritative billing source is configured. Financial metrics are unavailable, not zero.",
                "metrics":{"revenue":un(),"mrr":un(),"arr":un(),"churn":un(),"ltv":un(),"paymentVolume":un(),"refunds":un()}}); return
        if up.path=="/api/v1/admin/billing":
            m=loadmode()
            if m["mode"] in ("noauth","user403","panel_false"): self._j(403,{"status":"error","error":{"code":"PERMISSION_DENIED"}}); return
            self._j(200,{
              "provider":{"available":False,"reason":"No external payment/billing integration exists (no provider client, no credit card, no webhook). Subscription state is internal/manual only."},
              "plans":[
                {"key":"free","name":"Free","description":"Free plan","price":{"available":False,"reason":"Plan price is not authoritative: no external billing/pricing source exists."},"currency":None,"interval":None,"active":True,"available":True},
                {"key":"pro","name":"Pro","description":"Professional plan","price":{"available":False,"reason":"Plan price is not authoritative: no external billing/pricing source exists."},"currency":None,"interval":None,"active":True,"available":True}],
              "subscriptionStatuses":[{"key":"none","name":"None"},{"key":"active","name":"Active"},{"key":"past_due","name":"Past due"}],
              "distribution":{"available":True,"plan":[{"key":"free","count":96},{"key":"pro","count":32}],
                              "subscriptionStatus":[{"key":"active","count":32},{"key":"none","count":96}]},
              "entitlements":{"tradingAccountsPerUser":{"limit":10,"source":"config:metaapi.max_accounts_per_user"},
                              "providerBudget":{"daily":1500,"used":420}},
              "history":{"available":False,"reason":"No invoice/payment history exists (no billing provider)."}}); return
        if up.path=="/api/v1/admin/logs/system":
            from urllib.parse import parse_qs
            m=loadmode()
            if m.get("read500"): self._j(500,{"status":"error","error":{"code":"INTERNAL_ERROR"}}); return
            q=parse_qs(up.query)
            rows=[dict(r) for r in self.server.db_logs]
            if q.get("severity"): rows=[r for r in rows if r["severity"]==q["severity"][0].upper()]
            if q.get("q"):
                qq=q["q"][0].lower(); rows=[r for r in rows if qq in r["message"].lower()]
            try: page=int(q.get("page",["1"])[0])
            except: page=1
            try: per=int(q.get("per_page",["50"])[0])
            except: per=50
            total=len(rows)
            self._j(200,{"items":rows[(page-1)*per:page*per],"total":total,"page":page,"per_page":per}); return
        if up.path=="/api/v1/admin/logs/audit":
            from urllib.parse import parse_qs
            m=loadmode()
            if m.get("read500"): self._j(500,{"status":"error","error":{"code":"INTERNAL_ERROR"}}); return
            sensitive=(m["mode"]=="super")
            q=parse_qs(up.query)
            rows=[dict(r) for r in self.server.db_audit]
            if q.get("result"): rows=[r for r in rows if r["result"]==q["result"][0]]
            if not sensitive:
                rows=[{k:v for k,v in r.items() if k not in ("ipAddress","contextId")} for r in rows]
            try: page=int(q.get("page",["1"])[0])
            except: page=1
            try: per=int(q.get("per_page",["50"])[0])
            except: per=50
            total=len(rows)
            self._j(200,{"items":rows[(page-1)*per:page*per],"total":total,"page":page,"per_page":per}); return
        if up.path=="/api/v1/admin/feature-flags":
            m=loadmode()
            if m["mode"] in ("noauth","user403","panel_false"): self._j(403,{"status":"error","error":{"code":"PERMISSION_DENIED"}}); return
            self._j(200,{"flags":[dict(v,feature=k) for k,v in self.server.flags.items()],
                          "environment":"production",
                          "allowed":list(self.server.flags.keys())}); return
        if self.path.split("?")[0] in ("/fa/dashboard/","/en/dashboard/","/fa/dashboard","/en/dashboard"):
            loc="fa" if self.path.startswith("/fa") else "en"
            b=("<html><body><div id='dashboardPage' data-locale='"+loc+"'>VELORA DASHBOARD</div></body></html>").encode()
            self.send_response(200)
            self.send_header("Content-Type","text/html; charset=utf-8"); self.send_header("Content-Length",str(len(b))); self.end_headers(); self.wfile.write(b); return
        if self.path.split("?")[0] in ("/login","/login/"):
            b=b"<html><body>login</body></html>"; self.send_response(200)
            self.send_header("Content-Type","text/html"); self.send_header("Content-Length",str(len(b))); self.end_headers(); self.wfile.write(b); return
        if self.path.startswith("/api/v1/admin/me"):
            m=loadmode(); me=None
            if m["mode"]=="admin": me={"userId":4,"role":"admin","isSuperAdmin":False,"panel":True,"name":"Sahar Rahimi","email":"s.rahimi@veloratrade.ir","permissions":PERMS_ADMIN}
            elif m["mode"]=="super": me={"userId":5,"role":"super_admin","isSuperAdmin":True,"panel":True,"name":"Arman Kaveh","email":"a.kaveh@veloratrade.ir","permissions":PERMS_SUPER,"recentAdminActions":[]}
            elif m["mode"]=="adminminus": me={"userId":6,"role":"admin","isSuperAdmin":False,"panel":True,"permissions":PERMS_ADMIN_MINUS,"recentAdminActions":[]}
            elif m["mode"]=="creator": me={"userId":8,"role":"admin","isSuperAdmin":False,"panel":True,"name":"Sahar Rahimi","email":"s.rahimi@veloratrade.ir","permissions":PERMS_CREATOR,"recentAdminActions":[]}
            elif m["mode"]=="inviter": me={"userId":5,"role":"super_admin","isSuperAdmin":True,"panel":True,"name":"Arman Kaveh","email":"a.kaveh@veloratrade.ir","permissions":PERMS_INVITER,"recentAdminActions":[]}
            elif m["mode"]=="limited": me={"userId":7,"role":"admin","isSuperAdmin":False,"panel":True,"name":"Neda Karimi","email":"n.karimi@veloratrade.ir","permissions":PERMS_LIMITED,"recentAdminActions":[]}
            elif m["mode"]=="user403": self._j(403,{"status":"error","error":{"code":"ADMIN_REQUIRED"}}); return
            elif m["mode"]=="panel_false": me={"userId":9,"role":"user","isSuperAdmin":False,"panel":False,"permissions":[]}
            else: self._j(500,{"status":"error","error":{"code":"INTERNAL_ERROR"}}); return
            rAA=[{"action":"user.verify_email","targetType":"user","targetId":2,"result":"success","summary":"User #2 email verified by admin","createdAt":"2026-09-05 20:30:00"}] if m["mode"]=="admin" else []
            self._j(200,{"me":me,"recentAdminActions":rAA}); return
        super().do_GET()
    def do_POST(self):
        import re as _re
        from urllib.parse import urlparse
        up=urlparse(self.path)
        m0=loadmode()
        if up.path=="/api/v1/admin/users":
            # Create User (Phase 1) — mirrors the real UserManagementController::store shape.
            m=loadmode()
            length=int(self.headers.get("Content-Length") or 0)
            body={}
            if length:
                try: body=json.loads(self.rfile.read(length) or b"{}")
                except: body={}
            m["create_body"]=body; m["create_calls"]=m.get("create_calls",0)+1
            json.dump(m,open(os.path.join(ROOT,"mode.json"),"w"))
            res=m.get("create_result","ok")
            if res=="dup":
                self._j(409,{"status":"error","error":{"code":"EMAIL_ALREADY_REGISTERED","message":"This email is already registered.","messageKey":"errors.admin.emailAlreadyRegistered","params":{},"details":None}}); return
            if res=="invalid":
                self._j(422,{"status":"error","error":{"code":"VALIDATION_FAILED","message":"Validation failed.","messageKey":"errors.validation","params":{},"details":{"fields":{"email":{"code":"INVALID_EMAIL","messageKey":"errors.validation.email","params":[]}}}}}); return
            nu={"id":99,"email":body.get("email") or "new@velora.test","fullName":body.get("fullName") or "New User",
                "role":body.get("role") or "user","status":"active","emailVerified":False,"emailVerifiedAt":None,
                "createdAt":"2026-09-07 10:00:00","plan":body.get("plan") or "free",
                "subscriptionStatus":"active" if (body.get("plan")=="pro") else "none"}
            self.server.db_users.append(dict(nu))
            self._j(201,{"status":"success","data":{"ok":True,"user":nu,"verificationRequired":True,
                        "emailSent": res!="notsent"},"error":None,"timestamp":"2026-09-07T10:00:00+00:00"}); return
        if up.path=="/api/v1/admin/users/invitations":
            # Invite Admin (Phase 2) — mirrors the real controller::invite shape.
            m=loadmode()
            length=int(self.headers.get("Content-Length") or 0)
            body={}
            if length:
                try: body=json.loads(self.rfile.read(length) or b"{}")
                except: body={}
            m["invite_body"]=body; m["invite_calls"]=m.get("invite_calls",0)+1
            json.dump(m,open(os.path.join(ROOT,"mode.json"),"w"))
            res=m.get("invite_result","ok")
            if res=="dup":
                self._j(409,{"status":"error","error":{"code":"EMAIL_ALREADY_REGISTERED","message":"This email is already registered.","messageKey":"errors.auth.emailAlreadyRegistered","params":{},"details":None}}); return
            if res=="invalid":
                self._j(422,{"status":"error","error":{"code":"VALIDATION_FAILED","message":"Validation failed.","messageKey":"errors.validation","params":{},"details":{"fields":{"email":{"code":"INVALID_EMAIL","messageKey":"errors.validation.email","params":[]}}}}}); return
            if res=="serverfail":
                self._j(500,{"status":"error","error":{"code":"INTERNAL_ERROR","message":"Internal server error.","messageKey":"errors.http.500","params":{},"details":None}}); return
            nu={"id":98,"email":body.get("email") or "invited@velora.test","fullName":body.get("fullName") or "Invited Admin",
                "role":body.get("role") or "admin","status":"active","emailVerified":False,"emailVerifiedAt":None,
                "createdAt":"2026-09-07 12:00:00","plan":"free","subscriptionStatus":"none"}
            self.server.db_users.append(dict(nu))
            self._j(201,{"status":"success","data":{"ok":True,"user":dict(nu,invitePending=True),
                        "emailSent": res!="notsent"},"error":None,"timestamp":"2026-09-07T12:00:00+00:00"}); return
        mm=_re.match(r"^/api/v1/admin/users/(\d+)/(status|role|subscription|revoke-sessions|verify-email)$",up.path)
        if mm:
            uid=int(mm.group(1)); act=mm.group(2)
            u=self._find(uid)
            if m0.get("fail_actions"): self._j(500,{"status":"error","error":{"code":"INTERNAL_ERROR"}}); return
            if not u: self._j(404,{"status":"error","error":{"code":"USER_NOT_FOUND"}}); return
            if uid==4 and act in ("verify-email","status","role","subscription","revoke-sessions"):
                self._j(403,{"status":"error","error":{"code":"SELF_ACTION_DENIED"}}); return
            length=int(self.headers.get("Content-Length") or 0)
            body={}
            if length:
                try: body=json.loads(self.rfile.read(length) or b"{}")
                except: body={}
            if act=="status":
                st=body.get("status")
                if st not in ("active","suspended"): self._j(422,{"status":"error","error":{"code":"VALIDATION_FAILED","details":{"status":{"code":"INVALID_STATUS"}}}}); return
                u["status"]=st; self._j(200,{"ok":True,"user":u}); return
            if act=="role":
                r=body.get("role")
                if r not in ("user","admin","super_admin"): self._j(422,{"status":"error","error":{"code":"VALIDATION_FAILED","details":{"role":{"code":"INVALID_ROLE"}}}}); return
                u["role"]=r; self._j(200,{"ok":True,"user":u}); return
            if act=="subscription":
                pl=body.get("plan") or u["plan"] or "free"
                if pl not in ("free","pro"): self._j(422,{"status":"error","error":{"code":"VALIDATION_FAILED","details":{"plan":{"code":"INVALID_PLAN"}}}}); return
                u["plan"]=pl; u["subscriptionStatus"]=body.get("status") or u.get("subscriptionStatus") or "none"
                self._j(200,{"ok":True,"user":{"id":uid,"plan":pl,"status":u["subscriptionStatus"]}}); return
            if act=="revoke-sessions":
                u["sessionsRevoked"]=u.get("sessionsRevoked",0)+1
                self._j(200,{"ok":True,"revoked":u["sessionsRevoked"]}); return
            if act=="verify-email":
                changed=not u["emailVerified"]
                u["emailVerified"]=True
                u["emailVerifiedAt"]=u["emailVerifiedAt"] or "2026-09-05 21:10:00"
                self._j(200,{"ok":True,"changed":changed,"user":u}); return
        if up.path in ("/api/v1/admin/providers/gemini/verify","/api/v1/admin/providers/openai/verify",
                       "/api/v1/admin/providers/gemini/test-connection","/api/v1/admin/providers/openai/test-connection"):
            prov=up.path.split("/")[5]; op=up.path.split("/")[6]
            if m0.get("fail_actions"): self._j(504,{"status":"error","error":{"code":"PROVIDER_TIMEOUT"}}); return
            if prov=="openai":
                self._j(200,{"provider":"openai","status":"UNCONFIGURED","verified":False,"reachable":None,
                             "checked_at":"2026-09-05 21:12:00","latency_ms":None,"error_code":"CREDENTIAL_MISSING","message":"credential not configured","retryable":False,"source":"panel"}); return
            self._j(200,{"provider":"gemini","status":"VALID","verified":True,"reachable":True if op=="test-connection" else None,
                         "checked_at":"2026-09-05 21:12:00","latency_ms":318 if op=="test-connection" else None,
                         "error_code":None,"message":None,"retryable":False,"source":"panel"}); return
        if up.path=="/api/v1/admin/system/diagnostics/refresh":
            if m0.get("fail_actions"): self._j(500,{"status":"error","error":{"code":"INTERNAL_ERROR"}}); return
            ts="2026-09-05 21:30:00"
            self.server.last_refresh=ts
            self._j(200,{"results":{"metaapi":{"status":"SUCCESS","latencyMs":120,"checkedAt":ts},
                                    "email":{"status":"SUCCESS","latencyMs":95,"checkedAt":ts}}}); return
        if up.path in ("/api/v1/admin/integrations/metaapi/test","/api/v1/admin/integrations/email/test"):
            if m0["mode"]=="admin": self._j(403,{"status":"error","error":{"code":"PERMISSION_DENIED"}}); return
            which="metaapi" if "metaapi" in up.path else "email"
            I=self.server.integ[which]
            cfgok=I.get("tokenConfigured",True) and I.get("configured",True)
            if not cfgok:
                self._j(200,{"test":{"status":"NOT_CONFIGURED","checkedAt":"2026-09-05 21:31:00","latencyMs":None,"message":which+" is not configured."},
                             "integration":dict(I,reachability="NOT_CONFIGURED")}); return
            if m0.get("fail_actions"): self._j(500,{"status":"error","error":{"code":"INTERNAL_ERROR"}}); return
            if m0.get("probe_timeout"):
                st={"status":"TIMEOUT","checkedAt":"2026-09-05 21:31:00","latencyMs":8000,"message":"upstream did not answer in time"}
            elif m0.get("probe_authfail"):
                st={"status":"AUTH_FAILED","checkedAt":"2026-09-05 21:31:00","latencyMs":140,"message":"upstream rejected the credential"}
            else:
                st={"status":"SUCCESS","checkedAt":"2026-09-05 21:31:00","latencyMs":213 if which=="metaapi" else 95,"message":None}
            upd=dict(I,reachability=st["status"],lastCheckedAt=st["checkedAt"],latencyMs=st["latencyMs"])
            self.server.integ[which]=upd
            self._j(200,{"test":st,"integration":upd}); return
        if self.path.startswith("/api/v1/auth/refresh"):
            if loadmode()["mode"]=="noauth": self._j(401,{"status":"error","error":{"code":"UNAUTHORIZED"}})
            else:
                # session identity mirrors /admin/me per mode (as in production: same logged-in user)
                ident={"admin":(4,"Sahar Rahimi","s.rahimi@veloratrade.ir"),
                       "super":(5,"Arman Kaveh","a.kaveh@veloratrade.ir"),
                       "limited":(7,"Neda Karimi","n.karimi@veloratrade.ir")}
                _id,_fn,_em=ident.get(loadmode()["mode"],(4,None,None))
                _u={"id":_id,"role":"admin","locale":"fa"}
                if _fn: _u["fullName"]=_fn; _u["email"]=_em
                self._j(200,{"tokens":{"accessToken":"stub-token","user":_u}})
        elif self.path.startswith("/api/v1/auth/logout"):
            m=loadmode(); m["logout_called"]=True; json.dump(m,open(os.path.join(ROOT,"mode.json"),"w")); self._j(200,{"ok":True})
        else: self._j(404,{})
    def do_PUT(self):
        from urllib.parse import urlparse
        up=urlparse(self.path)
        if up.path=="/api/v1/admin/ai/route":
            m0=loadmode()
            if m0.get("fail_actions"): self._j(500,{"status":"error","error":{"code":"AI_ROUTE_PERSIST_FAILED"}}); return
            length=int(self.headers.get("Content-Length") or 0)
            body={}
            if length:
                try: body=json.loads(self.rfile.read(length) or b"{}")
                except: body={}
            r=str(body.get("route","")).lower().strip()
            if r not in ("direct","n8n_relay"):
                self._j(422,{"status":"error","error":{"code":"VALIDATION_FAILED","details":{"route":{"code":"INVALID_AI_ROUTE"}}}}); return
            self.server.ai_route=r
            self._j(200,{"route":{"configured":r,"effective":r,"source":"admin","allowed":["direct","n8n_relay"],"providerEffective":r}}); return
        if up.path in ("/api/v1/admin/integrations/relay/config","/api/v1/admin/integrations/metaapi","/api/v1/admin/integrations/email"):
            m0=loadmode()
            if m0["mode"]=="admin": self._j(403,{"status":"error","error":{"code":"PERMISSION_DENIED"}}); return
            if m0.get("fail_actions"):
                code={"relay/config":"RELAY_CONFIG_PERSIST_FAILED","metaapi":"METAAPI_CONFIG_PERSIST_FAILED","email":"EMAIL_CONFIG_PERSIST_FAILED"}[up.path.rsplit("/",1)[-1] if up.path.endswith(("metaapi","email")) else "relay/config"]
                self._j(500,{"status":"error","error":{"code":code}}); return
            if m0.get("relay429") and "relay" in up.path:
                self._j(429,{"status":"error","error":{"code":"RATE_LIMITED"}}); return
            length=int(self.headers.get("Content-Length") or 0)
            body={}
            if length:
                try: body=json.loads(self.rfile.read(length) or b"{}")
                except: body={}
            I=self.server.integ
            def badhost(u):
                return not u.startswith("https://") or "@" in u or "localhost" in u
            if "relay" in up.path:
                url=str(body.get("url") or "").strip(); tok=str(body.get("token") or "")
                if url=="" and tok=="": self._j(422,{"status":"error","error":{"code":"RELAY_CONFIG_EMPTY"}}); return
                if url and badhost(url): self._j(422,{"status":"error","error":{"code":"INVALID_ENDPOINT_URL"}}); return
                if url:
                    I["relay"].update(urlConfigured=True,tokenConfigured=I["relay"]["tokenConfigured"],urlHost=url.split("/")[2],configured=True)
                if tok: I["relay"].update(tokenConfigured=True,configured=True)
                self._j(200,{"config":dict(I["relay"])}); return
            if up.path.endswith("metaapi"):
                tok=str(body.get("token") or ""); whs=str(body.get("webhook_secret") or ""); bu=str(body.get("base_url") or "").strip()
                if tok=="" and whs=="" and bu=="": self._j(422,{"status":"error","error":{"code":"INTEGRATION_CONFIG_EMPTY"}}); return
                if bu and badhost(bu): self._j(422,{"status":"error","error":{"code":"INVALID_ENDPOINT_URL"}}); return
                if tok: I["metaapi"]["tokenConfigured"]=True; I["metaapi"]["configured"]=True
                if whs: I["metaapi"]["webhookSecretConfigured"]=True
                if bu: I["metaapi"]["baseUrl"]=bu; I["metaapi"]["baseUrlHost"]=bu.split("/")[2]
                self._j(200,{"integration":dict(I["metaapi"])}); return
            drv=str(body.get("driver") or "").strip().lower()
            frm=str(body.get("from") or "").strip()
            if drv and drv not in ("log","mail","smtp","resend"): self._j(422,{"status":"error","error":{"code":"INVALID_MAIL_DRIVER"}}); return
            if frm and "@" not in frm: self._j(422,{"status":"error","error":{"code":"INVALID_FROM_EMAIL"}}); return
            port=str(body.get("smtp_port") or "").strip()
            if port and not (port.isdigit() and 1<=int(port)<=65535): self._j(422,{"status":"error","error":{"code":"INVALID_FORMAT"}}); return
            E=I["email"]
            if drv: E["driver"]=drv; E["configured"]=True
            if frm: E["from"]=frm
            if str(body.get("from_name") or "").strip(): E["fromName"]=str(body["from_name"]).strip()
            if str(body.get("smtp_host") or "").strip(): E["smtpHost"]=str(body["smtp_host"]).strip()
            if port: E["smtpPort"]=port
            if str(body.get("smtp_user") or "").strip(): E["smtpUser"]=str(body["smtp_user"]).strip()
            if str(body.get("smtp_password") or ""): E["smtpPasswordConfigured"]=True
            if str(body.get("resend_api_key") or ""): E["resendApiKeyConfigured"]=True
            self._j(200,{"integration":dict(E)}); return
        self._j(404,{})
    def do_DELETE(self):
        from urllib.parse import urlparse
        up=urlparse(self.path)
        if up.path=="/api/v1/admin/ai/route":
            if loadmode().get("fail_actions"): self._j(500,{"status":"error","error":{"code":"AI_ROUTE_PERSIST_FAILED"}}); return
            self.server.ai_route=None
            self._j(200,{"route":{"configured":None,"effective":"direct","source":"default","allowed":["direct","n8n_relay"],"providerEffective":"direct"}}); return
        if up.path in ("/api/v1/admin/integrations/relay/config","/api/v1/admin/integrations/metaapi","/api/v1/admin/integrations/email"):
            m0=loadmode()
            if m0["mode"]=="admin": self._j(403,{"status":"error","error":{"code":"PERMISSION_DENIED"}}); return
            if m0.get("fail_actions"): self._j(500,{"status":"error","error":{"code":"INTERNAL_ERROR"}}); return
            I=self.server.integ
            if "relay" in up.path:
                I["relay"]={"configured":False,"urlConfigured":False,"tokenConfigured":False,"urlHost":None}
                self._j(200,{"config":dict(I["relay"])}); return
            if up.path.endswith("metaapi"):
                I["metaapi"].update(tokenConfigured=False,webhookSecretConfigured=False,configured=False,reachability="unknown",lastCheckedAt=None,latencyMs=None)
                self._j(200,{"integration":dict(I["metaapi"])}); return
            I["email"].update(resendApiKeyConfigured=False,smtpPasswordConfigured=False,configured=False,reachability="unknown",lastCheckedAt=None,latencyMs=None)
            self._j(200,{"integration":dict(I["email"])}); return
        self._j(404,{})
    def do_PATCH(self):
        from urllib.parse import urlparse
        up=urlparse(self.path)
        m0=loadmode()
        if up.path=="/api/v1/admin/feature-flags" or up.path.startswith("/api/v1/admin/feature-flags/"):
            if m0["mode"]!="super": self._j(403,{"status":"error","error":{"code":"PERMISSION_DENIED"}}); return
            feat=up.path.rsplit("/",1)[-1]
            if m0.get("fail_actions"): self._j(500,{"status":"error","error":{"code":"FEATURE_FLAG_PERSIST_FAILED"}}); return
            if feat not in self.server.flags: self._j(404,{"status":"error","error":{"code":"NOT_FOUND"}}); return
            length=int(self.headers.get("Content-Length") or 0)
            body={}
            if length:
                try: body=json.loads(self.rfile.read(length) or b"{}")
                except: body={}
            enabled=bool(body.get("enabled",False))
            rollout=int(body.get("rollout",100 if enabled else 0))
            if rollout<0 or rollout>100:
                self._j(422,{"status":"error","error":{"code":"ROLLOUT_RANGE"}}); return
            f=self.server.flags[feat]
            f.update(enabled=enabled,rollout=(rollout if enabled else 0),persisted=True,
                     updatedAt="2026-09-06 00:30:00",effective=("on" if enabled and rollout>=100 else ("off" if not enabled or rollout<=0 else "rollout:%d"%rollout)),
                     runtime=enabled)
            self._j(200,{"flag":dict(f,feature=feat)}); return
        self._j(404,{})

class Srv(HTTPServer):
    def __init__(self,*a,**kw):
        super().__init__(*a,**kw)
        self.ai_route=None
        self.last_refresh=None
        self.flags={
         "ai_screenshot_extraction":{"enabled":True,"rollout":100,"environment":"production","persisted":False,"updatedBy":None,"updatedAt":None,"effective":"on","runtime":True},
         "ai_trade_analysis":{"enabled":False,"rollout":0,"environment":"production","persisted":False,"updatedBy":None,"updatedAt":None,"effective":"off","runtime":False},
         "ai_weekly_report":{"enabled":False,"rollout":0,"environment":"production","persisted":False,"updatedBy":None,"updatedAt":None,"effective":"off","runtime":False},
         "ai_assistant":{"enabled":True,"rollout":40,"environment":"production","persisted":True,"updatedBy":1,"updatedAt":"2026-09-04 09:00:00","effective":"rollout:40","runtime":True},
        }
        self.db_logs=[
         {"id":41,"severity":"ERROR","source":"api","message":"MetaAPI sync failed for account 12","requestId":"req_9f21","correlationId":"ctx_31","userId":3,"errorCode":"SYNC_FAILED","metadata":{},"createdAt":"2026-09-05 20:44:00"},
         {"id":40,"severity":"WARN","source":"mailer","message":"Resend returned 429, retry scheduled","requestId":"req_9f18","correlationId":"ctx_29","userId":None,"errorCode":"RATE_LIMITED","metadata":{},"createdAt":"2026-09-05 20:12:00"},
         {"id":39,"severity":"INFO","source":"auth","message":"Admin session revoked by panel action","requestId":"req_9f10","correlationId":"ctx_25","userId":4,"errorCode":None,"metadata":{},"createdAt":"2026-09-05 19:58:00"},
         {"id":38,"severity":"ERROR","source":"ai","message":"provider timeout after 2 retries","requestId":"req_9e88","correlationId":"ctx_21","userId":2,"errorCode":"PROVIDER_TIMEOUT","metadata":{},"createdAt":"2026-09-05 18:30:00"},
         {"id":37,"severity":"INFO","source":"api","message":"feature flag ai_assistant rollout updated","requestId":"req_9e01","correlationId":"ctx_18","userId":1,"errorCode":None,"metadata":{},"createdAt":"2026-09-05 12:00:00"},
         {"id":36,"severity":"DEBUG","source":"queue","message":"jobs worker heartbeat","requestId":None,"correlationId":None,"userId":None,"errorCode":None,"metadata":{},"createdAt":"2026-09-05 11:45:00"},
        ]
        self.db_audit=[
         {"id":900,"actorUserId":4,"actorRole":"admin","action":"user.verify_email","targetType":"user","targetId":2,"result":"success",
          "summary":"User #2 email verified by admin","ipAddress":"203.0.113.24","contextId":"ctx_25","createdAt":"2026-09-05 20:30:00"},
         {"id":899,"actorUserId":5,"actorRole":"super_admin","action":"ai_route.updated","targetType":None,"targetId":None,"result":"success",
          "summary":"Admin updated AI route.","ipAddress":"198.51.100.7","contextId":"ctx_24","createdAt":"2026-09-05 19:10:00"},
         {"id":898,"actorUserId":4,"actorRole":"admin","action":"integration.metaapi.test","targetType":"integration","targetId":None,"result":"denied",
          "summary":"Admin attempted integration test without permission","ipAddress":"203.0.113.24","contextId":"ctx_22","createdAt":"2026-09-05 18:02:00"},
         {"id":897,"actorUserId":4,"actorRole":"admin","action":"user.status","targetType":"user","targetId":5,"result":"error",
          "summary":"Admin failed to update user status","ipAddress":"203.0.113.24","contextId":"ctx_20","createdAt":"2026-09-05 17:20:00"},
        ]
        self.integ={
         "relay":{"configured":False,"urlConfigured":False,"tokenConfigured":False,"urlHost":None},
         "metaapi":{"configured":True,"tokenConfigured":True,"webhookSecretConfigured":False,
                    "baseUrlHost":"api.metaapi.cloud","baseUrl":"https://api.metaapi.cloud","source":"env",
                    "reachability":"unknown","lastCheckedAt":None,"latencyMs":None},
         "email":{"configured":True,"driver":"resend","from":"no-reply@velora.test","fromName":"Velora",
                  "smtpHost":None,"smtpPort":None,"smtpUser":None,"resendApiKeyConfigured":True,
                  "smtpPasswordConfigured":False,"source":"env","reachability":"unknown","lastCheckedAt":None,"latencyMs":None},
        }
        now="2026-09-01 10:00:00"
        self.db_users=[
         {"id":1,"email":"owner@velora.test","fullName":"Owner","role":"super_admin","status":"active","emailVerified":True,"emailVerifiedAt":now,"createdAt":now,"plan":"pro","subscriptionStatus":"active"},
         {"id":2,"email":"sara@velora.test","fullName":"Sara Ahmadi","role":"user","status":"active","emailVerified":False,"emailVerifiedAt":None,"createdAt":"2026-09-04 12:00:00","plan":"free","subscriptionStatus":"none"},
         {"id":3,"email":"reza@velora.test","fullName":"Reza Karami","role":"user","status":"suspended","emailVerified":True,"emailVerifiedAt":now,"createdAt":"2026-08-20 08:00:00","plan":"pro","subscriptionStatus":"past_due"},
         {"id":4,"email":"admin4@velora.test","fullName":"Self Admin","role":"admin","status":"active","emailVerified":True,"emailVerifiedAt":now,"createdAt":"2026-07-01 08:00:00","plan":"pro","subscriptionStatus":"active"},
         {"id":5,"email":"mina@velora.test","fullName":"Mina Nouri","role":"user","status":"active","emailVerified":False,"emailVerifiedAt":None,"createdAt":"2026-09-05 09:30:00","plan":None,"subscriptionStatus":None,"subscriptionAvailable":False},
        ]
HTTPServer=Srv
HTTPServer(("127.0.0.1",8141),H).serve_forever()
