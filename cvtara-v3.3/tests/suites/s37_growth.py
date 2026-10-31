"""Growth page: month against the target ladder, funnel counts, the 2 PM / 7 PM report (saved and emailed, Mon–Sat only,
once per slot), the AI spending cap, resume segments by college and role, and campaign audiences limited to a segment."""
import json
NAME = "Growth page, reports, Claude cap and segments"


def run(ctx, t):
    db = ctx.db(); o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
    tinker = lambda code: ctx.inst.artisan("tinker", "--execute=" + code)

    s, h, b = o.page("/admin.html")
    t.check("the owner console has a Growth menu item and page", 'data-admin-view="growth"' in b and 'id="admin-growth"' in b and "/console/growth.js" in b)
    t.check("the Growth script is served to the owner only", o.raw("GET", "/console/growth.js")[0] == 200 and ctx.client().raw("GET", "/console/growth.js")[0] in (302, 401, 403, 404))
    t.check("the Growth data is owner-only", ctx.client().api("GET", "admin/growth")[0] in (401, 403))

    # Two buyers this month (one new, one repeat), one checkout that was not paid, two resumes.
    ids = []
    for i, (college, role) in enumerate([("RV College of Engineering", "Data Analyst"), ("PES University", "Java Developer"), ("RV College of Engineering", "Sales Executive")]):
        db.q("insert into users(name,email,password,email_verified_at,created_at,updated_at) values(?,?,'x',datetime('now'),datetime('now'),datetime('now'))", f"Growth {i}", f"growth{i}@test.local")
        uid = db.q("select id from users where email=?", f"growth{i}@test.local")[0][0]; ids.append(uid)
        db.q("insert or ignore into profiles(owner_id,name,marketing_consent,created_at,updated_at) values(?,?,?,datetime('now'),datetime('now'))", uid, f"Growth {i}", 1 if i != 1 else 0)
        db.q("update profiles set marketing_consent=? where owner_id=?", 1 if i != 1 else 0, uid)
        content = {"experience": [{"role": "Intern", "company": f"Acme {i}"}], "education": [{"degree": "B.E.", "school": college}]}
        db.q("insert into resume_drafts(id,owner_id,title,template,role,content_json,created_at,updated_at) values(?,?,?,?,?,?,datetime('now'),datetime('now','+1 minute'))", f"rd-growth-{i}", uid, "Resume", "classic", role, json.dumps(content))
    buy = "insert into purchases(id,owner_id,plan,amount_paise,status,provider,payment_reference,is_live,limits_json,access_days,purchased_at,access_until,created_at,updated_at) values(?,?,?,?,?,'razorpay',?,1,'{}',30,datetime('now',?),datetime('now','+30 days'),datetime('now',?),datetime('now'))"
    db.q(buy, "gp-old", ids[0], "job-hunt", 29900, "paid", "pay_g0", "-70 days", "-70 days")
    db.q(buy, "gp-1", ids[0], "quick", 9900, "paid", "pay_g1", "-1 minutes", "-2 minutes")
    db.q(buy, "gp-2", ids[2], "pro", 59900, "paid", "pay_g2", "-1 minutes", "-2 minutes")
    db.q("insert into purchases(id,owner_id,plan,amount_paise,status,provider,is_live,limits_json,access_days,created_at,updated_at) values('gp-3',?,'quick',9900,'created','razorpay',1,'{}',7,datetime('now'),datetime('now'))", ids[1])

    s, d = o.api("GET", "admin/growth")
    m = d.get("month", {}).get("metrics", {}) if s == 200 else {}
    t.check("the month counts purchases and gross sales from paid orders", s == 200 and m.get("purchases", 0) >= 2 and m.get("grossPaise", 0) >= 69800, f"{s} {m}")
    t.check("a returning buyer counts as a repeat purchase, not a new customer", m.get("repeatPurchases", 0) >= 1, m)
    t.check("checkout opened counts people, paid or not", d["today"]["checkouts"] >= 3 and d["today"]["buyers"] >= 2, d.get("today"))
    t.check("ad spend is shown as unavailable, never guessed", m.get("adSpendPaise", 0) is None)
    t.check("the year plan holds the owner's 12 months and ₹17.5 lakh", len(d["year"]["rows"]) == 12 and d["year"]["totalTarget"] == 13810 and abs(d["year"]["totalPaise"] - 175069000) < 1000, d.get("year", {}).get("totalPaise"))
    t.check("next actions are always given", isinstance(d.get("actions"), list) and d["actions"], d.get("actions"))

    # Segments are read from the newest resume, and only consenting users count towards college totals.
    r = tinker("echo App\\Services\\Growth::segments();")
    row = db.q("select college,target_role,last_company from profiles where owner_id=?", ids[0])[0]
    t.check("college, target role and last employer are read from the resume", tuple(row) == ("RV College of Engineering", "Data Analyst", "Acme 0"), f"{row} {(r.stdout + r.stderr)[-200:]}")
    s, d = o.api("GET", "admin/growth")
    rv = [c for c in d["colleges"] if c["college"] == "RV College of Engineering"]
    t.check("college totals count only users who agreed to emails", rv and rv[0]["users"] == 2 and not any(c["college"] == "PES University" for c in d["colleges"]), d.get("colleges"))
    s, j = o.api("GET", "admin/campaigns/count?audience=customers_all&college=rv%20college&role=analyst")
    t.check("a campaign can be limited to one college and target role", s == 200 and j["count"] == 1, f"{s} {j}")
    s, j = o.api("POST", "admin/campaigns/save", {"name": "RV analysts", "audience": "customers_all", "segCollege": "RV College", "segRole": "", "subject": "Hello", "body": "# Hi {{name}}\n\nA note."})
    saved = [c for c in (j.get("campaigns") or []) if c.get("id") == j.get("id")]
    t.check("the segment is saved with the campaign", s == 200 and saved and saved[0]["segCollege"] == "RV College", f"{s} {saved}")

    # Reports: one per slot per day, emailed to the owner, skipped on Sunday.
    before = db.q("select count(*) from mail_outbox where `trigger`='growth_report'")[0][0]
    r = tinker("Carbon\\Carbon::setTestNow(Carbon\\Carbon::parse('2026-11-02 14:05', 'Asia/Kolkata')); echo json_encode(App\\Services\\Growth::run());")
    out = (r.stdout.strip().splitlines() or [""])[-1]
    t.check("the 2 PM report goes out on a Monday after 2 PM", "midday" in out, (r.stdout + r.stderr)[-300:])
    r = tinker("Carbon\\Carbon::setTestNow(Carbon\\Carbon::parse('2026-11-02 14:20', 'Asia/Kolkata')); echo json_encode(App\\Services\\Growth::run());")
    t.check("the same report is not sent twice", "midday" not in (r.stdout.strip().splitlines() or [""])[-1], r.stdout[-200:])
    r = tinker("Carbon\\Carbon::setTestNow(Carbon\\Carbon::parse('2026-11-08 19:30', 'Asia/Kolkata')); echo json_encode(App\\Services\\Growth::run());")
    t.check("no report on Sunday", "evening" not in (r.stdout.strip().splitlines() or [""])[-1], r.stdout[-200:])
    rows = db.q("select subject,body,recipient from mail_outbox where `trigger`='growth_report' order by created_at desc")
    t.check("the report is emailed to the owner", len(rows) > before and "2 PM growth report" in rows[0][0] and "@" in rows[0][2], rows[:1])
    body = rows[0][1] if rows else ""
    t.check("the report has committed and stretch targets, funnel, risks, decisions and next actions", all(x in body for x in ["committed", "stretch", "Today so far", "Risks", "Decisions needed from you", "Next actions"]), body[:600])
    t.check("the report shows what the revenue follow-ups did (unfinished payments, recovered sales, free sign-ups reachable by email)", "Revenue follow-ups (last 7 days)" in body and "Unfinished payments" in body and "agreed to tips" in body, body[:900])
    r = tinker("echo json_encode(App\\Services\\Growth::followUps());")
    fu = (r.stdout.strip().splitlines() or ["{}"])[-1]
    t.check("follow-up numbers come from the database", all(k in fu for k in ['"unpaid"', '"recovered"', '"freeReachable"']), (r.stdout + r.stderr)[-300:])
    s, d = o.api("GET", "admin/growth/reports?slot=midday")
    t.check("reports are listed one page at a time and can be read", s == 200 and d["total"] >= 1 and o.api("GET", "admin/growth/report?id=" + d["rows"][0]["id"])[0] == 200, d)
    t.check("a snapshot of the day before is stored", db.q("select count(*) from growth_snapshots where day='2026-11-01'")[0][0] == 1)

    # AI spending: counted per request, alerts at 50%, growth tasks stop at the cap, customer add-ons do not.
    tinker("App\\Services\\Settings::put('growth', ['budgetInr' => 10] + App\\Services\\Growth::settings());")
    r = tinker("App\\Services\\Growth::recordAi('customer', 'm', 200000, 20000); App\\Services\\Growth::recordAi('seo', 'm', 0, 0); $b = App\\Services\\Growth::budget(); echo json_encode([$b, App\\Services\\Growth::aiAllowed('seo'), App\\Services\\Growth::aiAllowed('customer')]);")
    try: b, seo, cust = json.loads((r.stdout.strip().splitlines() or ["[]"])[-1])
    except ValueError: b, seo, cust = {}, None, None
    t.check("each request's cost is worked out from its tokens", b.get("spentPaise", 0) == 13200 and b["byPurpose"]["customer"]["calls"] == 1, (r.stdout + r.stderr)[-300:])
    t.check("over the cap growth tasks stop but paid customer add-ons keep working", seo is False and cust is True, (seo, cust))
    t.check("crossing the cap is written to the Error Log", db.q("select count(*) from error_logs where message like '%AI spending has reached%'")[0][0] >= 1)
    s, j = o.api("POST", "admin/growth/settings", {"budgetInr": 2000, "stretchPct": 200, "avgPaise": 12677, "reports": True, "reportEmail": "", "usdInr": 88, "inputUsdPerM": 5, "outputUsdPerM": 25, "ladder": {"2026-11": 120}})
    t.check("the owner can change the cap and the monthly targets", s == 200 and j["settings"]["budgetInr"] == 2000 and j["settings"]["ladder"]["2026-11"] == 120 and j["settings"]["ladder"]["2027-10"] == 2230, f"{s} {j}")
    tinker("App\\Services\\Settings::put('growth', []);")
    t.check("the privacy policy says how resume details are used for emails", "college, target role" in json.load(open(f"{ctx.app}/resources/data/defaults.json"))["legal"]["privacy"])
