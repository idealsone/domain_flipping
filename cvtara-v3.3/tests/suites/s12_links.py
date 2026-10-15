"""Crawl the public site: every internal link must lead to a real page, and public pages must not point at private areas."""
import json, os, re
from urllib.parse import urljoin, urlparse
NAME = "Link crawl"
PRIVATE = re.compile(r"^/(admin|console|api|preview(/|$)|install|setup|webhooks)")


def run(ctx, t):
    c = ctx.client(); base = ctx.inst.base
    queue = ["/", "/templates.html", "/blog.html", "/about.html", "/contact.html", "/privacy.html", "/terms.html", "/refund.html", "/checkout.html", "/login", "/register", "/forgot-password"]
    try:
        cat = json.load(open(os.path.join(ctx.app, "resources", "data", "catalog.json")))
        queue += [("/calculators/" if p.get("type") == "Calculator" else "/guides/") + p["slug"] + "/" for p in cat.get("blog", [])]
    except Exception: pass
    seen, broken, private, pages = set(), [], [], 0
    while queue and pages < 400:
        path = queue.pop(0)
        if path in seen: continue
        seen.add(path)
        s, h, body = c.raw("GET", path, None, {"Accept": "text/html"})
        if s in (301, 302):
            loc = urlparse(urljoin(base + path, h.get("Location", ""))).path or "/"
            if loc not in seen and loc.startswith("/") and "login" not in loc: queue.append(loc)
            continue
        if s == 404 and path.endswith((".js", ".css", ".png", ".svg", ".webp", ".ico")): broken.append((path, "asset 404")); continue
        if s != 200: broken.append((path, s)); continue
        pages += 1
        html = body.decode("utf8", "ignore")
        bm = re.search(r'<base href="([^"]+)"', html); here = urljoin(base + path, bm.group(1)) if bm else base + path
        for href in re.findall(r'(?:href|src)="([^"#]+)"', html):
            if href.startswith(("http", "mailto:", "tel:", "javascript:", "data:", "//")): continue
            p = urlparse(urljoin(here, href)).path
            if PRIVATE.match(p) and path == "/": private.append((path, p))
            if p.endswith((".js", ".css", ".png", ".svg", ".webp", ".ico", ".mjs", ".jpg")):
                if p not in seen:
                    seen.add(p); sa, _, _ = c.raw("GET", p)
                    if sa != 200: broken.append((path + " -> " + p, sa))
            elif p not in seen and len(seen) < 400: queue.append(p)
    t.check(f"crawled {pages} public pages", pages >= 20, pages)
    t.check("no broken internal links or missing assets", not broken, broken[:8])
    t.check("the home page does not link to owner areas", not private, private[:3])
