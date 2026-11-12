"""3.63.0 quick fixes (owner approval 2026-11-12): customer passwords need 8 characters (owner sign-in keeps 12), more work
categories (tech and non-tech) at sign-up and in the profile, What's new notes for 6-12 Nov and the retired phone note."""
import json, re
NAME = "8-character password, wider work categories, November What's new notes"

CATS = None


def php_categories(app):
    src = open(app + "/app/Services/JobFields.php", encoding="utf8").read()
    m = re.search(r"public const CATEGORIES = \[(.*?)\];", src, re.S)
    return re.findall(r"'([^']+)'", m.group(1)) if m else []


def run(ctx, t):
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    cats = php_categories(ctx.app)
    t.check("there are at least 20 work categories and the nine earlier ones are kept",
            len(cats) >= 20 and all(c in cats for c in ["Technology", "HR & Recruitment", "Sales & Marketing", "Finance", "Operations", "Education", "Healthcare", "Design", "Other"]), cats)
    t.check("non-tech work areas are offered", all(c in cats for c in ["Customer Support & BPO", "Banking & Insurance", "Supply Chain & Logistics", "Government & Public Sector", "Retail & E-commerce"]))
    for f in ("profile-onboarding.js", "upgrade-prompt.js"):
        js = open(ctx.public + "/" + f, encoding="utf8").read()
        t.check(f"{f} offers the same categories as the server", all(("'" + c + "'") in js for c in cats), f)

    inst = Instance(ctx.app, ctx.public, preview=True).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        c = Client(inst.base)
        s, h, b = c.page("/register")
        t.check("the sign-up form says 8 characters and lists the new categories", "min 8 characters" in b and "minlength=8" in b and "Customer Support &amp; BPO" in b, s)
        s, h, b = c.page("/reset-password/abc?email=x@test.local")
        t.check("the reset form also asks for 8 characters", "minlength=8" in b)
        c.register("eight@test.local", password="Abcd123!", verify=True)
        u = db.q("select id from users where email='eight@test.local'")
        t.check("an 8-character password creates the account", bool(u), u)
        c2 = Client(inst.base); c2.page("/register")
        c2.form("/register", {"name": "Seven", "email": "seven@test.local", "password": "Abc123!", "password_confirmation": "Abc123!", "experienceLevel": "fresher", "profession": "Analyst", "category": "Technology", "terms": "1"})
        s, h, b = c2.page("/register")
        t.check("a 7-character password is refused in plain words", not db.q("select id from users where email='seven@test.local'") and "at least 8 characters" in b)
        s, prof = c.api("GET", "profile")
        p = (prof or {}).get("profile") or prof or {}
        body = {k: p.get(k) for k in ("name", "phone", "city", "language", "experienceLevel", "profession", "experienceYears", "marketingConsent") if k in p}
        body.update({"name": "Eight", "experienceLevel": "experienced", "profession": "Team Lead", "experienceYears": 4, "marketingConsent": False, "category": "Customer Support & BPO"})
        s, r = c.api("PUT", "profile", body)
        if s == 405: s, r = c.api("POST", "profile", body)
        t.check("a profile can be saved with a new non-tech category", s == 200 and db.q("select category from profiles where owner_id=?", u[0][0])[0][0] == "Customer Support & BPO", (s, str(r)[:200]))
        body["category"] = "Astronaut"
        s, r = c.api("PUT", "profile", body)
        if s == 405: s, r = c.api("POST", "profile", body)
        t.check("an unknown category is still refused", s == 422, s)

        s, w = c.api("GET", "whats-new")
        ids = [e["id"] for e in (w or {}).get("entries", [])]
        t.check("customers see the November notes, newest first", ids[:1] and ids[0].startswith("2026-11-12") and "2026-11-07-job-hunt" in ids, ids[:6])
        t.check("the phone note is no longer shown", "2026-10-26-phone" not in ids)
        t.check("the notes mention no AI", not re.search(r"\b(ai|artificial intelligence|claude|anthropic)\b", json.dumps(w).lower()))

        # an owner-saved list (made before 3.63) gets the new notes and loses the phone note when the migration runs
        saved = {"entries": [{"id": "2026-10-26-phone", "date": "2026-10-26", "tag": "new", "title": "Call us", "body": "x", "published": True, "publishedAt": "2026-10-26T00:00:00+05:30"},
                             {"id": "owner-own-note", "date": "2026-11-02", "tag": "improved", "title": "Owner note", "body": "kept", "published": True, "publishedAt": "2026-11-02T00:00:00+05:30"}]}
        db.q("delete from site_settings where key='whatsNew'")
        db.q("insert into site_settings (key, value_json, revision, created_at, updated_at) values ('whatsNew', ?, 1, datetime('now'), datetime('now'))", json.dumps(saved))
        db.q("delete from migrations where migration='2026_11_28_000001_whats_new_november'")
        r = inst.artisan("migrate", "--force")
        v = json.loads(db.q("select value_json from site_settings where key='whatsNew'")[0][0])
        got = {e["id"]: e for e in v["entries"]}
        t.check("the update adds the November notes to an owner-saved list", r.returncode == 0 and "2026-11-12-signup" in got and "2026-11-06-plans" in got, (r.returncode, list(got)[:12]))
        t.check("the owner's own note stays and the phone note is hidden, not deleted", "owner-own-note" in got and got.get("2026-10-26-phone", {}).get("published") is False)
        inst.artisan("migrate", "--force")
        v2 = json.loads(db.q("select value_json from site_settings where key='whatsNew'")[0][0])
        t.check("running it again adds nothing twice", len(v2["entries"]) == len(v["entries"]))
    finally:
        inst.stop()
