"""Business numbers, owner alerts and payment reconciliation (3.43.0): one set of definitions (and docs/METRICS.md made
from them), the same numbers for a fixed dataset, "not tracked" instead of 0, role checks, and a daily comparison of
Razorpay payments with CVTara's orders that records each mismatch once."""
import os, tempfile
NAME = "Metrics, alerts and payment reconciliation"


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    from lib.fake_razorpay import FakeRazorpay
    rz = FakeRazorpay()
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"RAZORPAY_API_BASE": rz.base}).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")

        def staff(role):
            email = f"mx-{role.replace('_', '-')}@test.local"; c = Client(inst.base); c.register(email)
            o.api("POST", "admin/team/save", {"email": email, "role": role})
            x = Client(inst.base); x.form("/sanaulla", {"email": email, "password": "TestPassw0rd!!x"}); x.page("/admin.html"); return x
        fin, editor, agent = staff("finance_manager"), staff("content_editor"), staff("support_agent")
        base = {x["key"]: x for x in o.api("GET", "admin/metrics?days=30")[1]["metrics"]}
        for i in range(3): Client(inst.base).register(f"mx-c{i}@test.local")
        ids = [db.q("select id from users where email=?", f"mx-c{i}@test.local")[0][0] for i in range(3)]
        ins = "insert into purchases(id,owner_id,plan,status,amount_paise,refunded_paise,provider,payment_reference,provider_order_id,limits_json,access_days,purchased_at,access_until,created_at,updated_at) values(?,?,?,?,?,?,'razorpay',?,?,'{}',30,%s,datetime('now','+30 days'),%s,%s)"
        q = lambda pid, uid, plan, st, amt, ref, ref_paid=0, order=None, when="datetime('now','-2 hours')": db.q(ins % (when, when, when), pid, uid, plan, st, amt, ref_paid, ref, order or "order_" + pid)
        q("mx-1", ids[0], "job-hunt", "paid", 29900, "pay_A")
        q("mx-2", ids[1], "pro", "partially_refunded", 59900, "pay_B", 20000)
        q("mx-3", ids[2], "quick", "failed", 9900, None)
        db.q("insert into ats_scans(id, owner_id, resume_id, source, job_url, job_title, company, job_text, score, result_json, status, created_at, updated_at) values('mx-s1', ?, null, 'text', '', 'X', 'Y', 'job', 50, '{}', 'done', datetime('now'), datetime('now'))", ids[0])
        s, j = o.api("GET", "admin/metrics?days=30")
        m = {x["key"]: x for x in j.get("metrics", [])}
        d = lambda k: (m[k]["value"] or 0) - (base[k]["value"] or 0)
        t.check("every number comes with a label, unit and written definition", s == 200 and all(x["label"] and x["unit"] and len(x["definition"]) > 20 for x in m.values()) and len(m) >= 20, (s, str(j)[:200]))
        t.check("gross, refunds and net for a fixed dataset", d("grossPaise") == 89800 and d("refundedPaise") == 20000 and d("netPaise") == 69800, (d("grossPaise"), d("refundedPaise"), d("netPaise")))
        t.check("paying customers, first-time buyers, orders and sign-ups", d("paidCustomers") == 2 and d("newCustomers") == 2 and d("orders") == 2 and d("registrations") == 3, {k: d(k) for k in ("paidCustomers", "newCustomers", "orders", "registrations")})
        pd, fl = db.q("select sum(case when status in ('paid','refunded','partially_refunded','disputed') then 1 else 0 end), sum(case when status='failed' then 1 else 0 end) from purchases where amount_paise>0 and provider!='referral_reward' and created_at>=datetime('now','-31 days')")[0]
        t.check("payment success rate is paid ÷ (paid + failed)", m["paymentSuccessRate"]["value"] == round(pd / (pd + fl) * 100, 1) and fl >= 1, (m["paymentSuccessRate"], pd, fl))
        t.check("ATS scans are counted", d("atsScans") == 1)
        s2, g2 = o.api("GET", "admin/content/integrations"); d2 = g2["data"]; d2["analytics"] = False
        sx = o.api("POST", "admin/content/integrations", {"data": d2, "revision": g2["revision"]})
        m2 = {x["key"]: x for x in o.api("GET", "admin/metrics?days=30")[1]["metrics"]}
        t.check("visitors are 'not tracked' (not 0) while site analytics is off", m2["organicVisitors"]["tracked"] is False and m2["organicVisitors"]["value"] is None, m2["organicVisitors"])
        t.check("the response says when the numbers were worked out", bool(j.get("at")) and j.get("days") == 30)
        t.check("the same numbers come back on a second read", {k: v["value"] for k, v in m.items() if k != "aiSuccessRate"} == {x["key"]: x["value"] for x in o.api("GET", "admin/metrics?days=30")[1]["metrics"] if x["key"] != "aiSuccessRate"})
        t.check("a Finance Manager can see the numbers", fin.api("GET", "admin/metrics")[0] == 200)
        t.check("a Content Editor cannot", editor.api("GET", "admin/metrics")[0] == 403)
        out = os.path.join(tempfile.mkdtemp(), "m.md"); r = inst.artisan("cvtara:metrics-doc", out)
        doc = open(os.path.join(ctx.app, "..", "docs", "METRICS.md"), encoding="utf8").read()
        t.check("docs/METRICS.md matches the definitions in the code", os.path.exists(out) and open(out, encoding="utf8").read() == doc, r.stderr[-200:])

        # alerts
        for i in range(5): q(f"mx-f{i}", ids[2], "quick", "failed", 9900, None)
        s, j = o.api("GET", "admin/metrics?days=1")
        t.check("many failed payments raise an alert", any(a["key"] == "payments" for a in j["alerts"]), j["alerts"])
        inst.artisan("tinker", "--execute=App\\Services\\Metrics::alerts(true); App\\Services\\Metrics::alerts(true);")
        t.check("an alert goes to the Error Log once a day (repeats count up)", db.q("select count(*), max(occurrences) from error_logs where source='alert'")[0] == (1, 2), db.q("select count(*), max(occurrences) from error_logs where source='alert'"))

        # reconciliation
        s, g = o.api("GET", "admin/content/integrations"); dd = g["data"]; dd["payments"] = True
        o.api("POST", "admin/content/integrations", {"data": dd, "revision": g["revision"]})
        o.api("POST", "admin/providers", {"service": "payments", "values": {"provider": "razorpay", "mode": "test", "razorpayKeyId": "rzp_test_mx", "razorpayKeySecret": "secret_mx"}})
        db.q("update purchases set purchased_at=datetime('now','-1 day','start of day','+9 hours') where id in ('mx-1','mx-2')")
        q("mx-9", ids[0], "quick", "paid", 9900, "pay_Z", 0, None, "datetime('now','-1 day','start of day','+10 hours')")
        q("mx-4", ids[1], "quick", "created", 9900, None, 0, "order_late")
        rz.payments.update({"pay_A": {"id": "pay_A", "order_id": "order_mx-1", "amount": 29900, "status": "captured"}, "pay_B": {"id": "pay_B", "order_id": "order_mx-2", "amount": 49900, "status": "captured"},
                            "pay_L": {"id": "pay_L", "order_id": "order_late", "amount": 9900, "status": "captured"}})
        import datetime
        ist = datetime.datetime.utcnow() + datetime.timedelta(hours=5, minutes=30); yday = (ist - datetime.timedelta(days=1)).strftime("%Y-%m-%d")
        t.check("only the owner can run reconciliation", fin.api("POST", "admin/reconciliation/run", {"day": yday})[0] == 403)
        s, j = o.api("POST", "admin/reconciliation/run", {"day": yday})
        rows = {(x[0], x[1]) for x in db.q("select kind, payment_reference from payment_reconciliation_records")}
        t.check("a payment captured at Razorpay but not paid in CVTara is found", ("paid_not_recorded", "pay_L") in rows, (s, j, rows))
        t.check("a different amount is found", ("amount_differs", "pay_B") in rows, rows)
        t.check("an order paid in CVTara but not at Razorpay is found", ("recorded_not_paid", "pay_Z") in rows, rows)
        t.check("a matching payment is not reported", not any(r[1] == "pay_A" for r in rows))
        t.check("nothing was granted or changed by reconciliation", db.q("select status from purchases where id='mx-4'")[0][0] == "created")
        o.api("POST", "admin/reconciliation/run", {"day": yday})
        t.check("running again does not duplicate records", db.q("select count(*) from payment_reconciliation_records")[0][0] == 3)
        t.check("mismatches go to the Error Log", db.q("select count(*) from error_logs where source='payments' and message like 'Payment mismatch%'")[0][0] == 3)
        s, j = fin.api("GET", "admin/reconciliation")
        t.check("a Finance Manager sees open mismatches", s == 200 and len(j["open"]) == 3)
        t.check("a Support Agent cannot", agent.api("GET", "admin/reconciliation")[0] == 403)
        rid = db.q("select id from payment_reconciliation_records where payment_reference='pay_L'")[0][0]
        t.check("resolving needs a note", o.api("POST", f"admin/reconciliation/{rid}/resolve", {"note": ""})[0] == 422)
        t.check("the owner marks a mismatch checked", o.api("POST", f"admin/reconciliation/{rid}/resolve", {"note": "Webhook was late; order paid by hand"})[0] == 200 and len(o.api("GET", "admin/reconciliation")[1]["open"]) == 2)
        t.check("a mismatch raises an owner alert", any(a["key"] == "reconciliation" for a in o.api("GET", "admin/metrics")[1]["alerts"]))
        t.check("a day older than 60 days or in the future is refused", o.api("POST", "admin/reconciliation/run", {"day": "2020-01-01"})[0] == 422)
        ah = o.page("/admin.html")[2]
        t.check("the Overview loads the numbers script", "/console/metrics.js" in ah and o.raw("GET", "/console/metrics.js")[0] == 200)
    finally:
        inst.stop(); rz.stop()
