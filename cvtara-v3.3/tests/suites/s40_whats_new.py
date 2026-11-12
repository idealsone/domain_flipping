"""What's new: dated notes from the CVTara team, shown once to signed-in customers after an update; owner edits them."""
NAME = "What's new for customers"


def run(ctx, t):
    c, db, o = ctx.client(), ctx.db(), ctx.client()
    db.q("delete from site_settings where key='whatsNew'")
    c.register("wn-old@test.local")
    # an account that joined before the seeded notes sees them; a brand-new account does not get old notes pushed
    db.q("update users set created_at='2026-10-01 10:00:00' where email='wn-old@test.local'")
    s, j = c.api("GET", "whats-new")
    t.check("customer gets the published notes, newest first", s == 200 and len(j["entries"]) >= 8 and j["entries"][0]["date"] >= j["entries"][-1]["date"], f"{s} {str(j)[:200]}")
    t.check("notes added since the customer joined are marked new", s == 200 and len(j["unseen"]) >= 1, j.get("unseen"))
    t.check("seeded notes never mention AI or the tools behind it", not any(w in (e["title"] + " " + e["body"]).lower().split() for e in j.get("entries", []) for w in ("ai", "anthropic", "claude")))
    s, _ = c.api("POST", "whats-new/seen", {})
    t.check("closing the window is remembered", s == 200 and c.api("GET", "whats-new")[1]["unseen"] == [])
    c.api("POST", "whats-new/seen", {})
    uid = db.q("select id from users where email='wn-old@test.local'")[0][0]
    t.check("one seen row per customer", db.q("select count(*) from activity_events where owner_id=? and event='whats_new_seen'", uid)[0][0] == 1)

    n = ctx.client(); n.register("wn-new@test.local")
    t.check("a brand-new account is not shown older notes", n.api("GET", "whats-new")[1].get("unseen") == [])
    t.check("guests cannot read the customer list", ctx.client().api("GET", "whats-new")[0] in (401, 403))
    t.check("customers cannot edit notes", c.api("POST", "admin/whats-new/save", {"entries": []})[0] == 403)

    o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
    s, a = o.api("GET", "admin/whats-new")
    t.check("owner sees every note with types and reader count", s == 200 and a["entries"] and "safety" in a["tags"] and "readers30" in a["stats"], f"{s} {str(a)[:200]}")
    entries = [{k: e[k] for k in ("id", "date", "tag", "title", "body", "published")} for e in a["entries"]]
    bad = [{"date": "2026-11-02", "tag": "new", "title": "Our AI writes your resume", "body": "Powered by AI.", "published": True}] + entries
    s, j = o.api("POST", "admin/whats-new/save", {"entries": bad})
    t.check("notes that mention AI are refused", s == 422 and "CVTara team" in str(j), f"{s} {j}")
    s, j = o.api("POST", "admin/whats-new/save", {"entries": [{"date": "2026-13-40", "tag": "new", "title": "x", "body": "y", "published": True}]})
    t.check("bad dates and empty text are refused", s == 422, f"{s} {j}")
    __import__("time").sleep(1.2)  # seen and published times are kept to the second
    new = {"date": "2026-11-02", "tag": "new", "title": "Download in Word format", "body": "You can now download your resume as a Word file.", "published": True}
    hidden = {"date": "2026-11-02", "tag": "fixed", "title": "Draft note", "body": "Not ready yet.", "published": False}
    s, j = o.api("POST", "admin/whats-new/save", {"entries": [new, hidden] + entries})
    t.check("owner adds a note", s == 200 and any(e["title"] == "Download in Word format" for e in j["entries"]), f"{s} {str(j)[:200]}")
    s, j = c.api("GET", "whats-new")
    t.check("a newly shown note pops up for a customer who already closed the window", s == 200 and len(j["unseen"]) == 1 and next(e for e in j["entries"] if e["id"] == j["unseen"][0])["title"] == "Download in Word format", j.get("unseen"))
    t.check("hidden notes are not sent to customers", all(e["title"] != "Draft note" for e in j["entries"]))
    c.api("POST", "whats-new/seen", {})
    a = o.api("GET", "admin/whats-new")[1]
    edited = [{**{k: e[k] for k in ("id", "date", "tag", "title", "body", "published")}, **({"body": "Word and PDF downloads."} if e["title"] == "Download in Word format" else {})} for e in a["entries"]]
    o.api("POST", "admin/whats-new/save", {"entries": edited})
    t.check("editing a note that was already shown does not show it again", c.api("GET", "whats-new")[1]["unseen"] == [])
    t.check("the reader count includes the customer", o.api("GET", "admin/whats-new")[1]["stats"]["readers30"] >= 1)
    # release notes ship with the code and reach customers by themselves after a deploy
    import re
    banned = re.compile(r"\b(ai|a\.i\.|artificial intelligence|anthropic|claude|chatgpt|openai|gpt|model)\b", re.I)
    ent = o.api("GET", "admin/whats-new")[1]["entries"]
    rel = [e for e in ent if e["id"].startswith("2026-11-12-")]
    t.check("release notes for 12 Nov are on the site without anyone typing them", len(rel) >= 6 and all(e["published"] and e.get("publishedAt") for e in rel), [e["id"] for e in rel])
    t.check("release notes never mention AI, models or the tools behind them", not any(banned.search(e["title"] + " " + e["body"]) for e in ent))
    gone = rel[0]["id"]
    o.api("POST", "admin/whats-new/save", {"entries": [{k: e[k] for k in ("id", "date", "tag", "title", "body", "published")} for e in ent if e["id"] != gone]})
    t.check("a release note the owner deleted does not come back", all(e["id"] != gone for e in o.api("GET", "admin/whats-new")[1]["entries"]) and all(e["id"] != gone for e in c.api("GET", "whats-new")[1]["entries"]))
    db.q("delete from site_settings where key='whatsNew'")
    late = ctx.client(); late.register("wn-late@test.local")
    __import__("time").sleep(1.2)
    s, j = late.api("GET", "whats-new")
    t.check("customers who joined before a release see its notes once", s == 200 and any(i.startswith("2026-11-12-") for i in j["unseen"]), j.get("unseen"))
    late.api("POST", "whats-new/seen", {})
    t.check("and not again after closing the window", late.api("GET", "whats-new")[1]["unseen"] == [])
    t.check("the customer script and console page are served", c.raw("GET", "/whats-new.js")[0] == 200 and o.raw("GET", "/console/whatsnew.js")[0] == 200 and c.raw("GET", "/console/whatsnew.js")[0] == 404)
    db.q("delete from site_settings where key='whatsNew'")
