"""Business assistant (Tara owner mode): owner only, answers from real records through read-only tools, revenue rules,
AI costs per group and per package, agent and health reports, actions only after Confirm with before/after and audit,
kept apart from customer Tara, console page and downloads."""
import http.server, json, os, threading
NAME = "Business assistant (owner Tara)"
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


def tr_json(i=-1):
    try:
        return json.loads(last_tool_result(i).get("content") or "{}")
    except ValueError:
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
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        u = Client(inst.base); u.register("biz-a@test.local"); u.page("/workspace.html")
        b2 = Client(inst.base); b2.register("biz-b@test.local"); b2.page("/workspace.html")
        uid = db.q("select id from users where email='biz-a@test.local'")[0][0]
        bid = db.q("select id from users where email='biz-b@test.local'")[0][0]

        s, j = o.api("GET", "admin/tara/owner")
        t.check("the owner page opens with numbers, suggestions and actions even before the AI key", s == 200 and j["enabled"] is False and "revenueToday" in j["strip"] and len(j["suggestions"]) >= 6 and "grant_plan" in j["actions"], str(j)[:300])
        t.check("asking before the AI key is set is refused and sends nothing", o.api("POST", "admin/tara/owner/message", {"text": "revenue?"})[0] == 422 and HITS == [])
        t.check("customers cannot reach the business assistant", u.api("GET", "admin/tara/owner")[0] == 403 and u.api("POST", "admin/tara/owner/message", {"text": "revenue?"})[0] == 403 and u.api("GET", "admin/tara/owner/briefing")[0] == 403)

        # real records: one paid order, one unpaid checkout, one complimentary grant, one test (demo) excluded only on live sites
        lim = json.dumps({"exports": 3})
        db.q("insert into purchases(id,owner_id,plan,amount_paise,status,provider,payment_reference,is_live,limits_json,access_days,purchased_at,access_until,created_at,updated_at) values('aaaaaaaa-0000-0000-0000-000000000001',?,'job-hunt',29900,'paid','razorpay','pay_b1',1,?,30,datetime('now'),datetime('now','+3 days'),datetime('now'),datetime('now'))", uid, lim)
        db.q("insert into purchases(id,owner_id,plan,amount_paise,status,provider,is_live,limits_json,access_days,created_at,updated_at) values('aaaaaaaa-0000-0000-0000-000000000002',?,'pro',59900,'created','razorpay',1,?,30,datetime('now'),datetime('now'))", bid, lim)
        db.q("insert into purchases(id,owner_id,plan,amount_paise,status,provider,payment_reference,is_live,limits_json,access_days,purchased_at,access_until,created_at,updated_at) values('aaaaaaaa-0000-0000-0000-000000000003',?,'pro',0,'paid','manual','manual_x',0,?,30,datetime('now'),datetime('now','+30 days'),datetime('now'),datetime('now'))", bid, lim)
        db.q("insert into ai_usage(purpose,model,input_tokens,output_tokens,cost_paise,created_at,owner_id) values('seo','m1',1000,200,500,datetime('now'),null),('customer:cover_letter','m1',3000,900,1200,datetime('now'),?),('customer:ats_tailor','m2',4000,1500,2000,datetime('now'),?)", uid, bid)
        db.q("insert into support_reports(id,owner_id,email,area,kind,message,status,created_at,updated_at) values('bbbbbbbb-0000-0000-0000-000000000001',?,'biz-a@test.local','payment','bug','Paid but the plan is missing','new',datetime('now','-3 days'),datetime('now','-3 days'))", uid)

        s, j = o.api("GET", "admin/tara/owner/briefing?period=today&fresh=1"); b = j.get("briefing", {})
        k = {x["label"]: x for x in b.get("kpis", [])}
        t.check("the briefing counts only real money: the paid order, not the unpaid checkout or the ₹0 grant", s == 200 and k.get("Paid orders", {}).get("value") == 1 and k.get("Net revenue", {}).get("value") == "₹299" and b.get("unpaidCheckouts") == 1, (s, str(b)[:400]))
        t.check("the briefing says its period, comparison, time and what is not connected", b.get("range") and b.get("comparedWith") and b.get("workedOutAt") and any("not tracked" in x for x in b.get("notConnected", [])))
        t.check("the briefing lists AI spending in rupees and dollars with the rate", b["ai"]["total"]["inr"] == "₹37" and b["ai"]["total"]["usd"].startswith("$") and "1 USD" in b["ai"]["exchangeRate"], b.get("ai"))
        t.check("the briefing shows waiting work", b["pending"]["supportNotAnswered"] >= 1, b.get("pending"))

        o.api("POST", "admin/providers", {"service": "ai", "values": {"provider": "claude", "claudeApiKey": "sk-ant-test-2", "model": "", "dailyLimit": "0"}})
        s, g = o.api("GET", "admin/content/integrations"); d = g["data"]; d["ai"] = True; o.api("POST", "admin/content/integrations", {"data": d, "revision": g["revision"]})

        # 1. revenue question answered from the tool
        SCRIPT[:] = [("tool", "revenue", {"period": "today"}), ("text", "Today: **1 paid order**, net ₹299. Source: CVTara orders.")]
        n = len(HITS); s, j = o.api("POST", "admin/tara/owner/message", {"text": "What is today's revenue?"})
        cid = j.get("conversationId")
        t.check("the owner gets an answer and a conversation", s == 200 and "₹299" in j["message"]["content"] and cid, (s, str(j)[:300]))
        first = HITS[n] if len(HITS) > n else {}
        names = [x.get("name") for x in first.get("tools", [])]
        t.check("owner Tara has the business tools and the owner rules, not the customer's", "revenue" in names and "propose_action" in names and "ats_check" not in names and "business assistant" in json.dumps(first.get("system")) and "propose_action" in json.dumps(first.get("system")), names)
        r = tr_json()
        t.check("revenue comes from the orders with the rule, period and source", r.get("successfulTransactions") == 1 and r.get("netRevenue") == "₹299" and r.get("checkoutsOpenedNotPaid") == 1 and r.get("complimentaryGrantsNotCounted") == 1 and "Not counted" in r.get("rule", "") and r.get("source") and r.get("workedOutAt"), str(r)[:400])
        t.check("owner chat costs are counted as the business assistant, on the owner's account", db.q("select count(*) from ai_usage where purpose='owner:tara' and owner_id is not null")[0][0] >= 2)
        t.check("owner tool calls are logged in owner mode", db.q("select count(*) from tara_tool_calls where mode='owner' and tool='revenue' and status='ok'")[0][0] == 1)

        # 2. AI costs by group and per package; customers; credits; agents; health; support
        SCRIPT[:] = [("tool", "ai_costs", {"period": "this_month"}), ("text", "AI costs listed.")]
        o.api("POST", "admin/tara/owner/message", {"conversationId": cid, "text": "AI spend this month?"}); r = tr_json()
        groups = {g["group"]: g for g in r.get("byGroup", [])}
        t.check("AI costs split into the owner's groups with tokens, ₹ and $", "SEO agent" in groups and "Cover letter generation" in groups and "Resume improvement (ATS tailoring)" in groups and groups["SEO agent"]["inr"] == "₹5" and r.get("inputTokens", 0) >= 8000, str(r)[:400])
        pk = r.get("averageByPackage")
        t.check("AI cost per customer is worked out by package", isinstance(pk, list) and len(pk) >= 1 and "ATS checks are scored" in r.get("atsScanner", ""), pk)
        SCRIPT[:] = [("tool", "customers", {"period": "today"}), ("text", "ok")]
        o.api("POST", "admin/tara/owner/message", {"conversationId": cid, "text": "customers?"}); r = tr_json()
        t.check("customer numbers include new, paid, complimentary, never-bought and plans ending soon", r.get("newRegistrations", 0) >= 2 and r.get("activePaidCustomers", 0) >= 1 and r.get("activeComplimentary", 0) >= 1 and "neverPurchased" in r and any(x["email"] == "biz-a@test.local" for x in r.get("endingWithin7Days", [])), str(r)[:400])
        SCRIPT[:] = [("tool", "find_customer", {"query": "biz-a"}), ("text", "ok")]
        o.api("POST", "admin/tara/owner/message", {"conversationId": cid, "text": "find biz-a"}); r = tr_json()
        m = (r.get("matches") or [{}])[0]
        t.check("one customer's account shows package, orders, credits and open tickets", m.get("email") == "biz-a@test.local" and m.get("orders") and m.get("openSupportTickets") == 1 and "credits" in m, str(m)[:300])
        for tool, key in [("seo_agent", "status"), ("growth_agent", "activityIsNotRevenue"), ("site_health", "issuesByPriority"), ("credit_usage", "creditsUsed"), ("pending_approvals", "supportNotAnswered"), ("referrals_reviews_support", "support")]:
            SCRIPT[:] = [("tool", tool, {}), ("text", "ok")]
            o.api("POST", "admin/tara/owner/message", {"conversationId": cid, "text": tool}); r = tr_json()
            t.check(f"the {tool.replace('_', ' ')} lookup works", r.get("ok") is True and key in r, str(r)[:300])
        r = tr_json()
        t.check("the support report shows overdue tickets with their ids", r["support"]["overdue"] == 1 and r["support"]["oldestOpen"][0]["ticketId"] == "bbbbbbbb-0000-0000-0000-000000000001", r.get("support"))
        SCRIPT[:] = [("tool", "run_report", {"dataset": "orders"}), ("text", "ok")]
        s, j = o.api("POST", "admin/tara/owner/message", {"conversationId": cid, "text": "orders report"}); r = tr_json()
        t.check("a report from Reports & Export comes back with rows and a download link", r.get("ok") and r.get("totalRows", 0) >= 1 and any("format=xlsx" in l["url"] for l in j["message"]["links"]), (str(r)[:300], j["message"].get("links")))

        # 3. actions: proposed only, run after Confirm, with before/after and audit
        SCRIPT[:] = [("tool", "propose_action", {"action": "grant_plan", "params": {"email": "biz-b@test.local", "plan": "job-hunt", "days": 30, "note": "college partner thank-you", "sendEmail": False}, "reason": "owner asked"}), ("text", "Ready for your Confirm.")]
        before = db.q("select count(*) from purchases where owner_id=?", bid)[0][0]
        s, j = o.api("POST", "admin/tara/owner/message", {"conversationId": cid, "text": "Give biz-b Job Hunt for 30 days free"})
        cards = j["message"].get("cards", [])
        t.check("a change comes back as a card waiting for Confirm, and nothing is changed yet", s == 200 and len(cards) == 1 and cards[0]["status"] == "pending" and "complimentary" in cards[0]["summary"] and db.q("select count(*) from purchases where owner_id=?", bid)[0][0] == before, str(cards)[:300])
        aid = cards[0]["id"] if cards else ""
        t.check("the card shows the values now", cards and cards[0]["before"]["currentPackage"].startswith("Pro"), cards)
        t.check("a customer cannot confirm the owner's action", u.api("POST", "admin/tara/owner/actions/confirm", {"id": aid})[0] == 403)
        s, j = o.api("POST", "admin/tara/owner/actions/confirm", {"id": aid})
        t.check("Confirm runs it through the normal console action", s == 200 and j["card"]["status"] == "done" and db.q("select count(*) from purchases where owner_id=? and plan='job-hunt' and provider='manual' and amount_paise=0", bid)[0][0] == 1, (s, str(j)[:300]))
        row = db.q("select status,before_json,after_json,decided_by from tara_admin_actions where id=?", aid)[0]
        t.check("the record keeps who confirmed it and the values before and after", row[0] == "done" and row[3] and json.loads(row[1]) and json.loads(row[2]), row)
        t.check("the audit trail has the proposal and the result", db.q("select count(*) from activity_events where event in ('tara_action_proposed','tara_action_done')")[0][0] >= 2)
        t.check("a card cannot run twice", o.api("POST", "admin/tara/owner/actions/confirm", {"id": aid})[0] == 422)
        SCRIPT[:] = [("tool", "propose_action", {"action": "ai_monthly_cap", "params": {"inr": 2500}}), ("text", "Ready.")]
        s, j = o.api("POST", "admin/tara/owner/message", {"conversationId": cid, "text": "Set the AI cap to 2500"}); assert s == 200, (s, str(j)[:300]); c2 = j["message"]["cards"][0]["id"]
        t.check("a cancelled card changes nothing", o.api("POST", "admin/tara/owner/actions/cancel", {"id": c2})[0] == 200 and o.api("POST", "admin/tara/owner/actions/confirm", {"id": c2})[0] == 422)
        SCRIPT[:] = [("tool", "propose_action", {"action": "delete_user", "params": {"email": "biz-a@test.local"}}), ("text", "That must be done on the Users page.")]
        o.api("POST", "admin/tara/owner/message", {"conversationId": cid, "text": "delete biz-a"}); r = tr_json()
        t.check("restricted actions are not available to the assistant", r.get("ok") is False and "console page" in r.get("error", "") and db.q("select count(*) from users where id=?", uid)[0][0] == 1, r)
        SCRIPT[:] = [("tool", "propose_action", {"action": "support_ticket", "params": {"ticketId": "bbbbbbbb-0000-0000-0000-000000000001", "status": "resolved", "note": "Plan restored"}}), ("text", "Ready.")]
        s, j = o.api("POST", "admin/tara/owner/message", {"conversationId": cid, "text": "resolve the payment ticket"}); c3 = j["message"]["cards"][0]["id"]
        db.q("update tara_admin_actions set created_at=datetime('now','-31 minutes') where id=?", c3)
        t.check("a card older than 30 minutes expires and changes nothing", o.api("POST", "admin/tara/owner/actions/confirm", {"id": c3})[0] == 422 and db.q("select status from support_reports where id='bbbbbbbb-0000-0000-0000-000000000001'")[0][0] == "new")

        # 4. kept apart from customer Tara; history; lists; console page
        s, j = o.api("GET", "admin/tara/conversations")
        t.check("owner chats never show in the customer chat list", s == 200 and all(r["id"] != cid for r in j["rows"]))
        t.check("owner chats are not counted as customer Tara messages", o.api("GET", "admin/tara")[1]["stats"]["messagesToday"] == 0)
        s, j = o.api("GET", f"admin/tara/owner/conversation?id={cid}")
        t.check("an owner chat reopens with its cards and their current status", s == 200 and any(c["status"] == "done" for m in j["messages"] for c in m["cards"]) and any("Done:" in m["content"] for m in j["messages"]))
        t.check("a customer cannot open the owner's chat through their own Tara", u.api("GET", f"tara/conversations/{cid}")[0] == 404)
        s, j = o.api("GET", "admin/tara/owner/actions?status=done")
        t.check("the actions record lists done actions and downloads", s == 200 and j["total"] == 1 and o.raw("GET", "/api/admin/tara/owner/actions?export=csv")[0] == 200)
        s, h, page = o.page("/admin.html")
        t.check("the console has a Business assistant page for owners only", 'data-admin-view="assistant"' in page and 'id="admin-assistant"' in page and o.raw("GET", "/console/assistant.js")[0] == 200 and u.raw("GET", "/console/assistant.js")[0] in (403, 404))
        s, j = o.api("POST", "admin/tara/owner/settings", {"model": "", "instructions": "Compare with last week.", "monthlySpendInr": 0, "maxTokens": 2500, "historyMessages": 20, "maxSteps": 8})
        t.check("assistant settings save", s == 200 and j["settings"]["instructions"] == "Compare with last week.")
        o.api("POST", "admin/tara/owner/settings", {"model": "", "instructions": "", "monthlySpendInr": 1, "maxTokens": 2500, "historyMessages": 20, "maxSteps": 8})
        db.q("insert into ai_usage(purpose,model,input_tokens,output_tokens,cost_paise,created_at) values('owner:tara','m',1,1,500,datetime('now'))")
        t.check("the assistant stops at its own monthly spending limit", o.api("POST", "admin/tara/owner/message", {"text": "hi"})[0] == 422)
        s, j = o.api("POST", "admin/tara/owner/conversation/delete", {"id": cid})
        t.check("an owner chat can be deleted; its actions record stays", s == 200 and o.api("GET", f"admin/tara/owner/conversation?id={cid}")[0] == 404 and db.q("select count(*) from tara_admin_actions")[0][0] >= 3)
    finally:
        inst.stop(); srv.shutdown()
