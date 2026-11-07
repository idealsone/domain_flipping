"""A paid customer's package name: never "Free" while a paid plan is active, even when the order's package key was renamed or retired."""
import json
import uuid
NAME = "Paid package name on Billing"


def run(ctx, t):
    db = ctx.db()
    uid = lambda e: db.q("select id from users where email=?", e)[0][0]

    def give(email, plan, limits):
        db.q("insert into purchases(id,owner_id,plan,amount_paise,status,provider,payment_reference,is_live,limits_json,access_days,purchased_at,access_until,created_at,updated_at) values(?,?,?,29900,'paid','phonepe',?,1,?,10,datetime('now'),datetime('now','+10 days'),datetime('now'),datetime('now'))",
             str(uuid.uuid4()), uid(email), plan, "pl-" + uuid.uuid4().hex[:8], json.dumps(limits))

    free = ctx.client(); free.register("pl-free@test.local")
    s, a = free.api("GET", "allowance")
    t.check("a free account still reads Free", s == 200 and a["planName"] == "Free" and not a["paid"], f"{s} {str(a)[:160]}")

    cases = [
        ("pl-jh@test.local", "job-hunt", {"type": "plan", "name": "Job Hunt", "rank": 2, "savedResumes": 5, "savedLetters": 5, "atsScans": 20}, "Job Hunt"),
        ("pl-old@test.local", "fresher-basic", {"type": "plan", "name": "Fresher Basic", "rank": 2, "savedResumes": 5, "savedLetters": 5, "atsScans": 20}, "Job Hunt"),
        ("pl-gone@test.local", "launch-offer", {"type": "plan", "name": "Launch Offer", "rank": 2, "savedResumes": 10, "savedLetters": 10, "atsScans": 20}, "Launch Offer"),
        ("pl-noname@test.local", "launch-offer", {"type": "plan", "rank": 2, "savedResumes": 10, "savedLetters": 10, "atsScans": 20}, "Launch Offer"),
    ]
    for email, plan, limits, want in cases:
        c = ctx.client(); c.register(email)
        give(email, plan, limits)
        s, a = c.api("GET", "allowance")
        t.check(f"{plan} order shows '{want}', not Free", s == 200 and a["paid"] and a["planName"] == want, f"{s} {a.get('planName')} paid={a.get('paid')}")
