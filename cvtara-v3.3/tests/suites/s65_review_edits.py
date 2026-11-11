"""Review edit history (3.55.0, V7 §28, AT-21, R13): spelling fixes and removed personal details are kept with the
original words, any other wording change goes to the customer to approve, feedback is asked after a resume save,
and the customer guide no longer promises a 70% score."""
NAME = "Reviews: edit history, customer approval, feedback after save"


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    inst = Instance(ctx.app, ctx.public, preview=True).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        c = Client(inst.base); c.register("rv-cust@test.local")
        t.check("no feedback request before any resume is saved", c.api("GET", "feedback/ask")[1]["ask"] is False)
        s, j = c.api("POST", "resumes", {"data": {"version": "1", "title": "Main", "template": "suite-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Riya"}, "summary": "Analyst", "order": ["summary"], "experience": [], "education": [], "projects": [], "certifications": []}})
        rid = j.get("id")
        t.check("after a resume save the customer is asked for feedback", s == 200 and c.api("GET", "feedback/ask")[1]["ask"] is True, (s, j))
        c.api("POST", "feedback/ask", {"action": "shown"})
        t.check("and not again for 14 days once shown", c.api("GET", "feedback/ask")[1]["ask"] is False)
        s, _ = c.api("POST", "feedback", {"displayName": "Riya V.", "rating": 4, "content": "The bulder was realy easy to use and my resume looks clean. Call me on 9876543210.", "consent": True})
        f = db.q("select id, usage_ref, original_content from feedback where owner_id=(select id from users where email='rv-cust@test.local')")[0]
        fid = f[0]
        t.check("the review keeps what the writer had used", s == 200 and f[1] == "resume:" + rid and f[2] is None, f)

        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        base = "The bulder was realy easy to use and my resume looks clean. Call me on 9876543210."
        s, j = o.api("POST", "admin/reviews/edit", {"id": fid, "kind": "correction", "reason": "Spelling", "content": base.replace("bulder was realy", "builder was not really")})
        t.check("a 'spelling fix' that adds a 'not' is refused", s == 422, (s, j))
        s, j = o.api("POST", "admin/reviews/edit", {"id": fid, "kind": "correction", "reason": "Spelling", "content": "This is a completely different and much shorter text."})
        t.check("a rewrite cannot be filed as a spelling fix", s == 422)
        fixed = base.replace("bulder", "builder").replace("realy", "really")
        s, j = o.api("POST", "admin/reviews/edit", {"id": fid, "kind": "correction", "reason": "Fixed two spellings", "content": fixed})
        r = db.q("select content, original_content, edited_at from feedback where id=?", fid)[0]
        t.check("a spelling fix applies at once and the original words are kept", s == 200 and r[0] == fixed and r[1] == base and r[2], (s, j, r))
        s, j = o.api("POST", "admin/reviews/edit", {"id": fid, "kind": "redaction", "reason": "Phone number", "content": fixed.replace("Call me on 9876543210", "Call me [removed]")})
        t.check("a phone number can be replaced with [removed]", s == 200 and "9876543210" not in db.q("select content from feedback where id=?", fid)[0][0], (s, j))
        s, j = o.api("POST", "admin/reviews/edit", {"id": fid, "kind": "redaction", "reason": "x" * 6, "content": "The builder was amazing [removed]"})
        t.check("a 'redaction' that adds words is refused", s == 422)
        o.api("POST", "admin/reviews", {"id": fid, "status": "published"})
        t.check("the review is published", db.q("select status from feedback where id=?", fid)[0][0] == "published")

        s, j = o.api("POST", "admin/reviews/edit", {"id": fid, "kind": "material", "reason": "Shorter for the homepage", "content": "The builder was easy and my resume looks clean."})
        r = db.q("select status, edit_state, content from feedback where id=?", fid)[0]
        t.check("a wording change waits for the customer and takes the review off the website", s == 200 and j["state"] == "awaiting_customer" and r[0] == "pending" and r[1] == "awaiting_customer" and "[removed]" in r[2], (s, j, r))
        t.check("it cannot be published while waiting", o.api("POST", "admin/reviews", {"id": fid, "status": "published"})[0] == 422)
        t.check("a second change cannot be stacked on top", o.api("POST", "admin/reviews/edit", {"id": fid, "kind": "material", "reason": "Again please", "content": "Another wording for the same review."})[0] == 409)
        t.check("the customer is told", db.q("select count(*) from notifications where kind='review_edit'")[0][0] == 1)
        s, j = c.api("GET", "feedback")
        pe = j["reviews"][0]["pendingEdit"]
        t.check("the customer sees the suggested wording", pe and pe["after"] == "The builder was easy and my resume looks clean.", j["reviews"][0])
        other = Client(inst.base); other.register("rv-other@test.local")
        t.check("nobody else can answer it", other.api("POST", f"feedback/{fid}/edits/{pe['id']}", {"approve": True})[0] == 404)
        c.api("POST", f"feedback/{fid}/edits/{pe['id']}", {"approve": False})
        r = db.q("select edit_state, content from feedback where id=?", fid)[0]
        t.check("'Keep my words' leaves the review unchanged", r[0] == "customer_declined" and "[removed]" in r[1], r)
        o.api("POST", "admin/reviews/edit", {"id": fid, "kind": "material", "reason": "Shorter, second try", "content": "The builder was easy to use and my resume looks clean."})
        pe = c.api("GET", "feedback")[1]["reviews"][0]["pendingEdit"]
        c.api("POST", f"feedback/{fid}/edits/{pe['id']}", {"approve": True})
        r = db.q("select edit_state, content, original_content from feedback where id=?", fid)[0]
        t.check("an approved change applies; the first original stays", r[0] is None and r[1] == "The builder was easy to use and my resume looks clean." and r[2] == base, r)
        t.check("an answered change cannot be answered again", c.api("POST", f"feedback/{fid}/edits/{pe['id']}", {"approve": False})[0] == 404)

        s, j = o.api("GET", "admin/reviews/history?id=" + fid)
        t.check("the history shows every change with editor, reason and before/after", s == 200 and [e["status"] for e in j["edits"]] == ["applied", "applied", "declined", "approved"] and j["review"]["original_content"] == base and all(e["reason"] and e["before_text"] and e["after_text"] for e in j["edits"]), [e["status"] for e in j.get("edits", [])])
        ps = o.api("POST", "admin/reviews", {"id": fid, "status": "published"})
        cs, cj = Client(inst.base).api("GET", "public-content")
        pub = [x for x in cj.get("reviews", []) if x["id"] == fid]
        t.check("the published review is marked as edited", pub and pub[0]["edited"] is True and pub[0]["rating"] == 4, (ps, cs, str(cj)[:300]))
        oid = db.q("select id from users where email='rv-other@test.local'")[0][0]
        db.q("insert into feedback(id,owner_id,display_name,rating,content,consent,status,source,created_at,updated_at) values('11111111-2222-3333-4444-555555555555',?,'Sample',5,'A sample review written by the owner.',0,'pending','dummy',datetime('now'),datetime('now'))", oid)
        t.check("a sample review cannot get a customer-approved wording change", o.api("POST", "admin/reviews/edit", {"id": "11111111-2222-3333-4444-555555555555", "kind": "material", "reason": "Wording", "content": "A different sample text entirely here."})[0] == 422)

        gs, _, guide = c.page("/customer-guide.html")
        t.check("the customer guide no longer promises a 70% score", gs == 200 and "usually scores 70%" not in guide and "not a promise" in guide, gs)
    finally:
        inst.stop()
