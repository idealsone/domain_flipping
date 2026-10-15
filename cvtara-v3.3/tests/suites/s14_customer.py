"""Customer portal: saved letters under Cover Letters, feedback page, customer guide, cvtara.com details."""
import os
NAME = "Customer portal and guide"


def run(ctx, t):
    if not ctx.preview: return
    c = ctx.client(); c.register("portal-a@test.local")
    s, _, html = c.page("/workspace.html")
    t.check("workspace has a Share feedback page in the menu", 'data-workspace-view="feedback"' in html and 'id="view-feedback"' in html)
    t.check("workspace shows cvtara.com and the support address", "cvtara.com" in html and "support@cvtara.com" in html)
    js = open(os.path.join(ctx.public, "product-roadmap.js"), encoding="utf8").read()
    t.check("saved cover letters are drawn under the Cover Letters section", "$('#view-letter')" in js and "My saved cover letters" in js and "$('#view-account')" not in js.split("async function savedLetters")[1][:200])
    pub = open(os.path.join(ctx.public, "owner-public.js"), encoding="utf8").read()
    t.check("feedback form mounts on its own page with display name, rating and feedback", "#view-feedback" in pub and "displayName" in pub and "fb-starrow" in pub)
    s, j = c.api("POST", "feedback", {"displayName": "Priya S.", "rating": 5, "content": "The cover letter studio was very helpful.", "consent": False})
    t.check("feedback can be submitted", s == 200, f"{s} {j}")
    s, j = c.api("GET", "feedback"); t.check("customer sees their feedback", s == 200 and len(j.get("reviews", [])) == 1)
    s, _, g = ctx.client().page("/customer-guide.html")
    low = g.lower()
    t.check("customer guide loads", s == 200 and "customer guide" in low)
    for needle in ("cvtara.com", "support@cvtara.com", "non-refundable", "primary resume", "cover letters", "downloads", "refer"):
        t.check(f"guide covers: {needle}", needle in low, needle)
    t.check("guide has a dark-mode theme and mobile layout", "html.dark" in g and "@media(max-width:900px)" in g)
    t.check("guide has no placeholder addresses", "example.com" not in low and "yourdomain" not in low)
    s, _, ct = ctx.client().page("/contact.html"); t.check("contact page uses cvtara.com details", "support@cvtara.com" in ct and "example.com" not in ct)
