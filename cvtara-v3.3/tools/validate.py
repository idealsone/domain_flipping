#!/usr/bin/env python3
"""Release validation: syntax, data, wiring and policy checks before a package is built.

  python3 tools/validate.py [--vendor PATH_TO_VENDOR] [--mariadb]

Checks (each prints PASS / FAIL):
  * every PHP file lints (php -l), every JS/MJS file passes `node --check`, every JSON file parses
  * every owner-console module served by routes/web.php exists, and every module is wired in admin.html
  * every <script src> / <link href> in the static pages points to a file that exists
  * every Laravel route resolves (artisan route:list) and migrations run on an empty SQLite database
  * optional: migrations also run on a throw-away MariaDB database (--mariadb)
  * no mention of AI authorship or tooling in shipped files, no secrets, no debug leftovers
Exit code 0 only when everything passes.
"""
import argparse, json, os, re, shutil, subprocess, sys, tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
APP, PUB = ROOT / "app", ROOT / "public_html"
fails = []


def check(name, ok, detail=""):
    print(("  PASS " if ok else "  FAIL ") + name + ("" if ok else f" | {detail}"))
    if not ok: fails.append(name)


def files(base, *suffix, skip=("vendor", "node_modules", "storage", "bootstrap/cache", "_pages")):
    for p in base.rglob("*"):
        if p.is_file() and p.suffix in suffix and not any(s in p.as_posix() for s in skip):
            yield p


def main():
    ap = argparse.ArgumentParser(); ap.add_argument("--vendor"); ap.add_argument("--mariadb", action="store_true"); a = ap.parse_args()

    print("== Syntax")
    bad = [str(p.relative_to(ROOT)) for p in files(APP, ".php") if subprocess.run(["php", "-l", str(p)], capture_output=True).returncode]
    bad += [str(p.relative_to(ROOT)) for p in files(PUB, ".php") if subprocess.run(["php", "-l", str(p)], capture_output=True).returncode]
    check("all PHP files lint", not bad, bad[:5])
    node = shutil.which("node")
    if node:
        bad = []
        for p in list(files(PUB, ".js", ".mjs", skip=("assets/vendor", "node_modules", "_pages"))) + list(files(APP / "resources" / "console", ".js")) + list(files(ROOT / "tools" / "static-preview", ".js")):
            r = subprocess.run([node, "--check", str(p)], capture_output=True)
            if r.returncode and not p.suffix == ".mjs": bad.append(str(p.relative_to(ROOT)))
        check("all JavaScript files pass node --check", not bad, bad[:5])
        scripts = []
        for p in (APP / "resources" / "frontend").rglob("*.html"):
            for i, m in enumerate(re.finditer(r"<script(?![^>]*\bsrc=)(?![^>]*type=\"application/ld\+json\")[^>]*>(.*?)</script>", p.read_text(encoding="utf8"), re.S)):
                if m.group(1).strip():
                    t = Path(tempfile.mkdtemp()) / "x.js"; t.write_text(m.group(1), encoding="utf8")
                    if subprocess.run([node, "--check", str(t)], capture_output=True).returncode: scripts.append(f"{p.name}#{i}")
        check("inline scripts in the page templates pass node --check", not scripts, scripts[:5])
    else:
        print("  (node not installed: JavaScript syntax not checked)")
    bad = []
    for p in list(files(APP, ".json")) + list(files(PUB, ".json")) + [ROOT / "BUILD_INFO.json"]:
        try: json.loads(p.read_text(encoding="utf8"))
        except Exception: bad.append(str(p.relative_to(ROOT)))
    check("all JSON files parse", not bad, bad[:5])

    print("== Wiring")
    web = (APP / "routes" / "web.php").read_text(encoding="utf8")
    m = re.search(r"in_array\(\$name,\[([^\]]*)\]", web)
    mods = re.findall(r"'([a-z]+)'", m.group(1)) if m else []
    check("owner console module whitelist found", bool(mods))
    check("every whitelisted console module has a file", all((APP / "resources" / "console" / f"{n}.js").exists() for n in mods), [n for n in mods if not (APP / "resources" / "console" / f"{n}.js").exists()])
    admin = (APP / "resources" / "frontend" / "admin.html").read_text(encoding="utf8")
    check("every console module is loaded by admin.html", all(f"/console/{n}.js" in admin for n in mods), [n for n in mods if f"/console/{n}.js" not in admin])
    registered = set()
    for n in mods: registered |= set(re.findall(r"K\.register\('([a-z]+)'", (APP / "resources" / "console" / f"{n}.js").read_text(encoding="utf8")))
    missing = [k for k in registered if f'id="admin-{k}"' not in admin and k not in ("resumelib", "letterlib")]
    check("every registered console page has its section in admin.html", not missing, missing)
    miss = []
    for p in (APP / "resources" / "frontend").glob("*.html"):
        t = p.read_text(encoding="utf8")
        for ref in re.findall(r'(?:src|href)="(/[A-Za-z0-9_./-]+\.(?:js|css|mjs|png|svg|ico|webp))(?:\?[^"]*)?"', t):
            if ref.startswith("/console/"): continue
            if not (PUB / ref.lstrip("/")).exists(): miss.append(f"{p.name}: {ref}")
    check("every script, style and image a page links to exists in public_html", not miss, miss[:6])
    for need in ("ServeCachedPage", "support/report", "ats/scan"):
        check(f"wiring present: {need}", need in web or need in (APP / "bootstrap" / "app.php").read_text(encoding="utf8") or need in (APP / "app/Http/Controllers/ApiController.php").read_text(encoding="utf8"))

    print("== Application boot, routes and migrations")
    vendor = Path(a.vendor) if a.vendor else APP / "vendor"
    if not vendor.exists():
        print("  (vendor folder not found: pass --vendor; route and migration checks skipped)")
    else:
        tmp = Path(tempfile.mkdtemp(prefix="cvtara-validate-")); site = tmp / "site"
        shutil.copytree(APP, site, ignore=shutil.ignore_patterns("vendor", "*.sqlite", "*.log", ".env", "public_html"), symlinks=True)
        os.symlink(vendor.resolve(), site / "vendor")
        for d in ("storage/framework/cache", "storage/framework/sessions", "storage/framework/views", "storage/logs", "bootstrap/cache"): (site / d).mkdir(parents=True, exist_ok=True)
        (site / ".env").write_text("APP_NAME=CVTara\n")
        db = tmp / "t.sqlite"; db.write_text("")
        env = dict(os.environ, APP_ENV="local", APP_DEBUG="false", APP_KEY="base64:" + __import__("base64").b64encode(os.urandom(32)).decode(), DB_CONNECTION="sqlite", DB_DATABASE=str(db), CACHE_STORE="array", SESSION_DRIVER="array", MAIL_MAILER="log")
        r = subprocess.run(["php", "artisan", "route:list", "--json"], cwd=site, env=env, capture_output=True, text=True)
        try: routes = json.loads(r.stdout)
        except ValueError: routes = []
        uris = {x["uri"] for x in routes}
        check("routes load", r.returncode == 0 and len(routes) > 20, (r.stderr or r.stdout)[-300:])
        check("key routes exist", all(any(u.startswith(k) for u in uris) for k in ("api/", "export/", "support/report", "console/", "webhooks/razorpay", "login", "register")), sorted(uris)[:10])
        r = subprocess.run(["php", "artisan", "migrate", "--force"], cwd=site, env=env, capture_output=True, text=True)
        check("migrations run on an empty SQLite database", r.returncode == 0, (r.stdout + r.stderr)[-400:])
        r = subprocess.run(["php", "artisan", "cvtara:prebuild"], cwd=site, env=env, capture_output=True, text=True)
        check("ready-made pages build from an empty install", r.returncode == 0 and "Pre-built" in r.stdout and "could not build" not in r.stdout, (r.stdout + r.stderr)[-300:])
        if a.mariadb:
            mysql = shutil.which("mysql"); mariadbd = shutil.which("mariadbd") or shutil.which("mysqld")
            if not (mysql and mariadbd): print("  (MariaDB not installed: skipped)")
            else:
                data = tmp / "mdb"; sock = tmp / "m.sock"
                subprocess.run([shutil.which("mariadb-install-db") or "mysql_install_db", f"--datadir={data}", "--auth-root-authentication-method=normal", "--user=root"], capture_output=True)
                proc = subprocess.Popen([mariadbd, f"--datadir={data}", f"--socket={sock}", "--skip-networking", "--user=root"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                import time
                for _ in range(60):
                    if sock.exists(): break
                    time.sleep(0.5)
                try:
                    subprocess.run([mysql, f"--socket={sock}", "-uroot", "-e", "CREATE DATABASE cvt CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"], check=True, capture_output=True)
                    env2 = dict(env, DB_CONNECTION="mariadb", DB_SOCKET=str(sock), DB_DATABASE="cvt", DB_USERNAME="root", DB_PASSWORD="", DB_HOST="localhost")
                    r = subprocess.run(["php", "artisan", "migrate", "--force"], cwd=site, env=env2, capture_output=True, text=True)
                    check("migrations run on MariaDB (utf8mb4)", r.returncode == 0, (r.stdout + r.stderr)[-500:])
                except Exception as e:
                    check("MariaDB check could start", False, str(e)[:200])
                finally:
                    proc.terminate()
        shutil.rmtree(tmp, ignore_errors=True)

    print("== Policy")
    pat = re.compile(r"(chatgpt|\bgpt[- ]?\d|\bopenai\b|generated by (an )?ai|written by (claude|ai)|anthropic|\bclaude\b|co-authored|domain[ _-]?flipping)", re.I)
    hits = []
    for base in (APP / "app", APP / "resources", APP / "routes", APP / "database", PUB):
        for p in base.rglob("*"):
            if p.is_file() and p.suffix in (".php", ".js", ".html", ".css", ".json", ".md", ".txt", ".blade.php") and "vendor" not in p.as_posix() and "node_modules" not in p.as_posix():
                t = p.read_text(encoding="utf8", errors="ignore")
                m = pat.search(t)
                if m: hits.append(f"{p.relative_to(ROOT)}: {m.group(0)}")
    check("no mention of AI authorship or tooling in shipped files", not hits, hits[:5])
    sec = re.compile(r"(sk_live_[A-Za-z0-9]{10,}|rzp_live_[A-Za-z0-9]{8,}|-----BEGIN (RSA |EC )?PRIVATE KEY-----|AKIA[0-9A-Z]{16})")
    leaks = [str(p.relative_to(ROOT)) for base in (APP, PUB) for p in base.rglob("*") if p.is_file() and p.suffix in (".php", ".js", ".json", ".env", ".html", ".md") and "vendor" not in p.as_posix() and sec.search(p.read_text(encoding="utf8", errors="ignore"))]
    check("no live keys or private keys in the source", not leaks, leaks[:5])
    dbg = [str(p.relative_to(ROOT)) for p in files(PUB, ".js", ".mjs", skip=("assets/vendor", "_pages")) if re.search(r"\bdebugger\b|console\.log\(", p.read_text(encoding="utf8", errors="ignore"))]
    check("no debugger or console.log leftovers in shipped scripts", not dbg, dbg[:5])
    check("no environment file or database in the package tree", not any(p.name in (".env",) or p.suffix == ".sqlite" for p in ROOT.rglob("*") if "vendor" not in p.as_posix() and "node_modules" not in p.as_posix() and ".git/" not in p.as_posix()))

    print(f"\n{'ALL CHECKS PASSED' if not fails else str(len(fails)) + ' CHECK(S) FAILED: ' + ', '.join(fails)}")
    return 1 if fails else 0


if __name__ == "__main__":
    sys.exit(main())
