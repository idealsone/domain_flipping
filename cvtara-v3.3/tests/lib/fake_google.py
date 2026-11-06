"""A tiny stand-in for Google's token, Search Console, Indexing API and the IndexNow endpoint, so fast indexing can be tested offline."""
import json, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


class FakeGoogle:
    def __init__(self):
        self.calls, self.indexnow, self.published, self.sitemaps = [], [], [], []
        self.deny_indexing = False
        self.sa_rows = {}  # rows to answer per dimension list, e.g. {"query,page": [...]}
        self.inspected = []  # URL Inspection calls; answer PASS unless the url is in not_indexed
        self.not_indexed = set()
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
                if self.path == "/v1/urlInspection/index:inspect":
                    url = json.loads(raw)["inspectionUrl"]; outer.inspected.append(url)
                    bad = any(url.endswith(p) for p in outer.not_indexed)
                    return self._send(200, {"inspectionResult": {"indexStatusResult": {"verdict": "NEUTRAL" if bad else "PASS", "coverageState": "Crawled - currently not indexed" if bad else "Submitted and indexed",
                        "robotsTxtState": "ALLOWED", "indexingState": "INDEXING_ALLOWED", "pageFetchState": "SUCCESSFUL", "lastCrawlTime": "2026-11-01T10:00:00Z", "googleCanonical": url}}})
                if self.path.endswith("/searchAnalytics/query"):
                    dims = ",".join(json.loads(raw or b"{}").get("dimensions", []))
                    if dims in outer.sa_rows: return self._send(200, {"rows": outer.sa_rows[dims]})
                    return self._send(200, {"rows": [{"keys": ["resume builder"], "clicks": 3, "impressions": 90, "ctr": 0.033, "position": 11.2}]})
                return self._send(404, {"error": {"message": "not found"}})

            def do_GET(self):
                outer.calls.append(("GET", self.path))
                if self.headers.get("Authorization") != "Bearer g_tok": return self._send(401, {})
                if self.path.endswith("/sitemaps"):
                    return self._send(200, {"sitemap": [{"path": "https://cvtara.com/sitemap.xml", "lastSubmitted": "2026-11-01T00:00:00Z", "lastDownloaded": "2026-11-02T00:00:00Z", "isPending": False, "errors": "0", "warnings": "1", "contents": [{"type": "web", "submitted": "596"}]}]})
                return self._send(404, {"error": {"message": "not found"}})

            def do_PUT(self):
                self._body(); outer.calls.append(("PUT", self.path))
                if self.headers.get("Authorization") != "Bearer g_tok": return self._send(401, {})
                outer.sitemaps.append(self.path); self.send_response(204); self.end_headers()

        self.srv = ThreadingHTTPServer(("127.0.0.1", 0), H)
        self.base = "http://127.0.0.1:%d" % self.srv.server_address[1]
        threading.Thread(target=self.srv.serve_forever, daemon=True).start()

    def stop(self): self.srv.shutdown()
