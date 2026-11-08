"""Offers & festivals (owner manager, public pop-up data, schedule rules, coupon at checkout, statistics) and the recent-activity notice
(real buyers who opted in, nothing invented, default delay 90 seconds)."""
import os, uuid
NAME = "Offers & festivals and recent-activity notices"


def run(ctx, t):
    src = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "public_html")
    rd = lambda f: open(os.path.join(src, f), encoding="utf8").read()
    if ctx.preview:
        js, css, fomo = rd("promo.js"), rd("promo.css"), rd("fomo-popup.js")
        t.check("the offer script shows a top bar and timed, exit-intent and idle pop-ups", all(k in js for k in ("mouseout", "'idle'", "triggers.popup", "cvp-bar", "visibilityState")))
        t.check("the coupon travels with the visitor into sign-up and checkout", "cvtara-promo-intent" in js and "/register?next=" in js and "coupon=" in js and "cvtara-promo-intent" in rd("business-ui.js"))
        t.check("the offer shows a copy button and the refund note, and no countdown timer", "data-copy" in js and "data-count" not in js and "Ends in" not in js and "7-day refund if unused" in js)
        t.check("pop-ups respect reduced motion and fit phones", "prefers-reduced-motion" in css and "max-width:640px" in css)
        t.check("the activity notice shows a name only for real buyers and never invents any", "Verified purchase" in fomo and "kind === 'purchase'" in fomo and "Math.random" not in fomo)
        t.check("checkout offers an optional, unticked-by-default naming choice", 'id="coActivity"' in open(os.path.join(ctx.app, "resources/frontend/checkout.html"), encoding="utf8").read() and "showInActivity" in rd("checkout-options.js"))
        c0 = ctx.client(); s, _, home = c0.page("/")
        t.check("public pages load the offer script", s == 200 and "/promo.js" in home)
        s, _, co = c0.page("/checkout.html"); t.check("checkout and the dashboard do not show offer pop-ups", "/promo.js" not in co)
        admin = open(os.path.join(ctx.app, "resources/frontend/admin.html"), encoding="utf8").read()
        t.check("the owner console has the Offers & festivals page", 'data-admin-view="promos"' in admin and 'id="admin-promos"' in admin and "/console/promos.js" in admin)

    o = ctx.client(); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
    s, g = o.api("GET", "admin/promos")
    t.check("the owner sees festival templates and themes", s == 200 and len(g["templates"]) >= 15 and len(g["themes"]) >= 15 and {"diwali", "holi", "eid", "earlybird", "flash"} <= {x["key"] for x in g["templates"]}, str(g)[:160])
    plan = g["plans"][0]["key"]
    for x in g["campaigns"]:  # the preview comes with sample offers; start from a quiet site
        if x["status"] != "off": o.api("POST", "admin/promos/off", {"id": x["id"]})
    s, c = o.api("POST", "admin/promos/coupon", {"code": "diwali25", "type": "percent", "value": 25, "plans": [p["key"] for p in g["plans"]], "expiresAt": None, "maxUses": 0, "perUser": 1})
    t.check("the owner creates a coupon from the offer screen (upper-cased)", s == 200 and any(x["code"] == "DIWALI25" and x["value"] == 25 for x in c["coupons"]), (s, str(c)[:160]))
    t.check("a percentage coupon above 90 is refused", o.api("POST", "admin/promos/coupon", {"code": "TOOBIG", "type": "percent", "value": 95, "plans": [plan]})[0] == 422)

    def save(**kw):
        body = {"name": "Diwali 2026", "template": "diwali", "theme": "diwali", "heading": "Diwali Dhamaka {{year}}", "subheading": "Save {{discount}} with {{code}}", "badge": "FESTIVE OFFER", "cta": "Claim this offer", "couponCode": "DIWALI25", "planKey": plan,
                "banner": True, "popup": True, "popupDelay": 5, "exit": True, "idle": True, "idleSeconds": 20, "exitHeading": "Wait", "exitText": "Your offer is waiting", "idleHeading": "Still there?", "idleText": "Your code is ready", "pages": "all", "frequencyHours": 6, "priority": 0}
        body.update(kw); return o.api("POST", "admin/promos/save", body)
    pub = lambda q="": ctx.client().api("GET", "promo" + q)[1].get("promo")
    s, r1 = save(); a_id = r1.get("id")
    t.check("a new offer starts switched off and visitors see nothing", s == 200 and a_id and next(x for x in r1["campaigns"] if x["id"] == a_id)["status"] == "off" and pub() is None, (s, str(r1)[:200]))
    s, r = o.api("POST", "admin/promos/live", {"id": a_id}); v = None
    ctx.db().q("select 1")  # cache key moves on every change, so the next read is fresh
    v = pub()
    t.check("make live shows the offer to visitors with the coupon, discount and artwork filled in", s == 200 and v and v["code"] == "DIWALI25" and v["discount"] == "25% OFF" and v["theme"]["art"] == "🪔" and "Save 25% OFF with DIWALI25" in v["subheading"] and str(__import__("datetime").date.today().year) in v["heading"] and v["plan"] == plan, str(v)[:220])
    t.check("the public view exposes no owner fields", v and not ({"mode", "name", "priority", "couponCode"} & set(v)))
    t.check("the three triggers and their wording are included", v["triggers"]["popup"]["delay"] == 5 and v["triggers"]["exit"] and v["triggers"]["idle"]["seconds"] == 20 and v["variants"]["exit"]["heading"] == "Wait" and v["variants"]["idle"]["text"] == "Your code is ready", str(v["triggers"]))
    s, r2 = save(name="Holi", template="holi", theme="holi", heading="Holi Hai!", couponCode="")
    b_id = r2["id"]; o.api("POST", "admin/promos/live", {"id": b_id}); v2 = pub()
    st = {x["id"]: x["status"] for x in o.api("GET", "admin/promos")[1]["campaigns"]}
    t.check("switching to another offer is one click and the first one switches off", v2 and v2["heading"] == "Holi Hai!" and st[b_id] == "live" and st[a_id] == "off", (v2, st))
    t.check("an offer without a coupon shows no code", v2["code"] == "" and v2["discount"] == "")
    o.api("POST", "admin/promos/off", {"id": b_id}); t.check("switching off removes it from the site", pub() is None)

    # schedule rules
    save(name="Past", heading="Past offer", startsAt="2020-01-01T00:00", endsAt="2020-01-02T00:00")
    soon = save(name="Future", heading="Future offer", startsAt="2099-01-01T00:00", endsAt="2099-01-02T00:00")[1]["id"]
    run_now = save(name="Window", heading="Window offer", startsAt="2020-01-01T00:00", endsAt="2099-01-02T00:00")[1]["id"]
    for i in (soon, run_now): o.api("POST", "admin/promos/schedule", {"id": i})
    sts = {x["name"]: x["status"] for x in o.api("GET", "admin/promos")[1]["campaigns"]}
    t.check("a scheduled offer is upcoming before its start, running inside its window and ended after", sts["Future"] == "upcoming" and sts["Window"] == "scheduled" and sts["Past"] == "off", sts)
    v = pub(); t.check("visitors see the one running inside its window", v and v["heading"] == "Window offer")
    t.check("an offer keeps its end time but sends no countdown", v["endsAt"] and "countdown" not in v)
    o.api("POST", "admin/promos/live", {"id": a_id}); t.check("a live offer wins over a scheduled one", pub()["heading"].startswith("Diwali"))
    o.api("POST", "admin/promos/off", {"id": a_id}); o.api("POST", "admin/promos/off", {"id": run_now})
    t.check("a schedule needs a date", o.api("POST", "admin/promos/schedule", {"id": b_id})[0] == 422)

    # validation
    t.check("an unknown coupon is refused", save(couponCode="NOPE")[0] == 422)
    t.check("an unknown plan is refused", save(planKey="no-such-plan")[0] == 422)
    t.check("a picture must be an uploaded file or an https link", save(imageUrl="javascript:alert(1)")[0] == 422 and save(imageUrl="http://x.test/a.png")[0] == 422 and save(imageUrl="https://x.test/a.png")[0] == 200)
    t.check("the end must be after the start", save(startsAt="2030-01-02T00:00", endsAt="2030-01-01T00:00")[0] == 422)
    t.check("a bad theme is refused", save(theme="neon")[0] == 422)
    t.check("a coupon that has expired hides the offer", (o.api("POST", "admin/promos/coupon", {"code": "OLDONE", "type": "flat", "value": 50, "plans": [plan], "expiresAt": "2020-01-01"})[0] == 200) and (lambda i: (o.api("POST", "admin/promos/live", {"id": i}), pub() is None)[1])(save(name="Dead", couponCode="OLDONE")[1]["id"]))
    o.api("POST", "admin/promos/live", {"id": a_id}); v = pub(f"?offer=DIWALI25")
    t.check("an offer link (?offer=CODE) returns that offer", v and v["code"] == "DIWALI25")
    t.check("an unknown offer link falls back to the live offer", pub("?offer=NOSUCH")["code"] == "DIWALI25")
    t.check("customers cannot use the owner offer screen", (lambda c: (c.register("promo-cust@test.local"), c.api("GET", "admin/promos")[0])[1])(ctx.client()) == 403)

    # statistics and redemptions
    c = ctx.client(); c.register("promo-buyer@test.local")
    for k in ("shown", "shown", "click", "copy", "close"): c.api("POST", "promo/event", {"id": a_id, "kind": k})
    t.check("an unknown campaign id is ignored", c.api("POST", "promo/event", {"id": str(uuid.uuid4()), "kind": "shown"})[0] == 200 and ctx.db().q("select count(*) from promo_stats")[0][0] == 4)
    t.check("a bad event kind is refused", c.api("POST", "promo/event", {"id": a_id, "kind": "boom"})[0] == 422)
    s, q = c.api("POST", "checkout/quote", {"plan": plan, "coupon": "DIWALI25"}); t.check("the coupon from the offer prices the plan", s == 200 and q["discountPaise"] > 0 and q["totalPaise"] < q["subtotalPaise"], q)
    s, j = c.api("POST", "checkout/start", {"plan": plan, "coupon": "DIWALI25", "showInActivity": True}); c.api("POST", "checkout/verify", {"provider": "demo", "orderId": j["orderId"]})
    me = next(x for x in o.api("GET", "admin/promos")[1]["campaigns"] if x["id"] == a_id)["stats"]
    t.check("the owner sees seen, clicks, copies, orders and revenue per offer", me["shown"] == 2 and me["click"] == 1 and me["copy"] == 1 and me["close"] == 1 and me["orders"] == 1 and me["revenuePaise"] > 0, me)

    # recent-activity notice
    pa = c.api("GET", "public-activity")[1]
    t.check("the notice waits 90 seconds by default", pa["settings"]["delaySeconds"] == 90 and pa["settings"]["gapSeconds"] >= 20, pa["settings"])
    ctx.db().q("update profiles set city='pune', name='priya sharma' where owner_id=(select id from users where email='promo-buyer@test.local')")
    ctx.db().q("delete from cache")  # the notice list is cached for a minute
    msgs = [m for m in ctx.client().api("GET", "public-activity")[1]["messages"] if m.get("kind") == "purchase"]
    t.check("a buyer who opted in is shown by first name and city, with the plan and time", len(msgs) == 1 and msgs[0]["name"] == "Priya" and msgs[0]["city"] == "Pune" and msgs[0]["plan"] and msgs[0]["at"], msgs)
    t.check("no last name or email is ever sent", "sharma" not in str(msgs).lower() and "@" not in str(msgs))
    c2 = ctx.client(); c2.register("promo-quiet@test.local"); s, j = c2.api("POST", "checkout/start", {"plan": plan}); c2.api("POST", "checkout/verify", {"provider": "demo", "orderId": j["orderId"]})
    ctx.db().q("delete from cache")
    t.check("a buyer who did not tick the box is never shown", len([m for m in ctx.client().api("GET", "public-activity")[1]["messages"] if m.get("kind") == "purchase"]) == 1)
    s, d = o.api("GET", "admin/content/popup"); data = d["data"]; data["showPurchases"] = False
    sv = o.api("POST", "admin/content/popup", {"data": data, "revision": d["revision"]})[0]
    t.check("the owner can switch the real-purchase notices off", sv == 200 and not [m for m in ctx.client().api("GET", "public-activity")[1]["messages"] if m.get("kind") == "purchase"])
    s, g = o.api("GET", "admin/promos"); t.check("the owner sees the employer-names card, on by default with plain names", s == 200 and g["employers"]["enabled"] and "Infosys" in g["employers"]["names"], str(g.get("employers"))[:120])
    s, g = o.api("POST", "admin/promos/employers", {"enabled": True, "heading": "Where our customers apply", "names": ["Acme One", "Beta Two"]})
    pc = ctx.client().api("GET", "public-content")[1]["content"]["site"]["employers"]
    t.check("saved employer names reach the public page data", s == 200 and pc["names"] == ["Acme One", "Beta Two"] and pc["heading"] == "Where our customers apply", str(pc)[:120])
    t.check("the public home page has the disclaimer and the (hidden until filled) section", "not affiliated with, endorsed by or approved by" in ctx.client().page("/")[2])
