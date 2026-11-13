"""3.73.0 the large interview question bank (owner, 13-14 Nov 2026): 352 roles in 22 job families, 10 skills each, 66 patterns
written for CVTara in the standard interview categories, worded for three experience tiers: about 5.8 lakh questions. A question
is made only when needed and only from patterns the owner approved; the customer gets a personal bank by category and skill
with progress; free accounts see samples and how Job Hunt and Pro open the bank; mock interviews draw new questions from it."""
import http.server, json, re, threading, time
NAME = "3.73.0 large question bank"


class Fake(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))) or b"{}")
        msg = json.dumps(body.get("messages", []))
        n = len(set(re.findall(r"Question (\d+):", msg)))
        text = json.dumps({"summary": "Clear.", "next_step": "Add numbers.", "answers": [{"position": i + 1, "scores": {"relevance": 3, "structure": 3, "evidence": 3, "communication": 3, "role_alignment": 3},
                           "strengths": ["Clear."], "improve": ["Add a result."], "quote": "", "rewrite": "My name is Asha Rao.", "missing": ""} for i in range(n)]}) if "<session>" in msg else "My name is Asha Rao. I have [add your example]."
        out = {"id": "msg_1", "type": "message", "role": "assistant", "model": body.get("model"), "content": [{"type": "text", "text": text}],
               "stop_reason": "end_turn", "stop_sequence": None, "usage": {"input_tokens": 100, "output_tokens": 50}}
        b = json.dumps(out).encode()
        self.send_response(200); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)


RESUME = {"version": "1", "title": "Main", "template": "suite-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Asha Rao", "role": "Telecaller"},
          "summary": "Telecaller with two years of outbound calling.", "order": ["summary", "experience"],
          "experience": [{"role": "Telecaller", "company": "Star Finance", "start": "2023", "current": True, "bullets": "Made 120 calls a day from lead lists."}],
          "projects": [], "education": [], "certifications": []}
BANNED = re.compile(r"\b(AI|A\.I\.|Claude|Anthropic|GPT|OpenAI|ChatGPT)\b")


def run(ctx, t):
    cat = json.load(open(ctx.app + "/resources/data/interview-catalogue.json"))
    roles, pats = cat["roles"], cat["patterns"]
    t.check("the catalogue has 22 job families and 352 roles with 10 skills each", len(cat["families"]) == 22 and len(roles) == 352 and all(len(r["skills"]) == 10 for r in roles), (len(cat["families"]), len(roles)))
    t.check("66 patterns, each worded for freshers, mid-level and senior", len(pats) == 66 and all(set(p["text"]) == {"entry", "mid", "senior"} for p in pats))
    t.check("together they make over 5 lakh distinct questions", cat["total"] >= 500000 and cat["total"] == 352 * 10 * 3 * 54 + 352 * 3 * 12, cat["total"])
    t.check("every category of a standard interview is covered", {c for p in pats for c in p["category"].values()} >= {"hr", "behavioural", "situational", "skills", "customer", "leadership", "integrity", "motivation"})
    t.check("the 24 starter roles keep their slugs", {"customer-support", "sales-executive", "teacher", "event-coordinator", "data-entry"} <= {r["slug"] for r in roles})
    t.check("customer wording never mentions AI", not BANNED.search(json.dumps([p["text"] for p in pats] + [p["guidance"] for p in pats] + cat["tierTip"].__repr__().split())))
    slugs = [r["slug"] for r in roles]
    t.check("role slugs are unique", len(set(slugs)) == len(slugs))
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    srv = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Fake); threading.Thread(target=srv.serve_forever, daemon=True).start()
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"CLAUDE_API_BASE": f"http://127.0.0.1:{srv.server_address[1]}"}).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        t.check("the migration adds every catalogue role to the role list", db.q("select count(*) from career_occupations where slug in (%s)" % ",".join("?" * 50), *slugs[:50])[0][0] == 50
                and db.q("select count(*) from career_occupations")[0][0] >= 352)
        t.check("all 66 patterns wait for the owner's approval", db.q("select count(*) from interview_bank_patterns where status='draft'")[0][0] == 66)
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        o.api("POST", "admin/interviews/approve", {"occupation": "*"})
        o.api("POST", "admin/providers", {"service": "ai", "values": {"provider": "claude", "claudeApiKey": "sk-ant-test-1", "dailyLimit": "0"}})
        s, g = o.api("GET", "admin/content/integrations"); d = g["data"]; d["ai"] = True
        o.api("POST", "admin/content/integrations", {"data": d, "revision": g["revision"]})
        o.api("POST", "admin/interviews/settings", {"enabled": True, "releaseDelay": 0})

        c = Client(inst.base); c.register("qb-a@test.local"); c.page("/workspace.html")
        s, j = c.api("POST", "checkout/start", {"plan": "pro"}); c.api("POST", "checkout/verify", {"provider": "demo", "orderId": j["orderId"]})
        s, j = c.api("GET", "interviews/questions?occupation=telecaller&band=1-3")
        base = j.get("total", -1)
        t.check("before approval a new role has only the starter questions for every role", s == 200 and 0 < base < 60 and not any(q["code"].startswith("G-") for q in j["questions"]), (s, base))

        s, j = o.api("GET", "admin/interviews")
        b = j.get("bank", {})
        t.check("the console shows the bank's size and that nothing is live yet", s == 200 and b.get("possible") == cat["total"] and b.get("live") == 0 and b.get("roles") == 352 and b.get("patterns") == 66, b)
        s, j = o.api("GET", "admin/interviews/bank/patterns?per=20")
        t.check("patterns list 20 per page with a total", s == 200 and len(j["rows"]) == 20 and j["total"] == 66, (s, j.get("total")))
        s, j = o.api("GET", "admin/interviews/bank/patterns?category=hr")
        t.check("patterns filter by category", s == 200 and j["total"] >= 8 and all(r["category"] == "hr" for r in j["rows"]), (s, j.get("total")))
        s, h, body = o.raw("GET", "/api/admin/interviews/bank/patterns?export=csv")
        t.check("patterns download as CSV", s == 200 and body.decode("utf-8", "ignore").count("\n") >= 66, (s, body[:100]))
        s, j = o.api("GET", "admin/interviews/bank/patterns/P07")
        sm = j.get("samples", [])
        t.check("a pattern shows sample questions across roles, skills and levels", s == 200 and len(sm) == 6 and len({x["role"] for x in sm}) >= 4 and {x["level"] for x in sm} == {"entry", "mid", "senior"}
                and all("{" not in x["text"] for x in sm), sm[:2])
        s, j = o.api("POST", "admin/interviews/bank/status", {"codes": ["P07"], "status": "approved"})
        t.check("approving one pattern makes its questions live", s == 200 and j["bank"]["approvedPatterns"] == 1 and j["bank"]["live"] == 352 * 30, j.get("bank"))
        s, j = o.api("POST", "admin/interviews/bank/status", {"all": True, "status": "approved"})
        t.check("approve every pattern: the whole bank is live", s == 200 and j["bank"]["live"] == cat["total"], j.get("bank"))

        # The customer's own bank.
        s, j = c.api("GET", "interviews/questions?occupation=telecaller&band=1-3")
        t.check("a new role now has its own bank of hundreds of questions", s == 200 and j["full"] and j["total"] == base + 552 and len(j["questions"]) == 20 and j["pages"] == -(-(base + 552) // 20), (s, j.get("total"), j.get("pages")))
        t.check("questions are worded for the level and name the role's skills", any("outbound calls" in q["text"] for q in j["questions"]) and not any("{" in q["text"] for q in j["questions"]), [q["text"] for q in j["questions"][:3]])
        t.check("the bank has categories with progress and the role's 10 skills", len(j["categories"]) >= 7 and all("done" in x for x in j["categories"]) and len(j["skills"]) == 10, j["categories"][:2])
        s, j2 = c.api("GET", "interviews/questions?occupation=telecaller&band=12%2B&category=leadership")
        t.check("a senior level gets senior wording", s == 200 and any("your team" in q["text"] for q in j2["questions"]) and [q for q in j2["questions"] if q["code"].startswith("G-")] and all(q["code"].split("-")[3] == "s" for q in j2["questions"] if q["code"].startswith("G-")), [q["text"] for q in j2["questions"][:3]])
        s, k = c.api("GET", "interviews/questions?occupation=telecaller&band=1-3&category=hr")
        t.check("filter by category (HR round)", s == 200 and k["matching"] >= 12 and all(q["category"] == "hr" for q in k["questions"]), (s, k.get("matching")))
        sk = j["skills"][0]
        s, k = c.api("GET", "interviews/questions?occupation=telecaller&band=1-3&skill=" + sk.replace(" ", "+"))
        t.check("filter by skill", s == 200 and k["matching"] == 54 and all(q["skill"] == sk for q in k["questions"]), (s, k.get("matching")))
        last = j["pages"]
        s, k = c.api("GET", "interviews/questions?occupation=telecaller&band=1-3&page=%d" % last)
        t.check("the last page has the rest", s == 200 and k["page"] == last and len(k["questions"]) == (base + 552 - (last - 1) * 20), len(k.get("questions", [])))
        code = j["questions"][0]["code"]
        s, m = c.api("POST", "interviews/practised", {"code": code, "done": True})
        s2, k = c.api("GET", "interviews/questions?occupation=telecaller&band=1-3&todo=1")
        t.check("ticking a question as practised counts in progress and hides it from 'not practised yet'", s == 200 and m["done"] and k["practised"] == 1 and k["matching"] == base + 551 and code not in [q["code"] for q in k["questions"]], (s, k.get("practised"), k.get("matching")))
        s, m = c.api("POST", "interviews/practised", {"code": "G-zzzzzzz-1-e-P01", "done": True})
        t.check("an unknown code is refused", s == 404, s)

        # Model answers for a generated question.
        c.api("POST", "resumes", {"data": RESUME})
        gq = next(q for q in j["questions"] if q["code"].startswith("G-"))
        t.check("generated questions are not stored until needed", db.q("select count(*) from interview_questions where code=?", gq["code"])[0][0] == 0)
        s, a = c.api("POST", "interviews/model-answer", {"code": gq["code"]})
        t.check("a model answer works for a generated question and stores that one question", s == 200 and a["answer"].startswith("My name is") and db.q("select count(*) from interview_questions where code=? and status='approved'", gq["code"])[0][0] == 1, (s, a))

        # Free accounts: samples and the way to the full bank.
        f = Client(inst.base); f.register("qb-free@test.local"); f.page("/workspace.html")
        s, k = f.api("GET", "interviews/questions?occupation=telecaller&band=1-3")
        t.check("free accounts see a few questions and the full size of their bank", s == 200 and not k["full"] and len(k["questions"]) == 3 and k["total"] == base + 552, (s, k.get("total"), len(k.get("questions", []))))
        js = open(ctx.public + "/interview-practice.js").read()
        t.check("the page shows the bank size and how Pro and Job Hunt open it", "Your question bank has" in js and "See Pro and Job Hunt" in js and "data-ip-done" in js and "Surprise me" in js)
        page = re.sub(r"/\*.*?\*/|^\s*//.*$", "", js, flags=re.S | re.M)
        t.check("customer page wording never mentions AI", not BANNED.search(page))

        # Mock interviews draw from the bank.
        s, x = c.api("POST", "interviews/start", {"occupation": "telecaller", "band": "1-3", "track": "same"})
        sess = x.get("session", {})
        qs = [a["question"] for a in sess.get("answers", [])]
        gen = db.q("select count(*) from interview_answers a join interview_question_versions v on v.id=a.question_version_id join interview_questions q on q.id=v.question_id where a.session_id=? and q.code like 'G-%'", sess.get("id", ""))[0][0]
        t.check("a mock interview for a new role is full and uses bank questions", s == 200 and len(qs) == 8 and gen >= 3 and len(set(qs)) == 8, (s, gen, qs))
        t.check("it still opens with the introduction", qs and qs[0].startswith("Tell me about yourself"), qs[:1])
        first = {a["question"] for a in sess["answers"] if not a.get("personal")}
        for i, a in enumerate(sess["answers"]):
            c.api("POST", f"interviews/{sess['id']}/answer", {"position": a["position"], "answer": "I made 120 calls a day and kept notes in the CRM. " * 3})
        c.api("POST", f"interviews/{sess['id']}/submit", {})
        db.q("update interview_sessions set created_at=datetime('now','-2 days') where id=?", sess["id"])
        for _ in range(40):
            if db.q("select status from interview_sessions where id=?", sess["id"])[0][0] == "evaluated": break
            time.sleep(0.25)
        s, x = c.api("POST", "interviews/start", {"occupation": "telecaller", "band": "1-3", "track": "same"})
        second = [a["question"] for a in x.get("session", {}).get("answers", []) if not a.get("personal")]
        rep = [q for q in second if q in first and not q.startswith("Tell me about yourself")]
        t.check("the next mock interview gets new bank questions, not repeats", s == 200 and len(x["session"]["answers"]) == 8 and not rep, (s, rep))

        # Withdraw and switch off.
        s, j = o.api("POST", "admin/interviews/bank/status", {"codes": ["P01"], "status": "withdrawn"})
        s, k = c.api("GET", "interviews/questions?occupation=telecaller&band=1-3&skill=" + sk.replace(" ", "+"))
        t.check("withdrawing a pattern removes its questions", k["matching"] == 53, k.get("matching"))
        newcode = "G-" + gq["code"].split("-")[1] + "-2-e-P01"
        s, a = c.api("POST", "interviews/model-answer", {"code": newcode})
        t.check("a withdrawn pattern's questions cannot be asked for", s == 404, s)
        s, _ = o.api("POST", "admin/interviews/bank/settings", {"enabled": False})
        s2, k = c.api("GET", "interviews/questions?occupation=customer-support&band=1-3")
        t.check("switching the bank off leaves only the starter questions", s == 200 and k["total"] < 60 and not any(q["code"].startswith("G-") for q in k["questions"]), (s, k.get("total")))
        o.api("POST", "admin/interviews/bank/settings", {"enabled": True})
        js = open(inst.app + "/resources/console/interviews.js").read()
        t.check("the console has the bank panel with samples, approve and withdraw", "ivPGrid" in js and "data-iv-sample" in js and "Approve every pattern" in js)

        # Account deletion erases the practice ticks.
        uid = db.q("select id from users where email='qb-a@test.local'")[0][0]
        inst.artisan("tinker", "--execute=App\\Services\\AccountEraser::erase(%d);" % uid)
        t.check("deleting an account erases its practice ticks", db.q("select count(*) from interview_practised where owner_id=?", uid)[0][0] == 0)
    finally:
        inst.stop(); srv.shutdown()
