#!/usr/bin/env python3
"""Spam-score style check for CVTara emails.

Usage:  python3 tools/mail_lint.py DIR        (DIR from `php artisan cvtara:mail-preview DIR`)

Each NAME.html needs a NAME.txt whose first line is the subject. Checks what filters and readers look at:
subject wording, a real text part, text-to-link balance, no image-only mail, no link shorteners or scripts,
one main button, a preview line, an unsubscribe link on marketing mail (and none on security codes), no leftover
placeholders, and a size under Gmail's 102 KB clipping limit. Prints a score per mail (0 is best) and exits 1 when
any mail has an error.
"""
import html as htmllib, os, re, sys

SPAM_SUBJECT = re.compile(r"\bfree\b\s*!|100% free|guarantee|act now|limited time|urgent|winner|\bcash\b|click here|buy now|risk[- ]free|earn money|"
                          r"credit card|cheap|lowest price|best price|% off|\bdeal\b|congratulations|you have been selected|last chance|hurry|expires today|only \d+ left", re.I)
SPAM_BODY = re.compile(r"100% free|guaranteed|act now|click here|risk[- ]free|earn money|you have been selected|hurry|only \d+ left|\bwinner\b|\$\$\$|!!!", re.I)
SHORTENERS = re.compile(r"https?://(www\.)?(bit\.ly|tinyurl\.com|goo\.gl|t\.co|ow\.ly|is\.gd|cutt\.ly|rb\.gy|shorturl\.at|tiny\.cc|buff\.ly|rebrand\.ly)/", re.I)
ACRONYMS = {"ATS", "PDF", "CV", "PIN", "OTP", "TPO", "HR", "UPI", "GST", "INR", "ORD", "AI", "IT", "CSV", "FAQ", "KB"}
MARKETING = re.compile(r"^(nurture-\d+|digest|abandoned|blog|outreach-.+|campaign.*)$")
SECURITY = {"verify", "reset", "security"}


def visible_text(h):
    h = re.sub(r"(?is)<(style|script|title)[^>]*>.*?</\1>", " ", h)
    h = re.sub(r'(?is)<div style="display:none[^>]*>.*?</div>', " ", h)  # preview line
    return re.sub(r"\s+", " ", htmllib.unescape(re.sub(r"<[^>]+>", " ", h))).strip()


def lint(name, h, t):
    subject, _, text = t.partition("\n")
    err, warn = [], []
    s = subject.strip()
    if not s: err.append("no subject")
    if len(s) > 78: warn.append(f"subject is long ({len(s)} characters)")
    if SPAM_SUBJECT.search(s): err.append(f"spam-trigger wording in subject: {SPAM_SUBJECT.search(s).group(0)!r}")
    caps = [w for w in re.findall(r"\b[A-Z]{4,}\b", s) if w not in ACRONYMS]
    if caps: err.append(f"capitals in subject: {caps}")
    if re.search(r"[!?]{2,}|!\s*$", s): warn.append("exclamation marks in subject")
    if re.search(r"[\U0001F300-\U0001FAFF]", s): warn.append("emoji in subject")
    if len(text.strip()) < 80: err.append("plain-text part is missing or too short")
    vis = visible_text(h)
    words = len(vis.split())
    links = re.findall(r'<a\s[^>]*href="([^"]+)"', h, re.I)
    web = [u for u in links if u.startswith("http")]
    imgs = re.findall(r"<img\b", h, re.I)
    if imgs and words < 60: err.append("mostly images: too little text for the images")
    if web and words / len(web) < 12: err.append(f"too many links for the text ({len(web)} links, {words} words)")
    elif web and words / len(web) < 20: warn.append(f"many links for the text ({len(web)} links, {words} words)")
    if SHORTENERS.search(h) or SHORTENERS.search(text): err.append("link shortener used")
    if re.search(r"<script|<form|javascript:", h, re.I): err.append("script or form in the mail")
    if "{{" in h or "{{" in t: err.append("placeholder left unfilled")
    if SPAM_BODY.search(vis): err.append(f"spam-trigger wording in the mail: {SPAM_BODY.search(vis).group(0)!r}")
    if len(h.encode()) > 100_000: err.append(f"HTML is {len(h.encode()) // 1024} KB (Gmail clips mail over 102 KB)")
    if h.count('class="cv-btn"') > 1: warn.append("more than one main button")
    if not name.startswith("outreach-colleges") and 'display:none' not in h: warn.append("no preview line (preheader)")
    unsub = re.search(r"unsubscribe", h, re.I) and re.search(r"unsubscribe", text, re.I)
    if MARKETING.match(name) and not unsub: err.append("marketing mail without an unsubscribe link in both parts")
    if name in SECURITY and re.search(r">\s*Unsubscribe\s*<", h): err.append("security mail carries an unsubscribe link")
    if name in SECURITY and re.search(r"\b(offer|coupon|% OFF)\b", vis, re.I): err.append("security mail carries an offer")
    if 'lang="' not in h: warn.append("no language set on the HTML")
    return err, warn, words, len(web)


def main(d):
    names = sorted(f[:-5] for f in os.listdir(d) if f.endswith(".html"))
    bad = 0
    print(f"{'mail':28} score words links  findings")
    for n in names:
        h = open(os.path.join(d, n + ".html"), encoding="utf8").read()
        tp = os.path.join(d, n + ".txt")
        t = open(tp, encoding="utf8").read() if os.path.exists(tp) else ""
        err, warn, words, links = lint(n, h, t)
        score = 5 * len(err) + len(warn)
        bad += bool(err)
        print(f"{n:28} {score:5} {words:5} {links:5}  " + "; ".join([f"ERROR {e}" for e in err] + warn))
    print(f"\n{len(names)} mails checked, {bad} with errors")
    return 1 if bad or not names else 0


if __name__ == "__main__":
    if len(sys.argv) != 2: print(__doc__); sys.exit(2)
    sys.exit(main(sys.argv[1]))
