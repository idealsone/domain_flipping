"""Billing rules from the audit: add-on renewal, coupon limits, manual grants, revoke, account deletion."""
import json
NAME = "Billing, grants and account deletion"
RULES = {"enabled": True, "requirePan": False, "proOnly": True, "holdDays": 7}


def buy(c, plan, coupon=""):
    s, j = c.api("POST", "checkout/start", {"plan": plan, "coupon": coupon})
    if s != 200: return s, j
    return c.api("POST", "checkout/verify", {"provider": "demo", "orderId": j["orderId"]})[0], j


def run(ctx, t):
    if not ctx.preview: return
    db = ctx.db(); o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
    uid = lambda e: db.q("select id from users where email=?", e)[0][0]

    # F-02: renewing a plan must carry the live add-on with it
    a = ctx.client(); a.register("bill-a@test.local")
    t.check("buy plan", buy(a, "fresher-basic")[0] == 200)
    t.check("buy add-on", buy(a, "addon-10")[0] == 200)
    t.check("renew plan", buy(a, "fresher-basic")[0] == 200)
    rows = db.q("select plan, access_until from purchases where owner_id=? and status='paid'", uid("bill-a@test.local"))
    plan_end = max(r[1] for r in rows if r[0] == "fresher-basic"); addon_end = max(r[1] for r in rows if r[0] == "addon-10")
    t.check("add-on lasts as long as the renewed plan", addon_end >= plan_end, f"addon {addon_end} plan {plan_end}")

    # F-03: coupon limits
    s, j = o.api("GET", "admin/business-controls"); bc = j["data"]
    bc["coupons"] = [{"code": "ONE", "type": "percent", "value": 10, "enabled": True, "plans": ["fresher-basic", "fresher-standard"], "expiresAt": "", "maxUses": 1, "perUser": 1}]
    t.check("save coupon", o.api("POST", "admin/business-controls", bc)[0] == 200)
    b = ctx.client(); b.register("bill-b@test.local"); c2 = ctx.client(); c2.register("bill-c@test.local")
    s, j1 = b.api("POST", "checkout/start", {"plan": "fresher-basic", "coupon": "ONE"}); t.check("first order with coupon starts", s == 200, j1)
    s, j = c2.api("POST", "checkout/quote", {"plan": "fresher-basic", "coupon": "ONE"})
    t.check("an open order of someone else reserves a single-use coupon", s == 422 and "limit" in str(j), f"{s} {j}")
    s, j = b.api("POST", "checkout/quote", {"plan": "fresher-basic", "coupon": "ONE"}); t.check("the same customer can retry their own order", s == 200, j)
    s, j2 = b.api("POST", "checkout/start", {"plan": "fresher-basic", "coupon": "ONE"})
    ok1 = b.api("POST", "checkout/verify", {"provider": "demo", "orderId": j1["orderId"]})[0]; ok2 = b.api("POST", "checkout/verify", {"provider": "demo", "orderId": j2["orderId"]})[0]
    t.check("both paid tabs are granted (money was received)", ok1 == 200 and ok2 == 200, (ok1, ok2))
    n = db.q("select count(*) from error_logs where message like '%beyond its limit%'")[0][0]
    t.check("the extra redemption is flagged in the Error Log", n >= 1, n)

    # F-06 / F-07: manual grant and revoke
    d = ctx.client(); d.register("bill-d@test.local"); did = uid("bill-d@test.local")
    s, j = o.api("POST", "admin/users/grant", {"userId": did, "plan": "fresher-pro", "note": "complimentary", "sendEmail": False})
    t.check("grant without an amount succeeds", s == 200, j)
    r = db.q("select amount_paise, is_live from purchases where owner_id=? and provider='manual'", did)[0]
    t.check("a grant without an amount records zero revenue", r[0] == 0 and not r[1], r)
    t.check("user has access after grant", d.api("GET", "allowance")[1].get("plan") == "fresher-pro")
    s, j = o.api("POST", "admin/users/revoke", {"userId": did, "note": "test revoke"}); t.check("revoke works", s == 200, j)
    r = db.q("select status, refunded_paise from purchases where owner_id=? and provider='manual'", did)[0]
    t.check("revoke does not invent a refund", r[1] == 0 and r[0] == "paid", r)
    t.check("access is gone after revoke", d.api("GET", "allowance")[1].get("plan") == "free", d.api("GET", "allowance")[1].get("plan"))

    # F-12: deletion erases payout details
    e = ctx.client(); e.register("bill-e@test.local"); eid = uid("bill-e@test.local")
    db.q("insert into withdrawals (id, owner_id, amount_paise, destination_encrypted, method, destination_masked, status, due_at, created_at, updated_at) values ('11111111-1111-1111-1111-111111111111', ?, 100000, 'SECRET-BANK-DETAILS', 'upi', 'xxx@upi', 'paid', datetime('now'), datetime('now'), datetime('now'))", eid)
    ex = e.api("GET", "account/export")[1]["export"]
    t.check("export includes payout records", "withdrawals" in ex and "referralCommissions" in ex)
    t.check("delete account", e.api("POST", "account/delete", {"password": "TestPassw0rd!!x", "confirm": "DELETE"})[0] == 200)
    enc = db.q("select destination_encrypted, destination_masked from withdrawals where owner_id=?", eid)[0]
    t.check("payout details are erased, masked destination kept", "SECRET" not in enc[0] and enc[1] == "xxx@upi", enc)
