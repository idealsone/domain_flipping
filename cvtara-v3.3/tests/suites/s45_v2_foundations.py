"""V2 foundations (3.36.0+): request references, one approved writing service."""
import json, os

NAME = "V2 foundations"


def run(ctx, t):
    c, db = ctx.client(), ctx.db()
    s, h, _ = c.raw("GET", "/api/health", None, {"Accept": "application/json"})
    rid = h.get("X-Request-Id", "")
    t.check("every response carries a request reference", s == 200 and len(rid) == 12 and rid.isalnum(), h.get("X-Request-Id"))
    s2, h2, _ = c.raw("GET", "/api/health", None, {"Accept": "application/json", "X-Request-Id": "ATTACKER-CHOSEN"})
    t.check("a reference sent by the browser is ignored", h2.get("X-Request-Id") not in ("", "ATTACKER-CHOSEN", rid), h2.get("X-Request-Id"))
    s, h, _ = c.raw("GET", "/", None, {"Accept": "text/html"})
    t.check("pages carry a reference too", len(h.get("X-Request-Id", "")) == 12)

    c.token()
    s, h, _ = c.raw("POST", "/client-error", {"message": "v2 reference probe " + rid, "source": "/x.js", "line": 3, "url": "/workspace.html"}, {"Accept": "application/json", "X-CSRF-TOKEN": c.csrf or ""})
    row = db.q("select context from error_logs where message like ?", "%v2 reference probe%")
    ctxj = json.loads(row[0][0]) if row and row[0][0] else {}
    t.check("an Error Log entry keeps the reference of the request that caused it", ctxj.get("requestId") == h.get("X-Request-Id"), (ctxj, h.get("X-Request-Id")))

    o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
    s, j = o.api("GET", "admin/errors?q=" + ctxj.get("requestId", "none"))
    t.check("owner finds the entry by its reference in the Error Log", s == 200 and any("v2 reference probe" in e["message"] for e in j.get("errors", [])), f"{s} {str(j)[:200]}")

    # Only the approved writing service exists: no second provider path, key field or source file.
    app = ctx.app
    t.check("the old second writing provider is gone from the code", not os.path.exists(os.path.join(app, "app/Services/WritingAssistant.php")))
    src = open(os.path.join(app, "app/Http/Controllers/AdminController.php")).read() + open(os.path.join(app, "resources/console/advanced.js")).read()
    t.check("no key field for another writing provider in the console", "writerApiKey" not in src)
    u = ctx.client(); u.register("v2-writing@test.local")
    s, j = u.api("POST", "ai/suggest", {"task": "translate", "language": "English", "text": "x" * 40, "consent": True})
    t.check("a task the approved writing service does not offer is refused, with no other provider", s == 422, f"{s} {j}")
