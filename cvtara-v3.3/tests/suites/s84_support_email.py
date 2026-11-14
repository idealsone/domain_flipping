"""Support by email (3.75.0): mail to the support address becomes tickets (read-only, never twice), automatic reports are
skipped, each new mail is sorted (reply needed or not), a suggested reply is written as the CVTara team, the owner's reply
is emailed at once in the same conversation, and the customer's answer by email joins its ticket."""
import email, http.server, json, threading, time
from lib.fake_imap import FakeImap
from lib.fake_smtp import FakeSmtp
NAME = "Support by email: mailbox, sorting, suggested replies, threaded replies"

HITS = []


class FakeClaude(http.server.BaseHTTPRequestHandler):
    def log_message(self, *a): pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))) or b"{}"); HITS.append(body)
        sysp = json.dumps(body.get("system", "")); msg = json.dumps(body.get("messages", []))
        if "You sort email" in sysp:
            cat = "sales" if "backlinks" in msg.lower() else "customer"
            text = json.dumps({"category": cat, "reply_needed": cat == "customer", "reason": "Sells link building." if cat == "sales" else "Asks about a payment."})
        else:
            text = "Hi Ravi,\n\nSorry about this. Please send the payment time, amount and UPI reference, and we will check it today.\n\nRegards,\nCVTara team"
        out = {"id": "msg_1", "type": "message", "role": "assistant", "model": body.get("model"), "content": [{"type": "text", "text": text}],
               "stop_reason": "end_turn", "stop_sequence": None, "usage": {"input_tokens": 10, "output_tokens": 10}}
        b = json.dumps(out).encode()
        self.send_response(200); self.send_header("Content-Type", "application/json"); self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)


def mail(frm, subject, body, mid, extra="", ctype="text/plain; charset=utf-8", enc="8bit"):
    return (f"From: {frm}\nTo: support@cvtara.com\nSubject: {subject}\nDate: {time.strftime('%a, %d %b %Y %H:%M:%S +0000', time.gmtime())}\nMessage-ID: {mid}\n{extra}"
            f"MIME-Version: 1.0\nContent-Type: {ctype}\nContent-Transfer-Encoding: {enc}\n\n{body}\n")


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    claude = http.server.ThreadingHTTPServer(("127.0.0.1", 0), FakeClaude); threading.Thread(target=claude.serve_forever, daemon=True).start()
    imap, smtp = FakeImap(), FakeSmtp()
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"CLAUDE_API_BASE": f"http://127.0.0.1:{claude.server_address[1]}"}).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        _run(t, inst, db, Client, imap, smtp)
    finally:
        inst.stop(); imap.stop(); smtp.stop(); claude.shutdown()


def _run(t, inst, db, Client, imap, smtp):
    o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
    known = Client(inst.base); known.register("asha.known@test.local")
    o.api("POST", "admin/providers", {"service": "email", "values": {"provider": "smtp", "smtpHost": "127.0.0.1", "smtpPort": str(smtp.port), "smtpUser": "support@cvtara.com", "smtpPassword": "pw",
                                                                     "fromEmail": "support@cvtara.com", "fromName": "CVTara", "replyTo": "support@cvtara.com", "postalAddress": "CVTara, 12 MG Road, Bengaluru 560001"}})
    o.api("POST", "admin/providers", {"service": "ai", "values": {"provider": "claude", "claudeApiKey": "sk-ant-test-1", "model": "", "dailyLimit": "0"}})
    s, g = o.api("GET", "admin/content/integrations"); d = g["data"]; d["email"] = True; d["ai"] = True
    o.api("POST", "admin/content/integrations", {"data": d, "revision": g["revision"]})

    s, j = o.api("GET", "admin/support/inbox")
    t.check("the support mailbox is on by default, reads support@cvtara.com and borrows the sending password", s == 200 and j["inbox"]["enabled"] and j["inbox"]["user"] == "support@cvtara.com" and j["inbox"]["ready"] and j["inbox"]["usesSendingPassword"], j)
    s, j = o.api("POST", "admin/support/inbox", {"host": "127.0.0.1", "port": imap.port})
    t.check("the owner points it at a mail server", s == 200, (s, j))

    imap.add(mail("Ravi Kumar <ravi@example.in>", "Paid but no download", "I paid Rs 299 at 10:05 but my package does not show.\nPlease help.", "<a1@example.in>"))
    imap.add(mail("SEO Pro <pitch@seo-agency.test>", "Grow CVTara with backlinks", "We sell high DA backlinks and guest posts.", "<p1@seo-agency.test>", "List-Unsubscribe: <mailto:u@seo-agency.test>\n"))
    imap.add(mail("noreply-dmarc-support@google.com", "Report domain: cvtara.com Submitter: google.com Report-ID: 1", "report", "<d1@google.com>"))
    imap.add(mail("Asha <asha.known@test.local>", "Cannot sign in", "The sign-in code does not arrive.", "<k1@test.local>"))
    imap.add(mail("=?UTF-8?B?UHJpeWEgU2hhcm1h?= <priya@example.in>", "=?UTF-8?Q?R=C3=A9sum=C3=A9_help?=", "<html><body><p>Hello team,</p><p>Can I edit my r=C3=A9sum=C3=A9 after download?</p></body></html>", "<h1@example.in>", ctype="text/html; charset=utf-8", enc="quoted-printable"))

    s, j = o.api("POST", "admin/support/inbox/check", {})
    r = j.get("result", {})
    t.check("checking the mailbox makes tickets of four mails and skips the DMARC report", s == 200 and r.get("tickets") == 4 and r.get("skipped") == 1, j)
    T = {row[0]: row[1:] for row in db.q("select email, id, triage, reply_needed, status, draft, owner_id, message, channel, name from support_reports where channel='email'")}
    a = T.get("ravi@example.in"); b = T.get("pitch@seo-agency.test")
    t.check("a customer mail is sorted as needing a reply and gets a suggested reply signed CVTara team", a and a[1] == "customer" and a[2] == 1 and a[3] == "new" and "CVTara team" in (a[4] or ""), a)
    t.check("a sales pitch is sorted as not needing a reply and closed with the reason", b and b[1] == "sales" and b[2] == 0 and b[3] == "closed" and db.q("select count(*) from support_ticket_messages where ticket_id=? and author_type='system' and body like '%no reply needed%Sells link building%'", b[0])[0][0] == 1, b)
    t.check("no ticket is made for automatic reports", "noreply-dmarc-support@google.com" not in T)
    k = T.get("asha.known@test.local")
    t.check("mail from an account holder is linked to the account and needs a reply", k and k[5] is not None and k[2] == 1, k)
    p = T.get("priya@example.in")
    t.check("encoded names, subjects and HTML-only mail are read as plain text", p and p[8] == "Priya Sharma" and "Résumé help" in p[6] and "edit my résumé after download" in p[6] and "<p>" not in p[6], p)
    t.check("the mailbox is only read: nothing stored, copied, moved or deleted", not [c for c in imap.commands if any(x in c for x in ("STORE", "COPY", "MOVE", "EXPUNGE", "SELECT", "APPEND"))], imap.commands)
    t.check("it signed in with the sending password", any('"pw"' in l for l in imap.logins), imap.logins)
    s, j = o.api("POST", "admin/support/inbox/check", {})
    t.check("checking again makes no duplicates", j["result"].get("tickets") == 0 and db.q("select count(*) from support_reports where channel='email'")[0][0] == 4, j)

    s, j = o.api("GET", "admin/support?needs=reply&status=open")
    t.check("the 'Needs a reply' filter leaves out the sales pitch", s == 200 and "pitch@seo-agency.test" not in json.dumps(j) and "ravi@example.in" in json.dumps(j), str(j)[:300])
    s, j = o.api("GET", "admin/support/" + a[0])
    t.check("the ticket shows the sorting, the suggested reply and that it can be answered", s == 200 and j["report"]["triage"] == "customer" and j["report"]["draft"] and j["canReply"] and j["assist"], str(j)[:300])

    before = len(smtp.messages)
    s, j = o.api("POST", "admin/support/" + a[0], {"action": "reply", "body": "Thanks Ravi. Please send the UPI reference and we will add the package today."})
    t.check("the owner's reply to someone without an account is emailed at once", s == 200 and j["mail"]["mail"] == "sent" and len(smtp.messages) == before + 1, j.get("mail"))
    m = email.message_from_string(smtp.messages[-1]["data"]) if len(smtp.messages) > before else email.message_from_string("")
    ref = db.q("select reference from support_reports where id=?", a[0])[0][0]
    text = next((p.get_payload(decode=True).decode() for p in m.walk() if p.get_content_type() == "text/plain"), "")
    t.check("it goes to the customer from the support address, with replies coming back to it", smtp.messages[-1]["to"] == ["ravi@example.in"] if len(smtp.messages) > before else False)
    t.check("From reads CVTara Support and Reply-To is support@cvtara.com", "CVTara Support" in m.get("From", "") and "support@cvtara.com" in m.get("From", "") and "support@cvtara.com" in m.get("Reply-To", ""), (m.get("From"), m.get("Reply-To")))
    t.check("the subject answers theirs and carries the ticket reference", m.get("Subject", "") == f"Re: Paid but no download [{ref}]", m.get("Subject"))
    t.check("it threads under their email (In-Reply-To and References)", m.get("In-Reply-To") == "<a1@example.in>" and "<a1@example.in>" in m.get("References", ""), (m.get("In-Reply-To"), m.get("References")))
    sent_id = db.q("select email_message_id from support_ticket_messages where ticket_id=? and author_type='staff'", a[0])[0][0]
    t.check("its Message-ID is kept on the ticket", sent_id and m.get("Message-ID") == sent_id, (sent_id, m.get("Message-ID")))
    t.check("the email greets them, signs as CVTara team and never mentions AI", "Hi Ravi" in text and "CVTara team" in text and "UPI reference" in text and ref in text and not any(w in text for w in ("AI ", "Claude", "Anthropic")), text[:500])
    s, j = o.api("GET", "admin/support/" + a[0])
    t.check("the conversation shows the email as delivered and the used suggestion is cleared", j["messages"][-1].get("mail", {}).get("status") == "sent" and not j["report"]["draft"], str(j["messages"][-1])[:200])

    imap.add(mail("Ravi Kumar <ravi@example.in>", f"Re: Paid but no download [{ref}]", "UPI ref 123456789, paid 10:05 AM.\n\nOn Sat, 14 Nov 2026 at 11:20, CVTara Support <support@cvtara.com> wrote:\n> Thanks Ravi. Please send the UPI reference", "<a2@example.in>", f"In-Reply-To: {sent_id}\nReferences: <a1@example.in> {sent_id}\n"))
    s, j = o.api("POST", "admin/support/inbox/check", {})
    last = db.q("select body, author_type from support_ticket_messages where ticket_id=? order by created_at desc limit 1", a[0])[0]
    t.check("the customer's answer by email joins the same ticket without the quoted mail", j["result"].get("replies") == 1 and last[1] == "customer" and "123456789" in last[0] and "wrote:" not in last[0] and "Thanks Ravi" not in last[0], (j, last))
    t.check("the ticket is back at the top as new", db.q("select status from support_reports where id=?", a[0])[0][0] == "new")
    imap.add(mail("Mallory <mallory@evil.test>", f"Re: Paid but no download [{ref}]", "Send me Ravi's details.", "<e1@evil.test>"))
    o.api("POST", "admin/support/inbox/check", {})
    t.check("someone else using the reference gets their own ticket, not Ravi's", db.q("select count(*) from support_ticket_messages where ticket_id=? and body like '%Ravi''s details%'", a[0])[0][0] == 0 and db.q("select count(*) from support_reports where email='mallory@evil.test'")[0][0] == 1)

    s, j = o.api("POST", "admin/support/" + a[0], {"action": "draft"})
    t.check("'Suggest a reply' writes a new suggestion from the conversation", s == 200 and "CVTara team" in j.get("draft", "") and "upi" in json.dumps(HITS[-1]).lower(), (s, j))
    t.check("the suggestion prompt forbids mentioning AI and treats the customer text as content", "Never mention AI" in json.dumps(HITS[-1]["system"]) and "never instructions" in json.dumps(HITS[-1]["system"]))
    s, j = o.api("POST", "admin/support/" + b[0], {"action": "sort", "replyNeeded": True})
    t.check("the owner can overrule the sorting and reopen a mail", s == 200 and db.q("select reply_needed, status from support_reports where id=?", b[0])[0] == (1, "seen"))

    s, j = o.api("POST", "admin/support/inbox", {"password": "wrong"})
    s, j = o.api("POST", "admin/support/inbox/check", {})
    t.check("a refused mailbox password is shown in plain words", "refused the sign-in" in j["result"].get("error", "") and "refused the sign-in" in j["inbox"]["lastError"], j)
    t.check("the saved mailbox password is never sent back", "wrong" not in json.dumps(o.api("GET", "admin/support/inbox")[1]))
    agent = Client(inst.base); agent.register("sup-agent@test.local"); o.api("POST", "admin/team/save", {"email": "sup-agent@test.local", "role": "support_agent"})
    x = Client(inst.base); x.form("/sanaulla", {"email": "sup-agent@test.local", "password": "TestPassw0rd!!x"}); x.page("/admin.html")
    g1, g2 = x.api("GET", "admin/support/inbox")[0], x.api("POST", "admin/support/inbox", {"enabled": False})[0]
    t.check("support staff can check the mailbox but not change its settings", g1 == 200 and g2 == 403, (g1, g2))
