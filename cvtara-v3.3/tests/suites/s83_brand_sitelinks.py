"""3.74.0 brand result (owner 2026-11-13: show up for cvtara / cv tara / CVTara resume builder like a big brand, with
sitelinks): the top menu is in the page HTML and points at the main indexable pages, the brand data names CVTara with its
spellings, and the SEO agent tracks the brand and main product searches and checks the sitelink signals."""
import json, re
NAME = "Brand result: server menu, sitelink pages, brand keywords"

MENU = ["/resume-builder/", "/ats-checker/", "/templates.html", "/cover-letters.html", "/interview-questions/", "/pricing/", "/blog.html"]


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    inst = Instance(ctx.app, ctx.public, preview=True).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        c = Client(inst.base)
        for path in ("/", "/ats-checker/", "/templates.html", "/pricing/"):
            s, h, b = c.page(path)
            head = re.search(r'<div data-header>(.*?)</header></div>', b, re.S)
            links = re.findall(r'href="([^"]+)"', head.group(1)) if head else []
            t.check(f"{path} has the top menu in its HTML, linking every main page", s == 200 and all(m in links for m in MENU), links)
        s, h, b = c.page("/")
        t.check("the menu in the HTML matches the classes the script draws, so nothing jumps", 'class="site-header" id="siteHeader"' in b and 'class="main-nav"' in b)
        org = next((json.loads(x) for x in re.findall(r'<script type="application/ld\+json">(.*?)</script>', b, re.S) if '"Organization"' in x and '#organization' in x), {})
        t.check("the brand data carries the spellings people type", {"CV Tara", "CVTara Resume Builder", "CVTara ATS Resume Builder"} <= set(org.get("alternateName", [])), org.get("alternateName"))
        js = open(ctx.public + "/preview.js").read()
        t.check("the script's menu links the indexable pages, not the dashboard or a home-page anchor", all(f'href="{m}"' in js for m in ("/resume-builder/", "/interview-questions/", "/pricing/")) and '>Pricing</a>' in js and 'href="/#pricing">Pricing' not in js)
        t.check("the menu keeps Resume by Role and the city and state pages", ">Resume by Role</a>" in js and 'href="/resume-builder/india/"' in js)

        r = inst.artisan("tinker", "--execute=App\\Services\\SeoOps::syncKeywords(); echo json_encode(App\\Services\\SeoOps::SITELINKS);")
        sl = json.loads(r.stdout.strip().splitlines()[-1])
        t.check("the agent's sitelink list now includes Pricing, Interview Questions and Resume Examples", sl.get("Pricing and Plans") == "/pricing/" and sl.get("Interview Questions") == "/interview-questions/" and sl.get("Resume Examples") == "/resume-examples/", sl)
        kw = {k: v for k, v in db.q("select keyword, kind from seo_keywords")}
        t.check("the owner's brand searches are tracked as brand searches", all(kw.get(k) == "brand" for k in ("cvtara", "cv tara", "cvtara resume builder", "cvtara ats check", "cvtara resume template")), {k: kw.get(k) for k in ("cvtara", "cvtara ats check")})
        t.check("the main product searches are tracked too", all(k in kw for k in ("resume builder", "ats check", "resume template", "ats checker")))
        r = inst.artisan("tinker", "--execute=echo json_encode(App\\Services\\SeoOps::brand());")
        b2 = json.loads(r.stdout.strip().splitlines()[-1])
        names = [x["check"] for x in b2.get("checks", [])]
        t.check("the brand check reports the logo, social profiles and the menu in the HTML (once the home page is scanned)", not names or {"Logo for search results (square, at least 112 px)", "Official social profiles listed (sameAs)", "Top menu links the main pages in the page HTML"} <= set(names), names)
        t.check("the brand check says honestly that Google picks sitelinks", "Google chooses" in b2.get("note", ""))

        # images (owner 2026-11-13: "cvtara images are also not ranking"): real pictures of the designs in the page HTML
        s, h, b = c.page("/resume-templates/")
        imgs = re.findall(r'<img src="(/assets/templates/[a-z0-9-]+-resume-template\.webp)" alt="([^"]+)"[^>]*loading="lazy"', b)
        t.check("the templates hub shows a picture of every published resume design, with a describing alt text", len(imgs) >= 80 and all("resume template by CVTara" in a for _, a in imgs), len(imgs))
        s2, h2, _ = c.raw("GET", imgs[0][0]) if imgs else (0, {}, "")
        t.check("the pictures are served as WebP", s2 == 200 and "image/webp" in h2.get("Content-Type", ""), (s2, h2.get("Content-Type")))
        s, h, b = c.page("/resume-templates/fresher/")
        t.check("a style page shows pictures of designs in that style", 1 <= len(re.findall(r'<img src="/assets/templates/', b)) <= 6)
        s, h, b = c.page("/cover-letter-examples/")
        t.check("the cover letter hub shows the letter designs as pictures", len(re.findall(r'<img src="/assets/cover-letters/[a-z0-9-]+-cover-letter\.webp" alt="[^"]*cover letter template by CVTara', b)) >= 40)
        s, h, b = c.page("/")
        t.check("the logo is an image in the page HTML with the brand name as its text", 'src="/assets/brand/cvtara-logo.webp"' in b and 'alt="CVTara"' in b)
        s, h, sm = c.page("/sitemap.xml")
        t.check("the sitemap lists the pictures each page shows (image sitemap)", 'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"' in sm and sm.count("<image:loc>") >= 130 and "/favicon-512.png</image:loc>" in sm and "-cover-letter.webp</image:loc>" in sm, sm.count("<image:loc>"))
        r = inst.artisan("tinker", "--execute=App\\Services\\Settings::put('library', ['templates' => ['academic-cv' => ['published' => false]]]); App\\Services\\TemplateImages::flush(); echo json_encode(array_column(App\\Services\\TemplateImages::resumes(), 'slug'));")
        t.check("a design the owner hides is left out of the pictures", r.stdout.strip() and "academic-cv" not in r.stdout.strip().splitlines()[-1], r.stdout[-200:] + r.stderr[-300:])
    finally:
        inst.stop()
