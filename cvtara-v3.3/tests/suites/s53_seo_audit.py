"""SEO page record and audit history (3.44.0): every scanned page is scored against the technical checklist and the result
is kept with its date; orphan, sitemap, noindex and broken-link lists; the page record a person can edit (with permission
checks and the audit log); busy mode pauses scans; Core Web Vitals come only from the Chrome UX Report and are "not
tracked" without a key or without enough visitors."""
import json
NAME = "SEO page record and audit history"


def run(ctx, t):
    from lib.env import Instance
    from lib.fake_google import FakeGoogle
    from lib.http import Client
    from lib.db import Db
    g = FakeGoogle()
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"GOOGLE_API_BASE": g.base, "PHP_CLI_SERVER_WORKERS": "4"}).start()
    try:
        db = Db(inst.db); o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        tinker = lambda code: inst.artisan("tinker", "--execute=" + code)
        last = lambda r: (r.stdout.strip().splitlines() or [""])[-1]
        tinker("App\\Services\\Settings::put('seo', array_merge(App\\Services\\Settings::get('seo'), ['siteUrl' => '', 'searchIndexing' => true]));")

        def staff(role):
            email = f"sa-{role.replace('_', '-')}@test.local"; c = Client(inst.base); c.register(email)
            o.api("POST", "admin/team/save", {"email": email, "role": role})
            x = Client(inst.base); x.form("/sanaulla", {"email": email, "password": "TestPassw0rd!!x"}); x.page("/admin.html"); return x
        seo, editor, agent = staff("seo_manager"), staff("content_editor"), staff("support_agent")
        cust = Client(inst.base); cust.register("sa-cust@test.local")

        s, j = o.api("POST", "admin/growth/seo/scan", {"limit": 60})
        t.check("a scan also audits the pages it read", s == 200 and "Audited" in j.get("summary", ""), (s, j))
        n = db.q("select count(*) from seo_page_audits")[0][0]
        t.check("each scanned page gets a dated audit row", n >= 17, n)
        home = db.q("select audit_score, audit_status, page_type, images, outline_json, signals_json from seo_pages where path='/'")[0]
        t.check("the home page has a score, a status and type 'home'", home[0] is not None and 0 <= home[0] <= 100 and home[1] in ("good", "needs_work", "poor") and home[2] == "home", home[:3])
        sig = json.loads(home[5] or "{}")
        t.check("the scan reads the outline, images and mobile viewport", isinstance(json.loads(home[4] or "null"), list) and sig.get("viewport") is True, (home[4][:120] if home[4] else None, sig))
        types = dict(db.q("select path, page_type from seo_pages where path in ('/ats-checker/', '/about.html', '/resume-examples/')"))
        t.check("page types are filled in by rule", types.get("/ats-checker/") == "product" and types.get("/about.html") == "company" and types.get("/resume-examples/") == "examples", types)

        s, d = o.api("GET", "admin/growth/seo/page?path=/")
        keys = [c["key"] for c in d.get("checks", [])]
        t.check("the page record shows the full checklist (24 checks) and the history", s == 200 and len(keys) == 24 and {"canonical", "robots_txt", "sitemap", "broken_links", "links_in", "cwv"} <= set(keys) and d["audits"], (s, keys))
        cwv = next(c for c in d["checks"] if c["key"] == "cwv")
        t.check("without a Chrome UX Report key, Core Web Vitals say 'not tracked' and do not count", cwv["result"] == "na" and "Not tracked" in cwv["detail"], cwv)
        rob = next(c for c in d["checks"] if c["key"] == "robots_txt")
        t.check("robots.txt is read and the home page is allowed", rob["result"] == "pass", rob)

        # broken link and noindex in the sitemap
        links = json.loads(db.q("select links_json from seo_pages where path='/'")[0][0])
        target = next(p for p in links if p not in ("/",) and db.q("select 1 from seo_pages where path=?", p))
        db.q("update seo_pages set http_status=404 where path=?", target)
        db.q("update seo_pages set robots='noindex, follow' where path='/about.html'")
        before = db.q("select count(*) from seo_page_audits where path='/'")[0][0]
        s, j = o.api("POST", "admin/growth/seo/audit", {})
        t.check("the owner can run the full audit", s == 200 and j.get("audited", 0) >= 17, (s, j))
        t.check("the full audit cannot be run again within 5 minutes", o.api("POST", "admin/growth/seo/audit", {})[0] == 429)
        s, d = o.api("GET", "admin/growth/seo/page?path=/")
        bl = next(c for c in d["checks"] if c["key"] == "broken_links")
        t.check("a link to a broken page fails the home page's broken-links check", bl["result"] == "fail" and target in bl["detail"], bl)
        t.check("a changed result is written as a new audit row", db.q("select count(*) from seo_page_audits where path='/'")[0][0] == before + 1)
        ab = o.api("GET", "admin/growth/seo/page?path=/about.html")[1]
        t.check("a sitemap page marked noindex fails and is rated poor", next(c for c in ab["checks"] if c["key"] == "meta_robots")["result"] == "fail" and ab["record"]["auditStatus"] == "poor", ab["record"])
        s, L = o.api("GET", "admin/growth/seo/audit-lists")
        t.check("the lists show noindex pages in the sitemap and broken pages with where they are linked from", s == 200 and "/about.html" in L["noindexInSitemap"] and any(b["path"] == target and "/" in b["linkedFrom"] for b in L["broken"]), (s, str(L)[:300]))
        t.check("the lists include orphans and pages missing from the sitemap", "orphans" in L and "notInSitemap" in L)
        w0 = db.q("select count(*) from seo_page_audits")[0][0]
        r = json.loads(last(tinker("echo json_encode(App\\Services\\SeoAudit::run());")) or "{}")
        t.check("an unchanged result is not written again (history stays readable)", r.get("written") == 0 and db.q("select count(*) from seo_page_audits")[0][0] == w0, r)

        # page record
        rec = {"path": "/ats-checker/", "pageType": "product", "intent": "transactional", "primaryKeyword": "ats resume checker", "secondaryKeywords": ["ats score", "resume scanner"], "contentStatus": "needs_update", "assignedAgent": "content", "note": "Add a section on Naukri"}
        t.check("a customer cannot edit the page record", cust.api("POST", "admin/growth/seo/page/record", rec)[0] in (401, 403))
        t.check("a Support Agent cannot see the audit lists", agent.api("GET", "admin/growth/seo/audit-lists")[0] == 403)
        t.check("a Content Editor (view only) can read but not edit the record", editor.api("GET", "admin/growth/seo/page?path=/ats-checker/")[0] == 200 and editor.api("POST", "admin/growth/seo/page/record", rec)[0] == 403)
        t.check("a bad page type is refused", seo.api("POST", "admin/growth/seo/page/record", dict(rec, pageType="casino"))[0] == 422)
        t.check("a page the scan does not know is refused", seo.api("POST", "admin/growth/seo/page/record", dict(rec, path="/no-such-page/"))[0] == 404)
        t.check("a path with odd characters is refused", seo.api("POST", "admin/growth/seo/page/record", dict(rec, path="/x<script>"))[0] == 422)
        s, j = seo.api("POST", "admin/growth/seo/page/record", rec)
        t.check("an SEO Manager saves the record", s == 200 and j["record"]["primaryKeyword"] == "ats resume checker" and j["record"]["secondaryKeywords"] == ["ats score", "resume scanner"] and j["record"]["contentStatus"] == "needs_update", (s, str(j)[:300]))
        t.check("the change is in the audit log", db.q("select count(*) from audit_logs where action='seo_page_record' and target='/ats-checker/'")[0][0] == 1)
        r = json.loads(last(tinker("echo json_encode(App\\Services\\SeoAudit::run(['/ats-checker/']));")) or "{}")
        t.check("the agent never overwrites what a person saved", db.q("select page_type, primary_keyword from seo_pages where path='/ats-checker/'")[0] == ("product", "ats resume checker"))
        s, j = seo.api("POST", "admin/growth/seo/page/audit", {"path": "/ats-checker/"})
        t.check("a person can scan and audit one page now, recorded as a manual audit", s == 200 and j["audits"][0]["source"] == "manual", (s, str(j)[:200]))

        # busy mode
        tinker("App\\Services\\Settings::put('speed', array_merge((array) App\\Services\\Settings::get('speed', []), ['busyMode' => 'on']));")
        s, j = o.api("POST", "admin/growth/seo/scan", {"limit": 5})
        t.check("in busy mode the scan waits", s == 200 and j.get("scanned") == 0 and "busy" in j.get("summary", ""), (s, j))
        t.check("in busy mode a one-page audit is refused politely", seo.api("POST", "admin/growth/seo/page/audit", {"path": "/"})[0] == 503)
        tinker("App\\Services\\Settings::put('speed', array_merge((array) App\\Services\\Settings::get('speed', []), ['busyMode' => 'off']));")

        # Core Web Vitals
        t.check("measuring Core Web Vitals needs a key and says where to add it", o.api("POST", "admin/growth/seo/web-vitals", {})[0] == 422)
        o.api("POST", "admin/providers", {"service": "analytics", "values": {"cruxApiKey": "crux-test"}})
        pv = o.api("GET", "admin/providers")[1]
        t.check("the key is stored but never shown back", pv["providers"]["analytics"]["cruxApiKey"] == {"configured": True} and "crux-test" not in json.dumps(pv))
        g.crux["/"] = (1800, 150, 0.05); g.crux["/about.html"] = (5200, 620, 0.31)
        s, j = o.api("POST", "admin/growth/seo/web-vitals", {})
        t.check("Core Web Vitals are measured for important pages, with pages lacking data counted apart", s == 200 and j.get("measured") == 2 and j.get("noData", 0) >= 1, (s, j))
        hv = json.loads(db.q("select cwv_json from seo_pages where path='/'")[0][0])
        t.check("good field data is stored with its ratings", hv["status"] == "ok" and hv["lcp"] == 1800 and set(hv["rating"].values()) == {"good"}, hv)
        d = o.api("GET", "admin/growth/seo/page?path=/about.html")[1]
        c = next(x for x in d["checks"] if x["key"] == "cwv")
        t.check("poor field data fails the Core Web Vitals check", c["result"] == "fail" and "LCP 5.2 s" in c["detail"], c)
        nd = db.q("select path from seo_pages where important=1 and http_status=200 and path not in ('/', '/about.html') limit 1")[0][0]
        c = next(x for x in o.api("GET", "admin/growth/seo/page?path=" + nd)[1]["checks"] if x["key"] == "cwv")
        t.check("a page without enough Chrome visitors is 'not tracked', not zero", c["result"] == "na" and "not enough" in c["detail"], c)
        t.check("the key is never sent to the page view", "crux-test" not in json.dumps(d))

        h = o.page("/console/agents.js")[2]
        t.check("the console shows the page record, checklist and audit lists", "growth/seo/page/record" in h and "audit-lists" in h and "Audit history" in h)
        ps = o.api("GET", "admin/growth/seo/pages?audit=poor")[1]
        t.check("the page list filters by audit status", ps["rows"] and all(r["auditStatus"] == "poor" for r in ps["rows"]), ps.get("total"))
    finally:
        inst.stop(); g.stop()
