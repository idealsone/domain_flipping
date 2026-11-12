"""V7 step 2b (3.50.0): practice progress on the Interview practice page. Levels, a few badges and a streak where one rest
day between practice days does not break it. Derived from the customer's own sessions and practice visits only; no
rankings and no new tables."""
import json, os, re, uuid
NAME = "V7 step 2b: practice levels, badges and streak"


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    inst = Instance(ctx.app, ctx.public, preview=True).start()
    php = lambda code: inst.artisan("tinker", "--execute=" + code)
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        o.api("POST", "admin/interviews/approve", {"occupation": "general"})
        o.api("POST", "admin/interviews/approve", {"occupation": "sales-executive"})
        c = Client(inst.base); c.register("pp-a@test.local"); c.page("/workspace.html")
        uid = db.q("select id from users where email='pp-a@test.local'")[0][0]

        s, ov = c.api("GET", "interviews")
        p = ov.get("progress", {})
        t.check("a new customer starts with no level, no streak and no badges", s == 200 and p.get("level") is None and p.get("streak") == 0 and not any(b["earned"] for b in p.get("badges", [])), p)
        t.check("there are a few badges with plain explanations", 4 <= len(p.get("badges", [])) <= 8 and all(b["about"] for b in p["badges"]))

        c.api("GET", "interviews/questions?occupation=sales-executive&band=fresher")
        c.api("GET", "interviews/questions?occupation=sales-executive&band=fresher")
        t.check("opening practice questions counts once per role per day", db.q("select count(*) from activity_events where owner_id=? and event='interview_practice'", uid)[0][0] == 1)
        p = c.api("GET", "interviews")[1]["progress"]
        t.check("practising today starts a streak", p["streak"] == 1 and p["practisedToday"] is True, p)

        # Practice on days -6, -5, -3 (one rest day), -2, -1 and today: one six-visit streak.
        for d in (6, 5, 3, 2, 1):
            php(f"DB::table('activity_events')->insert(['id' => (string) Str::uuid(), 'owner_id' => {uid}, 'event' => 'interview_practice', 'reference' => 'role-{d % 3}', 'created_at' => now()->subDays({d}), 'updated_at' => now()]);")
        p = c.api("GET", "interviews")[1]["progress"]
        t.check("one rest day between practice days keeps the streak", p["streak"] == 6 and p["bestStreak"] == 6, p)
        badges = {b["key"]: b["earned"] for b in p["badges"]}
        t.check("Consistency Milestone is earned at a 5-day streak (3.57 V7 badge names)", badges.get("consistency") and p.get("roles") >= 3, (badges, p.get("roles")))

        # Without today's visit the streak still stands (resting today); without the last four days it ends.
        php(f"DB::table('activity_events')->where('owner_id', {uid})->where('created_at', '>=', now()->subHours(1))->delete();")
        p = c.api("GET", "interviews")[1]["progress"]
        t.check("resting today does not break the streak", p["streak"] == 5 and p["practisedToday"] is False, p)
        php(f"DB::table('activity_events')->where('owner_id', {uid})->where('created_at', '>=', now()->subDays(3)->subHour())->delete();")
        p = c.api("GET", "interviews")[1]["progress"]
        t.check("more than one day without practice ends the current streak", p["streak"] == 0 and p["bestStreak"] == 2, p)

        # Levels and the remaining badges come from finished mock interviews.
        def finished(level, evidence, days_ago):
            sid = str(uuid.uuid4())
            res = json.dumps({"summary": "x", "next_step": "y", "answers": [{"position": 1, "scores": {"relevance": 3, "structure": evidence, "evidence": evidence, "communication": evidence, "role_alignment": 3}, "strengths": [], "improve": [], "quote": "", "rewrite": "", "missing": ""}]}).replace("'", "\\'")
            php(f"DB::table('interview_sessions')->insert(['id' => '{sid}', 'owner_id' => {uid}, 'occupation' => 'sales-executive', 'band' => 'fresher', 'objective' => 'first_job', 'questions_json' => '[]', 'status' => 'evaluated', 'evaluated_at' => now()->subDays({days_ago}), 'created_at' => now()->subDays({days_ago}), 'updated_at' => now()]);"
                f"DB::table('interview_evaluations')->insert(['session_id' => '{sid}', 'level' => '{level}', 'result_json' => '{res}', 'created_at' => now()]);")
        finished("Developing", 2, 4)
        p = c.api("GET", "interviews")[1]["progress"]
        t.check("the first finished mock interview gives a level and the First Practice badge", p["level"] == "Developing" and {b["key"]: b["earned"] for b in p["badges"]}["first_practice"], p)
        finished("Confident", 4, 0)
        finished("Interview ready", 3, 0)
        p = c.api("GET", "interviews")[1]["progress"]
        b = {x["key"]: x["earned"] for x in p["badges"]}
        t.check("later sessions move the level and show the change", p["level"] == "Interview ready" and p["previousLevel"] == "Confident" and p["completed"] == 3, p)
        t.check("Clear Communication and STAR Practice are earned; 5 Sessions not yet", b["clear_communication"] and b["star_practice"] and not b["five_sessions"], b)
        finished("Confident", 3, 0)
        finished("Confident", 3, 0)
        t.check("5 Sessions is earned at five finished mock interviews", {x["key"]: x["earned"] for x in c.api("GET", "interviews")[1]["progress"]["badges"]}["five_sessions"])

        other = Client(inst.base); other.register("pp-b@test.local"); other.page("/workspace.html")
        po = other.api("GET", "interviews")[1]["progress"]
        t.check("progress belongs to each customer alone", po["completed"] == 0 and po["streak"] == 0 and not any(x["earned"] for x in po["badges"]))
        js = open(os.path.join(ctx.public, "interview-practice.js"), encoding="utf8").read()
        t.check("the page says there are no rankings and the level is not a prediction", "There are no rankings" in js and "not a prediction" in js)
        t.check("no ranking or leaderboard endpoint exists", not re.search(r"leaderboard|rank(ing)?s?/", open(os.path.join(ctx.app, "app", "Http", "Controllers", "ApiController.php"), encoding="utf8").read(), re.I))
    finally:
        inst.stop()
