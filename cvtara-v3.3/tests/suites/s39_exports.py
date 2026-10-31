"""Every owner-console list and report downloads as CSV, Excel (.xlsx) or PDF with the same search, filters, date range
and sort as the page, and the file holds every matching row, not only the page on screen."""
import csv, io, zipfile
NAME = "Owner downloads: CSV, Excel and PDF with dates and filters"


def run(ctx, t):
    if not ctx.preview: return
    db = ctx.db(); o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
    for i in range(45):
        db.q("insert into users (name, email, password, created_at, updated_at) values (?, ?, 'x', ?, ?)", f"Export Person {i:02d}", f"xport-{i:02d}@test.local", f"2026-09-{1 + i % 28:02d} 10:00:00", "2026-09-01 10:00:00")
    db.q("update users set name='=HYPERLINK(1)' where email='xport-44@test.local'")

    def get(path):
        s, h, b = o.raw("GET", "/api/admin/" + path, None, {"Accept": "application/json"})
        return s, {k.lower(): v for k, v in h.items()}, b

    def rows_of(b):
        return list(csv.reader(io.StringIO(b.decode("utf-8-sig"))))

    # CSV: all rows, not one page; filters and dates respected
    s, h, b = get("users?q=xport-&export=csv")
    rows = rows_of(b)
    t.check("users CSV holds every matching row (45), not the 20 on screen", s == 200 and "text/csv" in h.get("content-type", "") and len(rows) == 46, (s, len(rows), b[:150]))
    t.check("users CSV has readable headings and the page's columns", rows and rows[0][:3] == ["Id", "Name", "Email"] and "Subscription" in rows[0] and "Password" not in rows[0], rows[0] if rows else None)
    t.check("download is named after the list", "attachment" in h.get("content-disposition", "") and "cvtara-users-" in h.get("content-disposition", ""), h.get("content-disposition"))
    t.check("formulas in exported names are made inert", any(r[1] == "'=HYPERLINK(1)" for r in rows[1:]), [r[1] for r in rows[1:4]])
    s, h, b = get("users?q=xport-&from=2026-09-01&to=2026-09-02&export=csv")
    t.check("date range applies to the download", s == 200 and len(rows_of(b)) == 1 + 4, len(rows_of(b)))
    s, h, b = get("users?q=xport-&f=blocked&export=csv")
    t.check("filters apply to the download", s == 200 and len(rows_of(b)) == 1, len(rows_of(b)))
    s, h, b = get("users?q=xport-&sort=name&dir=asc&export=csv")
    names = [r[1] for r in rows_of(b)[1:]]
    t.check("sort order carries into the download", s == 200 and names[:2] == ["'=HYPERLINK(1)", "Export Person 00"], names[:3])
    s, j = o.api("GET", "admin/users?q=xport-")
    t.check("the page itself still answers JSON, 20 rows", s == 200 and len(j["users"]) == 20 and j["total"] == 45)

    # Excel
    s, h, b = get("users?q=xport-&export=xlsx")
    ok = s == 200 and "spreadsheetml" in h.get("content-type", "")
    z = zipfile.ZipFile(io.BytesIO(b)) if ok else None
    sheet = z.read("xl/worksheets/sheet1.xml").decode() if z else ""
    t.check("Excel download is a real .xlsx workbook", ok and "xl/workbook.xml" in z.namelist() and "cvtara-users-" in h.get("content-disposition", "") and h.get("content-disposition", "").rstrip('"').endswith(".xlsx"), (s, h.get("content-type")))
    t.check("Excel sheet holds the header plus every row", sheet.count("<row ") == 46 and "Export Person 00" in sheet, sheet.count("<row "))
    about = z.read("xl/worksheets/sheet2.xml").decode() if z else ""
    t.check("Excel 'About' sheet records the filters", "Search: xport-" in about and "Rows: 45" in about, about[:300])

    # PDF
    s, h, b = get("users?q=xport-&export=pdf")
    t.check("PDF download is a PDF", s == 200 and b[:5] == b"%PDF-" and "application/pdf" in h.get("content-type", ""), (s, b[:40]))

    # every paged console list exports
    for path in ("purchases", "reviews", "email/jobs", "errors", "page-audit", "search/rows?dimension=query", "referrals/list/withdrawals", "referrals/list/profiles",
                 "referrals/list/creators", "referrals/list/affiliates", "support", "outreach/contacts", "campaigns/contacts", "growth/reports", "growth/seo/proposals"):
        s, h, b = get(path + ("&" if "?" in path else "?") + "export=csv")
        t.check(f"{path} downloads as CSV", s == 200 and "text/csv" in h.get("content-type", "") and len(rows_of(b)) >= 1, (s, b[:160]))
    s, h, b = get("email/jobs?export=pdf&from=2026-01-01&to=2026-12-31")
    t.check("email queue downloads as PDF with a date range", s == 200 and b[:5] == b"%PDF-", (s, b[:120]))
    s, h, b = get("errors?export=xlsx&from=2020-01-01")
    t.check("error log downloads as Excel", s == 200 and b[:2] == b"PK", (s, b[:120]))

    # Reports & Export builder: new data sets and formats
    s, j = o.api("GET", "admin/report/meta")
    t.check("report catalogue has the new reports", s == 200 and {"daily", "traffic", "sources", "activity", "reviews", "support", "growth"} <= set(j["datasets"]), list(j.get("datasets", {})))
    for ds in ("daily", "traffic", "sources", "activity", "reviews", "support", "growth"):
        s, j = o.api("GET", f"admin/report?dataset={ds}&per=10&from=2026-10-01&to=2026-10-31")
        t.check(f"report {ds} runs for a date range", s == 200 and "rows" in j and "total" in j, (s, str(j)[:150]))
    s, j = o.api("GET", "admin/report?dataset=daily&from=2026-10-01&to=2026-10-31&per=100")
    t.check("daily summary has one row per day", s == 200 and j["total"] == 31 and j["rows"][0]["date"] == "2026-10-31" and j["range"] == {"from": "2026-10-01", "to": "2026-10-31"}, (j.get("total"), j.get("range")))
    s, h, b = get("report?dataset=daily&from=2026-10-01&to=2026-10-07&format=csv")
    rows = rows_of(b)
    t.check("daily summary CSV: 7 days plus a total line", s == 200 and len(rows) == 9 and rows[-1][0] == "Total", rows[-2:] if rows else b[:100])
    for fmt, magic in (("xlsx", b"PK"), ("pdf", b"%PDF-")):
        s, h, b = get(f"report?dataset=orders&format={fmt}&from=2026-01-01")
        t.check(f"orders report downloads as {fmt}", s == 200 and b[:len(magic)] == magic, (s, b[:120]))
    s, j = o.api("GET", "admin/report?dataset=daily&from=2026-10-10&to=2026-10-01")
    t.check("a backwards date range is refused", s == 422)

    # limits and access
    for i in range(60):
        db.q("insert into error_logs (fingerprint, level, source, message, occurrences, first_seen, last_seen) values (?, 'info', 'server', ?, 1, '2026-10-02 10:00:00', '2026-10-02 10:00:00')", f"fp-x-{i}", f"export test {i}")
    s, h, b = get("errors?q=export%20test&from=2026-10-01&to=2026-10-03&export=csv")
    t.check("error log download uses the date range, not the last-30-days window", s == 200 and len(rows_of(b)) == 61, len(rows_of(b)))
    cust = ctx.client(); cust.register("xport-cust@test.local")
    for path in ("users?export=csv", "purchases?export=xlsx", "report?dataset=daily&format=pdf"):
        s, _, _ = cust.raw("GET", "/api/admin/" + path, None, {"Accept": "application/json"})
        t.check(f"customers cannot download {path.split('?')[0]}", s == 403, s)
    ops = open(ctx.app + "/resources/console/ops.js", encoding="utf-8").read()
    t.check("every console grid gets the Download menu and a period picker", "spec.download === false ? '' : dlHtml()" in ops and "data-grange" in ops)
    db.q("delete from error_logs where fingerprint like 'fp-x-%'")
    db.q("delete from users where email like 'xport-%@test.local' and email != 'xport-cust@test.local'")
