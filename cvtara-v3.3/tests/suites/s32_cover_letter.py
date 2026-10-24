"""One-click cover letters: a complete letter from a saved resume and a job. Without writing help it is built from the resume's own
lines (no raw bullet dump, nothing invented); with it switched on, Claude writes it within the daily allowance and any failure falls
back to the standard letter at no cost. Customer-facing studio text never mentions AI."""
import http.server, json, re, threading
NAME = "One-click cover letters"
HITS = []
MODE = {"v": "ok"}

GOOD = {"subject": "Application for the Program Director position", "opening": "I am applying for the Program Director role at Northwind Learning.",
        "evidence": "At Sample Academy I run 40+ programs for 12,000 learners.", "motivation": "Northwind Learning is building campus programs, which is the work I do.",
        "closing": "I would welcome a conversation. Thank you for your time.", "checks": ["Check the learner count."]}


class Fake(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))) or b"{}")
        HITS.append(body)
        text = "Here it is:\n" + json.dumps(GOOD) if MODE["v"] == "ok" else "Sorry, here is some prose instead of JSON."
        out = {"id": "msg_1", "type": "message", "role": "assistant", "model": body.get("model"), "content": [{"type": "text", "text": text}],
               "stop_reason": "end_turn", "stop_sequence": None, "usage": {"input_tokens": 10, "output_tokens": 10}}
        b = json.dumps(out).encode()
        self.send_response(200); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)


# Shaped like an imported PDF: employer in "role", the job title as a line inside the bullets, bullets wrapped over two lines.
RESUME = {"version": "1", "title": "Imported", "template": "suite-prism", "accent": "#1557d2", "font": "Arial",
          "personal": {"name": "Asha Rao", "email": "asha@test.local", "phone": "+91 90000 11111", "city": "Bengaluru"},
          "summary": "Operations leader with 9+ years across edtech and consumer tech, with a record of running learning programs end to end. KEY ACHIEVEMENTS • • Grew learner satisfaction, lifting NPS from 30 to 62 across 15,000 learners.",
          "order": ["summary", "experience", "skills"], "skills": "Program Management, Campus Operations, Stakeholder Management, Team Leadership, Excel",
          "experience": [
              {"role": "Sample Academy (Sample Edutech Pvt Ltd", "company": "", "start": "Sep 2021", "current": True,
               "bullets": "Director - Operations Current\n\n\nRun 40+ learning programs for 12,000 learners every month, with a\nteam of 20 across two campuses.\nReduced support resolution time from 30 hours to 16 hours by redesigning the escalation process.\nCoordinated faculty onboarding and training for 60 instructors across campuses."},
              {"role": "Category Manager", "company": "Sample Foods", "start": "Jun 2016", "end": "Aug 2021",
               "bullets": "Launched a new category in 3 cities, onboarding 120 brands within a year.\nManaged key accounts worth 8 Cr in annual revenue."}],
          "education": [], "projects": [], "certifications": []}

JD = """Program Director – Northwind Learning
Location: Bengaluru
About Northwind Learning
Northwind Learning is redefining undergraduate technology education by combining university degrees with industry projects.
Key Responsibilities
Campus Operations
Lead and oversee day-to-day operations across multiple campuses and ensure smooth program delivery.
Faculty Coordination
Coordinate faculty onboarding, training and continuous improvement across all partner campuses.
Requirements
7+ years in education operations, strong stakeholder management and experience running learning programs at scale."""


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    srv = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Fake); threading.Thread(target=srv.serve_forever, daemon=True).start()
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"CLAUDE_API_BASE": f"http://127.0.0.1:{srv.server_address[1]}"}).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        u = Client(inst.base); u.register("letter-a@test.local"); u.page("/workspace.html")
        s, j = u.api("POST", "resumes", {"data": RESUME}); rid = j.get("id")
        t.check("the test resume saves", s == 200 and rid, (s, str(j)[:200]))
        draft = lambda c=u, **kw: c.api("POST", "letters/draft", {"resumeId": rid, **kw})

        s, j = draft(jobText=JD); L = j.get("letter", {})
        body = " ".join(L.get(k, "") for k in ("opening", "evidence", "motivation", "closing"))
        t.check("one click returns every part of the letter", s == 200 and all(L.get(k) for k in ("subject", "salutation", "opening", "evidence", "motivation", "closing", "signoff")), (s, str(j)[:300]))
        t.check("role and company come from the job text when left blank", j.get("role") == "Program Director" and j.get("company") == "Northwind Learning", (j.get("role"), j.get("company")))
        t.check("the letter names the role and the company", "Program Director" in L.get("opening", "") and "Northwind Learning" in L.get("opening", ""), L.get("opening"))
        t.check("evidence is written as sentences, not a bullet dump with job-title lines", L.get("evidence", "").startswith("In my current role as Director - Operations at Sample Academy, I ") and "\n" not in L.get("evidence", "") and "Current" not in L.get("evidence", ""), L.get("evidence"))
        t.check("wrapped bullet lines are joined", "with a team of 20" in body, L.get("evidence"))
        t.check("figures are kept exactly as written", any(x in body for x in ("40+ learning programs", "30 hours to 16 hours", "60 instructors")), body[:400])
        t.check("the employer is described only from the job text", "mission of redefining undergraduate technology education" in L.get("motivation", ""), L.get("motivation"))
        t.check("legal names are left out of the prose", "Pvt Ltd" not in body and "Edutech" not in body, body[:400])
        t.check("a complete letter of a sensible length", 150 <= len(body.split()) <= 420, len(body.split()))
        t.check("nothing is saved until the customer saves", db.q("select count(*) from cover_letter_drafts d join users u on u.id=d.owner_id where u.email=?", "letter-a@test.local")[0][0] == 0)
        t.check("no writing help is used while it is off", HITS == [] and j.get("team") is False)

        s, j = draft(role="Operations Lead", company="Acme Pvt Ltd")
        t.check("without a job description the letter still comes out whole", s == 200 and "Operations Lead" in j["letter"]["opening"] and "Acme" in j["letter"]["closing"] and j["letter"]["evidence"], (s, str(j)[:300]))
        t.check("a visitor who is not signed in cannot write a letter", Client(inst.base).api("POST", "letters/draft", {"resumeId": rid})[0] in (401, 419))
        b = Client(inst.base); b.register("letter-b@test.local"); b.page("/workspace.html")
        t.check("someone else's resume is refused", b.api("POST", "letters/draft", {"resumeId": rid})[0] == 422)

        # Switch writing help on: Claude writes it, within the daily allowance.
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        o.api("POST", "admin/providers", {"service": "ai", "values": {"provider": "claude", "claudeApiKey": "sk-ant-test-1", "model": "", "dailyLimit": "0"}})
        s, g = o.api("GET", "admin/content/integrations"); d = g["data"]; d["ai"] = True
        o.api("POST", "admin/content/integrations", {"data": d, "revision": g["revision"]})
        left = lambda: u.api("GET", "ai/status")[1]["ai"]["left"]
        before = left(); s, j = draft(jobText=JD)
        t.check("with writing help on, the team's letter comes back", s == 200 and j.get("team") is True and j["letter"]["opening"] == GOOD["opening"] and "Check the learner count." in j["checks"], (s, str(j)[:300]))
        t.check("it uses one of today's suggestions", left() == before - 1, (before, left()))
        sys = json.dumps(HITS[-1].get("system")) if HITS else ""
        t.check("the request keeps the resume and job in tags and the CVTara team rule", "<resume>" in HITS[-1]["messages"][0]["content"] and "<job>" in HITS[-1]["messages"][0]["content"] and "never mention AI" in sys and "Never invent" in sys)
        t.check("greeting and sign-off are set by CVTara, not the writer", j["letter"]["salutation"] == "Dear Hiring Manager," and j["letter"]["signoff"] == "Yours sincerely,")
        MODE["v"] = "bad"; before = left(); s, j = draft(jobText=JD)
        t.check("an unusable answer falls back to the standard letter and costs nothing", s == 200 and j.get("team") is False and "Northwind Learning" in j["letter"]["opening"] and left() == before, (s, j.get("team"), before, left()))
        MODE["v"] = "ok"
        while left() > 0: draft(jobText=JD)
        n = len(HITS); s, j = draft(jobText=JD)
        t.check("after today's allowance the standard letter still writes in one click", s == 200 and j.get("team") is False and j["letter"]["evidence"] and len(HITS) == n, (s, len(HITS), n))

        # The studio: one-click panel, no AI wording, builder button, ATS hand-off.
        js = open(ctx.public + "/cover-letter-studio.js", encoding="utf8").read()
        t.check("the studio offers one-click writing", "/api/letters/draft" in js and "Write my letter" in js and "data-quick-host" in js)
        t.check("the studio opens from ATS Match and the builder with ?write=1", "q.get('write')!=='1'" in js and "view=letter&write=1&resume=" in js)
        t.check("the old raw bullet import is gone", "letterResumeSource" not in js and "Use selected resume" not in js)
        t.check("customer-facing studio text never mentions AI", not re.search(r"\b(AI|Claude|Anthropic|GPT|artificial intelligence)\b", js), re.findall(r".{30}\b(?:AI|Claude|Anthropic)\b.{30}", js)[:3])
        t.check("the studio no longer says exports are not active", "not active in this preview" not in js)
    finally:
        inst.stop(); srv.shutdown()
