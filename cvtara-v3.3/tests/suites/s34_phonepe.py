"""PhonePe beside Razorpay: owner settings, order, return check, callback, scheduled check, refund and the gateway choice."""
import hashlib, json
NAME = "PhonePe checkout"
HOOK_USER, HOOK_PASS = "cvtara-hook", "hook-pass-123"


def run(ctx, t):
    from lib.env import Instance
    from lib.fake_phonepe import FakePhonePe
    from lib.fake_razorpay import FakeRazorpay
    from lib.http import Client
    from lib.db import Db
    pp, rz = FakePhonePe(), FakeRazorpay()
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"PHONEPE_API_BASE": pp.base, "RAZORPAY_API_BASE": rz.base}).start()
    auth = hashlib.sha256(f"{HOOK_USER}:{HOOK_PASS}".encode()).hexdigest()

    def hook(c, body, header=auth):
        return c._post_raw("/webhooks/phonepe", json.dumps(body).encode(), {"Authorization": header, "Content-Type": "application/json", "Accept": "application/json"})[0]

    try:
        db = Db(inst.db); o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        s, j = o.api("GET", "admin/content/integrations"); d = j["data"]; d["payments"] = True
        o.api("POST", "admin/content/integrations", {"data": d, "revision": j["revision"]})
        keys = {"provider": "phonepe", "phonepeMode": "test", "phonepeClientId": pp.CLIENT_ID, "phonepeClientVersion": "1"}
        s, j = o.api("POST", "admin/providers", {"service": "payments", "values": {"provider": "phonepe"}}); t.check("PhonePe needs its Client ID and secret", s == 422 and "PhonePe" in str(j), f"{s} {j}")
        s, j = o.api("POST", "admin/providers", {"service": "payments", "values": {**keys, "phonepeClientSecret": "wrong"}})
        t.check("PhonePe needs the webhook username and password", s == 422 and "webhook" in str(j), f"{s} {j}")
        s, j = o.api("POST", "admin/providers", {"service": "payments", "values": {**keys, "phonepeClientSecret": "wrong", "phonepeWebhookUsername": HOOK_USER, "phonepeWebhookPassword": HOOK_PASS}})
        t.check("credentials PhonePe refuses are not saved", s == 422 and "did not accept" in str(j), f"{s} {j}")
        s, j = o.api("POST", "admin/providers", {"service": "payments", "values": {"provider": "paytm"}}); t.check("an unknown gateway is refused", s == 422, f"{s} {j}")
        s, j = o.api("POST", "admin/providers", {"service": "payments", "values": {**keys, "phonepeClientSecret": pp.CLIENT_SECRET, "phonepeWebhookUsername": HOOK_USER, "phonepeWebhookPassword": HOOK_PASS}})
        t.check("save PhonePe credentials", s == 200, f"{s} {j}")
        s, j = o.api("GET", "admin/providers"); pj = j["providers"]["payments"]
        t.check("the PhonePe secret and webhook password are never sent back", pj.get("phonepeClientSecret") == {"configured": True} and pj.get("phonepeWebhookPassword") == {"configured": True}, pj)

        Client.verify_db = inst.db; u = Client(inst.base); u.register("pp-a@test.local"); uid = db.q("select id from users where email='pp-a@test.local'")[0][0]
        s, q = u.api("POST", "checkout/quote", {"plan": "job-hunt"})
        t.check("checkout offers only PhonePe, with no keys in the answer", s == 200 and q.get("gateways") == [{"id": "phonepe", "name": "PhonePe"}] and pp.CLIENT_SECRET not in json.dumps(q), q.get("gateways"))
        s, j = u.api("POST", "checkout/start", {"plan": "job-hunt", "gateway": "razorpay"}); t.check("a gateway that is switched off cannot be used", s == 422, f"{s} {j}")
        s, j = u.api("POST", "checkout/start", {"plan": "job-hunt"})
        t.check("checkout sends the customer to PhonePe's payment page", s == 200 and j.get("provider") == "phonepe" and j.get("redirectUrl", "").startswith("https://mercury-uat.phonepe.com/") and j.get("test") is True, f"{s} {j}")
        mid = j["orderId"]; sent = pp.orders.get(mid, {})
        t.check("the PhonePe order is for the plan price and returns to checkout", sent.get("amount") == 29900 and sent["paymentFlow"]["type"] == "PG_CHECKOUT" and sent["paymentFlow"]["merchantUrls"]["redirectUrl"].endswith("/checkout.html?phonepe_order=" + mid), sent)
        t.check("the order is stored as a PhonePe test order", db.q("select provider, is_live, status from purchases where provider_order_id=?", mid) == [("phonepe", 0, "created")])
        s, j = u.api("POST", "checkout/verify", {"provider": "phonepe", "orderId": mid}); t.check("before paying the order is pending and grants nothing", s == 200 and j.get("state") == "PENDING" and u.api("GET", "allowance")[1].get("plan") == "free", f"{s} {j}")
        other = Client(inst.base); other.register("pp-b@test.local")
        t.check("someone else cannot check this order", other.api("POST", "checkout/verify", {"provider": "phonepe", "orderId": mid})[0] == 404)
        tid = pp.pay(mid)
        s, j = u.api("POST", "checkout/verify", {"provider": "phonepe", "orderId": mid})
        t.check("back from PhonePe, a completed payment grants the plan", s == 200 and j.get("state") == "COMPLETED" and u.api("GET", "allowance")[1].get("plan") == "job-hunt", f"{s} {j}")
        t.check("the PhonePe transaction is the payment reference", db.q("select status, payment_reference from purchases where provider_order_id=?", mid) == [("paid", tid)])
        u.api("POST", "checkout/verify", {"provider": "phonepe", "orderId": mid})
        t.check("checking twice is harmless", db.q("select count(*) from purchases where owner_id=? and status='paid'", uid)[0][0] == 1)

        anon = Client(inst.base)
        t.check("a callback without the right authorization is refused", hook(anon, {"event": "checkout.order.completed", "payload": {"merchantOrderId": mid, "state": "COMPLETED"}}, "0" * 64) == 403)
        t.check("a repeated callback for a paid order changes nothing", hook(anon, {"event": "checkout.order.completed", "payload": {"merchantOrderId": mid, "state": "COMPLETED"}}) == 200 and db.q("select count(*) from purchases where owner_id=? and status='paid'", uid)[0][0] == 1)

        v = Client(inst.base); v.register("pp-c@test.local")
        mid2 = v.api("POST", "checkout/start", {"plan": "quick"})[1]["orderId"]
        t.check("a callback that claims payment is checked with PhonePe first", hook(anon, {"event": "checkout.order.completed", "payload": {"merchantOrderId": mid2, "state": "COMPLETED"}}) == 200 and db.q("select status from purchases where provider_order_id=?", mid2) == [("created",)])
        pp.pay(mid2)
        t.check("the callback grants the plan when the customer never came back", hook(anon, {"event": "checkout.order.completed", "payload": {"merchantOrderId": mid2, "state": "COMPLETED", "amount": 9900}}) == 200 and db.q("select status from purchases where provider_order_id=?", mid2) == [("paid",)])

        w = Client(inst.base); w.register("pp-d@test.local")
        mid3 = w.api("POST", "checkout/start", {"plan": "quick"})[1]["orderId"]; pp.pay(mid3)
        db.q("update purchases set created_at=datetime('now','-10 minutes') where provider_order_id=?", mid3)
        inst.artisan("tinker", "--execute", "App\\Services\\PhonePe::reconcile();")
        t.check("the scheduled check settles a paid order with no return and no callback", db.q("select status from purchases where provider_order_id=?", mid3) == [("paid",)])
        mid4 = w.api("POST", "checkout/start", {"plan": "job-hunt"})[1]["orderId"]; pp.fail(mid4)
        s, j = w.api("POST", "checkout/verify", {"provider": "phonepe", "orderId": mid4}); t.check("a failed PhonePe payment says so and grants nothing", j.get("state") == "FAILED" and db.q("select status from purchases where provider_order_id=?", mid4) == [("created",)], j)
        mid5 = w.api("POST", "checkout/start", {"plan": "job-hunt"})[1]["orderId"]; pp.pay(mid5, amount=100)
        s, j = w.api("POST", "checkout/verify", {"provider": "phonepe", "orderId": mid5}); t.check("a payment for the wrong amount does not grant the plan", s == 422 and db.q("select status from purchases where provider_order_id=?", mid5) == [("created",)], (s, j))

        s, pj = u.api("GET", "purchases"); purchase = [p for p in pj["purchases"] if p["provider_order_id"] == mid][0]["id"]
        t.check("the customer requests a refund", u.api("POST", "refunds", {"purchaseId": purchase})[0] == 200)
        s, rq = o.api("GET", "admin/refunds"); rid = [x for x in rq["requests"] if x["purchase_id"] == purchase][0]["id"]
        s, j = o.api("POST", f"admin/refunds/{rid}/approve", {})
        sent = list(pp.refunds.values())
        t.check("approving sends the full refund to PhonePe", s == 200 and sent and sent[-1]["originalMerchantOrderId"] == mid and sent[-1]["amount"] == 29900, (s, str(j)[:200], sent))
        t.check("access ends as soon as PhonePe accepts the refund", db.q("select status, refunded_paise from purchases where id=?", purchase) == [("refunded", 29900)] and db.q("select status from refund_requests where id=?", rid) == [("processed",)])
        mrid = sent[-1]["merchantRefundId"]; pp.refunds[mrid]["state"] = "COMPLETED"
        t.check("PhonePe's refund-completed callback does not count the refund twice", hook(anon, {"event": "pg.refund.completed", "payload": {"merchantRefundId": mrid, "originalMerchantOrderId": mid, "amount": 29900, "state": "COMPLETED"}}) == 200 and db.q("select status, refunded_paise from purchases where id=?", purchase) == [("refunded", 29900)])

        s, j = o.api("POST", "admin/providers", {"service": "payments", "values": {"provider": "both", "razorpayKeyId": "rzp_test_abc", "razorpayKeySecret": "rzp_secret"}}); t.check("switch on both gateways", s == 200, f"{s} {j}")
        s, q = u.api("POST", "checkout/quote", {"plan": "job-hunt"}); t.check("with both on, the customer can choose", [g["id"] for g in q.get("gateways", [])] == ["razorpay", "phonepe"], q.get("gateways"))
        s, j = u.api("POST", "checkout/start", {"plan": "job-hunt", "gateway": "razorpay"}); t.check("choosing Razorpay still opens Razorpay", s == 200 and j.get("provider") == "razorpay", f"{s} {j}")
        s, j = u.api("POST", "checkout/start", {"plan": "job-hunt", "gateway": "phonepe"}); t.check("choosing PhonePe opens PhonePe", s == 200 and j.get("provider") == "phonepe", f"{s} {j}")
        s, j = o.api("GET", "admin/setup/status"); t.check("setup status lists both webhook addresses", s == 200 and sorted(j.get("webhooks", {}).keys()) == ["phonepe", "razorpay"], j.get("webhooks"))
        t.check("PhonePe tokens are reused rather than fetched for every call", pp.tokens <= 4, pp.tokens)
    finally:
        inst.stop(); pp.stop(); rz.stop()
