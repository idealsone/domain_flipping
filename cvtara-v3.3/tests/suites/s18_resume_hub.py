"""Resume hub: upload an existing resume, ATS-friendly score on every resume, job match on the list, no deleting saved resumes,
automatic tracker entry with three status reminders (notification + email), then silence."""
import os
NAME = "Resume upload, ATS-friendly score, delete lock and reminders"

SAMPLE = """RAHUL VERMA
Senior HR Executive
rahul.verma@example.com | +91 98765 43210 | Bengaluru, Karnataka | linkedin.com/in/rahulverma

PROFESSIONAL SUMMARY
HR professional with six years of experience in technology recruitment, onboarding and employee engagement, known for filling hard roles quickly and keeping hiring managers informed at every step of the process.

WORK EXPERIENCE
Senior HR Executive | Infosys, Bengaluru
Jan 2021 - Present
• Managed end-to-end hiring for 120+ roles per year across engineering and finance
• Reduced time-to-hire by 22% by building weekly pipeline reports in Excel
• Coordinated interviews with 40 hiring managers and maintained the applicant tracking system
HR Associate
TCS | Jun 2018 – Dec 2020
• Screened 3000+ resumes and sourced candidates on LinkedIn
• Improved offer acceptance to 91% through structured onboarding

EDUCATION
MBA in Human Resources
Symbiosis Pune 2016 - 2018

SKILLS
Recruitment, Onboarding, Excel, Applicant tracking, Stakeholder management, LinkedIn sourcing, Interview coordination, Reporting

CERTIFICATIONS
SHRM-CP - SHRM 2022

LANGUAGES
English, Hindi, Kannada
"""
NO_HEADINGS = "Priya works in logistics and has handled warehouse operations for many years across several cities in India, coordinating drivers and vendors every day of the week."


def minimal(title="Thin"):
    return {"version": "1", "title": title, "template": "suite-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Ann"}, "summary": "", "order": ["summary"], "experience": [], "education": [], "projects": [], "certifications": []}


def run(ctx, t):
    src = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "public_html")
    rd = lambda f: open(os.path.join(src, f), encoding="utf8").read()
    if ctx.preview:
        hub = rd("primary-resume.js"); wa = rd("workspace-activity.js"); ui = rd("connected-ui.js")
        t.check("My Resumes has no delete button", "data-del" not in hub and "Delete this resume" not in hub and "method: 'DELETE'" not in hub)
        t.check("My Resumes offers Edit, Download, ATS check and Make primary", all(k in hub for k in ("data-open", "data-export", "data-ats", "data-primary")))
        t.check("My Resumes offers upload and template choices and shows the ATS-friendly percentage", "data-upload" in hub and "data-new" in hub and "ATS-friendly" in hub and "rh-ring" in hub)
        t.check("the page reader accepts PDF, DOCX, DOC and text files", all(k in ui for k in ("txt|md", "\\.doc$", "docx")))
        t.check("the tracker shows the reminder count and a stop switch", "Reminders" in wa and "remindersOff" in wa)
        t.check("the workspace loads the new styles and reminder cards", "resume-hub.css" in open(os.path.join(ctx.app, "resources/frontend/workspace.html"), encoding="utf8").read() and "notify-ui.js" in open(os.path.join(ctx.app, "resources/frontend/workspace.html"), encoding="utf8").read())

    db = ctx.db(); c = ctx.client(); c.register("hub-a@test.local")
    def buy(plan):
        s, j = c.api("POST", "checkout/start", {"plan": plan})
        return c.api("POST", "checkout/verify", {"provider": "demo", "orderId": j["orderId"]})[0] if s == 200 else s

    # ---- import ----
    s, j = c.api("POST", "resumes/import", {"text": SAMPLE, "fileName": "rahul.pdf"})
    rid = j.get("id")
    t.check("an uploaded resume becomes a saved draft", s == 200 and rid and j["found"]["experience"] == 2 and j["found"]["education"] == 1 and j["found"]["skills"] >= 6, (s, str(j)[:200]))
    s, d = c.api("GET", f"resumes/{rid}"); r = d["resume"]["data"] if s == 200 else {}
    t.check("name, role, email, phone, city and links are found", r.get("personal", {}).get("name") == "Rahul Verma" and r["personal"]["role"] == "Senior HR Executive" and r["personal"]["email"] == "rahul.verma@example.com" and "98765" in r["personal"]["phone"] and r["personal"]["city"].startswith("Bengaluru") and "linkedin.com" in r["personal"]["linkedin"], r.get("personal"))
    ex = r.get("experience", [])
    t.check("each job keeps its role, company, dates and bullets", len(ex) == 2 and ex[0]["role"] == "Senior HR Executive" and ex[0]["company"] == "Infosys" and ex[0]["current"] and ex[0]["start"] == "Jan 2021" and ex[0]["bullets"].count("\n") == 2 and ex[1]["role"] == "HR Associate" and ex[1]["company"] == "TCS" and ex[1]["end"] == "Dec 2020", ex)
    t.check("education, certificate, skills and languages are filled", r["education"][0]["degree"].startswith("MBA") and r["education"][0]["school"].startswith("Symbiosis") and r["certifications"][0]["name"] == "SHRM-CP" and "Onboarding" in r["skills"] and r["languages"] == "English, Hindi, Kannada", (r["education"], r["certifications"]))
    t.check("the first resume becomes the primary resume and is marked as uploaded", bool(c.api("GET", "resumes")[1]["resumes"][0]["is_primary"]) and db.q("select source from resume_drafts where id=?", rid)[0][0] == "import")
    t.check("an empty section-labels object stays an object", '"sectionLabels":{}' in db.q("select content_json from resume_drafts where id=?", rid)[0][0])
    t.check("too little text is refused", c.api("POST", "resumes/import", {"text": "hello"})[0] == 422)
    s, j2 = c.api("POST", "resumes/import", {"text": SAMPLE, "fileName": "again.pdf"})
    t.check("a free account still keeps one resume when it uploads another", s == 422 and "free account keeps one" in str(j2), (s, str(j2)[:160]))

    # ---- ATS-friendly score ----
    lst = c.api("GET", "resumes")[1]; row = lst["resumes"][0]
    t.check("a properly filled resume scores above 70% for ATS", row["ats_score"] is not None and row["ats_score"] >= 70, row["ats_score"])
    t.check("the list marks the resume as primary number 1 and shows no delete option", row["number"] == 1 and lst["canDelete"] is False)
    s, q = c.api("GET", f"resumes/{rid}/quality"); qq = q.get("quality", {})
    t.check("the score breakdown lists every check with points and tips", s == 200 and sum(x["max"] for x in qq["checks"]) == 100 and sum(x["got"] for x in qq["checks"]) == qq["score"] == row["ats_score"] and qq["level"] in ("good", "excellent"), str(q)[:200])
    s, jt = c.api("POST", "resumes", {"data": minimal()}); 
    other = ctx.client(); other.register("hub-b@test.local"); so, jo = other.api("POST", "resumes", {"data": minimal("Thin")})
    low = other.api("GET", "resumes")[1]["resumes"][0]; ql = other.api("GET", f"resumes/{jo['id']}/quality")[1]["quality"]
    t.check("a thin resume scores low and the tips say what to add", low["ats_score"] < 50 and ql["tips"] and ql["level"] == "low", (low["ats_score"], ql["tips"]))
    t.check("another person's resume score cannot be read", c.api("GET", f"resumes/{jo['id']}/quality")[0] == 404)
    db.q("update resume_drafts set ats_score=NULL where id=?", jo["id"])
    t.check("a resume saved before scores existed is scored when listed", other.api("GET", "resumes")[1]["resumes"][0]["ats_score"] is not None)
    s, ed = c.api("GET", f"resumes/{rid}"); d2 = ed["resume"]["data"]; d2["skills"] = "Excel"; d2["summary"] = ""
    c.api("POST", "resumes", {"id": rid, "revision": ed["resume"]["revision"], "data": d2})
    t.check("saving changes the score right away", c.api("GET", "resumes")[1]["resumes"][0]["ats_score"] < row["ats_score"])
    d2["skills"] = r["skills"]; d2["summary"] = r["summary"]; s, ed = c.api("GET", f"resumes/{rid}")
    c.api("POST", "resumes", {"id": rid, "revision": ed["resume"]["revision"], "data": d2})

    # ---- ATS match shows on the list ----
    JOB = "We are hiring a recruiter. Responsibilities: source candidates, screen candidates, schedule interviews, maintain the applicant tracking system. Required: 2+ years of recruitment experience and strong communication skills."
    t.check("buy Fresher Basic", buy("fresher-basic") == 200)
    s, sc = c.api("POST", "ats/scan", {"resumeId": rid, "source": "text", "text": JOB, "title": "Recruiter", "company": "Zeta"})
    m = c.api("GET", "resumes")[1]["resumes"][0]["match"]
    t.check("the resume list shows the latest job match next to the ATS-friendly score", s == 200 and m and m["score"] == sc["report"]["score"] and m["company"] == "Zeta" and m["count"] == 1, (s, m))

    # ---- no deleting ----
    s, j = c.api("DELETE", f"resumes/{rid}")
    t.check("a saved resume cannot be deleted", s == 422 and "stay in your account" in str(j), (s, str(j)[:160]))
    s, j = c.api("POST", "resumes", {"data": {**d2, "title": "Second"}}); r2 = j.get("id")
    t.check("a second resume numbers as Resume 2", s == 200 and [x["number"] for x in c.api("GET", "resumes")[1]["resumes"] if x["id"] == r2] == [2], (s, str(j)[:120]))
    t.check("even a never-downloaded resume cannot be deleted", c.api("DELETE", f"resumes/{r2}")[0] == 422 and db.q("select count(*) from resume_drafts where owner_id=(select owner_id from resume_drafts where id=?)", r2)[0][0] == 2)
    t.check("make primary still works and the other resumes keep their order", c.api("POST", f"resumes/{r2}/primary", {})[0] == 200 and c.api("GET", "resumes")[1]["resumes"][0]["id"] in (rid, r2))
    def dl(kind, doc, fmt="print"): return c.raw("POST", f"/export/{kind}/{doc}/{fmt}", {"clean": True}, {"Accept": "application/json", "X-CSRF-TOKEN": c.csrf or ""})
    s, j = c.api("POST", "letters", {"data": {"version": "1", "title": "L", "template": "letter-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Ann"}, "company": "Q", "role": "R", "opening": "Dear team, I am applying.", "evidence": "I hired 200 people.", "closing": "Thanks.", "experience": [], "education": [], "projects": [], "certifications": []}}); lid = j.get("id")
    t.check("a never-downloaded letter can still be removed", c.api("DELETE", f"letters/{lid}")[0] == 200)
    s, j = c.api("POST", "letters", {"data": {"version": "1", "title": "L2", "template": "letter-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Ann"}, "company": "Q2", "role": "R2", "opening": "Dear team, I am applying.", "evidence": "I hired 200 people.", "closing": "Thanks.", "experience": [], "education": [], "projects": [], "certifications": []}}); lid2 = j.get("id")
    dl("letter", lid2); s, j = c.api("DELETE", f"letters/{lid2}")
    t.check("a downloaded letter can no longer be deleted", s == 422 and "downloaded" in str(j), (s, str(j)[:140]))
    s, h, b = dl("resume", r2)
    t.check("a downloaded resume is marked as downloaded", db.q("select downloaded_at is not null from resume_drafts where id=?", r2)[0][0] == 1)

    # ---- tracker entry and three reminders ----
    apps = lambda: c.api("GET", "applications")[1]["applications"]
    s, v = c.api("POST", f"resumes/{rid}/variant", {"company": "Nimbus", "role": "Recruiter", "scanId": sc["scanId"]})
    a = [x for x in apps() if x["company"] == "Nimbus"]
    t.check("preparing a tailored resume from a check adds the job to the tracker", s == 200 and v.get("tracker") == "added" and len(a) == 1 and a[0]["origin"] == "ats" and a[0]["scan_id"] == sc["scanId"] and a[0]["resume_id"] == v["id"] and a[0]["stage"] == "saved", (s, a))
    t.check("the first reminder is due in three days", a[0]["reminders_sent"] == 0 and a[0]["next_reminder_at"] is not None, a[0]["next_reminder_at"])
    t.check("preparing the same job again does not duplicate it", c.api("POST", f"resumes/{rid}/variant", {"company": "nimbus", "role": "recruiter", "scanId": sc["scanId"]})[1].get("tracker") == "updated" and len([x for x in apps() if x["company"].lower() == "nimbus"]) == 1)
    aid = a[0]["id"]
    notes = lambda cl=None: (cl or c).api("GET", "notifications")[1]["notifications"]
    t.check("nothing is sent before the reminder is due", ctx.inst.artisan("cvtara:remind").returncode == 0 and not notes())
    mail0 = db.q("select count(*) from mail_outbox where trigger='application_status'")[0][0]
    sent = []
    for n in (1, 2, 3):
        db.q("update applications set next_reminder_at=datetime('now','-1 minute') where id=?", aid)
        out = ctx.inst.artisan("cvtara:remind").stdout.strip(); sent.append(out)
        row = db.q("select reminders_sent,next_reminder_at from applications where id=?", aid)[0]
        t.check(f"reminder {n} is sent and counted", f"sent: 1" in out and row[0] == n, (out, row))
    t.check("the gaps are 3, 4 and 7 days and nothing is scheduled after the third", db.q("select next_reminder_at is null from applications where id=?", aid)[0][0] == 1)
    ns = notes()
    t.check("three in-app reminders were created and the last says 3 of 3", len(ns) == 3 and any("Reminder 3 of 3" in x["body"] for x in ns) and all(x["link"] == "/workspace.html?view=tracker" for x in ns), ns)
    t.check("an email reminder was queued each time", db.q("select count(*) from mail_outbox where trigger='application_status'")[0][0] - mail0 == 3)
    body = db.q("select subject,body from mail_outbox where trigger='application_status' and subject like '%3 of 3%'")[0]
    t.check("the email names the job, its status and the count", "Nimbus" in body[0] and "Recruiter" in body[0] and "3 of 3" in body[0] and "Saved" in body[1] and "will not remind you again" in body[1], body)
    db.q("update applications set next_reminder_at=datetime('now','-1 minute') where id=?", aid)
    t.check("after the third reminder no more are sent", "sent: 0" in ctx.inst.artisan("cvtara:remind").stdout and db.q("select reminders_sent from applications where id=?", aid)[0][0] == 3)
    t.check("another person sees none of these reminders", not notes(other))
    c.api("POST", "notifications", {"ids": [ns[0]["id"]]}); t.check("a reminder can be dismissed", len(notes()) == 2)
    c.api("POST", "notifications", {}); t.check("all reminders can be dismissed at once", not notes())

    # stop switch, finished stages, manual entries
    s, j = c.api("POST", f"resumes/{rid}/variant", {"company": "Orbit", "role": "Analyst"}); oid = [x for x in apps() if x["company"] == "Orbit"][0]
    t.check("a tailored resume without a check is added as 'prepared'", oid["origin"] == "prepared")
    s, j = c.api("POST", "applications", {"company": "Orbit", "role": "Analyst", "stage": "saved", "id": oid["id"], "revision": oid["revision"], "remindersOff": True})
    db.q("update applications set next_reminder_at=datetime('now','-1 minute') where id=?", oid["id"])
    t.check("a job with reminders stopped gets none", s == 200 and "sent: 0" in ctx.inst.artisan("cvtara:remind").stdout and not notes())
    s, j = c.api("POST", f"resumes/{rid}/variant", {"company": "Pulse", "role": "Lead"}); pid = [x for x in apps() if x["company"] == "Pulse"][0]
    s, j = c.api("POST", "applications", {"company": "Pulse", "role": "Lead", "stage": "offer", "id": pid["id"], "revision": pid["revision"]})
    t.check("an offer or closed job schedules no reminder", s == 200 and db.q("select next_reminder_at is null from applications where id=?", pid["id"])[0][0] == 1)
    s, j = c.api("POST", "applications", {"company": "Manual Co", "role": "Clerk", "stage": "applied"}); mid = j["id"]
    db.q("update applications set next_reminder_at=datetime('now','-1 minute') where id=?", mid)
    t.check("jobs the customer added by hand are never reminded about", "sent: 0" in ctx.inst.artisan("cvtara:remind").stdout)
    s, j = c.api("POST", f"resumes/{rid}/variant", {"company": "Quartz", "role": "Buyer"}); qid = [x for x in apps() if x["company"] == "Quartz"][0]
    c.api("POST", "applications", {"company": "Quartz", "role": "Buyer", "stage": "interview", "id": qid["id"], "revision": qid["revision"]})
    db.q("update applications set next_reminder_at=datetime('now','-1 minute') where id=?", qid["id"]); ctx.inst.artisan("cvtara:remind")
    t.check("the reminder asks the right question for the current stage", any("interview" in x["body"].lower() and "Interview" in x["body"] for x in notes()), notes())

    # ---- unsuitable text ----
    s, j = c.api("POST", "resumes/import", {"text": NO_HEADINGS * 2})
    t.check("text with no sections is kept in the summary with a clear warning", s == 422 or (s == 200 and any("section headings" in w for w in j.get("warnings", []))), (s, str(j)[:160]))
