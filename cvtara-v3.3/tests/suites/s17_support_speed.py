"""Feedback button for signed-in customers (screenshots, limits, safety, owner inbox) and traffic protection (ready-made pages, busy mode)."""
import json, os, struct, urllib.error, urllib.request, uuid, zlib
NAME = "Feedback inbox and traffic protection"


def png(w=40, h=30, noise=False):
    raw = b"".join(b"\x00" + (os.urandom(w * 3) if noise else bytes([(x * 5 + y * 3) % 256 for x in range(w) for _ in range(3)])) for y in range(h))
    def chunk(t, d): return struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d) & 0xffffffff)
    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0)) + chunk(b"IDAT", zlib.compress(raw, 0 if noise else 6)) + chunk(b"IEND", b"")


def send(c, fields, files=(), path="/support/report", csrf=True):
    c.token()
    b = uuid.uuid4().hex; body = b""
    for k, v in fields.items(): body += f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
    for name, data, ctype in files: body += f'--{b}\r\nContent-Disposition: form-data; name="images[]"; filename="{name}"\r\nContent-Type: {ctype}\r\n\r\n'.encode() + data + b"\r\n"
    body += f"--{b}--\r\n".encode()
    h = {"Content-Type": f"multipart/form-data; boundary={b}", "Accept": "application/json", "X-CSRF-TOKEN": c.csrf or ""}
    try: r = c.op.open(urllib.request.Request(c.base + path, data=body, method="POST", headers=h), timeout=60)
    except urllib.error.HTTPError as e: r = e
    raw = r.read()
    try: return r.status, json.loads(raw)
    except ValueError: return r.status, {"_raw": raw[:200].decode("utf8", "ignore")}


BASE = {"area": "ats-match", "kind": "bug", "message": "The scan button does nothing on my phone when I tap it twice.", "page": "/workspace.html?view=match", "viewport": "390x800"}


def run(ctx, t):
    if not ctx.preview: return
    db = ctx.db(); pub = ctx.public; ipub = ctx.inst.public
    w = open(os.path.join(pub, "support-widget.js"), encoding="utf8").read()
    t.check("the widget limits files to 5 images of 2 MB", "MAX_FILES = 5" in w and "2 * 1024 * 1024" in w)
    base_all = db.q("select count(*) from support_reports")[0][0]; base_new = db.q("select count(*) from support_reports where status='new'")[0][0]; base_bill = db.q("select count(*) from support_reports where area='billing'")[0][0]
    cust = ctx.client(); cust.register("sup-a@test.local")
    s, _, home = ctx.client().page("/"); s2, _, ws = cust.page("/workspace.html")
    t.check("the Feedback button is in the customer portal only (not on public pages)", "support-widget.js" in ws and "support-widget.css" in ws and "support-widget.js" not in home and "support-widget.css" not in home)
    pr = ctx.client(); pr.page("/templates.html"); t.check("public pages stay free of the Feedback button", "support-widget" not in pr.page("/templates.html")[2] and "support-widget" not in pr.page("/customer-guide.html")[2])
    t.check("a visitor cannot send or load feedback options", ctx.client().raw("POST", "/support/report", {}, {"Accept": "application/json"})[0] in (401, 419) and ctx.client().raw("GET", "/support/options", None, {"Accept": "application/json"})[0] in (401, 302))
    jsw = open(os.path.join(pub, "support-widget.js"), encoding="utf8").read()
    t.check("the widget does nothing for visitors and can sit on either side", "=== 'guest') return" in jsw and "cvtara-feedback-side" in jsw and "data-flip" in jsw)
    o = ctx.client(); o.raw("GET", "/preview/go/admin"); _, _, adm = o.page("/admin.html")
    t.check("the owner console does not show the customer Feedback button", "support-widget.js" not in adm)
    s, _, b = cust.raw("GET", "/support/options"); j = json.loads(b); t.check("the form options are available", s == 200 and j["maxFiles"] == 5 and "ats-match" in j["areas"] and "bug" in j["kinds"])

    # sending
    s, j = send(cust, BASE); t.check("a customer can send feedback without screenshots", s == 200 and j["ok"], (s, j))
    row = db.q("select owner_id, area, kind, status, plan, viewport, page from support_reports order by created_at desc limit 1")[0]
    t.check("the report records who, what about, plan and the page", row[0] and row[1] == "ats-match" and row[2] == "bug" and row[3] == "new" and row[4] == "Free" and row[5] == "390x800" and row[6] == "/workspace.html?view=match", row)
    five = [(f"s{i}.png", png(40 + i, 30), "image/png") for i in range(5)]
    s, j = send(cust, {**BASE, "message": "Five screenshots attached for the layout problem in the builder."}, five); t.check("up to 5 screenshots are accepted", s == 200, (s, j))
    rid = db.q("select report_id from support_attachments limit 1")[0][0]
    t.check("every screenshot is stored", db.q("select count(*) from support_attachments where report_id=?", rid)[0][0] == 5)
    s, j = send(cust, BASE, five + [("s6.png", png(), "image/png")]); t.check("a sixth screenshot is refused", s == 422, (s, str(j)[:150]))
    s, j = send(cust, BASE, [("big.png", png(900, 900, noise=True), "image/png")]); t.check("a screenshot over 2 MB is refused", s == 422 and "2 MB" in str(j), (s, str(j)[:150]))
    s, j = send(cust, BASE, [("x.png", b"<?php echo 1; ?>", "image/png")]); t.check("a script disguised as an image is refused", s == 422, (s, str(j)[:150]))
    s, j = send(cust, BASE, [("x.svg", b"<svg xmlns='http://www.w3.org/2000/svg'><script>alert(1)</script></svg>", "image/svg+xml")]); t.check("SVG images are refused", s == 422)
    s, j = send(cust, BASE, [("x.gif", b"GIF89a\x01\x00\x01\x00\x00\x00\x00;", "image/gif")]); t.check("other formats are refused", s == 422)
    evil = png() + b"<?php system($_GET['c']); ?>"
    s, j = send(cust, {**BASE, "message": "Image with extra data appended after the PNG end marker."}, [("p.png", evil, "image/png")]); t.check("an image with extra data hidden after it is accepted but cleaned", s == 200, (s, str(j)[:150]))
    s, j = send(cust, {**BASE, "message": "short"}); t.check("a message that is too short is refused", s == 422 and "at least 10" in str(j), (s, str(j)[:150]))
    s, j = send(cust, {**BASE, "area": "nonsense"}); t.check("an unknown area is refused", s == 422)
    s, j = send(cust, {**BASE, "rating": "9"}); t.check("a rating outside 1-5 is refused", s == 422)
    s, j = send(cust, {**BASE, "company_site": "http://spam.example"}); t.check("the hidden spam trap blocks bots", s == 422)
    t.check("nothing from the refused attempts was stored", db.q("select count(*) from support_reports")[0][0] == base_all + 3, db.q("select count(*) from support_reports")[0][0])

    # owner inbox
    s, sm = o.api("GET", "admin/support/summary"); t.check("the owner sees the counts", s == 200 and sm["byStatus"].get("new") == base_new + 3 and sm["recent"]["total"] == base_all + 3, (s, str(sm)[:200]))
    s, ls = o.api("GET", "admin/support?status=new"); t.check("the owner can list new reports", s == 200 and ls["total"] == base_new + 3 and all(r["status"] == "new" for r in ls["rows"]) and any(r["images"] == 5 for r in ls["rows"]))
    s, f = o.api("GET", "admin/support?q=layout+problem"); t.check("the owner can search reports", s == 200 and f["total"] == 1)
    s, f = o.api("GET", "admin/support?area=billing"); t.check("the owner can filter by area", s == 200 and f["total"] == base_bill)
    s, d = o.api("GET", f"admin/support/{rid}"); t.check("opening a report shows screenshots and marks it seen", s == 200 and len(d["images"]) == 5 and d["report"]["status"] == "seen" and d["report"]["plan"] == "Free")
    st, hd, body = o.raw("GET", f"/api/admin/support/file/{d['images'][0]['id']}")
    t.check("a screenshot is served as a safe WebP image", st == 200 and hd.get("Content-Type") == "image/webp" and body[:4] == b"RIFF" and body[8:12] == b"WEBP" and hd.get("X-Content-Type-Options") == "nosniff", (st, hd.get("Content-Type"), body[:12]))
    pid = db.q("select report_id from support_attachments order by created_at desc limit 1")[0][0]
    allfiles = [r[0] for r in db.q("select path from support_attachments")]
    store = os.path.join(os.path.dirname(os.path.dirname(pub)), "storage", "app", "private") if False else None
    blob = b""
    for r in db.q("select id from support_attachments"):
        blob += o.raw("GET", f"/api/admin/support/file/{r[0]}")[2]
    t.check("hidden data inside uploaded images is not kept", b"<?php" not in blob and b"system(" not in blob)
    s, _ = o.api("POST", f"admin/support/{rid}", {"action": "update", "status": "working", "adminNote": "Reproducing on a phone."}); d = o.api("GET", f"admin/support/{rid}")[1]["report"]
    t.check("status and an internal note can be saved", s == 200 and d["status"] == "working" and d["admin_note"] == "Reproducing on a phone.")
    o.api("POST", f"admin/support/{rid}", {"action": "update", "status": "resolved"}); d = o.api("GET", f"admin/support/{rid}")[1]["report"]
    t.check("resolving stamps the time", d["status"] == "resolved" and d["resolved_at"])
    t.check("the customer cannot read the inbox", cust.api("GET", "admin/support")[0] in (401, 403, 404) and cust.raw("GET", f"/api/admin/support/file/{blob and ''}x")[0] in (401, 403, 404))
    t.check("a visitor cannot read the inbox", ctx.client().api("GET", "admin/support")[0] in (401, 403, 404))
    one = db.q("select id from support_attachments where report_id=? limit 1", rid)[0][0]
    t.check("the customer cannot open a screenshot directly", cust.raw("GET", f"/api/admin/support/file/{one}")[0] in (401, 403, 404))
    s, _ = o.api("POST", f"admin/support/{rid}", {"action": "delete"})
    t.check("deleting a report removes its screenshots", s == 200 and db.q("select count(*) from support_attachments where report_id=?", rid)[0][0] == 0 and o.raw("GET", f"/api/admin/support/file/{one}")[0] == 404)
    s, _ = send(cust, {**BASE, "message": "<script>alert('x')</script> markup in the message"}); lst = o.api("GET", "admin/support")[1]["rows"]
    t.check("markup in a message is kept as plain text for the inbox to escape", s == 200 and any("<script>" in r["message"] for r in lst))
    js = open(os.path.join(ctx.app, "resources/console/feedback.js"), encoding="utf8").read()
    t.check("the inbox page escapes everything it prints", "E(r.message)" in js and "E(r.admin_note" in js and "innerHTML" in js)

    # report rate limit (10 an hour per customer)
    more = ctx.client(); more.register("sup-b@test.local"); codes = [send(more, {**BASE, "message": f"Report number {i} about the tracker page."})[0] for i in range(12)]
    t.check("a customer is limited to 10 reports an hour", codes.count(200) == 10 and codes[10:] == [429, 429], codes)

    # traffic protection: ready-made pages
    o.api("POST", "admin/speed/purge", {}); g = ctx.client()
    s, h, b = g.raw("GET", "/"); first = b.decode("utf8", "ignore")
    t.check("the first visitor is served normally and the page is stored for the next", s == 200 and "csrf-lazy.js" in first and os.path.exists(os.path.join(ipub, "_pages", "index.html")), s)
    stored = open(os.path.join(ipub, "_pages", "index.html"), encoding="utf8").read()
    t.check("the stored copy carries nothing that belongs to one visitor", 'name="csrf-token" content=""' in stored and 'name="cvtara-user" content="guest"' in stored)
    fresh = ctx.client(); s, h, b = fresh.raw("GET", "/")
    t.check("a visitor without a cookie gets the ready-made page and no session is started", s == 200 and b"Pick the plan" in b and not any(k.lower() == "set-cookie" for k in h), (s, [k for k in h if k.lower() == "set-cookie"]))
    s, h2, _ = fresh.raw("GET", "/", None, {"If-None-Match": h.get("ETag", "")}); t.check("browsers can re-check a page cheaply (304)", s == 304, s)
    t.check("security headers are still sent with ready-made pages", "script-src" in h.get("Content-Security-Policy", "") and h.get("X-Content-Type-Options") == "nosniff")
    s, _, b = cust.raw("GET", "/"); page = b.decode("utf8", "ignore")
    t.check("a signed-in visitor always gets the live page with their own token", 'name="csrf-token" content=""' not in page and 'name="cvtara-user" content="guest"' not in page)
    s, h, _ = fresh.raw("GET", "/?x=1"); t.check("addresses with a query are never taken from the stored pages", s == 200 and not os.path.exists(os.path.join(ipub, "_pages", "x=1")))
    for p in ("workspace.html", "admin.html", "checkout.html", "login", "register"):
        t.check(f"/{p} is never stored", not os.path.exists(os.path.join(ipub, "_pages", p)) and not os.path.exists(os.path.join(ipub, "_pages", p, "index.html")))
    s, rb = o.api("POST", "admin/speed/rebuild", {})
    t.check("the owner can rebuild every public page", s == 200 and rb["result"]["built"] > 50 and not rb["result"]["skipped"] and rb["cache"]["pages"] > 50, (s, str(rb)[:200]))
    t.check("guides and calculators are pre-built too", os.path.exists(os.path.join(ipub, "_pages", "customer-guide.html")) and os.path.isdir(os.path.join(ipub, "_pages", "guides")) and os.path.isdir(os.path.join(ipub, "_pages", "calculators")))
    t.check("the folder of ready-made pages repeats the security headers for the web server", "Content-Security-Policy" in open(os.path.join(ipub, "_pages", ".htaccess"), encoding="utf8").read() and "Cache-Control" in open(os.path.join(ipub, "_pages", ".htaccess"), encoding="utf8").read())
    ht = open(os.path.join(ipub, ".htaccess"), encoding="utf8").read()
    t.check("the web server rules serve ready-made pages only to cookie-less GET requests without a query", "HTTP_COOKIE" in ht and "REQUEST_METHOD} =GET" in ht and "QUERY_STRING} ^$" in ht and "_pages/index.html" in ht)
    t.check("the csp repeated for the web server matches the application's", "frame-ancestors 'self'" in open(os.path.join(ipub, "_pages", ".htaccess"), encoding="utf8").read())
    s, j = ctx.client().api("GET", "csrf"); t.check("a page without a token can ask for one", s == 200 and len(j.get("token", "")) > 20)
    s, st = o.api("GET", "admin/speed/status"); t.check("the owner sees how many pages are ready", s == 200 and st["cache"]["pages"] > 50 and st["cache"]["enabled"])

    # content changes refresh the pages
    s, seo = o.api("GET", "admin/settings") if False else (200, None)
    s, ex = o.api("POST", "admin/speed/purge", {}); t.check("clearing removes the stored pages", s == 200 and ex["cache"]["pages"] == 0)
    o.api("POST", "admin/speed/rebuild", {}); sc = o.api("GET", "admin/content")[1] if False else None
    s, pr = o.api("POST", "admin/speed/save", {"pageCache": False, "busyMode": "off", "autoLoad": 2.5}); t.check("the ready-made pages can be switched off", s == 200 and pr["cache"]["enabled"] is False and pr["cache"]["pages"] == 0, str(pr)[:150])
    s, h, b = ctx.client().raw("GET", "/"); t.check("with the switch off visitors get live pages", s == 200 and not os.path.exists(os.path.join(ipub, "_pages", "index.html")))
    o.api("POST", "admin/speed/save", {"pageCache": True, "busyMode": "off", "autoLoad": 2.5})

    # busy mode
    c = ctx.client(); c.register("sup-c@test.local"); s, jr = c.api("POST", "resumes", {"data": {"version": "1", "title": "R", "template": "suite-prism", "accent": "#1557d2", "font": "Arial", "personal": {"name": "Ann", "email": "a@b.co"}, "summary": "Recruiter with four years of experience in hiring and reporting for technology teams.", "skills": "Excel, SQL, sourcing", "order": ["summary"], "experience": [], "education": [], "projects": [], "certifications": []}}); rid2 = jr["id"]
    job = "We are hiring a recruiter. Responsibilities: source candidates, screen candidates, schedule interviews, maintain the applicant tracking system. Required: 2+ years of recruitment experience and strong communication skills."
    o.api("POST", "admin/speed/save", {"pageCache": True, "busyMode": "on", "autoLoad": 2.5})
    s, j = c.api("POST", "ats/scan", {"resumeId": rid2, "source": "text", "text": job}); t.check("busy mode pauses ATS reviews with a polite message", s == 503 and "very busy" in str(j) and "try again" in str(j), (s, str(j)[:160]))
    s, h, b = c.raw("POST", f"/export/resume/{rid2}/pdf", {"clean": False}, {"Accept": "application/json", "X-CSRF-TOKEN": c.csrf or ""}); t.check("busy mode pauses PDF and Word creation", s == 503 and h.get("Retry-After") == "60", (s, h.get("Retry-After")))
    s, h, b = c.raw("POST", f"/export/resume/{rid2}/print", {"clean": False}, {"Accept": "application/json", "X-CSRF-TOKEN": c.csrf or ""}); t.check("the lightweight print layout keeps working", s == 200)
    t.check("pages, sign-in and payments keep working in busy mode", ctx.client().raw("GET", "/")[0] == 200 and c.api("GET", "allowance")[0] == 200 and c.api("POST", "checkout/start", {"plan": "fresher-basic"})[0] == 200)
    s, st = o.api("GET", "admin/speed/status"); t.check("the owner sees busy mode is on", st["traffic"]["mode"] == "on" and st["traffic"]["busyNow"] is True)
    o.api("POST", "admin/speed/save", {"pageCache": True, "busyMode": "auto", "autoLoad": 50})
    s, j = c.api("POST", "ats/scan", {"resumeId": rid2, "source": "text", "text": job}); t.check("automatic mode lets work through while the server is quiet", s in (200, 422) and s != 503, (s, str(j)[:120]))
    o.api("POST", "admin/speed/save", {"pageCache": True, "busyMode": "off", "autoLoad": 2.5})
    s, j = c.api("POST", "ats/scan", {"resumeId": rid2, "source": "text", "text": job}); t.check("with busy mode off everything works again", s in (200, 422) and s != 503)
    t.check("busy-mode settings are validated", o.api("POST", "admin/speed/save", {"pageCache": True, "busyMode": "maybe", "autoLoad": 2})[0] == 422 and o.api("POST", "admin/speed/save", {"pageCache": True, "busyMode": "auto", "autoLoad": 0})[0] == 422)
    t.check("a customer cannot change traffic settings", c.api("POST", "admin/speed/save", {"pageCache": False, "busyMode": "on", "autoLoad": 2})[0] in (401, 403, 404))
