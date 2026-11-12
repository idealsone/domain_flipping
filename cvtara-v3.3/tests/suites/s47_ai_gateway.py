"""AI gateway and AI health (3.38.0): one path to Claude, every request recorded with its outcome, time and request
reference; failures give customers a plain message and turn the AI health red for the owner."""
import http.server, json, threading, time
NAME = "AI gateway and AI health"
MODE = {"v": "ok"}
HITS = []


class Fake(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))) or b"{}")
        HITS.append(body)
        v = MODE["v"]
        if v == "slow": time.sleep(4)
        if v == "badkey": out, code = {"type": "error", "error": {"type": "authentication_error", "message": "invalid x-api-key"}}, 401
        elif v == "500": out, code = {"type": "error", "error": {"type": "api_error", "message": "boom"}}, 500
        elif v == "429": out, code = {"type": "error", "error": {"type": "rate_limit_error", "message": "slow down"}}, 429
        else:
            text = "" if v == "empty" else "Hired staff for 14 stores.\nCheck before using: the store count."
            out, code = {"id": "msg_1", "type": "message", "role": "assistant", "model": body.get("model"), "content": [{"type": "text", "text": text}] if text else [],
                         "stop_reason": "end_turn", "stop_sequence": None, "usage": {"input_tokens": 100, "output_tokens": 50}}, 200
        b = json.dumps(out).encode()
        try:
            self.send_response(code); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)
        except (BrokenPipeError, ConnectionResetError): pass


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    srv = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Fake); threading.Thread(target=srv.serve_forever, daemon=True).start()
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"CLAUDE_API_BASE": f"http://127.0.0.1:{srv.server_address[1]}", "AI_TIMEOUT": "2"}).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        o.api("POST", "admin/providers", {"service": "ai", "values": {"provider": "claude", "claudeApiKey": "sk-ant-test-47", "model": "", "dailyLimit": "0"}})
        s, g = o.api("GET", "admin/content/integrations"); d = g["data"]; d["ai"] = True
        o.api("POST", "admin/content/integrations", {"data": d, "revision": g["revision"]})
        u = Client(inst.base); u.register("gw-a@test.local"); u.page("/workspace.html")

        def ask(c=None):
            c = c or u; c.token()
            st, hd, b = c.raw("POST", "/api/ai/suggest", {"task": "rewrite", "text": "Responsible for hiring store staff across 14 stores in Pune.", "consent": True}, {"Accept": "application/json", "X-CSRF-TOKEN": c.csrf or ""})
            try: j = json.loads(b)
            except ValueError: j = {}
            return st, hd.get("X-Request-Id", ""), j

        last = lambda: db.q("select purpose, status, latency_ms, request_id, model, input_tokens, error from ai_usage order by id desc limit 1")[0]
        s, rid, j = ask(); row = last()
        t.check("a successful request is recorded as ok with its time, tokens and the request reference", s == 200 and row[1] == "ok" and row[2] is not None and row[3] == rid and row[5] == 100 and row[0] == "customer:writing_help", (s, row, rid))
        t.check("the model comes from config/ai.php when the owner left it blank", HITS[-1].get("model") == "claude-sonnet-5-5" and row[4] == "claude-sonnet-5-5", HITS[-1].get("model"))
        o.api("POST", "admin/providers", {"service": "ai", "values": {"model": "claude-opus-5-5"}})
        ask(); t.check("the owner's model choice wins", HITS[-1].get("model") == "claude-opus-5-5", HITS[-1].get("model"))
        o.api("POST", "admin/providers", {"service": "ai", "values": {"model": ""}})

        plain = lambda j: all(w not in json.dumps(j) for w in ("Claude", "Anthropic", "HTTP", "api_error", "exception", "Exception"))
        for i, (mode, status) in enumerate((("500", "error"), ("429", "rate_limit"), ("badkey", "auth"), ("empty", "empty"), ("slow", "timeout"))):
            c = Client(inst.base); c.register(f"gw-{i}@test.local"); c.page("/workspace.html")
            MODE["v"] = mode; s, rid, j = ask(c); row = last()
            t.check(f"{mode}: the customer gets a plain message and the request is recorded as '{status}'", s in (422, 429, 500, 503) and plain(j) and row[1] == status and row[3] == rid and row[2] is not None, (s, str(j)[:160], row))
        MODE["v"] = "ok"
        t.check("a refused key is reported in the Error Log", db.q("select count(*) from error_logs where message like '%Claude API key was refused%'")[0][0] >= 1)
        t.check("requests that never got an answer cost nothing", db.q("select coalesce(sum(cost_paise),0) from ai_usage where status in ('error','rate_limit','auth','timeout')")[0][0] == 0)
        t.check("failed requests did not use up the customer's suggestions", c.api("GET", "ai/status")[1]["ai"]["left"] == 3)
        t.check("the timeout came from config/ai.php (2 s), not the old fixed 50 s", db.q("select latency_ms from ai_usage where status='timeout'")[0][0] < 10000)

        s, h = o.api("GET", "admin/health/history"); a = h.get("ai", {})
        t.check("Setup & health shows AI requests, success rate, 95% time and the last problem", s == 200 and a.get("calls", 0) >= 7 and a.get("successPct") is not None and a.get("p95Ms") is not None and a["lastError"]["status"] in ("timeout", "empty", "auth", "rate_limit", "error"), str(a)[:300])
        t.check("each assistant is listed separately", "customer:writing_help" in a.get("assistants", {}))
        # 25 more failures in the last day: the service is failing; the hourly watch writes one warning.
        for i in range(25): db.q("insert into ai_usage(purpose, model, input_tokens, output_tokens, cost_paise, status, latency_ms, request_id, error, created_at) values('customer:tara_chat','',0,0,0,'error',900,'X','boom',datetime('now'))")
        s, h = o.api("GET", "admin/health/history")
        t.check("over 20 requests with under 80% success turns the AI health red", h["ai"]["status"] == "failing" and h["today"]["checks"]["aiService"] is False, h["ai"]["status"])
        inst.artisan("tinker", "--execute", "App\\Services\\AiGateway::watch(); Illuminate\\Support\\Facades\\Cache::forget('ai-health-watch'); App\\Services\\AiGateway::watch();")
        n = db.q("select count(*), max(occurrences) from error_logs where message like 'The AI service is failing%'")[0]
        t.check("the hourly watch reports it once in the Error Log (repeats count up, no flood)", n[0] == 1 and n[1] >= 2, n)
        s2, h2 = Client(inst.base).api("GET", "admin/health/history")
        t.check("customers cannot read AI health", s2 in (401, 403))
        t.check("the old fixed model constant is gone from the code", "const MODEL" not in open(ctx.app + "/app/Services/TaraAI.php").read())
        import glob, re
        direct = [f for f in glob.glob(ctx.app + "/app/**/*.php", recursive=True) if not f.endswith("AiGateway.php") and re.search(r"new\s+(\\\\?Anthropic\\\\)?Client\(|api\.anthropic\.com", open(f).read())]
        t.check("nothing but the gateway creates a Claude client", direct == [], direct)
    finally:
        inst.stop(); srv.shutdown()
