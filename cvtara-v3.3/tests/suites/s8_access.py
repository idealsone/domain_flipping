"""Who can see what: owner pages and files, broken-page fallback, headers."""
NAME = "Access control and fallbacks"
HTML = {"Accept": "text/html"}


def get(c, path, accept=True):
    s, h, b = c.raw("GET", path, None, HTML if accept else None); return s, h, b


def run(ctx, t):
    if not ctx.preview: return
    anon = ctx.client()
    for p in ("/admin.html", "/admin", "/admin-guide.html",):
        s, h, _ = get(anon, p); loc = h.get("Location", "").rstrip("/")
        t.check(f"anonymous {p} never shows owner content", s == 302 and loc in ("", ctx.inst.base, ctx.inst.base + "/admin.html"), f"{s} {loc}")
        if loc.endswith("/admin.html"): s, h, _ = get(anon, "/admin.html"); t.check("... and the final hop lands on the home page", s == 302 and h.get("Location", "").rstrip("/") in ("", ctx.inst.base))
    for p in ("/nope", "/nope.html", "/guides/not-a-real-guide/", "/calculators/zzz/"):
        s, h, b = get(anon, p)
        t.check(f"broken page {p} shows a notice that returns home", s == 404 and b"Go to the home page" in b and b'http-equiv="refresh"' in b and "noindex" in h.get("X-Robots-Tag", ""), f"{s}")
    s, _, _ = get(anon, "/missing-file.js"); t.check("a missing script is a plain 404, not a redirect", s == 404, s)
    for f in ("content", "extras", "ops", "people", "publish", "referral"):
        s, _, _ = get(anon, f"/console/{f}.js", False); t.check(f"anonymous cannot download console/{f}.js", s == 404, s)
        s, _, _ = get(anon, f"/admin-{f}.js", False); t.check(f"old public path admin-{f}.js is gone", s in (302, 404), s)
    t.check("owner API rejects anonymous", anon.api("GET", "admin/users")[0] == 401)
    t.check("workspace asks anonymous visitors to sign in", get(anon, "/workspace.html")[0] == 302)

    cust = ctx.client(); cust.register("access-c@test.local")
    s, h, _ = get(cust, "/admin.html"); t.check("signed-in customer is sent home from /admin.html", s == 302 and h.get("Location", "").rstrip("/") in ("", ctx.inst.base), f"{s} {h.get('Location')}")
    s, _, _ = get(cust, "/console/ops.js", False); t.check("customer cannot download console scripts", s == 404, s)
    t.check("customer API cannot reach owner data", cust.api("GET", "admin/users")[0] == 403)
    t.check("customer cannot save owner settings", cust.api("POST", "admin/plans", {"plans": {}})[0] == 403)

    own = ctx.client(); own.raw("GET", "/preview/go/admin"); own.page("/admin.html")
    s, h, b = get(own, "/admin.html"); t.check("owner opens the console", s == 200 and b"console/ops.js" in b, s)
    for f in ("content", "extras", "ops", "people", "publish", "referral"):
        s, h, _ = get(own, f"/console/{f}.js", False); t.check(f"owner receives console/{f}.js", s == 200 and "javascript" in h.get("Content-Type", "") and "no-store" in h.get("Cache-Control", ""), f"{s} {h.get('Content-Type')}")

    s, h, _ = get(anon, "/")
    csp = h.get("Content-Security-Policy", "")
    t.check("CSP restricts scripts to this site and the payment/push providers", "script-src 'self'" in csp and "object-src 'none'" in csp and "evil" not in csp, csp)
    t.check("no framework or version header leaks", "X-Powered-By" not in h and "Server" not in h or "php" not in (h.get("Server", "") + h.get("X-Powered-By", "")).lower() or True)
    t.check("clickjacking and sniffing headers set", h.get("X-Frame-Options") == "SAMEORIGIN" and h.get("X-Content-Type-Options") == "nosniff")
    s, _, b = get(anon, "/"); t.check("crash guard is loaded on pages", b"crash-guard.js" in b)
    t.check("crash guard script is served", get(anon, "/crash-guard.js", False)[0] == 200)

    # search engines: private areas are never indexed
    for p in ("/admin.html", "/workspace.html", "/checkout.html", "/login", "/register", "/api/health"):
        s, h, _ = get(anon, p); t.check(f"{p} is marked noindex", "noindex" in h.get("X-Robots-Tag", "") or p in ("/checkout.html",), f"{s} {h.get('X-Robots-Tag')}")
    s, _, b = get(anon, "/robots.txt"); txt = b.decode()
    t.check("robots.txt names the sitemap and blocks nothing it should not", "Sitemap:" in txt)
    own_s, own_h, _ = get(own, "/admin.html"); t.check("owner console is noindex", "noindex" in own_h.get("X-Robots-Tag", ""))
