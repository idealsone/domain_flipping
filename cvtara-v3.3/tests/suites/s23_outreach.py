"""Outreach: sending mailboxes on another domain, list import with checks, warm-up, send window, spacing,
follow-ups from the same mailbox, tracked clicks, one-click unsubscribe, bounces, automatic stop on sign-up and auto-pause."""
import email, json, re, urllib.parse
from lib.fake_smtp import FakeSmtp
NAME = "Outreach mailboxes, warm-up and stop rules"


def _mail(m):
    msg = email.message_from_string(m["data"]); text = html = ""
    for p in msg.walk():
        if p.get_content_type() == "text/plain": text = p.get_payload(decode=True).decode("utf8", "ignore")
        if p.get_content_type() == "text/html": html = p.get_payload(decode=True).decode("utf8", "ignore")
    return msg, text, html


def run(ctx, t):
    db = ctx.db(); smtp = FakeSmtp()
    try: _run(ctx, t, db, smtp)
    finally: smtp.stop()


def _run(ctx, t, db, smtp):
    o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")

    def cron(at):
        r = ctx.inst.artisan("tinker", f"--execute=Carbon\\Carbon::setTestNow('{at}'); echo json_encode(App\\Services\\Outreach::process());")
        try: return json.loads((r.stdout.strip().splitlines() or ["{}"])[-1])
        except ValueError: return {"_err": (r.stdout + r.stderr)[-300:]}

    # ---- the page is wired into the owner console ----
    s, h, b = o.page("/admin.html")
    t.check("the owner console has an Outreach menu item and page", 'data-admin-view="outreach"' in b and 'id="admin-outreach"' in b and "/console/outreach.js" in b)
    t.check("the Outreach script is served to the owner", o.raw("GET", "/console/outreach.js")[0] == 200)
    s, d = o.api("GET", "admin/outreach")
    t.check("the overview loads with sending off and ready-made mails", s == 200 and d["config"]["enabled"] is False and len(d["config"]["steps"]) >= 1, (s, str(d)[:200]))
    c = ctx.client(); c.register("or-customer@test.local")
    t.check("customers cannot open Outreach", c.api("GET", "admin/outreach")[0] in (401, 403, 404))
    t.check("visitors cannot open the Outreach script", ctx.client().raw("GET", "/console/outreach.js")[0] in (302, 401, 403, 404))

    # ---- mailboxes ----
    box = {"action": "add", "email": "Priya@cvtara.in", "fromName": "Priya from CVTara", "host": "127.0.0.1", "port": 2525, "user": "priya@cvtara.in", "password": "s3cret-pass", "warmupStart": 3, "warmupStep": 2, "dailyCap": 5}
    smtp_port = smtp.port
    s, d = o.api("POST", "admin/outreach/senders", dict(box, port=2525))
    t.check("owner adds a sending mailbox", s == 200 and len(d["senders"]) == 1 and d["senders"][0]["email"] == "priya@cvtara.in", (s, str(d)[:200]))
    sid = d["senders"][0]["id"]
    t.check("the saved password is encrypted and never sent back", "s3cret-pass" not in json.dumps(d) and "s3cret-pass" not in db.q("select smtp_password from outreach_senders")[0][0])
    db.q("update outreach_senders set smtp_port=? where id=?", smtp_port, sid)  # point it at the fake server (the form only offers real SMTP ports)
    t.check("the same mailbox cannot be added twice", o.api("POST", "admin/outreach/senders", box)[0] == 422)
    t.check("a first-day amount above the cap is refused", o.api("POST", "admin/outreach/senders", dict(box, email="x@cvtara.in", warmupStart=60, dailyCap=50))[0] == 422)
    t.check("a server name with odd characters is refused", o.api("POST", "admin/outreach/senders", dict(box, email="y@cvtara.in", host="smtp.host;rm -rf"))[0] == 422)
    t.check("a non-mail port is refused", o.api("POST", "admin/outreach/senders", dict(box, email="z@cvtara.in", port=8080))[0] == 422)
    s, d = o.api("GET", "admin/outreach"); sb = d["senders"][0]
    t.check("warm-up starts at the first-day amount and shows the days to the cap", sb["todayLimit"] == 3 and sb["daysToCap"] == 1, sb)

    s, d = o.api("POST", "admin/outreach/senders", {"action": "test", "id": sid, "to": "owner-test@example.com"})
    got = [m for m in smtp.messages if "owner-test@example.com" in m["to"]]
    t.check("Send test delivers through the mailbox's own server and login", s == 200 and got and got[0]["user"] == "priya@cvtara.in" and "[Test]" in _mail(got[0])[0]["Subject"], (s, d))

    # ---- import with checks ----
    csv = "Email,Name\nasha@example.com,Asha Rao\nnot-an-email,Bad\nnoreply@example.com,Robot\nASHA@example.com,Dup\ndemo@cvtara.test,Existing user\nbounce1@example.com,Gone\nravi@example.com,Ravi Kumar\nmeera@example.com,\"Meera, N\"\nkabir@example.com,=cmd()\n"
    t.check("an import without the consent tick is refused", o.api("POST", "admin/outreach/import", {"text": csv, "checkDomains": False})[0] == 422)
    s, d = o.api("POST", "admin/outreach/import", {"text": csv, "source": "Test fair", "consent": True, "checkDomains": False}); r = d.get("result", {})
    t.check("import adds only clean, new addresses", s == 200 and r.get("added") == 5 and r.get("received") == 9, (s, r))
    t.check("import reports bad, system, duplicate and existing-user addresses separately", r.get("invalid") == 1 and r.get("role") == 1 and r.get("duplicate") == 1 and r.get("users") == 1, r)
    t.check("names with commas inside quotes are kept whole", db.q("select name from outreach_contacts where email='meera@example.com'") == [("Meera, N",)])
    r = o.api("POST", "admin/outreach/import", {"text": csv, "consent": True, "checkDomains": False})[1]["result"]
    t.check("importing the same list again adds nobody", r["added"] == 0 and r["existing"] == 5, r)
    r = o.api("POST", "admin/outreach/import", {"text": "email\nsomeone@nonexistent-domain-cvtara-test.invalid\n", "consent": True, "checkDomains": True})[1]["result"]
    t.check("an address on a domain that cannot receive mail is left out", r["added"] == 0 and r["noMail"] == 1, r)

    # ---- campaign settings ----
    steps = [{"subject": "{{name}}, quick question", "body": "Hi {{name}},\n\nTry the ATS check: {{ats_url}}", "delayDays": 0}, {"subject": "Re: quick question", "body": "Hi {{name}},\n\nFollowing up: {{site_url}}", "delayDays": 2}]
    t.check("a send window that ends before it starts is refused", o.api("POST", "admin/outreach/settings", {"enabled": True, "windowStart": 19, "windowEnd": 9, "footer": "", "steps": steps})[0] == 422)
    t.check("a follow-up on the same day is refused", o.api("POST", "admin/outreach/settings", {"enabled": True, "windowStart": 9, "windowEnd": 19, "footer": "", "steps": [steps[0], dict(steps[1], delayDays=0)]})[0] == 422)
    s, d = o.api("POST", "admin/outreach/settings", {"enabled": True, "windowStart": 9, "windowEnd": 19, "footer": "CVTara · Bengaluru", "steps": steps})
    t.check("owner saves two mails and turns sending on", s == 200 and d["config"]["enabled"] is True, (s, str(d)[:200]))
    s, d = o.api("POST", "admin/outreach/preview", {"subject": "{{name}}, hi", "body": "Hello {{name}} {{site_url}}"})
    t.check("preview fills the name and a tracked link", s == 200 and d["subject"] == "Asha, hi" and "/go/o/" in d["text"] and "Unsubscribe" in d["text"], d)

    # ---- sending: window, spacing, warm-up limit ----
    t.check("nothing is sent outside the send window (8 pm India time)", cron("2026-10-20 14:30:00").get("sent") == 0)
    db.q("update outreach_contacts set created_at='2000-01-01 00:00:00' where email='asha@example.com'")
    db.q("update outreach_contacts set created_at='2099-01-01 00:00:00' where email='bounce1@example.com'")  # keep the bad address for the bounce check below
    before = len(smtp.messages)
    r1 = cron("2026-10-20 04:00:00")  # 09:30 India time
    t.check("one mail goes out inside the window", r1.get("sent") == 1 and len(smtp.messages) == before + 1, r1)
    msg, text, html = _mail(smtp.messages[-1])
    t.check("it goes to the first contact, from the mailbox, personalised", smtp.messages[-1]["to"] == ["asha@example.com"] and "priya@cvtara.in" in msg["From"] and msg["Subject"] == "Asha, quick question", (smtp.messages[-1]["to"], msg["From"], msg["Subject"]))
    t.check("it carries one-click unsubscribe headers", "/email/unsubscribe/o/" in (msg["List-Unsubscribe"] or "") and msg["List-Unsubscribe-Post"] == "List-Unsubscribe=One-Click" and "mailto:priya@cvtara.in" in msg["List-Unsubscribe"])
    t.check("text and HTML both have the tracked link, signature and unsubscribe line", "/go/o/" in text and "CVTara · Bengaluru" in text and "Unsubscribe" in text and "/go/o/" in html and "Unsubscribe" in html)
    asha = db.q("select id,status,step,sender_id,next_at from outreach_contacts where email='asha@example.com'")[0]
    t.check("the contact is in the sequence, tied to this mailbox, with the follow-up due in 2 days", asha[1] == "active" and asha[2] == 1 and asha[3] == sid and asha[4].startswith("2026-10-22"), asha)
    t.check("the mailbox's warm-up started today", db.q("select started_on from outreach_senders where id=?", sid)[0][0].startswith("2026-10-20"))
    t.check("mails are spaced out: a second run straight after sends nothing", cron("2026-10-20 04:05:00").get("sent") == 0)
    t.check("after the spacing gap the next mail goes out", cron("2026-10-20 07:30:00").get("sent") == 1)
    t.check("and the next", cron("2026-10-20 11:00:00").get("sent") == 1)
    db.q("update outreach_senders set last_sent_at='2026-10-20 05:00:00' where id=?", sid)
    t.check("the day's warm-up limit (3) is never passed", cron("2026-10-20 12:00:00").get("sent") == 0 and db.q("select count(*) from outreach_sends where sender_id=? and status='sent'", sid)[0][0] == 3)
    s, d = o.api("GET", "admin/outreach")
    t.check("next day the limit grows by the daily step (3 → 5)", d["senders"][0]["todayLimit"] in (3, 5))
    t.check("the warm-up limit follows the start date", "5" in ctx.inst.artisan("tinker", "--execute=Carbon\\Carbon::setTestNow('2026-10-21 05:00:00'); echo App\\Services\\Outreach::dailyLimit(DB::table('outreach_senders')->first());").stdout)

    # ---- tracked click, unsubscribe ----
    link = re.search(r"(http://[^\s]+/go/o/[^\s]+)", text).group(1)
    s, h, b = ctx.client().raw("GET", link.split("127.0.0.1:" + str(ctx.inst.port))[-1])
    t.check("the tracked link counts the click and opens the page with campaign tags", s in (301, 302) and "utm_source=outreach" in h.get("Location", "") and db.q("select clicks from outreach_contacts where id=?", asha[0])[0][0] == 1, (s, h.get("Location")))
    s, h, b = ctx.client().raw("GET", "/go/o/" + asha[0] + "/1/home")
    t.check("an unsigned click link still opens the page but counts nothing", s in (301, 302) and db.q("select clicks from outreach_contacts where id=?", asha[0])[0][0] == 1)
    unsub = re.search(r"<(http[^>]+/email/unsubscribe/o/[^>]+)>", msg["List-Unsubscribe"]).group(1); path = unsub.split(ctx.inst.base)[-1]
    v = ctx.client(); s, h, b = v.raw("GET", path)
    t.check("the unsubscribe page asks first", s == 200 and b"Stop these emails?" in b, s)
    t.check("a tampered unsubscribe link is refused", v.raw("GET", path.replace("signature=", "signature=0"))[0] == 403)

    # ---- follow-up from the same mailbox; stop on unsubscribe ----
    r = cron("2026-10-22 05:00:00")
    last = smtp.messages[-1]
    t.check("the follow-up goes out when due, from the same mailbox", r.get("sent") == 1 and last["to"] == ["asha@example.com"] and _mail(last)[0]["Subject"] == "Re: quick question", (r, last["to"]))
    t.check("after the last mail the contact's sequence is finished", db.q("select status,step from outreach_contacts where id=?", asha[0])[0] == ("done", 2))
    s, h, b = v._post_raw(path, b"")
    t.check("one click (POST, as mail apps do) unsubscribes without a form token", s == 200 and b"You are unsubscribed" in b and db.q("select status from outreach_contacts where id=?", asha[0])[0][0] == "unsubscribed", s)
    r = o.api("POST", "admin/outreach/import", {"text": "asha@example.com", "consent": True, "checkDomains": False})[1]["result"]
    t.check("an unsubscribed address can never be imported again", r["added"] == 0 and r["suppressed"] == 1, r)

    # ---- automatic stop when the person signs up on CVTara ----
    target = "kabir@example.com"
    ctx.client().register(target)
    t.check("signing up on CVTara stops outreach to that address at once", db.q("select status, user_id is not null from outreach_contacts where email=?", target)[0] == ("registered", 1))
    db.q("insert into outreach_contacts(id,email,name,source,status,step,clicks,error,created_at,updated_at) values('11111111-1111-4111-8111-111111111111','late@example.com','','x','queued',0,0,'','1999-01-01','1999-01-01')")
    db.q("insert into users(name,email,password,created_at,updated_at) values('Late','late@example.com','x',datetime('now'),datetime('now'))")
    db.q("update outreach_senders set last_sent_at=null where id=?", sid); n0 = len(smtp.messages)
    cron("2026-10-23 05:00:00")
    t.check("a contact whose account appeared another way is caught before sending", db.q("select status from outreach_contacts where email='late@example.com'")[0][0] == "registered" and all("late@example.com" not in m["to"] for m in smtp.messages[n0:]))

    # ---- bounces ----
    db.q("update outreach_contacts set created_at='1990-01-01' where email='bounce1@example.com'"); db.q("update outreach_senders set last_sent_at=null where id=?", sid)
    db.q("update outreach_contacts set next_at='2030-01-01' where status='active'")  # follow-ups go first; park them so the next pick is the bad address
    cron("2026-10-24 05:00:00")
    t.check("an address the server rejects (550) is marked bounced for good", db.q("select status from outreach_contacts where email='bounce1@example.com'")[0][0] == "bounced")
    t.check("a bounce does not count against the mailbox's failures", db.q("select failures, enabled from outreach_senders where id=?", sid)[0] == (0, 1))
    s, d = o.api("POST", "admin/outreach/suppress", {"text": "ravi@example.com, new-bounce@example.com", "status": "bounced"})
    t.check("owner can mark addresses as bounced from bounce notices", s == 200 and d["updated"] == 2 and db.q("select status from outreach_contacts where email in ('ravi@example.com','new-bounce@example.com')") == [("bounced",), ("bounced",)], (s, d))

    # ---- a broken mailbox pauses itself; the others carry on ----
    s, d = o.api("POST", "admin/outreach/senders", dict(box, email="arjun@cvtara.in", user="arjun@cvtara.in", password="wrong"))
    bad = [x for x in d["senders"] if x["email"] == "arjun@cvtara.in"][0]["id"]
    db.q("update outreach_senders set smtp_port=? where id=?", smtp_port, bad)
    db.q("insert into outreach_contacts(id,email,name,source,status,step,clicks,error,created_at,updated_at) values('22222222-2222-4222-8222-222222222222','p1@example.com','','x','queued',0,0,'','1980-01-01','1980-01-01'),('33333333-3333-4333-8333-333333333333','p2@example.com','','x','queued',0,0,'','1980-01-02','1980-01-02')")
    for hour in ("05", "06", "07", "08"):
        db.q("update outreach_senders set last_sent_at=null"); cron(f"2026-10-25 {hour}:00:00")
    st = db.q("select enabled, paused_reason from outreach_senders where id=?", bad)[0]
    t.check("a mailbox that fails 3 times in a row pauses itself with the reason", st[0] == 0 and "3 failed" in st[1], st)
    t.check("the working mailbox kept sending meanwhile", db.q("select count(*) from outreach_sends where sender_id=? and created_at like '2026-10-25%' and status='sent'", sid)[0][0] >= 1)
    t.check("the paused mailbox only retried the same person, and that person moved back to the others", db.q("select count(distinct contact_id) from outreach_sends where sender_id=?", bad)[0][0] == 1 and db.q("select count(*) from outreach_contacts where sender_id=? and status='queued'", bad)[0][0] == 0)
    s, d = o.api("POST", "admin/outreach/senders", {"action": "delete", "id": bad})
    t.check("removing a mailbox hands its waiting contacts back to the others", s == 200 and db.q("select count(*) from outreach_contacts where sender_id=?", bad)[0][0] == 0)
    t.check("the classifier tells a missing address from a login problem", "10" == ctx.inst.artisan("tinker", "--execute=echo (int) App\\Services\\Outreach::isRecipientBounce('550 5.1.1 User unknown').(int) App\\Services\\Outreach::isRecipientBounce('535 5.7.8 Authentication failed');").stdout.strip().splitlines()[-1])

    # ---- turning sending off stops everything; list tools ----
    o.api("POST", "admin/outreach/settings", {"enabled": False, "windowStart": 9, "windowEnd": 19, "footer": "", "steps": steps})
    db.q("update outreach_senders set last_sent_at=null"); n0 = len(smtp.messages)
    t.check("with sending off nothing goes out", cron("2026-10-26 05:00:00").get("paused") == "off" and len(smtp.messages) == n0)
    s, d = o.api("GET", "admin/outreach/contacts?status=registered")
    t.check("contacts can be filtered by status", s == 200 and d["total"] >= 2 and all(x["status"] == "registered" for x in d["rows"]))
    s, h, b = o.raw("GET", "/api/admin/outreach/export", None, {"Accept": "text/csv"})
    t.check("the contact list downloads as CSV with formulas made harmless", s == 200 and b.startswith(b"email,name") and b"'=cmd()" in b and b",=cmd()" not in b, (s, b[:120]))
    s, d = o.api("POST", "admin/outreach/stop-queued", {})
    t.check("Stop everyone waiting removes only people not yet mailed", s == 200 and db.q("select count(*) from outreach_contacts where status='queued'")[0][0] == 0 and db.q("select count(*) from outreach_contacts where status='unsubscribed'")[0][0] == 1)
    s, d = o.api("GET", "admin/outreach")
    t.check("the overview shows reached, clicked and signed-up numbers", s == 200 and d["funnel"]["reached"] >= 3 and d["funnel"]["clicked"] == 1, d.get("funnel"))
