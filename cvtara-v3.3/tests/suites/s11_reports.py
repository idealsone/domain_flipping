"""Owner reports and export, server-side totals, no-refund wording, retention and the smaller audit fixes."""
import csv, io
NAME = "Reports, policies and housekeeping"


def run(ctx, t):
    if not ctx.preview: return
    db = ctx.db(); o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
    cust = ctx.client(); cust.register("rep-a@test.local")

    # reports: every data set, filters, pagination, exports
    s, j = o.api("GET", "admin/report/meta"); t.check("report catalogue", s == 200 and set(j["datasets"]) >= {"orders", "users", "commissions", "withdrawals", "errors", "mail"}, j.get("datasets", {}).keys() if s == 200 else j)
    for ds in ("orders", "users", "commissions", "withdrawals", "errors", "mail"):
        s, j = o.api("GET", f"admin/report?dataset={ds}&per=10"); t.check(f"report {ds} runs", s == 200 and "rows" in j and "total" in j, f"{s} {str(j)[:100]}")
    s, j = o.api("GET", "admin/report?dataset=orders&per=10&status=paid"); t.check("orders filter by status is applied on the server", s == 200 and all(r["status"] == "paid" for r in j["rows"]))
    s, j = o.api("GET", "admin/report?dataset=orders&per=10&columns=order,amount_inr"); t.check("column choice is respected", j["columns"] and [c["key"] for c in j["columns"]] == ["order", "amount_inr"])
    s, j = o.api("GET", "admin/report?dataset=users&status=blocked"); t.check("users filter by blocked", s == 200 and j["total"] == 0, j.get("total"))
    s, j = o.api("GET", "admin/users?f=blocked"); t.check("Users view filters on the server", s == 200 and j["usersTotal"] == 0 and "blockedUsers" in j, j.get("usersTotal"))
    s, j = o.api("GET", "admin/users?f=normal"); t.check("normal-user filter counts everyone without a purchase", s == 200 and j["usersTotal"] >= 1)
    s, h, b = o.raw("GET", "/api/admin/report?dataset=users&format=csv&columns=name,email", None, {"Accept": "application/json"})
    rows = list(csv.reader(io.StringIO(b.decode("utf-8-sig"))))
    t.check("CSV export has a header and rows", s == 200 and "csv" in h.get("Content-Type", "") and rows[0] == ["Name", "Email"] and len(rows) > 1, rows[:2])
    s, h, b = o.raw("GET", "/api/admin/report?dataset=orders&format=xls", None, {"Accept": "application/json"}); t.check("Excel export", s == 200 and b"<table" in b)
    cust.api("POST", "profile", {"name": "=HYPERLINK(\"http://evil\")", "experienceLevel": "experienced", "profession": "HR", "category": "Other", "experienceYears": 1, "marketingConsent": False})
    db.q("update users set name='=SUM(1+1)' where email='rep-a@test.local'")
    s, h, b = o.raw("GET", "/api/admin/report?dataset=users&format=csv&columns=name,email&q=rep-a", None, {"Accept": "application/json"})
    t.check("spreadsheet formulas in names are neutralised", b"'=SUM" in b and b",=SUM" not in b and not b.decode("utf-8-sig").split("\n")[1].startswith("=SUM"), b[:120])
    t.check("customers cannot run reports", cust.api("GET", "admin/report?dataset=users")[0] == 403)
    t.check("customers cannot export", cust.raw("GET", "/api/admin/report?dataset=users&format=csv", None, {"Accept": "application/json"})[0] == 403)
    s, j = o.api("GET", "admin/report/kpis?days=30"); t.check("dashboard numbers come from the server", s == 200 and {"users", "netRevenuePaise", "series", "conversionPct", "paidUsers"} <= set(j) and len(j["series"]) == 30, list(j))
    s, j = o.api("GET", "admin/report?dataset=orders&per=10&page=2"); t.check("paging works and reports the true total", s == 200 and j["total"] >= len(j["rows"]) and j["page"] == 2)

    # no-refund policy everywhere
    c = ctx.client()
    for p in ("/refund.html",):
        s, _, html = c.page(p); low = html.lower()
        t.check(f"{p} states the 7-day refund if unused, once per customer", "within 7 days" in low and "once per customer" in low and "non-refundable" not in low, s)
    s, _, html = c.page("/checkout.html"); t.check("checkout tells customers about the 7-day refund", "within 7 days" in html.lower())
    s, _, html = c.page("/terms.html"); t.check("terms point to the refund policy", "within 7 days" in html.lower() and "non-refundable" not in html.lower())

    # F-25 verified review needs a real paying customer
    body = {"name": "Priya", "content": "Great product, helped me a lot.", "rating": 5, "source": "verified", "consent": True, "consentReference": "email on 2026-10-01", "customerEmail": "rep-a@test.local"}
    s, j = o.api("POST", "admin/feedback/add", body); t.check("verified review for a non-paying account is refused", s == 422, f"{s} {j}")
    s, j = o.api("POST", "admin/feedback/add", dict(body, customerEmail="nobody@test.local")); t.check("verified review for an unknown email is refused", s == 422)
    o.api("POST", "admin/users/grant", {"userId": db.q("select id from users where email='rep-a@test.local'")[0][0], "plan": "job-hunt", "note": "test", "amount": 299, "sendEmail": False})
    s, j = o.api("POST", "admin/feedback/add", body); t.check("verified review for a paying customer is accepted", s == 200, f"{s} {j}")

    # retention
    db.q("insert into mail_outbox (id, dedupe, trigger, recipient, subject, body, status, due_at, sent_at, created_at, updated_at) values ('old1','d-old1','x','a@b.c','s','SECRET BODY','sent',datetime('now','-60 day'),datetime('now','-60 day'),datetime('now','-60 day'),datetime('now','-60 day'))")
    ctx.inst.artisan("tinker", "--execute", "App\\Services\\Retention::run();")
    t.check("old sent mail bodies are blanked", db.q("select body from mail_outbox where id='old1'")[0][0] == "")

    # marketing / copy
    s, _, html = ctx.client().page("/login"); t.check("sign-in page says nothing is free", "free account" not in html.lower())

    # daily backup
    out = ctx.inst.artisan("cvtara:backup")
    import glob, gzip, os
    files = glob.glob(os.path.join(ctx.inst.app, "storage", "app", "backups", "cvtara-*.sql.gz"))
    t.check("backup command writes a compressed SQL file", out.returncode == 0 and len(files) == 1, (out.stdout + out.stderr)[-200:])
    if files:
        sql = gzip.open(files[0], "rt", encoding="utf8").read()
        t.check("backup contains users and purchases but no sessions", "INSERT INTO \"users\"" in sql and "CREATE TABLE \"purchases\"" in sql and "\"sessions\"" not in sql)
        t.check("backup file is private (owner-only)", (os.stat(files[0]).st_mode & 0o077) == 0)
