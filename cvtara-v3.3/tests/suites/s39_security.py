"""Pre-launch security pass: the owner sign-in needs an emailed code after the password (production mode, email on),
spread-out password guessing on the owner address is stopped, and busy endpoints have per-account caps."""
import re
NAME = "Security hardening (owner sign-in code, flood caps)"
PW = "OwnerPassw0rd!!x"


def run(ctx, t):
    from lib.env import Instance
    from lib.http import Client
    from lib.db import Db
    inst = Instance(ctx.app, ctx.public, preview=False).start()
    try:
        inst.artisan("tinker", "--execute", "$u=new App\\Models\\User(); $u->forceFill(['name'=>'Owner','email'=>'owner@test.local','password'=>Hash::make('" + PW + "'),'is_admin'=>true,'email_verified_at'=>now(),'referral_code'=>'ownercode000039'])->save();")
        db = Db(inst.db)
        db.q("insert or replace into site_settings(key,value_json,revision,created_at,updated_at) values('integrations','{\"payments\":false,\"email\":true,\"ai\":false,\"analytics\":false,\"grammar\":false}',1,datetime('now'),datetime('now'))")
        code = lambda: re.search(r"\[code: (\d{6})\]", db.q("select body from mail_outbox where subject like '%owner sign-in code%' order by created_at desc, rowid desc limit 1")[0][0]).group(1)
        o = Client(inst.base); s, h, _ = o.login("owner@test.local", PW, path="/sanaulla")
        t.check("the right password alone does not open the console", s == 302 and not h.get("Location", "").endswith("/admin.html") and o.raw("GET", "/admin.html", None, {"Accept": "text/html"})[0] == 302, h.get("Location"))
        s, _, page = o.page("/sanaulla")
        t.check("the owner page now asks for the emailed 6-digit code", s == 200 and 'name="code"' in page and "owner address" in page, s)
        t.check("the code was emailed to the owner", len(code()) == 6)
        s, h, _ = o.form("/sanaulla", {"code": "000000" if code() != "000000" else "111111"})
        t.check("a wrong code is refused", o.raw("GET", "/admin.html", None, {"Accept": "text/html"})[0] == 302)
        s, h, _ = o.form("/sanaulla", {"code": code()})
        t.check("password + right code lands in the console", s == 302 and h.get("Location", "").endswith("/admin.html"), h.get("Location"))
        t.check("console opens", o.raw("GET", "/admin.html", None, {"Accept": "text/html"})[0] == 200)
        s, j = o.api("POST", "admin/providers", {"service": "email", "values": {"provider": "smtp", "fromEmail": "a@test.local"}})
        t.check("signing in with the code unlocks sensitive saves for the hour (no second code)", "Verify your email code" not in str(j), str(j)[:160])
        x = Client(inst.base); x.login("owner@test.local", PW, path="/sanaulla")
        for _ in range(6): x.form("/sanaulla", {"code": "123456"})
        s, _, page = x.page("/sanaulla")
        t.check("five wrong codes end the attempt (back to password)", 'name="code"' not in page and 'name="password"' in page)
        r = Client(inst.base); r.login("owner@test.local", PW, path="/sanaulla"); s, _, page = r.page("/sanaulla?restart=1")
        t.check("'sign in again' goes back to the password form", 'name="password"' in page)
        c = Client(inst.base); c.register("flood@test.local")
        codes = [c.api("POST", "resumes/import", {"text": "Jane Doe\nSoftware engineer with five years of experience building web apps and APIs.\nExperience\nAcme 2020-2024"})[0] for _ in range(22)]
        t.check("resume uploads are capped per account (20 in 10 minutes)", 429 in codes and codes.index(429) >= 20, codes)
        s, _, b = Client(inst.base).raw("GET", "/.well-known/security.txt")
        t.check("security.txt names a contact", s == 200 and b"Contact: mailto:" in b, s)
    finally:
        inst.stop()
