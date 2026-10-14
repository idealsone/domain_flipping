"""Plan catalogue: three plans per level, add-ons, '1 premium primary resume', no free plan."""
NAME = "Plans and pricing"


def run(ctx, t):
    c = ctx.client(); s, j = c.api("GET", "business-public")
    t.check("public plans endpoint", s == 200 and "plans" in j, s)
    plans = j.get("plans", {}) if s == 200 else {}
    for k, price in {"fresher-basic": 299, "fresher-standard": 399, "fresher-pro": 499, "pro-basic": 399, "pro-standard": 499, "pro-pro": 599}.items():
        t.check(f"{k} costs Rs {price}", plans.get(k, {}).get("price") == price, plans.get(k, {}).get("price"))
    for k, price in {"addon-10": 149, "addon-20": 249, "addon-30": 399}.items():
        t.check(f"{k} costs Rs {price}", plans.get(k, {}).get("price") == price, plans.get(k, {}).get("price"))
    t.check("only the baseline 'Normal user' costs nothing, and it cannot be bought", [k for k, v in plans.items() if v.get("price") == 0] == ["free"])
    t.check("baseline is named Normal user", plans.get("free", {}).get("name") == "Normal user", plans.get("free", {}).get("name"))
    s, _ = c.api("POST", "checkout/quote", {"plan": "free"}); t.check("baseline plan is not purchasable", s in (401, 419, 422), s)
    for page in ("/", "/checkout.html"):
        t.check(f"{page} sells the 1 premium primary resume in every plan", "premium primary resume" in c.page(page)[2].lower())
    t.check("saved resumes + primary = 11 for Fresher Basic", plans.get("fresher-basic", {}).get("savedResumes") == 10)
    s, _ = c.api("POST", "checkout/quote", {"plan": "single"}); t.check("legacy plan key is rejected", s in (401, 419, 422), s)
