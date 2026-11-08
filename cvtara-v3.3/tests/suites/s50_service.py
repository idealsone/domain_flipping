"""Customer dashboard and the combined resume + cover letter service (3.41.0): states stored as events, failures give the
writing suggestion back, no saving before payment, payment unlocks saving, customers never see each other's requests,
the cron finishes requests the customer left, downloads list, career goal and new dashboard recommendations."""
import http.server, json, threading
NAME = "Resume + cover letter service and customer dashboard"
MODE = {"v": "ok"}

RESUME = {"version": "1", "title": "Asha resume", "template": "suite-prism", "accent": "#1557d2", "font": "Arial",
          "personal": {"name": "Asha Rao", "email": "svc-a@test.local", "phone": "9800000000", "city": "Pune"},
          "summary": "Recruiter with four years of hiring for retail and warehouse roles across Pune and Mumbai.",
          "skills": "Sourcing, Screening, Excel, Naukri, Interview scheduling",
          "order": ["summary", "experience", "skills"],
          "experience": [{"role": "Recruiter", "company": "Acme Retail", "start": "2021", "current": True,
                          "bullets": "Screened 300 candidates a month and coordinated interviews with hiring managers.\nImproved time to hire by building weekly reports in Excel.\nRan walk-in drives for 14 stores."}],
          "education": [{"degree": "BBA", "school": "Pune University", "end": "2020"}], "projects": [], "certifications": []}
JOB = ("Talent Acquisition Executive at Zenith Stores, Pune. Responsibilities: source and screen candidates for retail stores, "
       "run walk-in drives, coordinate interviews with store managers, maintain hiring trackers in Excel, use Naukri and LinkedIn. "
       "Requirements: 3+ years of recruitment experience, strong communication, stakeholder management, MIS reporting.")


class Fake(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))) or b"{}")
        system = json.dumps(body.get("system"))
        if MODE["v"] == "junk": text = "Sorry, prose instead of JSON"
        elif "senior resume writer" in system:
            text = json.dumps({"summary": "Talent acquisition professional with four years of hiring for retail stores across Pune.", "skills": "Recruitment, Walk-in drives, Excel, Naukri, Stakeholder management",
                               "experience": [{"i": 0, "bullets": "Screened 300 candidates a month for retail roles.\nRan walk-in drives for 14 stores.\nBuilt weekly hiring reports in Excel."}], "notShown": ["LinkedIn Recruiter"], "check": ["the candidate count"]})
        else:
            text = json.dumps({"subject": "Application for Talent Acquisition Executive", "opening": "I am applying for the Talent Acquisition Executive role at Zenith Stores. I am a recruiter with four years of retail hiring.",
                               "evidence": "At Acme Retail I screened 300 candidates a month and ran walk-in drives for 14 stores.", "motivation": "Zenith Stores is growing its stores in Pune, and my walk-in drives fit that work.",
                               "closing": "I would welcome a conversation. Thank you for your time.", "checks": []})
        out = {"id": "msg_1", "type": "message", "role": "assistant", "model": body.get("model"), "content": [{"type": "text", "text": text}], "stop_reason": "end_turn", "stop_sequence": None, "usage": {"input_tokens": 10, "output_tokens": 10}}
        b = json.dumps(out).encode()
        try:
            self.send_response(200); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)
        except (BrokenPipeError, ConnectionResetError): pass


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    srv = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Fake); threading.Thread(target=srv.serve_forever, daemon=True).start()
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"CLAUDE_API_BASE": f"http://127.0.0.1:{srv.server_address[1]}"}).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")

        def customer(email):
            c = Client(inst.base); c.register(email)
            s, j = c.api("POST", "resumes", {"data": {**RESUME, "personal": {**RESUME["personal"], "email": email}}})
            return c, j.get("id")
        a, ra = customer("svc-a@test.local")
        start = lambda c, rid, **kw: c.api("POST", "service-requests", {"resumeId": rid, "jobText": JOB, "role": "Talent Acquisition Executive", "company": "Zenith Stores", **kw})
        s, j = start(a, ra)
        t.check("the service is refused while the CVTara team writing help is off", s == 422 and db.q("select count(*) from service_requests")[0][0] == 0, (s, j))

        o.api("POST", "admin/providers", {"service": "ai", "values": {"provider": "claude", "claudeApiKey": "sk-ant-test-50", "model": "", "dailyLimit": "0"}})
        s, g = o.api("GET", "admin/content/integrations"); d = g["data"]; d["ai"] = True
        o.api("POST", "admin/content/integrations", {"data": d, "revision": g["revision"]})

        t.check("a short job description is refused before anything starts", start(a, ra, jobText="Recruiter needed")[0] == 422 and db.q("select count(*) from service_requests")[0][0] == 0)
        scans0 = db.q("select count(*) from ats_scans")[0][0]
        s, j = start(a, ra)
        req = j.get("request", {}); rid = req.get("id")
        t.check("a request starts with the information received, from what the customer already has", s == 200 and req["status"] == "received" and req["steps"][0]["at"] and req["job"]["company"] == "Zenith Stores", (s, str(j)[:300]))
        t.check("a new job goes through the normal ATS check (one scan used)", db.q("select count(*) from ats_scans")[0][0] == scans0 + 1)
        t.check("a second request is refused while the first is running", start(a, ra)[0] == 422)

        b, rb = customer("svc-b@test.local")
        for m, p, body in (("GET", f"service-requests/{rid}", None), ("POST", f"service-requests/{rid}/run", {}), ("POST", f"service-requests/{rid}/save", {}), ("POST", f"service-requests/{rid}/retry", {})):
            t.check(f"another customer gets nothing from {m} {p.replace(rid, '<id>')}", b.api(m, p, body)[0] == 404)
        t.check("another customer's list is empty", b.api("GET", "service-requests")[1]["requests"] == [])

        seen = []
        for _ in range(6):
            s, j = a.api("POST", f"service-requests/{rid}/run", {})
            seen.append(j["request"]["status"])
            if not j["request"]["working"]: break
        r = j["request"]
        t.check("the steps run in order and stop at payment for a free account", seen[:4] == ["resume", "letter", "checks", "payment_pending"] or seen == ["resume", "letter", "checks", "payment_pending"], seen)
        ev = [x[0] for x in db.q("select state from service_request_events where request_id=? order by id", rid)]
        t.check("every state is a stored event", ev == ["received", "resume", "letter", "checks", "ats", "preview_ready", "payment_pending"], ev)
        t.check("the tracker shows only stored states", [s_["key"] for s_ in r["steps"] if s_["at"]] == ev, r["steps"])
        p = r["preview"] or {}
        t.check("the preview shows the tailored resume, the letter and the ATS score", "Talent acquisition professional" in json.dumps(p) and "Zenith Stores" in (p.get("letter") or {}).get("opening", "") and isinstance(p.get("scoreAfter"), int), str(p)[:300])
        t.check("the resume and letter are checked against each other", any("name is the same" in c["text"] and c["ok"] for c in p["checks"]) and any("names Zenith Stores" in c["text"] and c["ok"] for c in p["checks"]), p["checks"])
        t.check("the order summary shows what is delivered, plans with price and validity, and no auto-renewal", r["order"] and r["order"]["plans"] and all(x["price"] > 0 and x["days"] > 0 for x in r["order"]["plans"]) and "no automatic renewal" in r["order"]["note"], r["order"])
        t.check("nothing is saved before payment", a.api("POST", f"service-requests/{rid}/save", {})[0] == 422 and db.q("select count(*) from resume_drafts where owner_id=(select id from users where email='svc-a@test.local')")[0][0] == 1 and db.q("select count(*) from cover_letter_drafts where owner_id=(select id from users where email='svc-a@test.local')")[0][0] == 0)
        t.check("running again does nothing once the work is done", a.api("POST", f"service-requests/{rid}/run", {})[1]["request"]["status"] == "payment_pending" and db.q("select count(*) from service_request_events where request_id=?", rid)[0][0] == 7)

        # payment
        aid = db.q("select id from users where email='svc-a@test.local'")[0][0]
        db.q("insert into purchases(id,owner_id,plan,status,amount_paise,provider,limits_json,access_days,purchased_at,access_until,created_at,updated_at) values('svc-p1',?,'job-hunt','paid',29900,'demo','{}',30,datetime('now'),datetime('now','+30 days'),datetime('now'),datetime('now'))", aid)
        s, j = a.api("GET", f"service-requests/{rid}")
        t.check("a verified payment moves the request to payment confirmed", j["request"]["status"] == "payment_confirmed" and db.q("select purchase_id from service_requests where id=?", rid)[0][0] == "svc-p1", j["request"]["status"])
        s, j = a.api("POST", f"service-requests/{rid}/save", {"mode": "version"})
        row = db.q("select result_resume_id, letter_id, status from service_requests where id=?", rid)[0]
        t.check("after payment both are saved to the account and the download is available", s == 200 and row[0] and row[1] and row[2] == "download_available", (s, str(j)[:300]))
        saved = db.q("select content_json, parent_id from resume_drafts where id=?", row[0])[0]
        t.check("the job version holds the tailored text and the customer's own resume is unchanged", "Talent acquisition professional" in saved[0] and saved[1] == ra and "Talent acquisition" not in db.q("select content_json from resume_drafts where id=?", ra)[0][0])
        t.check("the saved letter is for the job", db.q("select company, role from cover_letter_drafts where id=?", row[1])[0] == ("Zenith Stores", "Talent Acquisition Executive"))
        t.check("saving twice does not make copies", a.api("POST", f"service-requests/{rid}/save", {})[0] == 422 and db.q("select count(*) from resume_drafts where owner_id=?", aid)[0][0] == 2)

        # a paying customer goes straight from preview to payment confirmed
        t.check("a new job uses a scan credit like any ATS check (none left here)", start(a, ra)[0] == 422)
        s, j = a.api("POST", "service-requests", {"resumeId": ra, "scanId": db.q("select scan_id from service_requests where id=?", rid)[0][0]})
        t.check("a second request can reuse a job already checked", s == 200, (s, j))
        rid2 = j["request"]["id"]
        for _ in range(6):
            j = a.api("POST", f"service-requests/{rid2}/run", {})[1]
            if not j["request"]["working"]: break
        t.check("on a plan that covers it there is no payment step", j["request"]["status"] == "payment_confirmed" and "payment_pending" not in [x[0] for x in db.q("select state from service_request_events where request_id=?", rid2)], j["request"]["status"])
        t.check("the customer can put the tailored text into the resume they started from", a.api("POST", f"service-requests/{rid2}/save", {"mode": "update"})[0] == 200 and "Talent acquisition professional" in db.q("select content_json from resume_drafts where id=?", ra)[0][0])

        # failure: the suggestion is given back and the customer can try again
        c, rc = customer("svc-c@test.local")
        cid = db.q("select id from users where email='svc-c@test.local'")[0][0]
        s, j = start(c, rc); rid3 = j["request"]["id"]
        MODE["v"] = "junk"
        s, j = c.api("POST", f"service-requests/{rid3}/run", {})
        t.check("a failed step is stored with a plain message", j["request"]["status"] == "failed" and j["request"]["error"] and "Claude" not in j["request"]["error"] and db.q("select count(*) from service_request_events where request_id=? and state='failed'", rid3)[0][0] == 1, str(j)[:300])
        t.check("a failed step gives the writing suggestion back", db.q("select count(*) from product_usage where owner_id=? and action='ai'", cid)[0][0] == 0)
        MODE["v"] = "ok"
        s, j = c.api("POST", f"service-requests/{rid3}/retry", {})
        t.check("trying again restarts from the failed step", s == 200 and j["request"]["status"] == "received" and j["request"]["working"], (s, str(j)[:200]))
        t.check("retry is refused when nothing failed", c.api("POST", f"service-requests/{rid3}/retry", {})[0] == 422)

        # lock: a step being run is not run twice
        db.q("update service_requests set locked_until=datetime('now','+5 minutes') where id=?", rid3)
        t.check("a step already running is not started again", c.api("POST", f"service-requests/{rid3}/run", {})[1]["request"]["status"] == "received")
        # cron finishes a request the customer left
        db.q("update service_requests set locked_until=null, updated_at=datetime('now','-5 minutes') where id=?", rid3)
        out = inst.artisan("tinker", "--execute=echo App\\Services\\ServiceRequests::runDue(1);")
        t.check("the cron runs the next step of a request the customer left", db.q("select status from service_requests where id=?", rid3)[0][0] == "resume", (out.stdout[-200:], out.stderr[-300:]))

        # downloads, career goal, support view and recommendations
        db.q("insert into product_usage(id, owner_id, action, reference, created_at, updated_at) values('svc-dl1', ?, 'doc_resume', ?, datetime('now'), datetime('now'))", aid, f"{ra}|3|pool")
        s, j = a.api("GET", "downloads")
        t.check("Downloads lists each clean download with its document", s == 200 and j["downloads"][0]["kind"] == "resume" and j["downloads"][0]["title"] == "Asha resume" and j["downloads"][0]["version"] == 3, j)
        t.check("another customer's downloads list is empty", b.api("GET", "downloads")[1]["downloads"] == [])
        t.check("an unknown career goal is refused", a.api("POST", "profile/goal", {"goal": "hack"})[0] == 422)
        t.check("the career goal is saved to the profile", a.api("POST", "profile/goal", {"goal": "new_job", "role": "Talent Acquisition"})[0] == 200 and a.api("GET", "profile")[1]["profile"]["career_goal"] == "new_job")
        bid = db.q("select id from users where email='svc-b@test.local'")[0][0]
        db.q("insert into purchases(id,owner_id,plan,status,amount_paise,provider,limits_json,access_days,created_at,updated_at) values('svc-f1',?,'job-hunt','failed',29900,'razorpay','{}',30,datetime('now'),datetime('now'))", bid)
        s, j = b.api("GET", "tara/nudge"); n = j.get("nudge") or {}
        t.check("a failed payment is the dashboard's next step, with retry and support", "did not go through" in n.get("title", "") and any("checkout.html?plan=job-hunt" in x.get("url", "") for x in n.get("actions", [])) and any("support=new" in x.get("url", "") for x in n.get("actions", [])), n)
        db.q("insert into purchases(id,owner_id,plan,status,amount_paise,provider,limits_json,access_days,purchased_at,access_until,created_at,updated_at) values('svc-e1',?,'job-hunt','paid',29900,'demo','{}',30,datetime('now','-40 days'),datetime('now','-2 days'),datetime('now','-40 days'),datetime('now','-40 days'))", cid)
        n = c.api("GET", "tara/nudge")[1].get("nudge") or {}
        t.check("an ended plan shows the renewal options", "has ended" in n.get("title", "") and any("view=billing" in x.get("url", "") for x in n.get("actions", [])), n)
        db.q("update service_requests set status='download_available' where owner_id=?", cid)
        for i in range(4): db.q(f"insert into service_requests(id, owner_id, status, created_at, updated_at) values('svc-x{i}', ?, 'download_available', datetime('now'), datetime('now'))", cid)
        t.check("a customer cannot start more than 5 requests a day", start(c, rc)[0] == 429)
        s, j = a.api("GET", "account/export")
        t.check("the data export includes the requests and support tickets", s == 200 and len(j["export"]["resumeAndLetterRequests"]) == 2 and "supportRequests" in j["export"])
        d, rd = customer("svc-d@test.local")
        s, j = start(d, rd); did = db.q("select id from users where email='svc-d@test.local'")[0][0]
        d.api("POST", "account/delete", {"password": "TestPassw0rd!!x", "confirm": "DELETE"})
        t.check("deleting the account removes its requests and their events", db.q("select count(*) from service_requests where owner_id=?", did)[0][0] == 0 and db.q("select count(*) from service_request_events where request_id=?", j["request"]["id"])[0][0] == 0)
        ws = c.page("/workspace.html")[2]
        t.check("the workspace loads the dashboard script and the grouped menu", "/service-request.js" in ws and "My career workspace" in ws and "Help &amp; support" in ws and "data-support-mine" in ws)
    finally:
        inst.stop(); srv.shutdown()
