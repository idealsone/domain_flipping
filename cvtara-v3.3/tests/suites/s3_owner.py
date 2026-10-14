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
    t.check("referral rules include every field", all(k in r for k in ("enabled", "rateBps", "tierThreshold", "tierRateBps", "minimumPaise", "holdDays", "payoutHours", "downloadThreshold", "premiumThreshold", "premiumDays", "minQualifyingPaise", "requirePan", "proOnly")), list(r))
    body = {"enabled": True, "requirePan": False, "proOnly": True, "holdDays": 5, "rateBps": 2500, "tierRateBps": 3500, "tierThreshold": 50, "minimumPaise": 150000, "payoutHours": 48, "minQualifyingPaise": 19900, "downloadThreshold": 3, "premiumThreshold": 8, "premiumDays": 45}
    s, j = o.api("POST", "admin/referrals/settings", body); t.check("save all referral rules", s == 200, f"{s} {j}")
    r = o.api("GET", "admin/referrals")[1]["rules"]
    t.check("rules persisted", r["rateBps"] == 2500 and r["holdDays"] == 5 and r["premiumDays"] == 45 and r["minimumPaise"] == 150000, r)
    bad = dict(body, premiumThreshold=2, downloadThreshold=5); s, _ = o.api("POST", "admin/referrals/settings", bad)
    t.check("premium reward must need more friends than download reward", s == 422, s)
    s, j = o.api("GET", "admin/business-controls"); t.check("Business Ops no longer carries referral rules", s == 200 and "referrals" not in str(j.get("data", {}).keys()).lower(), list(j.get("data", {}))[:20])
    t.check("owner cannot be reached without login", ctx.client().api("GET", "admin/referrals")[0] in (401, 403))
