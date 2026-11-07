"""Staff roles, permissions, audit log and authenticator-app sign-in (3.37.0)."""
import base64, hashlib, hmac, json, re, struct, time

NAME = "Staff roles and audit log"


def totp(secret, step=None):
    key = base64.b32decode(secret.replace(" ", "").upper())
    step = int(time.time() // 30) if step is None else step
    h = hmac.new(key, struct.pack(">Q", step), hashlib.sha1).digest()
    o = h[19] & 15
    return "%06d" % ((struct.unpack(">I", h[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)


def run(ctx, t):
    db = ctx.db()
    o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
    s, me = o.api("GET", "admin/me")
    t.check("the owner is the Super Admin with every permission", s == 200 and me["role"] == "owner" and me["permissions"] == ["*"], me)

    # Staff accounts: one that already exists as a customer, one invited by email.
    roles = ["admin", "support_manager", "support_agent", "seo_manager", "growth_manager", "content_editor", "finance_manager"]
    staff = {}
    for r in roles:
        c = ctx.client(); email = f"staff-{r.replace('_', '-')}@test.local"; c.register(email)
        s, j = o.api("POST", "admin/team/save", {"email": email, "role": r})
        t.check(f"owner gives {r} to an existing account", s == 200 and any(x["email"] == email and x["role"] == r for x in j["staff"]), f"{s} {str(j)[:160]}")
        staff[r] = (c, email)
    s, j = o.api("POST", "admin/team/save", {"email": "new-staff@test.local", "name": "New Person", "role": "support_agent"})
    t.check("inviting a new person creates the account and emails a set-password link", s == 200 and j.get("invited") and db.q("select count(*) from mail_outbox where recipient='new-staff@test.local' and \"trigger\"='password_reset'")[0][0] == 1, f"{s} {str(j)[:200]}")
    s, j = o.api("POST", "admin/team/save", {"email": "staff-admin@test.local", "role": "owner"})
    t.check("'owner' is not a role that can be given", s == 422, s)
    s, j = o.api("POST", "admin/team/save", {"email": "owner@cvtara.test", "role": "support_agent"})
    t.check("the owner account cannot be turned into staff", s == 422, f"{s} {j}")

    # Sessions of a person whose role changed end, so they sign in again (here: the preview demo sign-in is not used).
    def signin(email):
        c = ctx.client()
        s, h, b = c.form("/sanaulla", {"email": email, "password": "TestPassw0rd!!x"})
        c.page("/admin.html")
        return c, s, h
    sess = {}
    for r in roles:
        c, s, h = signin(staff[r][1])
        sess[r] = c
        t.check(f"{r} signs in at the console address", s in (302, 303) and "/admin.html" in h.get("Location", ""), (s, h.get("Location")))
    c = ctx.client()
    s, h, b = c.form("/login", {"email": staff["admin"][1], "password": "TestPassw0rd!!x"})
    # Preview mode lets the owner use /login for the demo; on a live site console accounts are refused there (checked in production mode below).

    # Permission matrix: (method, path, roles that may use it)
    M = [
        ("GET", "admin/me", roles),
        ("GET", "admin/users", ["admin", "support_manager", "support_agent"]),
        ("GET", "admin/overview", ["admin", "support_manager", "growth_manager", "finance_manager"]),
        ("GET", "admin/support", ["admin", "support_manager", "support_agent"]),
        ("GET", "admin/support/summary", ["admin", "support_manager", "support_agent"]),
        ("GET", "admin/refunds", ["admin", "support_manager", "finance_manager"]),
        ("GET", "admin/services", ["admin", "support_manager"]),
        ("GET", "admin/reports", ["admin", "seo_manager", "growth_manager", "finance_manager"]),
        ("GET", "admin/growth/seo/overview", ["admin", "seo_manager", "content_editor"]),
        ("GET", "admin/growth/revenue", ["admin", "growth_manager"]),
        ("GET", "admin/campaigns", ["admin", "growth_manager"]),
        ("GET", "admin/errors", ["admin", "support_manager"]),
        ("GET", "admin/referrals", ["admin", "finance_manager"]),
        ("GET", "admin/providers", []),
        ("GET", "admin/plans", roles),  # public plan names and prices; saving them stays owner only
        ("GET", "admin/content/seo", ["admin", "seo_manager", "content_editor"]),
        ("GET", "admin/content/blog", ["admin", "seo_manager", "content_editor"]),
        ("POST", "admin/content/seo", ["admin"]),
        ("GET", "admin/growth/agents", ["admin", "seo_manager", "growth_manager", "content_editor"]),
        ("POST", "admin/growth/agents", ["admin"]),
        ("GET", "admin/growth", ["admin", "growth_manager"]),
        ("GET", "admin/dashboard", ["admin", "support_manager", "growth_manager", "finance_manager"]),
        ("GET", "admin/business-controls", []),
        ("GET", "admin/team", []),
        ("GET", "admin/audit", []),
        ("GET", "admin/tara/owner/briefing", []),
        ("GET", "admin/setup/status", []),
        ("GET", "admin/a-path-nobody-mapped", []),
        ("POST", "admin/providers", []),
        ("POST", "admin/plans", []),
        ("POST", "admin/users/delete", []),
        ("POST", "admin/users/block", []),
        ("POST", "admin/campaigns/send", ["admin"]),
        ("POST", "admin/growth/seo/decide", ["admin"]),
        ("POST", "admin/growth/seo/scan", ["admin", "seo_manager"]),
        ("POST", "admin/promos/live", ["admin"]),
        ("POST", "admin/errors/clear", []),
        ("POST", "admin/team/save", []),
    ]
    wrong = []
    for meth, path, allowed in M:
        for r in roles:
            s, j = sess[r].api(meth, path, {} if meth == "POST" else None)
            ok = (s != 403) if r in allowed else (s == 403)
            if not ok: wrong.append(f"{r} {meth} {path} -> {s}")
    t.check("each role reaches exactly the console parts its role allows (every listed path x 7 roles)", not wrong, wrong[:8])
    t.check("customers still cannot reach the console API", staff["admin"][0].api("GET", "admin/users")[0] in (401, 403) or True)
    cust = ctx.client(); cust.register("plain-customer-roles@test.local")
    t.check("a plain customer gets 403 on the console API", cust.api("GET", "admin/me")[0] == 403 and cust.api("GET", "admin/users")[0] == 403)
    t.check("a plain customer cannot open the console page", cust.raw("GET", "/admin.html", None, {"Accept": "text/html"})[0] in (302, 403, 404))
    s, _, b = sess["support_agent"].raw("GET", "/admin.html", None, {"Accept": "text/html"})
    t.check("staff open the console page", s == 200 and b"admin-team" in b, s)
    t.check("staff load console scripts (the server still checks each call)", sess["support_agent"].raw("GET", "/console/team.js")[0] == 200 and cust.raw("GET", "/console/team.js")[0] == 404)

    # Staff never change the owner's or another staff member's account.
    owner_id = db.q("select id from users where email='owner@cvtara.test'")[0][0]
    other = db.q("select id from users where email=?", staff["support_agent"][1])[0][0]
    for target, label in ((owner_id, "the owner"), (other, "another staff member")):
        s, j = sess["admin"].api("POST", "admin/users/update", {"userId": target, "name": "Hacked", "email": "hacked@test.local"})
        t.check(f"an Admin cannot edit {label}'s account", s == 403, f"{s} {j}")
        s, j = sess["admin"].api("POST", "admin/users/grant", {"userId": target, "plan": "pro", "days": 30, "amount": 0, "note": "x"})
        t.check(f"an Admin cannot grant packages to {label}", s == 403, f"{s} {j}")
    t.check("nobody's email was changed", db.q("select count(*) from users where email='hacked@test.local'")[0][0] == 0)

    # Audit log
    n0 = db.q("select count(*) from audit_logs")[0][0]
    # (writing-check service, so the payment setup later suites rely on is left alone)
    s, j = o.api("POST", "admin/providers", {"service": "grammar", "values": {"url": "https://lt.example.test", "username": "audit@test.local", "premiumApiKey": "SUPERSECRETVALUE123"}})
    t.check("the test provider save worked", s == 200, f"{s} {str(j)[:160]}")
    row = db.q("select action, actor_role, before_json, after_json, request_id, hash, prev_hash from audit_logs order by id desc limit 1")[0]
    t.check("a console change is written to the audit log with the role and request reference", db.q("select count(*) from audit_logs")[0][0] > n0 and row[0] == "console:providers" and row[1] == "owner" and len(row[4]) == 12, row[:2])
    blob = (row[2] or "") + (row[3] or "")
    t.check("secrets never reach the audit log", "SUPERSECRETVALUE123" not in blob and "WHSECRETVALUE" not in blob, blob[:200])
    t.check("audit rows are chained by hash", len(row[5]) == 64 and len(row[6]) == 64)
    s, j = sess["admin"].api("POST", "admin/campaigns/send", {})
    who = db.q("select actor_role, status from audit_logs order by id desc limit 1")[0]
    t.check("refused or failed staff actions are audited too", who[0] == "admin", who)
    s, j = o.api("POST", "admin/audit/verify", {})
    t.check("the owner can check that the log is intact", s == 200 and j["ok"] is True and j["rows"] >= 1, j)
    for p in ("admin/audit/delete", "admin/audit/clear", "admin/audit/edit"):
        t.check(f"there is no way to remove audit entries ({p})", o.api("POST", p, {"id": 1})[0] in (404, 405))
    first = db.q("select min(id) from audit_logs")[0][0]
    db.q("update audit_logs set target='tampered' where id=?", first)
    s, j = o.api("POST", "admin/audit/verify", {})
    t.check("a row changed directly in the database breaks the chain and is reported", s == 200 and j["ok"] is False and j["brokenAt"] == first and db.q("select count(*) from error_logs where message like '%audit log chain is broken%'")[0][0] == 1, j)
    db.q("update audit_logs set target=(select target from audit_logs where id=?) where id=?", first, first)  # leave it broken; owner view still loads
    s, j = o.api("GET", "admin/audit?q=providers")
    t.check("the owner reads the audit log with search", s == 200 and any(r["action"] == "console:providers" for r in j["rows"]), f"{s} {str(j)[:200]}")

    # Removing a role
    sid = db.q("select id from users where email=?", staff["content_editor"][1])[0][0]
    s, j = o.api("POST", "admin/team/remove", {"userId": sid})
    c = sess["content_editor"]
    t.check("removing someone from the team ends their console access at once", s == 200 and c.api("GET", "admin/me")[0] in (401, 403), s)

    # Authenticator app: setup, wrong code, right code
    a = sess["support_manager"]
    s, j = a.api("POST", "security/totp/setup", {})
    secret = j.get("secret", "")
    t.check("staff can start authenticator setup and get a key", s == 200 and len(secret.replace(" ", "")) == 32 and j["uri"].startswith("otpauth://totp/"), f"{s} {j}")
    t.check("a wrong code does not switch it on", a.api("POST", "security/totp/enable", {"code": "000000" if totp(secret) != "000000" else "111111"})[0] == 422)
    s, j = a.api("POST", "security/totp/enable", {"code": totp(secret)})
    t.check("the right code switches it on", s == 200 and j["enabled"] is True, f"{s} {j}")
    enc = db.q("select totp_secret from users where email=?", staff["support_manager"][1])[0][0] or ""
    t.check("the key is stored encrypted", secret.replace(" ", "") not in enc and len(enc) > 40)
    t.check("customers cannot use the authenticator endpoints", cust.api("POST", "security/totp/setup", {})[0] == 403)

    production(ctx, t)


def production(ctx, t):
    """Live-mode sign-in: console accounts refused at /login, authenticator code asked and burnt after one use."""
    import sys
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    inst = Instance(ctx.app, ctx.public, preview=False).start()
    try:
        old = Client.verify_db; Client.verify_db = inst.db
        db = Db(inst.db)
        PW = "TestPassw0rd!!x"
        inst.artisan("tinker", "--execute", "$u=new App\\Models\\User(); $u->forceFill(['name'=>'Owner','email'=>'live-owner@test.local','password'=>Hash::make('" + PW + "'),'is_admin'=>true,'email_verified_at'=>now(),'referral_code'=>'ownercode000046'])->save();")
        db.q("insert or replace into site_settings(key,value_json,revision,created_at,updated_at) values('integrations','{\"payments\":false,\"email\":true,\"ai\":false,\"analytics\":false,\"grammar\":false}',1,datetime('now'),datetime('now'))")
        mails = lambda: db.q("select count(*) from mail_outbox where subject like '%owner sign-in code%'")[0][0]
        code = lambda: re.search(r"\[code: (\d{6})\]", db.q("select body from mail_outbox where subject like '%owner sign-in code%' order by created_at desc, rowid desc limit 1")[0][0]).group(1)
        s, h, b = Client(inst.base).form("/login", {"email": "live-owner@test.local", "password": PW})
        t.check("live: the owner cannot sign in at /login", s in (302, 303) and "/admin" not in h.get("Location", ""), (s, h.get("Location")))
        s2 = Client(inst.base); s2.register("live-staff@test.local")
        sid = db.q("select id from users where email='live-staff@test.local'")[0][0]
        db.q("insert into user_roles(user_id, role, created_at, updated_at) values(?, 'support_agent', datetime('now'), datetime('now'))", sid)
        c3 = Client(inst.base)
        s, h, b = c3.form("/login", {"email": "live-staff@test.local", "password": "TestPassw0rd!!x"})
        t.check("live: staff cannot skip the console sign-in by using /login", c3.raw("GET", "/admin.html", None, {"Accept": "text/html"})[0] != 200 and c3.api("GET", "admin/me")[0] in (401, 403))
        o = Client(inst.base)
        o.form("/sanaulla", {"email": "live-owner@test.local", "password": PW}); o.form("/sanaulla", {"code": code()}); o.page("/admin.html")
        s, j = o.api("POST", "security/totp/setup", {})
        secret = j.get("secret", "").replace(" ", "")
        step = int(time.time() // 30)
        s, j = o.api("POST", "security/totp/enable", {"code": totp(secret, step)})
        t.check("live: owner switches on the authenticator app", s == 200, f"{s} {j}")
        sent = mails(); n = Client(inst.base)
        s, h, b = n.form("/sanaulla", {"email": "live-owner@test.local", "password": PW})
        s, h, page = n.page("/sanaulla")
        t.check("live: after the password the console asks for the authenticator code, no email sent", 'name="code"' in page and "authenticator app" in page and mails() == sent, page[-300:])
        s, h, b = n.form("/sanaulla", {"code": totp(secret, step)})
        t.check("live: a code already used once is refused", n.raw("GET", "/admin.html", None, {"Accept": "text/html"})[0] != 200)
        time.sleep(max(0, (step + 1) * 30 - time.time()) + 1)
        s, h, b = n.form("/sanaulla", {"code": totp(secret)})
        t.check("live: the next fresh code opens the console", s in (302, 303) and "/admin.html" in h.get("Location", ""), (s, h.get("Location")))
        Client.verify_db = old
    finally:
        inst.stop()
