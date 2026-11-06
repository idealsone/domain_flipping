"""Customer portal: saved letters under Cover Letters, feedback page, customer guide, cvtara.com details."""
import os
NAME = "Customer portal and guide"


def run(ctx, t):
    if not ctx.preview: return
    c = ctx.client(); c.register("portal-a@test.local")
    s, _, html = c.page("/workspace.html")
    t.check("workspace has a Share feedback page in the menu", 'data-workspace-view="feedback"' in html and 'id="view-feedback"' in html)
    t.check("workspace shows cvtara.com and the support address", "cvtara.com" in html and "support@cvtara.com" in html)
    js = open(os.path.join(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "public_html"), "product-roadmap.js"), encoding="utf8").read()
    t.check("saved cover letters are drawn under the Cover Letters section", "$('#view-letter')" in js and "My saved cover letters" in js and "$('#view-account')" not in js.split("async function savedLetters")[1][:200])
    pub = open(os.path.join(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "public_html"), "owner-public.js"), encoding="utf8").read()
    t.check("feedback form mounts on its own page with display name, rating and feedback", "#view-feedback" in pub and "displayName" in pub and "fb-starrow" in pub)
    s, j = c.api("POST", "feedback", {"displayName": "Priya S.", "rating": 5, "content": "The cover letter studio was very helpful.", "consent": False})
    t.check("feedback can be submitted", s == 200, f"{s} {j}")
    s, j = c.api("GET", "feedback"); t.check("customer sees their feedback", s == 200 and len(j.get("reviews", [])) == 1)
    s, j = c.api("POST", "feedback", {"displayName": "Priya S.", "rating": 5, "content": "The cover  letter studio was VERY helpful!", "consent": False})
    t.check("the same feedback sent twice is refused politely", s == 422 and "already sent" in str(j).lower(), f"{s} {j}")
    s, j = c.api("POST", "feedback", {"displayName": "Priya S.", "designation": "Data Analyst", "company": "Infosys", "rating": 4, "content": "ATS check showed exactly which keywords I missed.", "consent": True})
    t.check("feedback can carry job title and company", s == 200, f"{s} {j}")
    t.check("feedback form asks for job title and company", 'name="designation"' in pub and 'name="company"' in pub)
    o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
    s, j = o.api("GET", "admin/reviews?status=pending&q=keywords")
    row = next((r for r in j.get("reviews", []) if "keywords I missed" in r.get("content", "")), {})
    t.check("console review shows company, job title and buyer/duplicate flags", s == 200 and row.get("company") == "Infosys" and row.get("designation") == "Data Analyst" and row.get("verifiedBuyer") is False and "duplicates" in row, row)
    s, _ = o.api("POST", "admin/reviews", {"id": row.get("id"), "status": "published"})
    pc = ctx.client().api("GET", "public-content")[1]
    pr = next((r for r in pc.get("reviews", []) if "keywords I missed" in r.get("content", "")), {})
    t.check("published review shows job title and company; not marked verified without a paid order", pr.get("company") == "Infosys" and pr.get("designation") == "Data Analyst" and pr.get("verified") is False, pr)
    home = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "public_html", "home-ui.js"), encoding="utf8").read()
    t.check("homepage review card shows the Verified buyer badge", "Verified buyer" in home and "r.designation" in home)
    s, _, g = ctx.client().page("/customer-guide.html")
    low = g.lower()
    t.check("customer guide loads", s == 200 and "customer guide" in low)
    for needle in ("cvtara.com", "support@cvtara.com", "within 7 days", "primary resume", "cover letters", "downloads", "refer"):
        t.check(f"guide covers: {needle}", needle in low, needle)
    t.check("guide has a dark-mode theme and mobile layout", "html.dark" in g and "@media(max-width:900px)" in g)
    t.check("guide has no placeholder addresses", "example.com" not in low and "yourdomain" not in low)
    s, _, ct = ctx.client().page("/contact.html"); t.check("contact page uses cvtara.com details", "support@cvtara.com" in ct and "example.com" not in ct)

    # previews are protected and always watermarked until a clean download
    s, _, ws = c.page("/workspace.html")
    t.check("workspace loads the preview protection script", "preview-guard.js" in ws)
    guard = open(os.path.join(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "public_html"), "preview-guard.js"), encoding="utf8").read()
    t.check("preview protection blocks copy, print, drag and context menu and blurs on focus loss", all(k in guard for k in ("contextmenu", "dragstart", "copy", "@media print", "pg-hide", "PrintScreen")))
    t.check("preview watermark carries an account code", "ID '" in guard or "ID ' +" in guard)
    pub2 = open(os.path.join(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "public_html"), "owner-public.js"), encoding="utf8").read()
    t.check("on-screen previews are watermarked until the server says the account is paid (free accounts watermarked, paid clean)", "applyWatermark(false)" in pub2 and "/api/allowance" in pub2 and "j.paid" in pub2 and "applyWatermark(p.isPaid)" not in pub2)
