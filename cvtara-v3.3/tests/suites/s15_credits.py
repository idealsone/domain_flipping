"""Document credits: 1 free primary resume + one shared pool for tailored resumes and cover letters; add-ons enlarge the pool."""
NAME = "Document credits (shared pool)"


def resume(title):
    return {"version": "1", "title": title, "template": "suite-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Ann"}, "summary": "x",
            "order": ["summary", "experience"], "experience": [], "education": [], "projects": [], "certifications": []}


def letter(n):
    return {"version": "1", "title": f"Letter {n}", "template": "letter-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Ann"}, "company": f"Co{n}", "role": "Recruiter", "opening": "Dear team, I am applying.", "evidence": "I hired 200 people.", "closing": "Thanks.",
            "experience": [], "education": [], "projects": [], "certifications": []}


def run(ctx, t):
    if not ctx.preview: return
    c = ctx.client(); c.register("credit-a@test.local")
    def buy(plan):
        s, j = c.api("POST", "checkout/start", {"plan": plan})
        return c.api("POST", "checkout/verify", {"provider": "demo", "orderId": j["orderId"]})[0] if s == 200 else s
    t.check("buy Fresher Basic", buy("fresher-basic") == 200)
    cr = lambda: c.api("GET", "allowance")[1]["credits"]
    t.check("Fresher Basic pool is 20 credits (10 + 10), shared", cr()["total"] == 20 and cr()["used"] == 0, cr())
    s, j = c.api("POST", "resumes", {"data": resume("Primary")}); t.check("the primary resume is free and does not use a credit", s == 200 and cr()["used"] == 0, f"{s} {j}")
    pid = j["id"]
    for i in range(15):
        s, v = c.api("POST", f"resumes/{pid}/variant", {"company": f"Co{i}", "role": "Recruiter", "jobDescription": "Need recruiter"})
        if s != 200: t.check(f"tailored resume {i + 1}", False, v); break
    t.check("15 tailored resumes use 15 credits", cr()["used"] == 15 and cr()["tailored"] == 15, cr())
    ok = 0
    for i in range(5):
        s, j = c.api("POST", "letters", {"data": letter(i)}); ok += s == 200
        if s != 200 and i == 0: t.check("cover letter save works", False, f"{s} {j}")
    t.check("5 cover letters fit in the remaining 5 credits", ok == 5 and cr()["used"] == 20 and cr()["letters"] == 5, (ok, cr()))
    s, j = c.api("POST", "letters", {"data": letter(99)}); t.check("the 21st document is blocked with a clear message", s == 422 and "20 document credits" in str(j) and "15 tailored" in str(j), f"{s} {j}")
    s, j = c.api("POST", f"resumes/{pid}/variant", {"company": "Over", "role": "R"}); t.check("another tailored resume is blocked too (same pool)", s == 422 and "document credits" in str(j), f"{s} {j}")
    s, j = c.api("POST", "letters", {"data": letter(0), "id": c.api("GET", "letters")[1]["letters"][0]["id"], "revision": c.api("GET", "letters")[1]["letters"][0]["revision"]}); t.check("editing an existing letter uses no credit", s == 200, f"{s} {j}")
    t.check("the letters list reports the pool", c.api("GET", "letters")[1]["credits"]["total"] == 20)
    t.check("buy +10 resumes & +10 letters add-on", buy("addon-10") == 200)
    t.check("pool becomes 40 (20 + 10 + 10)", cr()["total"] == 40 and cr()["includesAddOns"] == 20, cr())
    ok = 0
    for i in range(20):
        s, _ = c.api("POST", "letters", {"data": letter(100 + i)}); ok += s == 200
    t.check("all 20 extra credits can be used on letters (or resumes) in any mix", ok == 20 and cr()["used"] == 40, (ok, cr()))
    s, j = c.api("POST", f"resumes/{pid}/variant", {"company": "Over", "role": "R"}); t.check("then the pool is empty again", s == 422)
    t.check("clean downloads are no longer limited by a separate export counter", c.api("GET", "allowance")[1]["caps"]["exports"] is None)
