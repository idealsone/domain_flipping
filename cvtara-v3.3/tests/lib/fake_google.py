"""A tiny stand-in for Google's token, Search Console, Indexing API and the IndexNow endpoint, so fast indexing can be tested offline."""
import json, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


class FakeGoogle:
    def __init__(self):
        self.calls, self.indexnow, self.published, self.sitemaps = [], [], [], []
        self.deny_indexing = False
        outer = self

        class H(BaseHTTPRequestHandler):
            def log_message(self, *a): pass

            def _send(self, code, obj):
                b = json.dumps(obj).encode(); self.send_response(code); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)

            def _body(self):
                n = int(self.headers.get("Content-Length", 0)); return self.rfile.read(n) if n else b""

            def do_POST(self):
                raw = self._body(); outer.calls.append(("POST", self.path))
                if self.path == "/token":
                    return self._send(200, {"access_token": "g_tok", "expires_in": 3600})
                if self.path == "/indexnow":
                    outer.indexnow.append(json.loads(raw)); return self._send(202, {})
                if self.headers.get("Authorization") != "Bearer g_tok": return self._send(401, {"error": {"message": "no token"}})
                if self.path == "/v3/urlNotifications:publish":
                    if outer.deny_indexing: return self._send(403, {"error": {"message": "Permission denied. Failed to verify the URL ownership."}})
                    outer.published.append(json.loads(raw)["url"]); return self._send(200, {"urlNotificationMetadata": {}})
                if self.path.endswith("/searchAnalytics/query"):
                    return self._send(200, {"rows": [{"keys": ["resume builder"], "clicks": 3, "impressions": 90, "ctr": 0.033, "position": 11.2}]})
                return self._send(404, {"error": {"message": "not found"}})

            def do_PUT(self):
                self._body(); outer.calls.append(("PUT", self.path))
                if self.headers.get("Authorization") != "Bearer g_tok": return self._send(401, {})
                outer.sitemaps.append(self.path); self.send_response(204); self.end_headers()

        self.srv = ThreadingHTTPServer(("127.0.0.1", 0), H)
        self.base = "http://127.0.0.1:%d" % self.srv.server_address[1]
        threading.Thread(target=self.srv.serve_forever, daemon=True).start()

    def stop(self): self.srv.shutdown()
