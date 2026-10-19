"""Programmatic SEO pages: every page renders, carries its title/meta/schema/breadcrumbs, and every internal link resolves."""
import html, json, re, time
NAME = "Programmatic SEO pages"

SAMPLES = ["/resume-examples/", "/resume-examples/information-technology/", "/resume-examples/information-technology/software-engineer/",
           "/resume-templates/ats-friendly/", "/cover-letter-examples/", "/cover-letter-examples/medical/", "/interview-questions/",
           "/interview-questions/staff-nurse/", "/resume-builder/india/", "/resume-builder/karnataka/", "/resume-builder/bangalore/",
           "/resume-builder/lucknow/", "/resume/bangalore/software-engineer/", "/resume/noida/bpo-customer-service/", "/resume/patna/freshers/",
           "/resume/kerala/government-jobs/", "/resume-words/", "/resume-words/responsible-for/", "/ats-checker/"]


def run(ctx, t):
    time.sleep(62)  # start with a fresh per-minute page limit
    c = ctx.client()
    s, _, sm = c.page("/sitemap.xml")
    if s != 200:
        t.skip("SEO pages", "sitemap disabled in this instance"); return
    locs = [re.sub(r"^https?://[^/]+", "", u) for u in re.findall(r"<loc>([^<]+)</loc>", sm)]
    seo = [u for u in locs if re.match(r"^/(resume-examples|resume-templates|cover-letter-examples|interview-questions|resume-builder|resume|resume-words|ats-checker)/", u)]
    t.check("the sitemap lists the programmatic pages (400+)", len(seo) >= 400, len(seo))
    t.check("every sample page is in the sitemap", all(p in seo for p in SAMPLES), [p for p in SAMPLES if p not in seo])
    bad, links, titles = [], set(), {}
    for p in seo:
        time.sleep(0.26); st, _, body = c.page(p)
        if st != 200:
            bad.append((p, st)); continue
        title = html.unescape(re.search(r"<title>(.*?)</title>", body, re.S).group(1))
        desc = html.unescape(re.search(r'<meta name="description" content="([^"]*)"', body).group(1))
        lds = [json.loads(x) for x in re.findall(r'<script type="application/ld\+json">(.*?)</script>', body, re.S)]
        types = {x.get("@type") for x in lds}
        visible = re.sub(r"<script.*?</script>|<style.*?</style>|<[^>]+>", " ", body, flags=re.S)
        problems = []
        if not (len(title) <= 60 and title.endswith("| CVTara")): problems.append(f"title {len(title)}: {title}")
        if not (50 <= len(desc) <= 155): problems.append(f"description {len(desc)}")
        if body.count("<h1") != 1: problems.append("h1 count")
        if f'rel="canonical" href="' not in body or not re.search(r'rel="canonical" href="[^"]+' + re.escape(p) + '"', body): problems.append("canonical")
        if not {"BreadcrumbList", "FAQPage"} <= types: problems.append(f"schema {types}")
        if "LocalBusiness" in types: problems.append("LocalBusiness")
        if 'class="sp-crumbs"' not in body or 'class="sp-toc"' not in body: problems.append("breadcrumbs/toc")
        if '<img class="sp-hero-img"' not in body or 'alt=""' in body: problems.append("image/alt")
        if 'href="/ats-checker/"' not in body or 'href="/#pricing"' not in body: problems.append("ats/pricing links")
        if "!" in re.sub(r"<!--.*?-->|<!doctype html>", "", visible, flags=re.S | re.I).replace("&#33;", ""): problems.append("exclamation mark")
        if "ATS (applicant tracking system" not in body: problems.append("ATS not explained")
        if re.search(r"\[VERIFY", body): problems.append("VERIFY marker shown")
        if problems: bad.append((p, problems))
        titles.setdefault(title, []).append(p)
        links |= {h for h in re.findall(r'href="(/[^"#?]*)', body) if not re.search(r"\.(css|js|ico|png|svg|webmanifest)$", h)}
    t.check("every programmatic page renders with title, meta, one H1, canonical, breadcrumbs, TOC, image, schema and core links", not bad, (len(bad), bad[:4]))
    dup = {k: v for k, v in titles.items() if len(v) > 1}
    t.check("titles are unique", not dup, list(dup.items())[:3])
    broken = []
    for h in sorted(links):
        if h in ("/register",) or h.startswith(("/login", "/workspace")): continue
        time.sleep(0.26); st = c.raw("GET", h)[0]
        if st not in (200, 301, 302): broken.append((h, st))
    t.check("every internal link on the programmatic pages resolves", not broken, broken[:6])
    time.sleep(62)  # let the per-minute page limit reset before the spot checks
    s, _, b = c.page("/resume/bangalore/software-engineer/")
    t.check("city x role page links to the role example, interview page and 2 other cities",
            'href="/resume-examples/information-technology/software-engineer/"' in b and 'href="/interview-questions/software-engineer/"' in b and len(re.findall(r'href="/resume/(?!bangalore)[a-z-]+/software-engineer/"', b)) >= 2)
    s, _, b = c.page("/resume-builder/india/")
    t.check("India hub links to every state and city", all(f'href="/resume-builder/{x}/"' in b for x in ("karnataka", "kerala", "bangalore", "tiruchirappalli")))
    t.check("unknown programmatic addresses are 404", c.raw("GET", "/resume-examples/sales/astronaut/")[0] == 404 and c.raw("GET", "/resume/bangalore/astronaut/")[0] == 404)
    t.check("brand keywords are not targeted", not any(re.search(r"/(google-docs|word|canva|indeed|linkedin|naukri)(/|$)", u) for u in seo))
    s, _, art = c.page("/guides/resume/action-verbs-list/")
    t.check("existing guides show visible breadcrumbs and link into the new hubs", 'class="article-crumbs"' in art and 'class="article-explore"' in art and "/resume-words/" in art, s)
    time.sleep(62)  # leave the per-minute page limit fresh for the suites that follow
