"""Tara chat: off until the owner switches it on, answers through the customer's own API (account, ATS check, tickets),
never reaches another customer's documents, failures cost nothing, daily limits, conversations, ratings, owner console."""
import http.server, json, os, threading
NAME = "Tara chat assistant"
HITS = []
SCRIPT = []  # responses to give, in order; each is ("tool", name, input) or ("text", words) or ("badkey",)


class Fake(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))) or b"{}")
        HITS.append(body)
        step = SCRIPT.pop(0) if SCRIPT else ("text", "Happy to help.")
        if step[0] == "badkey":
            out, code = {"type": "error", "error": {"type": "authentication_error", "message": "invalid x-api-key"}}, 401
        elif step[0] == "tool":
            out, code = {"id": "msg_t", "type": "message", "role": "assistant", "model": body.get("model"), "content": [{"type": "text", "text": "Let me check."}, {"type": "tool_use", "id": "tu_%d" % len(HITS), "name": step[1], "input": step[2]}],
                         "stop_reason": "tool_use", "stop_sequence": None, "usage": {"input_tokens": 100, "output_tokens": 20}}, 200
        else:
            out, code = {"id": "msg_x", "type": "message", "role": "assistant", "model": body.get("model"), "content": [{"type": "text", "text": step[1]}],
                         "stop_reason": "end_turn", "stop_sequence": None, "usage": {"input_tokens": 100, "output_tokens": 20}}, 200
        b = json.dumps(out).encode()
        self.send_response(code); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)


def last_tool_result(i=-1):
    try:
        msgs = HITS[i]["messages"]
        for blk in msgs[-1]["content"]:
            if blk.get("type") == "tool_result":
                return blk
    except (IndexError, KeyError, TypeError, AttributeError):
        pass
    return {}


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    srv = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Fake); threading.Thread(target=srv.serve_forever, daemon=True).start()
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"CLAUDE_API_BASE": f"http://127.0.0.1:{srv.server_address[1]}"}).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        u = Client(inst.base); u.register("tara-chat-a@test.local"); s, _, ws = u.page("/workspace.html")
        t.check("the workspace loads Tara's chat script and style", "/tara-chat.js" in ws and "/tara-chat.css" in ws)
        s, j = u.api("GET", "tara/status"); t.check("Tara is off by default", s == 200 and j["tara"]["enabled"] is False, j)
        t.check("a message while Tara is off is refused and sends nothing", u.api("POST", "tara/message", {"text": "hello"})[0] == 422 and HITS == [])

        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        base = {"enabled": True, "model": "", "instructions": "", "freeDaily": 10, "paidDaily": 60, "siteDaily": 2000, "dailySpendInr": 300, "monthlySpendInr": 5000, "maxTokens": 1500, "historyMessages": 16, "maxSteps": 5, "tools": [], "handoff": "Our team will reply by email within one working day.", "suggestions": True}
        s, j = o.api("POST", "admin/tara/settings", dict(base, tools=["account_overview"]))
        t.check("Tara cannot be switched on before the AI key is set up", s == 422 and "Integrations" in str(j), (s, str(j)[:200]))
        o.api("POST", "admin/providers", {"service": "ai", "values": {"provider": "claude", "claudeApiKey": "sk-ant-test-1", "model": "", "dailyLimit": "0"}})
        s, g = o.api("GET", "admin/content/integrations"); d = g["data"]; d["ai"] = True; o.api("POST", "admin/content/integrations", {"data": d, "revision": g["revision"]})
        all_tools = list(o.api("GET", "admin/tara")[1]["tools"].keys())
        t.check("the hand-off message may not mention AI", o.api("POST", "admin/tara/settings", dict(base, tools=all_tools, handoff="Our AI will email you soon."))[0] == 422)
        s, j = o.api("POST", "admin/tara/settings", dict(base, tools=all_tools)); t.check("the owner switches Tara on with every action", s == 200 and j["settings"]["enabled"] is True and len(j["settings"]["tools"]) == 14, (s, str(j)[:300]))
        t.check("a customer cannot reach Tara's owner settings", u.api("GET", "admin/tara")[0] == 403 and u.api("POST", "admin/tara/settings", base)[0] == 403)

        s, j = u.api("GET", "tara/status"); st = j["tara"]
        t.check("a free account sees Tara with 10 messages a day, a welcome and suggestions", st["enabled"] is True and st["left"] == 10 and st["limit"] == 10 and st["welcome"].startswith("Hi, I'm Tara") and len(st["suggestions"]) >= 4, st)
        t.check("Tara's welcome and chat never call her AI", " AI" not in st["welcome"] and "Claude" not in st["welcome"] and " AI " not in open(os.path.join(ctx.public, "tara-chat.js"), encoding="utf8").read())

        # 1. account question: Tara looks it up instead of guessing
        SCRIPT[:] = [("tool", "account_overview", {}), ("text", "You are on the Free plan. Clean downloads need a package.")]
        n = len(HITS); s, j = u.api("POST", "tara/message", {"text": "How many credits do I have?"})
        t.check("an answer comes back and starts a conversation", s == 200 and "Free plan" in j["message"]["content"] and j.get("conversationId") and j["left"] == 9, (s, str(j)[:300]))
        cid = j.get("conversationId")
        first = HITS[n] if len(HITS) > n else {}
        names = [x.get("name") for x in first.get("tools", [])]
        t.check("Tara is offered her actions, with the rules and the customer's message", "ats_check" in names and "create_support_ticket" in names and "Never say you are an AI" in json.dumps(first.get("system")) and "How many credits" in json.dumps(first.get("messages")), (names, str(first.get("system"))[:200]))
        tr = last_tool_result()
        t.check("the account lookup runs as this customer and is sent back to Tara", "resumeDownloadsLeft" in str(tr.get("content")) and '"plan":"Free"' in str(tr.get("content")).replace(" ", ""), str(tr)[:300])
        t.check("each action is logged", db.q("select count(*) from tara_tool_calls where tool='account_overview' and status='ok'")[0][0] == 1)
        t.check("chat cost is counted as Tara chat", db.q("select count(*) from ai_usage where purpose='customer:tara_chat'")[0][0] >= 2)

        # 2. an ATS check through the normal scanner, with a link to the full report
        doc = {"version": "1", "title": "Mine", "template": "suite-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Ann", "email": "ann@example.com"}, "summary": "Recruiter for retail stores in Pune with three years of hiring experience.", "skills": "Excel, Sourcing, Screening",
               "order": ["summary", "experience"], "experience": [{"role": "Recruiter", "company": "Acme", "start": "2021", "current": True, "bullets": "Hired staff for 14 stores.\nScreened candidates and scheduled interviews.\nKept weekly reports in Excel."}], "education": [], "projects": [], "certifications": []}
        rid = u.api("POST", "resumes", {"data": doc})[1]["id"]
        job = "We are hiring a recruiter. Responsibilities: source candidates, screen candidates, schedule interviews, use Workday and Excel. Required: 2+ years of recruitment experience in retail hiring."
        before = db.q("select count(*) from ats_scans where status='done'")[0][0]
        SCRIPT[:] = [("tool", "ats_check", {"resumeId": rid, "jobText": job, "title": "Recruiter", "company": "Shop Ltd"}), ("text", "Your match is shown below. Workday is missing.")]
        s, j = u.api("POST", "tara/message", {"conversationId": cid, "text": "Check my resume against this job: " + job})
        tr = json.loads(last_tool_result().get("content") or "{}")
        t.check("an ATS check runs through the normal scanner and returns a score and gaps", s == 200 and tr.get("ok") and isinstance(tr.get("score"), int) and tr.get("scanId") and db.q("select count(*) from ats_scans where status='done'")[0][0] == before + 1, str(tr)[:300])
        t.check("the answer links to the full ATS report", any("view=match&scan=" in l["url"] for l in j["message"].get("links", [])), j["message"].get("links"))
        sid = tr.get("scanId")
        SCRIPT[:] = [("tool", "open_cover_letter", {"resumeId": rid, "scanId": sid}), ("text", "Use the button to write it.")]
        s, j = u.api("POST", "tara/message", {"conversationId": cid, "text": "Write a cover letter for it"})
        t.check("a cover letter opens the normal letter page for that resume and job", s == 200 and any(f"write=1&resume={rid}&scan={sid}" in l["url"] for l in j["message"]["links"]), j["message"].get("links"))

        # 3. never another customer's documents
        v = Client(inst.base); v.register("tara-chat-b@test.local"); v.page("/workspace.html")
        SCRIPT[:] = [("tool", "read_resume", {"resumeId": rid}), ("text", "I could not find that resume.")]
        s, j = v.api("POST", "tara/message", {"text": "Read resume " + rid})
        tr = json.loads(last_tool_result().get("content") or "{}")
        t.check("Tara cannot read another customer's resume", s == 200 and tr.get("ok") is False and "Hired staff" not in json.dumps(HITS[-1]), str(tr)[:200])
        t.check("that refused action is logged as failed", db.q("select count(*) from tara_tool_calls where tool='read_resume' and status='failed'")[0][0] == 1)
        t.check("another customer cannot open this conversation", v.api("GET", f"tara/conversations/{cid}")[0] == 404 and v.api("POST", "tara/message", {"conversationId": cid, "text": "hi"})[0] == 404)

        # 4. hand-off to the team with a reference
        SCRIPT[:] = [("tool", "create_support_ticket", {"area": "payment", "summary": "Paid Rs 299 on 5 Nov by UPI but the plan does not show."}), ("text", "I have passed this to our team.")]
        s, j = u.api("POST", "tara/message", {"conversationId": cid, "text": "I paid but my plan is missing"})
        tr = json.loads(last_tool_result().get("content") or "{}")
        t.check("a problem becomes a support ticket with a reference", s == 200 and str(tr.get("reference", "")).startswith("CVT-") and db.q("select count(*) from support_reports where area='tara' and message like '%Rs 299%'")[0][0] == 1, str(tr)[:200])

        # 5. failures cost nothing; daily limit
        left = u.api("GET", "tara/status")[1]["tara"]["left"]
        SCRIPT[:] = [("badkey",)]
        s, j = u.api("POST", "tara/message", {"conversationId": cid, "text": "Are you there?"})
        t.check("when Tara cannot answer, the customer gets a plain message and keeps the message", s == 422 and "could not answer" in str(j) and u.api("GET", "tara/status")[1]["tara"]["left"] == left, (s, str(j)[:200]))
        o.api("POST", "admin/tara/settings", dict(base, tools=all_tools, freeDaily=1))
        s, j = v.api("POST", "tara/message", {"text": "hello again"})
        t.check("after the daily messages a free account is told when they refresh", s == 422 and "midnight" in str(j) and "60 a day" in str(j), (s, str(j)[:200]))
        o.api("POST", "admin/tara/settings", dict(base, tools=all_tools))

        # 6. conversations, ratings, delete
        s, j = u.api("GET", "tara/conversations"); t.check("the customer sees their past conversations", s == 200 and any(c["id"] == cid for c in j["conversations"]))
        s, j = u.api("GET", f"tara/conversations/{cid}"); msgs = j.get("messages", [])
        t.check("a conversation reopens with its messages and links", s == 200 and len(msgs) >= 8 and any(m["links"] for m in msgs), len(msgs))
        aid = next((m["id"] for m in msgs if m["role"] == "assistant"), "")
        t.check("an answer can be rated", u.api("POST", "tara/rate", {"messageId": aid, "rating": 1})[0] == 200 and v.api("POST", "tara/rate", {"messageId": aid, "rating": -1})[0] == 404)

        # 7. owner console
        s, h, b = o.page("/admin.html")
        t.check("the owner console has a Tara chat page", 'data-admin-view="tara"' in b and 'id="admin-tara"' in b and "/console/tara.js" in b and o.raw("GET", "/console/tara.js")[0] == 200 and u.raw("GET", "/console/tara.js")[0] in (403, 404))
        s, j = o.api("GET", "admin/tara"); stt = j.get("stats", {})
        t.check("the owner sees today's messages, spending and tickets", s == 200 and stt.get("messagesToday", 0) >= 5 and stt.get("spendTodayPaise", -1) >= 0 and stt.get("tickets7d") == 1 and stt.get("thumbsUp7d") == 1, stt)
        s, j = o.api("GET", "admin/tara/conversations?q=tara-chat-a"); t.check("conversations are listed a page at a time with search", s == 200 and j["total"] >= 1 and j["rows"][0]["email"] == "tara-chat-a@test.local", str(j)[:200])
        s, j = o.api("GET", f"admin/tara/conversation?id={cid}")
        t.check("opening a chat shows its messages and actions and is recorded", s == 200 and len(j["messages"]) >= 8 and len(j["tools"]) >= 4 and db.q("select count(*) from activity_events where event='tara_conversation_viewed'")[0][0] >= 1)
        s, j = o.api("GET", "admin/tara/tools?status=failed"); t.check("failed actions are listed with their reason", s == 200 and j["total"] >= 1 and all(r["status"] == "failed" for r in j["rows"]))
        t.check("both lists download as a file", o.raw("GET", "/api/admin/tara/conversations?export=csv")[0] == 200 and o.raw("GET", "/api/admin/tara/tools?export=csv")[0] == 200)
        t.check("Tara's tickets show up under their own area in Support", "'tara' => 'Tara chat'" in open(os.path.join(ctx.app, "app", "Http", "Controllers", "SupportController.php"), encoding="utf8").read())

        # 8. dashboard next-step card and profile facts: real numbers only, small groups hidden
        s, j = u.api("GET", "tara/nudge"); nd = j.get("nudge") or {}
        t.check("the dashboard card offers the next step for the last checked job in one click", s == 200 and "Recruiter" in nd.get("title", "") and any("fix=" in (x.get("url") or "") for x in nd.get("actions", [])) and any("write=1" in (x.get("url") or "") for x in nd.get("actions", [])), nd)
        t.check("a free customer is shown one package with its real price", (nd.get("offer") or {}).get("price") in (99, 299) and "/checkout.html?plan=" in (nd.get("offer") or {}).get("url", ""), nd.get("offer"))
        w = Client(inst.base); w.register("tara-chat-c@test.local"); w.page("/workspace.html")
        s, j = w.api("GET", "tara/nudge"); nw = j.get("nudge") or {}
        t.check("someone with no resume is offered upload or a template first, with no package yet", nw.get("title") == "Your resume is one click away" and any(x.get("upload") for x in nw["actions"]) and nw.get("offer") is None, nw)
        ids = [r[0] for r in db.q("select id from users where email like 'tara-chat-%' order by id")]
        for i in ids: db.q(f"update profiles set college='Govt College Pune' where owner_id={i}")
        s, j = w.api("GET", "tara/nudge"); t.check("fewer people than the owner's minimum from a college are not mentioned", s == 200 and not any("Govt College" in p for p in (j["nudge"] or {}).get("proof", [])), j.get("nudge", {}).get("proof"))
        o.api("POST", "admin/tara/settings", dict(base, tools=all_tools, peerMin=2))
        s, j = w.api("GET", "tara/nudge"); t.check("with enough people, the real count from the customer's college is shown", any(p.startswith("2 people from Govt College Pune use CVTara") for p in (j["nudge"] or {}).get("proof", [])), j.get("nudge", {}).get("proof"))
        SCRIPT[:] = [("tool", "my_profile", {}), ("text", "Two people from your college use CVTara.")]
        s, j = w.api("POST", "tara/message", {"text": "Who else uses this?"}); pf = json.loads(last_tool_result().get("content") or "{}")
        t.check("Tara sees the customer's profile and the same anonymous counts, never names", pf.get("college") == "Govt College Pune" and pf.get("peers", {}).get("sameCollegeUsers") == 2 and "tara-chat-a" not in json.dumps(pf), str(pf)[:300])
        SCRIPT[:] = [("tool", "product_guide", {}), ("text", "Here is how downloads work.")]
        w.api("POST", "tara/message", {"text": "How do downloads work?"}); g = json.loads(last_tool_result().get("content") or "{}")
        t.check("Tara can read the customer guide to answer how-to questions", "download" in g.get("guide", "").lower() and len(g.get("guide", "")) > 2000)
        t.check("Tara is told never to describe admin functions or website setup", "Never describe the owner console, admin or staff functions, website setup" in json.dumps(HITS[-1].get("system")))
        t.check("Tara has no owner or admin actions", not any(k.startswith(("admin", "owner", "setup", "settings")) for k in all_tools))
        o.api("POST", "admin/tara/settings", dict(base, tools=all_tools, nudges=False))
        t.check("the owner can turn the dashboard card off", w.api("GET", "tara/nudge")[1].get("nudge") is None)

        s, _ = u.api("POST", f"tara/conversations/{cid}/delete", {})
        t.check("a customer can delete a conversation and its words are wiped", s == 200 and u.api("GET", f"tara/conversations/{cid}")[0] == 404 and db.q(f"select count(*) from tara_messages where conversation_id='{cid}' and content != ''")[0][0] == 0)
        t.check("a visitor who is not signed in cannot use Tara", Client(inst.base).api("POST", "tara/message", {"text": "hi"})[0] == 401)
    finally:
        inst.stop(); srv.shutdown()
