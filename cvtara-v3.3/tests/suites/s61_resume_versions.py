"""V7 step 4 (3.52.0): resume version history and document lineage. Every save keeps the previous content; the copy
used for a job application is frozen; a version can be viewed and put back (and that can be undone); cover letters
remember the resume, ATS check and application they were made from; another customer can see none of it."""
import json, os
NAME = "V7 step 4: resume versions and letter lineage"


def resume(summary):
    return {"version": "1", "title": "Primary", "template": "suite-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Asha", "email": "x@test.local", "role": "Store Manager"},
            "summary": summary, "order": ["summary", "experience"], "experience": [{"id": "1", "role": "Store Manager", "company": "Sample Retail", "start": "2020-01", "current": True, "bullets": "Hired store staff across 14 stores."}],
            "education": [], "projects": [], "certifications": []}


def letter():
    return {"version": "1", "title": "Letter", "template": "letter-prism", "accent": "#153b5c", "font": "Arial", "personal": {"name": "Asha"}, "company": "Big Bazaar", "role": "Store Manager",
            "opening": "I am applying for the Store Manager role.", "evidence": "I hired store staff across 14 stores."}


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    inst = Instance(ctx.app, ctx.public, preview=True).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        c = Client(inst.base); c.register("rv-a@test.local"); c.page("/workspace.html")
        s, j = c.api("POST", "resumes", {"data": resume("Version one summary for a retail store manager.")})
        rid, rev = j["id"], j["revision"]
        t.check("a new resume has no earlier versions", c.api("GET", f"resumes/{rid}/versions")[1]["versions"] == [])
        s, j = c.api("POST", "resumes", {"id": rid, "revision": rev, "data": resume("Version two summary for a retail store manager.")}); rev = j["revision"]
        s, j = c.api("POST", "resumes", {"id": rid, "revision": rev, "data": resume("Version three summary for a retail store manager.")}); rev = j["revision"]
        vs = c.api("GET", f"resumes/{rid}/versions")[1]["versions"]
        t.check("each save keeps the content before it", len(vs) == 2 and all(v["kind"] == "edit" for v in vs), vs)
        old = c.api("GET", f"resumes/{rid}/versions/{vs[-1]['id']}")[1]["version"]
        t.check("an earlier version can be read in full", old["data"]["summary"].startswith("Version one"), old.get("data", {}).get("summary"))
        t.check("a conflicting save keeps no stray version", c.api("POST", "resumes", {"id": rid, "revision": 1, "data": resume("Stale save from another tab, should be refused.")})[0] == 409 and len(c.api("GET", f"resumes/{rid}/versions")[1]["versions"]) == 2)

        # Application freezes the copy that was sent.
        s, j = c.api("POST", "applications", {"company": "Big Bazaar", "role": "Store Manager", "stage": "saved", "resumeId": rid})
        aid = j["id"]
        t.check("a saved job does not freeze anything yet", db.q("select resume_version_id from applications where id=?", aid)[0][0] is None)
        app_rev = db.q("select revision from applications where id=?", aid)[0][0]
        c.api("POST", "applications", {"id": aid, "revision": app_rev, "company": "Big Bazaar", "role": "Store Manager", "stage": "applied", "resumeId": rid})
        fv = db.q("select resume_version_id from applications where id=?", aid)[0][0]
        frozen = db.q("select kind, content_json from resume_versions where id=?", fv)
        t.check("marking it applied freezes the resume as sent", frozen and frozen[0][0] == "application" and "Version three" in frozen[0][1], frozen)
        c.api("POST", "resumes", {"id": rid, "revision": rev, "data": resume("Version four after applying, rewritten.")}); rev += 1
        t.check("later edits never change the frozen copy", "Version three" in db.q("select content_json from resume_versions where id=?", fv)[0][0])
        vs = c.api("GET", f"resumes/{rid}/versions")[1]["versions"]
        t.check("the history shows which application used a version", any(v["kind"] == "application" and v["usedFor"] == "Store Manager · Big Bazaar" for v in vs), vs)

        # Restore and undo.
        v1 = [v for v in vs if v["kind"] == "edit"][-1]["id"]
        s, j = c.api("POST", f"resumes/{rid}/versions/{v1}/restore", {})
        cur = c.api("GET", f"resumes/{rid}")[1]["resume"]
        t.check("an earlier version can be put back", s == 200 and cur["data"]["summary"].startswith("Version one") and cur["revision"] == rev + 1, (s, cur.get("revision")))
        vs = c.api("GET", f"resumes/{rid}/versions")[1]["versions"]
        t.check("the content replaced by a restore is kept, so it can be undone", vs[0]["kind"] == "restore" and "Version four" in json.dumps(c.api("GET", f"resumes/{rid}/versions/{vs[0]['id']}")[1]["version"]["data"]))

        # Privacy.
        o = Client(inst.base); o.register("rv-b@test.local"); o.page("/workspace.html")
        t.check("another customer cannot list, read or restore these versions", o.api("GET", f"resumes/{rid}/versions")[0] == 404 and o.api("GET", f"resumes/{rid}/versions/{v1}")[0] == 404 and o.api("POST", f"resumes/{rid}/versions/{v1}/restore", {})[0] == 404)

        # Only the last 30 edits are kept, application copies always.
        r = rev + 1
        for i in range(33):
            s, j = c.api("POST", "resumes", {"id": rid, "revision": r, "data": resume(f"Bulk edit number {i} for the history limit.")}); r = j["revision"]
        kinds = db.q("select kind, count(*) from resume_versions where resume_id=? group by kind", rid)
        k = dict(kinds)
        t.check("only the last 30 edit versions are kept; the application copy stays", k.get("edit") == 30 and k.get("application") == 1, k)

        # Letters remember what they were made from.
        s, j = c.api("POST", "letters", {"data": letter(), "source": {"resumeId": rid, "applicationId": aid, "scanId": "00000000-0000-0000-0000-000000000000"}})
        row = db.q("select source_resume_id, source_application_id, source_scan_id from cover_letter_drafts where id=?", j.get("id"))
        t.check("a cover letter remembers its resume and application, and ignores ids that are not the customer's", s == 200 and row and row[0][0] == rid and row[0][1] == aid and row[0][2] is None, (s, row))
        s, j2 = o.api("POST", "letters", {"data": letter(), "source": {"resumeId": rid}})
        t.check("another customer's resume is never linked", db.q("select source_resume_id from cover_letter_drafts where id=?", j2.get("id"))[0][0] is None)

        # Account deletion removes the versions.
        uid = db.q("select id from users where email='rv-a@test.local'")[0][0]
        inst.artisan("tinker", f"--execute=App\\Services\\AccountEraser::erase({uid});")
        t.check("deleting the account removes the resume history", db.q("select count(*) from resume_versions where owner_id=?", uid)[0][0] == 0)
        js = open(os.path.join(ctx.public, "primary-resume.js"), encoding="utf8").read()
        ws = open(os.path.join(ctx.app, "resources", "frontend", "workspace.html"), encoding="utf8").read()
        t.check("each resume card has a History button and the workspace loads the history script", "data-history=" in js and "/resume-history.js" in ws)
    finally:
        inst.stop()
