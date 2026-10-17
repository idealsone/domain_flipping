"""Every public page loads, has no template placeholders or raw errors, and carries the theme switch."""
import re
NAME = "Public pages"
PAGES = ["/", "/templates.html", "/login", "/register", "/blog.html", "/about.html", "/contact.html",
         "/privacy.html", "/terms.html", "/refund.html", "/sitemap.xml", "/robots.txt"]


def run(ctx, t):
    c = ctx.client()
    for p in PAGES:
        s, h, body = c.page(p)
        t.check(f"{p} returns 200", s == 200, s)
        if p.endswith(".html") or p in ("/", "/login", "/register"):
            t.check(f"{p} has no placeholders", not re.search(r"\{\{|\$AUDIT_DIR|Undefined (variable|array key)|Whoops|Stack trace", body))
            t.check(f"{p} sets theme before paint", "cvtara-theme" in body)
    s, _, body = c.page("/robots.txt"); t.check("robots.txt points to the sitemap", "Sitemap:" in body and "cvtara.com" in body)
    s, _, body = c.page("/sitemap.xml"); t.check("sitemap lists cvtara pages", "<urlset" in body and "/blog" in body)
    for p in ("/admin-guide.html", "/preview/go/admin"):
        s, h, _ = c.raw("GET", p)
        t.check(f"{p} is not public", s in (302, 401, 403, 404) or ctx.preview, s)
    # sign-up shows password problems beside the password boxes, in plain words
    base = {"name": "Pw Test", "experienceLevel": "experienced", "profession": "Analyst", "category": "Finance", "terms": "1"}
    r = ctx.client()
    r.form("/register", dict(base, email="pw-short@test.local", password="short", password_confirmation="short"))
    _, _, body = r.page("/register")
    t.check("short sign-up password is explained under the password box",
            re.search(r'id="auPwErr"[^>]*>Use at least 12 characters', body) and 'aria-invalid="true"' in body and 'class="error"' not in body)
    r.form("/register", dict(base, email="pw-mismatch@test.local", password="LongEnough123!", password_confirmation="LongEnough124!"))
    _, _, body = r.page("/register")
    t.check("mismatched sign-up passwords are explained under the confirm box",
            re.search(r'id="auPw2Err"[^>]*>The two passwords don’t match', body) is not None and re.search(r'id="auPwErr"[^>]*hidden', body) is not None)
    t.check("the sign-up form keeps the name typed", 'value="Pw Test"' in body)
