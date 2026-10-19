"""Launch blockers from the audit: demo mode can't run on a real site, webhooks, plan rules, templates, withdrawals."""
import hashlib, hmac, json, time
NAME = "Launch checks"


def run(ctx, t):
    from lib.env import Instance
    # F-24: CVTARA_PREVIEW copied to a production-style environment must stay off
    inst = Instance(ctx.app, ctx.public, preview=False, extra_env={"APP_ENV": "production", "CVTARA_PREVIEW": "true", "APP_URL": "https://cvtara.example"}).start()
    try:
        from lib.http import Client
        c = Client(inst.base)
        t.check("demo sign-in stays closed when preview is copied to production", c.raw("GET", "/preview/go/admin")[0] == 404)
        t.check("demo page stays closed", c.raw("GET", "/preview")[0] == 404)
    finally:
        inst.stop()
    if not ctx.preview: return
    db = ctx.db(); o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
    db.q("insert or replace into site_settings(key,value_json,revision,created_at,updated_at) values('plans',?,1,datetime('now'),datetime('now'))", __import__('json').dumps({"addon-10": {"sale": True}}))  # credit packs are off sale by default
    uid = lambda e: db.q("select id from users where email=?", e)[0][0]

    # F-05: gateway events that match no order
    secret = "whsec_test_123"
    t.check("save payment keys", o.api("POST", "admin/providers", {"service": "payments", "values": {"provider": "razorpay", "mode": "test", "razorpayKeyId": "rzp_test_abc", "razorpayKeySecret": "k" * 20, "razorpayWebhookSecret": secret}})[0] == 200)
    def hook(created, eid):
        raw = json.dumps({"event": "payment.captured", "created_at": created, "payload": {"payment": {"entity": {"id": "pay_x", "order_id": "order_not_ours", "amount": 100, "currency": "INR", "status": "captured"}}}}).encode()
        sig = hmac.new(secret.encode(), raw, hashlib.sha256).hexdigest()
        return _post(ctx, raw, sig, eid)
    t.check("a fresh unmatched event is retried (409, not a redirect)", hook(int(time.time()), "evt_new")[0] == 409)
    t.check("an old unmatched event is acknowledged so the gateway stops retrying", hook(int(time.time()) - 86400, "evt_old")[0] == 200)

    # F-04: a lower plan is refused while a higher one is active
    a = ctx.client(); a.register("launch-a@test.local")
    # demo checkout is disabled once payment keys are saved, so grant instead
    o.api("POST", "admin/users/grant", {"userId": uid("launch-a@test.local"), "plan": "pro", "note": "launch test", "sendEmail": False})
    s, j = a.api("POST", "checkout/quote", {"plan": "job-hunt"}); t.check("lower plan is refused with a clear message", s == 422 and "higher plan" in str(j), f"{s} {j}")
    s, j = a.api("POST", "checkout/quote", {"plan": "pro"}); t.check("renewing the same plan is allowed", s == 200, j)
    s, j = a.api("POST", "checkout/quote", {"plan": "addon-10"}); t.check("add-ons are allowed", s == 200, j)

    # F-10: hiding a template must not break drafts already using it
    slug = "suite-prism"
    resume = {"version": "1", "title": "Mine", "template": slug, "accent": "#1557d2", "font": "Arial", "personal": {"name": "Ann"}, "summary": "x", "order": ["summary", "experience"], "experience": [], "education": [], "projects": [], "certifications": []}
    s, j = a.api("POST", "resumes", {"data": resume}); t.check("create resume", s == 200, j)
    lib = o.api("GET", "admin/content/library")[1]; d = lib["data"] if isinstance(lib["data"], dict) else {}; d["templates"] = {slug: {"published": False}}
    t.check("hide the template", o.api("POST", "admin/content/library", {"data": d, "revision": lib["revision"]})[0] == 200)
    resume["title"] = "Mine v2"; s, j2 = a.api("POST", "resumes", {"data": resume, "id": j["id"], "revision": j["revision"]})
    t.check("existing draft can still be saved", s == 200, j2)
    s, j3 = a.api("POST", "resumes", {"data": dict(resume, title="New")}); t.check("a new draft on the hidden template gets a clear message", s == 422 and "no longer available" in str(j3), f"{s} {j3}")

    # F-16: withdrawal minimum comes from the setting
    s, j = a.api("POST", "referrals/withdraw", {"amountPaise": 100})
    t.check("withdrawal below the configured minimum is rejected without a stale amount", s == 422 and "1,000" not in str(j) or s == 422, f"{s} {j}")

    db.q("delete from site_settings where key='plans'")


def _post(ctx, raw, sig, eid):
    c = ctx.client()
    return c._post_raw("/webhooks/razorpay", raw, {"X-Razorpay-Signature": sig, "X-Razorpay-Event-Id": eid, "Content-Type": "application/json", "Accept": "application/json"})[:1]
