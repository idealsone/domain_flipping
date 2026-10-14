import http.cookiejar, json, re, urllib.error, urllib.parse, urllib.request


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None


class Client:
    def __init__(self, base):
        self.base, self.csrf = base, None
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), _NoRedirect)

    def raw(self, method, path, body=None, headers=None):
        h = dict(headers or {}); data = None
        if body is not None: data = json.dumps(body).encode(); h["Content-Type"] = "application/json"
        try: r = self.op.open(urllib.request.Request(self.base + path, data=data, method=method, headers=h), timeout=30)
        except urllib.error.HTTPError as e: r = e
        return r.status, dict(r.headers), r.read()

    def page(self, path):
        s, h, b = self.raw("GET", path)
        m = re.search(rb'name="csrf-token" content="([^"]+)"', b)
        if m: self.csrf = m.group(1).decode()
        return s, h, b.decode("utf8", "ignore")

    def api(self, method, path, body=None):
        if not self.csrf: self.page("/")
        s, _, b = self.raw(method, "/api/" + path, body, {"Accept": "application/json", "X-CSRF-TOKEN": self.csrf or ""})
        try: return s, json.loads(b)
        except ValueError: return s, {"_raw": b[:200].decode("utf8", "ignore")}
