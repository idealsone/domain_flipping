"""Every public page loads, has no template placeholders or raw errors, and carries the theme switch."""
import re
import urllib.parse
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
    s, h, body = c.raw("GET", "/no-such-page-here")
    t.check("an unknown address answers 404 with the CVTara page and links onward", s == 404 and "We could not find that page" in body.decode() and 'href="/resume-examples/"' in body.decode(), s)
    for p in ("/admin-guide.html", "/preview/go/admin"):
        s, h, _ = c.raw("GET", p)
        t.check(f"{p} is not public", s in (302, 401, 403, 404) or ctx.preview, s)
    # reset shows password problems beside the password box, in plain words (sign-up is covered in s21, which owns the sign-up rate limit)
    r = ctx.client()
    _, _, body = r.page("/reset-password/abc?email=pw-reset@test.local"); tok = re.search(r'name="_token" value="([^"]+)"', body)
    r._post_raw("/reset-password", urllib.parse.urlencode({"_token": tok.group(1) if tok else "", "token": "abc", "email": "pw-reset@test.local", "password": "short", "password_confirmation": "short"}).encode(), {"Referer": r.base + "/reset-password/abc"})
    _, _, body = r.page("/reset-password/abc?email=pw-reset@test.local")
    t.check("short reset password is explained in plain words", '<span class="fe">Use at least 12 characters' in body)
