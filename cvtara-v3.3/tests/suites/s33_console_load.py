"""Owner console pages load: the site-wide page limit (240 a minute) no longer counts the API calls the console makes, so a minute
of clicking around never leaves a console page stuck on "Loading…". Visitors are still limited. Runs last because it uses up
this machine's visitor allowance for a minute."""
NAME = "Owner console load"


def run(ctx, t):
    o = ctx.client(); o.raw("GET", "/preview/go/admin")
    codes = [o.api("GET", "admin/dashboard?days=30")[0] for _ in range(260)]
    t.check("260 console data calls in a minute all load (no 'Too many requests')", codes.count(200) == 260, sorted(set(codes)))
    s, _, page = o.page("/admin.html")
    t.check("the console page shares repeat data calls and retries a busy answer", s == 200 and "Retry-After" in page and "c.clear()" in page, s)
    v = ctx.client(); codes = [v.raw("GET", "/login")[0] for _ in range(250)]
    t.check("visitors are still limited to 240 pages a minute", 0 < codes.count(200) <= 240 and codes[-1] == 429, (codes.count(200), sorted(set(codes))))
