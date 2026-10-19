"""Razorpay is the only payment gateway: order, signed verify, refund, and no mobile number at checkout."""
import hashlib, hmac
NAME = "Razorpay checkout"
SECRET = "rzp_secret_test_value"


def run(ctx, t):
    from lib.env import Instance
    from lib.fake_razorpay import FakeRazorpay
    from lib.http import Client
    from lib.db import Db
    rz = FakeRazorpay()
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"RAZORPAY_API_BASE": rz.base}).start()
    try:
        db = Db(inst.db); o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        s, j = o.api("GET", "admin/content/integrations"); d = j["data"]; d["payments"] = True
        o.api("POST", "admin/content/integrations", {"data": d, "revision": j["revision"]})
        s, j = o.api("POST", "admin/providers", {"service": "payments", "values": {"provider": "razorpay"}}); t.check("Razorpay needs both keys", s == 422, f"{s} {j}")
        s, j = o.api("POST", "admin/providers", {"service": "payments", "values": {"provider": "cashfree", "cashfreeClientId": "X", "cashfreeClientSecret": "Y"}}); t.check("Cashfree keys can no longer be saved", s == 422, f"{s} {j}")
        s, j = o.api("POST", "admin/providers", {"service": "payments", "values": {"provider": "razorpay", "mode": "test", "razorpayKeyId": "rzp_test_abc", "razorpayKeySecret": SECRET}}); t.check("save Razorpay keys", s == 200, f"{s} {j}")

        Client.verify_db = inst.db; u = Client(inst.base); u.register("rz-a@test.local"); uid = db.q("select id from users where email='rz-a@test.local'")[0][0]
        s, j = u.api("POST", "checkout/start", {"plan": "job-hunt"})
        t.check("checkout opens through Razorpay without asking for a mobile number", s == 200 and j.get("provider") == "razorpay" and j.get("keyId") == "rzp_test_abc" and j.get("test") is True, f"{s} {j}")
        oid = j["orderId"]
        t.check("the order amount sent to Razorpay is the plan price", rz.orders[oid]["amount"] == 29900 and rz.orders[oid]["currency"] == "INR", rz.orders.get(oid))
        t.check("the order is stored as a Razorpay test order", db.q("select provider, is_live, status from purchases where provider_order_id=?", oid) == [("razorpay", 0, "created")])
        pid = rz.pay(oid); sig = hmac.new(SECRET.encode(), f"{oid}|{pid}".encode(), hashlib.sha256).hexdigest()
        bad = {"razorpay_order_id": oid, "razorpay_payment_id": pid, "razorpay_signature": "0" * 64}
        t.check("a wrong signature does not grant access", u.api("POST", "checkout/verify", bad)[0] == 403 and u.api("GET", "allowance")[1].get("plan") == "free")
        good = {"razorpay_order_id": oid, "razorpay_payment_id": pid, "razorpay_signature": sig}
        s, j2 = u.api("POST", "checkout/verify", good); t.check("a signed payment grants the plan", s == 200 and u.api("GET", "allowance")[1].get("plan") == "job-hunt", f"{s} {j2}")
        s, j2 = u.api("POST", "checkout/verify", good); t.check("verifying twice is harmless", s == 200 and db.q("select count(*) from purchases where owner_id=? and status='paid'", uid)[0][0] == 1)
        other = Client(inst.base); other.register("rz-b@test.local")
        t.check("someone else cannot verify this order", other.api("POST", "checkout/verify", good)[0] == 404)

        s, pj = u.api("GET", "purchases"); purchase = pj["purchases"][0]["id"]
        t.check("the customer requests a refund", u.api("POST", "refunds", {"purchaseId": purchase})[0] == 200)
        s, rq = o.api("GET", "admin/refunds"); rid = [x for x in rq["requests"] if x["purchase_id"] == purchase][0]["id"]
        s, j = o.api("POST", f"admin/refunds/{rid}/approve", {})
        t.check("approving sends the full refund to Razorpay", s == 200 and rz.refunds and rz.refunds[-1]["payment_id"] == pid and rz.refunds[-1]["amount"] == 29900, (s, str(j)[:200], rz.refunds))
        t.check("the Cashfree webhook address is gone", Client(inst.base)._post_raw("/webhooks/cashfree", b"{}", {"Content-Type": "application/json", "Accept": "application/json"})[0] in (404, 405))
        s, j = o.api("GET", "admin/setup/status"); t.check("setup status sees payments configured and lists only the Razorpay webhook", s == 200 and list(j.get("webhooks", {}).keys()) == ["razorpay"], j.get("webhooks"))
    finally:
        inst.stop(); rz.stop()
