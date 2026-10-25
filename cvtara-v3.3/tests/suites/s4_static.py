"""Static guarantees: scripts parse, theme code exists, no broken includes, owner modules are wired."""
import os, re, shutil, subprocess
NAME = "Static checks"


def run(ctx, t):
    pub = ctx.public
    node = shutil.which("node")
    for f in sorted(os.listdir(os.path.join(ctx.app, "resources", "console"))):
        if node: t.check(f"console/{f} parses", subprocess.run([node, "--check", os.path.join(ctx.app, "resources", "console", f)], capture_output=True).returncode == 0)
    for f in sorted(os.listdir(pub)):
        if f.endswith(".js") and node:
            r = subprocess.run([node, "--check", os.path.join(pub, f)], capture_output=True, text=True)
            t.check(f"{f} parses", r.returncode == 0, r.stderr[:200])
    if not node: t.skip("JavaScript syntax", "node not installed")
    ds = open(os.path.join(pub, "cvtara-ds.css")).read()
    t.check("dark theme variables exist", "html.dark" in ds and "color-scheme:dark" in ds)
    t.check("theme toggle API exists", "CVTaraTheme" in open(os.path.join(pub, "preview.js")).read())
    admin = open(os.path.join(ctx.app, "resources", "frontend", "admin.html")).read()
    for m in re.findall(r'<script src="/([^"?]+)"', admin):
        where = os.path.join(ctx.app, "resources", m) if m.startswith("console/") else os.path.join(pub, m)
        t.check(f"admin.html script {m} exists", os.path.isfile(where))
        t.check(f"{m} is not a public file", not (m.startswith("console/") and os.path.exists(os.path.join(pub, m))))
    t.check("Referral Ops module is wired", "console/referral.js" in admin)
    for f in os.listdir(pub):
        if f.endswith((".js", ".html", ".css")):
            txt = open(os.path.join(pub, f), errors="ignore").read()
            if re.search(r"\$AUDIT_DIR|TODO_REPLACE", txt): t.check(f"{f} has no placeholders", False)

    # nothing shipped may carry authorship notes of any tool
    bad = re.compile(r"claude|anthropic|chatgpt|sonnet|co-authored|generated (by|with)|ai[- ]generated", re.I)
    PROVIDER_NAMED = re.compile(r"chatgpt|sonnet|co-authored|generated (by|with)|ai[- ]generated|written by (claude|ai)", re.I)
    TARA_FILES = {"app/Services/TaraAI.php", "app/Http/Controllers/AdminController.php", "resources/data/defaults.json", "resources/console/advanced.js", "resources/console/owner.js"}
    hits = []
    for base in (os.path.join(ctx.app, "app"), os.path.join(ctx.app, "routes"), os.path.join(ctx.app, "resources"), os.path.join(ctx.app, "database"), pub):
        for r, _, fs in os.walk(base):
            for f in fs:
                if f.endswith((".php", ".js", ".mjs", ".css", ".html", ".json", ".txt", ".md")) and "assets/vendor" not in r:
                    txt = open(os.path.join(r, f), errors="ignore").read()
                    rel = os.path.relpath(os.path.join(r, f), ctx.app)
                    # Tara AI names its provider (Claude by Anthropic) to the owner and in the privacy policy; those files are still checked for authorship notes
                    if (PROVIDER_NAMED.search(txt) if rel.replace(os.sep, "/") in TARA_FILES else bad.search(txt)): hits.append(rel)
    t.check("no tool/authorship mentions in shipped source", not hits, hits[:5])
