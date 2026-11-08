"""Grouped console menu and Ctrl+K search (3.39.0): the search answers only with what the person's role may see."""
NAME = "Console search and grouped menu"


def run(ctx, t):
    db = ctx.db()
    o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
    c = ctx.client(); c.register("findme-kumar@test.local")
    cid = db.q("select id from users where email='findme-kumar@test.local'")[0][0]
    s, j = o.api("POST", "admin/users/grant", {"userId": cid, "plan": "job-hunt", "note": "test", "amount": 299, "sendEmail": False})
    t.check("test order created", s == 200, (s, str(j)[:160]))
    db.q("insert into support_reports(id, owner_id, name, email, area, kind, page, message, status, admin_note, user_agent, viewport, plan, created_at, updated_at) values('aaaaaaaa-1111-2222-3333-444444444444', ?, 'Kumar', 'findme-kumar@test.local', 'billing', 'bug', '/workspace.html', 'Download button does nothing', 'new', '', '', '', '', datetime('now'), datetime('now'))", cid)

    s, j = o.api("GET", "admin/find?q=findme-kumar")
    t.check("the owner finds the customer, the order and the support request", s == 200 and len(j["users"]) == 1 and len(j["orders"]) >= 1 and len(j["tickets"]) == 1 and j["users"][0]["email"] == "findme-kumar@test.local", str(j)[:300])
    t.check("results carry no passwords or secrets", all(k not in str(j) for k in ("password", "totp", "remember_token")))
    t.check("one character searches nothing", o.api("GET", "admin/find?q=f")[1]["users"] == [])
    s, j = o.api("GET", "admin/find?q=%25")
    t.check("a % is searched literally, not as 'everything'", s == 200 and j["users"] == [] and j["orders"] == [], str(j)[:200])
    t.check("search is read-only (POST refused)", o.api("POST", "admin/find?q=findme", {})[0] in (403, 404, 405))
    oid = db.q("select id from purchases where owner_id=?", cid)[0][0]
    t.check("an order is found by the start of its number", any(x["id"] == oid for x in o.api("GET", "admin/find?q=" + oid[:8])[1]["orders"]))

    staff = {}
    for role in ("support_agent", "finance_manager", "content_editor"):
        email = f"find-{role.replace('_', '-')}@test.local"; s1 = ctx.client(); s1.register(email)
        o.api("POST", "admin/team/save", {"email": email, "role": role})
        x = ctx.client(); x.form("/sanaulla", {"email": email, "password": "TestPassw0rd!!x"}); x.page("/admin.html"); staff[role] = x
    s, j = staff["support_agent"].api("GET", "admin/find?q=findme-kumar")
    t.check("a Support Agent finds customers and support requests but no orders", s == 200 and j["users"] and j["tickets"] and j["orders"] == [], str(j)[:200])
    s, j = staff["finance_manager"].api("GET", "admin/find?q=findme-kumar")
    t.check("a Finance Manager finds orders only", s == 200 and j["orders"] and j["users"] == [] and j["tickets"] == [], str(j)[:200])
    s, j = staff["content_editor"].api("GET", "admin/find?q=findme-kumar")
    t.check("a Content Editor finds no customer data", s == 200 and j["users"] == [] and j["orders"] == [] and j["tickets"] == [], str(j)[:200])
    t.check("customers cannot use the console search", c.api("GET", "admin/find?q=findme")[0] == 403)
    t.check("visitors cannot use the console search", ctx.client().api("GET", "admin/find?q=findme")[0] in (401, 403))

    s, _, b = o.raw("GET", "/console/command.js")
    js = b.decode("utf8", "ignore")
    t.check("the palette script loads for the console", s == 200 and "Ctrl" in js and "/api/admin/find?q=" in js)
    t.check("the palette never sends a change by itself (it only opens pages and fills the assistant box)", "method: 'POST'" not in js and "requestSubmit" not in js)
    t.check("customers cannot load the palette script", c.raw("GET", "/console/command.js")[0] == 404)
    s, _, page = o.page("/admin.html")
    t.check("the console page loads the palette", "/console/command.js" in page)
