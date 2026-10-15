"""Hidden owner sign-in addresses (/afsar, /sanaulla) and the private installer address."""
import os, re
NAME = "Owner sign-in addresses"
PW = "OwnerPassw0rd!!x"


def run(ctx, t):
    from lib.env import Instance
    from lib.http import Client
    inst = Instance(ctx.app, ctx.public, preview=False).start()
    try:
        inst.artisan("tinker", "--execute", "$u=new App\\Models\\User(); $u->forceFill(['name'=>'Owner','email'=>'owner@test.local','password'=>Hash::make('" + PW + "'),'is_admin'=>true,'email_verified_at'=>now(),'referral_code'=>'ownercode000002'])->save();")
        c = Client(inst.base); c.register("cust-op@test.local")
        for slug in ("afsar", "sanaulla"):
            s, h, b = Client(inst.base).raw("GET", "/" + slug, None, {"Accept": "text/html"})
            t.check(f"/{slug} shows the owner sign-in page", s == 200 and b"Owner sign in" in b and "noindex" in h.get("X-Robots-Tag", ""), f"{s}")
        s, h, _ = Client(inst.base).login("owner@test.local", PW, path="/login")
        o = Client(inst.base); o.page("/admin.html")
        t.check("owners cannot sign in on the public /login page", o.raw("GET", "/admin.html", None, {"Accept": "text/html"})[0] == 302 and "/admin" not in (h.get("Location") or ""), h.get("Location"))
        x = Client(inst.base); x.login("owner@test.local", PW, path="/login"); s, h2, _ = x.raw("GET", "/admin.html", None, {"Accept": "text/html"}); t.check("... and /login did not open the console", s == 302 and h2.get("Location", "").rstrip("/") in ("", inst.base))
        bad = Client(inst.base); s, h, _ = bad.login("owner@test.local", "wrong-password-123", path="/afsar"); t.check("wrong password stays on the owner page", s == 302 and "/admin" not in h.get("Location", ""), h.get("Location"))
        cu = Client(inst.base); s, h, _ = cu.login("cust-op@test.local", "TestPassw0rd!!x", path="/afsar"); t.check("a customer cannot sign in on the owner page", "/admin" not in h.get("Location", "") and cu.raw("GET", "/admin.html", None, {"Accept": "text/html"})[0] == 302)
        for slug in ("afsar", "sanaulla"):
            ow = Client(inst.base); s, h, _ = ow.login("owner@test.local", PW, path="/" + slug)
            t.check(f"owner signs in at /{slug} and lands in the console", s == 302 and h.get("Location", "").endswith("/admin.html"), h.get("Location"))
            s, _, b = ow.raw("GET", "/admin.html", None, {"Accept": "text/html"}); t.check(f"console opens after /{slug} sign-in", s == 200 and b"console/ops.js" in b, s)
        pub = Client(inst.base)
        for p in ("/robots.txt", "/", "/login", "/sitemap.xml"):
            body = pub.raw("GET", p, None, {"Accept": "text/html"})[2].decode("utf8", "ignore")
            t.check(f"{p} does not mention the owner addresses", "afsar" not in body.lower() and "sanaulla" not in body.lower(), p)
    finally:
        inst.stop()
    # installer address (Apache rules are static text here; the server-side check runs on Hostinger)
    ht = open(os.path.join(ctx.public, ".htaccess")).read(); idx = open(os.path.join(ctx.public, "index.php")).read(); ins = open(os.path.join(ctx.public, "install.php")).read()
    t.check("installer is served at /setup/afsar", "RewriteRule ^setup/afsar/?$ install.php" in ht)
    t.check("install.php cannot be opened directly", re.search(r"THE_REQUEST.*install", ht) is not None and "R=404" in ht)
    t.check("an uninstalled site sends visitors to the private setup address", "/setup/afsar" in idx and "/install.php" not in idx)
    t.check("a finished install sends visitors home", "Location: /'" in ins.replace('"', "'") or "header('Location: /'," in ins)
