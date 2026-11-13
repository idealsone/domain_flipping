"""3.71.0 personal mock interviews (owner, 12-13 Nov 2026): the interview page starts from the sign-up answers and a job-switch
choice; each mock opens with questions made from the customer's own resume; a named practice panel of two asks in turn;
interviews last 30 minutes, one a day, and time running out submits what was written; results (with a model answer
built from the resume and cover letter) are released 30 minutes after the interview; personal model answers for bank
questions are made once and kept, with a daily cap; the console lists are paged with filters and downloads."""
import http.server, json, re, threading, time
NAME = "3.71.0 personal mock interviews"
HITS = []


class Fake(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))) or b"{}")
        HITS.append(body)
        msg = json.dumps(body.get("messages", []))
        n = len(set(re.findall(r"Question (\d+):", msg)))
        if "<session>" in msg:
            text = json.dumps({"summary": "Good, specific answers.", "next_step": "Add one number to each answer.",
                               "answers": [{"position": i + 1, "scores": {"relevance": 3, "structure": 3, "evidence": 3, "communication": 3, "role_alignment": 3},
                                            "strengths": ["Clear."], "improve": ["Add a result."], "quote": "", "rewrite": "My name is Ravi Kumar, and I have [add your example].", "missing": ""} for i in range(n)]})
        else:
            text = "My name is Ravi Kumar. As a Sales Executive at Bright Retail I closed 40 new accounts in a year."
        out = {"id": "msg_1", "type": "message", "role": "assistant", "model": body.get("model"), "content": [{"type": "text", "text": text}],
               "stop_reason": "end_turn", "stop_sequence": None, "usage": {"input_tokens": 300, "output_tokens": 200}}
        b = json.dumps(out).encode()
        self.send_response(200); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)


RESUME = {"version": "1", "title": "Main", "template": "suite-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Ravi Kumar", "role": "Sales Executive"},
          "summary": "Sales executive moving into customer support.", "order": ["summary", "experience", "projects"],
          "experience": [{"role": "Sales Executive", "company": "Bright Retail", "start": "2021", "current": True, "bullets": "Closed 40 new accounts in 2024 by calling leads every morning.\nTrained 3 new joiners."}],
          "projects": [{"name": "Lead tracker in Excel", "bullets": "Built a sheet the whole team used."}], "education": [], "certifications": []}
LETTER = {"version": "1", "title": "Letter", "template": "letter-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Ravi Kumar"}, "company": "Acme", "role": "Customer Support Executive",
          "opening": "Dear team, I am applying for customer support.", "evidence": "I handle 50 customer calls a day with patience.", "closing": "Thanks.", "experience": [], "education": [], "projects": [], "certifications": []}


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    srv = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Fake); threading.Thread(target=srv.serve_forever, daemon=True).start()
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"CLAUDE_API_BASE": f"http://127.0.0.1:{srv.server_address[1]}"}).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        uid = lambda e: db.q("select id from users where email=?", e)[0][0]
        cron = lambda: inst.artisan("cvtara:cron")
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        o.api("POST", "admin/interviews/approve", {"occupation": "*"})
        o.api("POST", "admin/providers", {"service": "ai", "values": {"provider": "claude", "claudeApiKey": "sk-ant-test-1", "dailyLimit": "0"}})
        s, g = o.api("GET", "admin/content/integrations"); d = g["data"]; d["ai"] = True
        o.api("POST", "admin/content/integrations", {"data": d, "revision": g["revision"]})
        s, j = o.api("POST", "admin/interviews/settings", {"enabled": True})
        st = j.get("settings", {})
        t.check("the owner's format is the default: 8 questions, 30 minutes, one a day, results 30 minutes later, no 60-minute mock",
                s == 200 and st.get("questions") == 8 and st.get("minutes") == 30 and st.get("dailySessions") == 1 and st.get("releaseDelay") == 30 and st.get("fullMock") is False, st)

        # Sign-up with the job-switch choice; the interview page starts from it.
        c = Client(inst.base)
        data = {"name": "Ravi Kumar", "email": "pi-a@test.local", "password": "TestPassw0rd!!x", "password_confirmation": "TestPassw0rd!!x", "experienceLevel": "experienced",
                "profession": "Customer Support Executive", "category": "Customer Service", "terms": "1", "switching": "1", "currentRole": "Sales Executive"}
        c.form("/register", data)
        db.q("update users set email_verified_at=datetime('now') where email='pi-a@test.local'")
        c.page("/workspace.html")
        s, j = c.api("POST", "checkout/start", {"plan": "job-hunt"}); c.api("POST", "checkout/verify", {"provider": "demo", "orderId": j["orderId"]})
        s, ov = c.api("GET", "interviews")
        p = ov.get("profile", {})
        t.check("sign-up answers fill the interview page: target role matched, switching from the current role", s == 200 and p.get("track") == "switch" and p.get("currentRole") == "Sales Executive" and p.get("occupation") == "customer-support", p)
        t.check("without a resume the page says the panel will ask about the role only", ov.get("hasResume") is False)

        c.api("POST", "resumes", {"data": RESUME}); c.api("POST", "letters", {"data": LETTER})
        t.check("the page knows a resume is there", c.api("GET", "interviews")[1].get("hasResume") is True)

        s, j = c.api("POST", "interviews/start", {"occupation": "customer-support", "band": "1-3", "objective": "career_change", "track": "switch", "currentRole": "Sales Executive"})
        x = j.get("session", {})
        qs = [a["question"] for a in x.get("answers", [])]
        t.check("the mock opens with the introduction, then personal questions", s == 200 and qs and qs[0].startswith("Tell me about yourself") and x["answers"][1]["personal"], (s, qs[:4]))
        t.check("a job-switch question names both roles", any("from Sales Executive to Customer Support Executive" in q for q in qs), qs)
        t.check("a question comes from the resume's job", any("Sales Executive at Bright Retail" in q for q in qs), qs)
        t.check("a question quotes a resume line with a number", any('Closed 40 new accounts' in q for q in qs), qs)
        t.check("the interview still has the owner's 8 questions", len(qs) == 8, len(qs))
        panel = x.get("panel", [])
        t.check("two named practice panelists take turns", len(panel) == 2 and panel[0]["name"] != panel[1]["name"] and {a["askedBy"] for a in x["answers"]} == {0, 1}, panel)
        dl = x.get("deadlineAt")
        t.check("the interview ends 30 minutes after it starts", dl and 29 * 60 <= (time.mktime(time.strptime(dl, "%Y-%m-%dT%H:%M:%SZ")) - time.mktime(time.strptime(x["now"], "%Y-%m-%dT%H:%M:%SZ"))) <= 30 * 60, (dl, x.get("now")))
        s, _ = c.api("POST", "interviews/start", {"occupation": "customer-support", "band": "1-3"})
        t.check("the open interview continues rather than a second one", s == 200 and _.get("session", {}).get("id") == x["id"])

        # Time runs out with two answers written: submitted for the customer, no minimum length.
        sid = x["id"]
        c.api("POST", f"interviews/{sid}/answer", {"position": 1, "answer": "I am Ravi, a sales executive with three years of experience."})
        c.api("POST", f"interviews/{sid}/answer", {"position": 2, "answer": "Short."})
        db.q("update interview_sessions set deadline_at=datetime('now','-1 minutes') where id=?", sid)
        s, j = c.api("POST", f"interviews/{sid}/submit", {})
        t.check("at time-up the interview is submitted with what was written", s == 200 and j["session"]["status"] in ("submitted", "evaluating", "releasing"), (s, str(j)[:200]))
        rel = db.q("select release_at, submitted_at from interview_sessions where id=?", sid)[0]
        t.check("results are due 30 minutes after the interview", rel[0] and rel[1], rel)
        n = len(HITS)
        for _ in range(40):
            if db.q("select status from interview_sessions where id=?", sid)[0][0] == "evaluated": break
            time.sleep(0.25)
        else:
            cron()
        sent = json.dumps(HITS[n]["messages"]) if len(HITS) > n else ""
        t.check("feedback uses the resume, cover letter and job switch", "<candidate>" in sent and "Bright Retail" in sent and "50 customer calls" in sent and "Changing jobs from: Sales Executive" in sent, sent[:300])
        s, j = c.api("GET", f"interviews/{sid}")
        t.check("before the release time the results stay hidden", j["session"]["status"] == "releasing" and j["session"]["feedback"] is None and j["session"]["releaseAt"], j["session"]["status"])
        t.check("nobody is told yet", db.q("select count(*) from notifications where owner_id=? and kind='interview'", uid("pi-a@test.local"))[0][0] == 0)
        lvl = c.api("GET", "interviews")[1]
        t.check("the progress level waits for the release too", lvl["progress"]["level"] is None and lvl["past"][0]["level"] is None and lvl["past"][0]["status"] == "releasing", lvl["past"][0])
        db.q("update interview_sessions set release_at=datetime('now','-1 minutes') where id=?", sid)
        cron()
        s, j = c.api("GET", f"interviews/{sid}")
        fb = j["session"].get("feedback") or {}
        t.check("after the release time the results and model answers show", j["session"]["status"] == "evaluated" and fb.get("result", {}).get("answers", [{}])[0].get("rewrite", "").startswith("My name is"), j["session"]["status"])
        t.check("the customer gets a notification and an email, once", db.q("select count(*) from notifications where owner_id=? and kind='interview'", uid("pi-a@test.local"))[0][0] == 1
                and db.q("select count(*) from mail_outbox where trigger='interview_results' and recipient='pi-a@test.local'")[0][0] == 1)
        cron()
        t.check("running cron again sends nothing new", db.q("select count(*) from mail_outbox where trigger='interview_results'")[0][0] == 1)

        # One a day.
        s, j = c.api("POST", "interviews/start", {"occupation": "customer-support", "band": "1-3"})
        t.check("only one mock interview a day", s == 422 and "today" in json.dumps(j), (s, j))

        # Time runs out with nothing written: credit returned.
        c2 = Client(inst.base); c2.register("pi-b@test.local"); c2.page("/workspace.html")
        s, j = c2.api("POST", "checkout/start", {"plan": "pro"}); c2.api("POST", "checkout/verify", {"provider": "demo", "orderId": j["orderId"]})
        s, j = c2.api("POST", "interviews/start", {"occupation": "customer-support", "band": "fresher"})
        sid2 = j["session"]["id"]
        t.check("a customer with no resume gets bank questions only", s == 200 and not any(a["personal"] for a in j["session"]["answers"]))
        t.check("a different pair of panelists from the last interview is fine; panel is set", len(j["session"]["panel"]) == 2)
        db.q("update interview_sessions set deadline_at=datetime('now','-5 minutes') where id=?", sid2)
        cron()
        t.check("time up with nothing written: not finished and the credit returned", db.q("select status from interview_sessions where id=?", sid2)[0][0] == "abandoned" and c2.api("GET", "interviews")[1]["left"] == 12)
        s, _ = c2.api("POST", f"interviews/{sid2}/answer", {"position": 1, "answer": "too late"})
        t.check("answers after time-up are refused", s == 409, s)

        # Model answers for bank questions.
        code = db.q("select code from interview_questions where occupation is null and status='approved' order by code limit 1")[0][0]
        n = len(HITS)
        s, j = c.api("POST", "interviews/model-answer", {"code": code})
        t.check("a model answer is made from the customer's resume", s == 200 and j["answer"].startswith("My name is Ravi Kumar") and j["kept"] is False and "Bright Retail" in json.dumps(HITS[n]["messages"]), (s, j))
        s, j = c.api("POST", "interviews/model-answer", {"code": code})
        t.check("asking again shows the kept answer without a new request", s == 200 and j["kept"] is True and len(HITS) == n + 1)
        s, j = c2.api("POST", "interviews/model-answer", {"code": code})
        t.check("without a resume the customer is asked to add one", s == 422 and "resume" in json.dumps(j).lower(), (s, j))
        free = Client(inst.base); free.register("pi-free@test.local"); free.page("/workspace.html")
        s, j = free.api("POST", "interviews/model-answer", {"code": code})
        t.check("free accounts do not get model answers", s == 422 and "Job Hunt" in json.dumps(j), (s, j))
        o.api("POST", "admin/interviews/settings", {"enabled": True, "modelAnswersDaily": 1})
        code2 = db.q("select code from interview_questions where occupation is null and status='approved' order by code limit 1 offset 1")[0][0]
        s, j = c.api("POST", "interviews/model-answer", {"code": code2})
        t.check("the daily cap on new model answers holds", s == 422 and "today" in json.dumps(j), (s, j))
        s, j = c.api("POST", "interviews/profile", {"track": "same"})
        t.check("the customer can change the job-switch choice", s == 200 and j["profile"]["track"] == "same")

        # Console lists: paged, filtered, downloadable.
        s, j = o.api("GET", "admin/interviews/questions?per=20&status=approved")
        t.check("the question bank loads 20 per page with a total", s == 200 and len(j["rows"]) == 20 and j["total"] > 20 and all(r["status"] == "approved" for r in j["rows"]), (s, j.get("total")))
        s, j = o.api("GET", "admin/interviews/questions?occupation=general&q=yourself")
        t.check("search and role filter work", s == 200 and j["total"] >= 1 and all(r["occupation"] is None for r in j["rows"]), (s, j.get("total")))
        s, h, b = o.raw("GET", "/api/admin/interviews/questions?export=csv&status=approved")
        t.check("the question bank downloads as CSV", s == 200 and b.decode("utf-8", "ignore").count("\n") > 20, (s, b[:120]))
        s, j = o.api("GET", "admin/interviews/sessions?status=evaluated")
        t.check("mock interviews list with status filter shows the job switch", s == 200 and j["total"] == 1 and j["rows"][0]["track"] == "switch", (s, str(j)[:200]))
        s, j = o.api("GET", "admin/interviews")
        t.check("the console summary has coverage per role and the panel", s == 200 and "coverage" in j and len(j["panel"]) >= 8 and "questions" not in j)
        js = open(inst.app + "/resources/console/interviews.js").read()
        t.check("the console uses paged grids for both lists", "ivQGrid" in js and "ivSGrid" in js and "K.grid" in js)
        page = open(ctx.public + "/interview-practice.js").read()
        t.check("customer wording never mentions AI", not re.search(r"\b(AI|A\.I\.|Claude|Anthropic|GPT|OpenAI)\b", page))
    finally:
        inst.stop(); srv.shutdown()
