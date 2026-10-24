"""Automatic Tara Expert Review: an order is written by Tara (shown to customers as the CVTara team), stored on the order, shown in the dashboard and emailed.
The owner setting switches it off (orders then wait for the owner), failures are retried at most 3 times and then flagged
for the owner, two cron runs never write the same review twice, and reviews never use the daily suggestion allowance."""
import http.server, json, subprocess, threading, time
NAME = "Automatic Tara Expert Review"
HITS = []
MODE = {"v": "ok"}
REVIEW = "## Overall verdict\nA clear resume for a store operations role.\n\n## Fix list, most important first\n- Add numbers to the hiring bullet\n- Name the target role\nCheck before using: the store count."


class Fake(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))) or b"{}")
        HITS.append(body)
        if MODE["v"] == "slow": time.sleep(3)
        if MODE["v"] == "fail":
            out, code = {"type": "error", "error": {"type": "api_error", "message": "boom"}}, 500
        else:
            stop = "refusal" if MODE["v"] == "refusal" else "end_turn"
            out, code = {"id": "msg_1", "type": "message", "role": "assistant", "model": body.get("model"), "content": [] if stop == "refusal" else [{"type": "text", "text": REVIEW}],
                         "stop_reason": stop, "stop_sequence": None, "usage": {"input_tokens": 10, "output_tokens": 10}}, 200
        b = json.dumps(out).encode()
        self.send_response(code); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)


def resume(name):
    return {"version": "1", "title": "Primary", "template": "suite-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": name, "email": "x@test.local", "role": "Store Manager"},
            "summary": "Retail store manager with six years of experience running busy stores in Pune and hiring and training store teams.",
            "order": ["summary", "experience"], "experience": [{"id": "1", "role": "Store Manager", "company": "Sample Retail", "start": "2020-01", "current": True, "bullets": "Responsible for hiring store staff across 14 stores.\nHandled stock and daily cash."}],
            "education": [], "projects": [], "certifications": []}


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    srv = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Fake); threading.Thread(target=srv.serve_forever, daemon=True).start()
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"CLAUDE_API_BASE": f"http://127.0.0.1:{srv.server_address[1]}"}).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        uid = lambda e: db.q("select id from users where email=?", e)[0][0]
        order = lambda e: db.q("select status, review, review_attempts, review_error, reviewer from service_orders where owner_id=?", uid(e))[0]
        mails = lambda e: db.q("select body from mail_outbox where owner_id=? and \"trigger\"='expert_review_delivered'", uid(e))
        hits_for = lambda name: [h for h in HITS if name in json.dumps(h)]

        def wait(e, status, secs=15):
            for _ in range(secs * 4):
                if order(e)[0] == status: return True
                time.sleep(0.25)
            return False

        def customer(e, name):
            c = Client(inst.base); c.register(e); c.page("/workspace.html")
            c.api("POST", "resumes", {"data": resume(name)})
            return c

        def buy(c):
            s, j = c.api("POST", "checkout/start", {"plan": "expert-review"})
            return c.api("POST", "checkout/verify", {"provider": "demo", "orderId": j["orderId"]})[0] if s == 200 else s

        def cron(): return inst.artisan("cvtara:cron")

        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        s, pl = o.api("GET", "admin/plans")
        o.api("POST", "admin/plans", {"plans": {"expert-review": {**pl["plans"]["expert-review"], "sale": True}}})
        s, sv = o.api("GET", "admin/services")
        t.check("automatic reviews default on, but Tara AI is not set up yet", s == 200 and sv["settings"] == {"autoReview": True, "taraEnabled": False}, sv.get("settings"))

        # Tara AI off: the order waits for the owner, nothing is sent
        a0 = customer("er-off@test.local", "Asha Zero")
        t.check("an order bought while Tara AI is off is opened", buy(a0) == 200)
        cron(); t.check("without Tara AI the order stays Not started and nothing is sent", order("er-off@test.local")[0] == "not_started" and HITS == [], order("er-off@test.local"))

        o.api("POST", "admin/providers", {"service": "ai", "values": {"provider": "claude", "claudeApiKey": "sk-ant-test-1", "model": "", "dailyLimit": "0"}})
        s, g = o.api("GET", "admin/content/integrations"); d = g["data"]; d["ai"] = True
        o.api("POST", "admin/content/integrations", {"data": d, "revision": g["revision"]})
        t.check("the queue reports Tara AI as on", o.api("GET", "admin/services")[1]["settings"]["taraEnabled"] is True)
        cron(); t.check("once Tara AI is on, the cron picks up the waiting order", wait("er-off@test.local", "delivered") and len(hits_for("Asha Zero")) == 1, order("er-off@test.local"))

        # automatic delivery straight after payment
        a = customer("er-a@test.local", "Asha One")
        t.check("the customer buys Tara Expert Review", buy(a) == 200)
        t.check("the review is written and delivered without the owner", wait("er-a@test.local", "delivered"), order("er-a@test.local"))
        st = order("er-a@test.local")
        t.check("the review is stored on the order and marked as Tara AI's", st[1] and "Overall verdict" in st[1] and st[4] == "tara" and st[2] == 1, st)
        h = hits_for("Asha One")[-1] if hits_for("Asha One") else {}
        prompt = json.dumps(h.get("messages", ""))
        t.check("the resume goes to Tara AI inside tags with the review structure asked for", "<resume>" in prompt and "14 stores" in prompt and "Rewritten bullets" in prompt and "Section-by-section" in prompt and "ATS issues" in prompt, prompt[:300])
        t.check("the request uses the owner's model settings", h.get("model") == "claude-opus-5-5" and h.get("fallbacks") == "default" and "Never invent" in json.dumps(h.get("system")))
        m = mails("er-a@test.local")
        t.check("the customer is emailed the review", len(m) == 1 and "Overall verdict" in m[0][0] and "Add numbers to the hiring bullet" in m[0][0], m)
        s, pj = a.api("GET", "purchases"); so = (pj.get("services") or [{}])[0]
        t.check("the delivered review shows in the customer's dashboard", so.get("status") == "delivered" and "Overall verdict" in (so.get("review") or ""), so)
        s, j = a.api("GET", "ai/status")
        t.check("the review does not use the customer's daily Tara AI suggestions", j["ai"]["left"] == 3 and db.q("select count(*) from product_usage where owner_id=? and action='ai'", uid("er-a@test.local"))[0][0] == 0, j)
        s, j = a.api("POST", "ai/suggest", {"task": "bullets", "text": "Responsible for hiring store staff across 14 stores in Pune.", "consent": True})
        t.check("suggestions still work after a review", s == 200 and j.get("left") == 2, (s, str(j)[:160]))
        t.check("a delivered review cannot be refunded", [p for p in pj["purchases"] if p["plan"] == "expert-review"][0]["refundable"] is False)

        # owner can view, edit and resend
        s, sv = o.api("GET", "admin/services"); row = [x for x in sv["orders"] if x["email"] == "er-a@test.local"][0]
        t.check("the owner sees the review in the queue", "Overall verdict" in (row.get("review") or "") and row["reviewer"] == "tara" and row["needs_owner"] is False, row)
        s, _ = o.api("POST", f"admin/services/{row['id']}/review", {"review": "## Overall verdict\nEdited by the owner.\n- Fix one"})
        t.check("the owner edits the review and the customer sees the edit", s == 200 and "Edited by the owner" in a.api("GET", "purchases")[1]["services"][0]["review"])
        s, _ = o.api("POST", f"admin/services/{row['id']}/resend", {})
        m = mails("er-a@test.local")
        t.check("the owner resends it and a second email is queued with the edit", s == 200 and len(m) == 2 and any("Edited by the owner" in x[0] for x in m), len(m))
        t.check("a customer cannot reach the owner's queue", a.api("GET", "admin/services")[0] in (401, 403))

        # setting off: orders wait for the owner, manual path works
        s, j = o.api("POST", "admin/services/settings", {"autoReview": False})
        t.check("the owner switches automatic reviews off", s == 200 and j["settings"]["autoReview"] is False, (s, str(j)[:200]))
        n = len(HITS); b = customer("er-b@test.local", "Asha Two"); buy(b); cron()
        t.check("with the setting off the order stays Not started and nothing is sent", order("er-b@test.local")[0] == "not_started" and len(HITS) == n)
        bid = [x for x in o.api("GET", "admin/services")[1]["orders"] if x["email"] == "er-b@test.local"][0]["id"]
        t.check("the owner starts it by hand", o.api("POST", f"admin/services/{bid}", {"status": "in_review"})[0] == 200 and order("er-b@test.local")[4] == "owner")
        s, _ = o.api("POST", f"admin/services/{bid}", {"status": "delivered", "review": "## Overall verdict\nWritten by hand by the owner."})
        t.check("delivering a hand-written review emails it", s == 200 and order("er-b@test.local")[0] == "delivered" and len(mails("er-b@test.local")) == 1 and "by hand" in mails("er-b@test.local")[0][0])
        o.api("POST", "admin/services/settings", {"autoReview": True})

        # failures: retried up to 3 times, then flagged for the owner
        MODE["v"] = "fail"; c = customer("er-c@test.local", "Asha Three"); buy(c)
        for _ in range(20):
            if order("er-c@test.local")[2] >= 1 and order("er-c@test.local")[0] == "not_started": break
            time.sleep(0.25)
        st = order("er-c@test.local")
        t.check("a failed attempt goes back to Not started with the reason", st[0] == "not_started" and st[2] == 1 and "error" in (st[3] or ""), st)
        s, pj = c.api("GET", "purchases")
        t.check("a failed attempt keeps the order refundable", [p for p in pj["purchases"] if p["plan"] == "expert-review"][0]["refundable"] is True)
        cron(); t.check("the next run waits for the retry time", order("er-c@test.local")[2] == 1)
        for _ in range(4):
            db.q("update service_orders set retry_at=datetime('now','-1 minute') where owner_id=?", uid("er-c@test.local")); cron()
        st = order("er-c@test.local")
        t.check("Tara AI stops after 3 attempts", st[0] == "not_started" and st[2] == 3 and len(hits_for("Asha Three")) == 3, (st, len(hits_for("Asha Three"))))
        row = [x for x in o.api("GET", "admin/services")[1]["orders"] if x["email"] == "er-c@test.local"][0]
        t.check("the owner queue flags it with the error", row["needs_owner"] is True and "HTTP 500" in (row["review_error"] or ""), row)
        t.check("the owner is told in the error log", db.q("select count(*) from error_logs where source='ai' and message like '%failed 3 times%'")[0][0] >= 1)
        t.check("no email was sent for a failed review", mails("er-c@test.local") == [])
        MODE["v"] = "ok"
        t.check("the owner asks Tara AI to try again", o.api("POST", f"admin/services/{row['id']}/retry", {})[0] == 200)
        cron(); t.check("after the retry the review is delivered", order("er-c@test.local")[0] == "delivered" and len(mails("er-c@test.local")) == 1)
        MODE["v"] = "refusal"; r = customer("er-r@test.local", "Asha Refused"); buy(r)
        for _ in range(20):
            if order("er-r@test.local")[2] >= 1: break
            time.sleep(0.25)
        time.sleep(0.5); st = order("er-r@test.local")
        t.check("a declined review is treated as a failed attempt", st[0] == "not_started" and "declined" in (st[3] or ""), st)
        MODE["v"] = "ok"

        # two cron runs at the same time write each review once
        o.api("POST", "admin/services/settings", {"autoReview": False})
        dd = customer("er-d@test.local", "Asha Four"); buy(dd)
        db.q("update service_orders set retry_at=null, review_attempts=0 where owner_id=?", uid("er-r@test.local"))
        o.api("POST", "admin/services/settings", {"autoReview": True})
        MODE["v"] = "slow"
        ps = [subprocess.Popen(["php", "artisan", "cvtara:cron"], cwd=inst.app, env=inst.env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL) for _ in range(2)]
        for p in ps: p.wait(timeout=120)
        MODE["v"] = "ok"
        t.check("two cron runs at once deliver the review once", order("er-d@test.local")[0] == "delivered" and len(hits_for("Asha Four")) == 1 and len(mails("er-d@test.local")) == 1, (order("er-d@test.local")[0], len(hits_for("Asha Four")), len(mails("er-d@test.local"))))
        cron(); t.check("a delivered review is never written again", len(hits_for("Asha Four")) == 1 and len(hits_for("Asha One")) == 1)

        # each service can be ordered 3 times a day per customer, then it is hidden until tomorrow
        o.api("POST", "admin/services/settings", {"autoReview": False})
        lim = customer("er-lim@test.local", "Asha Limit")
        t.check("a customer can order the same service 3 times in a day", [buy(lim) for _ in range(3)] == [200, 200, 200])
        s, j = lim.api("POST", "checkout/start", {"plan": "expert-review"})
        t.check("a 4th order the same day is refused until tomorrow", s == 422 and "3 times a day" in str(j) and "tomorrow" in str(j), (s, str(j)[:200]))
        t.check("the service is marked hidden for that customer", lim.api("GET", "business-public")[1]["plans"]["expert-review"].get("limitReached") is True)
        t.check("other visitors still see the service", "limitReached" not in Client(inst.base).api("GET", "business-public")[1]["plans"]["expert-review"])
        db.q("update service_orders set created_at=datetime('now','-2 days') where owner_id=?", uid("er-lim@test.local"))
        t.check("the next day the service can be ordered again", lim.api("POST", "checkout/start", {"plan": "expert-review"})[0] == 200)
        o.api("POST", "admin/services/settings", {"autoReview": True})

        s, _, priv = Client(inst.base).page("/privacy.html")
        t.check("the privacy policy covers Tara Expert Review", "Tara Expert Review" in priv and "text-processing provider" in priv)
        t.check("the privacy policy says CVTara team, not Tara AI", "CVTara team" in priv and "Tara AI" not in priv and "Anthropic" not in priv, priv[:300])
        for path in ["/tara-help.js", "/plans-ui.js", "/checkout-options.js", "/workspace-activity.js", "/refund.html"]:
            body = Client(inst.base).raw("GET", path)[2].decode("utf8", "ignore")
            t.check(f"{path} speaks of the CVTara team and never names AI or Claude", "Tara AI" not in body and "Claude" not in body, path)
        s, _, chk = Client(inst.base).page("/checkout.html")
        t.check("the checkout no longer promises a recruiter", "working recruiter" not in chk)
    finally:
        inst.stop(); srv.shutdown()
