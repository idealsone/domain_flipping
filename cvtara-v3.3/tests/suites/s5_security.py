"""Runtime checks from the code audit: open redirect, reset-link host, shared CSS for customers."""
import re
NAME = "Security re-checks"


def run(ctx, t):
    if not ctx.preview: return
    db = ctx.db(); c = ctx.client(); c.register("sec1@test.local")
    for nxt in ("/\t/evil.example", "/\n/evil.example", "/%09/evil.example", "//evil.example", "/\\evil.example", "https://evil.example"):
        x = ctx.client(); s, h, _ = x.login("sec1@test.local", "TestPassw0rd!!x", next=nxt)
        loc = h.get("Location", "")
        t.check(f"login ignores unsafe next {nxt!r}", s in (302, 303) and "evil.example" not in loc and "\t" not in loc and "%09" not in loc, f"{s} {loc!r}")
    s, h, _ = ctx.client().login("sec1@test.local", "TestPassw0rd!!x", next="/templates.html")
    t.check("login keeps a safe next path", h.get("Location", "").endswith("/templates.html"), h.get("Location"))
    x = ctx.client(); x.page("/forgot-password")
    _, _, b = x.raw("GET", "/forgot-password"); tok = re.search(rb'name="_token" value="([^"]+)"', b).group(1).decode()
    x._post_raw("/forgot-password", f"_token={tok}&email=sec1%40test.local".encode(), {"Host": "evil.example"})
    ctx.client().raw("GET", "/")
    ctx.inst.artisan("cvtara:cron")
    body = (db.q("select body from mail_outbox where \"trigger\"='password_reset' order by created_at desc limit 1") or [[""]])[0][0]
    link = re.findall(r"https?://[^\s\"<]*reset-password[^\s\"<]*", body)
    t.check("reset link is built from APP_URL, not the Host header", bool(link) and "evil.example" not in link[0], link[:1])
    cust = ctx.client(); cust.login("sec1@test.local", "TestPassw0rd!!x")
    for p in ("/workspace.html", "/templates.html"):
        s, _, html = cust.page(p); t.check(f"{p} loads the shared owner-console.css for customers", s == 200 and "owner-console.css" in html)
