"""PartnerHub part 2 (3.54.1, PartnerHub V2 §4-§19): agreement versions accepted in the portal before a campaign starts,
campaign dates and seats, student invite lists that create no accounts, first-valid-link attribution, V7 subtypes,
reversal entries, payout requests (₹5,000 minimum), monthly ranking (own place only), rewards, owner view as partner."""
import json
import time
NAME = "PartnerHub part 2: agreements, campaigns, seats, invites, payouts, ranking, rewards"


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    inst = Instance(ctx.app, ctx.public, preview=True).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        uid = lambda e: db.q("select id from users where email=?", e)[0][0]
        acq = lambda e: tuple(db.q("select acq_class, acq_subtype, acq_partner_id from users where email=?", e)[0])
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        pid = o.api("POST", "admin/partners/save", {"name": "Delta Coaching", "kind": "coaching"})[1]["id"]
        o.api("POST", f"admin/partners/{pid}/links", {"code": "delta-main", "campaign": "batch-1"})
        t.check("a campaign takes no sign-ups before an agreement is accepted", Client(inst.base).raw("GET", "/p/delta-main")[0] == 404)

        # Portal members and the agreement.
        mgr = Client(inst.base); mgr.register("dc-manager@test.local")
        view = Client(inst.base); view.register("dc-viewer@test.local")
        o.api("POST", f"admin/partners/{pid}/members", {"email": "dc-manager@test.local", "role": "manager"})
        o.api("POST", f"admin/partners/{pid}/members", {"email": "dc-viewer@test.local", "role": "viewer"})
        s, d = o.api("GET", f"admin/partners/{pid}")
        t.check("the owner gets a draft agreement filled from the partner's terms", s == 200 and "Delta Coaching" in d["draft"] and "30% of the net amount" in d["draft"] and "₹99 Quick Download earns no commission" in d["draft"], d.get("draft", "")[:200])
        s, j = o.api("POST", f"admin/partners/{pid}/agreements", {})
        t.check("the owner sends version 1", s == 200 and j["version"] == 1, (s, j))
        t.check("the manager is told", db.q("select count(*) from notifications where owner_id=? and kind='partner_agreement'", uid("dc-manager@test.local"))[0][0] == 1)
        s, p = mgr.api("GET", "partner")
        a = [x for x in p["agreements"] if x["status"] == "sent"][0]
        t.check("a viewer cannot accept", view.api("POST", f"partner/agreements/{a['id']}/accept", {"hash": a["body_hash"], "name": "Vik Viewer", "agree": 1})[0] == 403)
        t.check("acceptance needs the exact text that was shown", mgr.api("POST", f"partner/agreements/{a['id']}/accept", {"hash": "0" * 64, "name": "Meera Manager", "agree": 1})[0] == 409)
        s, p = mgr.api("POST", f"partner/agreements/{a['id']}/accept", {"hash": a["body_hash"], "name": "Meera Manager", "agree": 1})
        row = db.q("select status, accepted_name, accepted_ip, accept_method, body_hash from partner_agreements where id=?", a["id"])[0]
        t.check("the manager accepts; name, IP, method and text fingerprint are kept", s == 200 and row[0] == "accepted" and row[1] == "Meera Manager" and row[2] and row[3] == "portal_checkbox_typed_name" and row[4] == a["body_hash"], row)
        t.check("now the campaign takes sign-ups", Client(inst.base).raw("GET", "/p/delta-main")[0] == 302)
        o.api("POST", f"admin/partners/{pid}/agreements", {"body": d["draft"] + "\n\n10. Added clause for version two of this agreement."})
        t.check("a new version waits for fresh acceptance while the accepted one stays in force", [r for r in db.q("select version, status from partner_agreements where partner_id=? order by version", pid)] == [(1, "accepted"), (2, "sent")] and Client(inst.base).raw("GET", "/p/delta-main")[0] == 302)

        # Campaign dates and seats.
        lid = db.q("select id from partner_links where code='delta-main'")[0][0]
        s, _ = o.api("POST", f"admin/partners/links/{lid}/campaign", {"seats": 1, "target": 1, "days": 30})
        r = db.q("select seats, target_paid, starts_on, ends_on from partner_links where id=?", lid)[0]
        t.check("the owner sets seats, a target and a 30-day campaign", s == 200 and r[0] == 1 and r[1] == 1 and r[2] and r[3], r)
        st1 = Client(inst.base); st1.raw("GET", "/p/delta-main"); st1.register("dc-s1@test.local")
        t.check("a sign-up through the link is B2B coaching for the partner", acq("dc-s1@test.local") == ("b2b", "coaching", pid), acq("dc-s1@test.local"))
        t.check("when the seats are used, the link takes no more sign-ups", Client(inst.base).raw("GET", "/p/delta-main")[0] == 404)
        s, p = mgr.api("GET", "partner")
        seats = p["links"][0]["seats"]
        t.check("the portal shows seats used, signed up and invited", seats["allocated"] == 1 and seats["verified"] == 1 and seats["registered"] == 1 and not seats["open"], seats)
        o.api("POST", f"admin/partners/links/{lid}/campaign", {"seats": None})
        o.api("POST", f"admin/partners/links/{lid}/campaign", {"action": "close"})
        t.check("closing the campaign stops the link", Client(inst.base).raw("GET", "/p/delta-main")[0] == 404)
        o.api("POST", f"admin/partners/links/{lid}/campaign", {"action": "resume", "extendDays": 10})
        t.check("extending and resuming opens it again", Client(inst.base).raw("GET", "/p/delta-main")[0] == 302)

        # Student invites.
        csv = "name,email\nAsha Rao,asha.inv@test.local\nAsha Rao,ASHA.inv@test.local\nRavi,dc-s1@test.local\njust a name\nKiran,kiran.inv@test.local"
        t.check("a viewer cannot upload students", view.api("POST", "partner/invites", {"linkId": lid, "text": csv, "authority": 1})[0] == 403)
        t.check("the manager must confirm authority", mgr.api("POST", "partner/invites", {"linkId": lid, "text": csv})[0] == 422)
        s, j = mgr.api("POST", "partner/invites", {"linkId": lid, "text": csv, "authority": 1})
        t.check("rows are checked and de-duplicated; existing accounts are not invited", s == 200 and j["added"] == 3 and j["duplicates"] == 1 and j["hasAccount"] == 1 and j["unreadable"] == 1 and sum(1 for i in j["invites"] if i["link"]) == 2, (s, j))
        t.check("no account is created from the list", db.q("select count(*) from users where email in ('asha.inv@test.local','kiran.inv@test.local')")[0][0] == 0)
        link = [i["link"] for i in j["invites"] if i["email"] == "asha.inv@test.local"][0]
        st2 = Client(inst.base); st2.raw("GET", link.replace(inst.base, "") if link.startswith(inst.base) else "/" + link.split("/", 3)[3]); st2.register("asha.inv@test.local")
        t.check("a student who signs up with their personal link is counted", db.q("select status, user_id from partner_invites where email='asha.inv@test.local'")[0] == ("registered", uid("asha.inv@test.local")))
        t.check("uploading the same list again adds nobody", mgr.api("POST", "partner/invites", {"linkId": lid, "text": csv, "authority": 1})[1]["added"] == 0)

        # First valid link wins; V7 subtypes.
        ref = db.q("select referral_code from users where email='dc-viewer@test.local'")[0][0]
        v1 = Client(inst.base); v1.raw("GET", "/r/" + ref); time.sleep(1.1); v1.raw("GET", "/p/delta-main"); v1.register("dc-first-ref@test.local")
        t.check("referral link first, partner link second: B2C referral (first valid link wins)", acq("dc-first-ref@test.local")[:2] == ("b2c", "referral"), acq("dc-first-ref@test.local"))
        time.sleep(1.1)
        v2 = Client(inst.base); v2.raw("GET", "/p/delta-main"); time.sleep(1.1); v2.raw("GET", "/r/" + ref); v2.register("dc-first-partner@test.local")
        r2 = db.q("select acq_class, acq_subtype, referred_by from users where email='dc-first-partner@test.local'")[0]
        t.check("partner link first: B2B, and no customer referral reward as well", r2[:2] == ("b2b", "coaching") and r2[2] is None, r2)
        ipid = o.api("POST", "admin/partners/save", {"name": "Career Guru", "kind": "influencer", "agreementRef": "Signed 1 Nov"})[1]["id"]
        o.api("POST", f"admin/partners/{ipid}/links", {"code": "guru-yt"})
        spid = o.api("POST", "admin/partners/save", {"name": "CVTara sales desk", "kind": "sales", "agreementRef": "Internal"})[1]["id"]
        o.api("POST", f"admin/partners/{spid}/links", {"code": "sales-desk"})
        g = Client(inst.base); g.raw("GET", "/p/guru-yt"); g.register("dc-guru@test.local")
        sd = Client(inst.base); sd.raw("GET", "/p/sales-desk"); sd.register("dc-sales@test.local")
        t.check("an influencer link is B2B Influencer; a CVTara sales link is Direct B2B", acq("dc-guru@test.local")[:2] == ("b2b", "influencer") and acq("dc-sales@test.local")[:2] == ("b2b", "direct_b2b"), (acq("dc-guru@test.local"), acq("dc-sales@test.local")))

        # Reversal entries.
        sid = uid("dc-s1@test.local")
        db.q("insert into purchases(id,owner_id,plan,amount_paise,status,provider,is_live,limits_json,access_days,purchased_at,created_at,updated_at) values('dc-p1',?,'pro',59900,'paid','razorpay',1,'{}',90,datetime('now'),datetime('now'),datetime('now'))", sid)
        inst.artisan("tinker", "--execute=App\\Services\\Partners::credit(DB::table('purchases')->where('id','dc-p1')->first()); $p=DB::table('purchases')->where('id','dc-p1')->first(); App\\Services\\Referrals::reverse($p, 59900);")
        c = db.q("select id, amount_paise, status from partner_commissions where purchase_id='dc-p1'")[0]
        rv = db.q("select amount_paise, reason from partner_commission_reversals where commission_id=?", c[0])
        t.check("a refund adds a reversal entry; the original amount is kept", c[1] == 17970 and c[2] == "void" and rv == [(-17970, "Order refunded or disputed")], (c, rv))

        # Payouts.
        t.check("a payout needs ₹5,000 ready to pay", mgr.api("POST", "partner/payout", {})[0] == 422)
        db.q("insert into partner_commissions(partner_id,purchase_id,buyer_id,plan,kind,base_paise,rate_bps,amount_paise,status,available_at,created_at,updated_at) values(?,?,?,'pro','plan',2000000,3000,600000,'available',datetime('now'),datetime('now'),datetime('now'))", pid, "dc-bulk", sid)
        t.check("a viewer cannot ask for a payout", view.api("POST", "partner/payout", {})[0] == 403)
        s, j = mgr.api("POST", "partner/payout", {})
        t.check("the manager asks for the available amount; the owner is told", s == 200 and j["amountPaise"] == 600000 and db.q("select count(*) from notifications where kind='partner_payout'")[0][0] == 1, (s, j))
        t.check("one request at a time", mgr.api("POST", "partner/payout", {})[0] == 409)
        s, j = o.api("GET", "admin/partners/payouts?status=requested")
        po = j["payouts"][0]["id"]
        s, _ = o.api("POST", f"admin/partners/payouts/{po}", {"decision": "paid", "reference": "UTR-77"})
        t.check("the owner pays it with a reference; the commissions become paid", s == 200 and db.q("select status, payout_ref from partner_commissions where purchase_id='dc-bulk'")[0] == ("paid", "UTR-77") and db.q("select status from partner_payouts where id=?", po)[0][0] == "paid")

        # Ranking.
        month = db.q("select strftime('%Y-%m','now')")[0][0]
        s, j = o.api("POST", "admin/partners/rank", {"month": month})
        t.check("partners are ranked for a month", s == 200 and j["ranked"] >= 3, (s, j))
        s, j = o.api("GET", "admin/partners/rankings?month=" + month)
        t.check("the owner sees the full ranking", s == 200 and j["rankings"][0]["name"] == "Delta Coaching" and j["rankings"][0]["rank"] == 1, j.get("rankings"))
        s, p = mgr.api("GET", "partner")
        txt = json.dumps(p)
        t.check("a partner sees only its own place, never other partners' names", p["rankings"][0]["rank"] == 1 and p["rankings"][0]["of"] >= 3 and "Career Guru" not in txt and "sales desk" not in txt)
        t.check("and never a student's email", "dc-s1@test.local" not in txt and "asha.inv" not in txt)

        # Rewards.
        s, j = o.api("POST", "admin/partners/rewards/save", {"partnerId": pid, "title": "Top partner hamper", "costInr": 2500})
        rid = j["id"]
        t.check("the owner adds a reward and the manager is asked for an address", s == 200 and db.q("select count(*) from notifications where kind='partner_reward' and owner_id=?", uid("dc-manager@test.local"))[0][0] == 1)
        s, p = mgr.api("POST", f"partner/rewards/{rid}/address", {"address": "Delta Coaching, 12 MG Road, Pune 411001"})
        t.check("the manager confirms the address", s == 200 and db.q("select status from partner_rewards where id=?", rid)[0][0] == "address")
        o.api("POST", "admin/partners/rewards/save", {"id": rid, "status": "dispatched", "courier": "Blue Dart", "tracking": "BD123"})
        t.check("dispatch is recorded and the address can no longer change", mgr.api("POST", f"partner/rewards/{rid}/address", {"address": "Somewhere else entirely, Pune 411002"})[0] == 409)

        # Owner view as partner; outsiders.
        s, j = o.api("GET", f"admin/partners/{pid}/portal")
        t.check("the owner can view the partner portal read-only, and it is logged", s == 200 and j["partner"]["role"] == "viewer" and db.q("select count(*) from activity_events where event='partner_portal_viewed'")[0][0] >= 1, s)
        out = Client(inst.base); out.register("dc-outsider@test.local")
        t.check("someone who is not a member gets nothing", out.api("POST", "partner/payout", {})[0] == 404 and out.api("GET", "partner/invites")[0] == 404)
    finally:
        inst.stop()
