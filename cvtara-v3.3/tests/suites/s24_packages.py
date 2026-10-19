"""3.16 packages: Quick Download bound to one resume, upgrade credit, add-on price with a plan, the 7-day refund for unused
orders (once per customer), and "Give ₹50, get ₹50" wallet referrals with the 7-day wait, monthly cap and 5-friend reward."""
import uuid
NAME = "Packages, refunds and wallet referrals"


def resume(title):
    return {"version": "1", "title": title, "template": "suite-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Ann"}, "summary": "x",
            "order": ["summary", "experience"], "experience": [], "education": [], "projects": [], "certifications": []}


def letter():
    return {"version": "1", "title": "Letter", "template": "letter-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Ann"}, "company": "Co", "role": "Recruiter",
            "opening": "Dear team.", "evidence": "I hired 200 people.", "closing": "Thanks.", "experience": [], "education": [], "projects": [], "certifications": []}


def run(ctx, t):
    if not ctx.preview: return
    db = ctx.db()
    uid = lambda e: db.q("select id from users where email=?", e)[0][0]

    def buy(c, plan, **extra):
        s, j = c.api("POST", "checkout/start", {"plan": plan, **extra})
        if s != 200: return s, j
        return c.api("POST", "checkout/verify", {"provider": "demo", "orderId": j["orderId"]})[0], j

    def download(c, kind, doc, fmt="print"):
        s, h, b = c.raw("POST", f"/export/{kind}/{doc}/{fmt}", {"clean": True}, {"Accept": "application/json", "X-CSRF-TOKEN": c.csrf or ""})
        return s, b[:240]

    # Quick Download: one resume, edits and re-downloads of it are free, a second resume or a cover letter is refused
    q = ctx.client(); q.register("pk-quick@test.local")
    q.api("POST", "resumes", {"data": resume("Primary")})
    t.check("buy Quick Download for Rs 99", buy(q, "quick")[0] == 200)
    s, b = q.api("POST", "resumes", {"data": resume("Second")}); rid = b.get("id")
    t.check("the first clean download of a resume uses the one credit", download(q, "resume", rid)[0] == 200 and q.api("GET", "allowance")[1]["credits"]["resumes"]["used"] == 1)
    s, g = q.api("GET", f"resumes/{rid}"); d = g["resume"]["data"]; d["summary"] = "edited"
    q.api("POST", "resumes", {"data": d, "id": rid, "revision": g["resume"]["revision"]})
    t.check("an edited version of the same resume downloads free", download(q, "resume", rid)[0] == 200 and q.api("GET", "allowance")[1]["credits"]["resumes"]["used"] == 1)
    s, b = q.api("POST", "resumes", {"data": resume("Third")}); s, m = download(q, "resume", b.get("id"))
    t.check("a different resume is refused with the Quick Download message", s == 422 and b"Quick Download covers one resume" in m, (s, m))
    s, lj = q.api("POST", "letters", {"data": letter()}); s, m = download(q, "letter", lj.get("id"))
    t.check("cover letters are not part of Quick Download", s == 422 and b"does not include cover letters" in m, (s, m))
    s, v = q.api("POST", f"resumes/{rid}/variant", {"company": "Co", "role": "Recruiter", "jobDescription": "Need recruiter"})
    t.check("tailored versions need Job Hunt or Pro", s == 422 and "Job Hunt" in str(v), (s, str(v)[:160]))
    s, pj = q.api("GET", "purchases"); row = [p for p in pj["purchases"] if p["plan"] == "quick"][0]
    t.check("a used Quick Download is not refundable", row["refundable"] is False)
    s, j = q.api("POST", "checkout/quote", {"plan": "job-hunt"})
    t.check("Job Hunt within 7 days of Quick Download costs Rs 200 (Rs 99 credited)", s == 200 and j["upgradeCreditPaise"] == 9900 and j["totalPaise"] == 20000, j)
    t.check("the upgrade is bought", buy(q, "job-hunt")[0] == 200)
    s, j = q.api("POST", "checkout/quote", {"plan": "pro"})
    t.check("the upgrade credit is used once", s == 200 and j["upgradeCreditPaise"] == 0 and j["totalPaise"] == 59900, j)
    s, j = q.api("POST", "checkout/quote", {"plan": "quick"})
    t.check("Quick Download cannot be bought on top of Job Hunt", s == 422, s)

    # add-on price with a plan (services switched on by the owner)
    o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
    s, pl = o.api("GET", "admin/plans")
    s, j = o.api("POST", "admin/plans", {"plans": {"expert-review": {**pl["plans"]["expert-review"], "sale": True}}})
    t.check("the owner puts Tara Expert Review on sale", s == 200 and j["plans"]["expert-review"]["sale"] is True, (s, str(j)[:200]))
    s, j = q.api("POST", "checkout/quote", {"plan": "expert-review"})
    t.check("Expert Review is Rs 399 while Job Hunt is active", s == 200 and j["totalPaise"] == 39900 and j["addonDiscountPaise"] == 10000, j)
    n = ctx.client(); n.register("pk-noplan@test.local")
    s, j = n.api("POST", "checkout/quote", {"plan": "expert-review"})
    t.check("Expert Review is Rs 499 without a plan", s == 200 and j["totalPaise"] == 49900, j)
    t.check("buying Expert Review opens a reviewer order", buy(q, "expert-review")[0] == 200 and db.q("select status from service_orders where owner_id=?", uid("pk-quick@test.local")) == [("not_started",)])
    s, so = o.api("GET", "admin/services"); oid = so["orders"][0]["id"]
    t.check("the owner moves it to in review", o.api("POST", f"admin/services/{oid}", {"status": "in_review"})[0] == 200)
    s, pj = q.api("GET", "purchases"); row = [p for p in pj["purchases"] if p["plan"] == "expert-review"][0]
    t.check("a service that is in review is not refundable", row["refundable"] is False)
    t.check("delivering needs a note", o.api("POST", f"admin/services/{oid}", {"status": "delivered"})[0] == 422)
    t.check("the owner marks it delivered", o.api("POST", f"admin/services/{oid}", {"status": "delivered", "note": "Review sent by email today"})[0] == 200 and q.api("GET", "purchases")[1]["services"][0]["status"] == "delivered")

    # 7-day refund
    r = ctx.client(); r.register("pk-refund@test.local")
    t.check("buy Job Hunt", buy(r, "job-hunt")[0] == 200)
    s, pj = r.api("GET", "purchases"); pid = pj["purchases"][0]["id"]
    t.check("an unused order inside 7 days shows Request refund", pj["purchases"][0]["refundable"] is True and pj["refundDays"] == 7, pj["purchases"][0])
    s, j = r.api("POST", "refunds", {"purchaseId": pid, "reason": "Changed my mind"})
    t.check("the customer requests a refund", s == 200 and j["refund"]["status"] == "requested", (s, j))
    t.check("a second request for the same order is refused", r.api("POST", "refunds", {"purchaseId": pid})[0] == 422)
    t.check("the customer gets a confirmation email", db.q("select count(*) from mail_outbox where owner_id=? and \"trigger\"='refund_requested'", uid("pk-refund@test.local"))[0][0] == 1)
    s, rq = o.api("GET", "admin/refunds"); rid = [x for x in rq["requests"] if x["purchase_id"] == pid][0]["id"]
    s, j = o.api("POST", f"admin/refunds/{rid}/approve", {})
    t.check("the owner approves and the order is refunded", s == 200 and db.q("select status, refunded_paise from purchases where id=?", pid) == [("refunded", 29900)], (s, str(j)[:200]))
    t.check("the request is processed and the refund email is queued", db.q("select status from refund_requests where id=?", rid) == [("processed",)] and db.q("select count(*) from mail_outbox where owner_id=? and \"trigger\"='refund_processed'", uid("pk-refund@test.local"))[0][0] == 1)
    t.check("access ends with the refund", r.api("GET", "allowance")[1]["paid"] is False)
    buy(r, "job-hunt"); s, pj = r.api("GET", "purchases")
    new = [p for p in pj["purchases"] if p["status"] == "paid"][0]
    t.check("refunds are once per customer", new["refundable"] is False and r.api("POST", "refunds", {"purchaseId": new["id"]})[0] == 422)
    old = ctx.client(); old.register("pk-old@test.local"); buy(old, "job-hunt")
    db.q("update purchases set purchased_at=datetime('now','-8 days') where owner_id=?", uid("pk-old@test.local"))
    t.check("after 7 days the refund button is gone", old.api("GET", "purchases")[1]["purchases"][0]["refundable"] is False)
    used = ctx.client(); used.register("pk-used@test.local"); buy(used, "job-hunt")
    s, b = used.api("POST", "resumes", {"data": resume("Primary")}); download(used, "resume", b["id"])
    s, pj = used.api("GET", "purchases"); upid = pj["purchases"][0]["id"]; s, j = used.api("POST", "refunds", {"purchaseId": upid})
    t.check("an order with a clean download is not refundable", s == 422 and "already been used" in str(j), (s, j))

    # Give Rs 50, get Rs 50
    boss = ctx.client(); boss.register("pk-boss@test.local"); bid = uid("pk-boss@test.local")
    code = db.q("select referral_code from users where id=?", bid)[0][0]
    f = ctx.client(); f.register("pk-friend0@test.local", ref=code)
    s, j = f.api("POST", "checkout/quote", {"plan": "quick"})
    t.check("a referred friend gets Rs 50 off the first purchase", s == 200 and j["referralDiscountPaise"] == 5000 and j["totalPaise"] == 4900, j)
    s, j = boss.api("POST", "checkout/quote", {"plan": "quick"})
    t.check("someone who was not referred pays full price", s == 200 and j["referralDiscountPaise"] == 0, j)
    selfc = ctx.client(); selfc.register("pk-boss+2@test.local", ref=code)
    s, j = selfc.api("POST", "checkout/quote", {"plan": "quick"})
    t.check("an account with the referrer's own email (plus-address) gets no discount", j.get("referralDiscountPaise") == 0, j)

    def friend_paid(n, days_ago):
        c = ctx.client(); em = f"pk-friend{n}@test.local"
        if n: c.register(em, ref=code)
        fid = uid(em)
        db.q("insert into purchases(id,owner_id,plan,amount_paise,referral_discount_paise,status,provider,payment_reference,is_live,limits_json,access_days,purchased_at,access_until,created_at,updated_at) values(?,?,?,?,?,?,?,?,?,?,?,datetime('now',?),datetime('now','+30 days'),datetime('now'),datetime('now'))",
             str(uuid.uuid4()), fid, "job-hunt", 24900, 5000, "paid", "cashfree", "pk-pay-" + str(n), 1, "{}", 30, f"-{days_ago} days")
    friend_paid(0, 2)
    s, w = boss.api("GET", "wallet")
    t.check("a friend's purchase inside the 7-day window is pending, with an unlock date", s == 200 and w["balancePaise"] == 0 and len(w["pending"]) == 1 and w["pending"][0]["unlockAt"], w)
    db.q("update purchases set purchased_at=datetime('now','-8 days') where payment_reference='pk-pay-0'")
    s, w = boss.api("GET", "wallet")
    t.check("after 7 days without a refund the referrer earns Rs 50", w["balancePaise"] == 5000 and w["earnedPaise"] == 5000 and w["milestone"]["count"] == 1, w)
    s, w = boss.api("GET", "wallet")
    t.check("looking again does not pay twice", w["balancePaise"] == 5000)
    s, j = boss.api("POST", "checkout/quote", {"plan": "job-hunt"})
    t.check("wallet credit is applied at checkout", j["walletPaise"] == 5000 and j["totalPaise"] == 24900, j)
    s, j = boss.api("POST", "checkout/quote", {"plan": "job-hunt", "useWallet": False})
    t.check("the customer can keep the wallet for later", j["walletPaise"] == 0 and j["totalPaise"] == 29900, j)
    t.check("paying with wallet credit spends it", buy(boss, "job-hunt")[0] == 200 and boss.api("GET", "wallet")[1]["balancePaise"] == 0)
    # a lower monthly cap (Rs 200) so the test needs only 5 friends; the default is Rs 1,000
    db.q("insert or replace into site_settings(key,value_json,revision,created_at,updated_at) values('referrals','{\"monthlyCapPaise\":20000}',1,datetime('now'),datetime('now'))")
    for n in range(1, 5): friend_paid(n, 9)
    s, w = boss.api("GET", "wallet")
    t.check("5 qualified friends reach the reward", w["milestone"]["count"] == 5, w["milestone"])
    t.check("no more than the monthly cap is earned, but every friend still counts", w["earnedPaise"] == 20000, w["earnedPaise"])
    s, st = boss.api("GET", "referral-rewards"); t.check("the 5-friend count agrees", st.get("count") == 5, st.get("count"))
    db.q("update purchases set access_until=datetime('now','-1 day') where owner_id=?", bid)
    s, j = boss.api("POST", "referral-rewards/claim", {"milestone": 5})
    t.check("5 qualified friends unlock one free Job Hunt month", s in (200, 204) and db.q("select plan, access_days from purchases where owner_id=? and provider='referral_reward'", bid) == [("job-hunt", 30)], (s, str(j)[:160]))
    s, w = boss.api("GET", "wallet")
    db.q("insert into wallet_transactions(owner_id,kind,amount_paise,reference,earned_at,expires_at,created_at,updated_at) values(?, 'adjust', 7000, 'pk-old-credit', datetime('now','-100 days'), datetime('now','-10 days'), datetime('now','-100 days'), datetime('now'))", bid)
    t.check("credit older than 90 days has expired", boss.api("GET", "wallet")[1]["balancePaise"] == w["balancePaise"])
    s, rf = boss.api("GET", "referrals"); t.check("the referral page data carries the wallet", s == 200 and rf.get("mode") == "wallet" and rf["wallet"]["balancePaise"] == w["balancePaise"])
    db.q("delete from site_settings where key in ('plans', 'referrals')")  # later suites expect the defaults
