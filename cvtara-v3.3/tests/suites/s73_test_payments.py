"""3.61.0 test payment review (V4.8 §6; owner OK 12 Nov 2026 to read live payments): every order up to 9 Nov (India
time) with a suggestion from fixed rules; the owner marks each test or real; orders marked test leave revenue figures
but are never deleted or changed; owner only; downloadable."""
NAME = "Test payment review up to 9 November"


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    inst = Instance(ctx.app, ctx.public, preview=True).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        for e in ("asha.k@gmail.test.local", "qa-test@cvtara.in", "rahul.m@mail.local"):
            c = Client(inst.base); c.register(e)
        uid = lambda e: db.q("select id from users where email=?", e)[0][0]
        admin = db.q("select id from users where is_admin=1 limit 1")[0][0]
        ins = lambda i, owner, amt, st, prov, live, when, refunded=0: db.q(
            "insert into purchases(id,owner_id,plan,amount_paise,refunded_paise,status,provider,is_live,limits_json,access_days,purchased_at,created_at,updated_at) values(?,?,?,?,?,?,?,?,'{}',30,?,?,?)",
            i, owner, "job-hunt", amt, refunded, st, prov, live, when if st != "created" else None, when, when)
        db.q("delete from purchases")  # start from a clean order list (the preview seeds a demo order)
        ins("p-real", uid("rahul.m@mail.local"), 29900, "paid", "razorpay", 1, "2026-11-05 06:00:00")
        ins("p-testmode", uid("rahul.m@mail.local"), 29900, "paid", "razorpay", 0, "2026-11-05 07:00:00")
        ins("p-owner", admin, 900, "paid", "razorpay", 1, "2026-11-02 05:00:00")
        ins("p-email", uid("qa-test@cvtara.in"), 59900, "paid", "phonepe", 1, "2026-11-06 05:00:00")
        ins("p-unpaid", uid("asha.k@gmail.test.local"), 29900, "created", "razorpay", 1, "2026-11-07 05:00:00")
        ins("p-grant", uid("asha.k@gmail.test.local"), 0, "paid", "manual", 1, "2026-11-07 06:00:00")
        ins("p-late", uid("rahul.m@mail.local"), 29900, "paid", "razorpay", 1, "2026-11-09 18:40:00")  # 10 Nov 00:10 IST
        before = db.q("select count(*), sum(amount_paise) from purchases")[0]

        s, j = o.api("GET", "admin/costs/test-payments?per=50")
        rows = {r["id"]: r for r in j.get("rows", [])}
        t.check("every order up to 9 November India time is listed, nothing after", s == 200 and set(rows) == {"p-real", "p-testmode", "p-owner", "p-email", "p-unpaid", "p-grant"}, (s, list(rows)))
        sug = {k: v["suggested"] for k, v in rows.items()}
        t.check("a live payment from a normal customer looks real", sug.get("p-real") == "real", rows.get("p-real"))
        t.check("test mode, owner account and ₹10 or less, and a test email look like tests", sug.get("p-testmode") == "test" and sug.get("p-owner") == "test" and sug.get("p-email") == "test", sug)
        t.check("the reasons are shown", "test mode" in rows["p-testmode"]["reasons"] and "owner" in rows["p-owner"]["reasons"] and "₹10 or less" in rows["p-owner"]["reasons"] and "email" in rows["p-email"]["reasons"])
        t.check("unfinished checkouts and ₹0 grants are told apart", sug.get("p-unpaid") == "not_paid" and sug.get("p-grant") == "grant")
        t.check("a summary counts them", j["summary"]["orders"] == 6 and j["summary"]["bySuggested"].get("test") == 3 and j["summary"]["bySuggested"].get("real") == 1, j.get("summary"))
        s, j2 = o.api("GET", "admin/costs/test-payments?suggested=test")
        t.check("the list filters by suggestion", s == 200 and j2["total"] == 3 and all(r["suggested"] == "test" for r in j2["rows"]))

        k0 = o.api("GET", "admin/report/kpis?days=30")[1]["netRevenuePaise"]
        s, m = o.api("POST", "admin/costs/test-payments/mark", {"ids": ["p-email", "p-real"], "mark": "test", "note": "owner checked"})
        t.check("the owner marks orders as test", s == 200 and m["marked"] == 2 and db.q("select count(*) from payment_marks where mark='test'")[0][0] == 2, (s, m))
        k1 = o.api("GET", "admin/report/kpis?days=30")[1]["netRevenuePaise"]
        t.check("orders marked test are left out of revenue", k0 - k1 == 59900 + 29900, (k0, k1))
        t.check("no order is deleted or changed", db.q("select count(*), sum(amount_paise) from purchases")[0] == before and db.q("select status from purchases where id='p-real'")[0][0] == "paid")
        o.api("POST", "admin/costs/test-payments/mark", {"ids": ["p-real"], "mark": "real"})
        t.check("a mark can be changed back to real", o.api("GET", "admin/report/kpis?days=30")[1]["netRevenuePaise"] == k0 - 59900)
        s, _ = o.api("POST", "admin/costs/test-payments/mark", {"ids": ["p-late"], "mark": "test"})
        t.check("orders after 9 November cannot be marked here", s == 422, s)
        t.check("each mark is in the audit log", db.q("select count(*) from audit_logs where action='payment_marked'")[0][0] >= 3)
        s, j = o.api("GET", "admin/costs/test-payments?mark=test")
        t.check("the list shows your marks", s == 200 and [r["id"] for r in j["rows"]] == ["p-email"] and j["rows"][0]["note"] == "owner checked", j.get("rows"))
        st, h, b = o.raw("GET", "/api/admin/costs/test-payments?export=csv")
        t.check("and downloads with the suggestion, the reasons and your mark", st == 200 and b"Suggested" in b and b"Your mark" in b and b"p-email" in b, (st, b[:200]))

        c = Client(inst.base); c.register("tp-cust@test.local")
        t.check("customers cannot read it", c.api("GET", "admin/costs/test-payments")[0] in (403, 404) and c.api("POST", "admin/costs/test-payments/mark", {"ids": ["p-real"], "mark": "test"})[0] in (403, 404))
        js = open(ctx.app + "/resources/console/costs.js", encoding="utf8").read()
        t.check("the Costs & margin page has the review", "Test payments up to 9 November" in js and "data-cxt-accept" in js)
    finally:
        inst.stop()
