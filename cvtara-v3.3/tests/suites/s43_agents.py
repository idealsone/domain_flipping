"""SEO and growth agents as working systems (3.34.0): sign-up attribution by first page and source, the agent log,
page scan and Google index status, sitemap status, recrawl requests only for changed pages, the keyword database,
brand and cluster checks, the 11-step journey, revenue diagnosis, campaign and experiment registers, pause/resume,
and the agentStatus() hooks the Business assistant reads. Owner-only throughout; no invented numbers."""
import json, subprocess
NAME = "SEO and growth agents"


def row(keys, clicks, imp, pos):
    return {"keys": keys, "clicks": clicks, "impressions": imp, "ctr": clicks / imp if imp else 0, "position": pos}


def run(ctx, t):
    from lib.env import Instance
    from lib.fake_google import FakeGoogle
    from lib.http import Client
    from lib.db import Db
    g = FakeGoogle()
    U = "https://cvtara.com"
    g.sa_rows = {
        "query": [row(["cvtara"], 9, 40, 1.2), row(["ats resume checker"], 2, 300, 14.0), row(["how to write a cover letter"], 1, 120, 7.0), row(["naukri resume format"], 1, 90, 9.0)],
        "page": [row([U + "/"], 9, 40, 1.2), row([U + "/ats-checker/"], 2, 300, 14.0), row([U + "/cover-letter-examples/how-to-write/"], 1, 120, 7.0)],
        "query,page": [row(["cvtara", U + "/"], 9, 40, 1.2), row(["ats resume checker", U + "/ats-checker/"], 2, 300, 14.0), row(["how to write a cover letter", U + "/cover-letter-examples/how-to-write/"], 1, 120, 7.0), row(["naukri resume format", U + "/resume-examples/"], 1, 90, 9.0)],
    }
    g.not_indexed = {"/about.html"}
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"GOOGLE_API_BASE": g.base, "PHP_CLI_SERVER_WORKERS": "4"}).start()
    try:
        db = Db(inst.db); o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        tinker = lambda code: inst.artisan("tinker", "--execute=" + code)
        last = lambda r: (r.stdout.strip().splitlines() or [""])[-1]
        tinker("App\\Services\\Settings::put('seo', array_merge(App\\Services\\Settings::get('seo'), ['siteUrl' => '', 'searchIndexing' => true])); App\\Services\\Settings::put('integrations', array_merge(App\\Services\\Settings::get('integrations'), ['analytics' => true]));")

        t.check("agent pages are owner-only", all(Client(inst.base).api("GET", p)[0] in (401, 403) for p in ("admin/growth/agents", "admin/growth/seo/overview", "admin/growth/revenue", "admin/growth/seo/pages")))
        t.check("the agents console script is owner-only", Client(inst.base).raw("GET", "/console/agents.js")[0] == 404 and o.raw("GET", "/console/agents.js")[0] == 200)

        # Attribution: the first page and source of the visit are copied onto the new account.
        v = Client(inst.base); v.page("/")
        v.api("POST", "traffic", {"consent": True, "path": "/blog.html", "source": "internal"})
        v.api("POST", "traffic", {"consent": True, "path": "/ats-checker/", "source": "google", "campaign": "tpo-nov"})
        v.api("POST", "traffic", {"consent": True, "path": "/resume-builder/", "source": "referral"})
        v.form("/register", {"name": "Asha Rao", "email": "asha-attr@test.local", "password": "TestPassw0rd!!x", "password_confirmation": "TestPassw0rd!!x", "experienceLevel": "fresher", "profession": "Analyst", "category": "Technology", "terms": "1"})
        r = db.q("select signup_source, signup_landing, signup_campaign from users where email='asha-attr@test.local'")
        t.check("a sign-up keeps the first page, source and campaign of its visit (internal clicks never count as the first page)", r and tuple(r[0]) == ("google", "/ats-checker/", "tpo-nov"), r)
        w = Client(inst.base); w.page("/")
        w.form("/register", {"name": "No Consent", "email": "no-consent@test.local", "password": "TestPassw0rd!!x", "password_confirmation": "TestPassw0rd!!x", "experienceLevel": "fresher", "profession": "Analyst", "category": "Technology", "terms": "1"})
        r = db.q("select signup_source from users where email='no-consent@test.local'")
        t.check("without analytics consent the source stays unknown, never guessed", r and r[0][0] is None, r)
        v2 = Client(inst.base); v2.page("/")
        bad = v2.api("POST", "traffic", {"consent": True, "path": "/", "source": "google", "campaign": "<script>"})
        t.check("a bad campaign tag is refused", bad[0] == 422, bad)

        # Technical scan.
        s, j = o.api("POST", "admin/growth/seo/scan", {"limit": 60})
        t.check("a scan fetches pages like a visitor, important pages first", s == 200 and j.get("scanned", 0) >= 17, f"{s} {j}")
        home = db.q("select http_status, title, h1_count, canonical, schema_json, links_json from seo_pages where path='/'")
        t.check("the home page's title, heading, canonical, structured data and links are read", home and home[0][0] == 200 and "CVTara" in home[0][1] and home[0][2] >= 1 and '/resume-builder/' in json.loads(home[0][5]), home[0] if home else home)
        s, d = o.api("GET", "admin/growth/seo/pages?important=yes")
        t.check("the page list shows each important page with its issues and links in", s == 200 and d["total"] >= 15 and all("issues" in r and "linksIn" in r for r in d["rows"]), f"{s} {d.get('total')}")
        db.q("update seo_pages set issues_json='{\"title_long\":\"x\"}' where path='/about.html'")
        tinker("App\\Services\\SeoOps::scanOne('/about.html', true);")
        res = json.loads(last(tinker("echo json_encode(App\\Services\\Settings::get('seoResolved', []));")) or "[]")
        t.check("an issue that went away is counted as resolved", any(x["path"] == "/about.html" and x["issue"] == "title_long" for x in res), res[:3])

        # Search Console: URL Inspection, recrawl of changed pages only, sitemap status, keywords.
        t.check("index checks need Search Console and say so", o.api("POST", "admin/growth/seo/inspect", {})[0] == 422)
        key = subprocess.run(["openssl", "genpkey", "-algorithm", "RSA", "-pkeyopt", "rsa_keygen_bits:2048"], capture_output=True, text=True).stdout
        o.api("POST", "admin/providers", {"service": "analytics", "values": {"searchConsoleProperty": "sc-domain:cvtara.com", "searchConsoleServiceAccount": json.dumps({"type": "service_account", "client_email": "cvtara@proj.iam.gserviceaccount.com", "private_key": key})}})
        s, j = o.api("POST", "admin/growth/seo/inspect", {})
        t.check("Google's index status is checked for a batch of pages, important ones first", s == 200 and j.get("inspected") == 15 and len(g.inspected) == 15 and any(u.endswith("/about.html") for u in g.inspected), f"{s} {j}")
        ab = db.q("select verdict, coverage from seo_pages where path='/about.html'")[0]
        t.check("a page Google has not indexed is shown with Google's own reason", tuple(ab) == ("NEUTRAL", "Crawled - currently not indexed"), ab)
        out = json.loads(last(tinker("echo json_encode(App\\Services\\SeoOps::requestIndexing());")) or "{}")
        t.check("a not-indexed page is sent for recrawl once", out.get("requested") == 1, out)
        out = json.loads(last(tinker("echo json_encode(App\\Services\\SeoOps::requestIndexing());")) or "{}")
        t.check("an unchanged page is never resubmitted", out.get("skipped") is True, out)
        db.q("update seo_pages set fingerprint='changed' where path='/about.html'")
        out = json.loads(last(tinker("echo json_encode(App\\Services\\SeoOps::requestIndexing());")) or "{}")
        t.check("after the page changes it can be sent again", out.get("requested") == 1, out)
        tinker("App\\Services\\Settings::put('seoOps', ['inspectPerDay' => 15]);")
        out = json.loads(last(tinker("echo json_encode(App\\Services\\SeoOps::inspect());")) or "{}")
        t.check("the daily inspection cap is respected", out.get("skipped") is True and "cap" in out.get("summary", ""), out)
        tinker("App\\Services\\Settings::put('seoOps', []);")
        out = json.loads(last(tinker("echo json_encode(App\\Services\\SeoOps::sitemaps());")) or "{}")
        t.check("sitemap processing status is read from Search Console", out.get("list", [{}])[0].get("submitted") == 596 and out["list"][0]["warnings"] == 1, out)

        o.api("POST", "admin/growth/seo/run", {})
        tinker("App\\Services\\SeoOps::syncKeywords();")
        kw = {r[0]: r[1:] for r in db.q("select keyword, kind, intent, value, source, impressions from seo_keywords")}
        t.check("the owner's target keywords are seeded", all(k in kw for k in ("cvtara", "cv tara", "online resume builder", "ats resume scanner")) and kw["cvtara"][3] == "seed", list(kw)[:8])
        t.check("searches are classified by type, intent and value", kw.get("cvtara", [None])[0] == "brand" and kw["ats resume checker"][:3] == ("product", "transactional", "high") and kw["how to write a cover letter"][0] == "question", kw.get("ats resume checker"))
        t.check("Search Console numbers fill the keyword database", kw["ats resume checker"][4] == 300, kw["ats resume checker"])
        t.check("other brands' searches are kept out (owner rule)", "naukri resume format" not in kw)
        s, d = o.api("GET", "admin/growth/seo/keywords?kind=brand")
        t.check("the keyword list is paged and filterable", s == 200 and d["total"] >= 7 and all(r["kind"] == "brand" for r in d["rows"]), f"{s} {d.get('total')}")
        t.check("other brands cannot be added by hand either", o.api("POST", "admin/growth/seo/keyword", {"keyword": "zety resume"})[0] == 422)
        t.check("the owner can add a target keyword", o.api("POST", "admin/growth/seo/keyword", {"keyword": "resume format for freshers", "target": "/resume-builder/"})[0] == 200 and db.q("select source from seo_keywords where keyword='resume format for freshers'")[0][0] == "owner")

        s, ov = o.api("GET", "admin/growth/seo/overview")
        k = ov.get("kpis", {})
        t.check("SEO results: clicks, impressions, brand and non-brand split from Search Console", s == 200 and k.get("clicks") == 13 and k.get("brandImpressions") == 40 and k.get("nonBrandImpressions") == 510, k)
        t.check("SEO results: important pages indexed come from Google's checks", k.get("importantIndexed") is not None and k["importantIndexed"] < k["importantInspected"], k)
        t.check("SEO results: organic sign-ups come from attributed accounts", k.get("organicSignups30d") == 1 and k.get("signupsWithSource30d") == 1, k)
        t.check("pages Google prefers to show differently from the plan are found", any(r["keyword"] == "ats resume checker" for r in ov["opportunities"]["pageTwo"]["rows"]), ov["opportunities"]["pageTwo"])
        b = ov.get("brand", {})
        t.check("the brand result checks the home page and each main page, and admits Google picks sitelinks", b.get("checks") and any(l["label"] == "Resume vs JD Matcher" and l["path"] is None for l in b["sitelinks"]) and "Google chooses" in b.get("note", ""), b.get("checks"))
        t.check("topic clusters list supporting pages and suggested topics", len(ov.get("clusters", [])) == 4 and ov["clusters"][1]["suggestedTopics"], ov.get("clusters", [])[:1])
        s, d = o.api("GET", "admin/growth/seo/page?path=/about.html")
        t.check("each page has a history with a Search Console link for requesting indexing", s == 200 and "search-console/inspect" in d.get("inspectLink", ""), d)

        # Growth agent.
        s, rv = o.api("GET", "admin/growth/revenue?days=30")
        steps = [x["key"] for x in rv.get("funnel", {}).get("steps", [])]
        t.check("the customer journey has all 11 steps", s == 200 and len(steps) == 11 and steps[0] == "visitors" and steps[-1] == "upsell", steps)
        t.check("every diagnosis finding has cause, evidence, fix and a way to measure", rv.get("diagnosis", {}).get("findings") and all(all(f.get(x) is not None for x in ("cause", "evidence", "action", "measure")) for f in rv["diagnosis"]["findings"]), rv.get("diagnosis"))
        t.check("confirmed revenue and attributed revenue are reported apart, ad spend not tracked", "confirmedNetPaise" in rv["kpis"] and "attributedNetPaise" in rv["kpis"] and rv["kpis"]["adSpend"] is None, rv["kpis"])
        s, j = o.api("POST", "admin/growth/campaign-plan", {"name": "TPO mail Nov", "channel": "outreach", "status": "live", "outreach_list": "colleges", "utm": "tpo-nov", "start_date": "2026-11-01"})
        t.check("an outreach campaign cannot be registered without saying how permission was obtained", s == 422 and "permission" in str(j).lower(), f"{s} {j}")
        s, j = o.api("POST", "admin/growth/campaign-plan", {"name": "TPO mail Nov", "channel": "outreach", "status": "live", "outreach_list": "colleges", "utm": "tpo-nov", "consent": "Placement offices asked for it", "start_date": "2026-11-01", "cost_inr": 500, "audience": "Placement officers", "offer": "Free ATS checks for students"})
        rows = o.api("GET", "admin/growth/campaign-plans")[1].get("rows", [])
        t.check("a registered campaign counts its sign-ups from its utm tag", s == 200 and rows and rows[0]["result"]["signups"] == 1 and rows[0]["result"]["buyers"] == 0, rows[:1])
        s, j = o.api("POST", "admin/growth/experiment", {"title": "Bigger sign-up button", "hypothesis": "If the sign-up button is above the fold then more visitors sign up", "metric": "signups", "start_date": "2026-11-01", "end_date": "2026-11-30"})
        ex = o.api("GET", "admin/growth/experiments")[1].get("rows", [])
        t.check("an experiment gets a baseline and a running result from the database", s == 200 and ex and ex[0]["status"] == "running" and ex[0]["baseline"]["value"] is not None and ex[0]["result"]["value"] is not None, ex[:1])
        t.check("an experiment's end must not be before its start", o.api("POST", "admin/growth/experiment", {"title": "x", "hypothesis": "y", "metric": "signups", "start_date": "2026-11-10", "end_date": "2026-11-01"})[0] == 422)

        # Agent run, log, status hooks and pause.
        tinker("App\\Services\\Settings::put('growthOpsRun', []);")
        out = json.loads(last(tinker("echo json_encode(App\\Services\\GrowthOps::run(Carbon\\Carbon::parse('2026-11-06 09:00', 'Asia/Kolkata')));")) or "{}")
        t.check("the growth agent diagnoses once a day and logs it", "diagnose" in out and db.q("select count(*) from agent_runs where agent='growth' and task='diagnose' and status='ok'")[0][0] >= 1, out)
        out = last(tinker("echo json_encode(App\\Services\\GrowthOps::run(Carbon\\Carbon::parse('2026-11-06 10:00', 'Asia/Kolkata')));"))
        t.check("…and not twice on the same day", out == "[]", out)
        s, d = o.api("GET", "admin/growth/agents/log?agent=seo")
        t.check("the agent log lists SEO tasks with results", s == 200 and d["total"] >= 2 and {"scan", "inspect"} <= {r["task"] for r in d["rows"]}, f"{s} {d.get('total')}")
        need = {"status", "lastRun", "nextRun", "tasksDone7d", "tasksPending", "tasksFailed7d", "approvalsWaiting", "spendMonthPaise", "results"}
        for cls in ("App\\Services\\SeoAgent", "App\\Services\\Growth"):
            st = json.loads(last(tinker(f"echo json_encode({cls}::agentStatus());")) or "{}")
            t.check(f"{cls.split(chr(92))[-1]}::agentStatus() gives the Business assistant every field, with real numbers and their source", need <= set(st) and st["status"] in ("running", "paused", "off") and st["results"] and all({"label", "value", "period", "source"} <= set(r) for r in st["results"]), st)
        s, j = o.api("POST", "admin/growth/agents", {"agent": "seo", "auto": False})
        t.check("the owner can pause an agent", s == 200 and o.api("GET", "admin/growth/agents")[1]["seo"]["status"] == "paused")
        o.api("POST", "admin/growth/agents", {"agent": "seo", "auto": True})
        t.check("…and resume it", o.api("GET", "admin/growth/agents")[1]["seo"]["status"] == "running")
        bad = db.q("select count(*) from agent_runs where status='failed'")[0][0]
        t.check("no agent task failed in this run", bad == 0, db.q("select task, error from agent_runs where status='failed'"))
    finally:
        inst.stop(); g.stop()
