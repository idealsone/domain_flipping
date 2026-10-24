"""Mail delivery: the shared design and its spam check, sign-up and security codes sent at once (never behind campaigns),
headers that help inbox placement (aligned From, Message-ID on the own domain, one-click unsubscribe on marketing only,
text + HTML), the mailbox's 24-hour limit with room kept for account mail, live offers (never a stale one), current
package names and prices, and old ready-made wording replaced."""
import email, json, os, re, subprocess, sys, tempfile
from lib.fake_smtp import FakeSmtp
NAME = "Mail delivery, timing, headers and templates"


def _parts(m):
    msg = email.message_from_string(m["data"]); text = html = ""
    for p in msg.walk():
        if p.get_content_type() == "text/plain": text = p.get_payload(decode=True).decode("utf8", "ignore")
        if p.get_content_type() == "text/html": html = p.get_payload(decode=True).decode("utf8", "ignore")
    return msg, text, html


def run(ctx, t):
    db = ctx.db(); smtp = FakeSmtp()
    keys = ("providers", "integrations", "campaignSettings", "promos", "business_controls", "emails")
    saved = {k: db.q("select value_json, revision from site_settings where key=?", k) for k in keys}
    try: _run(ctx, t, db, smtp)
    finally:
        smtp.stop()
        for k, rows in saved.items():
            db.q("delete from site_settings where key=?", k)
            if rows: db.q("insert into site_settings(key,value_json,revision,created_at,updated_at) values(?,?,?,datetime('now'),datetime('now'))", k, rows[0][0], rows[0][1])
        ctx.inst.artisan("cache:clear")


def _run(ctx, t, db, smtp):
    root = os.path.dirname(ctx.app)
    o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
    tinker = lambda code: (ctx.inst.artisan("tinker", "--execute=" + code).stdout.strip().splitlines() or [""])[-1]

    # ---- every template, both systems: one design, and the spam-style check passes ----
    db.q("insert or replace into site_settings(key,value_json,revision,created_at,updated_at) values('promos','{\"campaigns\":[]}',1,datetime('now'),datetime('now'))"); ctx.inst.artisan("cache:clear")
    d = tempfile.mkdtemp(prefix="maillint")
    ctx.inst.artisan("cvtara:mail-preview", d)
    files = sorted(f[:-5] for f in os.listdir(d) if f.endswith(".html"))
    t.check("previews cover account mail and both outreach lists", "verify" in files and "outreach-students-1" in files and "outreach-colleges-1" in files and len(files) >= 34, files[:5])
    r = subprocess.run([sys.executable, os.path.join(root, "tools", "mail_lint.py"), d], capture_output=True, text=True)
    t.check("the spam-style check finds no errors in any template", r.returncode == 0, r.stdout[-800:])
    read = lambda n, ext="html": open(os.path.join(d, f"{n}.{ext}"), encoding="utf8").read()
    shared = [n for n in files if not n.startswith("outreach-colleges")]
    t.check("each designed mail is 600px, table layout, dark-mode aware, with a preview line", all('max-width:600px' in read(n) and 'role="presentation"' in read(n) and "prefers-color-scheme:dark" in read(n) and 'display:none' in read(n) for n in shared), [n for n in shared if "prefers-color-scheme:dark" not in read(n)][:3])
    acct = [n for n in files if not n.startswith("outreach")]
    t.check("each account mail has the footer identity, support address and a plain-text part", all("CVTara · " in read(n) and "support@cvtara.com" in read(n) and len(read(n, "txt")) > 150 for n in acct))
    stale = re.compile(r"cashfree|fresher standard|\bbasic plan|\bstandard plan|refer 5 friends|free month|subscription|countdown|timer ends|ends soon|hurry", re.I)
    t.check("no old plan names, other payment gateways, old referral rules or false urgency in any mail", not [n for n in files if stale.search(read(n) + read(n, "txt"))], [n for n in files if stale.search(read(n) + read(n, "txt"))][:4])
    t.check("package names and prices come from Pricing (Quick Download ₹99, Job Hunt ₹299, Pro ₹599)", all(x in read("download-locked") for x in ("Quick Download", "₹99", "Job Hunt", "₹299", "Pro", "₹599", "Razorpay", "7 days")))
    t.check("the referral mail is Give ₹50, get ₹50 on packages of ₹299 and up", "Give ₹50, get ₹50" in read("nurture-11", "txt") and "₹299 or more" in read("nurture-11", "txt"))
    t.check("with no offer running, no mail mentions a code or an offer", not [n for n in files if re.search(r"with code|% off", read(n, "txt"), re.I)])
    t.check("the receipt says paid once, 7-day refund and Razorpay", all(x in read("purchase", "txt") for x in ("does not renew", "7 days", "Razorpay")))
    t.check("security mails carry no unsubscribe link and a do-not-share note", all(">Unsubscribe<" not in read(n) and "Never share" in read(n) for n in ("verify", "reset")))

    # ---- the live offer, read when the mail is sent ----
    promos = {"campaigns": [{"id": "11111111-2222-4333-8444-555555555555", "name": "Diwali", "mode": "live", "badge": "Diwali offer", "heading": "Diwali offer on every package", "subheading": "", "body": "", "cta": "", "couponCode": "DIWALI20", "planKey": "", "theme": "diwali", "startsAt": "", "endsAt": "2099-11-02T23:59"}]}
    bc = {"coupons": [{"code": "DIWALI20", "type": "percent", "value": 20, "enabled": True, "plans": ["quick", "job-hunt", "pro"], "expiresAt": "", "maxUses": 0, "perUser": 1}]}
    put = lambda k, v: db.q("insert or replace into site_settings(key,value_json,revision,created_at,updated_at) values(?,?,1,datetime('now'),datetime('now'))", k, json.dumps(v))
    put("promos", promos); put("business_controls", bc); ctx.inst.artisan("cache:clear")
    d2 = tempfile.mkdtemp(prefix="mailoffer"); ctx.inst.artisan("cvtara:mail-preview", d2)
    dl = open(os.path.join(d2, "download-locked.html"), encoding="utf8").read()
    t.check("a live offer shows its code and discount, and the button applies the coupon", "DIWALI20" in dl and "20% off" in dl and "checkout.html?coupon=DIWALI20" in dl, re.findall(r"checkout\.html[^\"]*", dl)[:2])
    t.check("offers never appear in security mails", "DIWALI20" not in open(os.path.join(d2, "verify.html"), encoding="utf8").read())

    # ---- real delivery through an SMTP server ----
    s, g = o.api("GET", "admin/content/integrations"); data = g["data"]; data["email"] = True
    t.check("owner switches email delivery on", o.api("POST", "admin/content/integrations", {"data": data, "revision": g["revision"]})[0] == 200)
    s, _ = o.api("POST", "admin/providers", {"service": "email", "values": {"provider": "smtp", "smtpHost": "127.0.0.1", "smtpPort": str(smtp.port), "smtpUser": "support@cvtara.com", "smtpPassword": "pw", "fromEmail": "support@cvtara.com", "fromName": "CVTara", "replyTo": "support@cvtara.com", "postalAddress": "CVTara, 12 MG Road, Bengaluru 560001"}})
    t.check("owner saves the mail sender with a postal address", s == 200, s)

    # a big campaign waiting in the queue must not hold up a sign-up code
    db.q("update mail_outbox set status='cancelled' where status in ('queued','failed')")  # start from a quiet queue (earlier suites leave mail)
    db.q("insert into email_contacts(email,name,source,token,confirmed_at,created_at,updated_at) " + " union all ".join(
        f"select 'c{i}@example.com','C','test','{'t' * 40}{i:08d}',datetime('now'),datetime('now'),datetime('now')" for i in range(120)))
    db.q("insert into mail_outbox(id,dedupe,owner_id,contact_id,trigger,recipient,subject,body,status,attempts,due_at,created_at,updated_at) select 'camp-'||id,'camp-'||id,null,id,'campaign',email,'Hello','# Hello\nHi there, a short note from CVTara with one useful tip for your job search this week.','queued',0,datetime('now','-1 minute'),datetime('now'),datetime('now') from email_contacts where source='test'")
    c = ctx.client(); n0 = len(smtp.messages); c.register("otp-now@test.local", verify=False)
    got = [m for m in smtp.messages[n0:] if m["to"] == ["otp-now@test.local"]]
    t.check("the sign-up code is sent at once, inside the sign-up request, without waiting for the cron", len(got) == 1 and re.search(r"\b\d{6}\b", _parts(got[0])[0]["Subject"] or ""), [m["to"] for m in smtp.messages[n0:]][:3])
    row = db.q("select status, body from mail_outbox where recipient='otp-now@test.local' and trigger='email_verification'")
    t.check("the code mail is marked sent and the code is removed from the queue", row and row[0][0] == "sent" and not re.search(r"\b\d{6}\b", row[0][1]), row)
    t.check("none of the waiting campaign mail went out ahead of it", db.q("select count(*) from mail_outbox where trigger='campaign' and status='sent'")[0][0] == 0)
    msg, text, html = _parts(got[0])
    dom = lambda h: (re.search(r"@([^>\s]+)>?", h or "") or [None, ""])[1].strip(">").lower()
    t.check("From is the cvtara.com sender, Reply-To matches, Message-ID is on cvtara.com", "support@cvtara.com" in msg["From"] and "support@cvtara.com" in (msg["Reply-To"] or "") and dom(msg["Message-ID"]) == "cvtara.com", (msg["From"], msg["Reply-To"], msg["Message-ID"]))
    t.check("the mail is multipart: plain text and HTML", msg.get_content_type() == "multipart/alternative" and text.strip() and "<html" in html)
    t.check("a security code mail has no List-Unsubscribe header", msg["List-Unsubscribe"] is None and msg["List-Unsubscribe-Post"] is None)
    t.check("the owner's postal address is in the footer of both parts", "12 MG Road" in html and "12 MG Road" in text)

    # password reset: also immediate
    n0 = len(smtp.messages); c2 = ctx.client(); c2.page("/forgot-password"); c2.form("/forgot-password", {"email": "otp-now@test.local"})
    t.check("a password reset link is sent at once too", any(m["to"] == ["otp-now@test.local"] and "Reset" in (_parts(m)[0]["Subject"] or "") for m in smtp.messages[n0:]), [_parts(m)[0]["Subject"] for m in smtp.messages[n0:]])

    # one cron run: codes first, a bounded batch, campaigns after account mail
    uid = db.q("select id from users where email='otp-now@test.local'")[0][0]
    db.q("insert into mail_outbox(id,dedupe,owner_id,trigger,recipient,subject,body,status,attempts,due_at,created_at,updated_at) values('rcpt-1','rcpt-1',?,'purchase_verified','otp-now@test.local','Receipt','# Thanks\nHi','queued',0,datetime('now'),datetime('now'),datetime('now'))", uid)
    n0 = len(smtp.messages); ctx.inst.artisan("cvtara:cron"); batch = smtp.messages[n0:]
    t.check("one cron run sends at most a bounded batch (40 per pass)", 0 < len(batch) <= 80, len(batch))
    t.check("account mail goes before campaign mail in the same run", batch and batch[0]["to"] == ["otp-now@test.local"], [m["to"] for m in batch[:2]])
    camp = next((m for m in batch if re.match(r"c\d+@example\.com$", m["to"][0])), None)
    cm = _parts(camp)[0] if camp else {}
    t.check("campaign (marketing) mail carries one-click List-Unsubscribe and Feedback-ID headers", camp and "<http" in (cm["List-Unsubscribe"] or "") and cm["List-Unsubscribe-Post"] == "List-Unsubscribe=One-Click" and cm["Feedback-ID"])

    # mailbox limit: tips and campaigns wait, account mail still goes
    db.q("delete from mail_outbox where trigger='campaign'")
    put("campaignSettings", {"batchSize": 20, "dailyCap": 300, "mailboxCap": 160})
    db.q("insert into mail_outbox(id,dedupe,owner_id,trigger,recipient,subject,body,status,attempts,due_at,sent_at,created_at,updated_at) " + " union all ".join(
        f"select 'old-{i:03d}','old-{i:03d}',null,'nurture','o{i}@example.com','x','x','sent',1,datetime('now','-2 hours'),datetime('now','-2 hours'),datetime('now','-2 hours'),datetime('now')" for i in range(70)))
    db.q("insert into mail_outbox(id,dedupe,owner_id,trigger,recipient,subject,body,status,attempts,due_at,created_at,updated_at) values('tip-1','tip-1',?,'nurture','otp-now@test.local','Tip','# Tip\nHi','queued',0,datetime('now'),datetime('now'),datetime('now')),('rcpt-2','rcpt-2',?,'purchase_verified','otp-now@test.local','Receipt 2','# Thanks\nHi','queued',0,datetime('now'),datetime('now'),datetime('now'))", uid, uid)
    db.q("update profiles set marketing_consent=1 where owner_id=?", uid)
    ctx.inst.artisan("cache:clear"); ctx.inst.artisan("cvtara:cron")
    st = dict(db.q("select id, status from mail_outbox where id in ('tip-1','rcpt-2')"))
    t.check("near the mailbox's 24-hour limit, tips wait and receipts still go out", st.get("tip-1") == "queued" and st.get("rcpt-2") == "sent", st)
    put("campaignSettings", {"batchSize": 20, "dailyCap": 300, "mailboxCap": 1000})
    ctx.inst.artisan("cache:clear"); ctx.inst.artisan("cvtara:cron")
    t.check("with room again, the waiting tip goes out on the next run", db.q("select status from mail_outbox where id='tip-1'")[0][0] == "sent")
    t.check("the owner can set the mailbox limit and campaign limits", o.api("POST", "admin/campaigns/settings", {"batchSize": 20, "dailyCap": 300, "mailboxCap": 1000})[0] == 200 and o.api("POST", "admin/campaigns/settings", {"batchSize": 20, "dailyCap": 300, "mailboxCap": 10})[0] == 422)

    # an offer that ends while a mail waits is not mailed
    db.q("insert into mail_outbox(id,dedupe,owner_id,trigger,recipient,subject,body,status,attempts,due_at,created_at,updated_at) values('aband-1','aband-1',?,'abandoned_purchase','otp-now@test.local','Waiting','# Waiting\nHi\n\n[offer]\n\n[button: Finish | {{checkout_url}}]','queued',0,datetime('now'),datetime('now'),datetime('now'))", uid)
    put("promos", {"campaigns": []}); ctx.inst.artisan("cache:clear"); n0 = len(smtp.messages); ctx.inst.artisan("cvtara:cron")
    ab = [m for m in smtp.messages[n0:] if (_parts(m)[0]["Subject"] or "") == "Waiting"]
    t.check("an offer that ended before sending is left out, and the button goes to plain checkout", ab and "DIWALI20" not in ab[0]["data"] and "/checkout.html" in _parts(ab[0])[1], len(ab))

    # ---- health checks for the sender ----
    snap = json.loads(tinker("config(['app.url' => 'https://cvtara.com']); echo json_encode(App\\Services\\Health::snapshot()['checks']);") or "{}")
    t.check("Setup & health checks the sender domain, the postal address and that the queue is moving", snap.get("emailSenderAligned") is True and snap.get("emailPostalAddress") is True and "mailQueueMoving" in snap, snap)

    # ---- old ready-made wording the owner saved without changes is replaced ----
    old = {"id": "nurture-11", "name": "x", "trigger": "nurture", "enabled": True, "delayHours": 0, "subject": "Refer 5 friends, get a free month", "body": "# Refer 5 friends, get a free month\nHi {{name}},\n\nKnow someone job hunting? When 5 friends you refer sign up, confirm their email and buy a plan, you unlock one free resume download and one month of subscription, free. Your link and your progress are in your workspace.\n\n[button: Open my referral page | {{site_url}}/workspace.html?view=referrals]\nNot for you right now? You can unsubscribe with one click below. We will keep sending only account emails such as receipts and sign-in codes."}
    mine = {"id": "welcome", "name": "w", "trigger": "profile_created", "enabled": True, "delayHours": 0, "subject": "My own welcome", "body": "# Hi\nMy own words"}
    put("emails", [old, mine]); ctx.inst.artisan("cache:clear")
    s, g = o.api("GET", "admin/content/emails")
    by = {x["id"]: x for x in g["data"]}
    t.check("an untouched old template shows the current wording, and the owner's own text is kept", "Give {{referral_discount}}" in by["nurture-11"]["subject"] and by["welcome"]["subject"] == "My own welcome", (by["nurture-11"]["subject"], by["welcome"]["subject"]))
    # ---- owner console preview shows the real branded HTML email ----
    s, pv = o.api("POST", "admin/email/preview", {"subject": "Hello {{name}}", "body": "# Hi Priya\nYour code:\n\n[code: 482913]", "trigger": "profile_created"})
    t.check("email preview returns the branded HTML email", s == 200 and "<html" in pv.get("html", "").lower() and "482913" in pv.get("html", ""), s)
