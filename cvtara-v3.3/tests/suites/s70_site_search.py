"""3.60.0 website and search (V7 §31, §34; V5.0 §2, §9; V5.5 §2; V6 SEO §4-§5, §13): the colleges, coaching and partners
page with its application form, About page copy, og:locale, the interview hub's practice section, the keyword backlog
mapped to pages, and the directory and brand listings register."""
import json
NAME = "Partners page, About, og:locale, interview hub, keyword backlog, listings register"


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    inst = Instance(ctx.app, ctx.public, preview=True).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        c = Client(inst.base)
        s, h, b = c.page("/partners.html")
        t.check("the partners page opens with its form", s == 200 and 'id="partnerForm"' in b and "For Colleges, Coaching and Partners" in b and "partners-page.js" in b, s)
        t.check("it promises no jobs and mentions no AI", "guarantee" in b and not any(w in b.lower() for w in (" ai ", "artificial intelligence", "chatgpt", "claude")))
        t.check("it has og:locale en_IN and page structured data", 'og:locale" content="en_IN"' in b and '"WebPage"' in b)
        s, j = c.api("POST", "partners/apply", {"organisation": "Demo College of Commerce", "kind": "tpo", "name": "Ravi Kumar", "email": "tpo@democollege.test", "city": "Pune", "size": "100 to 500", "message": "Final-year batch", "website": ""})
        t.check("the form sends an application", s == 200 and db.q("select org_name, kind from partner_applications")[0] == ("Demo College of Commerce", "tpo"), (s, j))
        s, h, b = c.raw("GET", "/sitemap.xml")
        t.check("the page is in the sitemap", b"/partners.html" in b)
        s, h, b = c.page("/about.html")
        t.check("About uses the approved positioning without invented claims", "From resume building to interview readiness." in b and "The problems we are working to solve" in b and "prepared automatically" in b and "/partners.html" in b)
        s, h, b = c.page("/interview-questions/")
        t.check("the interview hub links to practice in the account", s == 200 and "Practise in your CVTara account" in b and "view=interview" in b)
        t.check("og:locale is on every page", 'og:locale" content="en_IN"' in b)
        s, h, b = c.raw("GET", "/llms.txt")
        t.check("llms.txt lists the partners page", b"/partners.html" in b)

        n = db.q("select count(*) from seo_keywords where source='seed' and cluster!=''")[0][0]
        t.check("the 118-search backlog is loaded with its clusters", n == 118, n)
        bk = json.load(open(ctx.app + "/resources/data/keyword-backlog.json"))
        targets = [k["target"] for k in bk["keywords"] if k["target"]]
        t.check("each mapped search points at a page that exists", all(c.raw("GET", p.split("#")[0] or "/")[0] in (200, 304) for p in sorted(set(targets))))
        t.check("searches with no page yet stay unassigned", db.q("select count(*) from seo_keywords where cluster!='' and target=''")[0][0] == sum(1 for k in bk["keywords"] if not k["target"]))
        inst.artisan("tinker", "--execute=App\\Services\\AgentCadence::run('seo', 'weekly', \\Carbon\\Carbon::parse('2026-11-12 10:00', 'Asia/Kolkata'));")
        tasks = db.q("select title, detail from ai_tasks where agent='seo' and title like 'Plan pages for%'")
        t.check("the SEO agent turns gaps into one planning task per cluster, with no thin pages", len(tasks) == len({k["cluster"] for k in bk["keywords"] if not k["target"]}) and all("Do not make one thin page" in d for _, d in tasks), tasks[:1])
        inst.artisan("tinker", "--execute=App\\Services\\AgentCadence::run('seo', 'weekly', \\Carbon\\Carbon::parse('2026-11-12 10:00', 'Asia/Kolkata'));")
        t.check("and not twice", db.q("select count(*) from ai_tasks where agent='seo' and title like 'Plan pages for%'")[0][0] == len(tasks))

        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        s, j = o.api("GET", "admin/growth/brand-profiles?per=50")
        names = {r["platform"] for r in j.get("rows", [])}
        t.check("the listings register starts with search, social and directory platforms", s == 200 and {"Bing Webmaster Tools", "LinkedIn", "Crunchbase", "Product Hunt", "G2"} <= names, names)
        s, j = o.api("POST", "admin/growth/brand-profiles", {"platform": "Clutch", "kind": "directory", "status": "live", "claimed": False, "verified": False})
        t.check("a live listing needs its URL", s == 422)
        s, j = o.api("POST", "admin/growth/brand-profiles", {"platform": "Clutch", "kind": "directory", "url": "https://127.0.0.1/x", "status": "pending", "claimed": False, "verified": False})
        t.check("private addresses are refused", s == 422)
        s, j = o.api("POST", "admin/growth/brand-profiles", {"platform": "Clutch", "kind": "directory", "url": "https://clutch.example.com/profile/cvtara", "status": "pending", "claimed": False, "verified": True})
        t.check("verified needs claimed", s == 422)
        s, j = o.api("POST", "admin/growth/brand-profiles", {"platform": "Clutch", "kind": "directory", "url": "https://clutch.example.com/profile/cvtara", "status": "pending", "claimed": True, "verified": False, "note": "Submitted"})
        t.check("a listing is saved", s == 200 and db.q("select status, claimed from brand_profiles where platform='Clutch'")[0] == ("pending", 1), (s, j))
        s, j = o.api("GET", "admin/growth/brand-profiles?status=live")
        t.check("filter by state", s == 200 and j["rows"] and all(r["status"] == "live" for r in j["rows"]))
        st, h, b = o.raw("GET", "/api/admin/growth/brand-profiles?export=csv")
        t.check("downloadable", st == 200 and b"Platform" in b and b"Verified" in b and b"Last checked" in b)
        s, j = o.api("GET", "admin/growth/seo/backlog")
        t.check("the console shows the backlog by cluster", s == 200 and len(j["clusters"]) == 6 and sum(c["keywords"] for c in j["clusters"]) == 118)
        cu = Client(inst.base); cu.register("ss-a@test.local")
        t.check("customers cannot see the register", cu.api("GET", "admin/growth/brand-profiles")[0] in (403, 404))
        gj = open(ctx.app + "/resources/console/growth.js").read()
        t.check("the Growth page has a Listings & keywords tab", "brand: 'Listings & keywords'" in gj)
    finally:
        inst.stop()
