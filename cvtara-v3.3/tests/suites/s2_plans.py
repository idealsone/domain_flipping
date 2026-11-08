"""Plan catalogue: Free, Quick Download ₹99, Job Hunt ₹299, Pro ₹599; services and credit packs are off sale until the owner turns them on."""
NAME = "Plans and pricing"


def run(ctx, t):
    c = ctx.client(); s, j = c.api("GET", "business-public")
    t.check("public plans endpoint", s == 200 and "plans" in j, s)
    plans = j.get("plans", {}) if s == 200 else {}
    for k, price, days in (("quick", 99, 7), ("job-hunt", 299, 30), ("pro", 599, 90)):
        p = plans.get(k, {})
        t.check(f"{k} costs Rs {price} for {days} days and is on sale", p.get("price") == price and p.get("accessDays") == days and p.get("sale") is True, p)
    t.check("Quick Download is 1 resume, no cover letters, bound to one resume", plans["quick"]["savedResumes"] == 1 and plans["quick"]["savedLetters"] == 0 and plans["quick"].get("oneResume") is True)
    t.check("Job Hunt is 5 + 5 + 20 ATS scans and marked most popular", (plans["job-hunt"]["savedResumes"], plans["job-hunt"]["savedLetters"], plans["job-hunt"]["atsScans"], plans["job-hunt"]["popular"]) == (5, 5, 20, True))
    t.check("Pro is 20 + 20 + 60 ATS scans and marked best value", (plans["pro"]["savedResumes"], plans["pro"]["savedLetters"], plans["pro"]["atsScans"], plans["pro"]["bestValue"]) == (20, 20, 60, True))
    for k, price, with_plan in (("expert-review", 499, 399), ("profile-boost", 699, 599)):
        p = plans.get(k, {})
        t.check(f"{k} is Rs {price} alone, Rs {with_plan} with a plan, off sale by default", p.get("price") == price and p.get("planPrice") == with_plan and p.get("sale") is False, p)
    kit = plans.get("complete-kit", {})
    t.check("Complete Job Kit is Rs 1,299, shown as worth Rs 1,797, off sale by default", kit.get("price") == 1299 and kit.get("mrp") == 1797 and kit.get("sale") is False, kit)
    t.check("the old fresher/professional plans are gone", not any(k in plans for k in ("fresher-basic", "fresher-standard", "fresher-pro", "pro-basic", "pro-standard", "pro-pro")))
    t.check("credit packs exist but are off sale", all(plans.get(k, {}).get("sale") is False for k in ("addon-10", "addon-20", "addon-30")))
    t.check("only Free costs nothing, and it cannot be bought", [k for k, v in plans.items() if v.get("price") == 0] == ["free"] and plans["free"]["name"] == "Free")
    s, _ = c.api("POST", "checkout/quote", {"plan": "free"}); t.check("the free plan is not purchasable", s in (401, 419, 422), s)
    u = ctx.client(); u.register("plans-a@test.local")
    for k in ("expert-review", "addon-10", "fresher-basic"):
        s, _ = u.api("POST", "checkout/quote", {"plan": k}); t.check(f"{k} cannot be bought while off sale", s == 422, s)
    s, q = u.api("POST", "checkout/quote", {"plan": "job-hunt"}); t.check("a quote for Job Hunt is Rs 299", s == 200 and q.get("totalPaise") == 29900, q)
    t.check("the checkout page shows the refund rule, not 'non-refundable'", "non-refundable" not in c.page("/checkout.html")[2].lower() and "7 days" in c.page("/checkout.html")[2])
