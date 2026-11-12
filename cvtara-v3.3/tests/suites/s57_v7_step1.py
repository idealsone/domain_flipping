"""V7 step 1 (3.48.0): short routine AI tasks can run on a cheaper "quick-task model" while customer writing keeps the
main model, each request records which prompt wording it used and is costed at its own model's price, and Tara Expert
Review no longer says a person reviews the resume (it is written automatically)."""
import http.server, json, os, threading, hashlib
NAME = "V7 step 1: quick-task model and honest review wording"
HITS = []


class Fake(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))) or b"{}")
        HITS.append({"headers": {k.lower(): v for k, v in self.headers.items()}, "body": body})
        out = {"id": "msg_1", "type": "message", "role": "assistant", "model": body.get("model"), "content": [{"type": "text", "text": "Hired staff for 14 stores.\nCheck before using: the count."}],
               "stop_reason": "end_turn", "stop_sequence": None, "usage": {"input_tokens": 1000, "output_tokens": 1000}}
        b = json.dumps(out).encode()
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
        t.check("a bad quick-task model name is refused", o.api("POST", "admin/providers", {"service": "ai", "values": {"quickModel": "haiku; drop"}})[0] == 422)
        s, g = o.api("POST", "admin/providers", {"service": "ai", "values": {"provider": "claude", "claudeApiKey": "sk-ant-test-1", "model": "", "quickModel": "claude-haiku-5-5", "dailyLimit": "0"}})
        t.check("the owner saves a quick-task model", s == 200, (s, str(g)[:200]))
        s, g = o.api("GET", "admin/content/integrations"); d = g["data"]; d["ai"] = True
        o.api("POST", "admin/content/integrations", {"data": d, "revision": g["revision"]})

        u = Client(inst.base); u.register("v7-a@test.local"); u.page("/workspace.html")
        s, j = u.api("POST", "ai/suggest", {"task": "bullets", "text": "Responsible for hiring store staff across 14 stores in Pune.", "consent": True})
        h = HITS[-1] if HITS else {"body": {}, "headers": {}}
        t.check("customer writing still uses the main model with the refusal fallback", s == 200 and h["body"].get("model") == "claude-sonnet-5-5" and h["body"].get("fallbacks") == "default", (s, h["body"].get("model"), h["body"].get("fallbacks")))

        n = len(HITS)
        r = php("echo App\\Services\\TaraAI::ask('Write one SEO title.', 'Page: resume builder', 200, 20, 'seo');")
        h = HITS[-1] if len(HITS) > n else {"body": {}, "headers": {}}
        t.check("an SEO title goes to the quick-task model", len(HITS) > n and h["body"].get("model") == "claude-haiku-5-5", (r.stdout[-200:], r.stderr[-300:], h["body"].get("model")))
        t.check("Haiku requests are sent without the fallback setting it does not support", "fallbacks" not in h["body"] and "server-side-fallback" not in h["headers"].get("anthropic-beta", ""), (h["body"].get("fallbacks"), h["headers"].get("anthropic-beta")))

        pv = hashlib.sha1(b"Write one SEO title.").hexdigest()[:10]
        row = db.q("select prompt_version, model, cost_paise from ai_usage where purpose='seo' order by id desc limit 1")
        t.check("the request records which prompt wording it used", row and row[0][0] == pv and row[0][1] == "claude-haiku-5-5", row)
        opus = db.q("select cost_paise from ai_usage where purpose='customer:writing_help' order by id desc limit 1")
        # 1,000 + 1,000 tokens: Haiku 5.5 $0.60 per million pairs vs Sonnet 5.5 $12 (the writing model since 3.64.0), at ₹88 a dollar
        t.check("each request is costed at its own model's price", row and opus and row[0][2] == 6 and opus[0][0] == 106, (row, opus))

        o.api("POST", "admin/providers", {"service": "ai", "values": {"quickModel": " "}})
        r = php("echo App\\Services\\AiGateway::model(null, 'seo');")
        t.check("routine tasks can still be sent to the main model", "claude" in r.stdout, r.stdout[-200:])

        # Honest wording for Tara Expert Review
        r = php("echo App\\Services\\Settings::plans()['expert-review']['tagline'];")
        t.check("the Expert Review tagline no longer says a person reviews the resume", "instant written review" in r.stdout.lower() and "team reviews" not in r.stdout, r.stdout[-200:])
        old = "The CVTara team reviews your whole resume and sends you a written report"
        mig = os.path.join("database", "migrations", "2026_11_16_000002_expert_review_wording.php")
        php("DB::table('site_settings')->updateOrInsert(['key' => 'plans'], ['value_json' => json_encode(['expert-review' => ['tagline' => '" + old + "', 'sale' => true]]), 'revision' => 1, 'updated_at' => now(), 'created_at' => now()]);")
        php("(require '" + mig + "')->up();")
        saved = json.loads(db.q("select value_json from site_settings where key='plans'")[0][0])
        t.check("a saved copy of the old tagline is updated", saved["expert-review"]["tagline"].startswith("An instant written review") and saved["expert-review"]["sale"] is True, saved)
        php("DB::table('site_settings')->where('key', 'plans')->update(['value_json' => json_encode(['expert-review' => ['tagline' => 'My own words']])]);")
        php("(require '" + mig + "')->up();")
        saved = json.loads(db.q("select value_json from site_settings where key='plans'")[0][0])
        t.check("a tagline the owner wrote is left alone", saved["expert-review"]["tagline"] == "My own words", saved)

        src = open(os.path.join(ctx.app, "app", "Services", "ExpertReview.php"), encoding="utf8").read()
        t.check("the review prompt never presents the review as a person's work", "senior resume reviewer" not in src and "never present it as written by a person" in src and "Prepared by the CVTara team" not in src)
        defaults = json.load(open(os.path.join(ctx.app, "resources", "data", "defaults.json"), encoding="utf8"))
        mail = [x for x in defaults["emails"] if x.get("id") == "expert-review"][0]
        t.check("the delivery email says the review was prepared automatically", "prepared automatically" in mail["body"] and "The CVTara team prepared it" not in mail["body"])
        js = open(os.path.join(ctx.public, "checkout-options.js"), encoding="utf8").read()
        t.check("checkout describes the review as instant, not done by the team", "Prepared by the CVTara team" not in js and "Instant written review" in js)
    finally:
        inst.stop(); srv.shutdown()
