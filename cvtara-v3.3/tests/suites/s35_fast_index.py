"""Search Console connection and fast indexing: friendly key errors, IndexNow key file, new/changed pages, Google quota and sitemap resubmission."""
import json, subprocess, urllib.parse
NAME = "Fast indexing"


def run(ctx, t):
    from lib.env import Instance
    from lib.fake_google import FakeGoogle
    from lib.http import Client
    from lib.db import Db
    g = FakeGoogle()
    inst = Instance(ctx.app, ctx.public, preview=True, extra_env={"GOOGLE_API_BASE": g.base}).start()
    try:
        db = Db(inst.db); o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        save = lambda values: o.api("POST", "admin/providers", {"service": "analytics", "values": values})
        s, j = o.api("POST", "admin/search/sync", {}); t.check("sync without credentials explains what to add", s == 422 and "Integrations" in str(j), f"{s} {j}")
        save({"searchConsoleProperty": "sc-domain:cvtara.com", "searchConsoleServiceAccount": "not json"})
        s, j = o.api("POST", "admin/search/sync", {}); t.check("pasted text that is not JSON gets a plain explanation", s == 422 and "not valid" in str(j), f"{s} {j}")
        key = subprocess.run(["openssl", "genpkey", "-algorithm", "RSA", "-pkeyopt", "rsa_keygen_bits:2048"], capture_output=True, text=True).stdout
        save({"searchConsoleServiceAccount": json.dumps({"type": "service_account", "client_email": "cvtara@proj.iam.gserviceaccount.com", "private_key": key})})
        s, j = o.api("POST", "admin/search/sync", {})
        t.check("Search Console sync reads queries for the saved property", s == 200 and j["data"]["query"][0]["key"] == "resume builder" and any("sc-domain%3Acvtara.com" in p for _, p in g.calls), f"{s} {j}")

        s, d = o.api("GET", "admin/indexing"); k = d.get("key", "")
        t.check("an IndexNow key is made once", s == 200 and len(k) == 32 and o.api("GET", "admin/indexing")[1]["key"] == k, d)
        s, h, body = o.raw("GET", "/" + k + ".txt")
        t.check("the key file is served at the site root", s == 200 and body.decode() == k and h.get("Content-Type", "").startswith("text/plain"), f"{s} {body[:40]}")
        t.check("other .txt addresses are not key files", o.raw("GET", "/" + "0" * 32 + ".txt")[0] == 404)
        s, j = o.api("POST", "admin/indexing/submit", {"mode": "changed", "google": False})
        t.check("nothing is sent while search indexing is off", s == 422 and "indexing is off" in str(j) and not g.indexnow, f"{s} {j}")

        seo = json.load(open(f"{ctx.app}/resources/data/defaults.json"))["seo"]
        db.q("insert or replace into site_settings(key,value_json,revision,created_at,updated_at) values('seo',?,1,datetime('now'),datetime('now'))", json.dumps({**seo, "searchIndexing": True}))
        s, j = o.api("POST", "admin/indexing/submit", {"mode": "changed", "google": False})
        sent = g.indexnow[-1] if g.indexnow else {}
        t.check("every public page goes to IndexNow the first time, with the key and its file", s == 200 and len(sent.get("urlList", [])) == d["pages"] > 100 and sent["key"] == k and sent["keyLocation"] == "https://cvtara.com/" + k + ".txt" and sent["host"] == "cvtara.com", f"{s} {j.get('entry')}")
        s, j = o.api("POST", "admin/indexing/submit", {"mode": "changed", "google": False})
        t.check("a second send has nothing new", s == 200 and len(g.indexnow) == 1 and j["waiting"] == 0, j.get("entry"))
        s, j = o.api("POST", "admin/indexing/submit", {"mode": "urls", "urls": "https://evil.example/x", "google": False})
        t.check("addresses outside the site are refused", s == 422, f"{s} {j}")
        s, j = o.api("POST", "admin/indexing/submit", {"mode": "urls", "urls": "https://cvtara.com/guides.html\nhttps://cvtara.com/templates.html", "google": True})
        t.check("chosen addresses go to IndexNow and Google", s == 200 and g.indexnow[-1]["urlList"] == ["https://cvtara.com/guides.html", "https://cvtara.com/templates.html"] and g.published == ["https://cvtara.com/guides.html", "https://cvtara.com/templates.html"], j.get("entry"))
        s, j = o.api("POST", "admin/indexing/submit", {"mode": "changed", "google": True})
        t.check("Google gets at most 40 a run and counts them against the daily 200", s == 200 and len(g.published) == 42 and j["googleUsed"] == 42 and "wait for the next run" in j["entry"]["google"], j.get("entry"))
        g.deny_indexing = True
        s, j = o.api("POST", "admin/indexing/submit", {"mode": "changed", "google": True})
        t.check("a Google permission error says to add the account as Owner", s == 200 and "Owner" in j["entry"]["google"] and j["googleUsed"] == 42, j.get("entry"))
        s, j = o.api("POST", "admin/indexing/sitemap", {})
        t.check("the sitemap is resubmitted through Search Console", s == 200 and g.sitemaps and urllib.parse.unquote(g.sitemaps[-1]).endswith("sites/sc-domain:cvtara.com/sitemaps/https://cvtara.com/sitemap.xml") and j["sitemapAt"], f"{s} {g.sitemaps}")
        s, j = o.api("POST", "admin/indexing/settings", {"auto": True, "google": False})
        t.check("automatic sending can be switched on", s == 200 and j["auto"] is True and j["google"] is False, j)
        n = len(g.indexnow); r = inst.artisan("cvtara:cron")
        t.check("the hourly run sends nothing when no page changed", r.returncode == 0 and len(g.indexnow) == n, (r.stdout + r.stderr)[-300:])
        t.check("sending is owner-only", Client(inst.base).api("GET", "admin/indexing")[0] in (401, 403))
        t.check("the activity log keeps what was sent", len(o.api("GET", "admin/indexing")[1]["log"]) >= 5)
    finally:
        inst.stop(); g.stop()
