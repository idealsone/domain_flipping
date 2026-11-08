"""Check my writing: weak words, tone and basic grammar in resumes and cover letters, run on the server with no outside service,
and the optional full grammar check (LanguageTool) that runs only when the owner switches it on and the customer presses it."""
import http.server, json, os, threading, urllib.parse
NAME = "Writing, tone and grammar check"

LT_HITS = []


class LT(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def do_POST(self):
        body = urllib.parse.parse_qs(self.rfile.read(int(self.headers.get("Content-Length", 0))).decode())
        LT_HITS.append({"path": self.path, "body": body})
        text = body.get("text", [""])[0]
        at = text.find("an resume")
        matches = [{"message": "Use “a” instead of “an” here.", "offset": at, "length": 9, "replacements": [{"value": "a resume"}]}] if at >= 0 else []
        out = json.dumps({"matches": matches}).encode()
        self.send_response(200); self.send_header("Content-Type", "application/json"); self.end_headers(); self.wfile.write(out)


def resume(**kw):
    d = {"version": "1", "title": "Mine", "template": "suite-prism", "accent": "#1557d2", "font": "Arial", "order": ["summary", "experience"], "personal": {"name": "Asha Rao", "email": "asha@example.com"},
         "summary": "Recruiter with four years of hiring for retail and logistics teams across Bengaluru and Pune.",
         "skills": "Sourcing, Screening, Excel, Naukri", "experience": [{"role": "Recruiter", "company": "Acme", "start": "2021", "current": True,
         "bullets": "Hired 120 store staff in 2024 across 14 locations.\nCut time to hire from 30 to 18 days by running weekly walk-in drives."}],
         "education": [], "projects": [], "certifications": []}
    d.update(kw); return d


def letter(**kw):
    d = {"version": 1, "title": "L", "template": "letter-prism", "personal": {"name": "Asha Rao"}, "role": "Recruiter", "company": "Beta",
         "salutation": "Dear Hiring Manager,", "opening": "I am applying for the Recruiter role at Beta.",
         "evidence": "At Acme I hired 120 store staff in 2024 and cut time to hire from 30 to 18 days.",
         "motivation": "Beta is opening new stores in Pune, and I know that market well.", "closing": "Thank you for considering my application."}
    d.update(kw); return d


def run(ctx, t):
    if not ctx.preview: return
    src = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
    ws = open(os.path.join(ctx.app, "resources/frontend/workspace.html"), encoding="utf8").read()
    js = open(os.path.join(src, "public_html", "writing-check.js"), encoding="utf8").read()
    t.check("the workspace loads the writing check", "/writing-check.js" in ws)
    t.check("the builders get a Check my writing button", "Check my writing" in js and "studio-actions" in js and "letterStatus" in js)
    t.check("the full grammar button only appears when the owner switched it on", "c.fullGrammar ?" in js and "LanguageTool" in js)

    db = ctx.db(); c = ctx.client(); c.register("write-a@test.local")
    chk = lambda typ, data, **kw: c.api("POST", "writing/check", {"type": typ, "data": data, **kw})
    s, j = chk("resume", resume()); r = j.get("check", {})
    t.check("a clean resume has no suggestions", s == 200 and r.get("issues") == [] and r.get("checked", 0) >= 3, (s, str(j)[:300]))
    t.check("the full grammar check is off by default", r.get("fullGrammar") is False)

    weak = resume(experience=[{"role": "Recruiter", "company": "Acme", "bullets": "Responsible for hiring store staff across the city\nI managed walk-in drives for retail roles\nManaged the  the joining paperwork\nManaged recieved offer letters\nManaged 40 joining calls a week"}])
    s, j = chk("resume", weak); iss = j["check"]["issues"]
    rules = {(x["rule"], x["match"].lower()) for x in iss}
    find = lambda rule, m=None: next((x for x in iss if x["rule"] == rule and (m is None or x["match"].lower() == m)), None)
    rf = find("weak", "responsible for")
    t.check("'responsible for' is flagged with stronger verbs to try", rf and "Led" in rf["try"] and rf["section"] == "Experience · Recruiter", rf)
    t.check("a bullet with no number is flagged", find("no-number") is not None)
    t.check("'I' in a resume bullet is flagged", find("first-person", "i") is not None)
    rv = find("repeat-verb")
    t.check("three bullets starting with the same verb are flagged with alternatives", rv and rv["match"] == "Managed" and "Led" in rv["try"], rv)
    t.check("a misspelling is flagged with the right spelling", (find("spelling", "recieved") or {}).get("try") == ["received"])
    t.check("a repeated word is flagged", find("repeat-word", "the  the") is not None or find("repeat-word", "the the") is not None, rules)
    t.check("a double space is flagged", find("double-space") is not None)
    t.check("grammar comes first, then tone, then writing", [x["kind"] for x in iss] == sorted([x["kind"] for x in iss], key=["grammar", "tone", "writing"].index))
    t.check("the counts add up", sum(j["check"]["counts"].values()) == len(iss))
    t.check("the excerpt shows the line the suggestion is about", "Responsible for hiring" in rf["excerpt"])

    s, j = chk("resume", resume(summary="i am a hard-working recruiter. unfortunately i tried to move into HR."))
    iss = j["check"]["issues"]; got = {x["rule"] for x in iss}; words = {x["match"].lower() for x in iss}
    t.check("a lowercase 'i' is flagged once, not also as a lowercase start", "lowercase-i" in got and "start-case" not in got, got)
    t.check("a line that starts in lowercase is flagged", any(x["rule"] == "start-case" and x["match"] == "hired" for x in chk("resume", resume(experience=[{"role": "Recruiter", "bullets": "hired 20 store staff in 2024."}]))[1]["check"]["issues"]))
    t.check("a sentence that starts in lowercase is flagged", "sentence-case" in got, got)
    t.check("a buzzword is flagged", "hard-working" in words)
    t.check("unsure and negative words are flagged as tone", all(any(x["kind"] == "tone" and x["match"].lower() == w for x in iss) for w in ("unfortunately", "tried to")))

    s, j = chk("letter", letter(salutation="Hey team,", closing="Please revert back asap. Hire me, you won't regret it!!"))
    iss = j["check"]["issues"]; words = {x["match"].lower() for x in iss}
    t.check("a casual greeting in a letter is flagged", "hey" in words, words)
    t.check("pushy lines in a letter are flagged", {"hire me", "asap", "you won't regret"} <= words, words)
    t.check("Indian-English phrasing is flagged with a plain alternative", "revert back" in words and "reply" in next(x for x in iss if x["match"].lower() == "revert back")["try"])
    t.check("'!!' is flagged", "!!" in words)
    t.check("letters are labelled by part", {"Greeting", "Closing"} <= {x["section"] for x in iss})
    s, j = chk("letter", letter())
    t.check("a clean letter has no suggestions", s == 200 and j["check"]["issues"] == [], str(j)[:300])
    s, j = chk("letter", letter(opening="Myself Asha Rao, applying for the Recruiter role."))
    t.check("'Myself Asha' is flagged with 'I am'", any(x["rule"] == "myself" and x["try"] == ["I am"] for x in j["check"]["issues"]), str(j)[:300])
    t.check("skills lists are not checked for comma spacing", not any(x["section"] == "Skills" and x["rule"] == "space-after" for x in chk("resume", resume(skills="Excel,Word,Naukri"))[1]["check"]["issues"]))

    s, rj = c.api("POST", "resumes", {"data": weak}); t.check("save a resume to check by id", s == 200, (s, str(rj)[:200]))
    s, j = c.api("POST", "writing/check", {"type": "resume", "id": rj.get("id")})
    t.check("a saved resume can be checked by its id", s == 200 and any(x["rule"] == "weak" for x in j["check"]["issues"]), (s, str(j)[:200]))
    other = ctx.client(); other.register("write-b@test.local")
    t.check("someone else's resume cannot be checked", other.api("POST", "writing/check", {"type": "resume", "id": rj.get("id")})[0] == 404)
    t.check("a visitor who is not signed in cannot use the check", ctx.client().api("POST", "writing/check", {"type": "resume", "data": resume()})[0] == 401)
    t.check("the full grammar check is refused while it is off", chk("resume", resume(), full=True)[0] == 422)
    t.check("nothing was sent to LanguageTool while it is off", LT_HITS == [])
    t.check("the basic check records no usage", db.q("select count(*) from product_usage where action='grammar'")[0][0] == 0)

    # owner switches the full grammar check on, pointing at a stand-in LanguageTool server
    srv = http.server.ThreadingHTTPServer(("127.0.0.1", 0), LT); port = srv.server_address[1]
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    try:
        o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        s, g = o.api("POST", "admin/providers", {"service": "grammar", "values": {"url": f"http://127.0.0.1:{port}", "username": "owner@example.com", "premiumApiKey": "lt-key-1"}})
        t.check("the owner saves LanguageTool settings", s == 200, (s, str(g)[:200]))
        s, g = o.api("GET", "admin/providers"); t.check("the LanguageTool key is never shown back", "lt-key-1" not in str(g) and g["providers"]["grammar"]["premiumApiKey"] == {"configured": True})
        s, g = o.api("GET", "admin/content/integrations"); d = g["data"]; d["grammar"] = True
        t.check("the owner switches the full grammar check on", o.api("POST", "admin/content/integrations", {"data": d, "revision": g["revision"]})[0] == 200)
        s, j = chk("resume", resume(summary="Recruiter who wrote an resume guide for 300 students."))
        t.check("the basic check still sends nothing", s == 200 and LT_HITS == [] and j["check"]["fullGrammar"] is True and j["check"]["fullGrammarLeft"] == 20)
        s, j = chk("resume", resume(summary="Recruiter who wrote an resume guide for 300 students."), full=True)
        lt = [x for x in j.get("check", {}).get("issues", []) if x["rule"] == "languagetool"]
        t.check("pressing the full check adds LanguageTool's findings to the right section", s == 200 and len(lt) == 1 and lt[0]["section"] == "Summary" and lt[0]["match"] == "an resume" and lt[0]["try"] == ["a resume"], (s, str(j)[:300]))
        hit = LT_HITS[-1] if LT_HITS else {"body": {}}
        t.check("LanguageTool gets the text, British English and the Premium key", hit.get("path") == "/v2/check" and hit["body"].get("language") == ["en-GB"] and hit["body"].get("apiKey") == ["lt-key-1"] and "an resume" in hit["body"].get("text", [""])[0])
        t.check("the skills list is not sent", "Naukri" not in hit["body"].get("text", [""])[0])
        t.check("one full check is counted for the day", j["check"]["fullGrammarLeft"] == 19 and db.q("select count(*) from product_usage where action='grammar'")[0][0] == 1)
        uid = db.q("select id from users where email='write-a@test.local'")[0][0]
        for n in range(19):
            db.q("insert into product_usage(id,owner_id,action,reference,created_at,updated_at) values(?,?, 'grammar','languagetool',datetime('now'),datetime('now'))", f"00000000-0000-0000-0000-{n:012d}", uid)
        s, j = chk("resume", resume(), full=True)
        t.check("after 20 full checks in a day the next one is refused with a clear message", s == 422 and "today" in str(j), (s, str(j)[:160]))
        t.check("the basic check keeps working after the daily limit", chk("resume", resume())[0] == 200)
        d["grammar"] = False; g = o.api("GET", "admin/content/integrations")[1]
        o.api("POST", "admin/content/integrations", {"data": d, "revision": g["revision"]})
    finally:
        srv.shutdown()

    s, _, priv = ctx.client().page("/privacy.html")
    t.check("the privacy policy names LanguageTool and says the basic check sends nothing", s == 200 and "LanguageTool" in priv and "sends your text nowhere" in priv)
