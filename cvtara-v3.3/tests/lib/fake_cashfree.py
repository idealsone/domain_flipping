"""A tiny stand-in for the Cashfree order API so payment flows can be tested without a network or real money."""
import json, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


class FakeCashfree:
    def __init__(self):
        self.orders, self.payments, self.refunds, self.calls = {}, {}, {}, []
        outer = self

        class H(BaseHTTPRequestHandler):
            def log_message(self, *a): pass

            def _send(self, code, obj):
                b = json.dumps(obj).encode(); self.send_response(code); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)

            def do_POST(self):
                n = int(self.headers.get("Content-Length", 0)); body = json.loads(self.rfile.read(n) or b"{}")
                outer.calls.append(("POST", self.path, dict(self.headers)))
                if self.path == "/orders":
                    if not self.headers.get("x-client-id") or not self.headers.get("x-client-secret"): return self._send(401, {"message": "auth"})
                    o = dict(body, order_status="ACTIVE", payment_session_id="session_" + body["order_id"]); outer.orders[body["order_id"]] = o
                    return self._send(200, o)
                if self.path.startswith("/__pay/"):
                    oid = self.path.split("/")[-1]; o = outer.orders[oid]; o["order_status"] = "PAID"
                    outer.payments[oid] = [{"payment_status": "SUCCESS", "payment_currency": "INR", "payment_amount": o["order_amount"], "cf_payment_id": 9000 + len(outer.payments)}]
                    return self._send(200, {})
                if self.path.startswith("/__refund/"):
                    _, _, oid, amt = self.path.split("/"); outer.refunds.setdefault(oid, []).append({"cf_refund_id": "r%d" % len(outer.refunds.get(oid, [])), "refund_status": "SUCCESS", "refund_amount": float(amt)})
                    return self._send(200, {})
                self._send(404, {})

            def do_GET(self):
                outer.calls.append(("GET", self.path, dict(self.headers)))
                parts = self.path.strip("/").split("/")
                if parts[0] == "orders" and len(parts) >= 2 and parts[1] in outer.orders:
                    oid = parts[1]
                    if len(parts) == 2: return self._send(200, dict(outer.orders[oid], order_id=oid))
                    if parts[2] == "payments": return self._send(200, outer.payments.get(oid, []))
                    if parts[2] == "refunds": return self._send(200, outer.refunds.get(oid, []))
                self._send(404, {"message": "order_not_found"})

        self.srv = ThreadingHTTPServer(("127.0.0.1", 0), H); self.base = f"http://127.0.0.1:{self.srv.server_address[1]}"
        threading.Thread(target=self.srv.serve_forever, daemon=True).start()

    def pay(self, order_id):
        import urllib.request; urllib.request.urlopen(urllib.request.Request(self.base + "/__pay/" + order_id, data=b"{}", method="POST"))

    def refund(self, order_id, amount):
        import urllib.request; urllib.request.urlopen(urllib.request.Request(f"{self.base}/__refund/{order_id}/{amount}", data=b"{}", method="POST"))

    def stop(self): self.srv.shutdown()
