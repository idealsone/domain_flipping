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
VERSION = "3.4.1"
SKIP_DIRS = {".git", "node_modules", "tests"}
SKIP_FILES = {".env", ".DS_Store"}


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
        add_tree(z, ROOT / "public_html", "public_html/")
        z.writestr("public_html/.install-key-hash", hashlib.sha256(key.encode()).hexdigest())
        z.write(payload, "public_html/.cvtara-payload.zip")
    shutil.rmtree(work)
    print(f"Built {final} ({final.stat().st_size // 1024} KB)")


if __name__ == "__main__":
    sys.exit(main())
