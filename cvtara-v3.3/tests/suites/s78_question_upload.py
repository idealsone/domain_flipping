"""3.66.0 interview question bank bulk upload (owner asked 12 Nov 2026 for bulk upload or approval): a CSV or Excel sheet
becomes draft or approved questions, bad rows are reported by row number, wording already in the bank is skipped,
"Approve all drafts" covers every role, and "Load any new starter questions" can be run again safely."""
import base64, io, zipfile
NAME = "Interview question bulk upload and approve all"


def xlsx(rows):
    """A minimal .xlsx with inline strings, enough for the sheet reader."""
    def cell(ref, v): return f'<c r="{ref}" t="inlineStr"><is><t>{v}</t></is></c>'
    body = "".join(f'<row r="{i+1}">' + "".join(cell(chr(65 + j) + str(i + 1), v) for j, v in enumerate(r)) + "</row>" for i, r in enumerate(rows))
    b = io.BytesIO(); z = zipfile.ZipFile(b, "w")
    z.writestr("[Content_Types].xml", '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>')
    z.writestr("_rels/.rels", '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>')
    z.writestr("xl/workbook.xml", '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>')
    z.writestr("xl/_rels/workbook.xml.rels", '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>')
    z.writestr("xl/worksheets/sheet1.xml", f'<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>{body}</sheetData></worksheet>')
    z.close(); return base64.b64encode(b.getvalue()).decode()


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    inst = Instance(ctx.app, ctx.public, preview=True).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        s, j = o.api("GET", "admin/interviews")
        total = sum(j["counts"].values()); drafts = j["counts"].get("draft", 0)
        t.check("the starter bank is loaded as drafts", s == 200 and drafts == total and total >= 100, j["counts"])
        s, j = o.api("POST", "admin/interviews/seed", {})
        t.check("loading the starter questions again adds nothing twice", s == 200 and j["added"] == 0, j.get("added"))
        db.q("delete from interview_question_versions where question_id in (select id from interview_questions where code='GEN-001')")
        db.q("delete from interview_questions where code='GEN-001'")
        s, j = o.api("POST", "admin/interviews/seed", {})
        t.check("a starter question missing from the site is loaded by the button", s == 200 and j["added"] == 1, j.get("added"))

        s, h, b = o.raw("GET", "/api/admin/interviews/template")
        t.check("the blank sheet downloads as CSV with the column names", s == 200 and b.startswith(b'"Role","Question","Guidance"'), b[:80])

        csv = ('Role,Question,Guidance,Example,Skill,Difficulty,From,To\r\n'
               'Customer Support Executive,"How do you handle a customer who calls back for the third time?","Own the case, check the history first, say sorry, fix the root cause and give a direct number or time to follow up.",,customer,2,fresher,5-8\r\n'
               'general,"What did you learn from your last job that you will bring here?","Pick one skill, give a short example of using it, and link it to this role.",,learning,1,,\r\n'
               'Astronaut,"How do you plan a space walk safely?","Talk about checklists and buddy checks.",,role,2,fresher,12+\r\n'
               'general,Too short,"Guidance that is long enough to pass.",,role,2,fresher,12+\r\n'
               'general,"How do you use AI tools at work every day?","Talk about the tools and how you check their output.",,role,2,fresher,12+\r\n'
               'general,"Where do you see yourself in five years?","Show a realistic plan.",,career,1,12+,fresher\r\n'
               'general,"Tell me about yourself.","Keep it to one minute.",,introduction,1,fresher,12+\r\n')
        s, j = o.api("POST", "admin/interviews/import", {"text": csv})
        r = j.get("import", {})
        t.check("a CSV upload adds the good rows as drafts", s == 200 and r["added"] == 2 and r["approved"] == 0 and r["received"] == 7, r)
        t.check("a question already in the bank is skipped, not added twice", r["duplicate"] == 1, r)
        errs = " ".join(r["errors"])
        t.check("an unknown role is reported with its row number", "Row 4: role \"Astronaut\"" in errs, errs)
        t.check("a too-short question is reported", "Row 5: question must be" in errs)
        t.check("a question that mentions AI is refused", "Row 6: customer-facing text never mentions" in errs)
        t.check("experience From above To is refused", "Row 7: experience must be" in errs)
        row = db.q("select q.occupation, q.min_band, q.max_band, q.status, q.competency from interview_questions q join interview_question_versions v on v.id=q.current_version_id where v.text like 'How do you handle a customer who calls back%'")
        t.check("the role name is matched to the role and the experience range is kept", row and row[0] == ("customer-support", "fresher", "5-8", "draft", "customer"), row)
        row = db.q("select q.occupation, q.min_band, q.max_band from interview_questions q join interview_question_versions v on v.id=q.current_version_id where v.text like 'What did you learn from your last job%'")
        t.check("general means every role and blank experience means fresher to 12+", row and row[0] == (None, "fresher", "12+"), row)
        s, j = o.api("POST", "admin/interviews/import", {"text": csv})
        t.check("uploading the same file again adds nothing", s == 200 and j["import"]["added"] == 0 and j["import"]["duplicate"] == 3, j.get("import"))

        sheet = xlsx([["Role", "Question", "Guidance", "Skill"], ["Sales Executive", "How do you follow up with a lead who has gone quiet?", "Space the follow-ups, add something useful each time, and know when to stop.", "role"]])
        s, j = o.api("POST", "admin/interviews/import", {"sheet": sheet, "approve": True})
        r = j.get("import", {})
        t.check("an Excel upload can approve its questions straight away", s == 200 and r["added"] == 1 and r["approved"] == 1, r)
        row = db.q("select q.status, v.approved_at is not null from interview_questions q join interview_question_versions v on v.id=q.current_version_id where v.text like 'How do you follow up with a lead%'")
        t.check("the approved question records who approved it and when", row and row[0][0] == "approved" and row[0][1], row)
        s, j = o.api("POST", "admin/interviews/import", {"sheet": base64.b64encode(b"not a sheet").decode()})
        t.check("a file that is not Excel is refused with a clear message", s == 422 and "could not be read" in str(j), (s, j))
        s, j = o.api("POST", "admin/interviews/import", {"text": "Role,Skill\r\ngeneral,role\r\n"})
        t.check("a sheet without Question and Guidance columns explains what is needed", s == 200 and j["import"]["added"] == 0 and "Question" in j["import"]["errors"][0], j.get("import"))

        s, j = o.api("POST", "admin/interviews/approve", {"occupation": "*"})
        t.check("Approve all approves every draft for every role", s == 200 and j["counts"].get("draft", 0) == 0 and j["approved"] >= 100, (j.get("approved"), j.get("counts")))
        audit = db.q("select count(*) from activity_events where event in ('interview_questions_imported','interview_questions_approved')")
        t.check("uploads and approvals are recorded", audit[0][0] >= 4, audit)

        c = Client(inst.base); c.register("qb-cust@test.local"); c.page("/workspace.html")
        s, j = c.api("POST", "admin/interviews/import", {"text": csv})
        t.check("a customer cannot upload questions", s in (403, 404), s)
        s, h, b = c.raw("GET", "/api/admin/interviews/template")
        t.check("a customer cannot download the blank sheet", s in (401, 403, 404), s)
        js = open(inst.app + "/resources/console/interviews.js").read()
        t.check("the console has the upload, blank sheet and approve-all buttons", all(x in js for x in ("ivUpload", "ivBlank", "ivApproveEvery", "Load any new starter questions")))
    finally:
        inst.stop()
