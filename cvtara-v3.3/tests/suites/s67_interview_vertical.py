"""3.57.0 interview vertical (V7 §16-§20, V5.6-V5.7 §5): India role taxonomy with synonyms, Research a company (public page,
robots.txt respected, labelled practice questions, no AI), optional answer timer, revision mode, V7 badge names and the
public interview pages linking to practice."""
import http.server, threading
NAME = "Interview vertical: taxonomy, research a company, timer, revision"

ABOUT = b"""<html><head><title>About Acme Retail</title><meta property="og:site_name" content="Acme Retail"></head><body><nav>menu</nav>
<h1>About us</h1><p>Acme Retail runs neighbourhood grocery stores across Pune and Mumbai, serving families with fresh produce every day. Our store teams focus on friendly service, clean aisles and fair prices for every customer.</p>
<p>We started in 2009 with one store. Today our grocery stores, home delivery service and loyalty programme help thousands of customers. Grocery, delivery and loyalty are at the centre of what we do, and our values are honesty, care and teamwork.</p></body></html>"""


class H(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def do_GET(self):
        if self.path == "/robots.txt":
            body = b"User-agent: *\nDisallow: /private/\n"
        elif self.path.startswith("/about"):
            body = ABOUT
        elif self.path.startswith("/private"):
            body = ABOUT
        else:
            self.send_response(404); self.end_headers(); return
        self.send_response(200); self.send_header("Content-Type", "text/plain" if self.path == "/robots.txt" else "text/html"); self.end_headers(); self.wfile.write(body)


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    srv = http.server.ThreadingHTTPServer(("127.0.0.1", 0), H); site = f"http://127.0.0.1:{srv.server_address[1]}"
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    inst = Instance(ctx.app, ctx.public, preview=True).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        roles = db.q("select slug, domain, isco_code, seniority, synonyms from career_occupations order by slug")
        t.check("24 roles, each with a domain, ISCO-08 code, seniority ladder and synonyms", len(roles) == 24 and all(r[1] and r[2] and r[3] and r[4] for r in roles), [r for r in roles if not (r[1] and r[2] and r[3] and r[4])][:3])
        t.check("the 12 new roles arrive with draft questions only", db.q("select count(*) from interview_questions where occupation='inside-sales' and status='draft'")[0][0] == 2)
        c = Client(inst.base); c.register("iv-a@test.local"); c.page("/workspace.html")
        uid = db.q("select id from users where email='iv-a@test.local'")[0][0]
        s, j = c.api("GET", "interviews/roles?q=telecaller")
        t.check("typing a common Indian job title finds the role through its synonyms", s == 200 and j["roles"] and j["roles"][0]["slug"] in ("inside-sales", "customer-support") and any(r["slug"] == "inside-sales" for r in j["roles"]), j)
        t.check("a role name match ranks first", c.api("GET", "interviews/roles?q=receptionist")[1]["roles"][0]["slug"] == "receptionist")
        t.check("the overview lists roles with their taxonomy", any(r.get("domain") for r in c.api("GET", "interviews")[1]["roles"]))

        s, j = c.api("POST", "interviews/research", {"company": "Acme Retail", "url": site + "/about", "occupation": "retail-store"})
        r = j.get("research", {})
        t.check("Research a company reads the public page and keeps the source and time", s == 200 and r["status"] == "ready" and r["source"].startswith(site + "/about") and r["readAt"] and "grocery" in r["summary"].lower(), (s, str(j)[:300]))
        t.check("six practice questions about the company, for the chosen role", len(r["questions"]) == 6 and all("Acme Retail" in q["text"] for q in r["questions"]) and "Retail Store Associate" in r["questions"][0]["text"])
        t.check("they are labelled as practice questions, not the employer's real questions", "not confirmed questions from the employer" in r["label"])
        t.check("no AI request was made", db.q("select count(*) from ai_usage")[0][0] == 0)
        s, j = c.api("POST", "interviews/research", {"company": "Acme Retail", "url": site + "/private/team"})
        t.check("a page the site's robots.txt disallows is not read", s == 200 and j["research"]["status"] == "blocked", (s, j))
        other = Client(inst.base); other.register("iv-b@test.local")
        t.check("another customer cannot open someone's research", other.api("GET", f"interviews/research/{r['id']}")[0] == 404)
        for i in range(3):
            c.api("POST", "interviews/research", {"company": f"Co {i}", "url": site + "/about"})
        s, j = c.api("POST", "interviews/research", {"company": "One more", "url": site + "/about"})
        t.check("five researches a day per customer", s == 422 and "5 companies a day" in str(j), (s, j))

        # Timer and revision: a finished session from the earlier suites' shape.
        import json, uuid
        qv = db.q("select current_version_id from interview_questions order by id limit 1")[0][0]
        sid = str(uuid.uuid4())
        res = json.dumps({"summary": "x", "next_step": "y", "answers": [{"position": 1, "scores": {"relevance": 3, "structure": 4, "evidence": 3, "communication": 4, "role_alignment": 3}, "strengths": [], "improve": ["Add a number"], "quote": "", "rewrite": "Better", "missing": ""}]})
        db.q("insert into interview_sessions(id,owner_id,occupation,band,objective,questions_json,status,evaluated_at,created_at,updated_at) values(?,?,?,?,?,?,?,datetime('now'),datetime('now'),datetime('now'))", sid, uid, "retail-store", "fresher", "first_job", "[]", "evaluated")
        db.q("insert into interview_answers(session_id,question_version_id,position,answer,created_at,updated_at) values(?,?,1,'My earlier answer',datetime('now'),datetime('now'))", sid, qv)
        db.q("insert into interview_evaluations(session_id,level,result_json,created_at) values(?,?,?,datetime('now'))", sid, "Confident", res)
        s, j = c.api("GET", "interviews/revision")
        q = j["questions"][0] if s == 200 and j.get("questions") else {}
        t.check("revision mode shows past questions with the earlier answer and what to improve", q.get("earlier") == "My earlier answer" and q.get("improve") == ["Add a number"], j)
        p = c.api("GET", "interviews")[1]["progress"]
        b = {x["key"]: x["earned"] for x in p["badges"]}
        t.check("V7 badge names: First Practice, 5 Sessions, Clear Communication, STAR Practice, Consistency Milestone", [x["name"] for x in p["badges"]] == ["First Practice", "5 Sessions", "Clear Communication", "STAR Practice", "Consistency Milestone"] and b["first_practice"] and b["clear_communication"] and b["star_practice"], p["badges"])
        t.check("revision counts as a practice day but not as a role", p["practisedToday"] and p["roles"] <= 1, p)
        open_sid = str(uuid.uuid4())
        db.q("insert into interview_sessions(id,owner_id,occupation,band,objective,questions_json,status,created_at,updated_at) values(?,?,?,?,?,?,?,datetime('now'),datetime('now'))", open_sid, uid, "retail-store", "fresher", "first_job", "[]", "in_progress")
        db.q("insert into interview_answers(session_id,question_version_id,position,answer,created_at,updated_at) values(?,?,1,'',datetime('now'),datetime('now'))", open_sid, qv)
        s, _ = c.api("POST", f"interviews/{open_sid}/answer", {"position": 1, "answer": "Timed answer", "seconds": 95})
        t.check("an answer saves the time taken when the timer is on", s == 200 and db.q("select seconds from interview_answers where session_id=?", open_sid)[0][0] == 95)

        s, h, b = c.raw("GET", "/interview-questions/")
        hub = b.decode("utf8", "ignore")
        import re
        m = re.search(r'href="(/interview-questions/[a-z0-9-]+/)"', hub)
        s2, h2, b2 = c.raw("GET", m.group(1)) if m else (0, {}, b"")
        t.check("public interview pages link to practice in the account", s2 == 200 and b"Practise your answers" in b2 and b"view%3Dinterview" in b2, (s2, m and m.group(1)))
        t.check("the practice page script has the timer, role search, research and revision", all(k in open(ctx.public + "/interview-practice.js", encoding="utf8").read() for k in ("data-ip-clock", "data-ip-find", "data-ip-research", "data-ip-revise")))
    finally:
        inst.stop(); srv.shutdown()
