"""3.68.0 index sprint and pricing pages (owner brief 2026-11-12): five pricing pages that read the live plan prices and
carry Product data, /pricing and /pricing.html moved to /pricing/, sitemap <lastmod> from the page scan, a 30-day sprint
that raises the agents' daily limits about tenfold and ends by itself, and SEO approvals that name the full page address,
the section of the page and, for links, every page the link goes on."""
import json, re
NAME = "Index sprint, pricing pages, approval detail"


def blocks(html):
    out = []
    for m in re.finditer(r'<script type="application/ld\+json">(.*?)</script>', html, re.S):
        try:
            v = json.loads(m.group(1)); out += v if isinstance(v, list) else [v]
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
        s, h, b = c.page("/pricing/")
        prods = [x for x in blocks(b) if x.get("@type") == "Product"]
        t.check("/pricing/ answers 200 and lists every plan on sale as a Product with its INR price",
                s == 200 and sorted(p["offers"]["price"] for p in prods) == ["299", "599", "99"] and all(p["offers"]["priceCurrency"] == "INR" for p in prods), (s, [p.get("offers") for p in prods]))
        t.check("/pricing/ has a comparison table and buy links to checkout", 'class="sp-table"' in b and "/checkout.html?plan=quick" in b, s)
        for anchor, price in (("quick-download", "99"), ("job-hunt", "299"), ("pro", "599")):
            t.check(f"/pricing/ has its own section for the ₹{price} plan with a buy button", f'id="{anchor}"' in b and f"₹{price} once" in b, anchor)
        t.check("each plan is a Product with a 7-day return window, linked to its section", all(p["offers"]["hasMerchantReturnPolicy"]["merchantReturnDays"] == 7 and "/pricing/#" in p["url"] for p in prods), prods[:1])
        t.check("free vs paid is a section of the same page", 'id="free-vs-paid"' in b and "Free, always" in b)
        for old, anchor in (("/pricing/job-hunt/", "job-hunt"), ("/pricing/quick-download/", "quick-download"), ("/pricing/free-vs-paid/", "free-vs-paid")):
            s2, h2, _ = c.raw("GET", old)
            t.check(f"{old} moves permanently to its section on the one pricing page", s2 == 301 and h2.get("Location", "").endswith("/pricing/#" + anchor), (s2, h2.get("Location")))
        text = c.page("/pricing/")[2]
        t.check("pricing pages never mention AI", not re.search(r"\bAI\b|artificial intelligence|anthropic|claude", re.sub(r"<script.*?</script>", "", text, flags=re.S), re.I))
        for a in ("/pricing", "/pricing.html", "/plans"):
            s, h, _ = c.raw("GET", a)
            t.check(f"{a} moves permanently to /pricing/ in one step (keeps the slash)", s == 301 and h.get("Location", "").endswith("/pricing/"), (s, h.get("Location")))
        # 3.69.0: more words of their own on the most templated pages.
        s, h, b = c.page("/interview-questions/hair-stylist/")
        t.check("interview pages give a sample answer for each role question", s == 200 and b.count('class="sp-sample-answer"') == 5 and "48 hours before" in b, s)
        s, h, b = c.page("/resume-words/leading/")
        t.check("resume word pages show more before-and-after lines and when the word is fine", s == 200 and 'id="more-examples"' in b and "Heading a 9-person sales team" in b and 'id="when-fine"' in b, s)
        s, h, b = c.page("/cover-letter-examples/transportation/")
        t.check("cover letter pages add a fresher opening and mistakes for the field", s == 200 and 'id="fresher"' in b and "LMV licence" in b and 'id="mistakes"' in b, s)
        s, h, b = c.page("/resume-words/")
        t.check("a hub page with no extra content shows no empty sections", s == 200 and not re.search(r'<h2>[^<]*</h2></section>', b))
        s, h, b = c.page("/resume-examples/")
        t.check("programmatic pages link to the pricing page, not the home page anchor", 'href="/pricing/"' in b and 'href="/#pricing"' not in b)

        db.q("insert or replace into seo_pages(path,important,section,fingerprint,changed_at,created_at,updated_at) values('/pricing/',1,'pricing','x','2026-11-10 08:00:00',datetime('now'),datetime('now'))")
        s, h, xml = c.raw("GET", "/sitemap.xml"); xml = xml.decode()
        locs = re.findall(r"<loc>([^<]+)</loc>", xml)
        t.check("the sitemap lists the one pricing page and no plan sub-pages", [l for l in locs if "/pricing/" in l] == [l for l in locs if l.endswith("/pricing/")] and any(l.endswith("/pricing/") for l in locs), [l for l in locs if "pricing" in l])
        s, h, b = c.page("/pricing/?fresh=1")  # a query string skips the ready-made copy
        page = next((x for x in blocks(b) if x.get("@type") == "WebPage"), {})
        t.check("a changed page shows 'Updated' with that date and sends it as dateModified", 'Updated <time datetime="2026-11-10">10 Nov 2026</time>' in b and page.get("dateModified") == "2026-11-10", page.get("dateModified"))
        t.check("a scanned page carries its last-change date as <lastmod>", re.search(r"/pricing/</loc><lastmod>2026-11-10</lastmod>", xml) is not None)

        tj = lambda e: json.loads(inst.artisan("tinker", "--execute=echo json_encode(" + e + ");").stdout.strip().splitlines()[-1])
        before = tj("App\\Services\\SeoOps::settings()")
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        s, j = o.api("POST", "admin/growth/seo/sprint", {"on": True})
        sp = j.get("sprint", {})
        t.check("starting the sprint runs it for 60 days from today", s == 200 and sp.get("active") and sp.get("day") == 1 and sp.get("days") == 60 and sp.get("until", "")[:10] == tj("now()->addDays(60)->toDateString()"), (s, j))
        now = tj("App\\Services\\SeoOps::settings()")
        t.check("while it runs Google checks go 60 -> 600 a day and scans 40 -> 200 a run (ceilings kept)", before["inspectPerDay"] == 60 and now["inspectPerDay"] == 600 and now["scanPerRun"] == 200 and now["requestPerRun"] == 50, now)
        s, j = o.api("GET", "admin/growth/seo")
        t.check("the SEO tab shows the sprint, this week's plan and the owner's own limits (not the boosted ones)", s == 200 and j["sprint"]["week"]["title"].startswith("Week 1") and j["settings"]["metaPerDay"] == 10, j.get("sprint", {}).get("week"))
        s, j = o.api("POST", "admin/growth/seo/settings", {"auto": True, "metaPerDay": 10, "linksPerDay": 10, "minImpressions": 20})
        t.check("saving the form during the sprint keeps the owner's numbers", json.loads(db.q("select value_json from site_settings where key='seoAgent'")[0][0])["metaPerDay"] == 10)
        db.q("update site_settings set value_json=json_set(value_json,'$.until',?) where key='seoSprint'", tj("now()->addDays(30)->toIso8601String()"))
        t.check("a sprint started for 30 days (3.68.0) now runs the full 60 days from its start", tj("App\\Services\\SeoSprint::status()")["until"][:10] == tj("now()->addDays(60)->toDateString()"))
        db.q("update site_settings set value_json=json_set(value_json,'$.startedAt','2019-11-01T00:00:00+00:00','$.until','2019-12-31T00:00:00+00:00') where key='seoSprint'")
        t.check("the sprint ends by itself when its 60 days are over", tj("App\\Services\\SeoOps::settings()")["inspectPerDay"] == 60)
        s, j = o.api("POST", "admin/growth/seo/sprint", {"on": False})
        t.check("the owner can stop it", s == 200 and not j["sprint"]["active"], j)

        w = tj("App\\Services\\Approvals::seoWhere('links', '/interview-questions/driver/', ['label' => 'Driver interview questions', 'from' => ['/interview-questions/', '/resume-examples/']])")
        t.check("a link approval names the full page, the section, every page the link goes on and the link text",
                w[0].endswith("/interview-questions/driver/") and w[0].startswith("Page: http") and "Popular with job seekers" in w[1] and sum(x.startswith("Link added on: ") for x in w) == 2 and 'Link text: "Driver interview questions"' in w[-1], w)
        w = tj("App\\Services\\Approvals::seoWhere('meta', '/pricing/', [])")
        t.check("a title approval says it changes the <title> tag and meta description", "<title>" in w[1] and "meta description" in w[1], w)
        js = open(ctx.app + "/resources/console/approvals.js").read()
        t.check("the approval card shows a Where row", "<dt>Where</dt>" in js)
        plan = json.load(open(ctx.app + "/resources/data/seo-sprint.json"))
        t.check("the sprint plan covers eight weeks and keeps the white-hat rules", len(plan["weeks"]) == 8 and any("bought links" in r for r in plan["rules"]))
    finally:
        inst.stop()
