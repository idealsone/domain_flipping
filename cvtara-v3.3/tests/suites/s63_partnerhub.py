"""PartnerHub (3.54.0, V7 §38-39): B2B partners, campaign links, sign-ups locked to the partner, a separate commission
ledger (30% plans, 40% add-ons, nothing on ₹99, pending through the refund window, voided by a refund, paid by the
owner), a partner portal with numbers only, and public partner applications."""
import json, os
NAME = "PartnerHub: B2B partners, links, commission ledger, portal"


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    inst = Instance(ctx.app, ctx.public, preview=True).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        uid = lambda e: db.q("select id from users where email=?", e)[0][0]
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        s, j = o.api("POST", "admin/partners/save", {"name": "ABC Engineering College", "kind": "college", "city": "Pune", "planPercent": 30, "addonPercent": 40, "agreementFrom": "2026-01-01", "agreementRef": "MOU-1"})
        t.check("the owner adds a partner with its agreement", s == 200 and j.get("id"), (s, j))
        pid = j["id"]
        s, j = o.api("POST", f"admin/partners/{pid}/links", {"code": "abc-college", "campaign": "placement-week"})
        t.check("and a campaign link", s == 200 and j["code"] == "abc-college", (s, j))
        t.check("a link name can be used once", o.api("POST", f"admin/partners/{pid}/links", {"code": "abc-college"})[0] == 422)

        # A student opens the link and signs up.
        st = Client(inst.base)
        s, h, _ = st.raw("GET", "/p/abc-college")
        t.check("the link opens sign-up and is not indexed", s == 302 and h.get("Location", "").endswith("/register") and "noindex" in h.get("X-Robots-Tag", ""), (s, h.get("Location")))
        st.raw("GET", "/p/abc-college")
        t.check("visits are counted once per visitor", db.q("select clicks from partner_links where code='abc-college'")[0][0] == 1)
        t.check("an unknown link is a 404", Client(inst.base).raw("GET", "/p/no-such-link")[0] == 404)
        st.register("ph-student@test.local")
        r = db.q("select acq_class, acq_subtype, acq_partner_id, acq_link, acq_campaign from users where email='ph-student@test.local'")[0]
        t.check("the sign-up is locked to the partner as B2B college with the link and campaign", tuple(r) == ("b2b", "college", pid, "abc-college", "placement-week"), r)
        st.raw("GET", "/r/" + db.q("select referral_code from users where email='ph-student@test.local'")[0][0])
        t.check("a later click changes nothing", db.q("select acq_partner_id from users where email='ph-student@test.local'")[0][0] == pid)

        # Commission ledger.
        sid = uid("ph-student@test.local")
        def buy(oid, plan, paise):
            db.q("insert into purchases(id,owner_id,plan,amount_paise,status,provider,is_live,limits_json,access_days,purchased_at,created_at,updated_at) values(?,?,?,?,'paid','razorpay',1,'{}',30,datetime('now'),datetime('now'),datetime('now'))", oid, sid, plan, paise)
            inst.artisan("tinker", f"--execute=App\\Services\\Partners::credit(DB::table('purchases')->where('id','{oid}')->first());")
        buy("ph-jh", "job-hunt", 29900); buy("ph-q", "quick", 9900); buy("ph-er", "expert-review", 49900)
        rows = {r[0]: r[1:] for r in db.q("select purchase_id, kind, rate_bps, amount_paise, status from partner_commissions")}
        t.check("30% of a plan, pending through the refund window", rows.get("ph-jh") == ("plan", 3000, 8970, "pending"), rows)
        t.check("40% of an add-on", rows.get("ph-er") == ("addon", 4000, 19960, "pending"), rows)
        t.check("the ₹99 Quick Download earns nothing", "ph-q" not in rows)
        inst.artisan("tinker", "--execute=App\\Services\\Partners::credit(DB::table('purchases')->where('id','ph-jh')->first());")
        t.check("an order is counted once", db.q("select count(*) from partner_commissions where purchase_id='ph-jh'")[0][0] == 1)
        inst.artisan("cvtara:cron")
        t.check("nothing is ready to pay inside the refund window", db.q("select count(*) from partner_commissions where status='available'")[0][0] == 0)
        db.q("update partner_commissions set available_at=datetime('now','-1 hour')")
        inst.artisan("tinker", "--execute=$p=DB::table('purchases')->where('id','ph-er')->first(); DB::table('purchases')->where('id','ph-er')->update(['refunded_paise'=>49900,'status'=>'refunded']); App\\Services\\Referrals::reverse($p, 49900);")
        inst.artisan("cvtara:cron")
        st_ = dict(db.q("select purchase_id, status from partner_commissions"))
        t.check("after the window it is ready to pay; a refunded order's commission is cancelled", st_ == {"ph-jh": "available", "ph-er": "void"}, st_)
        cid = db.q("select id from partner_commissions where purchase_id='ph-jh'")[0][0]
        s, j = o.api("POST", "admin/partners/commissions/pay", {"ids": [cid], "reference": "UTR123456"})
        t.check("the owner marks it paid with a reference", s == 200 and j["paid"] == 1 and db.q("select status, payout_ref from partner_commissions where id=?", cid)[0] == ("paid", "UTR123456"), (s, j))
        s, j = o.api("GET", "admin/partners/commissions?status=paid")
        t.check("the ledger lists and filters commissions", s == 200 and len(j["commissions"]) == 1 and j["commissions"][0]["partner"] == "ABC Engineering College")

        # Owner view of one partner.
        s, j = o.api("GET", f"admin/partners/{pid}")
        t.check("the partner page shows sign-ups, buyers, revenue, commission and link results", s == 200 and j["signups"] == 1 and j["buyers"] == 1 and j["commission"]["paidPaise"] == 8970 and j["links"][0]["signups"] == 1 and j["links"][0]["clicks"] == 1, {k: j.get(k) for k in ("signups", "buyers", "commission", "links")})
        s, j = o.api("GET", "admin/users?acq=b2b&partner=" + str(pid))
        t.check("the Users list filters by partner", [u["email"] for u in j["users"]] == ["ph-student@test.local"])

        # Partner portal.
        tpo = Client(inst.base); tpo.register("ph-tpo@test.local")
        _s, _j = tpo.api("GET", "partner")
        t.check("someone who is not a partner member has no portal", _s == 200 and _j.get("partner") is None)
        s, j = o.api("POST", f"admin/partners/{pid}/members", {"email": "ph-tpo@test.local", "role": "viewer"})
        t.check("the owner adds a portal member", s == 200, (s, j))
        s, j = tpo.api("GET", "partner")
        txt = json.dumps(j)
        t.check("the member sees their organisation's numbers", s == 200 and j["partner"]["name"] == "ABC Engineering College" and j["signups"] == 1 and j["commission"]["paidPaise"] == 8970, (s, txt[:300]))
        t.check("and never a student's details", "ph-student" not in txt and "@test.local" not in txt)
        s, j = o.api("POST", "admin/partners/save", {"name": "Other Coaching", "kind": "coaching"})
        t.check("a member cannot be added to a second partner", o.api("POST", f"admin/partners/{j['id']}/members", {"email": "ph-tpo@test.local", "role": "viewer"})[0] == 422)
        s, _, ws = tpo.page("/workspace.html")
        t.check("the workspace loads the partner portal script", "/partner-portal.js" in ws)

        # Agreement end and pause stop the link.
        o.api("POST", "admin/partners/save", {"id": pid, "name": "ABC Engineering College", "kind": "college", "agreementFrom": "2026-01-01", "agreementTo": "2026-01-31"})
        t.check("a link stops working after the agreement ends", Client(inst.base).raw("GET", "/p/abc-college")[0] == 404)

        # Applications.
        pub = Client(inst.base); pub.page("/")
        s, j = pub.api("POST", "partners/apply", {"organisation": "XYZ Institute", "kind": "coaching", "name": "Ravi", "email": "ravi@xyz.test", "city": "Nagpur", "message": "We train 400 students a year."})
        t.check("an organisation can apply without an account", s == 200 and j.get("id"), (s, j))
        t.check("a bot filling the hidden field is refused", pub.api("POST", "partners/apply", {"organisation": "Spam", "kind": "other", "name": "S", "email": "s@spam.test", "website": "x"})[0] == 422)
        t.check("the owner is told", db.q("select count(*) from notifications where kind='partner_application'")[0][0] == 1)
        aid = j["id"]
        s, j = o.api("POST", f"admin/partners/applications/{aid}", {"decision": "approved"})
        t.check("approving adds the partner, paused until the agreement and link are set", s == 200 and db.q("select status, kind from partners where id=?", j["partnerId"])[0] == ("paused", "coaching"), (s, j))
        t.check("an application is decided once", o.api("POST", f"admin/partners/applications/{aid}", {"decision": "declined"})[0] == 409)

        # Customers cannot reach the owner side; account deletion removes membership.
        t.check("customers cannot open the partner console", tpo.api("GET", "admin/partners")[0] in (401, 403, 404))
        inst.artisan("tinker", f"--execute=App\\Services\\AccountEraser::erase({uid('ph-tpo@test.local')});")
        t.check("deleting an account removes its portal membership", db.q("select count(*) from partner_members")[0][0] == 0)
        js = open(os.path.join(ctx.app, "resources", "console", "partners.js"), encoding="utf8").read()
        t.check("the console module is registered", "K.register('partners'" in js)
    finally:
        inst.stop()
