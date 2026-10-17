"""Public content: home page sections, blog index data, article structure and SEO data, About and Contact pages, and the Contact form reaching the owner inbox."""
import json, os, re, struct, urllib.error, urllib.request, uuid, zlib
NAME = "Home, blog, articles, About and Contact"


def png(w=30, h=20):
    raw = b"".join(b"\x00" + bytes([(x * 7 + y * 3) % 256 for x in range(w) for _ in range(3)]) for y in range(h))
    def chunk(t, d): return struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d) & 0xffffffff)
    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0)) + chunk(b"IDAT", zlib.compress(raw)) + chunk(b"IEND", b"")


def contact(c, fields, file=None):
    c.token(); b = uuid.uuid4().hex; body = b""
    for k, v in fields.items(): body += f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
    if file: body += f'--{b}\r\nContent-Disposition: form-data; name="attachment"; filename="shot.png"\r\nContent-Type: image/png\r\n\r\n'.encode() + file + b"\r\n"
    body += f"--{b}--\r\n".encode()
    try: r = c.op.open(urllib.request.Request(c.base + "/contact/message", data=body, method="POST", headers={"Content-Type": f"multipart/form-data; boundary={b}", "Accept": "application/json", "X-CSRF-TOKEN": c.csrf or ""}), timeout=30)
    except urllib.error.HTTPError as e: r = e
    raw = r.read()
    try: return r.status, json.loads(raw)
    except ValueError: return r.status, {"_raw": raw[:200].decode("utf8", "ignore")}


GOOD = {"name": "Asha Rao", "email": "asha@example.com", "topic": "Payment query", "reference": "order_123", "message": "I paid yesterday but my plan is not active yet. Please check.", "confirm": "1", "company_site": ""}


def run(ctx, t):
    if not ctx.preview: return
    src = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "public_html"); rd = lambda f: open(os.path.join(src, f), encoding="utf8").read()
    cat = json.load(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "app", "resources", "data", "catalog.json"), encoding="utf8"))["templates"]
    t.check("no template with a side column is labelled ATS-conscious (single column is the safe standard)", not [x["slug"] for x in cat if x.get("ats") and x["layout"] in ("sidebar", "signal")], [x["slug"] for x in cat if x.get("ats") and x["layout"] in ("sidebar", "signal")])
    t.check("calculators and checklists are mounted on articles that already carry their copy", "insertAdjacentHTML(\"afterbegin\",widget)" in rd("preview.js") and "editorial==='true'" in rd("preview.js"))
    t.check("the 'unlock a clean download' help is shown only to accounts without a plan", "if (!a.paid) addUnlock" in rd("reward-paywall.js"))
    t.check("article covers are drawn cards or a picture with a fallback, never an empty dark box", ".b2-cover" in rd("blog-v2.css") and "is-broken" in rd("blog-v2.css"))
    t.check("unused stylesheets are gone", not os.path.exists(os.path.join(src, "guide.css")) and not os.path.exists(os.path.join(src, "checkout-options.css")))
    c = ctx.client()
    # home
    s, _, home = c.page("/")
    t.check("the older leaving pop-up (template invitation) is gone from the home page", "exitDialog" not in home and "data-exit-close" not in rd("preview.js") and "exit.showModal" not in rd("preview.js"))
    t.check("the home page shows designs first, then a five-step flow, who it is for and the ATS report", all(k in home for k in ('id="designs"', "heroResume", "heroLetter", "letterSpotlight", 'id="how"', "lp-flow", "lp-report", "lpPersona", "lp-incl")) and home.index('id="designs"') < home.index('id="how"') < home.index('id="diagnose"') < home.index('id="pricing"'), s)
    t.check("the home page has no repeated sections from the old layout", not any(k in home for k in ('id="why"', "lp-auto", "lp-screen", "lp-steps")))
    t.check("the home page explains how employer screening reads a resume, without claiming any employer endorses CVTara", "not endorsed by or affiliated with" in home and "selectable text" in home)
    t.check("the home page compares CVTara with the usual alternatives in general terms and names no company", 'id="compare"' in home and "Typical resume builder" in home and "not statements about any named company" in home and "pay once, no auto-renewal" in home)
    t.check("the home page offers upload as well as templates", "Upload" in home and "Upload or start fresh" in home)
    t.check("customer reviews appear in one section only", home.count("lpReviews") == 1 and "What users say" not in home and "CUSTOMER FEEDBACK" not in home)
    t.check("the FAQ is short and honest about payments and guarantees", home.count("<details") == 8 and "final and non-refundable" in home and "guarantee an interview" in home)
    t.check("no placeholder or internal notes are shown to visitors", not re.search(r"replace with your facts|public launch|lorem ipsum", home, re.I))
    t.check("the 5 / 10 paying-friends reward cards are gone from the home page", "paying friends" not in home and "YOUR FIRST REWARD" not in home and "referral-offer" not in home)
    t.check("the home page says plainly what is free and what a plan adds", 'id="get"' in home and "Free, no card" in home and "paid once" in home.lower())
    # blog
    s, _, blog = c.page("/blog.html")
    ld = re.findall(r'<script type="application/ld\+json">(.*?)</script>', blog, re.S)
    col = [json.loads(x) for x in ld if "CollectionPage" in x]
    t.check("the blog lists calculators and guides with type tabs, topic chips and a popular strip", all(k in blog for k in ("data-blog-type", "blogChips", "blogPopularGrid", "blogSearch")) and "Calculators" in blog)
    t.check("the blog page publishes a CollectionPage with its resources for search engines", col and col[0]["mainEntity"]["itemListElement"] and len(col[0]["mainEntity"]["itemListElement"]) >= 60, str(col)[:120])
    # article
    s, _, art = c.page("/guides/jobs/application-tracker-method/")
    blocks = [json.loads(x) for x in re.findall(r'<script type="application/ld\+json">(.*?)</script>', art, re.S)]
    types = {b.get("@type") for b in blocks}
    t.check("an article shows author, reading time and type instead of an internal note", s == 200 and "article-meta" in art and "min read" in art and "Editorial structure reviewed" not in art)
    t.check("the 'On this page' list is built from the real headings and works without JavaScript", 'id="articleToc"' in art and len(re.findall(r'<aside class="toc" id="articleToc">(.*?)</aside>', art, re.S)[0].split("<a ")) > 3 and re.search(r'<h2 id="[a-z0-9-]+"', art))
    t.check("an article cover is drawn in the topic's colours and carries the title", 'class="b2-cover"' in art and "background:linear-gradient" not in art)
    t.check("an article ends with a next step and related reading", "article-cta" in art and "article-related" in art and art.count('<a href="/guides/') >= 3)
    t.check("an article publishes BlogPosting, breadcrumb and FAQ data", {"BlogPosting", "BreadcrumbList", "FAQPage"} <= types, types)
    t.check("the article's FAQ is shown once", art.count('class="owner-faq"') == 1 and art.count("Frequently asked questions") <= 2)
    s, _, calc = c.page("/calculators/gratuity-calculator-online/")
    t.check("a calculator page is built the same way", s == 200 and "article-meta" in calc and "Calculator" in calc)
    # about, contact, hours
    s, _, about = c.page("/about.html")
    t.check("About says who is behind CVTara, what it does and how it works", s == 200 and all(k in about for k in ("Who is behind it", "What you can do here", "How we work", "non-refundable", "support@cvtara.com")))
    s, _, cont = c.page("/contact.html")
    t.check("Contact shows email, hours and address with a working form", s == 200 and "c2-card" in cont and "Monday–Saturday" in cont and 'id="contactForm"' in cont and 'name="attachment"' in cont)
    t.check("support hours read the same on every page", "Monday–Friday" not in cont + about + home)
    s, _, priv = c.page("/privacy.html")
    t.check("the privacy policy mentions the optional recent-activity notice and the contact form", "recent-activity notice" in priv and "contact form" in priv)

    # contact form -> owner inbox
    o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
    base = o.api("GET", "admin/support/summary?days=30")[1]["recent"]["total"]
    g = ctx.client(); s, j = contact(g, GOOD)
    t.check("a visitor can send the contact form without signing in", s == 200 and j.get("ok") and j.get("id"), (s, j))
    rows = o.api("GET", "admin/support?area=contact&per=50")[1]["rows"]
    mine = [r for r in rows if r["email"] == "asha@example.com"]
    t.check("the message appears in the owner Support Inbox with name, email, topic and reference", len(mine) == 1 and mine[0]["name"] == "Asha Rao" and "Payment query" in mine[0]["message"] and "order_123" in mine[0]["message"] and mine[0]["status"] == "new", str(rows)[:200])
    t.check("the inbox total went up by one", o.api("GET", "admin/support/summary?days=30")[1]["recent"]["total"] == base + 1)
    t.check("the inbox lists 'Contact form' as an area", "contact" in o.api("GET", "admin/support/summary?days=30")[1]["areas"])
    gg = ctx.client(); s, j = contact(gg, dict(GOOD, email="shot@example.com"), png())
    t.check("one screenshot can be attached", s == 200 and ctx.db().q("select count(*) from support_attachments where report_id in (select id from support_reports where email='shot@example.com')")[0][0] == 1, (s, j))
    h = ctx.client(); t.check("a filled hidden field (a bot) is refused", contact(h, dict(GOOD, email="bot@example.com", company_site="spam.example"))[0] == 422)
    t.check("a bad email, a short message or a missing confirmation is refused", contact(h, dict(GOOD, email="not-an-email"))[0] == 422 and contact(h, dict(GOOD, message="hi"))[0] == 422 and contact(h, {k: v for k, v in GOOD.items() if k != "confirm"})[0] == 422)
    t.check("an unknown topic is refused", contact(h, dict(GOOD, topic="Sell me things"))[0] == 422)
    k = ctx.client(); k.register("contact-user@test.local")
    s, j = contact(ctx.client(), dict(GOOD, email="contact-user@test.local")); r = [x for x in o.api("GET", "admin/support?area=contact&per=50")[1]["rows"] if x["email"] == "contact-user@test.local"]
    t.check("a message from a registered email is linked to that customer and shows the plan", s == 200 and r and r[0]["plan"] == "Free", str(r)[:160])
    codes = [contact(ctx.client(), dict(GOOD, email="rate@example.com"))[0] for _ in range(6)]
    t.check("the same email cannot flood the inbox", codes.count(200) == 5 and codes[5] == 429, codes)
    t.check(".htaccess serves .mjs files as JavaScript (module imports fail otherwise)", "AddType application/javascript .mjs" in open(os.path.join(ctx.public, ".htaccess"), encoding="utf8").read())
