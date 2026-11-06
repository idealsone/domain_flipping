"""SEO Director and growth operator layer (3.35.0): AI-assistant and last-touch attribution, failed payments recorded from the
gateway, the customer event store and timeline, behaviour segments (also as campaign audiences), journeys that ship switched off
and stop when people act or buy, checkout recovery, referral activation, the link register with on-page verification, AI answer
checks, the crawler access audit with the training-crawler choice, the revenue scorecard and autonomy levels. Owner-only throughout."""
import hashlib, hmac, json, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
NAME = "SEO and growth operators"
SECRET = "rzp_secret_test_value"
WH = "whsec_test_value"

PAGES = {
    "/live": '<html><body><p>Good tools</p><a href="https://cvtara.com/ats-checker/" rel="nofollow">CVTara ATS checker</a></body></html>',
    "/mention": "<html><body><p>We tried CVTara and liked it.</p></body></html>",
    "/none": "<html><body><p>Nothing here.</p></body></html>",
}


def site():
    class H(BaseHTTPRequestHandler):
        def log_message(self, *a): pass

        def do_GET(self):
            b = PAGES.get(self.path)
            self.send_response(200 if b else 404); self.send_header("Content-Type", "text/html"); self.end_headers(); self.wfile.write((b or "gone").encode())
    s = ThreadingHTTPServer(("127.0.0.1", 0), H); threading.Thread(target=s.serve_forever, daemon=True).start()
    return s, "http://127.0.0.1:%d" % s.server_address[1]


def run(ctx, t):
    from lib.env import Instance
    from lib.fake_razorpay import FakeRazorpay
    from lib.http import Client
    from lib.db import Db
    rz = FakeRazorpay(); srv, ext = site()
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"RAZORPAY_API_BASE": rz.base, "PHP_CLI_SERVER_WORKERS": "4"}).start()
    try:
        db = Db(inst.db); o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        tinker = lambda code: inst.artisan("tinker", "--execute=" + code)
        last = lambda r: (r.stdout.strip().splitlines() or [""])[-1]
        tj = lambda code: json.loads(last(tinker("echo json_encode(" + code + ");")) or "null")
        tinker("App\\Services\\Settings::put('seo', array_merge(App\\Services\\Settings::get('seo'), ['siteUrl' => '', 'searchIndexing' => true])); App\\Services\\Settings::put('integrations', array_merge(App\\Services\\Settings::get('integrations'), ['analytics' => true, 'payments' => true]));")
        o.api("POST", "admin/providers", {"service": "payments", "values": {"provider": "razorpay", "mode": "test", "razorpayKeyId": "rzp_test_abc", "razorpayKeySecret": SECRET, "razorpayWebhookSecret": WH}})
        Client.verify_db = inst.db

        guest = Client(inst.base)
        t.check("operator pages are owner-only", all(guest.api("GET", p)[0] in (401, 403) for p in ("admin/growth/customers", "admin/growth/checkout", "admin/growth/customer?q=x", "admin/growth/links", "admin/growth/visibility", "admin/growth/scorecard")))
        t.check("the operators console script is owner-only", guest.raw("GET", "/console/operators.js")[0] == 404 and o.raw("GET", "/console/operators.js")[0] == 200)
        pub = open(ctx.public + "/owner-public.js", encoding="utf8").read().lower()
        t.check("the public script names no AI platform (the server works out the source)", "chatgpt" not in pub and "perplexity" not in pub and "claude" not in pub)

        # --- Attribution: AI assistants, referring site, last touch on the order
        v = Client(inst.base); v.page("/")
        v.api("POST", "traffic", {"consent": True, "path": "/ats-checker/", "source": "referral", "ref": "chatgpt.com"})
        v.api("POST", "traffic", {"consent": True, "path": "/blog.html", "source": "referral", "ref": "careers.example.edu", "campaign": "edu-nov"})
        pv = db.q("select source, ref_host from page_views where path='/ats-checker/' order by id desc limit 1")
        t.check("a visit from an AI assistant is recorded as 'ai' with its site", pv == [("ai", "chatgpt.com")], pv)
        v.register("ai-visitor@test.local")
        r = db.q("select signup_source, signup_landing, signup_ref_host, id from users where email='ai-visitor@test.local'")
        t.check("the sign-up keeps the AI assistant as its first touch", r and tuple(r[0][:3]) == ("ai", "/ats-checker/", "chatgpt.com"), r)
        uid = r[0][3]
        u2 = Client(inst.base); u2.page("/"); u2.api("POST", "traffic", {"consent": True, "path": "/", "source": "direct", "utm": "perplexity.ai"})
        t.check("utm_source from an assistant counts too", db.q("select source from page_views order by id desc limit 1") == [("ai",)])
        v.api("POST", "traffic", {"consent": True, "path": "/checkout.html", "source": "internal"})
        s, j = v.api("POST", "checkout/start", {"plan": "job-hunt"}); oid = j.get("orderId")
        po = db.q("select touch_source, touch_campaign, id from purchases where provider_order_id=?", oid)
        t.check("the order keeps the last outside arrival before checkout (last touch), apart from the first touch", s == 200 and po and tuple(po[0][:2]) == ("referral", "edu-nov"), (s, po))
        t.check("a signed-in pricing visit is tied to the account", db.q("select count(*) from page_views where owner_id=? and path='/checkout.html'", uid)[0][0] == 1)

        # --- Failed payment from the gateway, then a successful retry
        body = json.dumps({"event": "payment.failed", "payload": {"payment": {"entity": {"id": "pay_fail1", "order_id": oid, "status": "failed"}}}}).encode()
        sig = hmac.new(WH.encode(), body, hashlib.sha256).hexdigest()
        st = guest._post_raw("/webhooks/razorpay", body, {"Content-Type": "application/json", "Accept": "application/json", "X-Razorpay-Signature": sig, "X-Razorpay-Event-Id": "evt_fail_1"})[0]
        t.check("a signed payment.failed event marks the order failed (never paid)", st == 200 and db.q("select status from purchases where provider_order_id=?", oid) == [("failed",)], st)
        t.check("an unsigned event changes nothing", guest._post_raw("/webhooks/razorpay", body, {"Content-Type": "application/json", "Accept": "application/json", "X-Razorpay-Signature": "0" * 64, "X-Razorpay-Event-Id": "evt_fail_2"})[0] == 403)

        # --- Customer events
        out = tj("App\\Services\\CustomerEvents::sync()")
        ev = dict(db.q("select type, count(*) from customer_events where owner_id=? group by type", uid))
        t.check("customer events are copied from accounts, pricing visits and orders", all(ev.get(k) == 1 for k in ("registered", "verified", "pricing_view", "checkout_started", "payment_failed")), ev)
        n1 = db.q("select count(*) from customer_events")[0][0]; tj("App\\Services\\CustomerEvents::sync()")
        t.check("running the copy again adds nothing (idempotent)", db.q("select count(*) from customer_events")[0][0] == n1)
        t.check("events hold no resume text or personal details", all(len(m) < 60 for (m,) in db.q("select meta from customer_events")))

        # The customer retries and pays.
        pid = rz.pay(oid); good = {"razorpay_order_id": oid, "razorpay_payment_id": pid, "razorpay_signature": hmac.new(SECRET.encode(), f"{oid}|{pid}".encode(), hashlib.sha256).hexdigest()}
        t.check("a failed order can still be paid on retry", v.api("POST", "checkout/verify", good)[0] == 200 and db.q("select status from purchases where provider_order_id=?", oid) == [("paid",)])
        tj("App\\Services\\CustomerEvents::sync()")
        s, tl = o.api("GET", "admin/growth/customer?q=ai-visitor@test.local")
        t.check("the timeline shows the journey, first touch and money paid", s == 200 and tl["user"]["firstTouch"]["source"] == "ai" and tl["lifetimeNetPaise"] == 29900 and {"payment_paid", "payment_failed"} <= {e["type"] for e in tl["events"]}, (s, str(tl)[:300]))
        t.check("an unknown email is a clear 404", o.api("GET", "admin/growth/customer?q=nobody@test.local")[0] == 404)

        # --- Segments and journeys
        w = Client(inst.base); w.register("idle@test.local"); wid = db.q("select id from users where email='idle@test.local'")[0][0]
        db.q("update users set created_at=datetime('now','-2 days') where id=?", wid)
        db.q("update profiles set marketing_consent=1 where owner_id=?", wid)
        seg = {s["key"]: s for s in o.api("GET", "admin/growth/customers?days=30")[1]["segments"]}
        t.check("segments are worked out from behaviour: the idle sign-up is inactive and can be emailed", seg["inactive"]["people"] >= 1 and seg["inactive"]["reachable"] >= 1, seg["inactive"])
        t.check("the buyer is in 'first purchase', not in a free-user segment", tl and "first_paid" in [x["key"] for x in o.api("GET", "admin/growth/customer?q=ai-visitor@test.local")[1]["segments"]] and "ats_no_purchase" not in [x["key"] for x in o.api("GET", "admin/growth/customer?q=ai-visitor@test.local")[1]["segments"]])
        s, j = o.api("GET", "admin/campaigns/count?audience=seg_inactive")
        t.check("behaviour segments can be chosen as campaign audiences (opted-in only)", s == 200 and j["count"] >= 1, (s, j))
        out = tj("App\\Services\\Lifecycle::run()")
        t.check("journeys ship switched off: nothing is queued until the owner switches a template on", out["queued"]["activation_nudge"] == 0 and db.q("select count(*) from mail_outbox where `trigger`='activation_nudge'")[0][0] == 0, out)
        tinker("$l = App\\Services\\MailAutomation::upgradeTemplates((array) App\\Services\\Settings::get('emails', [])); foreach ($l as &$x) { if (($x['id'] ?? '') === 'activation-1') $x['enabled'] = true; } App\\Services\\Settings::put('emails', $l);")
        tinker("App\\Services\\Scorecard::saveLevel('growth', 2);")
        out = tj("App\\Services\\Lifecycle::run()")
        t.check("below level 3 the growth agent does not send journeys", out.get("skipped") is True, out)
        tinker("App\\Services\\Scorecard::saveLevel('growth', 3);")
        out = tj("App\\Services\\Lifecycle::run()")
        q = db.q("select recipient from mail_outbox where `trigger`='activation_nudge'")
        t.check("a switched-on journey reaches the opted-in inactive sign-up once", ("idle@test.local",) in q and ("ai-visitor@test.local",) not in q, q)
        tj("App\\Services\\Lifecycle::run()")
        t.check("it never goes twice to the same person", db.q("select count(*) from mail_outbox where `trigger`='activation_nudge' and recipient='idle@test.local'")[0][0] == 1)
        t.check("a waiting journey mail is still sent while the person has not acted", tj("App\\Services\\Lifecycle::stillEligible(DB::table('mail_outbox')->where('trigger','activation_nudge')->first())") is True)
        lim = json.dumps({"name": "Job Hunt"})
        db.q("insert into purchases(id,owner_id,plan,amount_paise,status,provider,payment_reference,is_live,limits_json,access_days,purchased_at,access_until,created_at,updated_at) values('bbbbbbbb-0000-0000-0000-000000000001',?,'job-hunt',29900,'paid','razorpay','pay_idle',0,?,30,datetime('now'),datetime('now','+30 days'),datetime('now'),datetime('now'))", wid, lim)
        t.check("once they buy, the waiting journey mail is dropped", tj("App\\Services\\Lifecycle::stillEligible(DB::table('mail_outbox')->where('trigger','activation_nudge')->first())") is False)
        tmpl = {x["id"]: x for x in o.api("GET", "admin/content/emails")[1]["data"]}
        t.check("the new journey and payment-help templates appear in Emails for the owner to read", all(k in tmpl for k in ("activation-1", "ats-1", "pricing-help", "payment-failed")) and tmpl["ats-1"]["enabled"] is False)
        t.check("journey mails are marketing mails (consent and unsubscribe apply)", tj("App\\Services\\EmailRenderer::isMarketing('activation_nudge') && App\\Services\\EmailRenderer::isMarketing('pricing_help')") is True)

        # --- Checkout recovery
        db.q("insert into purchases(id,owner_id,plan,amount_paise,status,provider,payment_reference,is_live,limits_json,access_days,purchased_at,access_until,created_at,updated_at) values('bbbbbbbb-0000-0000-0000-000000000002',?,'job-hunt',29900,'paid','razorpay','pay_idle2',0,?,30,datetime('now','+5 minutes'),datetime('now','+30 days'),datetime('now'),datetime('now'))", wid, lim)
        db.q("insert into purchases(id,owner_id,plan,amount_paise,status,provider,is_live,limits_json,access_days,created_at,updated_at) values('bbbbbbbb-0000-0000-0000-000000000003',?,'pro',59900,'created','razorpay',0,?,30,datetime('now','-3 hours'),datetime('now','-3 hours'))", wid, lim)
        s, co = o.api("GET", "admin/growth/checkout?days=30")
        k = co["kpis"]
        t.check("checkout recovery counts started, paid and unfinished orders from the ledger", s == 200 and k["started"] >= 4 and k["paidDirect"] >= 2 and k["abandoned"] >= 1, k)
        t.check("two payments for the same package within 30 minutes are flagged as a possible double payment", k["duplicates"] == 1 and co["duplicates"][0]["email"] == "idle@test.local", co["duplicates"])
        t.check("the checkout list pages and filters", len(co["rows"]) <= 20 and o.api("GET", "admin/growth/checkout?days=30&outcome=abandoned")[1]["total"] >= 1)
        s, h, b = o.raw("GET", "/api/admin/growth/checkout?days=30&export=csv")
        t.check("the checkout list downloads as CSV", s == 200 and b"Outcome" in b)

        # --- Referral activation
        for i in range(5):
            db.q("insert into users(name,email,password,created_at,updated_at,referred_by,is_admin,blocked) values('R','ref%d@test.local','x',datetime('now'),datetime('now'),?,0,0)" % i, uid)
        R = o.api("GET", "admin/growth/customers?days=30")[1]["referrals"]
        t.check("referral activation counts referred sign-ups and paid referrals separately", R["signups"] == 5 and R["paidReferrals"] == 0, {k2: R[k2] for k2 in ("signups", "paidReferrals")})
        t.check("five referred sign-ups in a day, never verified, are flagged for review", any(f["email"] == "ai-visitor@test.local" for f in R["flags"]) and len([f for f in R["flags"] if f["email"] == "ai-visitor@test.local"]) == 2, R["flags"])

        # --- Link register
        s, j = o.api("POST", "admin/growth/link", {"domain": "blog.example.com", "method": "guest", "status": "accepted", "relevance": 4, "quality": 4, "spam_risk": "low", "cost_inr": 2000, "link_attr": "follow"})
        t.check("a paid placement cannot be recorded as a followed link", s == 422 and "sponsored" in j.get("error", ""), (s, j))
        s, j = o.api("POST", "admin/growth/link", {"domain": "best-backlinks-seo.biz", "method": "directory", "status": "prospect", "relevance": 3, "quality": 3, "spam_risk": "low"})
        t.check("an obvious link-selling site is marked high spam risk and scored low", s == 200 and db.q("select spam_risk, score from link_prospects where domain='best-backlinks-seo.biz'") == [("high", 0)])
        s, j = o.api("POST", "admin/growth/link", {"url": "https://www.careers.example.edu/resources", "method": "resource_page", "status": "published", "relevance": 5, "quality": 4, "spam_risk": "low", "publication_url": ext + "/live", "contact_name": "Priya Nair"})
        lid = j.get("id")
        row = db.q("select domain, verify_state, link_attr, score from link_prospects where id=?", lid)
        t.check("a published page is opened and the link to CVTara found with its rel attribute", s == 200 and row and tuple(row[0][:3]) == ("careers.example.edu", "live", "nofollow") and row[0][3] == 90, row)
        d = o.api("GET", "admin/growth/links/draft?id=" + lid)[1]["text"]
        t.check("the pitch draft is a personal note from the CVTara team, sent by the owner", "Hi Priya" in d and "CVTara team" in d and " AI" not in d, d[:200])
        o.api("POST", "admin/growth/link", {"id": lid, "url": "https://www.careers.example.edu/resources", "method": "resource_page", "status": "published", "relevance": 5, "quality": 4, "spam_risk": "low", "publication_url": ext + "/mention"})
        t.check("a page that names CVTara without linking is an unlinked mention to reclaim", db.q("select verify_state from link_prospects where id=?", lid) == [("mention",)])
        o.api("POST", "admin/growth/link", {"id": lid, "url": "https://www.careers.example.edu/resources", "method": "resource_page", "status": "published", "relevance": 5, "quality": 4, "spam_risk": "low", "publication_url": ext + "/gone"})
        t.check("a removed page is noticed", db.q("select verify_state from link_prospects where id=?", lid) == [("gone",)])
        v.api("POST", "traffic", {"consent": True, "path": "/guides.html", "source": "referral", "ref": "www.careers.example.edu"})
        L = o.api("GET", "admin/growth/links")[1]
        t.check("visits from a site in the register are counted", next(r for r in L["rows"] if r["id"] == lid)["visits90d"] >= 1 and L["summary"]["total"] == 2)

        # --- AI answer checks
        s, j = o.api("POST", "admin/growth/visibility/test", {"tested_on": "2026-11-01", "platform": "chatgpt", "prompt": "Best resume builder in India", "mentioned": False, "recommended": True})
        t.check("an answer without CVTara cannot be recorded as recommending it", s == 422)
        s, j = o.api("POST", "admin/growth/visibility/test", {"tested_on": "2099-01-01", "platform": "chatgpt", "prompt": "Best resume builder in India", "mentioned": True})
        t.check("a check cannot be dated in the future", s == 422)
        for plat, men in (("chatgpt", True), ("gemini", False)):
            o.api("POST", "admin/growth/visibility/test", {"tested_on": "2026-11-05", "platform": plat, "model": "web", "prompt": "Best resume builder in India", "mentioned": men, "recommended": men, "linked": False, "competitors": "Brand A, Brand B", "cited_url": "https://cvtara.com/" if men else "", "missing": "" if men else "No pricing page"})
        V = o.api("GET", "admin/growth/visibility?days=90")[1]
        p = {x["key"]: x for x in V["platforms"]}
        t.check("AI checks are summarised per platform with the mention rate", p["chatgpt"]["tests"] == 1 and p["chatgpt"]["mentionRate"] == 100 and p["gemini"]["mentionRate"] == 0 and p["perplexity"]["tests"] == 0, {k3: p[k3]["mentionRate"] for k3 in p})
        t.check("competitors named and gaps are collected", V["competitors"][0]["n"] == 2 and V["missing"][0]["missing"] == "No pricing page")
        t.check("AI assistant visits, sign-ups and buyers are shown per platform", any(a["platform"] == "chatgpt" and a["signups"] == 1 and a["buyers"] == 1 for a in V["arrivals"]), V["arrivals"])
        t.check("the prompt list can be edited", o.api("POST", "admin/growth/visibility/prompts", {"prompts": ["Best resume builder for freshers", "  ", "Best ATS checker"]})[1]["prompts"] == ["Best resume builder for freshers", "Best ATS checker"])

        # --- Crawler access audit and the training-crawler choice
        robots = guest.raw("GET", "/robots.txt")[2].decode()
        t.check("by default robots.txt is unchanged: one group for all crawlers", robots.count("User-agent:") == 1, robots)
        s, j = o.api("POST", "admin/growth/visibility/audit", {})
        A = {c["agent"]: c for c in j["audit"]["crawlers"]}
        t.check("search crawlers can reach public pages and private areas are closed", s == 200 and A["Googlebot"]["publicAllowed"] == A["Googlebot"]["publicChecked"] and not A["Googlebot"]["privateOpen"] and A["Googlebot"]["homeStatus"] == 200, A.get("Googlebot"))
        t.check("customer workspace, console and checkout pages answer noindex", all(p["ok"] for p in j["audit"]["noindex"]), j["audit"]["noindex"])
        s, j = o.api("POST", "admin/growth/visibility/policy", {"policy": {"GPTBot": "block", "Googlebot": "block"}})
        robots = guest.raw("GET", "/robots.txt")[2].decode()
        t.check("the owner can block a training crawler; search crawlers cannot be blocked from here", "User-agent: GPTBot\nDisallow: /" in robots and "Googlebot" not in robots and j["policy"]["GPTBot"] == "block", robots)
        j = o.api("POST", "admin/growth/visibility/audit", {})[1]
        A = {c["agent"]: c for c in j["audit"]["crawlers"]}
        t.check("the audit respects the choice: the blocked training crawler is OK, search still open", A["GPTBot"]["ok"] and A["GPTBot"]["publicAllowed"] == 0 and A["OAI-SearchBot"]["publicAllowed"] == A["OAI-SearchBot"]["publicChecked"], (A["GPTBot"], A["OAI-SearchBot"]))
        t.check("robots.txt rules are read like crawlers read them", tj("[App\\Services\\AiVisibility::allowed(App\\Services\\AiVisibility::parseRobots(\"User-agent: *\\nDisallow: /api/\\nAllow: /api/public\\n\\nUser-agent: GPTBot\\nDisallow: /\\n\"), 'Googlebot', '/api/x'), App\\Services\\AiVisibility::allowed(App\\Services\\AiVisibility::parseRobots(\"User-agent: *\\nDisallow: /api/\\nAllow: /api/public\\n\"), 'Googlebot', '/api/public/a'), App\\Services\\AiVisibility::allowed(App\\Services\\AiVisibility::parseRobots(\"User-agent: *\\nDisallow: /api/\\n\\nUser-agent: GPTBot\\nDisallow: /\\n\"), 'GPTBot', '/')]") == [False, True, False])

        # --- Scorecard and autonomy
        S = o.api("GET", "admin/growth/scorecard?days=30")[1]
        M = S["money"]
        t.check("without a fee rate, gateway fees are 'not tracked' and contribution says it is incomplete", M["gatewayFeesPaise"] is None and M["contributionComplete"] is False)
        t.check("first-touch and last-touch views each add up to net revenue (nothing counted twice)", sum(a["firstPaise"] for a in S["attribution"]) == M["netPaise"] == sum(a["lastPaise"] for a in S["attribution"]) and M["netPaise"] > 0, (M["netPaise"], S["attribution"]))
        o.api("POST", "admin/growth/costs", {"gatewayFeePct": 2, "dailyCap": 50})
        S = o.api("GET", "admin/growth/scorecard?days=30")[1]
        t.check("with a fee rate, fees are estimated and contribution is net minus variable costs", S["money"]["gatewayFeesPaise"] == round(S["money"]["grossPaise"] * 0.02) and S["money"]["contributionPaise"] == S["money"]["netPaise"] - S["money"]["gatewayFeesPaise"] - S["money"]["aiPaise"] - S["money"]["campaignPaise"] - S["money"]["linkPaise"] and S["lifecycle"]["dailyCap"] == 50)
        t.check("autonomy level 5 can never be switched on", o.api("POST", "admin/growth/levels", {"agent": "seo", "level": 5})[0] == 422 and o.api("POST", "admin/growth/levels", {"agent": "seo", "level": 2})[1]["levels"]["seo"] == 2)
        t.check("the permission matrix lists what is never automated", "redirects" in S["matrix"]["seo"]["5"] and "refunds" in S["matrix"]["growth"]["5"])

        # --- Business assistant hooks
        g = tj("App\\Services\\Growth::agentStatus()")
        labels = [r["label"] for r in g["results"]]
        t.check("the growth agent reports checkout recovery to the Business assistant", any("Checkouts started" in l for l in labels) and any("Referred sign-ups" in l for l in labels), labels)
        sa = tj("App\\Services\\SeoAgent::agentStatus()")
        labels = [r["label"] for r in sa["results"]]
        t.check("the SEO agent reports links, AI answer checks and crawler problems", any("Published links" in l for l in labels) and any("AI answer checks" in l for l in labels) and any("Crawler access" in l for l in labels), labels)
        t.check("the agents' daily work runs the new tasks and logs them", (tinker("Illuminate\\Support\\Facades\\Cache::flush(); App\\Services\\SeoAgent::work(); App\\Services\\GrowthOps::work();") or True) and {r[0] for r in db.q("select task from agent_runs")} >= {"crawler-audit", "link-check", "customer-events", "journeys"}, db.q("select task, status from agent_runs"))
    finally:
        inst.stop(); srv.shutdown()
