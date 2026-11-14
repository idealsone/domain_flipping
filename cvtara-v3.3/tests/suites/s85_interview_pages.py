"""3.77.0 longer public interview pages (owner plan item 9, 13-14 Nov 2026): each /interview-questions/<role>/ page adds about
10 questions from the CVTara question bank, by experience level and interview round, with how to answer, worded with the
page's own job title, and links to practise them. Pages about a kind of search (freshers, Gulf jobs) get none."""
import re
NAME = "3.77.0 longer interview pages"


def run(ctx, t):
    if not ctx.preview: return
    from lib.env import Instance
    from lib.http import Client
    inst = Instance(ctx.app, ctx.public, preview=True).start()
    try:
        c = Client(inst.base)
        s, h, b = c.raw("GET", "/interview-questions/chef/")
        t.check("before the owner approves the bank, the page has no bank questions", s == 200 and "more-questions" not in b.decode("utf-8", "ignore"), s)
        o = Client(inst.base); o.raw("GET", "/preview/go/admin"); o.page("/admin.html")
        o.api("POST", "admin/interviews/bank/status", {"all": True, "status": "approved"})
        s, h, b = c.raw("GET", "/interview-questions/accountant/")
        html = b.decode("utf-8", "ignore")
        sec = html[html.find('id="more-questions"'):] if 'id="more-questions"' in html else ""
        qs = re.findall(r"<h4>(.*?)</h4>", sec)
        t.check("the page adds about 10 bank questions", s == 200 and 9 <= len(qs) <= 10, (s, len(qs)))
        t.check("grouped by experience level", all(x in sec for x in ("Freshers and up to 3 years", "3 to 8 years of experience", "8 years or more")))
        t.check("worded with the page's own job title and the role's skills", any("Accountant" in q for q in qs) and not any("Accounts Executive" in q for q in qs) and not any("{" in q for q in qs), qs[:4])
        t.check("each question has its interview round and how to answer", sec.count("How to answer:") >= 9 and re.search(r"Behavioural|Situational|HR round|Role skills", sec), sec[:200])
        t.check("the questions link to practice", "/register?next=/workspace.html?view=interview" in sec and "Practise these questions" in sec)
        t.check("no duplicate of the page's own notice-period or why-hire-you questions", not any(re.search(r"notice period|Why should we hire you", q) for q in qs), qs)
        s, h, b = c.raw("GET", "/interview-questions/accountant/")
        t.check("the same questions on every visit", re.findall(r"<h4>(.*?)</h4>", b.decode("utf-8", "ignore")) == re.findall(r"<h4>(.*?)</h4>", html))
        s, h, b = c.raw("GET", "/interview-questions/software-engineer/")
        sw = b.decode("utf-8", "ignore")
        qs2 = re.findall(r"<h4>(.*?)</h4>", sw[sw.find('id="more-questions"'):]) if 'id="more-questions"' in sw else []
        t.check("a role with no close bank role gets HR-round and career questions only", s == 200 and len(qs2) >= 8 and any("Software Engineer" in q for q in qs2), (s, qs2[:3]))
        s, h, b = c.raw("GET", "/interview-questions/freshers/")
        t.check("pages about a kind of search get no bank questions", s in (200, 404) and 'id="more-questions"' not in b.decode("utf-8", "ignore"), s)
        t.check("public wording never mentions AI", not re.search(r"\b(AI|Claude|Anthropic|GPT|OpenAI)\b", re.sub(r"<script.*?</script>", "", sec, flags=re.S)))
    finally:
        inst.stop()
