"""Pre-launch checks (3.27.2): ATS Match reads Indian job posts fairly (degrees such as B.Com/B.E., "0 to 2 years" for freshers,
location/salary lines and the employer's name are not keywords, "good to have" is optional),
and nothing the public can download calls the product AI."""
import json, os, re, tempfile
NAME = "Pre-launch: ATS fairness and public wording"

FRESHER = {"personal": {"name": "Ravi Kumar", "email": "ravi@example.com", "phone": "9876543210", "city": "Pune"},
           "summary": "Fresher B.Com graduate looking for an accounts role. Comfortable with Tally Prime, GST filing and Excel.",
           "skills": "Tally Prime, GST, Excel, Accounting, Bank reconciliation, Communication",
           "projects": [{"name": "GST return project", "bullets": "Prepared GST returns for a local shop for 6 months.\nReconciled bank statements and fixed 15 mismatches."}],
           "education": [{"degree": "B.Com", "school": "Pune University", "end": "2025"}]}
FRESHER_JD = ("Job Title: Accounts Executive\nLocation: Mumbai, Maharashtra\nSalary: 2.5 - 3.5 LPA\nExperience: 0 to 2 years\nJob Type: Full-time\n\n"
              "Job Description\nWe are hiring an Accounts Executive for our Mumbai office.\nResponsibilities:\n- Maintain books of accounts in Tally Prime.\n"
              "- Prepare and file GST returns and TDS on time.\n- Do bank reconciliation every month.\n- Should have good knowledge of MS Excel.\n\n"
              "Qualification: B.Com / M.Com / BBA\nFreshers can apply. Immediate joiners preferred.\nInterested candidates can share CV on WhatsApp.\n")
DEV = {"personal": {"name": "Neha", "email": "neha@example.com", "phone": "9876543210"},
       "summary": "Backend developer with 3 years of experience building Java Spring Boot services and REST APIs on AWS.",
       "skills": "Java, Spring Boot, SQL, MySQL, Docker, Git, AWS, REST APIs, JUnit",
       "experience": [{"role": "Software Engineer", "company": "Infy", "start": "2022", "current": True, "bullets": "Developed REST APIs in Java and Spring Boot serving 2 million requests a day.\nDeployed services on AWS with Docker and Jenkins pipelines.\nWrote unit tests with JUnit and raised coverage to 80%."}],
       "education": [{"degree": "B.E. Computer Science", "school": "VTU", "end": "2022"}]}
DEV_JD = ("Java Developer - TCS\nExperience: 3 to 5 years\nLocation: Hyderabad\n\nKey Responsibilities:\n- Develop microservices using Java 17 and Spring Boot.\n"
          "- Design REST APIs and integrate with Kafka.\n- Deploy on AWS or Azure using Docker and Kubernetes.\n- Write unit tests (JUnit, Mockito).\n- Use Git and CI/CD pipelines.\n\n"
          "Must have: Java, Spring Boot, SQL, Microservices.\nGood to have experience with Kafka streams.\nEducation: B.E/B.Tech in Computer Science from a recognised university.")


def run(ctx, t):
    if not ctx.preview: return
    tmp = os.path.join(tempfile.mkdtemp(prefix="ats-prelaunch-"), "pairs.json")
    json.dump([[FRESHER, FRESHER_JD], [DEV, DEV_JD], [DEV, DEV_JD]], open(tmp, "w", encoding="utf8"))
    r = ctx.inst.artisan("cvtara:ats-report", tmp)
    try: fr, dv, dv2 = json.loads(r.stdout)
    except ValueError: t.check("the ATS report command runs", False, (r.stderr or r.stdout)[-300:]); return
    reqs = lambda rep: {x["name"]: x for x in rep["result"]["requirements"]}
    missing = [m.lower() for m in fr["keywords"]["missing"]]
    t.check("location, salary, 'title' and how-to-apply words are not keywords", not set(missing) & {"mumbai", "maharashtra", "lpa", "title", "whatsapp"}, missing)
    t.check("a list of acceptable degrees (B.Com / M.Com / BBA) does not count the others as missing", "m.com" not in missing and "bba" not in missing, missing)
    t.check("a fresher post (0 to 2 years) asks for no minimum experience", not any(k.startswith("Experience duration") for k in reqs(fr)), list(reqs(fr)))
    edu = reqs(fr).get("Education or professional eligibility", {})
    t.check("a B.Com on the resume is found for a B.Com job", edu.get("status") == "review" and "B.Com" in edu.get("evidence", ""), edu)
    t.check("a well-matched fresher scores in the good range", fr["score"] >= 60, fr["score"])
    d = reqs(dv)
    t.check("'3 to 5 years' asks for 3 years, not 5", "Experience duration · 3+ years" in d, list(d))
    t.check("B.E. with dots counts as a degree", d.get("Education or professional eligibility", {}).get("status") == "review", d.get("Education or professional eligibility"))
    t.check("an education line does not make an IT job ask for teaching or edtech", "Education and edtech" not in d, list(d))
    t.check("the employer on the title line (Java Developer - TCS) is not a missing keyword", "TCS" not in dv["keywords"]["missing"], dv["keywords"]["missing"])
    t.check("'Must have: Java' makes Java a required skill", d.get("Java", {}).get("priority") == "Required", d.get("Java"))
    t.check("the same resume and job always give the same score", dv["score"] == dv2["score"] and dv["keywords"] == dv2["keywords"])

    pub = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "public_html")
    bad = re.compile(r"Tara AI|Anthropic|Claude\b|ChatGPT|OpenAI|AI[- ](?:powered|generated|written|resume builder)", re.I)
    hits = [f for f in os.listdir(pub) if f.endswith((".js", ".html", ".css", ".json")) and f not in ("OneSignalSDKWorker.js",) and bad.search(open(os.path.join(pub, f), encoding="utf8", errors="ignore").read())]
    t.check("no file anyone can download from the site names AI or its provider", not hits, hits)
    t.check("the owner's service-queue script is served only from the owner console", not os.path.exists(os.path.join(pub, "refunds-admin.js")) and os.path.exists(os.path.join(ctx.app, "resources/console/refunds.js")))
    seo = json.load(open(os.path.join(ctx.app, "resources/data/defaults.json"), encoding="utf8"))["seo"]["pages"]
    t.check("no default page keyword says AI", not [p for p, v in seo.items() if re.search(r"\bai\b", v.get("keywords", ""), re.I)])
    cl = ctx.client()
    s, _, p = cl.page("/ats-checker/")
    t.check("the ATS checker page leads with its own check, not 'Use this template'", s == 200 and "Use this template" not in p, s)
    s, _, p = cl.page("/resume-examples/information-technology/software-engineer/")
    t.check("role pages still offer Use this template", s == 200 and "Use this template" in p, s)
