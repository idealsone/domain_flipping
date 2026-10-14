"""Static guarantees: scripts parse, theme code exists, no broken includes, owner modules are wired."""
import os, re, shutil, subprocess
NAME = "Static checks"


def run(ctx, t):
    pub = ctx.public
    node = shutil.which("node")
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
        t.check(f"admin.html script {m} exists", os.path.isfile(os.path.join(pub, m)))
    t.check("Referral Ops module is wired", "admin-referral.js" in admin)
    for f in os.listdir(pub):
        if f.endswith((".js", ".html", ".css")):
            txt = open(os.path.join(pub, f), errors="ignore").read()
            if re.search(r"\$AUDIT_DIR|TODO_REPLACE", txt): t.check(f"{f} has no placeholders", False)
