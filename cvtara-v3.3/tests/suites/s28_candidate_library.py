"""Candidate-type library and guided picker: five resume designs and five cover letters for every candidate type, every role,
industry and template style on the site gets five designs with sample content, and the gallery and SEO pages link into the picker."""
import json, os, re, shutil, subprocess, sys
NAME = "Candidate library and guided picker"
TYPES = ["fresher", "experienced", "freelance", "career-change", "senior", "internship"]


def run(ctx, t):
    root = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    pub, data = os.path.join(root, "public_html"), os.path.join(root, "app", "resources", "data")
    cat = json.load(open(os.path.join(data, "catalog.json"), encoding="utf8"))
    seo = json.load(open(os.path.join(data, "seo-pages.json"), encoding="utf8"))
    tpl, let = cat["templates"], cat["coverLetters"]
    t.check("the catalogue lists the six candidate types", [a["id"] for a in cat.get("audiences", [])] == TYPES, cat.get("audiences"))
    for a in TYPES:
        r = [x for x in tpl if x.get("audience") == a]; l = [x for x in let if x.get("audience") == a]
        t.check(f"{a}: 5 resume designs and 5 cover letters", len(r) == 5 and len(l) == 5, (len(r), len(l)))
        t.check(f"{a}: the 5 designs differ in layout and colour, and the 5 letters in design", len({x["layout"] for x in r}) == 5 and len({x["accent"] for x in r}) == 5 and len({x["design"] for x in l}) == 5, [x["layout"] for x in r])
    base_layouts = {x["layout"] for x in tpl if not x.get("audience")}
    new = [x for x in tpl if x.get("audience")]
    t.check("new designs reuse existing layouts only", all(x["layout"] in base_layouts for x in new), [x["layout"] for x in new if x["layout"] not in base_layouts])
    t.check("new designs have their own sample profile", all(x["profile"] in cat["resumeProfiles"] for x in new))
    t.check("existing designs keep their ids and slugs; new ids are appended", [x["id"] for x in tpl[:91]] == [78, 79, 80, 81, 82, 83, 84, 85, 86, 87, 88, 89, 90, 91] + list(range(1, 78)) and sorted(x["id"] for x in new) == list(range(92, 122)) and len({x["slug"] for x in tpl}) == len(tpl) and len({x["slug"] for x in let}) == len(let))
    t.check("letters use an existing letter design", all(x["design"] in {"prism", "canvas", "folio", "portrait", "chroma", "gallery", "meridian", "atelier", "vector", "apex", "forma", "ledger", "studio", "origin"} for x in let if x.get("audience")))
    t.check("no side-column design is labelled ATS-conscious", not [x["slug"] for x in new if x["ats"] and re.search(r"sidebar|^signal$|two-column|^suite-(vector|forma|prism|folio)$", x["layout"])])
    words = json.dumps([new, [x for x in let if x.get("audience")]], ensure_ascii=False)
    t.check("sample content names no job site or competing builder", not re.search(r"\b(naukri|indeed|monster|upwork|fiverr|zety|canva resume|novoresume|resume\.io)\b", words, re.I))
    sys.path.insert(0, os.path.join(root, "tools")); sys.path.insert(0, os.path.join(root, "tools", "seo"))
    node = shutil.which("node")
    if not node:
        t.skip("picker checks", "node is not installed"); return
    import sync_catalog, build_role_library
    t.check("catalog.json matches the designs and letters in data.js", sync_catalog.merged()[1] == cat)
    lib = json.load(open(os.path.join(pub, "role-library.json"), encoding="utf8"))
    t.check("role-library.json is current with seo-pages.json", build_role_library.library() == lib)
    r = subprocess.run([node, os.path.join(root, "tests", "lib", "picker_check.cjs"), pub, os.path.join(data, "seo-pages.json")], capture_output=True, text=True, timeout=300)
    try: rep = json.loads(r.stdout)
    except ValueError: rep = {"ok": False, "bad": [r.stderr[-400:]], "counts": {}}
    c = rep.get("counts", {})
    t.check("every role, industry, style and city page gets 5 designs with sample content and 5 letters, all rendering", rep["ok"], rep["bad"][:6])
    t.check(f"coverage: {c.get('roles')} roles, {c.get('industries')} industries, {c.get('styles')} styles, {c.get('cityPages')} city pages, {c.get('combos')} design sets, {c.get('letters')} letter sets",
            c.get("roles", 0) >= len(seo["roles"]) and c.get("industries") == len(seo["categories"]) and c.get("styles") == len(seo["templates"]) and c.get("combos", 0) >= (len(seo["roles"]) + len(seo["categories"]) + len(seo["templates"])) * 6, c)
    print("    coverage:", json.dumps(c))
    # ---- pages
    cl = ctx.client()
    s, _, g = cl.page("/templates.html")
    t.check("the template gallery has the guided picker and candidate-type chips", s == 200 and 'data-template-picker="resume"' in g and all(f'data-template-audience="{a}"' in g for a in TYPES) and "/template-picker.js" in g)
    s, _, g = cl.page("/cover-letters.html")
    t.check("the cover-letter gallery has the guided picker and candidate-type chips", s == 200 and 'data-template-picker="letter"' in g and all(f'data-letter-audience="{a}"' in g for a in TYPES))
    t.check("the role library is served", cl.raw("GET", "/role-library.json")[0] == 200)
    for style in ("fresher", "experienced", "freelance", "career-change", "senior", "internship"):
        s, _, p = cl.page(f"/resume-templates/{style}/")
        names = [x["name"] for x in new if x["audience"] == style]
        t.check(f"/resume-templates/{style}/ lists its 5 designs and opens the picker", s == 200 and all(n in p for n in names) and f'data-picker-style="{style}"' in p and f'href="/templates.html?style={style}#picker"' in p and p.count("<h1") == 1, s)
    s, _, p = cl.page("/resume-examples/information-technology/software-engineer/")
    t.check("a role page has a Use this template button for that role", s == 200 and 'href="/templates.html?role=software-engineer#picker">Use this template' in p)
    s, _, p = cl.page("/cover-letter-examples/medical/")
    t.check("a cover-letter example page opens the letter picker for its field", s == 200 and 'href="/cover-letters.html?industry=medical#picker">Use this template' in p)
    s, _, p = cl.page("/resume/patna/freshers/")
    t.check("a city fresher page opens the picker for freshers", s == 200 and "/templates.html?type=fresher&amp;role=freshers#picker" in p)
    s, _, sm = cl.page("/sitemap.xml")
    t.check("the new template style pages are in the sitemap", s != 200 or all(f"/resume-templates/{x}/</loc>" in sm for x in ("experienced", "freelance", "career-change", "senior", "internship")))
