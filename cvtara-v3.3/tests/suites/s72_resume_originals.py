"""3.61.0 original uploaded resume file (V7 §11 R1; owner OK 12 Nov 2026): a private copy of the file a customer
uploaded to build a resume is kept for 90 days, only that customer can download it, they can delete it at any time,
it is erased with the account and when the 90 days end; only real PDF, Word and text files up to 5 MB are kept."""
import base64, glob, json, os
NAME = "Original uploaded resume file kept 90 days"

TEXT = ("Asha Rao\nasha@example.com | +91 98765 43210 | Pune\n\nSUMMARY\nRecruiter with 4 years of experience hiring for retail stores.\n\n"
        "EXPERIENCE\nRecruiter, Acme Retail, Pune\nJan 2021 - Present\n- Hired staff for 14 stores\n\nEDUCATION\nBBA, Pune University, 2020\n\nSKILLS\nSourcing, Screening, Excel")
PDF = b"%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    inst = Instance(ctx.app, ctx.public, preview=True).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        c = Client(inst.base); c.register("orig-a@test.local"); c.page("/workspace.html")
        s, j = c.api("POST", "resumes/import", {"text": TEXT, "fileName": "Asha resume.pdf"})
        rid = j.get("id")
        t.check("a resume is built from the upload as before", s == 200 and rid, (s, str(j)[:200]))
        s, j = c.api("POST", f"resumes/{rid}/original", {"file": base64.b64encode(PDF).decode(), "fileName": "Asha resume.pdf"})
        o = j.get("original", {})
        t.check("the original file is kept for 90 days", s == 200 and o.get("kind") == "pdf" and o.get("fileName") == "Asha resume.pdf" and o.get("size") == len(PDF), (s, j))
        row = db.q("select path, sha256, julianday(expires_at) - julianday(created_at) from resume_originals where id=?", o.get("id"))
        t.check("stored privately with its checksum, deleted after 90 days", row and row[0][0].startswith("originals/") and len(row[0][1]) == 64 and 89.9 < row[0][2] < 90.1, row)
        path = os.path.join(inst.app, "storage", "app", "private", row[0][0]) if row else ""
        if row and not os.path.exists(path):
            path = os.path.join(inst.app, "storage", "app", row[0][0])
        t.check("the file is on disk outside the public folder", os.path.exists(path) and "public_html" not in path, path)

        s, j = c.api("GET", "resumes/originals")
        t.check("the customer sees it in their data list", s == 200 and len(j["originals"]) == 1 and j["days"] == 90 and j["originals"][0]["resumeTitle"] is not None, j)
        st, h, b = c.raw("GET", f"/api/resumes/originals/{o['id']}")
        t.check("and can download the exact file", st == 200 and b == PDF and "attachment" in h.get("Content-Disposition", "") and h.get("Content-Type", "").startswith("application/pdf"), (st, h.get("Content-Type"), b[:30]))

        other = Client(inst.base); other.register("orig-b@test.local"); other.page("/workspace.html")
        t.check("nobody else can download it", other.raw("GET", f"/api/resumes/originals/{o['id']}")[0] == 404 and other.api("POST", f"resumes/originals/{o['id']}/delete", {})[0] == 404)
        t.check("nobody can attach a file to someone else's resume", other.api("POST", f"resumes/{rid}/original", {"file": base64.b64encode(PDF).decode(), "fileName": "x.pdf"})[0] == 404)
        t.check("signed-out visitors cannot download it", Client(inst.base).raw("GET", f"/api/resumes/originals/{o['id']}")[0] in (401, 302, 403, 419))

        s, _ = c.api("POST", f"resumes/{rid}/original", {"file": base64.b64encode(b"MZ\x90\x00\x03\x00\x00\x00binary").decode(), "fileName": "resume.pdf"})
        t.check("a file that is not really a PDF, Word or text file is refused", s == 422, s)
        s, _ = c.api("POST", f"resumes/{rid}/original", {"file": base64.b64encode(b"%PDF-" + b"0" * (5 * 1024 * 1024 + 10)).decode(), "fileName": "big.pdf"})
        t.check("a file over 5 MB is refused", s == 422, s)
        s, j = c.api("POST", f"resumes/{rid}/original", {"file": base64.b64encode("Asha Rao — Recruiter, Pune ₹".encode()).decode(), "fileName": "../../etc/passwd<script>.txt"})
        t.check("a new upload for the same resume replaces the old copy, with a safe file name", s == 200 and db.q("select count(*) from resume_originals where resume_id=?", rid)[0][0] == 1
                and "/" not in j["original"]["fileName"] and "<" not in j["original"]["fileName"] and j["original"]["fileName"].endswith(".txt"), j)
        t.check("the replaced file is removed from disk", not os.path.exists(path))
        oid = j["original"]["id"]

        s, ex = c.api("GET", "account/export")
        t.check("the data download lists uploaded files", s == 200 and len(ex["export"].get("uploadedFiles", [])) == 1, str(ex)[:200])

        s, _ = c.api("POST", f"resumes/originals/{oid}/delete", {})
        t.check("the customer can delete it at any time", s == 200 and db.q("select count(*) from resume_originals where id=?", oid)[0][0] == 0)
        t.check("the resume itself stays", db.q("select count(*) from resume_drafts where id=?", rid)[0][0] == 1)

        # 90 days later the cron erases it.
        s, j = c.api("POST", f"resumes/{rid}/original", {"file": base64.b64encode(PDF).decode(), "fileName": "again.pdf"})
        p2 = db.q("select path from resume_originals where id=?", j["original"]["id"])[0][0]
        db.q("update resume_originals set expires_at=datetime('now', '-1 minute')")
        t.check("an expired file is no longer offered", c.api("GET", "resumes/originals")[1]["originals"] == [] and c.raw("GET", f"/api/resumes/originals/{j['original']['id']}")[0] == 404)
        inst.artisan("tinker", "--execute=echo App\\Services\\ResumeOriginals::purge();")
        t.check("and the daily run erases it", db.q("select count(*) from resume_originals")[0][0] == 0 and not glob.glob(os.path.join(inst.app, "storage", "app", "**", os.path.basename(p2)), recursive=True))

        # Account deletion erases every file.
        s, j = c.api("POST", f"resumes/{rid}/original", {"file": base64.b64encode(PDF).decode(), "fileName": "last.pdf"})
        p3 = db.q("select path from resume_originals where id=?", j["original"]["id"])[0][0]
        uid = db.q("select id from users where email='orig-a@test.local'")[0][0]
        inst.artisan("tinker", f"--execute=App\\Services\\AccountEraser::erase({uid});")
        t.check("deleting the account erases the files", db.q("select count(*) from resume_originals")[0][0] == 0 and not glob.glob(os.path.join(inst.app, "storage", "app", "**", os.path.basename(p3)), recursive=True))

        js = open(ctx.public + "/primary-resume.js", encoding="utf8").read(); wj = open(ctx.public + "/workspace-ui.js", encoding="utf8").read()
        t.check("the upload dialog says a copy is kept for 90 days and where to delete it", "keep a copy of your original file for 90 days" in js and "Your uploaded files" in wj and "Delete now" in wj)
        d = json.load(open(ctx.app + "/resources/data/defaults.json", encoding="utf8"))
        t.check("the privacy policy says so", "private copy of that original file for 90 days" in d["legal"]["privacy"])
    finally:
        inst.stop()
