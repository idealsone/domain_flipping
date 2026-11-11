"""V7 step 3 (3.51.0): one assistant and Profile Boost as a draft the customer approves.
- Writing help (clearer wording, bullets, summary, tailoring, letter draft, job check, interview questions) is a Tara chat
  action; the separate pop-up is no longer loaded. It uses the same daily writing allowance and saves nothing.
- A Profile Boost order gets an automatic draft from the customer's resume; the customer approves it or asks for one
  round of changes; the owner can still finish it by hand; wording never mentions AI or claims a person wrote it."""
import http.server, json, os, re, threading, time
NAME = "V7 step 3: writing help in Tara chat, Profile Boost drafts"
HITS = []
DRAFT = "## LinkedIn headline\nStore Manager | Retail operations | Hiring and training store teams\n\n## Key skills\n- Store operations\n- Hiring\nCheck before using: the store count."


class Fake(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))) or b"{}")
        HITS.append(body)
        sysp = json.dumps(body.get("system", ""))
        msgs = body.get("messages", [])
        if "Profile Boost drafts" in sysp:
            text = DRAFT.replace("Store Manager |", "Retail Store Manager |") if "<changes>" in json.dumps(msgs) else DRAFT
            content, stop = [{"type": "text", "text": text}], "end_turn"
        elif body.get("tools") and not any(isinstance(m.get("content"), list) and any(c.get("type") == "tool_result" for c in m["content"]) for m in msgs):
            content, stop = [{"type": "tool_use", "id": "tu_1", "name": "writing_help", "input": {"task": "bullets", "text": "Responsible for hiring store staff across 14 stores in Pune and training them."}}], "tool_use"
        elif body.get("tools"):
            content, stop = [{"type": "text", "text": "Here are your bullets. Check every fact before you use them."}], "end_turn"
        else:
            content, stop = [{"type": "text", "text": "- Hired and trained store staff across 14 stores in Pune."}], "end_turn"
        out = {"id": "msg_1", "type": "message", "role": "assistant", "model": body.get("model"), "content": content, "stop_reason": stop, "stop_sequence": None, "usage": {"input_tokens": 10, "output_tokens": 10}}
        b = json.dumps(out).encode()
        self.send_response(200); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)


def resume(name):
    return {"version": "1", "title": "Primary", "template": "suite-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": name, "email": "x@test.local", "role": "Store Manager"},
            "summary": "Retail store manager with six years of experience running busy stores in Pune and hiring and training store teams.",
            "order": ["summary", "experience"], "experience": [{"id": "1", "role": "Store Manager", "company": "Sample Retail", "start": "2020-01", "current": True, "bullets": "Responsible for hiring store staff across 14 stores.\nHandled stock and daily cash."}],
            "education": [], "projects": [], "certifications": []}


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    srv = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Fake); threading.Thread(target=srv.serve_forever, daemon=True).start()
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"CLAUDE_API_BASE": f"http://127.0.0.1:{srv.server_address[1]}"}).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        uid = lambda e: db.q("select id from users where email=?", e)[0][0]
        cron = lambda: inst.artisan("cvtara:cron")
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        o.api("POST", "admin/providers", {"service": "ai", "values": {"provider": "claude", "claudeApiKey": "sk-ant-test-1", "model": "", "dailyLimit": "0"}})
        s, g = o.api("GET", "admin/content/integrations"); d = g["data"]; d["ai"] = True
        o.api("POST", "admin/content/integrations", {"data": d, "revision": g["revision"]})

        # ---- One assistant
        s, ts = o.api("GET", "admin/tara")
        t.check("writing help is one of Tara's actions", s == 200 and "writing_help" in ts["tools"] and "writing_help" in ts["settings"]["tools"], ts.get("settings", {}).get("tools"))
        st = ts["settings"]; st["enabled"] = True; st["tools"] = [k for k in ts["tools"] if k != "writing_help"]
        o.api("POST", "admin/tara/settings", st)
        t.check("the owner can turn writing help off in Tara", "writing_help" not in o.api("GET", "admin/tara")[1]["settings"]["tools"])
        st["tools"] = list(ts["tools"]); o.api("POST", "admin/tara/settings", st)
        php = "App\\Services\\Settings::put('tara', array_merge((array) App\\Services\\Settings::get('tara', []), ['tools' => ['account_overview', 'list_documents'], 'toolsKnown' => []]));"
        inst.artisan("tinker", "--execute=" + php)
        t.check("an owner list saved before 3.51 gets writing help switched on", "writing_help" in o.api("GET", "admin/tara")[1]["settings"]["tools"])
        st["tools"] = list(ts["tools"]); o.api("POST", "admin/tara/settings", st)

        c = Client(inst.base); c.register("v7s3-a@test.local"); c.page("/workspace.html")
        c.api("POST", "resumes", {"data": resume("Asha")})
        left0 = c.api("GET", "ai/status")[1]["ai"]["left"]
        n = len(HITS)
        s, j = c.api("POST", "tara/message", {"text": "Turn my duties into bullets: Responsible for hiring store staff across 14 stores in Pune."})
        used = db.q("select tool, status from tara_tool_calls where owner_id=? order by created_at", uid("v7s3-a@test.local"))
        t.check("Tara writes from the customer's text with the writing_help action", s == 200 and ("writing_help", "ok") in [tuple(x) for x in used], (s, str(j)[:200], used))
        t.check("it uses one of the day's writing suggestions", c.api("GET", "ai/status")[1]["ai"]["left"] == left0 - 1)
        sug = [h for h in HITS[n:] if "<text>" in json.dumps(h.get("messages", []))]
        t.check("the text goes to the writing model inside tags", sug and "Responsible for hiring" in json.dumps(sug[0]["messages"]))

        s, _, ws = c.page("/workspace.html")
        t.check("the workspace no longer loads the separate writing pop-up", "/tara-help.js" not in ws and "/tara-chat.js" in ws)
        old = Client(inst.base).raw("GET", "/tara-help.js")[2].decode("utf8", "ignore")
        t.check("an old cached call to the pop-up opens Tara chat instead", "CVTaraAssist" in old and "/api/ai/suggest" not in old)

        # ---- Profile Boost draft
        s, pl = o.api("GET", "admin/plans")
        o.api("POST", "admin/plans", {"plans": {"profile-boost": {**pl["plans"]["profile-boost"], "sale": True}}})
        t.check("the Profile Boost card promises a draft to approve, not a person's work", "A draft you approve, with one round of changes" in o.api("GET", "admin/plans")[1]["plans"]["profile-boost"]["perks"])
        b = Client(inst.base); b.register("v7s3-b@test.local"); b.page("/workspace.html")
        b.api("POST", "resumes", {"data": resume("Bina")})
        s, j = b.api("POST", "checkout/start", {"plan": "profile-boost"})
        b.api("POST", "checkout/verify", {"provider": "demo", "orderId": j["orderId"]})
        order = lambda: db.q("select id, status, reviewer, review, note from service_orders where owner_id=? and service='profile-boost'", uid("v7s3-b@test.local"))[0]
        for _ in range(40):
            if order()[2] == "draft": break
            time.sleep(0.25)
        else:
            cron()
        oid, st_, rv, txt, _ = order()
        t.check("a draft is written from the resume after payment", st_ == "in_review" and rv == "draft" and "LinkedIn headline" in (txt or ""), (st_, rv))
        pb = [h for h in HITS if "Profile Boost drafts" in json.dumps(h.get("system", ""))][0]
        sysp = json.dumps(pb["system"])
        t.check("the draft request forbids invented facts, AI mentions and a person's byline", "Never invent" in sysp and "do not mention AI" in sysp and "never present it as written by a person" in sysp and "Sample Retail" in json.dumps(pb["messages"]))
        sv = [x for x in b.api("GET", "purchases")[1]["services"] if x["service"] == "profile-boost"][0]
        t.check("the customer sees the draft with approve and change buttons", sv["draftState"] == "draft" and sv["canApprove"] and sv["canRevise"] and "LinkedIn headline" in sv["draft"], sv)
        t.check("someone else cannot approve it", c.api("POST", f"service-orders/{oid}/approve", {})[0] == 409)
        t.check("the customer is told the draft is ready", db.q("select count(*) from notifications where owner_id=? and kind='profile_boost'", uid("v7s3-b@test.local"))[0][0] == 1)
        t.check("a short change request is refused", b.api("POST", f"service-orders/{oid}/revise", {"request": "more"})[0] == 422)
        s, j = b.api("POST", f"service-orders/{oid}/revise", {"request": "Please make the headline about retail store management."})
        t.check("the customer asks for one round of changes", s == 200, (s, str(j)[:200]))
        for _ in range(40):
            if order()[2] == "draft2": break
            time.sleep(0.25)
        else:
            cron()
        oid, st_, rv, txt, note = order()
        t.check("the second draft follows the request", rv == "draft2" and "Retail Store Manager" in txt and "retail store management" in note, (rv, note))
        sv = [x for x in b.api("GET", "purchases")[1]["services"] if x["service"] == "profile-boost"][0]
        t.check("only one round of changes is offered", sv["draftState"] == "final_draft" and not sv["canRevise"] and b.api("POST", f"service-orders/{oid}/revise", {"request": "Change it once more please, thank you."})[0] == 409)
        s, j = b.api("POST", f"service-orders/{oid}/approve", {})
        sv = [x for x in j.get("services", []) if x["service"] == "profile-boost"][0] if s == 200 else {}
        t.check("approving delivers the order with the approved text", s == 200 and sv.get("status") == "delivered" and "Retail Store Manager" in (sv.get("review") or "") and order()[1] == "delivered", (s, sv))

        # Owner switch: drafts off -> the order waits for the owner, as before.
        o.api("POST", "admin/services/settings", {"autoReview": True, "autoProfileBoost": False})
        e = Client(inst.base); e.register("v7s3-c@test.local"); e.page("/workspace.html"); e.api("POST", "resumes", {"data": resume("Chitra")})
        s, j = e.api("POST", "checkout/start", {"plan": "profile-boost"}); e.api("POST", "checkout/verify", {"provider": "demo", "orderId": j["orderId"]})
        cron()
        t.check("with automatic drafts off the order waits for the owner", db.q("select status from service_orders where owner_id=? and service='profile-boost'", uid("v7s3-c@test.local"))[0][0] == "not_started")

        js = open(os.path.join(ctx.public, "workspace-activity.js"), encoding="utf8").read()
        t.check("the customer page no longer promises a person within 48 hours", "within 48 working hours" not in js and not re.search(r"\bAI\b", js))
    finally:
        inst.stop(); srv.shutdown()
