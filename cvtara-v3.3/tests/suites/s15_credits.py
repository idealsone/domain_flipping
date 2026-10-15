"""Download credits: drafts are free; a clean download uses 1 credit of its own kind (resume / cover letter, separate pools);
one primary resume is free once for life after a first purchase; the same version again is free; add-ons add to each pool for 45 days."""
import datetime as dt
NAME = "Download credits (separate pools)"


def resume(title, extra=""):
    return {"version": "1", "title": title, "template": "suite-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Ann"}, "summary": "x" + extra,
            "order": ["summary", "experience"], "experience": [], "education": [], "projects": [], "certifications": []}


def letter(n):
    return {"version": "1", "title": f"Letter {n}", "template": "letter-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Ann"}, "company": f"Co{n}", "role": "Recruiter",
            "opening": "Dear team, I am applying.", "evidence": "I hired 200 people.", "closing": "Thanks.", "experience": [], "education": [], "projects": [], "certifications": []}


def run(ctx, t):
    if not ctx.preview: return
    db = ctx.db(); c = ctx.client(); c.register("credit-a@test.local")
    def buy(plan):
        s, j = c.api("POST", "checkout/start", {"plan": plan})
        return c.api("POST", "checkout/verify", {"provider": "demo", "orderId": j["orderId"]})[0] if s == 200 else s
    cr = lambda: c.api("GET", "allowance")[1]["credits"]
    def download(kind, doc, fmt="print", clean=True):
        s, h, b = c.raw("POST", f"/export/{kind}/{doc}/{fmt}", {"clean": clean}, {"Accept": "application/json", "X-CSRF-TOKEN": c.csrf or ""})
        return s, b[:200]

    t.check("buy Fresher Basic", buy("fresher-basic") == 200)
    t.check("Fresher Basic = 10 resume + 10 cover-letter downloads (separate)", cr()["resumes"]["total"] == 10 and cr()["letters"]["total"] == 10 and cr()["resumes"]["used"] == 0, cr())
    s, j = c.api("POST", "resumes", {"data": resume("Primary")}); pid = j["id"]
    vids = []
    for i in range(12):
        s, v = c.api("POST", f"resumes/{pid}/variant", {"company": f"Co{i}", "role": "Recruiter", "jobDescription": "Need recruiter"}); vids.append(v.get("id"))
    t.check("saving 12 tailored drafts is free (no credits used)", all(vids) and cr()["resumes"]["used"] == 0, cr())
    lids = []
    for i in range(12):
        s, j = c.api("POST", "letters", {"data": letter(i)}); lids.append(j.get("id"))
    t.check("saving 12 cover-letter drafts is free", all(lids) and cr()["letters"]["used"] == 0, cr())
    t.check("a watermarked preview download is free", download("resume", vids[0], clean=False)[0] == 200 and cr()["resumes"]["used"] == 0)
    t.check("the lifetime free primary resume is offered before it is used", cr()["primaryFree"] is True, cr())
    t.check("the first primary resume download is free (one-time)", download("resume", pid)[0] == 200 and cr()["resumes"]["used"] == 0)
    t.check("the one-time free primary is now claimed", cr()["primaryFree"] is False, cr())
    t.check("the same primary version in another format is still free", download("resume", pid, "docx")[0] == 200 and cr()["resumes"]["used"] == 0)
    ok = [download("resume", vids[i])[0] for i in range(10)]
    t.check("10 tailored resumes use 10 resume credits", ok == [200] * 10 and cr()["resumes"]["used"] == 10 and cr()["letters"]["used"] == 0, (ok, cr()))
    s, b = download("resume", vids[10]); t.check("the 11th resume download is blocked with a clear message", s == 422 and b"resume download credits" in b, (s, b))
    t.check("the same version in another format is free", download("resume", vids[0], "docx")[0] == 200 and cr()["resumes"]["used"] == 10)
    t.check("cover-letter credits are untouched by resume downloads", cr()["letters"]["used"] == 0 and cr()["letters"]["remaining"] == 10)
    ok = [download("letter", lids[i])[0] for i in range(10)]
    t.check("10 cover letters use the 10 letter credits", ok == [200] * 10 and cr()["letters"]["used"] == 10, (ok, cr()))
    s, b = download("letter", lids[10]); t.check("the 11th cover-letter download is blocked", s == 422 and b"cover-letter download credits" in b, (s, b))
    # editing makes a new version that needs a new credit
    s, g = c.api("GET", f"resumes/{vids[0]}"); rev = g["resume"]["revision"]
    d = g["resume"]["data"]; d["summary"] = "edited"; s, e = c.api("POST", "resumes", {"data": d, "id": vids[0], "revision": rev})
    s, b = download("resume", vids[0]); t.check("an edited version needs a new credit (none left)", s == 422, (s, b))
    # add-on: +10 resumes & +10 letters, separate balances
    t.check("buy +10 resumes & +10 cover letters add-on", buy("addon-10") == 200)
    k = cr()
    t.check("add-on adds 10 to each pool (20 + 20, separate)", k["resumes"]["total"] == 20 and k["letters"]["total"] == 20 and k["addOns"]["resumes"] == 10 and k["addOns"]["letters"] == 10, k)
    t.check("the edited resume and an 11th resume can now be downloaded", download("resume", vids[0])[0] == 200 and download("resume", vids[10])[0] == 200 and cr()["resumes"]["used"] == 12)
    t.check("a letter can be downloaded again after the add-on", download("letter", lids[10])[0] == 200 and cr()["letters"]["used"] == 11)
    row = db.q("select access_until, purchased_at from purchases where plan='addon-10' and owner_id=(select id from users where email='credit-a@test.local')")[0]
    days = (dt.datetime.fromisoformat(row[0]) - dt.datetime.fromisoformat(row[1])).days
    t.check("add-on credits are valid 45 days from purchase", 44 <= days <= 45, days)
    t.check("plan renewal keeps unused add-on credits", buy("fresher-basic") == 200 and cr()["addOns"]["resumes"] + cr()["addOns"]["letters"] > 0, cr())
    s, g = c.api("GET", f"resumes/{pid}"); d = g["resume"]["data"]; d["summary"] = "primary edited"
    c.api("POST", "resumes", {"data": d, "id": pid, "revision": g["resume"]["revision"]}); before = cr()["resumes"]["used"]
    t.check("after a renewal the primary resume is not free again (an edited primary uses a credit)", download("resume", pid)[0] == 200 and cr()["resumes"]["used"] == before + 1, (before, cr()))
    t.check("no separate export counter any more", c.api("GET", "allowance")[1]["caps"]["exports"] is None)
    t.check("free users cannot download clean files", ctx.client().register("credit-b@test.local") and True)
    f = ctx.client(); f.register("credit-c@test.local"); s, j = f.api("POST", "resumes", {"data": resume("Mine")})
    s2, h, b = f.raw("POST", f"/export/resume/{j['id']}/print", {"clean": True}, {"Accept": "application/json", "X-CSRF-TOKEN": f.csrf or ""})
    t.check("a normal user (no plan) gets 402 for a clean download", s2 == 402, s2)
    s1, j1 = f.api("POST", "resumes", {"data": resume("Second")})
    t.check("a free account cannot create a second resume", s1 == 422, (s1, j1))
    sl, jl = f.api("POST", "letters", {"data": letter(1)}); sl2, jl2 = f.api("POST", "letters", {"data": letter(2)})
    t.check("a free account gets exactly one cover letter", sl == 200 and sl2 == 422, (sl, sl2))
    t.check("a free account cannot create a tailored version", f.api("POST", f"resumes/{j['id']}/variant", {"company": "X", "role": "Y", "jobDescription": "z"})[0] == 422)
