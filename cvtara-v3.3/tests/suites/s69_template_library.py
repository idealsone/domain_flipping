"""3.59.0 template library (V7 §15, V5.0 §5-§7): licence/source/author/version for every design, the text-reading test of
exported PDF and Word files (special characters included), the ATS label only for tested single-column text-first
designs, ZIP package import with validation, drafts, approval, versions and rollback, and coverage per role group."""
import hashlib, io, json, uuid, zipfile, urllib.request
NAME = "Template library: metadata, reading test, package import, coverage"


def tpl(tid, kind="resume", base="ats-software-engineer", accent="#225588", version=1, **extra):
    t = {"template_id": tid, "version": version, "document_type": kind, "name": tid.replace("_", " ").title(), "category": "Retail & Sales",
         "industry_ids": ["retail"], "job_family_ids": ["sales"], "occupation_ids": ["retail-store"], "experience_band": "fresher", "layout_family": base,
         "ATS_mode": True, "section_order": ["summary", "experience", "education", "skills"], "field_bindings": {"name": "personal.name"}, "conditional_sections": [],
         "style_tokens": {"accent": accent, "font": "Georgia"}, "export_formats": ["pdf", "docx"], "preview_asset": "assets/previews/x.png", "status": "ready", "changelog": "First version",
         "licence": "Commissioned work, all rights to CVTara", "source": "Test studio", "author": "Test Designer"}
    t.update(extra)
    return t


def package(templates, extra_files=None, bad_checksum=False, count=None):
    files = {}
    for t in templates:
        folder = "resume" if t["document_type"] == "resume" else "cover-letter"
        files[f"templates/{folder}/{t['template_id']}.template.json"] = json.dumps(t).encode()
    tpaths = sorted(files)
    checksum = hashlib.sha256(b"".join(files[p] for p in tpaths)).hexdigest()
    manifest = {"package_schema_version": "1.0", "package_name": "Test pack", "vendor": "Test studio", "created_at": "2026-11-12", "locale": "en-IN", "taxonomy_version": "2026-11",
                "template_count": len(templates) if count is None else count, "checksum": "0" * 64 if bad_checksum else checksum, "compatibility": {"min": "3.59.0"}}
    files["manifest.json"] = json.dumps(manifest).encode()
    files.update(extra_files or {})
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as z:
        for p, b in files.items():
            z.writestr(p, b)
    return buf.getvalue()


def upload(c, data, name="pack.zip"):
    c.token(); b = "----cvt" + uuid.uuid4().hex
    body = (f"--{b}\r\nContent-Disposition: form-data; name=\"package\"; filename=\"{name}\"\r\nContent-Type: application/zip\r\n\r\n").encode() + data + f"\r\n--{b}--\r\n".encode()
    req = urllib.request.Request(c.base + "/api/admin/template-library/import", data=body, method="POST",
                                 headers={"Content-Type": "multipart/form-data; boundary=" + b, "Accept": "application/json", "X-CSRF-TOKEN": c.csrf or "", "X-CVTara-Request": "1"})
    try: r = c.op.open(req, timeout=120)
    except urllib.error.HTTPError as e: r = e
    raw = r.read()
    try: return r.status, json.loads(raw)
    except ValueError: return r.status, {"_raw": raw[:300]}


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    inst = Instance(ctx.app, ctx.public, preview=True).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        cat = json.load(open(ctx.app + "/resources/data/catalog.json"))
        n = db.q("select count(*) from template_library where origin='builtin'")[0][0]
        t.check("every built-in resume and cover-letter design is listed", n == len(cat["templates"]) + len(cat["coverLetters"]), (n, len(cat["templates"]), len(cat["coverLetters"])))
        t.check("each with licence, source, author, version, preview and export formats", db.q("select count(*) from template_library where licence='' or source='' or author='' or version<1 or preview='' or compat_json is null")[0][0] == 0)
        t.check("two-column designs are marked", db.q("select single_column from template_library where slug='suite-prism'")[0][0] == 0)
        ats = next(x["slug"] for x in cat["templates"] if x.get("ats") and "sidebar" not in x["slug"] and not x["slug"].startswith("suite-"))
        letter = cat["coverLetters"][0]["slug"]

        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        s, j = o.api("POST", f"admin/template-library/{ats}/test", {})
        r = j.get("test", {})
        t.check("the reading test makes a PDF and a Word file and reads every word back", s == 200 and r.get("passed") and r["checks"]["pdf"]["ok"] and r["checks"]["docx"]["ok"], (s, str(j)[:400]))
        t.check("special characters survive in the PDF: ? – [ ] ( ) & \" ' ₹ →", all(x in r.get("sample", "") for x in ["\"why?\" – and finds answers [fast] & clearly", "₹4,50,000", "6 hours → 3.5 hours", "D'Souza-Iyer", "(Power BI)"]), r.get("sample", "")[:400])
        t.check("sections come back in order and dates sit next to the employer", r["checks"]["pdf"]["sectionOrder"] and r["checks"]["pdf"]["datesWithEmployer"])
        t.check("the test uses demo data, clearly labelled", r.get("demoData") is True and "demo data" in r.get("sample", ""))
        s, j = o.api("POST", "admin/template-library/suite-prism/test", {})
        t.check("a two-column design can pass the reading test but never shows the ATS label", s == 200 and j["template"]["atsLabel"] is False and j["template"]["singleColumn"] is False, j.get("template"))
        s, j = o.api("POST", f"admin/template-library/{letter}/test", {})
        t.check("cover letters are tested too", s == 200 and j["test"]["passed"], (s, str(j)[:300]))

        cu = Client(inst.base); cu.register("tl-a@test.local"); cu.page("/workspace.html")
        pc = cu.api("GET", "public-content")[1]["content"]["templateLib"]
        t.check("the public site gets the ATS label only for tested designs", pc["ats"][ats] is True and pc["ats"]["suite-prism"] is False
                and sum(1 for v in pc["ats"].values() if v) == 1, sum(1 for v in pc["ats"].values() if v))
        t.check("customers cannot open the template console", cu.api("GET", "admin/template-library")[0] in (403, 404))

        s, j = o.api("GET", "admin/template-library?kind=resume&test=passed")
        t.check("the console lists designs with filters", s == 200 and j["total"] == 2 and {x["slug"] for x in j["rows"]} == {ats, "suite-prism"}, (s, str(j)[:300]))
        st, h, b = o.raw("GET", "/api/admin/template-library?export=csv")
        t.check("and downloads them with licence, author and test result", st == 200 and b"Licence" in b and b"Reading test passed" in b and b"Shows ATS label" in b, b[:200])
        s, j = o.api("GET", "admin/template-library/coverage")
        t.check("coverage shows each role group against five resumes and five letters", s == 200 and j["target"] == 5 and j["rows"] and all("resumeGap" in x and "letterGap" in x for x in j["rows"]), str(j)[:300])
        st, h, b = o.raw("GET", f"/api/admin/template-library/{ats}/preview")
        t.check("a preview with demo data", st == 200 and b"demo data" in b)

        # A bad package: script file, unsafe layout, bad checksum.
        bad = package([tpl("retail_fresher_01")], {"layouts/x.html": b"<div onclick=\"x()\">hi</div>", "evil.js": b"alert(1)", "styles/t.css": b"@import url(https://x.test/a.css);"}, bad_checksum=True)
        s, j = upload(o, bad)
        e = " ".join(j.get("report", {}).get("errors", []))
        t.check("a package with scripts, event handlers, remote CSS or a wrong checksum is refused", s == 200 and j["report"]["ok"] is False and "evil.js" in e and "layouts/x.html" in e and "styles/t.css" in e and "checksum" in e, (s, j))
        t.check("nothing from a refused package is imported", db.q("select count(*) from template_library where origin='package'")[0][0] == 0)
        s, j = upload(o, package([tpl("xx1", base="no-such-design", style_tokens={"accent": "red", "font": "Comic Sans"})]))
        e = " ".join(j["report"]["errors"])
        t.check("layout_family must name a CVTara design; colour and font are checked", "layout_family" in e and "accent" in e and "font" in e, e)
        s, j = upload(o, package([tpl("xx2", licence="", source="")]))
        t.check("licence and source are required", "licence is missing" in " ".join(j["report"]["errors"]), j["report"]["errors"])

        good = package([tpl("retail_fresher_01"), tpl("retail_letter_01", kind="cover_letter", base=letter, section_order=[])])
        s, j = upload(o, good)
        rep = j.get("report", {})
        t.check("a valid package imports its designs as drafts and tests them", s == 200 and rep.get("ok") and len(rep["templates"]) == 2 and all(x["result"] == "new" and x["testPassed"] for x in rep["templates"]), (s, str(j)[:400]))
        slug = "pkg-retail_fresher_01"
        row = db.q("select status, licence, author, base, version, category from template_library where slug=?", slug)[0]
        t.check("the draft keeps licence, author, base design and version", row[0] == "draft" and row[1].startswith("Commissioned") and row[2] == "Test Designer" and row[3] == "ats-software-engineer" and row[4] == 1, row)
        pc = cu.api("GET", "public-content")[1]["content"]["templateLib"]
        t.check("a draft is not on the public site", pc["imported"]["templates"] == [])
        d = {"version": "1", "title": "Mine", "template": slug, "accent": "#1557d2", "font": "Arial", "personal": {"name": "Ann Rao"}, "summary": "x", "order": ["summary"], "experience": [], "education": [], "projects": [], "certifications": []}
        t.check("customers cannot save with a draft design", cu.api("POST", "resumes", {"data": d})[0] == 422)
        s, j = o.api("POST", f"admin/template-library/{slug}/decide", {"action": "approve"})
        t.check("approving publishes it", s == 200 and j["template"]["status"] == "published", (s, j))
        pc = cu.api("GET", "public-content")[1]["content"]["templateLib"]
        t.check("the published design reaches the public site with its colour, font and ATS label", pc["imported"]["templates"][0]["slug"] == slug and pc["imported"]["templates"][0]["accent"] == "#225588" and pc["imported"]["templates"][0]["font"] == "Georgia" and pc["ats"][slug] is True, pc["imported"])
        s, j = cu.api("POST", "resumes", {"data": d})
        t.check("customers can save a resume with it", s == 200 and j.get("id"), (s, j))
        st, h, b = cu.raw("POST", f"/export/resume/{j.get('id')}/pdf", {"clean": False}, {"Accept": "application/json", "X-CSRF-TOKEN": cu.csrf or ""})
        t.check("and it exports to PDF with the base design", st == 200 and b[:4] == b"%PDF", (st, b[:200]))

        s, j = upload(o, good)
        t.check("uploading the same package again finds duplicates", all(x["result"] == "duplicate" for x in j["report"]["templates"]), j["report"])
        s, j = upload(o, package([tpl("retail_fresher_01", accent="#aa3300", version=2)]))
        t.check("a changed design becomes a new version, waiting for approval", j["report"]["templates"][0]["result"] == "new_version" and db.q("select version, status from template_library where slug=?", slug)[0] == (2, "draft"), j["report"])
        c2 = Client(inst.base); c2.register("tl-b@test.local"); c2.page("/workspace.html")
        t.check("documents with the design still save while the new version waits", c2.api("POST", "resumes", {"data": d})[0] == 200)
        t.check("both versions are kept", db.q("select count(*) from template_versions where slug=?", slug)[0][0] == 2)
        s, j = o.api("POST", f"admin/template-library/{slug}/decide", {"action": "rollback", "version": 1})
        t.check("rolling back restores version 1's content as a new version", s == 200 and j["template"]["version"] == 3 and j["template"]["definition"]["style_tokens"]["accent"] == "#225588" and j["template"]["status"] == "draft", (s, str(j)[:300]))
        s, j = o.api("POST", f"admin/template-library/{slug}/decide", {"action": "reject"})
        t.check("a draft can be rejected", s == 200 and j["template"]["status"] == "rejected")
        s, j = o.api("POST", "admin/template-library/pkg-retail_letter_01/decide", {"action": "approve"})
        s, j = o.api("POST", "admin/template-library/pkg-retail_letter_01/decide", {"action": "retire"})
        t.check("a live design can be retired", s == 200 and j["template"]["status"] == "retired")
        s, j = o.api("GET", "admin/template-library/packages")
        t.check("every uploaded package is listed with its result", s == 200 and j["total"] >= 6 and any(p["status"] == "rejected" for p in j["rows"]), (s, str(j)[:200]))
        t.check("imports and decisions are in the activity log", db.q("select count(*) from activity_events where event like 'template_%'")[0][0] >= 6)

        out = inst.artisan("tinker", "--execute=echo App\\Services\\TemplateLibrary::testDue(3);")
        t.check("the scheduler tests untested designs a few at a time", "3" in out.stdout and db.q("select count(*) from template_library where tested_at is not null")[0][0] >= 8, out.stdout + out.stderr)
        js = open(ctx.app + "/resources/console/templib.js").read(); op = open(ctx.public + "/owner-public.js").read()
        t.check("the console has a Template quality page and the site hides untested ATS labels", "K.register('templib'" in js and "TL.ats[t.slug]!==true" in op)
    finally:
        inst.stop()
