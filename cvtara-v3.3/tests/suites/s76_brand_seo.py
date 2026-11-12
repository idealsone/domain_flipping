"""3.62.0 brand search and SEO playbook (owner brief 2026-11-12): Organization + WebSite structured data with stable ids,
alternate spellings and the official profiles; permanent redirects for typed addresses of public pages; the SEO agent's
close-to-page-one range 4-30; the larger keyword backlog and the page playbook shown in the console."""
import json
NAME = "Brand structured data, permanent redirects, SEO range 4-30, playbook"


def blocks(html):
    import re
    out = []
    for m in re.finditer(r'<script type="application/ld\+json">(.*?)</script>', html, re.S):
        try: out.append(json.loads(m.group(1)))
        except ValueError: pass
    return out


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    inst = Instance(ctx.app, ctx.public, preview=True).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        c = Client(inst.base)
        s, h, b = c.page("/")
        ld = blocks(b)
        org = next((x for x in ld if x.get("@type") == "Organization"), {})
        site = next((x for x in ld if x.get("@type") == "WebSite"), {})
        t.check("the home page has one Organization with a stable id, spellings people search and a logo",
                org.get("@id", "").endswith("/#organization") and "CV Tara" in org.get("alternateName", []) and org.get("logo", {}).get("url", "").endswith("/favicon-512.png"), org)
        t.check("the Organization lists the official social profiles", any("linkedin.com/company/cvtara" in u for u in org.get("sameAs", [])) and any("x.com/cvtara_resume" in u for u in org.get("sameAs", [])), org.get("sameAs"))
        t.check("the WebSite names CVTara and points at the Organization", site.get("name") == "CVTara" and site.get("publisher", {}).get("@id") == org.get("@id") and site.get("@id", "").endswith("/#website"), site)
        t.check("the brand data mentions no AI", not any(w in json.dumps(ld).lower() for w in (" ai ", "artificial intelligence")))
        s, h, b = c.page("/about.html")
        t.check("About carries the same Organization", any(x.get("@type") == "Organization" and x.get("@id", "").endswith("/#organization") for x in blocks(b)))
        s, h, b = c.page("/templates.html")
        t.check("other pages do not repeat the Organization block", not any(x.get("@type") == "Organization" for x in blocks(b)))
        for a, to in (("/privacy-policy", "/privacy.html"), ("/about-us", "/about.html"), ("/contact-us", "/contact.html"), ("/terms-and-conditions", "/terms.html")):
            s, h, _ = c.raw("GET", a)
            t.check(f"{a} moves permanently to {to}", s == 301 and h.get("Location", "").endswith(to), (s, h.get("Location")))
        s, h, _ = c.raw("GET", "/cover-letter")
        t.check("workspace shortcuts stay temporary", s == 302, s)

        q = [{"query": "maintenance cover letter", "path": "/cover-letter-examples/maintenance-repair/", "impressions": 40, "clicks": 0, "ctr": 0, "position": 25.0},
             {"query": "resume for mba freshers", "path": "/resume-examples/", "impressions": 90, "clicks": 0, "ctr": 0, "position": 34.0}]
        db.q("insert or replace into site_settings(key,value_json,revision,created_at,updated_at) values('seoPairs',?,1,datetime('now'),datetime('now'))", json.dumps({"rows": q}))
        r = inst.artisan("tinker", "--execute=echo json_encode(App\\Services\\SeoAgent::analyse());")
        a = json.loads(r.stdout.strip().splitlines()[-1])
        t.check("a search at position 25 counts as close to page one (owner range 4-30)", any(x["query"] == "maintenance cover letter" for x in a["striking"]), a["striking"])
        t.check("a search beyond 30 is a gap, not close to page one", any(x["query"] == "resume for mba freshers" for x in a["gaps"]) and not any(x["query"] == "resume for mba freshers" for x in a["striking"]))

        bk = json.load(open(ctx.app + "/resources/data/keyword-backlog.json"))
        t.check("the backlog holds the new unmet, buy-ready and Search Console clusters", {"Buy-ready (BOFU) searches (2026-11)", "Seen in Search Console (real queries, 2026-11)", "Campus placement & students (2026-11)"} <= {k["cluster"] for k in bk["keywords"]})
        t.check("no search in the backlog names another brand or job site", not any(w in k["keyword"] for k in bk["keywords"][118:] for w in ("naukri", "indeed", "linkedin", "canva", "zety", "glassdoor")))
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        s, j = o.api("GET", "admin/growth/seo/backlog")
        pb = j.get("playbook", {})
        t.check("the console shows the playbook with its template, checklist and pillars", s == 200 and len(pb.get("pageTemplate", [])) >= 10 and pb.get("aiSearch") and len(pb.get("pillars", [])) == 6 and "linkPlan" in j, list(j))
        inst.artisan("tinker", "--execute=App\\Services\\AgentCadence::run('seo', 'weekly', \\Carbon\\Carbon::parse('2026-11-12 10:00', 'Asia/Kolkata'));")
        d = db.q("select detail from ai_tasks where agent='seo' and title like 'Plan pages for%' limit 1")
        t.check("each planning task carries the page structure to follow", d and "Page structure to follow" in d[0][0] and "Short answer" in d[0][0], d[:1])
        js = open(ctx.app + "/resources/console/agents.js").read()
        t.check("the listings tab renders the playbook and missing pillar links", "kbPb" in js and "kbLp" in js)
    finally:
        inst.stop()
