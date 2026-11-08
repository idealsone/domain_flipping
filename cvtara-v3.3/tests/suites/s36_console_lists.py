"""Owner console lists are paged on the server: every list sends one page (20 rows unless the owner picks 10, 50 or 100),
the total, and runs its search, filters, date range and sort in the database. The dashboard and the live card no longer
carry user lists, and the page-keyword checklist and Search Console rows arrive a page at a time."""
NAME = "Owner console lists, one page at a time"


def run(ctx, t):
    if not ctx.preview: return
    ops = open(ctx.app + "/resources/console/ops.js", encoding="utf-8").read()
    t.check("a reload reopens a Settings sub-page (?tab=integrations), not the dashboard", "const first = nav(tab) || sub(tab) || nav('dashboard')" in ops)
    db = ctx.db(); o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
    for i in range(45):
        db.q("insert into users (name, email, password, created_at, updated_at) values (?, ?, 'x', ?, ?)", f"Pager Person {i:02d}", f"pager-{i:02d}@test.local", f"2026-09-{1 + i % 28:02d} 10:00:00", "2026-09-01 10:00:00")

    s, j = o.api("GET", "admin/users?q=pager-")
    t.check("users come 20 to a page with the total", s == 200 and len(j["users"]) == 20 and j["total"] == 45 and j["usersTotal"] == 45 and j["pages"] == 3 and j["per"] == 20, (s, j.get("total"), len(j.get("users", []))))
    s, j = o.api("GET", "admin/users?q=pager-&page=3")
    t.check("the last page holds the rest", s == 200 and len(j["users"]) == 5 and j["page"] == 3, len(j.get("users", [])))
    s, j = o.api("GET", "admin/users?q=pager-&page=99")
    t.check("a page past the end shows the last page", s == 200 and j["page"] == 3 and len(j["users"]) == 5, j.get("page"))
    s, j = o.api("GET", "admin/users?q=pager-&per=50")
    t.check("the owner can pick 50 rows a page", s == 200 and len(j["users"]) == 45 and j["per"] == 50)
    s, j = o.api("GET", "admin/users?q=pager-&per=5000")
    t.check("an unlisted page size falls back to 20", s == 200 and j["per"] == 20 and len(j["users"]) == 20)
    s, j = o.api("GET", "admin/users?q=pager-&sort=name&dir=asc&per=10")
    names = [u["name"] for u in j.get("users", [])]
    t.check("sorting by name runs on the server", s == 200 and names == sorted(names) and names[0] == "Pager Person 00", names[:3])
    s, j = o.api("GET", "admin/users?q=pager-&sort=password&dir=asc")
    t.check("an unknown sort column is ignored", s == 200 and j["sort"] == "joined")
    s, j = o.api("GET", "admin/users?q=pager-&from=2026-09-01&to=2026-09-02")
    t.check("joined-date range filter", s == 200 and j["total"] == 4, j.get("total"))
    s, j = o.api("GET", "admin/users?q=" + "%25")
    t.check("a % in the search is treated as text", s == 200 and j["total"] == 0, j.get("total"))
    s, j = o.api("GET", "admin/users?f=blocked&q=pager-")
    t.check("the status filter still applies", s == 200 and j["total"] == 0 and {"totalUsers", "paidUsers", "blockedUsers"} <= set(j))

    s, j = o.api("GET", "admin/dashboard?days=30")
    t.check("the dashboard sends numbers, not a user list", s == 200 and j["users"] == [] and j["totalUsers"] >= 45 and len(j["activity"]) <= 20, (s, type(j.get("users"))))
    s, j = o.api("GET", "admin/dashboard?days=30")
    t.check("a cached dashboard keeps its lists (the cache stores plain data)", s == 200 and isinstance(j["activity"], list) and isinstance(j["purchases"], list) and isinstance(j["stages"], list), str(j.get("activity"))[:80])
    s, j = o.api("GET", "admin/live")
    t.check("the live card asks for five counts", s == 200 and {"activeUsers", "resumesToday", "revenueTodayPaise", "scansToday", "signupsToday", "totalUsers"} <= set(j), j)

    for path, key in (("admin/purchases", "purchases"), ("admin/reviews", "reviews"), ("admin/email/jobs", "jobs"), ("admin/errors", "errors")):
        s, j = o.api("GET", path + "?per=10")
        t.check(f"{path} is paged", s == 200 and key in j and len(j[key]) <= 10 and {"total", "page", "per", "pages"} <= set(j) and j["per"] == 10, (s, str(j)[:120]))
    s, j = o.api("GET", "admin/reviews?status=published")
    t.check("reviews filter by tab and count every tab", s == 200 and all(r["status"] == "published" for r in j["reviews"]) and "counts" in j)
    s, j = o.api("GET", "admin/purchases?status=paid&provider=razorpay&from=2026-01-01")
    t.check("orders filters run on the server", s == 200 and all(p["status"] == "paid" and p["provider"] == "razorpay" for p in j["purchases"]))

    s, j = o.api("GET", "admin/page-audit")
    t.check("the page keyword checklist comes 20 pages at a time", s == 200 and len(j["pages"]) == 20 and j["total"] > 100 and j["sections"], (s, j.get("total")))
    sec = next(iter(j["sections"]))
    s, j2 = o.api("GET", f"admin/page-audit?section={sec}&sort=score&dir=desc&per=50")
    scores = [p["score"] for p in j2["pages"]]
    t.check("checklist filters by section and sorts by score", s == 200 and j2["total"] == j["sections"][sec] and scores == sorted(scores, reverse=True), (j2.get("total"), j["sections"][sec]))
    s, j = o.api("GET", "admin/page-audit?q=zzzz-no-such-page")
    t.check("checklist search", s == 200 and j["total"] == 0 and j["pages"] == [])
    s, j = o.api("GET", "admin/seo")
    t.check("the SEO editor no longer carries the checklist", s == 200 and "routes" in j and "pages" not in j)

    rows = [{"key": f"resume query {i}", "clicks": i, "impressions": 100 + i, "ctr": 0.01, "position": 5} for i in range(60)]
    o.api("POST", "admin/search/import", {"dimension": "query", "startDate": "2026-10-01", "endDate": "2026-10-20", "rows": rows})
    s, j = o.api("GET", "admin/search/rows?dimension=query")
    t.check("Search Console rows come a page at a time, most clicks first", s == 200 and j["total"] == 60 and len(j["rows"]) == 20 and j["rows"][0]["clicks"] == 59, (s, j.get("total")))
    s, j = o.api("GET", "admin/search/rows?dimension=query&q=query%205&sort=key&dir=asc")
    t.check("Search Console rows search and sort", s == 200 and j["total"] == 11 and j["rows"][0]["key"] == "resume query 5", j.get("total"))

    s, j = o.api("GET", "admin/referrals")
    t.check("Referral Ops sends rules and counts, not whole lists", s == 200 and "rules" in j and set(j["counts"]) == {"affiliates", "withdrawals", "profiles", "creators"} and "affiliates" not in j)
    for kind in ("withdrawals", "profiles", "creators", "affiliates"):
        s, j = o.api("GET", f"admin/referrals/list/{kind}?per=10")
        t.check(f"referral {kind} list is paged", s == 200 and len(j["rows"]) <= 10 and "total" in j, (s, str(j)[:100]))
    s, j = o.api("GET", "admin/referrals/list/affiliates?q=pager-0")
    t.check("affiliate balances are worked out for the page only", s == 200 and all("availablePaise" in a for a in j["rows"]))

    cust = ctx.client(); cust.register("pager-cust@test.local")
    for path in ("admin/users", "admin/live", "admin/page-audit", "admin/search/rows", "admin/referrals/list/affiliates"):
        t.check(f"customers cannot read {path}", cust.api("GET", path)[0] == 403)
    db.q("delete from users where email like 'pager-%@test.local' and email != 'pager-cust@test.local'")
