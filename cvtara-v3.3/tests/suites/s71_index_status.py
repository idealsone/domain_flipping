"""Search engines box on the Overview (3.60.1): Google indexed counts from URL Inspection results, Bing Webmaster Tools
counts with the owner's API key (cached, friendly errors, key never shown), IndexNow sent/waiting, and role checks."""
import json
NAME = "Search engine index status"


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.fake_google import FakeGoogle
    from lib.http import Client
    from lib.db import Db
    g = FakeGoogle()
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"GOOGLE_API_BASE": g.base, "BING_API_BASE": g.base + "/bing"}).start()
    try:
        db = Db(inst.db); o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        s, j = o.api("GET", "admin/indexing/engines")
        t.check("the box loads with Bing not connected", s == 200 and j["bing"]["connected"] is False and j["bing"]["site"] == "https://cvtara.com/" and "indexnow" in j and "google" in j, f"{s} {j}")
        t.check("IndexNow shows every page waiting before the first send", j["indexnow"]["pages"] > 100 and j["indexnow"]["waiting"] == j["indexnow"]["pages"] and j["indexnow"]["sent"] == 0, j["indexnow"])

        rows = [("/", "PASS", "Submitted and indexed", 1), ("/a/", "PASS", "Submitted and indexed", 1), ("/b/", "NEUTRAL", "Discovered - currently not indexed", 1),
                ("/c/", "NEUTRAL", "Discovered - currently not indexed", 1), ("/d/", "NEUTRAL", "URL is unknown to Google", 1), ("/e/", "", "", 0), ("/f/", "", "", 0)]
        db.q("delete from seo_pages")
        for p, v, c, done in rows:
            db.q("insert into seo_pages(path,http_status,verdict,coverage,inspected_at,inspect_error) values(?,200,?,?,%s,'')" % ("datetime('now','-1 hour')" if done else "null"), p, v, c)
        db.q("insert into seo_pages(path,http_status,verdict,coverage,inspected_at,inspect_error) values('/gone/',404,'PASS','Submitted and indexed',datetime('now'),'')")
        sd = {"source": "Google Search Console API", "startDate": "2026-10-11", "endDate": "2026-11-08", "updatedAt": "2026-11-11T05:30:02+00:00", "query": [],
              "page": [{"key": "https://cvtara.com/", "clicks": 4, "impressions": 200, "ctr": 0.02, "position": 9}, {"key": "https://cvtara.com/a/", "clicks": 1, "impressions": 90, "ctr": 0.01, "position": 14}]}
        db.q("insert or replace into site_settings(key,value_json,revision,created_at,updated_at) values('searchData',?,1,datetime('now'),datetime('now'))", json.dumps(sd))
        gg = o.api("GET", "admin/indexing/engines")[1]["google"]
        reasons = {r["label"]: r["count"] for r in gg["reasons"]}
        t.check("Google counts indexed, checked and not-yet-checked live pages", gg["pages"] == 7 and gg["indexed"] == 2 and gg["checked"] == 5 and gg["notChecked"] == 2, gg)
        t.check("Google lists why pages are not indexed", reasons == {"Discovered - currently not indexed": 2, "URL is unknown to Google": 1}, gg["reasons"])
        t.check("Google search totals come from Search Console data", gg["search"] == {"from": "2026-10-11", "to": "2026-11-08", "pages": 2, "clicks": 5, "impressions": 290}, gg["search"])

        s, j = o.api("POST", "admin/providers", {"service": "analytics", "values": {"bingApiKey": "wrong-key"}})
        t.check("the Bing key is saved in Integrations > Analytics", s == 200, f"{s} {j}")
        b = o.api("GET", "admin/indexing/engines")[1]["bing"]
        t.check("a refused Bing key gets a plain explanation", b["connected"] and "refused the API key" in b.get("error", "") and "wrong-key" not in json.dumps(b), b)
        prov = o.api("GET", "admin/providers")[1]["providers"]["analytics"]
        t.check("the saved key is never sent back", prov.get("bingApiKey") == {"configured": True}, prov)
        o.api("POST", "admin/providers", {"service": "analytics", "values": {"bingApiKey": "bing-test"}})
        n = len(g.bing_calls); b = o.api("GET", "admin/indexing/engines")[1]["bing"]
        t.check("a new key is used at once, without waiting for the cache", len(g.bing_calls) > n and not b.get("error"), b)
        t.check("Bing shows the latest day's pages in index and crawl figures", b["inIndex"] == 57 and b["crawled"] == 20 and b["crawlErrors"] == 2 and b["asOf"], b)
        t.check("Bing clicks and impressions cover the last 30 days only", b["clicks30"] == 4 and b["impressions30"] == 150, b)
        t.check("Bing is asked for the site's own address", all("siteUrl=https%3A%2F%2Fcvtara.com%2F" in c for c in g.bing_calls[n:]), g.bing_calls[n:])
        n = len(g.bing_calls); o.api("GET", "admin/indexing/engines")
        t.check("Bing numbers are cached between Overview loads", len(g.bing_calls) == n)
        g.bing_crawl = []
        s, j = o.api("POST", "admin/indexing/engines/refresh", {})
        t.check("Refresh asks Bing again and explains a site with no data yet", s == 200 and len(g.bing_calls) > n and j["bing"]["inIndex"] is None and "no crawl data" in j["bing"]["note"], j.get("bing"))

        seo = json.load(open(f"{ctx.app}/resources/data/defaults.json"))["seo"]
        db.q("insert or replace into site_settings(key,value_json,revision,created_at,updated_at) values('seo',?,1,datetime('now'),datetime('now'))", json.dumps({**seo, "searchIndexing": True}))
        o.api("POST", "admin/indexing/submit", {"mode": "urls", "urls": "https://cvtara.com/guides.html", "google": False})
        x = o.api("GET", "admin/indexing/engines")[1]["indexnow"]
        t.check("IndexNow shows the last send and what is still waiting", x["sent"] == 1 and x["waiting"] == x["pages"] - 1 and x["lastCount"] == 1 and x["lastAt"] and x["indexing"] is True, x)

        t.check("visitors cannot read the box", Client(inst.base).api("GET", "admin/indexing/engines")[0] in (401, 403))

        def staff(role):
            email = f"ix-{role.replace('_', '-')}@test.local"; c = Client(inst.base); c.register(email)
            o.api("POST", "admin/team/save", {"email": email, "role": role})
            x = Client(inst.base); x.form("/sanaulla", {"email": email, "password": "TestPassw0rd!!x"}); x.page("/admin.html"); return x
        seo_m, agent = staff("seo_manager"), staff("support_agent")
        t.check("an SEO Manager can see the box", seo_m.api("GET", "admin/indexing/engines")[0] == 200)
        t.check("a support agent cannot", agent.api("GET", "admin/indexing/engines")[0] == 403)
        t.check("only the owner can change the Bing key", seo_m.api("POST", "admin/providers", {"service": "analytics", "values": {"bingApiKey": "x"}})[0] == 403)

        js = open(ctx.app + "/resources/console/indexing.js").read(); html = open(ctx.app + "/resources/frontend/admin.html").read()
        t.check("the Overview loads the Search engines box", '/console/indexing.js' in html and "Search engines" in js and "indexing/engines" in js)
        s, h, body = o.raw("GET", "/console/indexing.js")
        t.check("the box script is served to staff only", s == 200 and Client(inst.base).raw("GET", "/console/indexing.js")[0] == 404, s)
    finally:
        inst.stop(); g.stop()
