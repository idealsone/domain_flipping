"""A tiny stand-in for the Razorpay orders and payments API so payment flows can be tested without a network or real money."""
import base64, json, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


class FakeRazorpay:
    def __init__(self):
        self.orders, self.payments, self.refunds, self.calls = {}, {}, [], []
        outer = self

        class H(BaseHTTPRequestHandler):
            def log_message(self, *a): pass

            def _send(self, code, obj):
                b = json.dumps(obj).encode(); self.send_response(code); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)

            def _auth(self):
                h = self.headers.get("Authorization", "")
                return h.startswith("Basic ") and ":" in base64.b64decode(h[6:]).decode()

            def do_POST(self):
                n = int(self.headers.get("Content-Length", 0)); raw = self.rfile.read(n) or b"{}"
                body = json.loads(raw) if raw.strip().startswith(b"{") else {}
                outer.calls.append(("POST", self.path, body))
                if not self._auth(): return self._send(401, {"error": {"description": "auth"}})
                if self.path == "/orders":
                    oid = "order_%d" % (len(outer.orders) + 1); outer.orders[oid] = dict(body, id=oid, status="created")
                    return self._send(200, outer.orders[oid])
                parts = self.path.strip("/").split("/")
                if parts[0] == "payments" and len(parts) == 3 and parts[2] == "refund" and parts[1] in outer.payments:
                    r = dict(body, id="rfnd_%d" % (len(outer.refunds) + 1), payment_id=parts[1]); outer.refunds.append(r)
                    return self._send(200, r)
                self._send(404, {"error": {"description": "not found"}})

            def do_GET(self):
                outer.calls.append(("GET", self.path, {}))
                if not self._auth(): return self._send(401, {})
                parts = self.path.strip("/").split("/")
                if parts[0] == "payments" and len(parts) == 2 and parts[1] in outer.payments: return self._send(200, outer.payments[parts[1]])
                self._send(404, {"error": {"description": "not found"}})

        self.srv = ThreadingHTTPServer(("127.0.0.1", 0), H); self.base = f"http://127.0.0.1:{self.srv.server_address[1]}"
        threading.Thread(target=self.srv.serve_forever, daemon=True).start()

    def pay(self, order_id):
        """The customer pays in the Razorpay window: a captured payment for the order's full amount."""
        pid = "pay_%d" % (len(self.payments) + 1); o = self.orders[order_id]
        self.payments[pid] = {"id": pid, "order_id": order_id, "amount": o["amount"], "currency": o["currency"], "status": "captured"}
        return pid

    def stop(self): self.srv.shutdown()
