"""A paid customer's package name: never "Free" while a paid plan is active, even when the order's package key was renamed or retired."""
import json
import uuid
NAME = "Paid package name on Billing"


def run(ctx, t):
    db = ctx.db()
    uid = lambda e: db.q("select id from users where email=?", e)[0][0]

    def give(email, plan, limits):
        db.q("insert into purchases(id,owner_id,plan,amount_paise,status,provider,payment_reference,is_live,limits_json,access_days,purchased_at,access_until,created_at,updated_at) values(?,?,?,29900,'paid','phonepe',?,1,?,10,datetime('now'),datetime('now','+10 days'),datetime('now'),datetime('now'))",
             str(uuid.uuid4()), uid(email), plan, "pl-" + uuid.uuid4().hex[:8], json.dumps(limits))

    free = ctx.client(); free.register("pl-free@test.local")
    s, a = free.api("GET", "allowance")
    t.check("a free account still reads Free", s == 200 and a["planName"] == "Free" and not a["paid"], f"{s} {str(a)[:160]}")

    cases = [
        ("pl-jh@test.local", "job-hunt", {"type": "plan", "name": "Job Hunt", "rank": 2, "savedResumes": 5, "savedLetters": 5, "atsScans": 20}, "Job Hunt"),
        ("pl-old@test.local", "fresher-basic", {"type": "plan", "name": "Fresher Basic", "rank": 2, "savedResumes": 5, "savedLetters": 5, "atsScans": 20}, "Fresher Basic"),
        ("pl-old2@test.local", "fresher-basic", {"type": "plan", "rank": 2, "savedResumes": 5, "savedLetters": 5, "atsScans": 20}, "Job Hunt"),
        ("pl-gone@test.local", "launch-offer", {"type": "plan", "name": "Launch Offer", "rank": 2, "savedResumes": 10, "savedLetters": 10, "atsScans": 20}, "Launch Offer"),
        ("pl-noname@test.local", "launch-offer", {"type": "plan", "rank": 2, "savedResumes": 10, "savedLetters": 10, "atsScans": 20}, "Launch Offer"),
    ]
    for email, plan, limits, want in cases:
        c = ctx.client(); c.register(email)
        give(email, plan, limits)
        s, a = c.api("GET", "allowance")
        t.check(f"{plan} order shows '{want}', not Free", s == 200 and a["paid"] and a["planName"] == want, f"{s} {a.get('planName')} paid={a.get('paid')}")

    # 3.35.1: no analytics consent box; visits are counted on CVTara's own server without third-party analytics.
    s, _, js = ctx.client().raw("GET", "/owner-public.js")
    js = js.decode() if isinstance(js, bytes) else js
    t.check("public script has no consent box and loads no Google Analytics", s == 200 and "Allow analytics" not in js and "googletagmanager" not in js and "/api/traffic" in js, s)
    s, _, html = ctx.client().raw("GET", "/privacy.html")
    html = html.decode() if isinstance(html, bytes) else html
    t.check("privacy policy no longer mentions Google Analytics or accepting analytics cookies", s == 200 and "Google Analytics" not in html and "accept analytics" not in html, s)
    row = db.q("select value_json from site_settings where key='integrations'")
    integ = json.loads(row[0][0]) if row else json.load(open(__import__("os").path.join(__import__("os").path.dirname(__file__), "../../app/resources/data/defaults.json")))["integrations"]
    integ["analytics"] = True
    db.q("delete from site_settings where key='integrations'")
    db.q("insert into site_settings(key,value_json,revision,created_at,updated_at) values('integrations',?,1,datetime('now'),datetime('now'))", json.dumps(integ))
    g = ctx.client()
    g.page("/")
    before = db.q("select count(*) from page_views where path='/pricing.html'")[0][0]
    s, _ = g.api("POST", "traffic", {"consent": True, "path": "/pricing.html", "source": "direct"})
    t.check("a visit is counted without any consent click", s == 200 and db.q("select count(*) from page_views where path='/pricing.html'")[0][0] == before + 1, s)

    # A Quick Download bought while an older package of the same level runs keeps its own credit (seen live on 7 Nov 2026).
    c = ctx.client(); c.register("pl-stack@test.local")
    give("pl-stack@test.local", "fresher-basic", {"type": "plan", "name": "Fresher Basic", "rank": 1, "savedResumes": 10, "savedLetters": 10, "atsScans": 20})
    db.q("update purchases set access_until=datetime('now','+9 days') where owner_id=?", uid("pl-stack@test.local"))
    db.q("insert into purchases(id,owner_id,plan,amount_paise,status,provider,payment_reference,is_live,limits_json,access_days,purchased_at,access_until,created_at,updated_at) values(?,?,'quick',9900,'paid','phonepe','pl-q',1,?,7,datetime('now'),datetime('now','+7 days'),datetime('now'),datetime('now'))",
         str(uuid.uuid4()), uid("pl-stack@test.local"), json.dumps({"type": "plan", "name": "Quick Download", "rank": 1, "savedResumes": 1, "savedLetters": 0, "atsScans": 0, "oneResume": True}))
    s, a = c.api("GET", "allowance")
    t.check("the older package still names the plan", s == 200 and a["planName"] == "Fresher Basic", a.get("planName"))
    t.check("the Quick Download credit is added, not lost", s == 200 and a["credits"]["resumes"]["total"] == 11 and a["credits"]["letters"]["total"] == 10 and a["credits"]["scans"]["total"] == 20, str(a.get("credits"))[:200])
