"""3.78.0 a picture for every SEO page (owner, 14 Nov 2026, after Google's 10 Nov update): each programmatic page shows its own
WebP drawn from its content with the CVTara mark and a copyright line, sized so nothing shifts, with alt text, an ImageObject
carrying creator/credit/copyright, og:image and an image sitemap entry. Also the senior roles testers could not find
(HR business partner, area sales manager and five more) get resume example and interview pages."""
import json, os, re
NAME = "3.78.0 SEO page pictures and senior roles"


def run(ctx, t):
    idx = json.load(open(os.path.join(ctx.app, "resources/data/seo-images.json"), encoding="utf8"))
    files = [os.path.join(ctx.public, v[0].lstrip("/")) for v in idx.values()]
    t.check("a picture listed for over 500 pages", len(idx) >= 500, len(idx))
    t.check("every listed picture exists", all(os.path.isfile(f) for f in files), [f for f in files if not os.path.isfile(f)][:3])
    t.check("pictures are unique files", len({v[0] for v in idx.values()}) == len(idx))
    sizes = [os.path.getsize(f) for f in files if os.path.isfile(f)]
    t.check("pictures stay light (under 120 KB each, under 40 MB in all)", sizes and max(sizes) < 120 * 1024 and sum(sizes) < 40 * 1024 * 1024, (max(sizes or [0]), sum(sizes)))
    t.check("alt text is specific and unique per page", all(len(v[3]) > 25 for v in idx.values()) and len({v[3] for v in idx.values()}) >= len(idx) * 0.95)
    raw = open(files[0], "rb").read()
    t.check("copyright metadata inside the file", b"\xc2\xa9 2026 CVTara" in raw and b"photoshop:Credit" in raw and b"dc:creator" in raw)
    t.check("no AI wording in alt text", not any(re.search(r"\b(AI|Claude|Anthropic|GPT|generated)\b", v[3]) for v in idx.values()))

    from lib.env import Instance
    from lib.http import Client
    inst = Instance(ctx.app, ctx.public, preview=ctx.preview).start()
    try:
        c = Client(inst.base)
        s, h, b = c.raw("GET", "/resume-examples/sales/area-sales-manager/")
        html = b.decode("utf-8", "ignore")
        t.check("area sales manager resume example page exists", s == 200 and "Area Sales Manager" in html, s)
        m = re.search(r'<img class="sp-hero-img" src="([^"]+)" alt="([^"]+)" width="(\d+)" height="(\d+)"', html)
        t.check("hero shows the page's own picture with width and height", m and m.group(1) == "/assets/seo/pages/resume-examples-sales-area-sales-manager.webp" and m.group(3) == "1200" and m.group(4) == "675", m and m.groups())
        t.check("alt text names the role and skills", m and "Area Sales Manager" in m.group(2) and "Territory management" in m.group(2), m and m.group(2))
        t.check("og:image is the page picture with its size", re.search(r'og:image" content="[^"]*/assets/seo/pages/resume-examples-sales-area-sales-manager\.webp"', html) and 'og:image:width" content="1200"' in html, re.findall(r'og:image[^>]*>', html)[:3])
        ld = [json.loads(x) for x in re.findall(r'<script type="application/ld\+json">(.*?)</script>', html, re.S)]
        page = next((x for x in ld if x.get("@type") in ("WebPage", "CollectionPage")), {})
        img = page.get("primaryImageOfPage") or {}
        t.check("WebPage has an ImageObject with creator, credit and copyright", img.get("@type") == "ImageObject" and img.get("creditText") == "CVTara" and "CVTara" in img.get("copyrightNotice", "") and img.get("width") == 1200, img)
        for path in ("/interview-questions/hr-business-partner/", "/interview-questions/area-sales-manager/", "/interview-questions/finance-manager/", "/interview-questions/it-project-manager/",
                     "/interview-questions/digital-marketing-manager/", "/interview-questions/key-account-manager/", "/interview-questions/brand-manager/", "/resume-examples/human-resources/hr-business-partner/"):
            s, h, b = c.raw("GET", path)
            t.check(f"{path} is live with its own picture", s == 200 and "/assets/seo/pages/" + path.strip("/").replace("/", "-") + ".webp" in b.decode("utf-8", "ignore"), s)
        s, h, b = c.raw("GET", "/resume-builder/bangalore/")
        t.check("city page uses its own picture", s == 200 and "resume-builder-bangalore.webp" in b.decode("utf-8", "ignore"), s)
        s, h, b = c.raw("GET", "/sitemap.xml")
        x = b.decode("utf-8", "ignore")
        t.check("image sitemap lists the page pictures", s == 200 and x.count("/assets/seo/pages/") >= 400, (s, x.count("/assets/seo/pages/")))
        t.check("sitemap lists the new senior pages", "/interview-questions/hr-business-partner/" in x and "/resume-examples/sales/area-sales-manager/" in x)
        s, h, b = c.raw("GET", "/assets/seo/pages/resume-builder-bangalore.webp")
        t.check("pictures are served as WebP", s == 200 and b[:4] == b"RIFF" and b[8:12] == b"WEBP", s)
    finally:
        inst.stop()
