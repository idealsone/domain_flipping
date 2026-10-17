"""Referral reward: 5 paying friends = one free resume download plus one month of subscription. One reward only, claimed once."""
import json, uuid
NAME = "Referral reward (5 paying friends)"


def run(ctx, t):
    db = ctx.db()
    r = ctx.client(); r.register("ref-boss@test.local")
    uid = db.q("select id from users where email='ref-boss@test.local'")[0][0]
    code = db.q("select referral_code from users where id=?", uid)[0][0]
    s, st = r.api("GET", "referral-rewards")
    t.check("the reward is on and needs 5 paying friends", s == 200 and st.get("enabled") is True and st["rules"]["downloadThreshold"] == 5, (s, str(st)[:200]))
    t.check("there is no second 10-friend tier in the rules the page uses", "10" not in str(st["rules"].get("downloadThreshold")) and st["rules"].get("downloadThreshold") == 5)

    def friend(n, pay=True):
        c = ctx.client(); em = f"ref-f{n}@test.local"; c.register(em, ref=code)
        fid = db.q("select id from users where email=?", em)[0][0]
        if pay:
            db.q("insert into purchases(id,owner_id,plan,amount_paise,status,provider,payment_reference,is_live,limits_json,access_days,purchased_at,access_until,created_at,updated_at) values(?,?,?,?,?,?,?,?,?,?,datetime('now'),datetime('now','+60 days'),datetime('now'),datetime('now'))",
                 str(uuid.uuid4()), fid, "fresher-standard", 39900, "paid", "cashfree", "ref-pay-" + str(n), 1, "{}", 60)
        return fid
    for n in range(4): friend(n)
    friend(90, pay=False)  # signed up but never paid: does not count
    s, st = r.api("GET", "referral-rewards"); t.check("4 paying friends and 1 who did not pay count as 4", st.get("count") == 4, st.get("count"))
    s, j = r.api("POST", "referral-rewards/claim", {"milestone": 5}); t.check("4 paying friends cannot claim yet", s == 422, (s, str(j)[:120]))
    friend(4)
    s, st = r.api("GET", "referral-rewards"); t.check("the fifth paying friend makes 5", st.get("count") == 5, st.get("count"))
    s, j = r.api("POST", "referral-rewards/claim", {"milestone": 10}); t.check("there is no 10-friend reward", s == 422, s)
    s, j = r.api("POST", "referral-rewards/claim", {"milestone": 5}); t.check("5 paying friends claim the reward", s in (200, 204), (s, str(j)[:160]))
    row = db.q("select exports_remaining from referral_rewards where owner_id=? and milestone=5", uid)
    t.check("one free resume download is added", row and row[0][0] == 1, row)
    p = db.q("select provider,access_days,status from purchases where owner_id=? and provider='referral_reward'", uid)
    t.check("one month of subscription is added", len(p) == 1 and p[0][1] == 30 and p[0][2] == "paid", p)
    s, a = r.api("GET", "allowance"); t.check("the customer now has plan access", a.get("paid") is True, str(a)[:120])
    s, j = r.api("POST", "referral-rewards/claim", {"milestone": 5}); t.check("the reward can only be claimed once", s == 409, s)
    t.check("phone verification is no longer needed", r.api("POST", "referral-rewards/send", {"phone": "9876543210"})[0] in (404, 405))
