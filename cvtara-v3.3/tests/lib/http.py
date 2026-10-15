import http.cookiejar, json, re, time, urllib.error, urllib.parse, urllib.request


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

    def form(self, path, data):
        """POST an HTML form (adds the _token found on `path` first). Returns status, headers, body."""
        self.page(path)
        s, h, b = self.raw("GET", path)
        m = re.search(rb'name="_token" value="([^"]+)"', b)
        body = urllib.parse.urlencode(dict(data, _token=m.group(1).decode() if m else "")).encode()
        return self._post_raw(path, body)

    def _post_raw(self, path, body, headers=None):
        h = {"Content-Type": "application/x-www-form-urlencoded", **(headers or {})}
        try: r = self.op.open(urllib.request.Request(self.base + path, data=body, method="POST", headers=h), timeout=30)
        except urllib.error.HTTPError as e: r = e
        return r.status, dict(r.headers), r.read()

    def register(self, email, password="TestPassw0rd!!x", ref=None):
        if ref: self.raw("GET", "/r/" + ref)
        data = {"name": "Test User", "email": email, "password": password, "password_confirmation": password,
                "experienceLevel": "experienced", "profession": "Recruiter", "category": "HR & Recruitment", "terms": "1"}
        r = self.form("/register", data)
        while r[0] == 429:  # the app allows 5 sign-ups per minute per IP; wait like a real visitor would
            time.sleep(int(r[1].get("Retry-After", "10")) + 1); r = self.form("/register", data)
        self.page("/workspace.html"); return r

    def login(self, email, password, **extra):
        r = self.form("/login", dict({"email": email, "password": password}, **extra)); self.page("/workspace.html"); return r
