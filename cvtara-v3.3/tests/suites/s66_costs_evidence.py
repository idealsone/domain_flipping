"""3.56.0: evidence check before a clean download (V7 §12, §14), "Is this the right job?" (§13), usage ledger mirror for
downloads and scans (§24), AI caps and alerts, invoice check and contribution margin (§25, §26)."""
import json
NAME = "Evidence check, job confirmation, ledger mirror, costs and margin"


def resume(title, summary="Analyst with Excel reporting experience."):
    return {"version": "1", "title": title, "template": "suite-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Ann Rao"}, "summary": summary,
            "order": ["summary", "experience"], "experience": [{"title": "Analyst", "company": "Acme", "start": "2021", "end": "2024", "bullets": "Built weekly Excel reports for 3 teams."}], "education": [], "projects": [], "certifications": []}


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    inst = Instance(ctx.app, ctx.public, preview=True).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        c = Client(inst.base); c.register("ev-cust@test.local")
        uid = db.q("select id from users where email='ev-cust@test.local'")[0][0]
        s, j = c.api("POST", "checkout/start", {"plan": "job-hunt"})
        t.check("the customer buys Job Hunt", s == 200 and c.api("POST", "checkout/verify", {"provider": "demo", "orderId": j["orderId"]})[0] == 200, (s, j))
        s, j = c.api("POST", "resumes", {"data": resume("Primary")}); pid = j["id"]
        vid = c.api("POST", f"resumes/{pid}/variant", {"company": "Globex", "role": "Data Analyst", "jobDescription": "Need SQL"})[1]["id"]
        dl = lambda doc: c.raw("POST", f"/export/resume/{doc}/print", {"clean": True}, {"Accept": "application/json", "X-CSRF-TOKEN": c.csrf or ""})
        s, j = c.api("GET", f"evidence/resume/{vid}")
        t.check("a job version that only repeats the resume needs no confirmation", s == 200 and j["checked"] and j["flags"] == [] and j["confirmed"], j)
        g = c.api("GET", f"resumes/{vid}")[1]["resume"]
        d = g["data"]; d["summary"] = "Data Analyst at Globex level. Improved reporting speed by 45% using SQL and Tableau since 2019."
        c.api("POST", "resumes", {"data": d, "id": vid, "revision": g["revision"]})
        s, j = c.api("GET", f"evidence/resume/{vid}")
        flat = {f["kind"]: f["items"] for f in j["flags"]}
        t.check("new numbers and tools are listed; the employer and role typed for this version are not", "45%" in flat.get("numbers", []) and "2019" in flat.get("numbers", []) and {"SQL", "Tableau"} <= set(flat.get("names", [])) and "Globex" not in flat.get("names", []) and not j["confirmed"], flat)
        s, h, b = dl(vid)
        body = json.loads(b) if s == 409 else {}
        t.check("the clean download waits for the customer to check them, and no credit is used", s == 409 and body.get("evidence") and db.q("select count(*) from product_usage where owner_id=? and action='doc_resume'", uid)[0][0] == 0, (s, b[:200]))
        t.check("a watermarked preview is not held up", c.raw("POST", f"/export/resume/{vid}/print", {"clean": False}, {"Accept": "application/json", "X-CSRF-TOKEN": c.csrf or ""})[0] == 200)
        other = Client(inst.base); other.register("ev-other@test.local")
        t.check("nobody else can confirm it", other.api("POST", f"evidence/resume/{vid}/confirm", {})[0] == 404)
        s, j = c.api("POST", f"evidence/resume/{vid}/confirm", {})
        t.check("the customer confirms; the items and time are kept for this version", s == 200 and j["confirmed"] and json.loads(db.q("select flags_json from evidence_confirmations where doc_id=?", vid)[0][0]))
        t.check("then the clean download works", dl(vid)[0] == 200)
        rows = db.q("select resource, kind, amount, purchase_id from usage_ledger where owner_id=? and resource='resume_download'", uid)
        t.check("the download is mirrored in the usage ledger against the order", len(rows) == 1 and rows[0][1] == "consume" and rows[0][2] == -1 and rows[0][3], rows)
        t.check("the same version again is not charged or mirrored twice", dl(vid)[0] == 200 and db.q("select count(*) from usage_ledger where owner_id=? and resource='resume_download'", uid)[0][0] == 1)
        g = c.api("GET", f"resumes/{vid}")[1]["resume"]; d = g["data"]; d["summary"] += " Led 12 people."
        c.api("POST", "resumes", {"data": d, "id": vid, "revision": g["revision"]})
        t.check("a new version with new facts asks again", dl(vid)[0] == 409)
        t.check("the customer's own primary resume is never held up", c.api("GET", f"evidence/resume/{pid}")[1]["checked"] is False)

        jd = "Data Analyst - Globex\nLocation: Pune\nFull-time\nWe need 3-5 years of experience in SQL, Python and Power BI.\nEducation: B.Tech or MBA.\nSalary: ₹8-12 LPA\nGood to have: Tableau.\nYou will build dashboards and reports for the sales team every week."
        s, j = c.api("POST", "ats/read", {"source": "text", "text": jd})
        job = j.get("job", {})
        t.check("the posting is read into job fields without using a scan", s == 200 and job["title"] == "Data Analyst" and job["company"] == "Globex" and job["location"] == "Pune" and job["employmentType"] == "Full-time" and "3-5 years" in job["experience"] and "MBA" in job["education"] and "LPA" in job["compensation"] and job["confirmed"] is False and db.q("select count(*) from ats_scans where owner_id=?", uid)[0][0] == 0, job)
        t.check("a skill under 'good to have' is listed as preferred", any(x.lower() == "tableau" for x in job["preferredSkills"]), job)
        t.check("pay is left empty when the posting does not state it", c.api("POST", "ats/read", {"source": "text", "text": jd.replace("Salary: ₹8-12 LPA\n", "")})[1]["job"]["compensation"] == "")
        s, j = c.api("POST", "ats/scan", {"resumeId": pid, "source": "text", "text": jd, "confirmed": True})
        rep = json.loads(db.q("select result_json from ats_scans where id=?", j.get("scanId"))[0][0]) if s == 200 else {}
        t.check("the confirmed job is stored with the check", s == 200 and rep.get("jd", {}).get("confirmed") is True and rep["jd"]["confirmedAt"], (s, str(j)[:200]))
        t.check("the scan is mirrored in the usage ledger", db.q("select count(*) from usage_ledger where resource='ats_scan' and reference=?", j.get("scanId"))[0][0] == 1)

        # Costs: caps and alerts.
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        s, _ = o.api("POST", "admin/costs/settings", {"userDailyInr": 10, "orderPct": 1, "gatewayPct": 2.36, "gstPct": 0, "featureMonthlyInr": {"customer:expert_review": 5}, "fulfilmentInr": {"job-hunt": 10}})
        t.check("the owner sets caps and per-order costs", s == 200)
        inst.artisan("tinker", f"--execute=App\\Services\\Growth::recordAi('customer:expert_review', 'claude-test', 1000000, 100000, {uid});")
        logs = " ".join(r[0] for r in db.q("select message from error_logs where source='ai-cost'"))
        t.check("over the daily cap, the per-order share and the feature cap: the owner is warned", "Customer #%d" % uid in logs and "Order ORD-" in logs and "customer:expert_review" in logs, logs[:300])
        out = inst.artisan("tinker", f"--execute=echo json_encode([App\\Services\\CostControls::allowed('customer:tara_chat', {uid}), App\\Services\\CostControls::allowed('customer:expert_review', {uid}), App\\Services\\CostControls::allowed('customer:tara_chat', null)]);")
        t.check("free chat pauses for that customer; paid features never stop", "[false,true,true]" in str(out), out)
        s, j = o.api("GET", "admin/costs")
        t.check("the console shows this month by feature and today's top customer", s == 200 and j["features"][0]["purpose"] == "customer:expert_review" and j["features"][0]["capPaise"] == 500 and j["topToday"][0]["ownerId"] == uid, str(j)[:300])

        # Invoice check.
        ours = j["invoices"][0]["oursPaise"]
        o.api("POST", "admin/costs/invoice", {"month": j["month"], "amountInr": round(ours * 1.2 / 100, 2), "note": "INV-1"})
        inv = o.api("GET", "admin/costs")[1]["invoices"][0]
        t.check("the invoice is compared with our cost log", inv["invoicePaise"] is not None and abs(inv["diffPct"] - 20) < 1, inv)
        t.check("a bad month is refused", o.api("POST", "admin/costs/invoice", {"month": "2026-13", "amountInr": 5})[0] == 422)

        # Margin.
        s, j = o.api("GET", "admin/costs/margin")
        row = [r for r in j.get("rows", []) if r["sku"] == "job-hunt"]
        ai = db.q("select sum(cost_paise) from ai_usage where owner_id=?", uid)[0][0]
        if row:
            r = row[0]
            exp = r["netPaise"] - round(r["grossPaise"] * 2.36 / 100) - ai - 1000
        t.check("margin per plan: net minus gateway fee, the buyer's AI cost and fulfilment", row and r["orders"] == 1 and r["aiPaise"] == ai and r["fulfilmentPaise"] == 1000 and r["marginPaise"] == exp, row)
        s, h, b = o.raw("GET", "/api/admin/report?dataset=margin&format=csv&columns=ALL", None, {"Accept": "text/csv"})
        t.check("the margin report downloads", s == 200 and b"Margin" in b, (s, b[:120]))
        s, h, b = o.raw("GET", "/console/costs.js")
        t.check("the console module is served and registered", s == 200 and b"K.register('costs'" in b)
    finally:
        inst.stop()
