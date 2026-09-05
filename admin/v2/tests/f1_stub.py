import json, os
from http.server import HTTPServer, SimpleHTTPRequestHandler
import os
REPO=os.path.abspath(os.path.join(os.path.dirname(__file__),"..","..",".."))
ROOT=os.path.dirname(os.path.abspath(__file__))
def loadmode(): return json.load(open(os.path.join(ROOT,"mode.json")))
PERMS_SUPER=["overview.view","users.view","users.suspend","users.activate","users.manage_subscription","users.verify_email","audit.view","audit.view_sensitive","system.health.view","system.logs.view","settings.view","feature_flags.view","billing.view","integrations.view","aiManage","analytics.view","users.change_role","system.settings.manage","feature_flags.edit","integrations.manage","aiRouteManage"]
PERMS_ADMIN=[p for p in PERMS_SUPER if p not in ("users.change_role","audit.view_sensitive","system.settings.manage","feature_flags.edit","integrations.manage","aiRouteManage")]
PERMS_LIMITED=["overview.view","users.view"]
class H(SimpleHTTPRequestHandler):
    def __init__(self,*a,**kw): super().__init__(*a,directory=REPO,**kw)
    def log_message(self,*a): pass
    def _j(self,code,obj):
        b=json.dumps(obj).encode(); self.send_response(code)
        self.send_header("Content-Type","application/json"); self.send_header("Content-Length",str(len(b))); self.end_headers(); self.wfile.write(b)
    def do_GET(self):
        if self.path.split("?")[0] in ("/login","/login/"):
            b=b"<html><body>login</body></html>"; self.send_response(200)
            self.send_header("Content-Type","text/html"); self.send_header("Content-Length",str(len(b))); self.end_headers(); self.wfile.write(b); return
        if self.path.startswith("/api/v1/admin/me"):
            m=loadmode(); me=None
            if m["mode"]=="admin": me={"userId":4,"role":"admin","isSuperAdmin":False,"panel":True,"permissions":PERMS_ADMIN}
            elif m["mode"]=="super": me={"userId":5,"role":"super_admin","isSuperAdmin":True,"panel":True,"permissions":PERMS_SUPER,"recentAdminActions":[]}
            elif m["mode"]=="limited": me={"userId":7,"role":"admin","isSuperAdmin":False,"panel":True,"permissions":PERMS_LIMITED,"recentAdminActions":[]}
            elif m["mode"]=="user403": self._j(403,{"status":"error","error":{"code":"ADMIN_REQUIRED"}}); return
            elif m["mode"]=="panel_false": me={"userId":9,"role":"user","isSuperAdmin":False,"panel":False,"permissions":[]}
            else: self._j(500,{"status":"error","error":{"code":"INTERNAL_ERROR"}}); return
            rAA=[{"action":"user.verify_email","targetType":"user","targetId":2,"result":"success","summary":"User #2 email verified by admin","createdAt":"2026-09-05 20:30:00"}] if m["mode"]=="admin" else []
            self._j(200,{"me":me,"recentAdminActions":rAA}); return
        super().do_GET()
    def do_POST(self):
        if self.path.startswith("/api/v1/auth/refresh"):
            if loadmode()["mode"]=="noauth": self._j(401,{"status":"error","error":{"code":"UNAUTHORIZED"}})
            else: self._j(200,{"tokens":{"accessToken":"stub-token","user":{"id":4,"role":"admin","locale":"fa"}}})
        elif self.path.startswith("/api/v1/auth/logout"):
            m=loadmode(); m["logout_called"]=True; json.dump(m,open(os.path.join(ROOT,"mode.json"),"w")); self._j(200,{"ok":True})
        else: self._j(404,{})
HTTPServer(("127.0.0.1",8141),H).serve_forever()
