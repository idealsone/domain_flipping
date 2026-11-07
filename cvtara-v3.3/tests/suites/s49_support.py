"""Support Centre (3.40.0): ticket conversations, staff replies by email, customer replies, support profile and the
read-only customer view (30 minutes, reads only, every request logged, customer A never sees customer B)."""
NAME = "Support Centre and read-only customer view"
SID = "bbbbbbbb-1111-2222-3333-444444444444"


def run(ctx, t):
    db = ctx.db()
    o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
    a = ctx.client(); a.register("sup-a@test.local"); b = ctx.client(); b.register("sup-b@test.local")
    aid = db.q("select id from users where email='sup-a@test.local'")[0][0]; bid = db.q("select id from users where email='sup-b@test.local'")[0][0]
    db.q("insert into support_reports(id, owner_id, name, email, area, kind, page, message, status, admin_note, user_agent, viewport, plan, created_at, updated_at) values(?, ?, 'A', 'sup-a@test.local', 'billing', 'bug', '/workspace.html', 'My download does not start', 'new', '', '', '', '', datetime('now'), datetime('now'))", SID, aid)
    a.api("POST", "resumes", {"data": {"version": "1", "title": "A private resume", "template": "suite-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Asha"}, "summary": "x", "order": ["summary"], "experience": [], "education": [], "projects": [], "certifications": []}})

    # staff
    def staff(role):
        email = f"sup-{role.replace('_', '-')}@test.local"; c = ctx.client(); c.register(email)
        o.api("POST", "admin/team/save", {"email": email, "role": role})
        x = ctx.client(); x.form("/sanaulla", {"email": email, "password": "TestPassw0rd!!x"}); x.page("/admin.html"); return x
    mgr, agent, content = staff("support_manager"), staff("support_agent"), staff("content_editor")

    s, j = agent.api("GET", "admin/support/" + SID)
    t.check("a ticket shows its reference, conversation and whether the view is allowed", s == 200 and j["report"]["reference"].startswith("S-") and j["messages"] == [] and j["canViewAs"] is False and j["canDelete"] is False, str(j)[:300])
    t.check("a Support Agent cannot delete a ticket", agent.api("POST", "admin/support/" + SID, {"action": "delete"})[0] == 403)
    s, j = agent.api("POST", "admin/support/" + SID, {"action": "note", "body": "Checked the logs, looks like a browser block."})
    t.check("staff add an internal note", s == 200 and j["messages"][-1]["internal"] is True, str(j)[:200])
    n0 = db.q("select count(*) from mail_outbox where recipient='sup-a@test.local' and \"trigger\"='support_reply'")[0][0]
    s, j = agent.api("POST", "admin/support/" + SID, {"action": "reply", "body": "Please try again in Chrome; we fixed the button."})
    row = db.q("select status, first_response_at from support_reports where id=?", SID)[0]
    t.check("a staff reply is stored, marks the first response and moves the ticket to working", s == 200 and row[0] == "working" and row[1] is not None, (s, row))
    t.check("the customer is emailed about the reply", db.q("select count(*) from mail_outbox where recipient='sup-a@test.local' and \"trigger\"='support_reply'")[0][0] == n0 + 1)
    s, j = a.api("GET", "support/mine")
    tk = j["tickets"][0] if s == 200 and j.get("tickets") else {}
    t.check("the customer sees the reply but not the internal note or who on the team wrote it", s == 200 and len(tk.get("messages", [])) == 1 and tk["messages"][0]["author"] == "CVTara team" and "browser block" not in str(j), str(j)[:300])
    t.check("another customer sees none of it", b.api("GET", "support/mine")[1]["tickets"] == [])
    t.check("another customer cannot reply to it", b.api("POST", f"support/mine/{SID}/reply", {"body": "hello"})[0] == 404)
    s, j = a.api("POST", f"support/mine/{SID}/reply", {"body": "Works now, thank you!"})
    t.check("the customer replies from their account", s == 200 and j["tickets"][0]["messages"][-1]["author"] == "You")
    s, j = mgr.api("POST", "admin/support/" + SID, {"action": "update", "priority": "high", "assignedTo": db.q("select id from users where email='sup-support-agent@test.local'")[0][0]})
    t.check("a manager sets priority and gives the ticket to an agent", s == 200 and db.q("select priority, assigned_to is not null from support_reports where id=?", SID)[0] == ("high", 1), (s, j))
    s, j = mgr.api("POST", "admin/support/" + SID, {"action": "update", "assignedTo": db.q("select id from users where email='sup-content-editor@test.local'")[0][0]})
    t.check("a ticket cannot go to someone who cannot answer tickets", s == 422)
    s, j = mgr.api("POST", "admin/support/new", {"email": "sup-b@test.local", "message": "Called about a refund; opening a ticket.", "priority": "normal"})
    t.check("staff open a ticket for a customer who phoned", s == 200 and j["reference"].startswith("S-") and db.q("select channel from support_reports where id=?", j["id"])[0][0] == "staff", (s, j))
    t.check("staff cannot open a ticket for a console account", mgr.api("POST", "admin/support/new", {"email": "sup-support-agent@test.local", "message": "hello there"})[0] == 422)

    s, j = agent.api("GET", f"admin/support/users/{aid}")
    p = j.get("profile", {})
    t.check("the support profile shows plan, orders, documents count, tickets and errors", s == 200 and {"user", "plan", "orders", "documents", "tickets", "errors"} <= set(p) and p["documents"]["resumes"] >= 1, str(j)[:300])
    t.check("the profile has no document text, password or payout details", "A private resume" not in str(j) and "password" not in str(j).lower() and "ifsc" not in str(j).lower())
    t.check("a Content Editor cannot open support profiles", content.api("GET", f"admin/support/users/{aid}")[0] == 403)

    # read-only customer view
    t.check("a Support Agent cannot open the customer view", agent.api("POST", "admin/support/view/start", {"customerId": aid, "ticketId": SID, "reason": "Check the download button for this person"})[0] == 403)
    t.check("a reason is required", mgr.api("POST", "admin/support/view/start", {"customerId": aid, "ticketId": SID, "reason": "x"})[0] == 422)
    t.check("the ticket must belong to that customer", mgr.api("POST", "admin/support/view/start", {"customerId": bid, "ticketId": SID, "reason": "Check the download button for this person"})[0] == 422)
    t.check("console accounts cannot be viewed", mgr.api("POST", "admin/support/view/start", {"customerId": db.q("select id from users where email='owner@cvtara.test'")[0][0], "reason": "Check the download button for this person"})[0] == 422)
    s, j = mgr.api("POST", "admin/support/view/start", {"customerId": aid, "ticketId": SID, "reason": "Check the download button for this person"})
    t.check("a Support Manager opens a 30-minute read-only view from the ticket", s == 200 and j["active"] and j["customer"]["email"] == "sup-a@test.local" and 1700 < j["secondsLeft"] <= 1800, (s, j))
    s, j = mgr.api("GET", "resumes")
    t.check("in the view, reads are answered as the customer", s == 200 and "A private resume" in str(j), str(j)[:200])
    s, j = mgr.api("GET", "profile")
    t.check("the profile read is the customer's", s == 200 and "sup-a@test.local" in str(j))
    for meth, path, body in (("POST", "resumes", {"data": {"title": "x"}}), ("POST", "profile", {"name": "Hacked"}), ("POST", "ai/suggest", {"task": "rewrite", "text": "x" * 30, "consent": True}),
                             ("GET", "security/status", None), ("GET", "referrals", None), ("GET", "wallet", None), ("GET", "account/export", None), ("POST", "checkout/start", {"plan": "pro"}),
                             ("POST", f"support/mine/{SID}/reply", {"body": "as customer"})):
        s, _ = mgr.api(meth, path, body)
        t.check(f"in the view, {meth} {path} is refused", s == 403, s)
    t.check("nothing was changed on the customer's account", db.q("select name from users where id=?", aid)[0][0] != "Hacked" and db.q("select count(*) from support_ticket_messages where body='as customer'")[0][0] == 0)
    t.check("the console still works for the staff member during the view", mgr.api("GET", "admin/me")[1].get("role") == "support_manager")
    sess = db.q("select id from support_sessions where staff_id=(select id from users where email='sup-support-manager@test.local') order by started_at desc limit 1")[0][0]
    ev = db.q("select count(*), sum(case when status=403 then 1 else 0 end) from support_session_events where session_id=?", sess)[0]
    t.check("every request in the view is logged, refused ones too", ev[0] >= 11 and ev[1] >= 9, ev)
    t.check("the ticket notes that the view was opened, with the reason", db.q("select count(*) from support_ticket_messages where ticket_id=? and author_type='system' and body like '%read-only view%Check the download%'", SID)[0][0] == 1)
    t.check("starting the view is in the audit log", db.q("select count(*) from audit_logs where action='support:view_as_start'")[0][0] == 1)
    s, j = o.api("GET", "admin/support/view/sessions")
    t.check("the owner sees every view session with its request counts", s == 200 and j["sessions"][0]["requests"] >= 11 and j["sessions"][0]["refused"] >= 9, str(j)[:200])
    # expiry
    db.q("update support_sessions set expires_at=datetime('now','-1 minute') where id=?", sess)
    s, j = mgr.api("GET", "resumes")
    t.check("after 30 minutes the view ends by itself and reads are the staff member's own again", s == 200 and "A private resume" not in str(j) and db.q("select ended_at is not null from support_sessions where id=?", sess)[0][0] == 1, str(j)[:160])
    # owner ends a view
    mgr.api("POST", "admin/support/view/start", {"customerId": aid, "ticketId": SID, "reason": "Second look at the download button"})
    sess2 = db.q("select id from support_sessions where ended_at is null")[0][0]
    t.check("the owner can end any open view", o.api("POST", f"admin/support/view/sessions/{sess2}/end", {})[0] == 200 and "A private resume" not in str(mgr.api("GET", "resumes")[1]))
    t.check("staff cannot end someone else's view", mgr.api("POST", f"admin/support/view/sessions/{sess2}/end", {})[0] == 403)
    # role removed mid-view
    mgr.api("POST", "admin/support/view/start", {"customerId": aid, "ticketId": SID, "reason": "Third look at the download button"})
    db.q("update user_roles set role='content_editor' where user_id=(select id from users where email='sup-support-manager@test.local')")
    import time; time.sleep(1)
    s, j = mgr.api("GET", "support-view")
    t.check("a view ends at once when the staff member loses the permission", j.get("active") is False or s in (401, 403), (s, j))
    t.check("customers cannot open a view", a.api("POST", "admin/support/view/start", {"customerId": bid, "reason": "Check the download button"})[0] == 403)
