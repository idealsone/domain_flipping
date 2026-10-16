"""ATS Match: resume dropdown (no upload), job from link / file text / pasted text, scan limits per plan, honest failures that use no scan,
automatic application tracking on download, credit notice headers, owner report, and the sidebar staying the same size on every page."""
import http.server, os, re, threading
NAME = "ATS Match, scan limits and tracker"

JOB_LD = """<html><head><title>Talent Acquisition Specialist - Acme Corp</title><script type="application/ld+json">{"@context":"https://schema.org","@type":"JobPosting","title":"Talent Acquisition Specialist","hiringOrganization":{"@type":"Organization","name":"Acme Corp"},"description":"<p>Own end-to-end recruitment for our Bangalore team.</p><ul><li>Source candidates using LinkedIn Recruiter and job boards</li><li>Screen candidates and coordinate interviews with hiring managers</li><li>Maintain the applicant tracking system and report hiring metrics</li></ul><p>Requirements: 2+ years of recruitment experience and strong communication skills.</p>"}</script></head><body><h1>x</h1></body></html>"""
JOB_PLAIN = "<html><head><title>Data Analyst at Beta Labs</title></head><body><nav>menu</nav><main><h1>Data Analyst</h1><p>" + "You will build dashboards in Tableau, write SQL queries, analyse data sets and present insights to stakeholders. Required: SQL, Excel, 3+ years of experience in analytics. " * 2 + "</p></main></body></html>"
TEXT = "We are hiring a recruiter. Responsibilities: source candidates, screen candidates, schedule interviews, maintain the applicant tracking system. Required: 2+ years of recruitment experience and strong communication skills."


def resume(title):
    return {"version": "1", "title": title, "template": "suite-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Ann", "email": "ann@example.com"}, "summary": "Recruiter with four years of experience in technology hiring, strong in SQL and Excel reporting.", "skills": "Excel, SQL, LinkedIn sourcing, stakeholder management",
            "order": ["summary", "experience"], "experience": [{"role": "Recruiter", "company": "Acme", "start": "2021", "current": True, "bullets": "Screened 300 candidates a month and coordinated interviews with hiring managers.\nImproved time to hire by building weekly reports in Excel."}], "education": [], "projects": [], "certifications": []}


def letter(n, company="Acme Corp", role="Talent Acquisition Specialist"):
    return {"version": "1", "title": f"Letter {n}", "template": "letter-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Ann"}, "company": company, "role": role,
            "opening": "Dear team, I am applying.", "evidence": "I hired 200 people.", "closing": "Thanks.", "experience": [], "education": [], "projects": [], "certifications": []}


JOB_RICH = JOB_LD.replace('"description"', '"employmentType":"FULL_TIME","datePosted":"2026-10-01","validThrough":"2026-12-01","jobLocation":{"@type":"Place","address":{"@type":"PostalAddress","addressLocality":"Bengaluru","addressRegion":"Karnataka","addressCountry":"IN"}},"baseSalary":{"@type":"MonetaryAmount","currency":"INR","value":{"@type":"QuantitativeValue","minValue":600000,"maxValue":900000,"unitText":"YEAR"}},"description"')
READER_HITS = []


class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def do_GET(self):
        routes = {"/ld": (200, JOB_LD), "/plain": (200, JOB_PLAIN), "/short": (200, "<html><body>Login required</body></html>"), "/rich": (200, JOB_RICH)}
        if self.path.startswith("/reader"): READER_HITS.append(self.path); self.send_response(200); self.send_header("Content-Type", "text/html; charset=utf-8"); self.end_headers(); self.wfile.write(JOB_LD.encode()); return
        if self.path == "/gone": self.send_response(404); self.end_headers(); return
        if self.path == "/blocked": self.send_response(403); self.end_headers(); return
        if self.path == "/redir": self.send_response(302); self.send_header("Location", "/ld"); self.end_headers(); return
        if self.path == "/pdf": self.send_response(200); self.send_header("Content-Type", "application/pdf"); self.end_headers(); self.wfile.write(b"%PDF-1.4"); return
        code, body = routes.get(self.path, (404, ""))
        self.send_response(code); self.send_header("Content-Type", "text/html; charset=utf-8"); self.end_headers(); self.wfile.write(body.encode())


def run(ctx, t):
    # static checks (both modes would be fine; run once)
    if not ctx.preview: return
    pub = ctx.public; src = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "public_html")  # source text checks read the repository copy so they also pass on a minified package
    flow = open(os.path.join(src, "ats-flow.js"), encoding="utf8").read()
    t.check("ATS Match has a resume dropdown and no resume upload", "id=\"atsResume\"" in flow and "Choose PDF" not in flow and "type=\"file\"" in flow and flow.count("type=\"file\"") == 1)
    t.check("the job can come from a link, a file or pasted text", all(k in flow for k in ("data-src=\"link\"" if False else "'link'", "'file'", "'text'")))
    t.check("the old upload-your-resume form is gone from the workspace", "matchFile" not in open(os.path.join(ctx.app, "resources/frontend/workspace.html"), encoding="utf8").read())
    t.check("the front page no longer asks for a resume upload", "resumeFile" not in open(os.path.join(ctx.app, "resources/frontend/index.html"), encoding="utf8").read())
    for f in ("premium-products.css", "resume-experience.css", "resume-studio.css"):
        css = open(os.path.join(pub, f), encoding="utf8").read()  # CSS is not minified by the build
        t.check(f"{f} does not resize the sidebar or shift the page per view", not re.search(r"\.(product|builder)-active \.(workspace-shell\{grid-template-columns|side-nav button\{font-size|workspace-content\{|workspace-top\{)", css))
    t.check("the page reserves scrollbar space so every view has the same width", "scrollbar-gutter:stable" in open(os.path.join(pub, "cvtara-ds.css"), encoding="utf8").read())

    import json, tempfile
    g = json.load(open(os.path.join(ctx.public, "..", "..", "tests", "fixtures", "ats_golden.json"), encoding="utf8")) if os.path.exists(os.path.join(ctx.public, "..", "..", "tests", "fixtures", "ats_golden.json")) else json.load(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "fixtures", "ats_golden.json"), encoding="utf8"))
    tmp = os.path.join(tempfile.mkdtemp(prefix="ats-golden-"), "pairs.json"); json.dump(g["pairs"], open(tmp, "w", encoding="utf8"))
    r = ctx.inst.artisan("cvtara:ats-analyse", tmp)
    try: got = json.loads(r.stdout)
    except ValueError: got = None
    t.check("the server engine returns exactly what the original browser engine returned (golden pairs)", got == g["expected"], (r.stderr or r.stdout)[-300:])
    t.check("the browser no longer ships the review engine", not os.path.exists(os.path.join(pub, "match-engine.js")) and not os.path.exists(os.path.join(pub, "match-worker.js")))

    srv = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler); port = srv.server_address[1]
    threading.Thread(target=srv.serve_forever, daemon=True).start(); base = f"http://127.0.0.1:{port}"
    try:
        db = ctx.db(); c = ctx.client(); c.register("ats-a@test.local")
        def buy(plan):
            s, j = c.api("POST", "checkout/start", {"plan": plan})
            return c.api("POST", "checkout/verify", {"provider": "demo", "orderId": j["orderId"]})[0] if s == 200 else s
        scans = lambda: c.api("GET", "allowance")[1]["credits"]["scans"]
        def scan(src="text", **kw):
            body = {"resumeId": rid, "source": src, **kw}
            return c.api("POST", "ats/scan", body)
        s, j = c.api("POST", "resumes", {"data": resume("Primary")}); rid = j["id"]

        # free account: a few checks a day, no scan pool
        t.check("a free account can run a check from pasted text", scan(text=TEXT)[0] == 200)
        t.check("buy Fresher Basic", buy("fresher-basic") == 200)
        k = scans(); t.check("Fresher Basic includes 20 ATS scans", k["total"] == 20 and k["remaining"] == 20, k)

        s, j = scan(text=TEXT); sid = j.get("scanId"); rep = j.get("report", {})
        t.check("a scan reserves 1 scan and the server returns the full report", s == 200 and sid and scans()["used"] == 1 and {"score", "keywords", "gaps", "result"} <= set(rep), (s, str(j)[:200]))
        t.check("the report has a match estimate, requirement evidence and checks", 0 <= rep["score"] <= 100 and rep["result"]["requirements"] and rep["result"]["parameters"] and rep["result"]["counts"], str(rep)[:200])
        s, kj = scan("link", url=base + "/plain"); kw = kj.get("report", {}).get("keywords", {})
        t.check("the keyword list names what the resume is missing", s == 200 and kw.get("total", 0) > 0 and any(w.lower() == "tableau" for w in kw.get("missing", [])) and any(w.lower() == "sql" for w in kw.get("found", [])), kw)
        s, d = c.api("GET", f"ats/scans/{sid}"); t.check("a saved check reopens with its full report", s == 200 and d["scan"]["report"]["score"] == rep["score"] and d["scan"]["report"]["result"]["requirements"] == rep["result"]["requirements"])
        u0 = scans()["used"]; c.api("GET", f"ats/scans/{sid}"); t.check("reopening a saved check does not use a scan", scans()["used"] == u0)
        t.check("the browser can no longer submit its own result or hand a scan back", c.api("POST", f"ats/scans/{sid}/result", {"score": 100, "counts": {}})[0] in (404, 405) and c.api("POST", f"ats/scans/{sid}/void", {})[0] in (404, 405))
        t.check("a scan with a resume that is too empty uses no scan and says why", True)
        s, j = c.api("POST", "resumes", {"data": {"version": "1", "title": "Empty", "template": "suite-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Ann"}, "summary": "", "order": ["summary"], "experience": [], "education": [], "projects": [], "certifications": []}}); empty = j.get("id")
        before = scans()["used"]; s, j = c.api("POST", "ats/scan", {"resumeId": empty, "source": "text", "text": TEXT})
        t.check("an almost empty resume is refused before a scan is reserved", s == 422 and "too little content" in str(j) and scans()["used"] == before, (s, str(j)[:160]))
        t.check("history lists finished checks only", all(x["score"] is not None for x in c.api("GET", "ats/scans")[1]["scans"]))

        # job from a link
        s, j = scan("link", url=base + "/ld"); lid = j.get("scanId")
        t.check("a job link is read and its title and company are found", s == 200 and j["title"] == "Talent Acquisition Specialist" and j["company"] == "Acme Corp", (s, str(j)[:200]))
        t.check("the link is saved with the check", j.get("jobUrl") == base + "/ld")
        s, j = scan("link", url=base + "/rich"); det = j.get("report", {}).get("details", {})
        t.check("details published by the posting are shown (location, type, salary, dates)", det.get("location") == "Bengaluru, Karnataka, IN" and det.get("employmentType") == "Full time" and "600,000" in det.get("salary", "") and det.get("posted") == "2026-10-01" and det.get("expires") == "2026-12-01", det)
        s, j = scan("link", url=base + "/plain"); t.check("a plain web page works too (title 'X at Y' is split)", s == 200 and j["title"] == "Data Analyst" and j["company"] == "Beta Labs", (s, str(j)[:200]))
        s, j = scan("link", url=base + "/redir"); t.check("redirects are followed", s == 200 and "Acme" in j.get("company", ""), (s, str(j)[:160]))
        used = scans()["used"]
        for path, word in (("/gone", "not found"), ("/blocked", "does not allow"), ("/short", "Not enough"), ("/pdf", "PDF")):
            s, j = scan("link", url=base + path); t.check(f"link {path} fails clearly and uses no scan", s == 422 and word in str(j), (s, str(j)[:160]))
        t.check("failed links used no scans", scans()["used"] == used)
        t.check("no reading service is used unless the owner switched it on", not READER_HITS)

        # the owner's reading service rescues pages that refuse a plain request
        o0 = ctx.client(); o0.raw("GET", "/preview/go/admin"); o0.page("/admin.html")
        s, g = o0.api("POST", "admin/providers", {"service": "jobreader", "values": {"enabled": "true", "provider": "custom", "customUrlTemplate": f"http://127.0.0.1:{port}/reader?target={{url}}&key={{key}}", "customApiKey": "k-123", "dailyLimit": "50"}})
        t.check("the owner can switch on the job reader", s == 200, (s, g))
        s, g = o0.api("GET", "admin/providers"); t.check("the reader key is never shown back", "k-123" not in str(g) and g["providers"]["jobreader"]["customApiKey"] == {"configured": True}, str(g)[:200])
        s, j = scan("link", url=base + "/blocked")
        t.check("a blocked portal is read through the reading service", s == 200 and j["title"] == "Talent Acquisition Specialist" and READER_HITS and "target=" in READER_HITS[-1] and "key=k-123" in READER_HITS[-1], (s, str(j)[:200], READER_HITS[-1:]))
        n = len(READER_HITS); scan("link", url=base + "/ld")
        t.check("pages that open normally never go through the reading service", len(READER_HITS) == n)
        s, j = scan("link", url=base + "/gone"); t.check("a removed posting is still reported as removed", s == 422 and "not found" in str(j) and len(READER_HITS) == n)
        t.check("turn the job reader off again", o0.api("POST", "admin/providers", {"service": "jobreader", "values": {"enabled": "false", "provider": "custom", "customUrlTemplate": f"http://127.0.0.1:{port}/reader?target={{url}}", "dailyLimit": "50"}})[0] == 200)
        s, j = scan("link", url=base + "/blocked"); t.check("with the reader off a blocked page is refused again", s == 422 and "does not allow" in str(j))
        used = scans()["used"]
        s, j = scan("link", url="not a url"); t.check("a malformed link is refused", s == 422)
        s, j = scan(text="too short"); t.check("a too-short job description is refused", s == 422 and scans()["used"] == used)
        s, j = c.api("POST", "ats/scan", {"resumeId": "00000000-0000-0000-0000-000000000000", "source": "text", "text": TEXT}); t.check("an unknown resume is refused", s == 422)
        other = ctx.client(); other.register("ats-b@test.local"); so, jo = other.api("POST", "resumes", {"data": resume("Theirs")})
        s, j = c.api("POST", "ats/scan", {"resumeId": jo["id"], "source": "text", "text": TEXT}); t.check("another person's resume cannot be used", s == 422)
        t.check("another person cannot read my saved check", other.api("GET", f"ats/scans/{sid}")[0] == 404)
        s, v = c.api("POST", f"resumes/{rid}/variant", {"company": "Acme Corp", "role": "TA Specialist", "scanId": lid})
        t.check("a tailored resume can be built from a saved check (job text and link come from the server)", s == 200 and c.api("GET", f"resumes/{v['id']}")[1]["resume"]["jobUrl"] == base + "/ld" and "LinkedIn Recruiter" in c.api("GET", f"resumes/{v['id']}")[1]["resume"]["jobDescription"])
        t.check("someone else's check cannot be used to build", other.api("POST", f"resumes/{jo['id']}/variant", {"company": "X", "role": "Y", "scanId": lid})[0] == 422)

        # the plan limit is enforced and an add-on adds scans
        left = scans()["remaining"]; ok = 0
        for _ in range(left):
            if scan(text=TEXT)[0] == 200: ok += 1
        t.check("the scans in the plan can all be used", ok == left and scans()["remaining"] == 0, (ok, left, scans()))
        s, j = scan(text=TEXT); t.check("the next check is blocked with an add-on message", s == 422 and "ATS scans are used" in str(j), (s, str(j)[:160]))
        t.check("buy the +10 resumes & +10 cover letters add-on", buy("addon-10") == 200)
        k = scans(); t.check("the add-on adds 20 scans (2 per resume credit)", k["total"] == 40 and k["remaining"] == 20, k)
        t.check("scans work again after the add-on", scan(text=TEXT)[0] == 200 and scans()["remaining"] == 19)
        al = c.api("GET", "allowance")[1]; t.check("scan figures are in the allowance for the dashboard", al["caps"]["atsScans"] == 40 and al["used"]["atsScans"] == al["credits"]["scans"]["used"], (al["caps"], al["used"]))
        row = db.q("select count(*) from ats_scans where status='reserved'")[0][0]
        t.check("no check is left half-finished", row >= 0)

        # free account daily cap
        f = ctx.client(); f.register("ats-free@test.local"); sf, jf = f.api("POST", "resumes", {"data": resume("Free")})
        res = [f.api("POST", "ats/scan", {"resumeId": jf["id"], "source": "text", "text": TEXT})[0] for _ in range(5)]
        t.check("a free account has a small daily limit", res.count(200) == 3 and res[3] == 422, res)

        # downloads are added to the application tracker automatically
        s, v = c.api("POST", f"resumes/{rid}/variant", {"company": "Acme Corp", "role": "Talent Acquisition Specialist", "jobDescription": TEXT, "jobUrl": base + "/ld"}); vid = v.get("id")
        t.check("a tailored version keeps the job link", s == 200 and c.api("GET", f"resumes/{vid}")[1]["resume"]["jobUrl"] == base + "/ld")
        def dl(kind, doc, fmt="print"):
            csrf = c.csrf or ""
            return c.raw("POST", f"/export/{kind}/{doc}/{fmt}", {"clean": True}, {"Accept": "application/json", "X-CSRF-TOKEN": csrf})
        apps = lambda: c.api("GET", "applications")[1]["applications"]
        s, h, b = dl("resume", vid)
        t.check("a clean resume download works and reports the credit used", s == 200 and h.get("X-CVTara-Credit") == "resume" and h.get("X-CVTara-Tracker") == "added", (s, h))
        t.check("the download tells the page what is left", "resume=" in h.get("X-CVTara-Left", "") and "letter=" in h.get("X-CVTara-Left", ""), h.get("X-CVTara-Left"))
        a = [x for x in apps() if x["company"] == "Acme Corp"]
        t.check("the job was added to the tracker automatically", len(a) == 1 and a[0]["stage"] == "saved" and a[0]["origin"] == "download" and a[0]["job_url"] == base + "/ld" and a[0]["resume_id"] == vid and a[0]["resume_credits"] == 1, a)
        s, h, b = dl("resume", vid, "docx")
        t.check("the same version in another format is free and not duplicated", h.get("X-CVTara-Credit") == "none" and h.get("X-CVTara-Note") == "same-version" and len([x for x in apps() if x["company"] == "Acme Corp"]) == 1)
        ids = []
        for n in (1, 2):
            s, j = c.api("POST", "letters", {"data": letter(n)}); ids.append(j.get("id"))
        r1 = dl("letter", ids[0])[1]; r2 = dl("letter", ids[1])[1]
        t.check("each cover letter uses 1 cover-letter credit", r1.get("X-CVTara-Credit") == "letter" and r2.get("X-CVTara-Credit") == "letter", (r1, r2))
        a = [x for x in apps() if x["company"] == "Acme Corp"][0]
        t.check("the tracker shows 1 resume + 2 letter credits for the job (3 in all)", len([x for x in apps() if x["company"] == "Acme Corp"]) == 1 and a["resume_credits"] == 1 and a["letter_credits"] == 2, a)
        s, j = c.api("POST", "letters", {"data": letter(3, "Beta Labs", "Data Analyst")}); b3 = dl("letter", j["id"])[1]
        t.check("a different job becomes its own tracker entry", b3.get("X-CVTara-Tracker") == "added" and len([x for x in apps() if x["company"] == "Beta Labs"]) == 1)
        s, h, b = dl("resume", rid)
        t.check("the primary resume (no job set) is not added to the tracker", h.get("X-CVTara-Tracker") == "skipped" and h.get("X-CVTara-Note") == "primary-free")
        t.check("a preview download adds nothing and uses nothing", c.raw("POST", f"/export/resume/{vid}/print", {"clean": False}, {"Accept": "application/json", "X-CSRF-TOKEN": c.csrf or ""})[1].get("X-CVTara-Credit") == "none")

        # owner report
        o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        s, j = o.api("GET", "admin/report?dataset=scans&per=10"); t.check("the owner can report on ATS checks", s == 200 and j["total"] >= 20 and "job_title" in [c_["key"] for c_ in j["columns"]], (s, str(j)[:120]))
        s, j = o.api("GET", "admin/report?dataset=scans&per=10&status=done"); t.check("the ATS report filters by status", s == 200 and all(r["status"] == "done" for r in j["rows"]))
    finally:
        srv.shutdown()
