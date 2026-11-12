"""AI cost cuts (3.64.0): customer Tara chat runs on the low-cost chat model, customer writing and the Business assistant
on the writing model, routine jobs on the quick model, each at a set thinking effort; Tara's fixed rules and tools are
sent as a cached block with the changing part after it; cached tokens are recorded and costed at their own price; long
tool results are cut; the chat shows "CVTara team is typing"."""
import http.server, json, os, threading
NAME = "AI cost cuts: models per tier, prompt caching, caps"
HITS = []
SCRIPT = []


class Fake(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))) or b"{}")
        HITS.append({"headers": {k.lower(): v for k, v in self.headers.items()}, "body": body})
        step = SCRIPT.pop(0) if SCRIPT else ("text", "Happy to help.\nCheck before using: the dates.")
        usage = {"input_tokens": 100, "output_tokens": 50, "cache_read_input_tokens": 100000, "cache_creation_input_tokens": 0}
        if step[0] == "tool":
            content, stop = [{"type": "tool_use", "id": "tu_%d" % len(HITS), "name": step[1], "input": step[2]}], "tool_use"
        else:
            content, stop = [{"type": "text", "text": step[1]}], "end_turn"
        b = json.dumps({"id": "msg_1", "type": "message", "role": "assistant", "model": body.get("model"), "content": content, "stop_reason": stop, "stop_sequence": None, "usage": usage}).encode()
        self.send_response(200); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    srv = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Fake); threading.Thread(target=srv.serve_forever, daemon=True).start()
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"CLAUDE_API_BASE": f"http://127.0.0.1:{srv.server_address[1]}"}).start()
    php = lambda code: inst.artisan("tinker", "--execute=" + code)
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        t.check("a bad chat model name is refused", o.api("POST", "admin/providers", {"service": "ai", "values": {"chatModel": "haiku; drop"}})[0] == 422)
        o.api("POST", "admin/providers", {"service": "ai", "values": {"provider": "claude", "claudeApiKey": "sk-ant-test-76", "model": "", "chatModel": "", "quickModel": "", "dailyLimit": "0"}})
        s, g = o.api("GET", "admin/content/integrations"); d = g["data"]; d["ai"] = True
        o.api("POST", "admin/content/integrations", {"data": d, "revision": g["revision"]})
        all_tools = list(o.api("GET", "admin/tara")[1]["tools"].keys())
        base = {"enabled": True, "model": "", "instructions": "", "freeDaily": 10, "paidDaily": 60, "siteDaily": 2000, "dailySpendInr": 300, "monthlySpendInr": 5000, "maxTokens": 1500, "historyMessages": 10, "maxSteps": 4, "tools": all_tools, "handoff": "Our team will reply by email within one working day.", "suggestions": True}
        s, j = o.api("POST", "admin/tara/settings", base); t.check("Tara is switched on", s == 200, (s, str(j)[:200]))

        u = Client(inst.base); u.register("cost-a@test.local"); u.page("/workspace.html")
        SCRIPT[:] = [("tool", "product_guide", {}), ("tool", "account_overview", {}), ("text", "You are on the Free plan.")]
        n = len(HITS); s, j = u.api("POST", "tara/message", {"text": "What plan am I on?"})
        reqs = [h["body"] for h in HITS[n:]]
        t.check("Tara answers after two tool steps", s == 200 and "Free plan" in j["message"]["content"] and len(reqs) == 3, (s, str(j)[:200], len(reqs)))
        first = reqs[0] if reqs else {}
        t.check("customer Tara chat runs on the low-cost chat model (claude-haiku-5-5) at low effort", all(r.get("model") == "claude-haiku-5-5" and r.get("output_config", {}).get("effort") == "low" for r in reqs), [(r.get("model"), r.get("output_config")) for r in reqs])
        sysb = first.get("system") or []
        t.check("the fixed rules are one cached block, with today's date and the name after it",
                isinstance(sysb, list) and len(sysb) == 2 and sysb[0].get("cache_control", {}).get("type") == "ephemeral" and "Never say you are an AI" in sysb[0]["text"] and "Today is" not in sysb[0]["text"] and "Today is" in sysb[1]["text"] and "cache_control" not in sysb[1], json.dumps(sysb)[:300])
        t.check("every step of one answer sends the identical cached block (so steps 2 and 3 read it from cache)", len({json.dumps(r.get("system", [None])[0], sort_keys=True) for r in reqs}) == 1)
        t.check("Haiku chat requests carry no refusal-fallback setting", all("fallbacks" not in r for r in reqs))
        rows = db.q("select model, cache_read_tokens, cache_write_tokens, cost_paise from ai_usage where purpose='customer:tara_chat' order by id desc limit 1")
        t.check("cached input tokens are recorded with each request", rows and rows[0][0] == "claude-haiku-5-5" and rows[0][1] == 100000 and rows[0][2] == 0, rows)
        t.check("100,000 cached tokens on the chat model cost about 10 paise (the same request uncached on Opus: about Rs 35)", rows and rows[0][3] <= 15, rows)

        # Long tool results are cut before they are re-sent on every later step (the customer guide keeps its own limit).
        php("Illuminate\\Support\\Facades\\DB::table('tara_events')->delete();")
        res = [b for h in HITS[n:] for m in h["body"].get("messages", []) if isinstance(m.get("content"), list) for b in m["content"] if b.get("type") == "tool_result"]
        guide = [b for b in res if len(json.dumps(b.get("content"))) > 6500]
        t.check("the customer guide is still given whole (it is the how-to source)", len(guide) >= 1, [len(json.dumps(b.get("content"))) for b in res])

        # Writing (customer writing help) uses the writing model at medium effort; the owner's box wins.
        u.token()
        n = len(HITS); st, hd, b = u.raw("POST", "/api/ai/suggest", {"task": "rewrite", "text": "Responsible for hiring store staff across 14 stores in Pune.", "consent": True}, {"Accept": "application/json", "X-CSRF-TOKEN": u.csrf or ""})
        h = HITS[-1]["body"] if len(HITS) > n else {}
        t.check("customer writing runs on the writing model (claude-sonnet-5-5) at medium effort with the refusal fallback", st == 200 and h.get("model") == "claude-sonnet-5-5" and h.get("output_config", {}).get("effort") == "medium" and h.get("fallbacks") == "default", (st, b[:300], h.get("model"), h.get("output_config"), h.get("fallbacks")))
        rows = db.q("select cost_paise from ai_usage where purpose='customer:writing_help' order by id desc limit 1")
        t.check("cached tokens are costed at the cache-read price (100,000 on Sonnet = $0.01, under Rs 2 in total)", rows and 0 < rows[0][0] < 200, rows)

        # Quick jobs: the SEO title writer gets the quick model, low effort and enough room to finish.
        n = len(HITS)
        r = php("var_dump(App\\Services\\SeoAgent::draftMeta(['title' => 'Resume builder', 'description' => 'Build a resume.'], ['free resume builder']));")
        h = HITS[-1]["body"] if len(HITS) > n else {}
        t.check("an SEO title goes to the quick model at low effort with room to finish (1500 tokens)", h.get("model") == "claude-haiku-5-5" and h.get("output_config", {}).get("effort") == "low" and h.get("max_tokens") == 1500, (r.stdout[-300:], r.stderr[-300:], h.get("model"), h.get("output_config"), h.get("max_tokens")))

        # The owner can choose each tier.
        o.api("POST", "admin/providers", {"service": "ai", "values": {"chatModel": "claude-sonnet-5-5"}})
        n = len(HITS); u.api("POST", "tara/message", {"text": "Thanks"})
        t.check("the owner's Tara chat model choice wins", len(HITS) > n and HITS[-1]["body"].get("model") == "claude-sonnet-5-5", HITS[-1]["body"].get("model"))
        o.api("POST", "admin/providers", {"service": "ai", "values": {"chatModel": ""}})
        n = len(HITS); u.api("POST", "tara/message", {"text": "Thanks again"})
        t.check("emptying the box goes back to the low-cost chat model", HITS[-1]["body"].get("model") == "claude-haiku-5-5", HITS[-1]["body"].get("model"))

        # Business assistant: writing model, cached rules, the time after them.
        n = len(HITS); s, j = o.api("POST", "admin/tara/owner/message", {"text": "What is today's revenue?"})
        h = HITS[-1]["body"] if len(HITS) > n else {}
        sysb = h.get("system") or []
        t.check("the Business assistant runs on the writing model with its rules cached and the time after them",
                s == 200 and h.get("model") == "claude-sonnet-5-5" and len(sysb) == 2 and sysb[0].get("cache_control") and "Now:" in sysb[1]["text"] and "Now:" not in sysb[0]["text"], (s, str(j)[:300], h.get("model"), json.dumps(sysb)[:200]))

        js = open(os.path.join(ctx.public, "tara-chat.js"), encoding="utf8").read()
        t.check("while waiting, the chat shows 'CVTara team is typing…'", "CVTara team is typing" in js)
        ad = open(os.path.join(ctx.app, "resources", "console", "advanced.js"), encoding="utf8").read()
        t.check("Integrations > AI has a box for each model tier", "chatModel:[" in ad and "quickModel:[" in ad and "mid-priced" in ad)
    finally:
        inst.stop(); srv.shutdown()
