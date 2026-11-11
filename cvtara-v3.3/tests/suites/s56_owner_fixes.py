"""Owner-reported fixes (3.47.0): User Mailer saves every built-in template (new triggers were refused), "On this page"
links stay on the article, the job/city/state list in Blog & SEO fills (an id clash with the Resume Library emptied it)
and carries Google's status, one help entry and no separate "Ask Tara" item in the customer menu, and Integrations > AI
can test the saved key."""
import os, re
NAME = "Owner-reported fixes"


def run(ctx, t):
    o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")

    s, j = o.api("GET", "admin/content/emails")
    trig = {x.get("trigger") for x in j["data"]}
    t.check("the built-in templates include the newer triggers", {"activation_nudge", "ats_nudge", "pricing_help", "support_reply"} <= trig, sorted(trig))
    s2, r = o.api("POST", "admin/content/emails", {"data": j["data"], "revision": j["revision"]})
    t.check("Save templates works with every built-in template (no 'selected trigger is invalid')", s2 == 200, (s2, r))
    bad = [dict(x) for x in j["data"]]; bad[0]["trigger"] = "not_a_trigger"
    s3, _ = o.api("POST", "admin/content/emails", {"data": bad, "revision": (r or {}).get("revision", j["revision"] + 1)})
    t.check("an unknown trigger is still refused", s3 == 422, s3)

    s, d = o.api("GET", "admin/seo")
    t.check("the SEO list sends each page's Google status map", s == 200 and isinstance(d.get("google"), (dict, list)) and "searchConsole" in d, list(d)[:6])
    land = [x for x in d.get("routes", []) if re.match(r"^/(resume|resume-builder|resume-examples)/", x["path"])]
    t.check("the job, city and state pages are listed", len(land) > 100, len(land))

    js = open(os.path.join(ctx.app, "resources", "console", "publish.js"), encoding="utf8").read()
    lib = open(os.path.join(ctx.app, "resources", "console", "content.js"), encoding="utf8").read()
    ids = set(re.findall(r'id="(land[A-Za-z]+)"', js))
    t.check("the job/city/state list uses its own element ids (no clash with the Resume Library)", ids >= {"landQ", "landG", "landI", "landBody", "landPager"} and not any(f'id="{i}"' in lib for i in ids), ids)
    t.check("the list shows Google's index status and clicks", "Not checked yet" in js and "Indexed" in js and "Clicks" in js)

    pub = ctx.public
    cg = open(os.path.join(pub, "crash-guard.js"), encoding="utf8").read()
    t.check("in-page links keep the visitor on the page despite <base href=\"/\">", "a[href^=\"#\"]" in cg and "location.hash = hash" in cg)
    guide = next((x["path"] for x in d.get("routes", []) if x["path"].startswith("/guides/")), None)
    if guide:
        h = ctx.client().page(guide)[2]
        hrefs = re.findall(r'<aside class="toc" id="articleToc">(.*?)</aside>', h, re.S)
        links = re.findall(r'href="([^"]+)"', hrefs[0]) if hrefs else []
        t.check("an article's 'On this page' links carry the article's own address", not links or all(l.startswith(guide + "#") for l in links), links[:3])

    tara = open(os.path.join(pub, "tara-chat.js"), encoding="utf8").read()
    exp = open(os.path.join(pub, "experience.js"), encoding="utf8").read()
    t.check("no separate 'Ask Tara' item is added to the customer menu", "data-tara-nav" not in tara)
    t.check("the menu gets no second guide link when it already has the Help centre", 'a[href$="customer-guide.html"]' in exp)
    c = ctx.client(); c.register("menu-fix@test.local")
    w = c.page("/workspace.html")[2]
    t.check("the workspace menu keeps Help centre and Career assistance", "Help centre" in w and "Career assistance" in w)

    s, r = o.api("POST", "admin/providers", {"test": "ai"})
    t.check("Integrations > AI can test the key and answers in words, never echoing the key", s == 200 and r.get("works") is False and "key" in r.get("message", "").lower() and "sk-ant" not in str(r), (s, r))
