"""V7 WS-02 (3.53.0): where an account came from is decided once at sign-up (B2C/B2B and a subtype, rule version, time)
and never changed by later clicks; the Users list filters and exports by it; accounts made before 3.53 are filled in."""
import os
NAME = "V7 WS-02: signup attribution locked at sign-up"


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    inst = Instance(ctx.app, ctx.public, preview=True).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        acq = lambda e: db.q("select acq_class, acq_subtype, acq_rule_version, acq_locked_at, acq_partner_id from users where email=?", e)[0]
        a = Client(inst.base); a.register("acq-a@test.local")
        r = acq("acq-a@test.local")
        t.check("a plain sign-up is B2C direct, with the rule version and time", r[0] == "b2c" and r[1] == "direct" and r[2] == "v7-1" and r[3], r)
        code = db.q("select referral_code from users where email='acq-a@test.local'")[0][0]
        b = Client(inst.base); b.register("acq-b@test.local", ref=code)
        t.check("a sign-up through a customer's link is B2C referral", acq("acq-b@test.local")[:2] == ("b2c", "referral"), acq("acq-b@test.local"))
        # An approved creator's link counts as an influencer.
        uid_a = db.q("select id from users where email='acq-a@test.local'")[0][0]
        inst.artisan("tinker", f"--execute=DB::table('creator_applications')->insert(['owner_id'=>{uid_a},'social_links_json'=>'[]','audience'=>'x','status'=>'approved','created_at'=>now(),'updated_at'=>now()]);")
        c = Client(inst.base); c.register("acq-c@test.local", ref=code)
        t.check("a sign-up through an approved creator's link is B2C influencer", acq("acq-c@test.local")[:2] == ("b2c", "influencer"), acq("acq-c@test.local"))
        # Locked: a later click and a second call change nothing.
        before = acq("acq-a@test.local")
        a.raw("GET", "/r/" + code)
        inst.artisan("tinker", f"--execute=App\\Services\\Attribution::lock(App\\Models\\User::find({uid_a}));")
        t.check("later clicks and repeated calls never change the decision", acq("acq-a@test.local") == before)
        # Backfill: an account with no decision (made before 3.53) gets one from what is known.
        uid_b = db.q("select id from users where email='acq-b@test.local'")[0][0]
        import sqlite3
        con = sqlite3.connect(inst.db); con.execute("update users set acq_class=null, acq_subtype=null, acq_rule_version=null, acq_locked_at=null where id in (?, ?)", (uid_a, uid_b)); con.commit(); con.close()
        mig = os.path.join(ctx.app, "database", "migrations", "2026_11_19_000001_signup_attribution.php")
        inst.artisan("tinker", "--execute=(require '" + mig + "')->up();")
        ra, rb = acq("acq-a@test.local"), acq("acq-b@test.local")
        t.check("older accounts are filled in from who referred them (here a creator, so influencer), marked as backfilled", ra[:3] == ("b2c", "direct", "backfill-1") and rb[:3] == ("b2c", "influencer", "backfill-1"), (ra, rb))

        # Users list.
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        s, j = o.api("GET", "admin/users?sub=influencer")
        t.check("the Users list filters by source", s == 200 and sorted(u["email"] for u in j["users"]) == ["acq-b@test.local", "acq-c@test.local"], (s, [u.get("email") for u in j.get("users", [])]))
        s, j = o.api("GET", "admin/users?acq=b2c&q=acq-")
        t.check("and by B2C / B2B, with a readable label on each row", s == 200 and len(j["users"]) == 3 and all(u["acquisition"].startswith("B2C · ") for u in j["users"]), [u.get("acquisition") for u in j.get("users", [])])
        t.check("no account is B2B without a partner link", o.api("GET", "admin/users?acq=b2b")[1]["users"] == [])
        s, h, body = o.raw("GET", "/api/admin/users?sub=influencer&export=csv")
        txt = body.decode("utf8", "ignore")
        t.check("the Users export carries the source", s == 200 and "B2C · Influencer" in txt and "acq-c@test.local" in txt, (s, txt[:300]))
        js = open(os.path.join(ctx.app, "resources", "console", "people.js"), encoding="utf8").read()
        t.check("the console shows the Came as column and filters", "key: 'acq'" in js and "key: 'sub'" in js and "label: 'Came as'" in js)
    finally:
        inst.stop()
