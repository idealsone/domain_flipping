"""Guides and help centre (3.46.0): the owner guide has the 21 chapters and the customer guide the 16 chapters of the V2
spec, each with a last-reviewed date, a version note, search and video slots; the customer guide says nothing about AI
and keeps the facts customers rely on; the "?" buttons point to the right chapter; Tara's product guide reads the new
guide; the owner guide stays private."""
import os, re
NAME = "Guides and help centre"
ADMIN = ["login", "dashboard", "command", "customers", "orders", "libraries", "templates", "offers", "referrals", "comms", "reports", "support", "assisted", "ai", "seo", "workforce", "growth", "approvals", "integrations", "backups", "help"]
CUST = ["register", "dashboard", "create", "upload", "improve", "ats", "letters", "combined", "applications", "interview", "tara", "team", "plans", "downloads", "referrals", "account", "help"]


def run(ctx, t):
    o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
    s, _, g = o.page("/admin-guide.html")
    ids = re.findall(r'<section id="([a-z-]+)">', g)
    t.check("the owner guide has the 21 chapters in order, plus its version history", s == 200 and ids == ADMIN + ["versions"], ids)
    t.check("the owner guide shows when it was last reviewed and its version", re.search(r'Last reviewed <time datetime="\d{4}-\d\d-\d\d">', g) and "Guide version 3.46.0" in g)
    t.check("the owner guide is searchable and has a slot for a short video per chapter", 'id="gq"' in g and g.count('data-video=') == 21)
    t.check("the owner guide stays private", ctx.client().raw("GET", "/admin-guide.html")[0] in (302, 401, 403, 404))
    for needle in ("Ctrl</kbd>+<kbd>K", "read-only", "Approvals &amp; tasks", "Payment reconciliation", "Content versions", "Team &amp; security"):
        t.check(f"owner guide covers: {needle}", needle in g, needle)

    c = ctx.client(); s, _, h = c.page("/customer-guide.html")
    ids = re.findall(r'<section class="g" id="([a-z-]+)">', h)
    t.check("the customer guide has the 17 chapters in order, plus its version history", s == 200 and ids == CUST + ["versions"], ids)
    t.check("the customer guide shows when it was last reviewed, is searchable and has video slots", re.search(r'Last reviewed <time datetime="\d{4}-\d\d-\d\d">', h) and 'id="gq"' in h and h.count('data-video=') == 17)
    text = re.sub(r"<script.*?</script>|<style.*?</style>", "", h, flags=re.S)
    t.check("the customer guide never mentions AI or the provider", not re.search(r"\bAI\b|artificial intelligence|Claude|Anthropic", text), re.findall(r".{30}\bAI\b.{30}", text)[:3])
    t.check("the customer guide states the packages as they are", all(x in text for x in ("10 tailored resumes and 10 cover letters", "30 tailored resumes and 30 cover letters", "One-time payment", "7-day refund if unused")))
    t.check("old package names and rules are gone", not any(x in text for x in ("Fresher Basic", "Standard 40", "No-Refund Policy", "commission")))
    t.check("every chapter link in the menu has its chapter", all(f'href="#{i}"' in h for i in CUST))

    for page, key in (("/admin.html", o), ("/workspace.html", None)):
        x = key or ctx.client()
        if key is None: x.register("gd-help@test.local")
        body = x.page(page)[2]
        t.check(f"{page} has the ? help button and its script", 'id="ctxHelp"' in body and "/context-help.js" in body)
    js = open(os.path.join(ctx.public, "context-help.js"), encoding="utf8").read()
    t.check("the help button maps console and workspace pages to existing chapters", all(f"'{v}'" in js for v in ("approvals", "seo", "workforce", "applications", "plans")) and all(re.findall(r":\s*'([a-z-]+)'", js)))
    mapped = set(re.findall(r":\s*'([a-z-]+)'", js))
    t.check("no help link points to a chapter that does not exist", mapped <= set(ADMIN) | set(CUST), mapped - set(ADMIN) - set(CUST))
    r = ctx.inst.artisan("tinker", "--execute=$m=new ReflectionMethod(App\\Services\\TaraChat::class,'guide');$m->setAccessible(true);echo $m->invoke(null);")
    out = r.stdout
    t.check("Tara's product guide reads the new guide, without video slots or history", "Combined service" in out and "Ask Tara" in out and "coming soon" not in out and "version history" not in out.lower(), out[:200])
