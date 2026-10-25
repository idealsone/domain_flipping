"""Owner console pages load: the site-wide page limit (240 a minute) no longer counts the API calls the console makes, so a minute
of clicking around never leaves a console page stuck on "Loading…". Visitors are still limited, and visitors' pages no longer ask
for an account allowance they cannot have (a 401 on every page view). The owner's
own scripts (which name the writing provider) are served only to a signed-in owner."""
import os, time
NAME = "Owner console load"


def run(ctx, t):
    o = ctx.client(); o.raw("GET", "/preview/go/admin")
    codes = [o.api("GET", "admin/dashboard?days=30")[0] for _ in range(260)]
    t.check("260 console data calls in a minute all load (no 'Too many requests')", codes.count(200) == 260, sorted(set(codes)))
    s, _, page = o.page("/admin.html")
    t.check("the console page shares repeat data calls and retries a busy answer", s == 200 and "Retry-After" in page and "c.clear()" in page, s)
    for name in ("owner", "advanced"):
        t.check(f"console/{name}.js loads for the owner only", o.raw("GET", f"/console/{name}.js")[0] == 200 and ctx.client().raw("GET", f"/console/{name}.js")[0] == 404)
    t.check("the owner scripts are no longer public files", all(not os.path.exists(os.path.join(ctx.public, f)) for f in ("owner-console.js", "owner-advanced.js")))
    g = ctx.client(); s, _, home = g.page("/templates.html"); s2, _, mine = o.page("/templates.html")
    t.check("visitors' pages skip the account check; signed-in pages are marked for it", s == 200 and "data-signed-in" not in home and s2 == 200 and 'data-signed-in="1"' in mine)
    v = ctx.client(); codes = [v.raw("GET", "/login")[0] for _ in range(250)]
    t.check("visitors are still limited to 240 pages a minute", 0 < codes.count(200) <= 240 and codes[-1] == 429, (codes.count(200), sorted(set(codes))))
    time.sleep(61)  # give the next suites this machine's visitor allowance back
