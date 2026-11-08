"""Email campaigns: audiences built only from people who said yes, imported contacts (confirmation required, cleaned and
de-duplicated), preview and test send, batches with a per-run and daily cap, pause and cancel, one-click unsubscribe
without signing in, the do-not-mail list, and owner-only access."""
import os, urllib.parse
NAME = "Email campaigns (outreach)"


def run(ctx, t):
    db = ctx.db()
    o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
    tinker = lambda code: ctx.inst.artisan("tinker", "--execute=" + code).stdout.strip().splitlines()[-1:] or [""]
    tick = lambda: tinker("echo json_encode(App\\Services\\Campaigns::run());")[0]
    camp_rows = lambda cid: db.q("select count(*) from mail_outbox where campaign_id=?", cid)[0][0]
    uid = lambda e: db.q("select id from users where email=?", e)[0][0]
    made = []

    if ctx.preview:
        admin = open(os.path.join(ctx.app, "resources/frontend/admin.html"), encoding="utf8").read()
        t.check("the owner console has the Email campaigns page", 'data-admin-view="campaigns"' in admin and 'id="admin-campaigns"' in admin and "/console/campaigns.js" in admin)
        t.check("the campaigns module is served to the owner", o.raw("GET", "/console/campaigns.js")[0] == 200 and ctx.client().raw("GET", "/console/campaigns.js")[0] == 404)
        src = open(os.path.join(ctx.app, "app/Http/Controllers/CampaignAdminController.php"), encoding="utf8").read()
        send = src[src.index("'campaigns/send'"):src.index("'campaigns/settings'")]
        t.check("starting or scheduling a campaign needs the recent identity check", "AccountSecurity::requireRecent($r)" in send)

    # ---- audiences: only people who said yes; free, paid and ended plans ----
    s, g = o.api("GET", "admin/campaigns")
    t.check("the owner sees the campaign page with audiences, limits and real counts", s == 200 and {a["key"] for a in g["audiences"]} == {"customers_all", "customers_free", "customers_paid", "customers_expired", "contacts"} and g["settings"] == {"batchSize": 20, "dailyCap": 300, "mailboxCap": 1000}, (s, str(g)[:200]))
    before = g["counts"]
    for e in ("cm-yes@test.local", "cm-yes2@test.local", "cm-ended@test.local", "cm-no@test.local"): ctx.client().register(e)
    db.q("update profiles set marketing_consent=1 where owner_id in (?,?,?)", uid("cm-yes@test.local"), uid("cm-yes2@test.local"), uid("cm-ended@test.local"))
    db.q("insert into purchases(id,owner_id,plan,status,amount_paise,provider,limits_json,access_days,purchased_at,access_until,created_at,updated_at) values('cm-p1',?,'job-hunt','paid',29900,'demo','{}',30,datetime('now','-40 days'),datetime('now','-10 days'),datetime('now','-40 days'),datetime('now'))", uid("cm-ended@test.local"))
    after = o.api("GET", "admin/campaigns")[1]["counts"]
    t.check("people who said yes are counted and someone who did not is left out", after["customers_all"] - before["customers_all"] == 3, (before, after))
    t.check("never-bought, active-plan and ended-plan groups are separate", after["customers_free"] - before["customers_free"] == 2 and after["customers_expired"] - before["customers_expired"] == 1 and after["customers_paid"] == before["customers_paid"], (before, after))
    want = db.q("select count(*) from users u join profiles p on p.owner_id=u.id where p.marketing_consent=1 and u.is_admin=0 and u.blocked=0")[0][0]
    t.check("the count matches the database exactly (owners and opted-out people excluded)", after["customers_all"] == want, (after["customers_all"], want))
    t.check("the count endpoint gives the same number", o.api("GET", "admin/campaigns/count?audience=customers_all")[1]["count"] == want)

    # ---- imported contacts ----
    csv = "email,name\ncm-a@example.com, Asha Rao\nnot-an-email\nCM-A@example.com,Asha again\ncm-b@example.com\ncm-c@example.com;Chitra\n\"cm-d@example.com\",\"Dev\"\ncm-yes@test.local,Customer\n"
    s, j = o.api("POST", "admin/campaigns/contacts/import", {"csv": csv, "source": "Test fair"})
    t.check("an import without the confirmation box is refused", s == 422 and "agreed" in str(j), (s, str(j)[:160]))
    s, j = o.api("POST", "admin/campaigns/contacts/import", {"csv": csv, "source": "Test fair", "confirm": False})
    t.check("an unticked confirmation box is refused", s == 422)
    s, j = o.api("POST", "admin/campaigns/contacts/import", {"csv": csv, "source": "Test fair", "confirm": True}); r = j.get("result", {})
    t.check("valid addresses are added, a repeat is merged and a bad line is reported", s == 200 and r["read"] == 5 and r["invalid"] == 1 and r["added"] == 5 and "not-an-email" in r["invalidSamples"], (s, r))
    t.check("contacts are stored lower-case with their name, source and the owner's confirmation", db.q("select name,source,confirmed_by is not null,length(token) from email_contacts where email='cm-a@example.com'") == [("Asha Rao", "Test fair", 1, 48)])
    t.check("a contact who already has an account is reached through the customer groups only", r["haveAccount"] == 1 and o.api("GET", "admin/campaigns/count?audience=contacts")[1]["count"] == db.q("select count(*) from email_contacts where unsubscribed_at is null and email not in (select email from users)")[0][0])
    s, j = o.api("POST", "admin/campaigns/contacts/import", {"csv": "cm-a@example.com\ncm-e@example.com, Esha", "confirm": True}); r = j["result"]
    t.check("importing the same address again does not add it twice", r["added"] == 1 and r["alreadyListed"] == 1, r)
    s, j = o.api("GET", "admin/campaigns/contacts?q=test%20fair")
    t.check("the owner can search the contact list", s == 200 and j["total"] == 5, (s, j.get("total")))

    # ---- preview and test send ----
    body = "# Hello {{name}}\n\nNew guides are out.\n\n- Resume tips\n\n[button: Read them | https://cvtara.com/blog.html]"
    s, p = o.api("POST", "admin/campaigns/preview", {"subject": "Hi {{name}}", "body": body})
    t.check("the preview uses the branded email with the name filled in and the unsubscribe footer", s == 200 and "Hello Demo" in p["html"] and "Team CVTara" in p["html"] and "Unsubscribe" in p["html"] and "{{" not in p["html"] and p["subject"] == "Hi Demo", (s, str(p)[:160]))
    s, j = o.api("POST", "admin/campaigns/test", {"subject": "Hi {{name}}", "body": body})
    row = db.q("select recipient,subject,body,status from mail_outbox where trigger='campaign_test' order by created_at desc limit 1")
    t.check("send a test goes to the owner only, through the normal mail queue", s == 200 and j["to"] == "owner@cvtara.test" and row and row[0][0] == "owner@cvtara.test" and row[0][1] == "[Test] Hi Demo" and "Hello Demo" in row[0][2] and row[0][3] == "queued", row)
    t.check("a test is not counted towards the campaign daily limit", o.api("GET", "admin/campaigns")[1]["sentToday"] == 0)

    # ---- batches: per-run cap and daily cap ----
    s, _ = o.api("POST", "admin/campaigns/settings", {"batchSize": 2, "dailyCap": 3})
    t.check("the owner sets the sending limits", s == 200 and o.api("GET", "admin/campaigns")[1]["settings"] == {"batchSize": 2, "dailyCap": 3, "mailboxCap": 1000})
    t.check("limits outside the safe range are refused", o.api("POST", "admin/campaigns/settings", {"batchSize": 0, "dailyCap": 3})[0] == 422)
    s, j = o.api("POST", "admin/campaigns/save", {"name": "Contacts hello", "audience": "contacts", "subject": "Hello {{name}}", "body": body}); cid = j.get("id"); made.append(cid)
    t.check("a new campaign starts as a draft", s == 200 and next(c for c in j["campaigns"] if c["id"] == cid)["status"] == "draft", (s, str(j)[:160]))
    s, j = o.api("POST", "admin/campaigns/send", {"id": cid, "when": "now"})
    st = next(c for c in j["campaigns"] if c["id"] == cid)
    n_contacts = db.q("select count(*) from campaign_recipients where campaign_id=?", cid)[0][0]
    t.check("send now fixes the recipient list and starts sending", s == 200 and st["status"] == "sending" and n_contacts == 5 and st["stats"]["waiting"] == 5, (s, st, n_contacts))
    t.check("nothing is handed to the mailer until the next run", camp_rows(cid) == 0)
    tick(); t.check("one run hands over only one batch (2)", camp_rows(cid) == 2, camp_rows(cid))
    tick(); t.check("the daily limit (3) stops further batches", camp_rows(cid) == 3, camp_rows(cid))
    tick(); t.check("the next run still respects the daily limit", camp_rows(cid) == 3 and next(c for c in o.api("GET", "admin/campaigns")[1]["campaigns"] if c["id"] == cid)["status"] == "sending")
    mail = db.q("select recipient,subject,body,contact_id is not null,owner_id from mail_outbox where campaign_id=? and recipient='cm-a@example.com'", cid)
    t.check("each email is personalised and linked to the contact", mail and mail[0][1] == "Hello Asha" and "Hello Asha" in mail[0][2] and mail[0][3] == 1 and mail[0][4] is None, mail)
    t.check("campaign mail counts as marketing (unsubscribe footer and headers)", tinker("echo App\\Services\\EmailRenderer::isMarketing('campaign') ? 'yes' : 'no';")[0] == "yes")
    t.check("an edit after the campaign started is refused", o.api("POST", "admin/campaigns/save", {"id": cid, "name": "x", "audience": "contacts", "subject": "x", "body": "x"})[0] == 422)

    # ---- one-click unsubscribe for a contact, no sign-in ----
    tok = db.q("select token from email_contacts where email='cm-a@example.com'")[0][0]
    link = tinker(f"echo App\\Services\\EmailRenderer::contactUnsubscribeUrl('{tok}', '{cid}');")[0]
    path = urllib.parse.urlparse(link).path + "?" + urllib.parse.urlparse(link).query
    v = ctx.client()
    s, _, b = v.raw("GET", path); t.check("the contact's unsubscribe page opens without signing in and asks first", s == 200 and b"Unsubscribe from CVTara emails" in b, (s, link))
    s, _, b = v._post_raw(path, b"List-Unsubscribe=One-Click"); t.check("one click (also the mail-client request) unsubscribes", s == 200 and b"You are unsubscribed" in b, s)
    t.check("the contact is marked and goes on the do-not-mail list", db.q("select unsubscribed_at is not null from email_contacts where email='cm-a@example.com'")[0][0] == 1 and db.q("select count(*) from email_suppressions where email='cm-a@example.com'")[0][0] == 1)
    t.check("their waiting email is cancelled", db.q("select status from mail_outbox where campaign_id=? and recipient='cm-a@example.com'", cid)[0][0] == "cancelled")
    t.check("the campaign report counts the unsubscribe", next(c for c in o.api("GET", "admin/campaigns")[1]["campaigns"] if c["id"] == cid)["stats"]["unsubscribed"] == 1)
    t.check("a made-up token is refused", ctx.client().raw("GET", "/email/unsubscribe/c/" + "x" * 48)[0] == 404)
    s, j = o.api("POST", "admin/campaigns/contacts/import", {"csv": "cm-a@example.com", "confirm": True})
    t.check("an unsubscribed address is not imported again", j["result"]["added"] == 0 and j["result"]["alreadyListed"] == 1 and j["result"]["doNotMail"] == 1, j.get("result"))
    t.check("the unsubscribed contact is left out of new campaigns", o.api("GET", "admin/campaigns/count?audience=contacts")[1]["count"] == db.q("select count(*) from email_contacts where unsubscribed_at is null and email not in (select email from users)")[0][0] and "cm-a@example.com" not in str(db.q("select email from email_contacts where unsubscribed_at is null")))
    o.api("POST", "admin/campaigns/suppress", {"emails": "cm-e@example.com"})
    t.check("an address the owner adds to the do-not-mail list is skipped", db.q("select count(*) from email_suppressions where email='cm-e@example.com' and reason='owner'")[0][0] == 1)

    # ---- pause and resume ----
    s, _ = o.api("POST", "admin/campaigns/pause", {"id": cid})
    waiting = db.q("select count(*) from mail_outbox where campaign_id=? and status='queued'", cid)[0][0]
    t.check("pause takes back emails still waiting in the queue", s == 200 and waiting == 0 and db.q("select count(*) from campaign_recipients where campaign_id=? and status='pending'", cid)[0][0] == 4, (s, waiting))
    o.api("POST", "admin/campaigns/settings", {"batchSize": 50, "dailyCap": 1000})
    tick(); t.check("a paused campaign sends nothing", db.q("select count(*) from mail_outbox where campaign_id=? and status='queued'", cid)[0][0] == 0)
    s, _ = o.api("POST", "admin/campaigns/resume", {"id": cid}); tick()
    rec = dict(db.q("select email,status from campaign_recipients where campaign_id=?", cid))
    t.check("resume carries on, skipping unsubscribed and do-not-mail addresses", s == 200 and rec["cm-e@example.com"] == "skipped" and rec["cm-b@example.com"] == "queued" and db.q("select count(*) from mail_outbox where campaign_id=? and status='queued'", cid)[0][0] == 3, rec)
    tick(); st = next(c for c in o.api("GET", "admin/campaigns")[1]["campaigns"] if c["id"] == cid)
    t.check("the campaign finishes when everyone is handed over, and the report adds up", st["status"] == "done" and st["stats"]["waiting"] == 3 and st["stats"]["notSent"] == 2 and st["stats"]["recipients"] == 5, st)
    t.check("a finished campaign cannot be paused", o.api("POST", "admin/campaigns/pause", {"id": cid})[0] == 422)

    # ---- customer campaign: consent checked again at each batch, signed unsubscribe, cancel ----
    s, j = o.api("POST", "admin/campaigns/save", {"name": "Ended plans", "audience": "customers_expired", "subject": "Come back {{name}}", "body": body}); c2 = j["id"]; made.append(c2)
    o.api("POST", "admin/campaigns/send", {"id": c2, "when": "now"})
    t.check("the ended-plan campaign includes the customer whose plan ended", db.q("select count(*) from campaign_recipients where campaign_id=? and email='cm-ended@test.local'", c2)[0][0] == 1)
    db.q("update profiles set marketing_consent=0 where owner_id=?", uid("cm-ended@test.local")); tick()
    t.check("someone who switched emails off after the start is skipped", db.q("select status from campaign_recipients where campaign_id=? and email='cm-ended@test.local'", c2)[0][0] == "skipped" and db.q("select count(*) from mail_outbox where campaign_id=? and recipient='cm-ended@test.local'", c2)[0][0] == 0)
    s, j = o.api("POST", "admin/campaigns/save", {"name": "Everyone", "audience": "customers_all", "subject": "Hello {{name}}", "body": body}); c3 = j["id"]; made.append(c3)
    o.api("POST", "admin/campaigns/settings", {"batchSize": 1, "dailyCap": 1000}); o.api("POST", "admin/campaigns/send", {"id": c3, "when": "now"}); tick()
    yes = uid("cm-yes@test.local")
    url = tinker(f"echo App\\Services\\EmailRenderer::unsubscribeUrl({yes}, '{c3}');")[0]
    p = urllib.parse.urlparse(url); s, _, b = ctx.client()._post_raw(p.path + "?" + p.query, b"")
    t.check("a customer's signed unsubscribe link works without signing in and is counted for the campaign", s == 200 and db.q("select marketing_consent from profiles where owner_id=?", yes)[0][0] in (0, False) and db.q("select unsubscribed_at is not null from campaign_recipients where campaign_id=? and owner_id=?", c3, yes)[0][0] == 1, (s, url[:80]))
    s, _ = o.api("POST", "admin/campaigns/cancel", {"id": c3}); st = next(c for c in o.api("GET", "admin/campaigns")[1]["campaigns"] if c["id"] == c3)
    t.check("cancel stops a sending campaign and nothing more goes out", s == 200 and st["status"] == "cancelled" and st["stats"]["waiting"] == 0 and db.q("select count(*) from mail_outbox where campaign_id=? and status='queued'", c3)[0][0] == 0 and st["stats"]["notSent"] == st["stats"]["recipients"], st)
    tick(); t.check("a cancelled campaign stays stopped", db.q("select count(*) from mail_outbox where campaign_id=? and status='queued'", c3)[0][0] == 0)

    # ---- scheduling ----
    s, j = o.api("POST", "admin/campaigns/save", {"name": "Later", "audience": "customers_free", "subject": "Hi", "body": body}); c4 = j["id"]; made.append(c4)
    t.check("a time in the past is refused", o.api("POST", "admin/campaigns/send", {"id": c4, "when": "schedule", "at": "2020-01-01T10:00"})[0] == 422)
    s, j = o.api("POST", "admin/campaigns/send", {"id": c4, "when": "schedule", "at": "2099-01-01T10:00"}); st = next(c for c in j["campaigns"] if c["id"] == c4)
    t.check("a campaign can be scheduled (India time) and waits", s == 200 and st["status"] == "scheduled" and st["scheduledAt"] == "2099-01-01T10:00" and db.q("select scheduled_at from email_campaigns where id=?", c4)[0][0].startswith("2099-01-01 04:30"), st)
    tick(); t.check("nothing is fixed before the scheduled time", db.q("select count(*) from campaign_recipients where campaign_id=?", c4)[0][0] == 0)
    o.api("POST", "admin/campaigns/pause", {"id": c4}); s, _ = o.api("POST", "admin/campaigns/cancel", {"id": c4})
    t.check("a scheduled campaign can be paused and then cancelled", s == 200 and db.q("select status from email_campaigns where id=?", c4)[0][0] == "cancelled")
    s, j = o.api("POST", "admin/campaigns/save", {"name": "Soon", "audience": "customers_free", "subject": "Hi", "body": body}); c5 = j["id"]; made.append(c5)
    o.api("POST", "admin/campaigns/send", {"id": c5, "when": "schedule", "at": "2099-01-01T10:00"}); db.q("update email_campaigns set scheduled_at=datetime('now','-1 minute') where id=?", c5); tick()
    t.check("when the time comes the campaign starts by itself", db.q("select status from email_campaigns where id=?", c5)[0][0] in ("sending", "done") and db.q("select count(*) from campaign_recipients where campaign_id=?", c5)[0][0] > 0)
    o.api("POST", "admin/campaigns/cancel", {"id": c5})
    t.check("an unknown audience is refused", o.api("GET", "admin/campaigns/count?audience=everyone")[0] == 422 and o.api("POST", "admin/campaigns/save", {"name": "x", "audience": "everyone", "subject": "x", "body": "x"})[0] == 422)

    # ---- owner only ----
    cu = ctx.client(); cu.register("cm-cust@test.local")
    t.check("customers cannot open, test or send campaigns", cu.api("GET", "admin/campaigns")[0] == 403 and cu.api("POST", "admin/campaigns/test", {"subject": "x", "body": "x"})[0] == 403 and cu.api("POST", "admin/campaigns/contacts/import", {"csv": "a@b.co", "confirm": True})[0] == 403)
    t.check("visitors who are not signed in are refused", ctx.client().api("GET", "admin/campaigns")[0] == 401)

    # ---- leave the shared database as it was ----
    for c in o.api("GET", "admin/campaigns")[1]["campaigns"]:
        if c["status"] in ("scheduled", "sending", "paused"): o.api("POST", "admin/campaigns/cancel", {"id": c["id"]})
    db.q("delete from mail_outbox where trigger in ('campaign','campaign_test')")
    db.q("delete from campaign_recipients"); db.q("delete from email_campaigns"); db.q("delete from email_contacts"); db.q("delete from email_suppressions")
    db.q("delete from site_settings where key='campaignSettings'")
    t.check("the sending limits are back to their defaults", o.api("GET", "admin/campaigns")[1]["settings"] == {"batchSize": 20, "dailyCap": 300, "mailboxCap": 1000})
