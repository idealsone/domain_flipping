"""Approval Centre and agent tasks (3.42.0): each kind of approval needs its own permission, nobody approves what they
created, Verified needs evidence and a different reviewer, agents create one task per finding, overdue tasks are marked
by the cron, and every decision is recorded."""
import json
NAME = "Approval Centre and tasks"


def run(ctx, t):
    db = ctx.db()
    o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")

    def staff(role):
        email = f"ap-{role.replace('_', '-')}@test.local"; c = ctx.client(); c.register(email)
        o.api("POST", "admin/team/save", {"email": email, "role": role})
        x = ctx.client(); x.form("/sanaulla", {"email": email, "password": "TestPassw0rd!!x"}); x.page("/admin.html"); return x
    admin, growth, seo, fin, agent, editor = staff("admin"), staff("growth_manager"), staff("seo_manager"), staff("finance_manager"), staff("support_agent"), staff("content_editor")
    uid = lambda e: db.q("select id from users where email=?", e)[0][0]

    # sources
    db.q("insert into seo_proposals(id, kind, path, query, status, data_json, before_json, reason, impressions, clicks, position, created_at, updated_at) values('aaaaaaaa-0000-0000-0000-000000000001', 'meta', '/no-such-page.html', 'resume format', 'pending', '{\"title\":\"New\",\"description\":\"New desc\"}', '{\"title\":\"Old\"}', 'Better match for the search', 120, 3, 8.5, datetime('now'), datetime('now'))")
    cust = ctx.client(); cust.register("ap-cust@test.local"); cid = uid("ap-cust@test.local")
    db.q("insert into purchases(id,owner_id,plan,status,amount_paise,provider,limits_json,access_days,purchased_at,access_until,created_at,updated_at) values('ap-p1',?,'job-hunt','paid',29900,'demo','{}',30,datetime('now'),datetime('now','+30 days'),datetime('now'),datetime('now'))", cid)
    db.q("insert into refund_requests(id, owner_id, purchase_id, status, amount_paise, reason, created_at, updated_at) values('aaaaaaaa-0000-0000-0000-0000000000f1', ?, 'ap-p1', 'requested', 29900, 'Bought by mistake', datetime('now','-3 days'), datetime('now','-3 days'))", cid)

    s, j = o.api("GET", "admin/approvals")
    cats = {x["category"] for x in j.get("items", [])}
    t.check("the owner sees SEO changes and refunds in one list", s == 200 and {"seo", "refund"} <= cats, (s, str(j)[:300]))
    seo_item = next(x for x in j["items"] if x["category"] == "seo")
    t.check("each item shows who asked, why, cost, impact, risk, the change and evidence", all(k in seo_item for k in ("submittedBy", "agent", "action", "justification", "cost", "impact", "risk", "changes", "evidence")) and seo_item["changes"]["after"]["title"] == "New")
    t.check("an old request is flagged as waiting too long", next(x for x in j["items"] if x["category"] == "refund")["waitingLong"] is True)
    t.check("a Finance Manager sees refunds but not SEO changes", {x["category"] for x in fin.api("GET", "admin/approvals")[1]["items"]} == {"refund"})
    t.check("an SEO Manager (drafts only) can decide nothing", seo.api("GET", "admin/approvals")[1]["items"] == [])
    t.check("a Support Agent sees nothing to decide", agent.api("GET", "admin/approvals")[1]["items"] == [])
    t.check("an SEO Manager cannot approve an SEO change", seo.api("POST", "admin/approvals/decide", {"category": "seo", "id": "aaaaaaaa-0000-0000-0000-000000000001", "decision": "reject", "reason": "Not now please"})[0] == 403)
    t.check("a Finance Manager cannot decide SEO changes", fin.api("POST", "admin/approvals/decide", {"category": "seo", "id": "aaaaaaaa-0000-0000-0000-000000000001", "decision": "reject", "reason": "Not now please"})[0] == 403)
    t.check("rejecting needs a reason", admin.api("POST", "admin/approvals/decide", {"category": "seo", "id": "aaaaaaaa-0000-0000-0000-000000000001", "decision": "reject", "reason": ""})[0] == 422)
    s, j = admin.api("POST", "admin/approvals/decide", {"category": "seo", "id": "aaaaaaaa-0000-0000-0000-000000000001", "decision": "reject", "reason": "Title is too long"})
    t.check("an Admin rejects an SEO change through the SEO agent's own rules", s == 200 and db.q("select status from seo_proposals where id='aaaaaaaa-0000-0000-0000-000000000001'")[0][0] == "rejected", (s, j))
    t.check("a decided change cannot be decided again", admin.api("POST", "admin/approvals/decide", {"category": "seo", "id": "aaaaaaaa-0000-0000-0000-000000000001", "decision": "approve"})[0] == 422)
    s, j = fin.api("POST", "admin/approvals/decide", {"category": "refund", "id": "aaaaaaaa-0000-0000-0000-0000000000f1", "decision": "reject", "reason": "Downloads were used"})
    t.check("a Finance Manager rejects a refund through the refund rules", s == 200 and db.q("select status from refund_requests where id='aaaaaaaa-0000-0000-0000-0000000000f1'")[0][0] == "rejected", (s, j))
    t.check("decisions are recorded with who and why", db.q("select count(*) from approval_events where decided_by=? and source='refund' and reason='Downloads were used'", uid("ap-finance-manager@test.local"))[0][0] == 1)
    t.check("decisions are in the audit log", db.q("select count(*) from audit_logs where action in ('approval:seo:reject','approval:refund:reject')")[0][0] == 2)
    t.check("Business assistant actions are owner only", admin.api("POST", "admin/approvals/decide", {"category": "tara", "id": "aaaaaaaa-0000-0000-0000-000000000009", "decision": "reject", "reason": "no thanks"})[0] == 403)
    t.check("an unknown kind is refused", admin.api("POST", "admin/approvals/decide", {"category": "payments", "id": "aaaaaaaa-0000-0000-0000-000000000009", "decision": "approve"})[0] in (403, 422))
    t.check("customers cannot reach the Approval Centre", cust.api("GET", "admin/approvals")[0] in (401, 403))

    # tasks
    t.check("a Support Agent cannot see tasks", agent.api("GET", "admin/tasks")[0] == 403)
    s, j = editor.api("GET", "admin/tasks")
    t.check("a Content Editor (SEO view) sees only SEO tasks and cannot create", s == 200 and list(j["agents"]) == ["seo"] and j["canCreate"] == [], j.get("agents"))
    t.check("an SEO Manager cannot create a Growth task", seo.api("POST", "admin/tasks", {"agent": "growth", "title": "Run a campus campaign"})[0] == 403)
    s, j = growth.api("POST", "admin/tasks", {"agent": "growth", "title": "Win back people who left checkout", "detail": "Email the 40 people who left checkout last week.", "priority": "high", "dueAt": "2030-01-01", "submit": True})
    tid = j.get("id")
    t.check("a Growth Manager creates and submits a task", s == 200 and j["task"]["status"] == "submitted", (s, str(j)[:200]))
    t.check("a Growth Manager cannot approve tasks", growth.api("POST", f"admin/tasks/{tid}/move", {"to": "approved"})[0] == 403)
    t.check("a task cannot jump to Completed before approval", growth.api("POST", f"admin/tasks/{tid}/move", {"to": "completed"})[0] == 422)
    s, j = admin.api("GET", "admin/approvals")
    t.check("the submitted task waits in the Approval Centre", any(x["id"] == tid and x["category"] == "task_growth" for x in j["items"]))
    s, j = admin.api("POST", "admin/approvals/decide", {"category": "task_growth", "id": tid, "decision": "approve", "reason": ""})
    t.check("an Admin approves it", s == 200 and j["status"] == "approved", (s, j))
    t.check("work starts and finishes", growth.api("POST", f"admin/tasks/{tid}/move", {"to": "in_progress"})[0] == 200 and growth.api("POST", f"admin/tasks/{tid}/move", {"to": "completed"})[0] == 200)
    t.check("Verified is refused without evidence", admin.api("POST", f"admin/tasks/{tid}/move", {"to": "verified"})[0] == 422)
    t.check("a bad evidence link is refused", growth.api("POST", f"admin/tasks/{tid}/evidence", {"kind": "link", "value": "javascript:alert(1)"})[0] == 422)
    t.check("evidence is added", growth.api("POST", f"admin/tasks/{tid}/evidence", {"kind": "metric", "value": "12 of 40 came back, 3 bought (₹897)"})[0] == 200)
    t.check("the person without approve rights cannot verify", growth.api("POST", f"admin/tasks/{tid}/move", {"to": "verified"})[0] == 403)
    s, j = admin.api("POST", f"admin/tasks/{tid}/move", {"to": "verified", "note": "Numbers match the orders list"})
    t.check("an Admin verifies it and is recorded as reviewer", s == 200 and j["task"]["status"] == "verified" and db.q("select reviewer_id from ai_tasks where id=?", tid)[0][0] == uid("ap-admin@test.local"), (s, str(j)[:200]))
    t.check("every move is in the task history", [x[0] for x in db.q("select to_status from ai_task_events where task_id=? order by id", tid)] == ["submitted", "approved", "in_progress", "completed", "completed", "verified"])
    t.check("a verified task cannot move again", admin.api("POST", f"admin/tasks/{tid}/move", {"to": "in_progress"})[0] == 422)

    s, j = admin.api("POST", "admin/tasks", {"agent": "seo", "title": "Fix duplicate titles on guides", "submit": True})
    t2 = j["id"]
    t.check("nobody approves a task they created themselves", admin.api("POST", "admin/approvals/decide", {"category": "task_seo", "id": t2, "decision": "approve"})[0] == 403 and not any(x["id"] == t2 for x in admin.api("GET", "admin/approvals")[1]["items"]))
    t.check("blocking a task needs a reason", o.api("POST", f"admin/tasks/{t2}/move", {"to": "approved"})[0] == 200 and admin.api("POST", f"admin/tasks/{t2}/move", {"to": "blocked"})[0] == 422)
    t.check("a task can only be given to someone who works in that area", admin.api("POST", "admin/tasks", {"agent": "seo", "title": "Check sitemaps for errors", "assignedTo": uid("ap-finance-manager@test.local")})[0] == 422)

    # agent tasks and overdue
    findings = [{"area": "Checkout", "sev": "high", "finding": "Half of checkouts are left at payment", "cause": "UPI app switch fails", "evidence": ["40 started, 20 paid"], "action": "Offer UPI QR", "impact": "₹6,000 a month", "measure": "Paid / started"},
                {"area": "Overall", "sev": "info", "finding": "No clear problem", "cause": "", "evidence": [], "action": "", "impact": "", "measure": ""}]
    code = "echo App\\Services\\AgentTasks::fromGrowthFindings(json_decode(base64_decode('" + __import__("base64").b64encode(json.dumps(findings).encode()).decode() + "'), true));"
    r1 = ctx.inst.artisan("tinker", "--execute=" + code)
    ctx.inst.artisan("tinker", "--execute=" + code)
    rows = db.q("select status, created_by, priority from ai_tasks where title='Half of checkouts are left at payment'")
    t.check("the Growth agent turns a high finding into one submitted task, and only once", len(rows) == 1 and tuple(rows[0]) == ("submitted", None, "high"), (rows, r1.stdout[-200:], r1.stderr[-300:]))
    t.check("information-only findings make no task", db.q("select count(*) from ai_tasks where title='No clear problem'")[0][0] == 0)
    db.q("update ai_tasks set status='approved', due_at=datetime('now','-1 day') where id=?", t2)
    ctx.inst.artisan("tinker", "--execute=echo App\\Services\\AgentTasks::overdue();")
    t.check("an approved task past its due date is marked Overdue", db.q("select status from ai_tasks where id=?", t2)[0][0] == "overdue")
    ah = admin.page("/admin.html")[2]
    t.check("the console has the Approvals & tasks page", 'data-admin-view="approvals"' in ah and "/console/approvals.js" in ah and 'id="admin-approvals"' in ah)
    t.check("staff can load the Approvals script", admin.raw("GET", "/console/approvals.js")[0] == 200)
