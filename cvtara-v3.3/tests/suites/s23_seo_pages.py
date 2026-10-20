"""Programmatic SEO pages: a full crawl of the sitemap and every internal link, as a search engine without JavaScript sees it.

Checks status codes and redirects, titles, descriptions, H1, canonical, robots, JSON-LD, breadcrumbs, orphans, click depth,
keyword density, near-duplicate pages, interlinking and the product call to action (see tests/lib/seo_audit.py)."""
import html, json, os, re, time
from lib.seo_audit import audit, analyse, fetcher, SEO_RE
NAME = "Programmatic SEO pages"

SAMPLES = ["/resume-examples/", "/resume-examples/information-technology/", "/resume-examples/information-technology/software-engineer/",
           "/resume-templates/", "/resume-templates/ats-friendly/", "/cover-letter-examples/", "/cover-letter-examples/medical/", "/interview-questions/",
           "/interview-questions/staff-nurse/", "/resume-builder/india/", "/resume-builder/karnataka/", "/resume-builder/bangalore/",
           "/resume-builder/lucknow/", "/resume/bangalore/software-engineer/", "/resume/noida/bpo-customer-service/", "/resume/patna/freshers/",
           "/resume-words/", "/resume-words/responsible-for/", "/ats-checker/"]
HUBS = ["/resume-examples/", "/resume-templates/", "/cover-letter-examples/", "/interview-questions/", "/resume-words/", "/resume-builder/india/", "/ats-checker/"]


def purge_pages(ctx):
    """Drop ready-made pages (built with the other indexing setting); the cache markers stay."""
    root = os.path.join(ctx.inst.public, "_pages")
    for r, _, fs in os.walk(root):
        for f in fs:
            if f.endswith(".html"): os.remove(os.path.join(r, f))


def run(ctx, t):
    db = ctx.db()
    old = db.q("select value_json from site_settings where key='seo'")
    seo = json.load(open(f"{ctx.app}/resources/data/defaults.json"))["seo"] if not old else json.loads(old[0][0])
    db.q("insert or replace into site_settings(key,value_json,revision,created_at,updated_at) values('seo',?,1,datetime('now'),datetime('now'))", json.dumps({**seo, "searchIndexing": True}))
    purge_pages(ctx)
    try:
        time.sleep(62)  # start with a fresh per-minute page limit
        check(ctx, t)
    finally:
        if old: db.q("update site_settings set value_json=? where key='seo'", old[0][0])
        else: db.q("delete from site_settings where key='seo'")
        purge_pages(ctx)
        time.sleep(62)  # leave the per-minute page limit fresh for the suites that follow


def check(ctx, t):
    R = audit(fetcher(ctx.inst.base, 0.27))
    m, probs, extra = analyse(R)
    print("   ", json.dumps(m))
    kinds = lambda *k: [p for p in probs if p[0] in k]
    locs = R["sitemap"]
    seo = [u for u in locs if SEO_RE.match(u)]
    robots = R["robots"]
    t.check("robots.txt allows the SEO pages and page scripts, and names the sitemap",
            "Sitemap:" in robots and "Disallow: /\n" not in robots and "Disallow: /preview\n" not in robots and not re.search(r"Disallow: /(resume|interview|cover|ats|guides|calculators|templates)", robots), robots)
    t.check("the sitemap lists the programmatic pages (400+)", len(seo) >= 400, len(seo))
    t.check("every sample page and hub is in the sitemap", all(p in seo for p in SAMPLES + HUBS), [p for p in SAMPLES + HUBS if p not in seo])
    t.check("every sitemap address and internal link answers 200 directly (no 404, 500 or redirect)", not kinds("status", "link"), kinds("status", "link")[:6])
    t.check("every indexable page: title up to 60, description 50 to 160, one H1, self canonical, index,follow, valid JSON-LD, breadcrumbs",
            not kinds("page"), (len(kinds("page")), kinds("page")[:4]))
    t.check("titles and descriptions are unique", not kinds("duplicate title", "duplicate description"), kinds("duplicate title", "duplicate description")[:4])
    t.check("no orphan pages: every sitemap page has a link from another page", not kinds("orphan"), kinds("orphan")[:6])
    t.check("every sitemap page is reachable from the home page in 4 clicks without JavaScript", m["unreachable_from_home"] == 0 and m["deeper_than_4"] == 0, (m["unreachable_from_home"], m["deeper_than_4"]))
    t.check("main phrase density stays at 3% or below on every page", not kinds("keyword density"), kinds("keyword density")[:5])
    t.check("no 2 to 3 word phrase takes more than 4.5% of a page", all(x[0] <= 0.045 for x in extra["top_phrase"]), extra["top_phrase"][:3])
    t.check("no two indexable pages are near-duplicates (shared text under 60%)", not kinds("near duplicate"), kinds("near duplicate")[:5])
    t.check("every programmatic page links to at least 4 other programmatic pages and shows the product call to action",
            not kinds("few internal links", "no product CTA"), kinds("few internal links", "no product CTA")[:5])
    t.check("no hidden text on the programmatic pages", not any("hidden text in main" in str(p) for p in kinds("page")))
    bad = []
    for p in seo:
        body = R["pages"][p]
        main = body.split("<main", 1)[-1].split("</main>")[0]
        visible = re.sub(r"<script.*?</script>|<style.*?</style>|<[^>]+>", " ", body, flags=re.S)
        types = {x.get("@type") for x in (json.loads(j) for j in re.findall(r'<script type="application/ld\+json">(.*?)</script>', body, re.S))}
        f = []
        if not {"BreadcrumbList", "FAQPage"} <= types: f.append(f"schema {types}")
        if "LocalBusiness" in types: f.append("LocalBusiness")
        if 'class="sp-crumbs"' not in body or 'class="sp-toc"' not in body: f.append("breadcrumbs/toc")
        if '<img class="sp-hero-img"' not in body or 'alt=""' in main: f.append("image/alt")
        if 'href="/ats-checker/"' not in body or 'href="/#pricing"' not in body: f.append("ats/pricing links")
        if body.count('data-seo-cta="hero"') != 1 or body.count('data-seo-cta="final"') != 1: f.append("CTA block")
        if "!" in re.sub(r"<!--.*?-->|<!doctype html>", "", visible, flags=re.S | re.I).replace("&#33;", ""): f.append("exclamation mark")
        if "ATS (applicant tracking system" not in body: f.append("ATS not explained")
        if "…" in html.unescape(re.search(r"<title>(.*?)</title>", body).group(1)): f.append("title cut short")
        if re.search(r"\[VERIFY", body): f.append("VERIFY marker shown")
        if f: bad.append((p, f))
    t.check("programmatic pages carry FAQ and breadcrumb schema, TOC, image, ATS and pricing links and one marked CTA block", not bad, (len(bad), bad[:4]))
    hubs = {h: R["pages"].get(h, "") for h in HUBS}
    t.check("every hub is linked from the server-rendered footer of the home page", all(f'href="{h}"' in R["pages"]["/"] for h in HUBS))
    children = [("/resume-examples/", r"^/resume-examples/[^/]+/$"), ("/resume-templates/", r"^/resume-templates/[^/]+/$"), ("/cover-letter-examples/", r"^/cover-letter-examples/[^/]+/$"),
                ("/interview-questions/", r"^/interview-questions/[^/]+/$"), ("/resume-words/", r"^/resume-words/[^/]+/$"), ("/resume-builder/india/", r"^/resume-builder/(?!india/)[^/]+/$")]
    missing = [(h, u) for h, rx in children for u in locs if re.match(rx, u) and f'href="{u}"' not in hubs[h]]
    t.check("hubs link to every child page", not missing, missing[:5])
    held = [p for p in R["pages"] if SEO_RE.match(p) and p not in locs]
    wrong = [p for p in held if '<meta name="robots" content="noindex,follow">' not in R["pages"][p] or not re.search(r'rel="canonical" href="[^"]+' + re.escape(p) + '"', R["pages"][p])]
    t.check("pages held back from search (no local content yet) are noindex,follow with a self canonical, and stay linked", held and not wrong, (len(held), wrong[:3]))
    time.sleep(62)  # let the per-minute page limit reset before the spot checks
    c = ctx.client()
    s, _, b = c.page("/resume/bangalore/software-engineer/")
    t.check("city x role page links to the role example, interview page and other cities",
            'href="/resume-examples/information-technology/software-engineer/"' in b and 'href="/interview-questions/software-engineer/"' in b and len(re.findall(r'href="/resume/(?!bangalore)[a-z-]+/software-engineer/"', b)) >= 3)
    s, _, b = c.page("/resume-examples/information-technology/software-engineer/")
    t.check("role page links to its hub, field, interview page and related roles",
            'href="/resume-examples/"' in b and 'href="/resume-examples/information-technology/"' in b and 'href="/interview-questions/software-engineer/"' in b
            and len(re.findall(r'href="/resume-examples/information-technology/(?!software-engineer/)[a-z-]+/"', b)) >= 3)
    t.check("unknown programmatic addresses are 404", c.raw("GET", "/resume-examples/sales/astronaut/")[0] == 404 and c.raw("GET", "/resume/bangalore/astronaut/")[0] == 404)
    r1, r2, r3 = c.raw("GET", "/resume-examples/sales"), c.raw("GET", "/resume/"), c.raw("GET", "/guides/resume/action-verbs-list")
    t.check("addresses without the trailing slash and trimmed folders redirect once, permanently",
            (r1[0], r2[0], r3[0]) == (301, 301, 301) and r1[1].get("Location", "").endswith("/resume-examples/sales/") and r2[1].get("Location", "").endswith("/resume-builder/india/"), (r1[0], r2[0], r3[0]))
    t.check("brand keywords are not targeted", not any(re.search(r"/(google-docs|word|canva|indeed|linkedin|naukri)(/|$)", u) for u in seo))
    s, _, art = c.page("/guides/resume/action-verbs-list/")
    t.check("existing guides show visible breadcrumbs and link into the new hubs", 'class="article-crumbs"' in art and 'class="article-explore"' in art and "/resume-words/" in art, s)
