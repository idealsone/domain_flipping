"""3.61.0 full mock interview (V5.8 §5, V5.10 §2, V7 D04; owner OK 12 Nov 2026): about 60 minutes in four 15-minute
parts (about you, the role, past experience, situations and closing), three questions each, one mock interview credit,
feedback for every answer plus an average per part worked out from the rubric scores (no extra request)."""
import http.server, json, re, threading, time
NAME = "Full mock interview in four parts"
HITS = []


class Fake(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))) or b"{}")
        HITS.append(body)
        n = len(set(re.findall(r"Question (\d+):", json.dumps(body.get("messages", [])))))
        text = json.dumps({"summary": "Steady answers across the round.", "next_step": "Add one number to each example.",
                           "answers": [{"position": i + 1, "scores": {k: (4 if i < 3 else 2) for k in ("relevance", "structure", "evidence", "communication", "role_alignment")},
                                        "strengths": ["Direct."], "improve": ["Add a result."], "quote": "", "rewrite": "In my last role I [add your example].", "missing": ""} for i in range(n)]})
        out = {"id": "m", "type": "message", "role": "assistant", "model": body.get("model"), "content": [{"type": "text", "text": text}],
               "stop_reason": "end_turn", "stop_sequence": None, "usage": {"input_tokens": 900, "output_tokens": 900}}
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
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        o.api("POST", "admin/interviews/approve", {"occupation": "general"})
        o.api("POST", "admin/interviews/approve", {"occupation": "customer-support"})
        o.api("POST", "admin/providers", {"service": "ai", "values": {"provider": "claude", "claudeApiKey": "sk-ant-test-1", "dailyLimit": "0"}})
        s, g = o.api("GET", "admin/content/integrations"); d = g["data"]; d["ai"] = True
        o.api("POST", "admin/content/integrations", {"data": d, "revision": g["revision"]})
        s, j = o.api("POST", "admin/interviews/settings", {"enabled": True, "questions": 5, "dailySessions": 3, "releaseDelay": 0, "fullMock": True})
        t.check("the full mock is offered once the owner switches it on (off by default since 3.71.0)", s == 200 and j["settings"]["fullMock"] is True, j)

        c = Client(inst.base); c.register("full-a@test.local"); c.page("/workspace.html")
        s, k = c.api("POST", "checkout/start", {"plan": "job-hunt"}); c.api("POST", "checkout/verify", {"provider": "demo", "orderId": k["orderId"]})
        s, ov = c.api("GET", "interviews")
        t.check("the practice page tells the browser the full mock is offered", ov.get("fullMock") is True and ov["left"] == 4)
        s, j = c.api("POST", "interviews/start", {"occupation": "customer-support", "band": "fresher", "objective": "first_job", "mode": "full"})
        x = j.get("session", {})
        secs = x.get("sections", [])
        t.check("a full mock has four parts of 15 minutes", s == 200 and x.get("mode") == "full" and len(secs) == 4 and all(p["minutes"] == 15 for p in secs), (s, str(j)[:300]))
        t.check("with three questions in each part (12 in all)", len(x["answers"]) == 12 and [(p["from"], p["to"]) for p in secs] == [(1, 3), (4, 6), (7, 9), (10, 12)], [(p["from"], p["to"]) for p in secs])
        t.check("the parts are about you, the role, past experience, situations and closing",
                [p["title"] for p in secs] == ["About you and your motivation", "Knowledge of the role", "Your past experience", "Situations and closing"])
        t.check("it opens with Tell me about yourself", x["answers"][0]["question"].startswith("Tell me about yourself"))
        ids = db.q("select v.question_id from interview_answers a join interview_question_versions v on v.id=a.question_version_id where a.session_id=? order by a.position", x["id"])
        t.check("no question is repeated inside the round", len({r[0] for r in ids}) == 12)
        roles = db.q("select q.occupation from interview_answers a join interview_question_versions v on v.id=a.question_version_id join interview_questions q on q.id=v.question_id where a.session_id=? and a.position between 4 and 6", x["id"])
        t.check("part 2 asks questions about the role itself", roles and all(r[0] == "customer-support" for r in roles), roles)
        last = db.q("select q.competency from interview_answers a join interview_question_versions v on v.id=a.question_version_id join interview_questions q on q.id=v.question_id where a.session_id=? and a.position=12", x["id"])
        t.check("the round ends with a closing question", last and last[0][0] == "closing", last)
        t.check("it uses one credit, the same as a standard mock", c.api("GET", "interviews")[1]["left"] == 3)

        for a in x["answers"]:
            s, _ = c.api("POST", f"interviews/{x['id']}/answer", {"position": a["position"], "answer": "In my last role I handled about 60 customer calls a day and kept notes for every complaint."})
        t.check("answers to questions 11 and 12 are accepted", s == 200 and db.q("select count(*) from interview_answers where session_id=? and length(answer) > 20", x["id"])[0][0] == 12)
        n = len(HITS)
        c.api("POST", f"interviews/{x['id']}/submit", {})
        for _ in range(60):
            if db.q("select status from interview_sessions where id=?", x["id"])[0][0] == "evaluated": break
            time.sleep(0.25)
        else:
            inst.artisan("cvtara:cron")
        s, j = c.api("GET", f"interviews/{x['id']}")
        fb = j["session"].get("feedback") or {}
        t.check("feedback covers all 12 answers", j["session"]["status"] == "evaluated" and len(fb["result"]["answers"]) == 12, j["session"]["status"])
        t.check("with an average for each part from the rubric scores", [p["average"] for p in fb.get("sections", [])] == [4.0, 2.0, 2.0, 2.0], fb.get("sections"))
        t.check("one feedback request covers the whole round, with room for 12 answers", len(HITS) == n + 1 and HITS[-1].get("max_tokens", 0) >= 12000, (len(HITS) - n, HITS[-1].get("max_tokens") if HITS else None))
        s, ov = c.api("GET", "interviews")
        t.check("past mock interviews show which ones were full", any(p.get("mode") == "full" for p in ov["past"]))

        # A standard mock is unchanged.
        s, j = c.api("POST", "interviews/start", {"occupation": "customer-support", "band": "fresher", "objective": "first_job"})
        t.check("a standard mock interview is unchanged", s == 200 and j["session"]["mode"] == "standard" and len(j["session"]["answers"]) == 5 and j["session"]["sections"] == [])
        db.q("update interview_sessions set status='abandoned' where id=?", j["session"]["id"])

        # Owner switch.
        o.api("POST", "admin/interviews/settings", {"enabled": True, "fullMock": False})
        s, j = c.api("POST", "interviews/start", {"occupation": "customer-support", "band": "fresher", "mode": "full"})
        t.check("when the owner stops offering it, a full mock cannot start", s == 422 and "full mock" in json.dumps(j).lower(), (s, j))
        t.check("and the practice page no longer offers it", c.api("GET", "interviews")[1]["fullMock"] is False)
        s, _ = c.api("POST", "interviews/start", {"occupation": "customer-support", "band": "fresher", "mode": "marathon"})
        t.check("an unknown type is refused", s == 422)

        js = open(ctx.public + "/interview-practice.js", encoding="utf8").read()
        t.check("the page shows one part at a time with a 15-minute guide clock and never mentions AI",
                "data-ip-part" in js and "left in this part" in js and "Full mock interview (about 60 minutes, 4 parts)" in js and not re.search(r"\bAI\b", re.sub(r"/\*.*?\*/|//[^\n]*", "", js, flags=re.S)))
        cj = open(ctx.app + "/resources/console/interviews.js", encoding="utf8").read()
        t.check("the console has the full mock switch", 'name="fullMock"' in cj)
    finally:
        inst.stop(); srv.shutdown()
