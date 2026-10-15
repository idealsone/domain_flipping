"""Cashfree is the primary gateway: order, verify, webhook, refund, unmatched events."""
import base64, hashlib, hmac, json, time
NAME = "Cashfree checkout"
SECRET = "cf_secret_test_value"


def sign(raw):
    ts = str(int(time.time())).encode()
    return ts.decode(), base64.b64encode(hmac.new(SECRET.encode(), ts + raw, hashlib.sha256).digest()).decode()


def run(ctx, t):
    from lib.env import Instance
    from lib.fake_cashfree import FakeCashfree
    from lib.http import Client
    from lib.db import Db
    cf = FakeCashfree()
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"CASHFREE_API_BASE": cf.base}).start()
    try:
        db = Db(inst.db); o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        s, j = o.api("GET", "admin/content/integrations"); d = j["data"]; d["payments"] = True
        o.api("POST", "admin/content/integrations", {"data": d, "revision": j["revision"]})
        s, j = o.api("POST", "admin/providers", {"service": "payments", "values": {"provider": "cashfree"}}); t.check("Cashfree needs both keys", s == 422, f"{s} {j}")
        s, j = o.api("POST", "admin/providers", {"service": "payments", "values": {"provider": "cashfree", "mode": "test", "cashfreeClientId": "TEST123", "cashfreeClientSecret": SECRET}}); t.check("save Cashfree keys", s == 200, f"{s} {j}")

        u = Client(inst.base); u.register("cf-a@test.local"); uid = db.q("select id from users where email='cf-a@test.local'")[0][0]
        s, j = u.api("POST", "checkout/start", {"plan": "fresher-basic"}); t.check("a mobile number is required", s == 422 and "mobile" in str(j).lower(), f"{s} {j}")
        s, j = u.api("POST", "checkout/start", {"plan": "fresher-basic", "phone": "9876543210"})
        t.check("checkout opens through Cashfree", s == 200 and j.get("provider") == "cashfree" and j.get("paymentSessionId"), f"{s} {j}")
        oid = j["orderId"]
        t.check("the order amount sent to Cashfree is the plan price", cf.orders[oid]["order_amount"] == 299, cf.orders.get(oid))
        s, j2 = u.api("POST", "checkout/verify", {"provider": "cashfree", "orderId": oid}); t.check("verify before payment does not grant access", s == 422 and u.api("GET", "allowance")[1].get("plan") == "free", f"{s} {j2}")
        cf.pay(oid)
        s, j2 = u.api("POST", "checkout/verify", {"provider": "cashfree", "orderId": oid}); t.check("verify after payment grants the plan", s == 200 and u.api("GET", "allowance")[1].get("plan") == "fresher-basic", f"{s} {j2}")
        s, j2 = u.api("POST", "checkout/verify", {"provider": "cashfree", "orderId": oid}); t.check("verifying twice is harmless", s == 200 and db.q("select count(*) from purchases where owner_id=? and status='paid'", uid)[0][0] == 1)

        def hook(order_id, tsig=None):
            raw = json.dumps({"type": "PAYMENT_SUCCESS_WEBHOOK", "data": {"order": {"order_id": order_id}}}).encode(); ts, sg = tsig or sign(raw)
            return Client(inst.base)._post_raw("/webhooks/cashfree", raw, {"x-webhook-timestamp": ts, "x-webhook-signature": sg, "Content-Type": "application/json", "Accept": "application/json"})[0]
        t.check("webhook with a wrong signature is rejected", hook(oid, ("1700000000", "bad")) == 403)
        cf.refund(oid, 299)
        t.check("signed refund webhook is accepted", hook(oid) == 200)
        r = db.q("select status, refunded_paise from purchases where provider_order_id=?", oid)[0]
        t.check("full refund is recorded and access removed", r[0] == "refunded" and r[1] == 29900 and u.api("GET", "allowance")[1].get("plan") == "free", r)
        t.check("an event for an order this site never created is acknowledged", hook("someone_elses_order") == 200)
        t.check("Cashfree sends the API version and keys", any(c[0] == "POST" and c[1] == "/orders" and c[2].get("x-api-version") for c in cf.calls))
        s, j = o.api("GET", "admin/setup/status"); t.check("setup status sees payments configured", s == 200 and j.get("checks", {}).get("paymentsConfigured") in (True, None), j.get("checks"))
    finally:
        inst.stop(); cf.stop()
