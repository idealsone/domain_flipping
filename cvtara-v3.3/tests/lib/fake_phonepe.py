"""A tiny stand-in for PhonePe's PG v2 API (token, pay, order status, refund) so PhonePe flows can be tested offline."""
import json, threading, urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


class FakePhonePe:
    CLIENT_ID, CLIENT_SECRET = "PPTESTCLIENT", "pp_secret_test_value"

    def __init__(self):
        self.orders, self.refunds, self.calls, self.tokens = {}, {}, [], 0
        outer = self

        class H(BaseHTTPRequestHandler):
            def log_message(self, *a): pass

            def _send(self, code, obj):
                b = json.dumps(obj).encode(); self.send_response(code); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)

            def _auth(self): return self.headers.get("Authorization", "") == "O-Bearer tok_ok"

            def do_POST(self):
                n = int(self.headers.get("Content-Length", 0)); raw = self.rfile.read(n) or b""
                if self.path == "/v1/oauth/token":
                    f = dict(urllib.parse.parse_qsl(raw.decode()))
                    outer.calls.append(("POST", self.path, f))
                    if f.get("client_id") != outer.CLIENT_ID or f.get("client_secret") != outer.CLIENT_SECRET or f.get("grant_type") != "client_credentials":
                        return self._send(401, {"code": "401", "message": "bad credentials"})
                    outer.tokens += 1
                    return self._send(200, {"access_token": "tok_ok", "token_type": "O-Bearer", "expires_at": 4102444800})
                body = json.loads(raw or b"{}"); outer.calls.append(("POST", self.path, body))
                if not self._auth(): return self._send(401, {"code": "401"})
                if self.path == "/checkout/v2/pay":
                    mid = body["merchantOrderId"]
                    outer.orders[mid] = dict(body, orderId="OMO%d" % (len(outer.orders) + 1), state="PENDING", paymentDetails=[])
                    return self._send(200, {"orderId": outer.orders[mid]["orderId"], "state": "PENDING", "expireAt": 4102444800000, "redirectUrl": "https://mercury-uat.phonepe.com/transact/uat_v2?token=x" + mid})
                if self.path == "/payments/v2/refund":
                    o = outer.orders.get(body.get("originalMerchantOrderId"))
                    if not o or o["state"] != "COMPLETED": return self._send(400, {"code": "BAD_REQUEST"})
                    outer.refunds[body["merchantRefundId"]] = dict(body, refundId="OMR%d" % (len(outer.refunds) + 1), state="PENDING")
                    return self._send(200, {"refundId": outer.refunds[body["merchantRefundId"]]["refundId"], "amount": body["amount"], "state": "PENDING"})
                self._send(404, {"code": "NOT_FOUND"})

            def do_GET(self):
                outer.calls.append(("GET", self.path, {}))
                if not self._auth(): return self._send(401, {"code": "401"})
                parts = urllib.parse.urlparse(self.path).path.strip("/").split("/")
                if parts[:3] == ["checkout", "v2", "order"] and len(parts) == 5 and parts[4] == "status" and parts[3] in outer.orders:
                    o = outer.orders[parts[3]]
                    return self._send(200, {"orderId": o["orderId"], "state": o["state"], "amount": o["amount"], "expireAt": 4102444800000, "paymentDetails": o["paymentDetails"]})
                if parts[:3] == ["payments", "v2", "refund"] and len(parts) == 5 and parts[3] in outer.refunds:
                    r = outer.refunds[parts[3]]
                    return self._send(200, {"merchantRefundId": parts[3], "originalMerchantOrderId": r["originalMerchantOrderId"], "amount": r["amount"], "state": r["state"]})
                self._send(404, {"code": "NOT_FOUND"})

        self.srv = ThreadingHTTPServer(("127.0.0.1", 0), H); self.base = f"http://127.0.0.1:{self.srv.server_address[1]}"
        threading.Thread(target=self.srv.serve_forever, daemon=True).start()

    def pay(self, merchant_order_id, amount=None):
        """The customer pays on PhonePe's page: the order turns COMPLETED with one completed transaction."""
        o = self.orders[merchant_order_id]; tid = "T%d" % (len(self.orders) * 1000 + len(o["paymentDetails"]) + 1)
        o["state"] = "COMPLETED"; o["paymentDetails"].append({"paymentMode": "UPI_QR", "transactionId": tid, "amount": amount or o["amount"], "state": "COMPLETED"})
        if amount: o["amount"] = amount
        return tid

    def fail(self, merchant_order_id): self.orders[merchant_order_id]["state"] = "FAILED"

    def stop(self): self.srv.shutdown()
