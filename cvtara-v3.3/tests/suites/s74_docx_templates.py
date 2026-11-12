"""3.61.0 template import from a Word file (V5.0 §6; owner OK 12 Nov 2026): the owner uploads one .docx resume or cover
letter design with its licence, source and author; the section headings and their order, main font, heading colour and
one or two columns are read; it becomes a draft built on the closest CVTara design, gets the text-reading test, and goes
live only after approval. Files with macros, without headings or without licence details are refused."""
import io, json, urllib.request, urllib.error, uuid, zipfile
NAME = "Template import from a Word file"
W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'


def para(text, color=None, bold=False):
    rpr = (f'<w:color w:val="{color}"/>' if color else "") + ("<w:b/>" if bold else "")
    return f'<w:p><w:r><w:rPr>{rpr}</w:rPr><w:t xml:space="preserve">{text}</w:t></w:r></w:p>'


def docx(body, font="Garamond", macro=False):
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as z:
        z.writestr("[Content_Types].xml", '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>')
        z.writestr("word/document.xml", f'<?xml version="1.0" encoding="UTF-8"?><w:document {W}><w:body>{body}<w:sectPr/></w:body></w:document>')
        z.writestr("word/styles.xml", f'<?xml version="1.0"?><w:styles {W}><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="{font}" w:hAnsi="{font}"/></w:rPr></w:rPrDefault></w:docDefaults></w:styles>')
        if macro: z.writestr("word/vbaProject.bin", b"\x00evil")
    return buf.getvalue()


def upload(c, data, fields, name="design.docx"):
    c.token(); b = "----cvt" + uuid.uuid4().hex
    body = b"".join(f"--{b}\r\nContent-Disposition: form-data; name=\"{k}\"\r\n\r\n{v}\r\n".encode() for k, v in fields.items())
    body += (f"--{b}\r\nContent-Disposition: form-data; name=\"package\"; filename=\"{name}\"\r\nContent-Type: application/octet-stream\r\n\r\n").encode() + data + f"\r\n--{b}--\r\n".encode()
    req = urllib.request.Request(c.base + "/api/admin/template-library/import", data=body, method="POST",
                                 headers={"Content-Type": "multipart/form-data; boundary=" + b, "Accept": "application/json", "X-CSRF-TOKEN": c.csrf or "", "X-CVTara-Request": "1"})
    try: r = c.op.open(req, timeout=120)
    except urllib.error.HTTPError as e: r = e
    raw = r.read()
    try: return r.status, json.loads(raw)
    except ValueError: return r.status, {"_raw": raw[:300]}


META = {"name": "Navy Classic", "kind": "resume", "category": "Sales", "licence": "Bought from a design shop, commercial use", "source": "Design shop order 1234", "author": "Studio A"}
ONE = "".join([para("Asha Rao", bold=True), para("asha@example.com | Pune"), para("PROFESSIONAL SUMMARY", "1F4E79", True), para("Sales lead with five years in retail and a record of growing store revenue."),
               para("Work Experience", "1F4E79", True), para("Store Manager, Acme Retail, 2021 - Present"), para("Education:", "1F4E79", True), para("BBA, Pune University"),
               para("Skills", "1F4E79", True), para("Negotiation, CRM, Excel"), para("Certifications", "000000", True), para("Retail Management Certificate")])
TWO = ('<w:tbl><w:tr><w:tc>' + para("Skills", "C00000", True) + para("Negotiation and key account handling across regions") + '</w:tc><w:tc>' + para("Experience", "C00000", True)
       + para("Area Sales Manager at Acme Retail for four years, west zone") + '</w:tc></w:tr></w:tbl>' + para("Education", "C00000", True) + para("MBA"))


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    inst = Instance(ctx.app, ctx.public, preview=True).start()
    try:
        db = Db(inst.db); Client.verify_db = inst.db
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        s, j = upload(o, docx(ONE), META, "Navy Classic.docx")
        r = j.get("report", {})
        t.check("a Word design is imported as a draft", s == 200 and r.get("ok") and r["templates"][0]["result"] == "new", (s, str(j)[:400]))
        rd = r.get("read", {})
        t.check("its headings are read in order", rd.get("sections") == ["summary", "experience", "education", "skills", "certifications"], rd)
        t.check("its heading colour is read, black is ignored", rd.get("accent") == "#1f4e79", rd)
        t.check("a font CVTara does not have is swapped for the closest, with a note", rd.get("font") == "Georgia" and any("Garamond" in w for w in r.get("warnings", [])), (rd, r.get("warnings")))
        t.check("a one-column file builds on a one-column CVTara design", rd.get("twoColumn") is False and rd.get("base") and "vector" not in rd["base"], rd)
        slug = r["templates"][0]["slug"]
        row = db.q("select status, origin, licence, source, author, category, ats_layout, single_column, definition_json from template_library where slug=?", slug)
        d = json.loads(row[0][8]) if row else {}
        t.check("it is a draft with its licence, source, author and role group", row and row[0][:6] == ("draft", "package", META["licence"], META["source"], META["author"], "Sales"), row[:1])
        t.check("its section order and style are kept on the design", d.get("section_order", [])[:5] == ["summary", "experience", "education", "skills", "certifications"] and d.get("style_tokens") == {"font": "Georgia", "accent": "#1f4e79"}, d)
        t.check("the sample text in the file is not stored", "Acme Retail" not in json.dumps(d) and "Asha" not in (db.q("select manifest_json from template_packages order by id desc limit 1")[0][0] or ""))
        t.check("it took the text-reading test like any design", r["templates"][0].get("testPassed") in (True, False) and db.q("select tested_at is not null from template_library where slug=?", slug)[0][0] == 1)
        cu = Client(inst.base); cu.register("dx-c@test.local")
        pc = cu.api("GET", "public-content")[1]["content"]["templateLib"]
        t.check("customers do not see it before approval", slug not in json.dumps(pc["imported"]["templates"]))
        s, _ = o.api("POST", f"admin/template-library/{slug}/decide", {"action": "approve"})
        pc = cu.api("GET", "public-content")[1]["content"]["templateLib"]
        t.check("after approval it is live", s == 200 and slug in json.dumps(pc["imported"]["templates"]))

        s, j = upload(o, docx(ONE), META, "Navy Classic.docx")
        t.check("the same file again is a duplicate, not a second design", s == 200 and j["report"]["templates"][0]["result"] == "duplicate", str(j)[:300])

        s, j = upload(o, docx(TWO, font="Calibri"), dict(META, name="Red Split"), "Red Split.docx")
        rd = j.get("report", {}).get("read", {})
        t.check("a two-column table layout builds on a two-column design without the ATS label", s == 200 and rd.get("twoColumn") is True and rd.get("font") == "Calibri" and rd.get("accent") == "#c00000"
                and db.q("select ats_layout, single_column from template_library where slug=?", j["report"]["templates"][0]["slug"])[0] == (0, 0), (rd, str(j)[:300]))

        s, j = upload(o, docx(ONE, macro=True), META, "macro.docx")
        t.check("a Word file with macros is refused", s == 200 and not j["report"]["ok"] and any("macros" in e for e in j["report"]["errors"]), str(j)[:300])
        s, j = upload(o, docx(ONE), {k: v for k, v in META.items() if k != "licence"}, "nolicence.docx")
        t.check("licence, source and author are required", s == 200 and not j["report"]["ok"] and any("licence" in e for e in j["report"]["errors"]))
        s, j = upload(o, docx(para("Just a letter of text with no headings at all.")), dict(META, name="Plain"), "plain.docx")
        t.check("a file without section headings is refused with a reason", s == 200 and not j["report"]["ok"] and any("headings" in e for e in j["report"]["errors"]))
        s, j = upload(o, b"not a zip", META, "fake.docx")
        t.check("a file that is not really a Word file is refused", s == 422)
        s, j = upload(o, docx(para("Dear Hiring Manager,") + para("I am writing to apply.")), dict(META, kind="letter", name="Letter One"), "letter.docx")
        t.check("a cover letter design is imported on a cover letter design", s == 200 and j["report"]["ok"] and db.q("select kind, base from template_library where slug=?", j["report"]["templates"][0]["slug"])[0][0] == "letter", str(j)[:300])

        s, _ = upload(cu, docx(ONE), META)
        t.check("customers cannot import designs", s in (403, 404))
        js = open(ctx.app + "/resources/console/templib.js", encoding="utf8").read()
        t.check("the console accepts .docx with licence, source and author", 'accept=".zip,.docx"' in js and 'name="licence"' in js and "Read from the Word file" in js)
    finally:
        inst.stop()
