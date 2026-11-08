"""Owner console: every view's data endpoint answers, and Referral Ops is the one place for referral rules."""
NAME = "Owner console and Referral Ops"
ENDPOINTS = ["admin/users?page=1", "admin/plans", "admin/business-controls", "admin/referrals", "admin/content/legal", "admin/content/blog",
             "admin/content/seo", "admin/content/popup", "admin/content/emails", "admin/content/integrations", "admin/setup/status"]


def run(ctx, t):
    o = ctx.client()
    if not ctx.preview: return
    s, h, _ = o.raw("GET", "/preview/go/admin"); t.check("preview admin sign-in", s in (200, 302), s)
    o.page("/admin.html")
    for e in ENDPOINTS:
        s, j = o.api("GET", e); t.check(f"GET /api/{e}", s == 200, f"{s} {str(j)[:120]}")
    s, j = o.api("GET", "admin/referrals"); r = j.get("rules", {})
    t.check("referral rules include every field", all(k in r for k in ("enabled", "rateBps", "tierThreshold", "tierRateBps", "minimumPaise", "holdDays", "payoutHours", "downloadThreshold", "premiumDays", "minQualifyingPaise", "requirePan", "proOnly")), list(r))
    body = {"enabled": True, "requirePan": False, "proOnly": True, "holdDays": 5, "rateBps": 2500, "tierRateBps": 3500, "tierThreshold": 50, "minimumPaise": 150000, "payoutHours": 48, "minQualifyingPaise": 19900, "downloadThreshold": 3, "premiumDays": 45}
    s, j = o.api("POST", "admin/referrals/settings", body); t.check("save all referral rules", s == 200, f"{s} {j}")
    r = o.api("GET", "admin/referrals")[1]["rules"]
    t.check("rules persisted", r["rateBps"] == 2500 and r["holdDays"] == 5 and r["premiumDays"] == 45 and r["minimumPaise"] == 150000, r)
    low = dict(body, downloadThreshold=3); s, _ = o.api("POST", "admin/referrals/settings", low)
    t.check("the owner can change how many paying friends earn the reward", s == 200 and o.api("GET", "admin/referrals")[1]["rules"]["downloadThreshold"] == 3, s)
    o.api("POST", "admin/referrals/settings", body)
    s, j = o.api("GET", "admin/business-controls"); t.check("Business Ops no longer carries referral rules", s == 200 and "referrals" not in str(j.get("data", {}).keys()).lower(), list(j.get("data", {}))[:20])
    t.check("owner cannot be reached without login", ctx.client().api("GET", "admin/referrals")[0] in (401, 403))
    # "Give ₹50, get ₹50" wallet rules are saved from the same form.
    w = {"mode": "wallet", "friendDiscountPaise": 6000, "rewardPaise": 4000, "qualifyDays": 7, "walletDays": 120, "monthlyCapPaise": 80000}
    s, j = o.api("POST", "admin/referrals/settings", dict(body, **w)); r = o.api("GET", "admin/referrals")[1]["rules"]
    t.check("the give-and-get wallet rules are saved", s == 200 and all(r[k] == v for k, v in w.items()), (s, {k: r.get(k) for k in w}))
    s, _ = o.api("POST", "admin/referrals/settings", dict(body, mode="cash")); t.check("an unknown reward type is refused", s == 422, s)
    ctx.db().q("delete from site_settings where key='referrals'")
    # Main-page search text: a saved row with blank fields still shows the prefilled text.
    db = ctx.db(); db.q("insert or replace into site_settings(key,value_json,revision,created_at,updated_at) values('seo',?,1,datetime('now'),datetime('now'))", __import__("json").dumps({"sitemapEnabled": True, "searchIndexing": False, "siteUrl": "https://cvtara.com", "pages": {"/": {"title": "", "description": "", "keywords": "", "includeInSitemap": True}, "/about.html": {"title": "Owner title"}}}))
    p = o.api("GET", "admin/content/seo")[1]["data"]["pages"]
    t.check("blank search titles fall back to the prefilled ones", p["/"]["title"] and p["/"]["description"] and p["/"]["keywords"] and p["/templates.html"]["title"], p.get("/"))
    t.check("an owner's own title is kept", p["/about.html"]["title"] == "Owner title" and p["/about.html"]["description"], p.get("/about.html"))
    db.q("delete from site_settings where key='seo'")
