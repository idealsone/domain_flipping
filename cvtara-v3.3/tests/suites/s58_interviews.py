"""V7 step 2 (3.49.0): interview practice. Only approved questions are shown; Job Hunt gets 4 and Pro 12 mock interviews
for the whole plan from the order's frozen limits; a credit is held at the start, used when feedback is ready and given
back when feedback fails three times or the session is left unanswered; feedback follows the fixed rubric; customers can
challenge it; the 7-day refund sees a used mock interview; customer wording never mentions AI."""
import http.server, json, os, re, threading, time
NAME = "V7 step 2: interview practice and mock interviews"
MODE = {"bad": False}
HITS = []


class Fake(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))) or b"{}")
        HITS.append(body)
        msg = json.dumps(body.get("messages", []))
        n = len(set(re.findall(r"Question (\d+):", msg)))
        if MODE["bad"]:
            text = "Sorry, here are some thoughts."
        else:
            text = json.dumps({"summary": "Clear answers with a good example in the second one.", "next_step": "Practise giving one result with a number.",
                               "answers": [{"position": i + 1, "scores": {"relevance": 3, "structure": 3, "evidence": 2, "communication": 3, "role_alignment": 3},
                                            "strengths": ["You answered the question directly."], "improve": ["Add the result of your action."], "quote": "handled",
                                            "rewrite": "In my last role I handled [add your example].", "missing": ""} for i in range(n)]})
        out = {"id": "msg_1", "type": "message", "role": "assistant", "model": body.get("model"), "content": [{"type": "text", "text": text}],
               "stop_reason": "end_turn", "stop_sequence": None, "usage": {"input_tokens": 500, "output_tokens": 500}}
        b = json.dumps(out).encode()
        self.send_response(200); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)


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

        # Starter bank arrives as drafts from the migration; nothing is shown before approval.
        s, a = o.api("GET", "admin/interviews")
        t.check("the starter question bank is loaded as drafts", s == 200 and int(a["counts"].get("draft", 0)) >= 80 and not a["counts"].get("approved"), (s, a.get("counts") if isinstance(a, dict) else a))
        t.check("24 non-technical roles are listed (12 more since 3.57)", len(a["occupations"]) == 24, len(a.get("occupations", [])))

        def customer(e, plan=None):
            c = Client(inst.base); c.register(e); c.page("/workspace.html")
            if plan:
                s, j = c.api("POST", "checkout/start", {"plan": plan})
                c.api("POST", "checkout/verify", {"provider": "demo", "orderId": j["orderId"]})
            return c

        free = customer("iv-free@test.local")
        s, q = free.api("GET", "interviews/questions?occupation=customer-support&band=fresher")
        t.check("no question is shown before the owner approves it", s == 200 and q["total"] == 0, q)

        s, j = o.api("POST", "admin/interviews/approve", {"occupation": "general"})
        s2, j2 = o.api("POST", "admin/interviews/approve", {"occupation": "customer-support"})
        t.check("the owner approves the questions for a role in one step", s == 200 and j["approved"] >= 15 and s2 == 200 and j2["approved"] >= 5, (j.get("approved"), j2.get("approved")))
        row = db.q("select v.approved_by, v.approved_at from interview_question_versions v join interview_questions q on q.current_version_id=v.id where q.code='GEN-001'")
        t.check("approval records who approved and when", row and row[0][0] and row[0][1], row)

        s, q = free.api("GET", "interviews/questions?occupation=customer-support&band=fresher")
        t.check("a free account sees a few sample questions with guidance", s == 200 and len(q["questions"]) == 3 and q["total"] > 3 and not q["full"] and q["questions"][0]["guidance"], (s, len(q.get("questions", [])), q.get("total")))

        # Switched off: nobody can start.
        jh = customer("iv-jh@test.local", "job-hunt")
        s, ov = jh.api("GET", "interviews")
        t.check("Job Hunt shows 4 mock interviews from the order", s == 200 and ov["left"] == 4, ov.get("left"))
        lim = db.q("select limits_json from purchases where owner_id=? and plan='job-hunt'", uid("iv-jh@test.local"))
        t.check("the allowance is frozen on the order", lim and json.loads(lim[0][0]).get("mockInterviews") == 4, lim)
        s, _ = jh.api("POST", "interviews/start", {"occupation": "customer-support", "band": "fresher", "objective": "first_job"})
        t.check("mock interviews cannot start while switched off", s == 422, s)
        s, pub = Client(inst.base).api("GET", "business-public")
        t.check("plan cards do not promise mock interviews while switched off", not any("mock interview" in " ".join(p.get("perks", [])).lower() for p in pub["plans"].values()))

        # Switch the writing service and mock interviews on.
        o.api("POST", "admin/providers", {"service": "ai", "values": {"provider": "claude", "claudeApiKey": "sk-ant-test-1", "dailyLimit": "0"}})
        s, g = o.api("GET", "admin/content/integrations"); d = g["data"]; d["ai"] = True
        o.api("POST", "admin/content/integrations", {"data": d, "revision": g["revision"]})
        s, j = o.api("POST", "admin/interviews/settings", {"enabled": True, "questions": 5, "dailySessions": 3, "releaseDelay": 0})
        t.check("the owner switches mock interviews on", s == 200 and j["available"] is True, (s, j))
        s, pub = Client(inst.base).api("GET", "business-public")
        t.check("Job Hunt and Pro cards now list their mock interviews", "4 mock interviews with written feedback" in pub["plans"]["job-hunt"]["perks"] and "12 mock interviews with written feedback" in pub["plans"]["pro"]["perks"], pub["plans"]["job-hunt"]["perks"])

        s, _ = free.api("POST", "interviews/start", {"occupation": "customer-support", "band": "fresher"})
        t.check("an account without credits cannot start", s == 422, s)

        s, j = jh.api("POST", "interviews/start", {"occupation": "customer-support", "band": "fresher", "objective": "first_job", "company": "A bank"})
        sess = j.get("session", {})
        t.check("a mock interview starts with a locked set of approved questions", s == 200 and sess.get("status") == "in_progress" and 3 <= len(sess.get("answers", [])) <= 5, (s, str(j)[:300]))
        t.check("the first question is an introduction", sess.get("answers", [{}])[0].get("question", "").startswith("Tell me about yourself"), sess.get("answers", [{}])[0])
        t.check("one credit is held at the start", jh.api("GET", "interviews")[1]["left"] == 3)
        s, j2 = jh.api("POST", "interviews/start", {"occupation": "customer-support", "band": "fresher"})
        t.check("starting again continues the open mock interview", j2.get("session", {}).get("id") == sess.get("id") and jh.api("GET", "interviews")[1]["left"] == 3)

        sid = sess["id"]
        s, _ = jh.api("POST", f"interviews/{sid}/submit", {})
        t.check("empty answers cannot be submitted", s == 422, s)
        for a in sess["answers"]:
            jh.api("POST", f"interviews/{sid}/answer", {"position": a["position"], "answer": "In my last role I handled about 60 customer calls a day and kept notes for every complaint."})
        other = customer("iv-other@test.local")
        t.check("another customer cannot read or answer this session", other.api("GET", f"interviews/{sid}")[0] == 404 and other.api("POST", f"interviews/{sid}/answer", {"position": 1, "answer": "x" * 20})[0] in (404, 409))
        n = len(HITS)
        s, j = jh.api("POST", f"interviews/{sid}/submit", {})
        t.check("submitting queues the feedback", s == 200 and j["session"]["status"] in ("submitted", "evaluating", "evaluated"), (s, str(j)[:200]))
        for _ in range(40):
            if db.q("select status from interview_sessions where id=?", sid)[0][0] == "evaluated": break
            time.sleep(0.25)
        else:
            cron()
        s, j = jh.api("GET", f"interviews/{sid}")
        fb = j["session"].get("feedback") or {}
        t.check("feedback is ready with a level and per-answer rubric scores", j["session"]["status"] == "evaluated" and fb.get("level") == "Confident" and len(fb["result"]["answers"]) == len(sess["answers"]) and fb["result"]["answers"][0]["scores"]["evidence"] == 2, (j["session"]["status"], fb.get("level")))
        sent = HITS[n] if len(HITS) > n else {}
        sysp = json.dumps(sent.get("system", ""))
        t.check("the feedback request carries the answers as content, with the no-human and no-AI rules", "<session>" in json.dumps(sent.get("messages", [])) and "Do not claim to be a human interviewer" in sysp and "Do not mention AI" in sysp, sysp[:200])
        ev = db.q("select rubric_version, prompt_version, model from interview_evaluations where session_id=?", sid)
        t.check("the feedback records rubric, prompt and model versions", ev and ev[0][0] == "r1-2026-11" and len(ev[0][1]) == 10 and ev[0][2], ev)
        led = db.q("select kind, amount from usage_ledger where reference=? order by id", sid)
        t.check("the ledger shows the credit held then used", led == [("reserve", -1), ("consume", 0)] or [tuple(x) for x in led] == [("reserve", -1), ("consume", 0)], led)
        t.check("the customer is told the feedback is ready", db.q("select count(*) from notifications where owner_id=? and kind='interview'", uid("iv-jh@test.local"))[0][0] == 1)
        t.check("credits left after one mock interview", jh.api("GET", "interviews")[1]["left"] == 3)

        s, j = jh.api("POST", f"interviews/{sid}/challenge", {"note": "The feedback says I gave no example but I did."})
        t.check("the customer can challenge the feedback once and it reaches support", s == 200 and j["session"]["feedback"]["challenged"] and db.q("select count(*) from support_reports where owner_id=? and message like 'Mock interview feedback challenged%'", uid("iv-jh@test.local"))[0][0] == 1 and jh.api("POST", f"interviews/{sid}/challenge", {"note": "again please"})[0] == 409)

        # Refund rule: a used mock interview blocks the 7-day refund.
        s, pj = jh.api("GET", "purchases")
        jhp = [p for p in pj["purchases"] if p["plan"] == "job-hunt"][0]
        t.check("a used mock interview ends the unused-order refund", jhp.get("refundable") is False, jhp)

        # Failure path: three bad answers give the credit back.
        pro = customer("iv-pro@test.local", "pro")
        t.check("Pro shows 12 mock interviews", pro.api("GET", "interviews")[1]["left"] == 12)
        MODE["bad"] = True
        s, j = pro.api("POST", "interviews/start", {"occupation": "customer-support", "band": "1-3"})
        psid = j["session"]["id"]
        for a in j["session"]["answers"]:
            pro.api("POST", f"interviews/{psid}/answer", {"position": a["position"], "answer": "I solved billing complaints within a day for our biggest accounts."})
        pro.api("POST", f"interviews/{psid}/submit", {})
        for _ in range(3):
            time.sleep(0.5)
            php = "DB::table('interview_sessions')->where('id', '" + psid + "')->update(['retry_at' => now()->subMinute(), 'status' => DB::raw(\"case when status='evaluating' then 'submitted' else status end\")]);"
            inst.artisan("tinker", "--execute=" + php)
            cron()
        st = db.q("select status, attempts from interview_sessions where id=?", psid)[0]
        t.check("after three failed attempts the session is closed", st[0] == "failed" and st[1] == 3, st)
        t.check("the held credit is given back", pro.api("GET", "interviews")[1]["left"] == 12)
        t.check("the failure is written to the Error Log", db.q("select count(*) from error_logs where message like 'Mock interview feedback failed%'")[0][0] >= 1)
        s, pj = pro.api("GET", "purchases")
        t.check("a returned credit does not block the refund", [p for p in pj["purchases"] if p["plan"] == "pro"][0].get("refundable") is True)
        MODE["bad"] = False

        # Abandoned: no submit within a day gives the credit back.
        s, j = pro.api("POST", "interviews/start", {"occupation": "customer-support", "band": "1-3"})
        asid = j["session"]["id"]
        inst.artisan("tinker", "--execute=DB::table('interview_sessions')->where('id', '" + asid + "')->update(['updated_at' => now()->subDays(2)]);")
        cron()
        t.check("an unanswered session closes after a day and returns the credit", db.q("select status from interview_sessions where id=?", asid)[0][0] == "abandoned" and pro.api("GET", "interviews")[1]["left"] == 12)

        # Complimentary credits, idempotent ledger.
        s, j = o.api("POST", "admin/interviews/grant", {"email": "iv-free@test.local", "count": 2, "reason": "Goodwill after a support call"})
        t.check("the owner gives complimentary mock interviews with a reason", s == 200 and j["left"] == 2 and free.api("GET", "interviews")[1]["left"] == 2, (s, j))
        t.check("a grant without a reason is refused", o.api("POST", "admin/interviews/grant", {"email": "iv-free@test.local", "count": 1, "reason": ""})[0] == 422)
        r = inst.artisan("tinker", "--execute=var_dump(App\\Services\\UsageLedger::add(" + str(uid("iv-free@test.local")) + ", 'mock_interview', 'grant', 5, 'same-key'), App\\Services\\UsageLedger::add(" + str(uid("iv-free@test.local")) + ", 'mock_interview', 'grant', 5, 'same-key'));")
        t.check("a repeated ledger write with the same key changes nothing", "bool(true)" in r.stdout and "bool(false)" in r.stdout and free.api("GET", "interviews")[1]["left"] == 7, r.stdout[-200:])

        # Question edits are new versions and go back to draft.
        qid = db.q("select id from interview_questions where code='GEN-002'")[0][0]
        s, _ = o.api("POST", f"admin/interviews/questions/{qid}", {"text": "Why do you want to work with us using AI tools?", "guidance": "Talk about the company and the role."})
        t.check("question text that mentions AI is refused", s == 422, s)
        s, _ = o.api("POST", f"admin/interviews/questions/{qid}", {"text": "Why do you want to join our team?", "guidance": "Show you know what the company does and how the role fits your plans."})
        vers = db.q("select count(*) from interview_question_versions where question_id=?", qid)[0][0]
        t.check("an edited question becomes a new version waiting for approval", s == 200 and vers == 2 and db.q("select status from interview_questions where id=?", qid)[0][0] == "draft")

        # Account deletion removes answers and feedback; the credit record stays without the person.
        jid = uid("iv-jh@test.local")
        inst.artisan("tinker", "--execute=App\\Services\\AccountEraser::erase(" + str(jid) + ");")
        t.check("deleting the account removes mock interview answers and feedback", db.q("select count(*) from interview_sessions where owner_id=?", jid)[0][0] == 0 and db.q("select count(*) from interview_answers where session_id=?", sid)[0][0] == 0 and db.q("select count(*) from interview_evaluations where session_id=?", sid)[0][0] == 0 and db.q("select count(*) from usage_ledger where owner_id=?", jid)[0][0] == 0)

        sup = Client(inst.base); sup.register("iv-staff@test.local")
        s, _ = sup.api("GET", "admin/interviews")
        t.check("a customer cannot open the console question bank", s == 403, s)

        # Wording on the customer page.
        js = open(os.path.join(ctx.public, "interview-practice.js"), encoding="utf8").read()
        t.check("the customer page never mentions AI", not re.search(r"\b(AI|artificial intelligence)\b", re.sub(r"/\*.*?\*/", "", js, flags=re.S)))
        s, _, ws = pro.page("/workspace.html")
        t.check("the workspace has an Interview practice section", 'id="view-interview"' in ws and "/interview-practice.js" in ws)
    finally:
        inst.stop(); srv.shutdown()
