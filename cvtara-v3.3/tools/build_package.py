#!/usr/bin/env python3
"""Build the Hostinger upload ZIP from this source tree.

  python3 tools/build_package.py [--vendor PATH_TO_VENDOR] [--out dist]

Without --vendor the script runs `composer install --no-dev --optimize-autoloader`
in a temporary copy of ./app (needs composer + PHP 8.3). The ZIP contains a fresh one-time
install key (INSTALL_KEY.txt, outside the web root once extracted). Do not commit dist/.
"""
import argparse, hashlib, os, secrets, shutil, subprocess, sys, tempfile, zipfile
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
VERSION = "3.6.0"
SKIP_DIRS = {".git", "node_modules", "tests"}
SKIP_FILES = {".env", ".DS_Store"}


def tool(name):
    """Find a node tool (terser / cleancss) on PATH, in tools/node_modules, or in $CVTARA_NODE_BIN."""
    for d in filter(None, [os.environ.get("CVTARA_NODE_BIN"), str(ROOT / "tools" / "node_modules" / ".bin")]):
        if (Path(d) / name).exists():
            return str(Path(d) / name)
    return shutil.which(name)


def minify_tree(root: Path):
    """Shrink and scramble shipped scripts (no comments, mangled names, one line). Source stays readable in the repo."""
    terser = tool("terser")
    if not terser:
        print("WARNING: terser not found - scripts are shipped readable. Run `npm i terser clean-css-cli` in tools/ or set CVTARA_NODE_BIN.")
    n = 0
    for p in root.rglob("*"):
        if not p.is_file() or "assets/vendor" in p.as_posix() or p.name.endswith(".min.js"):
            continue
        if terser and p.suffix in (".js", ".mjs"):
            cmd = [terser, str(p), "-c", "-m", "--comments", "false", "-o", str(p)] + (["--module"] if p.suffix == ".mjs" else [])
            subprocess.check_call(cmd, stdout=subprocess.DEVNULL); n += 1
    print(f"Minified {n} files in {root.name}")


def add_tree(z, src: Path, arc_prefix: str, skip_vendor=False):
    for p in sorted(src.rglob("*")):
        rel = p.relative_to(src)
        parts = rel.parts
        if p.is_dir() or any(x in SKIP_DIRS for x in parts) or p.name in SKIP_FILES:
            continue
        if skip_vendor and parts[0] == "vendor":
            continue
        if parts[:2] == ("storage", "logs") and p.name != ".gitignore":
            continue
        if parts[0] == "storage" and parts[1:2] == ("framework",) and p.name != ".gitignore":
            continue
        if p.suffix in (".sqlite", ".log"):
            continue
        z.write(p, f"{arc_prefix}{rel.as_posix()}")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--vendor")
    ap.add_argument("--out", default=str(ROOT / "dist"))
    a = ap.parse_args()
    out = Path(a.out); out.mkdir(parents=True, exist_ok=True)
    key = secrets.token_urlsafe(18)
    app = ROOT / "app"
    work = Path(tempfile.mkdtemp())
    stage = work / "app"
    shutil.copytree(app, stage, ignore=shutil.ignore_patterns(".env", "*.sqlite", "*.log", "vendor"))
    if a.vendor:
        shutil.copytree(a.vendor, stage / "vendor")
    else:
        subprocess.check_call(["composer", "install", "--no-dev", "--optimize-autoloader", "--no-interaction"], cwd=stage)
    (stage / ".setup-key").write_text(key)
    minify_tree(stage / "resources" / "console")
    (stage / "composer.lock").unlink(missing_ok=True)
    pub = work / "public_html"
    shutil.copytree(ROOT / "public_html", pub, ignore=shutil.ignore_patterns(".install-key-hash", "*.zip", "__pycache__"))
    minify_tree(pub)
    payload = work / "payload.zip"
    with zipfile.ZipFile(payload, "w", zipfile.ZIP_DEFLATED) as z:
        add_tree(z, stage, "")
    name = f"CVTara_Hostinger_v{VERSION}_{date.today().isoformat()}.zip"
    final = out / name
    with zipfile.ZipFile(final, "w", zipfile.ZIP_DEFLATED) as z:
        for d in ("README_HOSTINGER.md", "LAUNCH_READINESS.md", "CHANGELOG.md"):
            z.write(ROOT / "docs" / d, d)
        z.write(ROOT / "docs" / "START_HERE.html", "START_HERE.html")
        z.write(ROOT / "BUILD_INFO.json", "BUILD_INFO.json")
        z.writestr("INSTALL_KEY.txt", f"CVTara one-time installation key\n\n{key}\n\nDelete this file after installation.\n")
        add_tree(z, pub, "public_html/")
        z.writestr("public_html/.install-key-hash", hashlib.sha256(key.encode()).hexdigest())
        z.write(payload, "public_html/.cvtara-payload.zip")
    shutil.rmtree(work)
    print(f"Built {final} ({final.stat().st_size // 1024} KB)")


if __name__ == "__main__":
    sys.exit(main())
