"""3.63.0 quick fixes (owner approval 2026-11-12): customer passwords need 8 characters (owner sign-in keeps 12), more work
categories (tech and non-tech) at sign-up and in the profile, What's new notes for 6-12 Nov and the retired phone note."""
import json, re
NAME = "Customer journey 3.67: password, categories, What's new, account menu, plan tools, V1-V3, price question"

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
        t.check("customers see the November notes, newest first", ids[:1] and ids[0] >= "2026-11-12" and "2026-11-07-job-hunt" in ids, ids[:6])
        t.check("the phone note is no longer shown", "2026-10-26-phone" not in ids)
        t.check("the notes mention no AI", not re.search(r"\b(ai|artificial intelligence|claude|anthropic)\b", json.dumps(w).lower()))

        # an owner-saved list (made before 3.63) gets the new notes and loses the phone note when the migration runs
        saved = {"entries": [{"id": "2026-10-26-phone", "date": "2026-10-26", "tag": "new", "title": "Call us", "body": "x", "published": True, "publishedAt": "2026-10-26T00:00:00+05:30"},
                             {"id": "owner-own-note", "date": "2026-11-02", "tag": "improved", "title": "Owner note", "body": "kept", "published": True, "publishedAt": "2026-11-02T00:00:00+05:30"}]}
        db.q("delete from site_settings where key='whatsNew'")
        db.q("insert into site_settings (key, value_json, revision, created_at, updated_at) values ('whatsNew', ?, 1, datetime('now'), datetime('now'))", json.dumps(saved))
        db.q("delete from migrations where migration='2026_12_01_000001_whats_new_november'")
        r = inst.artisan("migrate", "--force")
        v = json.loads(db.q("select value_json from site_settings where key='whatsNew'")[0][0])
        got = {e["id"]: e for e in v["entries"]}
        t.check("the update adds the November notes to an owner-saved list", r.returncode == 0 and "2026-11-12-signup" in got and "2026-11-06-plans" in got, (r.returncode, list(got)[:12]))
        t.check("the owner's own note stays and the phone note is hidden, not deleted", "owner-own-note" in got and got.get("2026-10-26-phone", {}).get("published") is False)
        inst.artisan("migrate", "--force")
        v2 = json.loads(db.q("select value_json from site_settings where key='whatsNew'")[0][0])
        t.check("running it again adds nothing twice", len(v2["entries"]) == len(v["entries"]))

        # ---- 3.66 dashboard: one account menu, no duplicate links
        s, h, ws = c.page("/workspace.html")
        nav = ws.split('<nav class="side-nav">', 1)[1].split("</nav>", 1)[0] if '<nav class="side-nav">' in ws else ""
        t.check("the left menu no longer repeats the account pages", 'data-workspace-view="billing"' not in nav and 'data-workspace-view="wallet"' not in nav and "My account</div>" not in nav, nav[:200])
        t.check("the top-bar My account menu holds account, billing, refer, wallet and referral history",
                all(f'role="menuitem" data-workspace-view="{k}"' in ws for k in ("account", "billing", "referrals", "wallet", "referral-history")))
        t.check("the dashboard has no separate referral shortcut row", "ref-quick-links" not in ws)

        # ---- Job Hunt / Pro tools: free accounts get the upgrade dialog, a Job Hunt pack opens them
        from lib.plans import give_job_hunt
        s, j = c.api("POST", "resumes", {"data": {"version": "1", "title": "Mine", "template": "suite-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Eight Test", "email": "eight@test.local"}, "summary": "Team lead for customer support with five years of experience in voice and chat process.",
                                                  "experience": [{"role": "Team Lead", "company": "Acme BPO", "start": "2021", "current": True, "bullets": "Led a team of 14 agents.\nRaised CSAT from 82% to 91%."}], "education": [], "skills": "Customer service, CRM", "order": ["summary", "experience", "skills"], "projects": [], "certifications": []}})
        rid = (j or {}).get("id")
        t.check("the test resume saves", s == 200 and rid, (s, str(j)[:300]))
        s, j = c.api("POST", "letters/draft", {"resumeId": rid, "jobText": "We are hiring a support team lead to run a chat process team of 15 agents and improve CSAT."})
        t.check("a free account cannot use one-click cover letters (402 and a plan hint)", s == 402 and j.get("plan") == "career", (s, str(j)[:200]))
        js = open(ctx.public + "/upgrade-prompt.js", encoding="utf8").read()
        t.check("the upgrade dialog opens for letters, tailoring and mock interviews", "show('career')" in js and "letters\\/draft" in js and "interviews\\/start" in js)
        db.q("insert into purchases(id,owner_id,plan,status,amount_paise,provider,limits_json,access_days,purchased_at,access_until,created_at,updated_at) values('q99',?,'quick','paid',9900,'demo',?,7,datetime('now'),datetime('now','+7 days'),datetime('now'),datetime('now'))", u[0][0], json.dumps({"type": "plan", "rank": 1, "savedResumes": 1, "savedLetters": 0, "oneResume": True}))
        s, j = c.api("POST", "letters/draft", {"resumeId": rid, "jobText": "We are hiring a support team lead to run a chat process team of 15 agents and improve CSAT."})
        t.check("Quick Download (Rs 99) does not open them either", s == 402, s)
        give_job_hunt(db, "eight@test.local")
        s, j = c.api("POST", "letters/draft", {"resumeId": rid, "jobText": "We are hiring a support team lead to run a chat process team of 15 agents and improve CSAT."})
        t.check("with Job Hunt the one-click letter works", s == 200 and j.get("letter"), (s, str(j)[:200]))

        # ---- resume journey V1 -> V2 -> V3
        db.q("update resume_drafts set is_primary=1 where id=?", rid)
        s, j = c.api("GET", "resumes/journey"); jr = (j or {}).get("journey", {})
        t.check("the journey shows V1 and V2 with real scores (same before any edit)", s == 200 and jr.get("v1", {}).get("score") == jr.get("v2", {}).get("score") and jr["v1"]["same"] is True, (s, str(j)[:300]))
        rev = db.q("select revision from resume_drafts where id=?", rid)[0][0]
        doc = json.loads(db.q("select content_json from resume_drafts where id=?", rid)[0][0])
        doc["skills"] = "Customer service, CRM, Zendesk, Freshdesk, team leadership, SLA management, quality audits, coaching"
        s2, j2 = c.api("POST", "resumes", {"id": rid, "revision": rev, "data": doc})
        s, j = c.api("GET", "resumes/journey"); jr = (j or {}).get("journey", {})
        t.check("after an edit V1 keeps the first version's score and V2 shows the new one", jr.get("v1", {}).get("same") is False and jr["v2"]["gain"] == jr["v2"]["score"] - jr["v1"]["score"], (s2, str(j2)[:150], str(j)[:300]))

        # ---- price question after a save
        t.check("the price answer must be one of the three choices", c.api("POST", "feedback/ask", {"action": "price", "value": "cheap"})[0] == 422)
        t.check("a price answer is kept", c.api("POST", "feedback/ask", {"action": "price", "value": "fair"})[0] == 200)
        c.api("POST", "feedback/ask", {"action": "price", "value": "great_value"})
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        s, j = o.api("GET", "admin/reviews?status=pending")
        pv = (j or {}).get("priceViews", {})
        t.check("the owner sees one answer per customer (the latest)", pv.get("great_value") == 1 and pv.get("fair") == 0, pv)

        # ---- template coverage: every role group and career level has at least five of each
        s, j = o.api("GET", "admin/template-library/coverage?per=50")
        rows = {r["cluster"]: r for r in (j or {}).get("rows", [])}
        short = {k: (r["resumes"], r["letters"]) for k, r in rows.items() if k not in ("General", "Reference collection") and (r["resumeGap"] or r["letterGap"])}
        t.check("every role group has five resume designs and five cover letters", s == 200 and len(rows) >= 8 and not short, (s, short))
        t.check("letters are no longer all filed under Technology", rows.get("Technology", {}).get("letters", 99) < 20, rows.get("Technology"))
        lv = {l["level"]: l for l in (j or {}).get("levels", [])}
        t.check("fresher, mid and experienced each have five of each", set(lv) == {"fresher", "mid", "experienced"} and all(l["resumes"] >= 5 and l["letters"] >= 5 for l in lv.values()), lv)
    finally:
        inst.stop()
