"""Production mode: every high-impact owner write needs the email-code confirmation (F-01)."""
NAME = "Owner step-up confirmation (production mode)"
PW = "OwnerPassw0rd!!x"


def run(ctx, t):
    from lib.env import Instance
    ctx.inst.stop() if False else None
    inst = Instance(ctx.app, ctx.public, preview=False).start()
    try:
        r = inst.artisan("tinker", "--execute", "$u=new App\\Models\\User(); $u->forceFill(['name'=>'Owner','email'=>'owner@test.local','password'=>Hash::make('" + PW + "'),'is_admin'=>true,'email_verified_at'=>now(),'referral_code'=>'ownercode000001'])->save();")
        from lib.http import Client
        o = Client(inst.base); s, h, _ = o.login("owner@test.local", PW, path="/afsar"); t.check("owner can sign in", s in (302, 303), s)
        o.page("/admin.html")
        t.check("owner can read plans", o.api("GET", "admin/plans")[0] == 200)
        plans = o.api("GET", "admin/plans")[1].get("plans", {})
        bc = o.api("GET", "admin/business-controls")[1].get("data", {})
        writes = {"plans": ("admin/plans", {"plans": {"job-hunt": plans.get("job-hunt", {})}}),
                  "business controls/coupons": ("admin/business-controls", bc),
                  "payment provider keys": ("admin/providers", {"service": "payments", "values": {"provider": "razorpay", "razorpayKeyId": "rzp_live_x"}}),
                  "email provider": ("admin/providers", {"service": "email", "values": {"provider": "resend", "resendApiKey": "re_x"}}),
                  "integrations content": ("admin/content/integrations", {"data": {}, "revision": 0})}
        for label, (path, body) in writes.items():
            s, j = o.api("POST", path, body)
            t.check(f"{label}: blocked without confirmation", s == 422 and "Verify your email code" in str(j), f"{s} {str(j)[:120]}")
        t.check("owners unlock for an hour, so a run of saves asks once", "unlock for 1 hour" in str(j), j)
        # Plain text saves (email wording, legal pages, blog, Expert Review auto switch) never ask for a code.
        for key in ("blog", "emails", "legal"):
            s, j = o.api("POST", f"admin/content/{key}", {"data": {}, "revision": 0}); t.check(f"{key} content is not step-up protected (normal editing)", "Verify your email code" not in str(j), j)
        s, j = o.api("POST", "admin/services/settings", {"autoReview": False}); t.check("Expert Review auto switch needs no code", s == 200, j)
    finally:
        inst.stop()
