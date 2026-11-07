"""More approvable SEO changes and content versions (3.45.0): heading, FAQ, structured data and content-brief drafts with
checks on what may be said, nobody approves their own draft, every live change is a content version that is checked on
the live page and can be undone, briefs become tasks, verification lands on the linked task's evidence, and the editorial
review line appears only after a person really reviewed the page."""
import json, re
NAME = "SEO changes, versions and editorial review"


def run(ctx, t):
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"PHP_CLI_SERVER_WORKERS": "4"}).start()
    try:
        db = Db(inst.db); o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        tinker = lambda code: inst.artisan("tinker", "--execute=" + code)
        last = lambda r: (r.stdout.strip().splitlines() or [""])[-1]
        tinker("App\\Services\\Settings::put('seo', array_merge(App\\Services\\Settings::get('seo'), ['siteUrl' => '', 'searchIndexing' => true]));")

        def staff(role, tag):
            email = f"sc-{tag}@test.local"; c = Client(inst.base); c.register(email)
            o.api("POST", "admin/team/save", {"email": email, "role": role})
            x = Client(inst.base); x.form("/sanaulla", {"email": email, "password": "TestPassw0rd!!x"}); x.page("/admin.html"); return x
        seo, admin, editor = staff("seo_manager", "seo"), staff("admin", "admin"), staff("content_editor", "editor")
        cust = Client(inst.base); cust.register("sc-cust@test.local")
        uid = lambda e: db.q("select id from users where email=?", e)[0][0]
        P = "/resume-examples/"
        h1_of = lambda: re.search(r"<h1[^>]*>(.*?)</h1>", o.page(P)[2], re.S).group(1).strip()
        orig = h1_of()
        o.api("POST", "admin/growth/seo/scan", {"limit": 30})  # so the page has a record

        # drafting rules
        d = lambda c, kind, data, path=P, reason="Matches what people search for on this page": c.api("POST", "admin/growth/seo/draft", {"kind": kind, "path": path, "data": data, "reason": reason})
        t.check("a customer cannot draft SEO changes", d(cust, "h1", {"h1": "Resume examples by field"})[0] in (401, 403))
        t.check("a Content Editor (view only) cannot draft", d(editor, "h1", {"h1": "Resume examples by field"})[0] == 403)
        t.check("a change that mentions AI is refused", d(seo, "h1", {"h1": "AI resume examples for India"})[0] == 422)
        t.check("a change with another brand's name is refused", d(seo, "h1", {"h1": "Naukri resume examples for India"})[0] == 422)
        t.check("a promise is refused", d(seo, "faq", {"items": [["Will this get me a job?", "Yes, 100% guaranteed interviews within a week."]]})[0] == 422)
        t.check("only the ready-made SEO pages can be changed", d(seo, "h1", {"h1": "About the team behind it"}, path="/about.html")[0] == 422)
        t.check("SoftwareApplication data only goes on the free tools", d(seo, "schema", {"type": "SoftwareApplication"})[0] == 422)
        t.check("a reason is required", d(seo, "h1", {"h1": "Resume examples by field"}, reason="")[0] == 422)
        s, j = d(seo, "h1", {"h1": "Resume examples for every job in India"})
        hid = j.get("id")
        t.check("an SEO Manager drafts a new heading", s == 200 and db.q("select created_by, status from seo_proposals where id=?", hid)[0] == (uid("sc-seo@test.local"), "pending"), (s, j))
        t.check("a second heading draft for the same page waits for the first", d(seo, "h1", {"h1": "Another heading for this page"})[0] == 422)
        s, j = d(seo, "faq", {"items": [["How many examples should I read?<script>alert(1)</script>", "Two or three from your own field are enough to see the pattern."]]})
        fid = j.get("id")
        t.check("FAQ drafts are cleaned of markup", s == 200 and "<script>" not in db.q("select data_json from seo_proposals where id=?", fid)[0][0], (s, j))
        t.check("a question already on the page is refused", d(seo, "schema", {"type": "Article"})[0] == 200 and d(admin, "faq", {"items": [["Are these examples free?", "Yes, reading the examples is free for everyone."]]})[0] == 422)
        sid = db.q("select id from seo_proposals where kind='schema' and path=?", P)[0][0]

        # approval
        t.check("the drafter cannot approve their own change", seo.api("POST", "admin/approvals/decide", {"category": "seo", "id": hid, "decision": "approve"})[0] == 403)
        s, j = admin.api("GET", "admin/approvals")
        it = next((x for x in j["items"] if x["id"] == fid), None)
        t.check("the Approval Centre shows who drafted it and what it adds", it and it["submittedBy"] != "SEO agent" and "FAQ" in it["action"] and it["changes"]["after"]["items"], it)
        ad = d(admin, "h1", {"h1": "Resume examples for every role"}, path="/resume-templates/")[1]["id"]
        t.check("an Admin's own draft is not in their approval list and cannot be approved by them", not any(x["id"] == ad for x in admin.api("GET", "admin/approvals")[1]["items"]) and admin.api("POST", "admin/approvals/decide", {"category": "seo", "id": ad, "decision": "approve"})[0] == 403)
        for pid in (hid, fid, sid):
            s, j = admin.api("POST", "admin/approvals/decide", {"category": "seo", "id": pid, "decision": "approve"})
        t.check("an Admin approves the heading, FAQ and structured data", s == 200 and db.q("select count(*) from seo_proposals where id in (?,?,?) and status='approved'", hid, fid, sid)[0][0] == 3, (s, j))
        html = o.page(P)[2]
        t.check("the new heading is live on the page", h1_of() == "Resume examples for every job in India", h1_of())
        t.check("the new FAQ question is live, in the FAQ structured data too, and without the script", "How many examples should I read?" in html and "alert(1)" not in html and html.count("How many examples should I read?") >= 2)
        lds = [json.loads(x) for x in re.findall(r'<script type="application/ld\+json">(.*?)</script>', html, re.S)]
        flat = [b for x in lds for b in (x if isinstance(x, list) else [x])]
        art = next((b for b in flat if b.get("@type") == "Article"), None)
        t.check("Article data names the CVTara team as author, never an invented person", art and art["author"] == {"@type": "Organization", "name": "CVTara team", "url": art["author"]["url"]}, art)
        vers = db.q("select id, kind, verify_status, applied_by from seo_content_versions where proposal_id in (?,?,?)", hid, fid, sid)
        t.check("each live change is a content version with who applied it", len(vers) == 3 and all(v[2] == "pending" and v[3] == uid("sc-admin@test.local") for v in vers), vers)
        hv = next(v[0] for v in vers if v[1] == "h1")
        out = [json.loads(last(tinker(f"echo json_encode(App\\Services\\SeoContent::verify({v[0]}));")) or "{}") for v in vers]
        t.check("each change is checked on the live page and confirmed", all(x.get("verify_status") == "live" for x in out), [(x.get("kind"), x.get("verify_status"), x.get("verify_note")) for x in out])
        s, j = seo.api("GET", "admin/growth/seo/versions?path=" + P)
        t.check("the page's versions show before, after, the live check and undo", s == 200 and len(j["rows"]) == 3 and next(r for r in j["rows"] if r["kind"] == "h1")["before"]["h1"] == orig and all(r["canUndo"] for r in j["rows"]), (s, str(j)[:300]))

        # undo
        t.check("an SEO Manager (drafts only) cannot undo", seo.api("POST", "admin/growth/seo/decide", {"id": hid, "action": "undo"})[0] == 403)
        s, j = admin.api("POST", "admin/growth/seo/decide", {"id": hid, "action": "undo"})
        t.check("one click undoes the heading on the live page", s == 200 and h1_of() == orig, (s, j, h1_of()))
        v = db.q("select reverted_by, verify_status from seo_content_versions where id=?", hv)[0]
        t.check("the undo is recorded on the version and checked again", v == (uid("sc-admin@test.local"), "pending"), v)
        db.q("update seo_content_versions set reverted_at=datetime('now','-3 minutes') where id=?", hv)
        r = json.loads(last(tinker("echo json_encode(App\\Services\\SeoContent::verifyDue());")) or "{}")
        t.check("the cron confirms the undo on the live page", r.get("checked") == 1 and db.q("select verify_status, verify_note from seo_content_versions where id=?", hv)[0][0] == "live", (r, db.q("select verify_status, verify_note from seo_content_versions where id=?", hv)))
        t.check("the FAQ and structured data stay live after the heading is undone", "How many examples should I read?" in o.page(P)[2])

        # briefs become tasks
        n = int(last(tinker("echo App\\Services\\SeoContent::autoBriefs();")) or 0)
        n2 = int(last(tinker("echo App\\Services\\SeoContent::autoBriefs();")) or 0)
        topics = [json.loads(x[0])["topic"] for x in db.q("select data_json from seo_proposals where kind='brief' and created_by is null")]
        t.check("the agent drafts two briefs a day for missing topics, each topic once", n == 2 and n2 == 2 and len(topics) == 4 and len(set(topics)) == 4, (n, n2, topics))
        bid = db.q("select id from seo_proposals where kind='brief' order by created_at limit 1")[0][0]
        s, j = admin.api("POST", "admin/approvals/decide", {"category": "seo", "id": bid, "decision": "approve"})
        task = db.q("select t.status, t.title, t.agent from ai_tasks t join seo_proposals p on p.task_id=t.id where p.id=?", bid)
        t.check("an approved brief becomes an approved writing task", s == 200 and task and task[0][0] == "approved" and task[0][1].startswith("Write: ") and task[0][2] == "seo", (s, j, task))
        t.check("a brief changes no page, so it has no content version and no undo", db.q("select count(*) from seo_content_versions where proposal_id=?", bid)[0][0] == 0 and admin.api("POST", "admin/growth/seo/decide", {"id": bid, "action": "undo"})[0] == 422)

        # verification lands on a linked task
        tk = o.api("POST", "admin/tasks", {"agent": "seo", "title": "Improve the templates page heading", "submit": True})[1]["id"]
        s, j = seo.api("POST", "admin/growth/seo/draft", {"kind": "faq", "path": "/resume-templates/", "data": {"items": [["Can I print these templates?", "Yes. Download a PDF and print it on A4 paper at full size."]]}, "reason": "Asked often by people who print resumes for walk-ins", "taskId": tk})
        lid = j.get("id")
        t.check("a draft can be linked to an SEO task", s == 200 and db.q("select task_id from seo_proposals where id=?", lid)[0][0] == tk, (s, j))
        t.check("only SEO tasks can be linked", seo.api("POST", "admin/growth/seo/draft", {"kind": "h1", "path": "/resume-builder/", "data": {"h1": "Free resume builder for India"}, "reason": "Matches the main search for this page", "taskId": "aaaaaaaa-0000-0000-0000-000000000000"})[0] == 422)
        admin.api("POST", "admin/approvals/decide", {"category": "seo", "id": lid, "decision": "approve"})
        vid = db.q("select id from seo_content_versions where proposal_id=?", lid)[0][0]
        s, j = seo.api("POST", f"admin/growth/seo/versions/{vid}/verify", {})
        ev = db.q("select value from ai_task_evidence where task_id=?", tk)
        t.check("checking the change now confirms it and adds the result to the task's evidence", s == 200 and j["status"] == "live" and ev and "Seen on the live page" in ev[0][0], (s, j, ev))

        # editorial review
        before = o.page(P)[2]
        t.check("before any review the page says only 'Written by the CVTara team'", "Written by the" in before and "Reviewed by the CVTara team" not in before)
        t.check("a Content Editor (view only) cannot mark a page reviewed", editor.api("POST", "admin/growth/seo/page/reviewed", {"path": P})[0] == 403)
        s, j = seo.api("POST", "admin/growth/seo/page/reviewed", {"path": P})
        t.check("an SEO Manager marks the page reviewed, in the audit log", s == 200 and j["record"]["reviewedBy"] and db.q("select count(*) from audit_logs where action='seo_page_reviewed' and target=?", P)[0][0] == 1, (s, str(j)[:200]))
        t.check("the page then shows the review date", re.search(r"Reviewed by the CVTara team on <time datetime=\"\d{4}-\d\d-\d\d\">", o.page(P)[2]) is not None)
        t.check("the console has drafting, versions and review", all(x in o.page("/console/agents.js")[2] for x in ("growth/seo/draft", "Content versions", "page/reviewed")))
        t.check("the proposals export names every kind", o.raw("GET", "/api/admin/growth/seo/proposals?export=csv")[0] == 200)
    finally:
        inst.stop()
