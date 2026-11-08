"""Site-wide SEO crawl used by the SEO suite (and by hand: python3 tests/lib/seo_audit.py BASE_URL).

Fetches every sitemap address plus every internal link found in the server-rendered HTML (no JavaScript), then
measures what search engines see: status codes and redirects, title and description length and uniqueness, H1,
canonical, robots, JSON-LD, breadcrumbs, inbound links (orphans), click depth from the home page, keyword density,
text repeated across many pages and near-duplicate pages.
"""
import html as H, json, re, sys, time, urllib.request, urllib.error
from collections import Counter, defaultdict, deque

SEO_RE = re.compile(r"^/(resume-examples|resume-templates|cover-letter-examples|interview-questions|resume-builder|resume|resume-words|ats-checker)/")
SKIP_LINK = re.compile(r"^/(register|login|workspace|admin|checkout|api/|export/|console/|preview|r/|forgot-password|reset-password)|\.(css|js|ico|png|svg|webp|jpg|webmanifest|xml|txt)$")
STOP = set("a an the and or of to in on for with by at from as is are be your you it this that these those can will not do if then than so how what why when which who".split())


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None


def fetcher(base, pause=0.0):
    opener = urllib.request.build_opener(NoRedirect)

    def fetch(path):
        if pause: time.sleep(pause)
        try:
            with opener.open(urllib.request.Request(base + path, headers={"User-Agent": "seo-audit"}), timeout=30) as r:
                return r.status, dict(r.headers), r.read().decode("utf8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers or {}), (e.read() or b"").decode("utf8", "replace")
    return fetch


def main_text(body, core=False):
    """Visible text of <main>. core=True also drops the parts every page shares on purpose (trust line, closing call to
    action, related-link lists), which search engines treat as boilerplate; used for the near-duplicate measure."""
    m = re.search(r"<main\b.*?</main>", body, re.S)
    chunk = m.group(0) if m else body
    if core:
        chunk = re.sub(r'<p class="sp-trust">.*?</p>|<aside class="sp-final".*?</aside>|<nav class="sp-related".*?</nav>', " ", chunk, flags=re.S)
    chunk = re.sub(r"<script.*?</script>|<style.*?</style>|<nav class=\"sp-crumbs\".*?</nav>|<aside class=\"sp-toc\".*?</aside>", " ", chunk, flags=re.S)
    chunk = re.sub(r"</(p|li|h\d|summary|blockquote|figcaption|b|span|div|a)>", ". ", chunk)
    return H.unescape(re.sub(r"<[^>]+>", " ", chunk))


def words(text):
    return re.findall(r"[a-z0-9][a-z0-9'+#.-]*[a-z0-9+#]|[a-z0-9]", text.lower())


def sentences(text):
    return [s.strip().lower() for s in re.split(r"(?<=[.?:])\s+|\s{2,}", text) if len(s.split()) >= 6]


def primary_phrase(title):
    t = re.split(r"\s+\|\s*|\s+-\s+|:\s", title.replace(" | CVTara", ""))[0]
    t = re.sub(r"&\s*(format|answers)\b|\b(example|examples|format|and answers)\b", "", t, flags=re.I)
    head = re.split(r"\s+(?:in|for)\s+", t)[0]
    return " ".join(words(head if len(words(head)) > 1 else re.split(r"\s+in\s+", t)[0]))


def phrase_density(ws, phrase):
    p = phrase.split()
    if not p or not ws: return 0.0
    n = sum(1 for i in range(len(ws) - len(p) + 1) if ws[i:i + len(p)] == p)
    return n * len(p) / len(ws)


def top_ngram(ws):
    best = (0.0, "")
    for n in (2, 3):
        c = Counter(tuple(ws[i:i + n]) for i in range(len(ws) - n + 1))
        for g, k in c.most_common(30):
            if g[0] in STOP or g[-1] in STOP or k < 3: continue
            d = k * n / len(ws)
            if d > best[0]: best = (d, " ".join(g))
    return best


def shingles(ws, k=5):
    return {hash(tuple(ws[i:i + k])) for i in range(len(ws) - k + 1)}


def audit(fetch, log=None):
    say = log or (lambda *a: None)
    st, _, robots = fetch("/robots.txt")
    st2, _, sm = fetch("/sitemap.xml")
    locs = [re.sub(r"^https?://[^/]+", "", u) for u in re.findall(r"<loc>([^<]+)</loc>", sm)]
    R = dict(robots=robots, robots_status=st, sitemap_status=st2, sitemap=locs, pages={}, links={}, problems=[])
    inbound = defaultdict(set)
    queue, seen = deque(["/"] + locs), set()
    graph = {}
    while queue:
        p = queue.popleft()
        if p in seen: continue
        seen.add(p)
        s, hd, body = fetch(p)
        if len(seen) % 100 == 0: say(f"  crawled {len(seen)}")
        R["links"][p] = (s, hd.get("Location", ""))
        if s != 200 or "text/html" not in hd.get("Content-Type", "text/html"): continue
        out = {h for h in re.findall(r'<a\b[^>]*href="(/[^"#?]*)', body) if not SKIP_LINK.search(h)}
        graph[p] = out
        for h in out:
            if h != p: inbound[h].add(p)
            if h not in seen: queue.append(h)
        R["pages"][p] = body
    # Click depth from the home page through server-rendered links only.
    depth, dq = {"/": 0}, deque(["/"])
    while dq:
        p = dq.popleft()
        for h in graph.get(p, ()):
            if h not in depth: depth[h] = depth[p] + 1; dq.append(h)
    R["inbound"], R["depth"] = inbound, depth
    return R


def analyse(R):
    """Turns the crawl into numbers and a list of problems. Returns (metrics, problems)."""
    P, problems = [], []
    locs = R["sitemap"]
    titles, descs = defaultdict(list), defaultdict(list)
    for p in locs:
        s, loc = R["links"].get(p, (None, ""))
        if s != 200: problems.append(("status", p, s, loc)); continue
    bad_links = [(p, s, loc) for p, (s, loc) in R["links"].items() if s not in (200,)]
    seo_pages = {}
    for p in locs:
        body = R["pages"].get(p)
        if body is None: continue
        title = H.unescape((re.search(r"<title>(.*?)</title>", body, re.S) or [None, ""])[1]).strip()
        dm = re.search(r'<meta name="description"[^>]*content="([^"]*)"', body)
        desc = H.unescape(dm.group(1)) if dm else ""
        titles[title].append(p); descs[desc].append(p)
        f = []
        if not title or len(title) > 60: f.append(f"title length {len(title)}")
        if not (50 <= len(desc) <= 160): f.append(f"description length {len(desc)}")
        if len(re.findall(r"<h1\b", body)) != 1: f.append("h1 count %d" % len(re.findall(r"<h1\b", body)))
        cm = re.findall(r'<link rel="canonical" href="([^"]+)"', body)
        if len(cm) != 1 or not re.sub(r"^https?://[^/]+", "", cm[0]) == p: f.append(f"canonical {cm}")
        rb = re.findall(r'<meta name="robots" content="([^"]+)"', body)
        if rb != ["index,follow"]: f.append(f"robots {rb}")
        lds = []
        for x in re.findall(r'<script type="application/ld\+json">(.*?)</script>', body, re.S):
            try: lds.append(json.loads(x))
            except ValueError: f.append("invalid JSON-LD")
        if SEO_RE.match(p):
            types = {x.get("@type") for x in lds if isinstance(x, dict)}
            if "BreadcrumbList" not in types: f.append("no BreadcrumbList")
            for x in lds:
                if isinstance(x, dict) and x.get("@type") == "BreadcrumbList":
                    items = [re.sub(r"^https?://[^/]+", "", i["item"]) for i in x["itemListElement"]]
                    if items[-1] != p: f.append("breadcrumb does not end on the page")
                    if any(R["links"].get(i, (0,))[0] != 200 for i in items): f.append("breadcrumb link not 200")
            main = re.search(r"<main\b.*?</main>", body, re.S)
            m = main.group(0) if main else ""
            if re.search(r'style="[^"]*(display:\s*none|visibility:\s*hidden|font-size:\s*0)|\shidden[\s>]', m): f.append("hidden text in main")
            text = main_text(body); ws = words(text)
            seo_pages[p] = dict(title=title, words=ws, core=words(main_text(body, True)), sents=sentences(text), links={h for h in re.findall(r'href="(/[^"#?]*)"', m)},
                                cta=bool(re.search(r'data-seo-cta|class="sp-cta"', m)))
        if f: problems.append(("page", p, f))
    for t, ps in titles.items():
        if len(ps) > 1: problems.append(("duplicate title", t, ps[:4]))
    for d, ps in descs.items():
        if len(ps) > 1: problems.append(("duplicate description", d[:60], ps[:4]))
    for p, s, loc in bad_links:
        problems.append(("link", p, s, loc, sorted(R["inbound"].get(p, ()))[:2]))
    orphans = [p for p in locs if not (R["inbound"].get(p, set()) - {p})]
    for p in orphans: problems.append(("orphan", p))
    unreachable = [p for p in locs if p not in R["depth"]]
    deep = [p for p in locs if R["depth"].get(p, 99) > 4]
    # Content quality on the programmatic pages.
    sent_pages = Counter()
    for v in seo_pages.values():
        for s in set(v["sents"]): sent_pages[s] += 1
    n = max(1, len(seo_pages))
    common = {s for s, k in sent_pages.items() if k > max(25, n * 0.15)}
    dens, tops, boiler, linkc = [], [], [], []
    for p, v in seo_pages.items():
        ws = v["words"]
        d = phrase_density(ws, primary_phrase(v["title"]))
        t = top_ngram(ws)
        b = sum(len(s.split()) for s in set(v["sents"]) if s in common) / max(1, len(ws))
        hub_links = [h for h in v["links"] if SEO_RE.match(h) and h != p]
        dens.append((d, p)); tops.append((t[0], t[1], p)); boiler.append((b, p)); linkc.append((len(hub_links), p))
        if d > 0.03: problems.append(("keyword density", p, round(d * 100, 1), primary_phrase(v["title"])))
        if t[0] > 0.04: problems.append(("repeated phrase", p, round(t[0] * 100, 1), t[1]))
        if len(hub_links) < 4: problems.append(("few internal links", p, len(hub_links)))
        if not v["cta"]: problems.append(("no product CTA", p))
    # Near duplicates: compare pages of the same type (same first two path parts for /resume/ pages).
    sh = {p: shingles(v["core"]) for p, v in seo_pages.items()}
    groups = defaultdict(list)
    for p in seo_pages:
        parts = p.strip("/").split("/")
        groups[(parts[0], len(parts))].append(p)
    dup = []
    for g in groups.values():
        for i, a in enumerate(g):
            best = (0.0, "")
            for b in g:
                if a == b: continue
                inter = len(sh[a] & sh[b])
                if not inter: continue
                j = inter / len(sh[a] | sh[b])
                if j > best[0]: best = (j, b)
            dup.append((best[0], a, best[1]))
    noisy = [x for x in dup if x[0] > 0.6]
    for j, a, b in noisy: problems.append(("near duplicate", a, b, round(j, 2)))
    avg = lambda xs: sum(xs) / max(1, len(xs))
    metrics = dict(
        sitemap=len(locs), crawled=len(R["links"]), html_pages=len(R["pages"]), seo_pages=len(seo_pages),
        non_200_links=len(bad_links), orphans=len(orphans), unreachable_from_home=len(unreachable), deeper_than_4=len(deep),
        dup_titles=sum(1 for ps in titles.values() if len(ps) > 1), dup_descriptions=sum(1 for ps in descs.values() if len(ps) > 1),
        max_primary_density=round(max(dens)[0] * 100, 2) if dens else 0, avg_primary_density=round(avg([d for d, _ in dens]) * 100, 2),
        pages_over_3pct=sum(1 for d, _ in dens if d > 0.03), max_top_phrase=round(max(tops)[0] * 100, 2) if tops else 0,
        avg_boilerplate_share=round(avg([b for b, _ in boiler]) * 100, 1), common_sentences=len(common),
        max_similarity=round(max(dup)[0], 2) if dup else 0, avg_similarity=round(avg([d[0] for d in dup]), 2), pages_over_60pct_similar=len(noisy),
        avg_internal_links=round(avg([c for c, _ in linkc]), 1), min_internal_links=min(linkc)[0] if linkc else 0,
        avg_words=round(avg([len(v["words"]) for v in seo_pages.values()])), min_words=min((len(v["words"]), p) for p, v in seo_pages.items())[0] if seo_pages else 0,
        problems=len(problems))
    extra = dict(top_density=sorted(dens, reverse=True)[:5], top_phrase=sorted(tops, reverse=True)[:5], most_similar=sorted(dup, reverse=True)[:5],
                 common_sentences=sorted(((sent_pages[s], s) for s in common), reverse=True)[:15])
    return metrics, problems, extra


if __name__ == "__main__":
    base = sys.argv[1].rstrip("/")
    R = audit(fetcher(base, float(sys.argv[2]) if len(sys.argv) > 2 else 0), log=print)
    m, probs, extra = analyse(R)
    print(json.dumps(m, indent=1))
    kinds = Counter(p[0] for p in probs)
    print(kinds)
    for k in kinds:
        for p in [x for x in probs if x[0] == k][:6]: print("  ", p)
    for k, v in extra.items():
        print(k)
        for x in v: print("   ", x)
    print("robots:", R["robots"])
