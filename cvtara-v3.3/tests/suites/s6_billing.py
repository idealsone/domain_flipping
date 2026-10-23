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
    db.q("insert or replace into site_settings(key,value_json,revision,created_at,updated_at) values('plans',?,1,datetime('now'),datetime('now'))", __import__('json').dumps({"addon-10": {"sale": True}}))  # credit packs are off sale by default
    uid = lambda e: db.q("select id from users where email=?", e)[0][0]

    # add-on credits last as long as the subscription and follow it into a renewal
    a = ctx.client(); a.register("bill-a@test.local")
    t.check("buy plan", buy(a, "job-hunt")[0] == 200)
    t.check("buy add-on", buy(a, "addon-10")[0] == 200)
    q1 = "select access_until from purchases where owner_id=? and status='paid' and plan=? order by access_until desc"
    plan_end = db.q(q1, uid("bill-a@test.local"), "job-hunt")[0][0]; add_end = db.q(q1, uid("bill-a@test.local"), "addon-10")[0][0]
    t.check("an add-on ends exactly when the subscription ends", plan_end == add_end, (plan_end, add_end))
    t.check("renew plan", buy(a, "job-hunt")[0] == 200)
    plan_end2 = db.q(q1, uid("bill-a@test.local"), "job-hunt")[0][0]; add_end2 = db.q(q1, uid("bill-a@test.local"), "addon-10")[0][0]
    t.check("after an early renewal the add-on moves to the end of the renewed plan", plan_end2 > plan_end and add_end2 == plan_end2, (plan_end2, add_end2))
    k = a.api("GET", "allowance")[1]["credits"]
    t.check("after plan + add-on + renewal the add-on credits are still there", k["resumes"]["total"] == 15 and k["letters"]["total"] == 15 and k["addOns"]["resumes"] == 10, k)

    # F-03: coupon limits
    s, j = o.api("GET", "admin/business-controls"); bc = j["data"]
    bc["coupons"] = [{"code": "ONE", "type": "percent", "value": 10, "enabled": True, "plans": ["job-hunt", "pro"], "expiresAt": "", "maxUses": 1, "perUser": 1}]
    t.check("save coupon", o.api("POST", "admin/business-controls", bc)[0] == 200)
    stale = dict(bc, coupons=[dict(bc["coupons"][0], plans=["job-hunt", "pro", "complete-kit"])])
    t.check("a coupon that still lists an off-sale package saves, without that package", o.api("POST", "admin/business-controls", stale)[0] == 200 and o.api("GET", "admin/business-controls")[1]["data"]["coupons"][0]["plans"] == ["job-hunt", "pro"])
    b = ctx.client(); b.register("bill-b@test.local"); c2 = ctx.client(); c2.register("bill-c@test.local")
    s, j1 = b.api("POST", "checkout/start", {"plan": "job-hunt", "coupon": "ONE"}); t.check("first order with coupon starts", s == 200, j1)
    s, j = c2.api("POST", "checkout/quote", {"plan": "job-hunt", "coupon": "ONE"})
    t.check("an open order of someone else reserves a single-use coupon", s == 422 and "limit" in str(j), f"{s} {j}")
    s, j = b.api("POST", "checkout/quote", {"plan": "job-hunt", "coupon": "ONE"}); t.check("the same customer can retry their own order", s == 200, j)
    s, j2 = b.api("POST", "checkout/start", {"plan": "job-hunt", "coupon": "ONE"})
    ok1 = b.api("POST", "checkout/verify", {"provider": "demo", "orderId": j1["orderId"]})[0]; ok2 = b.api("POST", "checkout/verify", {"provider": "demo", "orderId": j2["orderId"]})[0]
    t.check("both paid tabs are granted (money was received)", ok1 == 200 and ok2 == 200, (ok1, ok2))
    n = db.q("select count(*) from error_logs where message like '%beyond its limit%'")[0][0]
    t.check("the extra redemption is flagged in the Error Log", n >= 1, n)

    # F-06 / F-07: manual grant and revoke
    d = ctx.client(); d.register("bill-d@test.local"); did = uid("bill-d@test.local")
    s, j = o.api("POST", "admin/users/grant", {"userId": did, "plan": "pro", "note": "complimentary", "sendEmail": False})
    t.check("grant without an amount succeeds", s == 200, j)
    r = db.q("select amount_paise, is_live from purchases where owner_id=? and provider='manual'", did)[0]
    t.check("a grant without an amount records zero revenue", r[0] == 0 and not r[1], r)
    t.check("user has access after grant", d.api("GET", "allowance")[1].get("plan") == "pro")
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
    enc = db.q("select destination_encrypted, destination_masked, owner_id from withdrawals where id='11111111-1111-1111-1111-111111111111'")[0]
    t.check("payout details are erased, masked destination kept", "SECRET" not in enc[0] and enc[1] == "xxx@upi", enc)
    keep = db.q("select id from users where email='deleted-customers@deleted.invalid'")
    t.check("the account row is gone; the payout record moves to the Deleted customers placeholder", not db.q("select id from users where id=?", eid) and keep and enc[2] == keep[0][0], (enc[2], keep))

    # The owner deletes a customer: the account and its content leave the server, money records stay with no name.
    f = ctx.client(); f.register("bill-f@test.local"); fid = uid("bill-f@test.local")
    f.api("POST", "resumes", {"title": "Mine", "data": {"version": "1", "title": "Mine", "personal": {"name": "Bill F"}}})
    db.q("insert into purchases(id,owner_id,plan,amount_paise,status,provider,payment_reference,is_live,limits_json,access_days,purchased_at,access_until,created_at,updated_at) values('22222222-2222-2222-2222-222222222222',?,'job-hunt',29900,'paid','razorpay','pay_del_1',1,'{}',30,datetime('now'),datetime('now','+30 days'),datetime('now'),datetime('now'))", fid)
    db.q("insert into support_reports(id,owner_id,name,email,area,kind,page,message,status,created_at,updated_at) values('33333333-3333-3333-3333-333333333333',?,'Bill F','bill-f@test.local','builder','bug','/','help me','new',datetime('now'),datetime('now'))", fid)
    s, j = o.api("POST", "admin/users/delete", {"userId": fid, "confirm": "DELETE"}); t.check("the owner deletes a customer", s == 200, (s, j))
    t.check("the customer's account, resumes and support messages are gone", not db.q("select id from users where id=?", fid) and not db.q("select id from resume_drafts where owner_id=?", fid) and not db.q("select id from support_reports where id='33333333-3333-3333-3333-333333333333'"))
    t.check("the payment record stays for accounting under the placeholder", db.q("select owner_id from purchases where id='22222222-2222-2222-2222-222222222222'")[0][0] == keep[0][0])
    t.check("no row anywhere still carries the deleted email", not db.q("select id from users where email='bill-f@test.local'") and not db.q("select id from support_reports where email='bill-f@test.local'"))
    t.check("the placeholder is not listed among users", "deleted-customers@" not in str(o.api("GET", "admin/users?page=1")[1]))
    db.q("delete from site_settings where key='plans'")
