"""3.58.0 stronger agents (V7 §29, §37; Growth plan §11.2, §12.1-§12.2): SEO Head and Growth & Revenue daily, weekly and
monthly reports, traced runs (run id, rules version, model, sources, knowledge version), corrective records with every
§11.2 field that also open a task, and the change feed that shows which agent is behind."""
import json
NAME = "Agent cadences, run evidence, corrective actions, change feed"

NOW = "\\Carbon\\Carbon::parse('2026-11-12 10:00', 'Asia/Kolkata')"


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    inst = Instance(ctx.app, ctx.public, preview=True).start()
    def php(code):
        r = inst.artisan("tinker", "--execute=" + code)
        return r.stdout + r.stderr
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        status = lambda: json.loads(php("echo json_encode(App\\Services\\KnowledgeSync::status());").strip().splitlines()[-1])
        t.check("the three new tables exist", all(db.q(f"select count(*) from {x}")[0][0] >= 0 for x in ("agent_reports", "agent_corrections", "config_changes")))

        # Change feed: watched areas only.
        before = db.q("select count(*) from config_changes")[0][0]
        php("App\\Services\\Settings::put('plans', App\\Services\\Settings::get('plans', []));")
        php("App\\Services\\Settings::put('speed', App\\Services\\Settings::get('speed', []));")
        rows = db.q("select area, summary, revision from config_changes order by id")
        t.check("saving plans writes one change with its revision; speed settings are not agent knowledge", len(rows) == before + 1 and rows[-1][0] == "plans" and "Plans and prices" in rows[-1][1] and rows[-1][2] >= 1, rows)
        kv = db.q("select max(id) from config_changes")[0][0]

        # Growth daily: November target 100, nothing sold yet -> red, a corrective record and a task.
        out = php(f"echo json_encode(App\\Services\\AgentCadence::run('growth', 'daily', {NOW}));")
        t.check("the Growth & Revenue daily report runs for yesterday", "2026-11-11" in out, out[:300])
        rep = db.q("select data_json from agent_reports where agent='growth' and cadence='daily' and period='2026-11-11'")
        d = json.loads(rep[0][0]) if rep else {}
        t.check("it covers registrations, verified, resumes, payments ok and failed, paid customers, revenue, order value and abandoned checkouts",
                all(k in d for k in ("signups", "verified", "startedResume", "payments", "paymentsFailed", "paidCustomers", "grossPaise", "aovPaise", "abandonedCheckouts")), list(d)[:30])
        t.check("an unmeasured number is null, not zero", d.get("aovPaise") is None)
        t.check("the month's pace and band are in the report", d.get("month", {}).get("target") == 100 and d["month"]["band"] == "red", d.get("month"))
        c = db.q("select metric, target, actual, variance, evidence, root_cause, action, owner, deadline, expected, review_date, task_id from agent_corrections where agent='growth'")
        t.check("a missed target writes every §11.2 field", len(c) == 1 and all(c[0][:11]), c)
        t.check("deadline in 7 days, review a week after", c and str(c[0][8]) == "2026-11-19" and str(c[0][10]) == "2026-11-26", c[:1])
        task = db.q("select agent, status, priority, detail from ai_tasks where id=?", c[0][11]) if c and c[0][11] else []
        t.check("the correction opens a task waiting for approval, with every field in it", task and task[0][0] == "growth" and task[0][1] == "submitted" and task[0][2] == "high"
                and all(x in task[0][3] for x in ("Target:", "Actual:", "Variance:", "Evidence:", "Root cause:", "Corrective action:", "Owner:", "Deadline:", "Expected result:", "Review date:")), task)
        php(f"App\\Services\\AgentCadence::run('growth', 'daily', {NOW});")
        t.check("running the same report again does not duplicate the correction or the task", db.q("select count(*) from agent_corrections")[0][0] == 1 and db.q("select count(*) from ai_tasks where agent='growth'")[0][0] == 1)

        # Run evidence.
        r = db.q("select run_uid, prompt_version, model, sources_json, knowledge_version, task, status from agent_runs order by id desc limit 1")[0]
        t.check("each run keeps its run id, rules version, model (none), sources and knowledge version", r[0] and len(r[0]) == 36 and r[1].startswith("cadence-") and r[2] == "" and "purchases" in r[3] and r[4] == kv and r[5] == "daily-report" and r[6] == "ok", r)

        # Knowledge sync: growth worked from the latest version, SEO never ran.
        k = status()
        g = next(a for a in k["agents"] if a["agent"] == "growth"); s_ = next(a for a in k["agents"] if a["agent"] == "seo")
        t.check("an agent that just worked is up to date; one that never worked is behind", g["version"] == kv and g["changesSince"] == 0 and not g["stale"] and s_["stale"], k)
        php("App\\Services\\Settings::put('referrals', App\\Services\\Settings::get('referrals', []));")
        k = status()
        t.check("a later change is counted against the agent", next(a for a in k["agents"] if a["agent"] == "growth")["changesSince"] == 1, k)

        # SEO Head: broken pages (daily) and low click rate (weekly).
        db.q("insert into seo_pages (path, important, http_status, created_at, updated_at) values ('/gone.html', 1, 404, datetime('now'), datetime('now'))")
        php("App\\Services\\Settings::put('searchData', ['startDate' => '2026-10-15', 'endDate' => '2026-11-10', 'query' => [['key' => 'resume format for freshers', 'clicks' => 1, 'impressions' => 400, 'ctr' => 0.0025, 'position' => 6.2], ['key' => 'ats resume checker', 'clicks' => 3, 'impressions' => 90, 'ctr' => 0.033, 'position' => 14]]]);")
        php(f"App\\Services\\AgentCadence::run('seo', 'daily', {NOW});")
        php(f"App\\Services\\AgentCadence::run('seo', 'weekly', {NOW});")
        sd = json.loads(db.q("select data_json from agent_reports where agent='seo' and cadence='weekly'")[0][0])
        t.check("the SEO weekly report lists searches close to page 1 and searches shown but not clicked", any(x["query"] == "ats resume checker" for x in sd["nearWins"]) and sd["lowClickRate"][0]["query"] == "resume format for freshers", sd)
        t.check("the weekly period is last ISO week", db.q("select period from agent_reports where agent='seo' and cadence='weekly'")[0][0] == "2026-W45")
        m = {x[0] for x in db.q("select metric from agent_corrections where agent='seo'")}
        t.check("a page that does not open and a low click rate each become a corrective action", {"Pages that do not open", "Searches shown but not clicked"} <= m, m)
        t.check("the SEO task names the broken page", db.q("select count(*) from ai_tasks where agent='seo' and detail like '%/gone.html (404)%'")[0][0] == 1)

        # Monthly reports.
        php(f"App\\Services\\AgentCadence::run('growth', 'monthly', {NOW});")
        gm = json.loads(db.q("select data_json from agent_reports where agent='growth' and cadence='monthly'")[0][0])
        t.check("the Growth monthly report has refund rate, referral purchases, margin, partner sign-ups, interview use and plan mix", gm["period"] == "2026-10" and all(k in gm for k in ("refundRatePct", "referralPurchases", "margin", "partnerSignups", "interviewSessions", "plans")), list(gm))
        php(f"App\\Services\\AgentCadence::run('seo', 'monthly', {NOW});")
        sm = json.loads(db.q("select data_json from agent_reports where agent='seo' and cadence='monthly'")[0][0])
        t.check("the SEO monthly report has section coverage and organic sign-ups", "sections" in sm and "organicSignups" in sm, list(sm))

        # Scheduler: everything due is written once per period.
        php(f"App\\Services\\AgentCadence::due({NOW});")
        t.check("the scheduler writes the remaining reports (six in all)", db.q("select count(*) from agent_reports")[0][0] == 6)
        n = db.q("select count(*) from agent_runs")[0][0]
        php(f"App\\Services\\AgentCadence::due({NOW});")
        t.check("and nothing again in the same period", db.q("select count(*) from agent_runs")[0][0] == n)
        php("App\\Services\\AgentCadence::due(\\Carbon\\Carbon::parse('2026-11-13 06:30', 'Asia/Kolkata'));")
        t.check("nothing is written before 7 AM India time", db.q("select count(*) from agent_runs")[0][0] == n)
        t.check("no AI request was made for any report", db.q("select count(*) from ai_usage")[0][0] == 0)

        # Console.
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        s, j = o.api("GET", "admin/growth/agents/cadence")
        t.check("the console shows the six latest reports, corrections, knowledge state, changes and traced runs", s == 200 and len(j["reports"]) == 6 and j["corrections"] and j["knowledge"]["agents"] and j["changes"][0]["area"] == "searchData" or (s == 200 and j["changes"][0]["area"] == "referrals"), str(j)[:300])
        t.check("traced runs show rules, sources and run id", s == 200 and j["runs"] and j["runs"][0]["rules"].startswith("cadence-") and j["runs"][0]["sources"] and j["runs"][0]["model"] is None, j.get("runs", [])[:1])
        s, j = o.api("GET", "admin/growth/agents/corrections?agent=seo")
        t.check("the corrective actions list is paged and filtered", s == 200 and j["total"] == 2 and all(r["agent"] == "seo" for r in j["rows"]), (s, str(j)[:200]))
        st, h, b = o.raw("GET", "/api/admin/growth/agents/corrections?export=csv")
        t.check("and downloadable with every §11.2 column", st == 200 and b"Root cause" in b and b"Review date" in b and b"Expected result" in b, (st, b[:200]))
        s, j = o.api("POST", "admin/growth/agents/cadence/run", {"agent": "growth", "cadence": "weekly"})
        t.check("the owner can write a report now", s == 200 and "Weekly report" in j.get("summary", ""), (s, j))
        s, _ = o.api("POST", "admin/growth/agents/cadence/run", {"agent": "growth", "cadence": "yearly"})
        t.check("an unknown cadence is refused", s == 422)
        cu = Client(inst.base); cu.register("cad-a@test.local")
        t.check("customers cannot read agent reports", cu.api("GET", "admin/growth/agents/cadence")[0] in (403, 404))
        js = open(ctx.app + "/resources/console/agents.js").read(); gj = open(ctx.app + "/resources/console/growth.js").read()
        t.check("the Growth page has an Agent reports tab", "cadence: 'Agent reports'" in gj and "cadence }" in js and "agents.js?v=3.58.0" in gj)
    finally:
        inst.stop()
