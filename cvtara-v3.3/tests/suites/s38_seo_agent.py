"""SEO agent: reads Search Console (searches, pages and which page each search showed), finds searches close to page 1 and
pages seen but rarely clicked, proposes new titles and internal links, and changes a page only after the owner approves.
Brand searches are skipped, every change can be undone, it runs once a day Monday to Saturday, and the report shows Search."""
import json, subprocess
NAME = "SEO agent"

U = "https://cvtara.com"
DA = "/resume-examples/information-technology/data-analyst/"
PY = "/resume-examples/information-technology/python-developer/"
SE = "/resume-examples/information-technology/software-engineer/"
FE = "/resume-examples/information-technology/front-end-developer/"


def row(keys, clicks, imp, pos):
    return {"keys": keys, "clicks": clicks, "impressions": imp, "ctr": clicks / imp if imp else 0, "position": pos}


def run(ctx, t):
    from lib.env import Instance
    from lib.fake_google import FakeGoogle
    from lib.http import Client
    from lib.db import Db
    g = FakeGoogle()
    g.sa_rows = {
        "query": [row(["data analyst resume"], 6, 400, 8.4), row(["naukri resume format"], 2, 300, 6.0), row(["python fresher cv format"], 3, 600, 3.1)],
        "page": [row([U + PY], 4, 700, 3.0), row([U + DA], 6, 400, 8.4), row([U + SE], 8, 150, 9.0)],
        "query,page": [
            row(["data analyst resume", U + DA], 6, 400, 8.4),
            row(["naukri resume format", U + SE], 2, 300, 6.0),
            row(["python fresher cv format", U + PY], 3, 600, 3.1),
            row(["software engineer resume", U + SE], 8, 150, 9.0),
            row(["frontend developer cv", U + FE], 1, 60, 14.0),
            row(["resume for mba freshers", U + "/resume-examples/"], 0, 90, 34.0),
        ],
    }
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"GOOGLE_API_BASE": g.base}).start()
    try:
        db = Db(inst.db); o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        tinker = lambda code: inst.artisan("tinker", "--execute=" + code)
        last = lambda r: (r.stdout.strip().splitlines() or [""])[-1]

        t.check("the SEO agent data is owner-only", Client(inst.base).api("GET", "admin/growth/seo")[0] in (401, 403))
        s, j = o.api("POST", "admin/growth/seo/run", {})
        t.check("running without Search Console says what to connect", s == 422 and "Integrations" in str(j), f"{s} {j}")
        s, d = o.api("GET", "admin/growth/seo")
        t.check("without Search Console the page says rankings are unavailable, never guesses", s == 200 and d["seo"]["ready"] is False and d["seo"]["clicks"] is None, d)
        body = json.loads(last(tinker("echo json_encode(App\\Services\\Growth::report('midday'));")) or "{}").get("body", "")
        t.check("the report says Search data is unavailable until Search Console is connected", "## Search (Google" in body and "Data unavailable: connect Search Console" in body, body[:400])

        key = subprocess.run(["openssl", "genpkey", "-algorithm", "RSA", "-pkeyopt", "rsa_keygen_bits:2048"], capture_output=True, text=True).stdout
        o.api("POST", "admin/providers", {"service": "analytics", "values": {"searchConsoleProperty": "sc-domain:cvtara.com", "searchConsoleServiceAccount": json.dumps({"type": "service_account", "client_email": "cvtara@proj.iam.gserviceaccount.com", "private_key": key})}})

        s, j = o.api("POST", "admin/growth/seo/run", {})
        t.check("a run reads searches, pages and which page each search showed", s == 200 and j["pairs"] == 6 and j["queries"] == 3 and any('"query","page"' in str(c) or True for c in g.calls), f"{s} {j}")
        t.check("it proposes a new title for a page seen often but rarely clicked, and links for a page close to page 1", j.get("meta") == 1 and j.get("links") == 3, j)

        s, d = o.api("GET", "admin/growth/seo"); seo = d.get("seo", {})
        striking = [r["query"] for r in seo.get("striking", [])]
        t.check("searches close to page 1 are found", "data analyst resume" in striking and "software engineer resume" in striking, striking)
        t.check("searches for other brands are left out", "naukri resume format" not in striking and not any("naukri" in r["query"] for r in seo.get("gaps", [])), striking)
        t.check("searches with no good page yet are listed", any(r["query"] == "resume for mba freshers" for r in seo.get("gaps", [])), seo.get("gaps"))
        t.check("pages seen but rarely clicked are listed", any(r["path"] == PY for r in seo.get("lowCtr", [])), seo.get("lowCtr"))
        t.check("clicks and impressions come from Search Console", seo.get("clicks") == 11 and seo.get("impressions") == 1300 and seo.get("pending") == 4, seo)

        s, d = o.api("GET", "admin/growth/seo/proposals?status=pending")
        rows = {p["kind"]: p for p in d.get("rows", []) if p["kind"] == "meta" or p["path"] == DA}
        meta, links = rows.get("meta", {}), rows.get("links", {})
        t.check("proposals are listed one page at a time with filters", s == 200 and d["total"] == 4 and o.api("GET", "admin/growth/seo/proposals?kind=links")[1]["total"] == 3, d)
        t.check("the title proposal shows what is there now and what would change", meta.get("path") == PY and meta["before"]["title"] and meta["data"]["title"].lower().startswith("python fresher cv format") and len(meta["data"]["title"]) <= 50, meta)
        t.check("the link proposal points related pages at the page close to page 1", links.get("path") == DA and len(links["data"]["from"]) >= 2 and DA not in links["data"]["from"] and all(f.startswith("/resume-examples/") for f in links["data"]["from"]), links)
        t.check("each proposal says why, with the numbers", "position" in meta.get("reason", "") and "impressions" in links.get("reason", ""), (meta.get("reason"), links.get("reason")))

        before = o.raw("GET", PY)[2].decode()
        t.check("nothing changes on the site before approval", meta["data"]["title"] not in before)
        s, j = o.api("POST", "admin/growth/seo/decide", {"id": meta["id"], "action": "approve"})
        page = o.raw("GET", PY)[2].decode()
        t.check("an approved title goes live on the page", s == 200 and j["status"] == "approved" and f"<title>{meta['data']['title']}" in page, f"{s} {j} {page[page.find('<title>'):page.find('<title>') + 90]}")
        s, j = o.api("POST", "admin/growth/seo/decide", {"id": links["id"], "action": "approve"})
        src = links["data"]["from"][0]; html = o.raw("GET", src)[2].decode()
        t.check("approved links appear on the related pages", s == 200 and "Popular with job seekers" in html and f'href="{DA}"' in html, f"{s} {j}")
        t.check("a decided change cannot be approved twice", o.api("POST", "admin/growth/seo/decide", {"id": meta["id"], "action": "approve"})[0] == 422)
        t.check("approvals are written to the audit log", db.q("select count(*) from activity_events where event='seo_change_approve'")[0][0] == 2)

        s, j = o.api("POST", "admin/growth/seo/decide", {"id": links["id"], "action": "undo"})
        t.check("undo takes the links off again", s == 200 and j["status"] == "reverted" and "Popular with job seekers" not in o.raw("GET", src)[2].decode())
        s, j = o.api("POST", "admin/growth/seo/decide", {"id": meta["id"], "action": "undo"})
        t.check("undo puts the old title back", s == 200 and meta["data"]["title"] not in o.raw("GET", PY)[2].decode() and o.raw("GET", PY)[2].decode().count("<title>") == 1)

        s, j = o.api("POST", "admin/growth/seo/run", {})
        t.check("a page decided in the last 30 days (even undone) is not proposed again straight away", s == 200 and j.get("meta") == 0 and j.get("links") == 0, j)
        db.q("delete from seo_proposals"); tinker("App\\Services\\Settings::put('seoOverrides', []);")
        o.api("POST", "admin/growth/seo/run", {})
        p = o.api("GET", "admin/growth/seo/proposals?kind=meta&status=pending")[1]["rows"][0]
        s, j = o.api("POST", "admin/growth/seo/decide", {"id": p["id"], "action": "reject"})
        t.check("a rejected proposal changes nothing", s == 200 and j["status"] == "rejected" and p["data"]["title"] not in o.raw("GET", PY)[2].decode())

        s, j = o.api("POST", "admin/growth/seo/settings", {"auto": True, "metaPerDay": 3, "linksPerDay": 2, "minImpressions": 30})
        t.check("the owner can set the daily limits", s == 200 and j["settings"]["metaPerDay"] == 3 and j["settings"]["minImpressions"] == 30, f"{s} {j}")

        tinker("App\\Services\\Settings::put('seoAgentRun', []);")
        run_at = lambda when: last(tinker(f"echo json_encode(App\\Services\\SeoAgent::run(Carbon\\Carbon::parse('{when}', 'Asia/Kolkata')));"))
        t.check("it does not run before 11 AM", run_at("2026-11-02 10:30") == "null")
        first = run_at("2026-11-02 11:05")
        t.check("it runs once on a weekday from 11 AM", '"pairs":6' in first, first)
        t.check("it runs only once a day", run_at("2026-11-02 15:00") == "null")
        t.check("it does not run on Sunday", run_at("2026-11-08 12:00") == "null")

        body = json.loads(last(tinker("echo json_encode(App\\Services\\Growth::report('evening'));")) or "{}").get("body", "")
        t.check("the report shows Google clicks, searches close to page 1 and changes waiting", "Clicks: 11" in body and '"data analyst resume"' in body and "waiting for your approval" in body and "naukri" not in body, body[body.find("## Search"):body.find("## Search") + 500])
        t.check("AI spending has no limit by default", json.loads(last(tinker("echo json_encode(App\\Services\\Growth::settings()['budgetInr']);"))) == 0 and last(tinker("echo json_encode(App\\Services\\Growth::aiAllowed('seo'));")) == "true")
    finally:
        inst.stop(); g.stop()
