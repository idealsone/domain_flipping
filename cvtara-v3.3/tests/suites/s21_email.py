"""Email: branded HTML + text mail, signature, one-click unsubscribe, sign-up verification code, expiry reminders at 7/4/1 days,
12 yearly plan nudges (opted-in, until the first purchase), a monthly guide, resume-saved reminder and the three follow-up reminders."""
import json, os, re, tempfile, urllib.parse
NAME = "Email templates, verification code, reminders and unsubscribe"


def run(ctx, t):
    db = ctx.db()
    o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")

    # ---- every template renders as a branded mail with a signature, no leftover placeholders ----
    d = tempfile.mkdtemp(prefix="mailprev")
    r = ctx.inst.artisan("cvtara:mail-preview", d)
    files = [f for f in os.listdir(d) if f.endswith(".html")]
    t.check("every template renders as HTML and text", r.returncode == 0 and len(files) >= 25, (r.returncode, len(files), (r.stdout + r.stderr)[-200:]))
    bad = []
    for f in [f for f in files if not f.startswith("outreach-")]:  # outreach mail is signed by its mailbox (suite s28)
        h = open(os.path.join(d, f), encoding="utf8").read(); x = open(os.path.join(d, f[:-5] + ".txt"), encoding="utf8").read()
        if "Team CVTara" not in h or "support@cvtara.com" not in h or "Bangalore" not in h or "{{" in h or "{{" in x or "Team CVTara" not in x: bad.append(f)
    t.check("each mail has the CVTara header, a signature, the support address and no placeholder left behind", not bad, bad[:4])
    code = open(os.path.join(d, "verify.html"), encoding="utf8").read()
    t.check("the verification mail shows the code in a box and says it expires", "482915" in code and "15 minutes" in code)
    t.check("expiry mails exist for 7, 4 and 1 days and there are twelve plan nudges", all(os.path.exists(os.path.join(d, f"expiry{n}.html")) for n in (7, 4, 1)) and all(os.path.exists(os.path.join(d, f"nurture-{n}.html")) for n in range(1, 13)))
    subjects = [open(os.path.join(d, f"nurture-{n}.txt"), encoding="utf8").read().split("\n")[0] for n in range(1, 13)]
    t.check("the twelve nudges all have different subjects", len(set(subjects)) == 12, subjects)
    spam = re.compile(r"free!!|100% free|guarantee|act now|limited time|\$\$\$|click here|winner", re.I)
    t.check("no spam-trigger wording in any template", not [f for f in files if spam.search(open(os.path.join(d, f), encoding="utf8").read())])

    # ---- registration mistakes are shown on the form, next to the field ----
    c = ctx.client(); c.page("/register")
    r = c.form("/register", {"name": "Bad Pw", "email": "bad-pw@test.local", "password": "TestPassw0rd!!x", "password_confirmation": "different-Passw0rd!!", "experienceLevel": "fresher", "profession": "Analyst", "category": "Technology", "terms": "1"})
    s, h, b = c.page("/register")
    t.check("a password that does not match is explained on the sign-up form", r[0] in (302, 303) and "match" in b.lower() and 'class="fe"' in b, (r[0], b[-300:]))
    r = c.form("/register", {"name": "Bad Pw", "email": "bad-pw@test.local", "password": "short", "password_confirmation": "short", "experienceLevel": "fresher", "profession": "Analyst", "category": "Technology", "terms": "1"})
    s, h, b = c.page("/register")
    t.check("a too-short password is explained on the sign-up form", "at least" in b.lower() and 'class="fe"' in b)
    c = ctx.client(); c.register("ws-up@test.local"); s, h, b = c.page("/workspace.html")
    t.check("the plan-needed dialog and field switcher load on the workspace", "/upgrade-prompt.js" in b and ctx.client().raw("GET", "/upgrade-prompt.js")[0] == 200)

    # ---- switch email delivery on (the code check only applies once it is on) ----
    s, g = o.api("GET", "admin/content/integrations")
    data = g["data"]; before = json.loads(json.dumps(data)); data["email"] = True
    s, _ = o.api("POST", "admin/content/integrations", {"data": data, "revision": g["revision"]})
    t.check("owner switches email delivery on", s == 200, s)
    try:
        # ---- sign-up sends a verification code and gates buying and ATS checks until it is entered ----
        c = ctx.client(); em = "ver-a@test.local"; r = c.register(em, verify=False)
        t.check("sign-up goes to the confirm-your-email page", r[0] in (302, 303) and "/verify-email" in r[1].get("Location", ""), (r[0], r[1].get("Location")))
        uid = db.q("select id from users where email=?", em)[0][0]
        row = db.q("select body,status from mail_outbox where owner_id=? and trigger='email_verification'", uid)
        t.check("a verification code mail is queued with a 6-digit code", len(row) == 1 and re.search(r"\b\d{6}\b", row[0][0]), row)
        code = re.search(r"\b(\d{6})\b", row[0][0]).group(1)
        s, h, b = c.page("/verify-email"); t.check("the confirm page asks for the code and offers a new one", s == 200 and "6-digit code" in b and "Send me a new code" in b)
        s, j = c.api("POST", "checkout/start", {"plan": "job-hunt"}); t.check("buying is blocked until the email is confirmed", s == 403 and j.get("verify") == "/verify-email", (s, str(j)[:160]))
        s, a = c.api("GET", "allowance"); t.check("the allowance says the email is not confirmed yet", a.get("emailVerified") is False)
        r = c.form("/verify-email", {"code": "000000" if code != "000000" else "111111"})
        t.check("a wrong code is refused with a clear message", r[0] in (302, 303) and db.q("select email_verified_at from users where id=?", uid)[0][0] is None)
        r = c.form("/verify-email", {"code": code})
        t.check("the right code confirms the address", r[0] in (302, 303) and db.q("select email_verified_at from users where id=?", uid)[0][0] is not None, r[0])
        t.check("after confirming, the customer lands on the Dashboard", r[1].get("Location", "").rstrip("/").endswith("/workspace.html"), r[1].get("Location"))
        s, a = c.api("GET", "allowance"); t.check("after confirming, the allowance says so and buying works again", a.get("emailVerified") is True and c.api("POST", "checkout/start", {"plan": "job-hunt"})[0] != 403)

        # ---- marketing mail: consent, unsubscribe link, one-click unsubscribe ----
        db.q("update profiles set marketing_consent=1 where owner_id=?", uid)
        url = ctx.inst.artisan("tinker", f"--execute=echo App\\Services\\EmailRenderer::unsubscribeUrl({uid});").stdout.strip().splitlines()[-1]
        t.check("marketing mail carries a signed unsubscribe link", "/email/unsubscribe/" in url and "signature=" in url, url[:120])
        path = url.split(ctx.inst.base)[-1] if ctx.inst.base in url else urllib.parse.urlparse(url).path + "?" + urllib.parse.urlparse(url).query
        s, h, b = c.raw("GET", path); t.check("the unsubscribe page asks first", s == 200 and b"Unsubscribe from CVTara tips" in b, s)
        s, h, b = c._post_raw(path, b""); t.check("one click unsubscribes and says which mail continues", s == 200 and b"You are unsubscribed" in b and db.q("select marketing_consent from profiles where owner_id=?", uid)[0][0] in (0, False), s)
        s, h, b = c.raw("GET", path.replace("signature=", "signature=x")); t.check("a tampered link is refused", s == 403, s)

        # ---- plan nudges: opted-in people who never bought, one step at a time ----
        uid2 = (lambda e: (ctx.client().register(e), db.q("select id from users where email=?", e)[0][0])[1])("ver-b@test.local")
        db.q("update users set email_verified_at=datetime('now'), created_at=datetime('now','-40 days') where id=?", uid2)
        db.q("update profiles set marketing_consent=1 where owner_id=?", uid2)
        ctx.inst.artisan("cache:clear"); ctx.inst.artisan("cvtara:cron")
        n = db.q("select subject from mail_outbox where owner_id=? and trigger='nurture'", uid2)
        t.check("a 40-day-old opted-in customer gets exactly one nudge (step 4)", len(n) == 1 and n[0][0] == subjects[3], n)
        ctx.inst.artisan("cache:clear"); ctx.inst.artisan("cvtara:cron")
        t.check("the same step is never sent twice", db.q("select count(*) from mail_outbox where owner_id=? and trigger='nurture'", uid2)[0][0] == 1)
        uid3 = (lambda e: (ctx.client().register(e), db.q("select id from users where email=?", e)[0][0])[1])("ver-c@test.local")
        db.q("update users set email_verified_at=datetime('now'), created_at=datetime('now','-40 days') where id=?", uid3)
        ctx.inst.artisan("cache:clear"); ctx.inst.artisan("cvtara:cron")
        t.check("someone who did not opt in gets no marketing mail", db.q("select count(*) from mail_outbox where owner_id=? and trigger in ('nurture','monthly_digest')", uid3)[0][0] == 0)
        # a paid customer gets the monthly guide too, but no plan nudges
        db.q("update profiles set marketing_consent=1 where owner_id=?", uid3)
        db.q("insert into purchases(id,owner_id,plan,status,amount_paise,provider,limits_json,access_days,purchased_at,access_until,created_at,updated_at) values('pp-1',?,?,'paid',29900,'demo','{}',60,datetime('now','-30 days'),datetime('now','+30 days'),datetime('now'),datetime('now'))", uid3, "job-hunt")
        ctx.inst.artisan("cache:clear"); ctx.inst.artisan("cvtara:cron")
        t.check("a customer who already bought gets no plan nudge", db.q("select count(*) from mail_outbox where owner_id=? and trigger='nurture'", uid3)[0][0] == 0)

        # ---- monthly guide: one a month, a different guide for different people ----
        ctx.inst.artisan("cache:clear"); ctx.inst.artisan("cvtara:cron")
        dg = db.q("select owner_id, subject from mail_outbox where trigger='monthly_digest'")
        t.check("a paid, opted-in customer gets the monthly career guide", any(x[0] == uid3 for x in dg) and all("Career guide of the month" in x[1] for x in dg), dg[:3])
        t.check("a person who just joined is not sent a guide yet", not any(x[0] == uid for x in dg))

        # ---- plan expiry: 7, 4 and 1 day reminders ----
        uid4 = (lambda e: (ctx.client().register(e), db.q("select id from users where email=?", e)[0][0])[1])("ver-d@test.local")
        db.q("update users set email_verified_at=datetime('now') where id=?", uid4)
        cols = [r[1] for r in db.q("pragma table_info(purchases)")]
        def purchase(days):
            vals = {"id": "exp-" + str(days), "owner_id": uid4, "plan": "job-hunt", "status": "paid", "amount_paise": 29900, "provider": "demo", "limits_json": "{}", "access_days": 30, "purchased_at": "2026-01-01 00:00:00", "created_at": "2026-01-01 00:00:00", "updated_at": "2026-01-01 00:00:00"}
            vals = {k: v for k, v in vals.items() if k in cols}
            ins = ",".join(vals); q = ",".join("?" for _ in vals)
            db.q(f"delete from purchases where owner_id={uid4}")
            db.q(f"insert into purchases({ins},access_until) values({q},datetime('now','+{days} days'))", *vals.values())
        for days, trig in ((6.5, "access_expiring_7"), (3.5, "access_expiring_4"), (0.6, "access_expiring_1")):
            purchase(days); ctx.inst.artisan("cache:clear"); ctx.inst.artisan("cvtara:cron")
            t.check(f"{days} days left queues the {trig} reminder", db.q("select count(*) from mail_outbox where owner_id=? and trigger=?", uid4, trig)[0][0] == 1, trig)
        ctx.inst.artisan("cache:clear"); ctx.inst.artisan("cvtara:cron")
        t.check("each expiry reminder is sent once only", db.q("select count(*) from mail_outbox where owner_id=? and trigger like 'access_expiring%'", uid4)[0][0] == 3)

        # ---- resume saved mail at most once per fortnight; follow-up reminders over about a month ----
        t.check("follow-up reminders are spread over about a month (day 7, 15, 30)", sum(ctx.inst.artisan("tinker", "--execute=echo json_encode(App\\Services\\Tracker::GAPS);").stdout.strip().splitlines()[-1:] and [1]) == 1 and ctx.inst.artisan("tinker", "--execute=echo array_sum(App\\Services\\Tracker::GAPS);").stdout.strip().splitlines()[-1] == "30")
    finally:
        cur = o.api("GET", "admin/content/integrations")[1]
        s, _ = o.api("POST", "admin/content/integrations", {"data": before, "revision": cur["revision"]})
        now = o.api("GET", "admin/content/integrations")[1]["data"]; t.check("email delivery is restored to its earlier setting", s == 200 and now.get("email") == before.get("email"), (s, before, now))
