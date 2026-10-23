"""Tara AI: writing help powered by Claude. Off by default, owner key never shown back, daily limits per account,
only the chosen text is sent, failures cost the customer nothing, and the privacy policy names Anthropic."""
import http.server, json, threading
NAME = "Tara AI (Claude)"
HITS = []
MODE = {"v": "ok"}


class Fake(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))) or b"{}")
        HITS.append({"path": self.path, "headers": {k.lower(): v for k, v in self.headers.items()}, "body": body})
        if MODE["v"] == "badkey":
            out, code = {"type": "error", "error": {"type": "authentication_error", "message": "invalid x-api-key"}}, 401
        else:
            stop = "refusal" if MODE["v"] == "refusal" else "end_turn"
            text = "" if stop == "refusal" else "Led hiring for 14 retail stores.\nCheck before using: the store count."
            out, code = {"id": "msg_1", "type": "message", "role": "assistant", "model": body.get("model"), "content": ([{"type": "text", "text": text}] if text else []),
                         "stop_reason": stop, "stop_sequence": None, "usage": {"input_tokens": 10, "output_tokens": 10}}, 200
        b = json.dumps(out).encode()
        self.send_response(code); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    srv = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Fake); threading.Thread(target=srv.serve_forever, daemon=True).start()
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"CLAUDE_API_BASE": f"http://127.0.0.1:{srv.server_address[1]}"}).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        u = Client(inst.base); u.register("tara-a@test.local"); u.page("/workspace.html")
        ask = lambda c, **kw: c.api("POST", "ai/suggest", {"task": "bullets", "text": "Responsible for hiring store staff across 14 stores in Pune.", "consent": True, **kw})
        s, j = u.api("GET", "ai/status"); t.check("Tara AI is off by default", s == 200 and j["ai"]["enabled"] is False, j)
        t.check("asking while it is off sends nothing", ask(u)[0] == 422 and HITS == [])

        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        s, g = o.api("POST", "admin/providers", {"service": "ai", "values": {"provider": "claude", "claudeApiKey": "sk-ant-test-1", "model": "", "dailyLimit": "0"}})
        t.check("the owner saves the Claude key", s == 200, (s, str(g)[:200]))
        s, g = o.api("GET", "admin/providers"); t.check("the Claude key is never shown back", "sk-ant-test-1" not in json.dumps(g) and g["providers"]["ai"]["claudeApiKey"] == {"configured": True})
        t.check("a bad model name is refused", o.api("POST", "admin/providers", {"service": "ai", "values": {"model": "gpt 5; drop"}})[0] == 422)
        s, g = o.api("GET", "admin/content/integrations"); d = g["data"]; d["ai"] = True
        t.check("the owner switches Tara AI on", o.api("POST", "admin/content/integrations", {"data": d, "revision": g["revision"]})[0] == 200)

        s, j = u.api("GET", "ai/status"); t.check("a free account sees 3 suggestions a day and every task", j["ai"]["enabled"] is True and j["ai"]["left"] == 3 and len(j["ai"]["tasks"]) == 7, j)
        t.check("the customer must agree to send the text", u.api("POST", "ai/suggest", {"task": "bullets", "text": "Responsible for hiring store staff across Pune."})[0] == 422)
        t.check("an unknown task is refused", ask(u, task="hack")[0] == 422)
        s, j = ask(u); h = HITS[-1] if HITS else {"body": {}, "headers": {}}
        t.check("a suggestion comes back with the count left", s == 200 and "14 retail stores" in j.get("suggestion", "") and j.get("left") == 2, (s, str(j)[:200]))
        t.check("Claude gets the default model, the key and the fallback setting", h["path"].startswith("/v1/messages") and h["body"].get("model") == "claude-opus-5-5" and h["headers"].get("x-api-key") == "sk-ant-test-1" and h["body"].get("fallbacks") == "default" and "server-side-fallback-2026-07-01" in h["headers"].get("anthropic-beta", ""), (h["path"], h["body"].get("model"), h["body"].get("fallbacks"), h["headers"].get("anthropic-beta")))
        t.check("the text goes inside tags with the rules in the system prompt", "<text>" in h["body"]["messages"][0]["content"] and "Never invent" in json.dumps(h["body"].get("system")))
        t.check("job-based tasks send the job description too", ask(u, task="tailor", context="Need Excel and walk-in drives")[0] == 200 and "<job>" in HITS[-1]["body"]["messages"][0]["content"])
        MODE["v"] = "refusal"; s, j = ask(u)
        t.check("a declined request says so and does not use up a suggestion", s == 422 and "could not help" in str(j) and u.api("GET", "ai/status")[1]["ai"]["left"] == 1, (s, str(j)[:200]))
        MODE["v"] = "badkey"; s, j = ask(u)
        t.check("a wrong key gives a plain message, logs it for the owner and costs nothing", s == 422 and "not set up correctly" in str(j) and u.api("GET", "ai/status")[1]["ai"]["left"] == 1 and db.q("select count(*) from error_logs where source='ai'")[0][0] >= 1, (s, str(j)[:200]))
        MODE["v"] = "ok"; ask(u)
        s, j = ask(u); t.check("after 3 a day a free account is told when it refreshes", s == 422 and "tomorrow" in str(j) and "20 a day" in str(j), (s, str(j)[:200]))
        o.api("POST", "admin/providers", {"service": "ai", "values": {"dailyLimit": "1"}})
        v = Client(inst.base); v.register("tara-b@test.local"); v.page("/workspace.html")
        s, j = ask(v); t.check("the site-wide daily limit protects the owner's Claude bill", s == 422 and "busy today" in str(j), (s, str(j)[:200]))
        t.check("a visitor who is not signed in cannot use Tara AI", Client(inst.base).api("POST", "ai/suggest", {"task": "bullets", "text": "x" * 30, "consent": True})[0] == 401)
        ws = u.page("/workspace.html")[2]
        t.check("the workspace loads Tara AI", "/tara-help.js" in ws)
        s, _, priv = Client(inst.base).page("/privacy.html")
        t.check("the privacy policy names Anthropic as the provider for Tara team writing help", "Anthropic" in priv and "Tara team writing help" in priv)
    finally:
        inst.stop(); srv.shutdown()
