#!/usr/bin/env python3
"""Build a self-contained PREVIEW bundle (SQLite + demo data + demo payments).

  python3 tools/build_preview.py --vendor PATH_TO_VENDOR [--out dist]

Needs PHP 8.3 on the build machine. The bundle runs on any machine with PHP 8.3
(pdo_sqlite, mbstring, openssl, fileinfo, gd): unzip, run start-preview (.bat / .sh),
open http://127.0.0.1:8080/preview. For demos only — never deploy it publicly.
"""
import argparse, base64, os, secrets, shutil, subprocess, sys, tempfile, zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
ENV = """APP_NAME=CVTara
APP_ENV=local
APP_KEY=base64:{key}
APP_DEBUG=false
APP_URL=http://127.0.0.1:8080
LOG_CHANNEL=single
LOG_LEVEL=error
DB_CONNECTION=sqlite
SESSION_DRIVER=database
SESSION_LIFETIME=720
SESSION_SECURE_COOKIE=false
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=log
API_RATE_LIMIT=5000
CVTARA_PREVIEW=true
"""
ROUTER = """<?php
// Router for PHP's built-in server: serves real files, everything else goes to the app.
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$f = __DIR__.'/public_html'.$p;
if ($p !== '/' && is_file($f) && substr(basename($f), 0, 1) !== '.' && substr($f, -4) !== '.php') { return false; }
require __DIR__.'/public_html/index.php';
"""
BAT = """@echo off
cd /d "%~dp0"
where php >nul 2>nul
if errorlevel 1 (
  echo PHP 8.3 or newer is required. Install it from https://windows.php.net/download and try again.
  pause
  exit /b 1
)
echo Starting CVTara preview on http://127.0.0.1:8080  (press Ctrl+C to stop)
start "" http://127.0.0.1:8080/preview
php -S 127.0.0.1:8080 -t public_html preview-router.php
"""
SH = """#!/usr/bin/env bash
cd "$(dirname "$0")"
command -v php >/dev/null || { echo "PHP 8.3+ is required (pdo_sqlite, mbstring, openssl, fileinfo, gd)."; exit 1; }
echo "Starting CVTara preview on http://127.0.0.1:8080  (Ctrl+C to stop)"
(sleep 1; command -v xdg-open >/dev/null && xdg-open http://127.0.0.1:8080/preview || command -v open >/dev/null && open http://127.0.0.1:8080/preview) >/dev/null 2>&1 &
exec php -S 127.0.0.1:8080 -t public_html preview-router.php
"""
README = """# CVTara v3.3 — local preview (demo only)

1. Install PHP 8.3+ (extensions: pdo_sqlite, mbstring, openssl, fileinfo, gd, curl, zip).
2. Windows: double-click `start-preview.bat`.  Mac/Linux: `./start-preview.sh`.
3. Open http://127.0.0.1:8080/preview

The preview shows three views — **Website home**, **Customer dashboard**, **Admin dashboard** — all working:
sign up, log in, build resumes, tailor variants, run ATS checks, buy plans (demo payment, no real money),
manage pricing, users, offers/popup, email templates and more.

Demo logins (password `DemoPass#2026!x`): customer `demo@cvtara.test` (Fresher Pro), owner `owner@cvtara.test`.
Use the "Sign in as…" buttons in the preview bar. Everything resets if you delete `database/database.sqlite`
and re-run `php artisan migrate --force && php artisan cvtara:demo`.

Preview mode (CVTARA_PREVIEW=true) skips email-code re-verification and uses demo payments. Never use it on a live site.
"""


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--vendor", required=True)
    ap.add_argument("--out", default=str(ROOT / "dist"))
    a = ap.parse_args()
    out = Path(a.out); out.mkdir(parents=True, exist_ok=True)
    work = Path(tempfile.mkdtemp())
    st = work / "CVTara-Preview"
    shutil.copytree(ROOT / "app", st, ignore=shutil.ignore_patterns(".env", "*.sqlite", "*.log"))
    shutil.copytree(a.vendor, st / "vendor")
    shutil.copytree(ROOT / "public_html", st / "public_html", ignore=shutil.ignore_patterns(".install-key-hash", ".cvtara-payload.zip"))
    for sub in ("storage/framework/cache/data", "storage/framework/sessions", "storage/framework/views", "storage/logs", "storage/app/public", "bootstrap/cache"):
        (st / sub).mkdir(parents=True, exist_ok=True)
    (st / ".env").write_text(ENV.format(key=base64.b64encode(secrets.token_bytes(32)).decode()))
    (st / "database").mkdir(exist_ok=True)
    (st / "database" / "database.sqlite").touch()
    for cmd in (["migrate", "--force"], ["cvtara:demo"]):
        subprocess.check_call(["php", "artisan", *cmd], cwd=st)
    (st / "preview-router.php").write_text(ROUTER)
    (st / "start-preview.bat").write_text(BAT.replace("\n", "\r\n"))
    (st / "start-preview.sh").write_text(SH)
    (st / "README_PREVIEW.md").write_text(README)
    # fresh sessions/cache/logs
    for sub in ("storage/framework/sessions", "storage/framework/views", "storage/logs"):
        for f in (st / sub).glob("*"):
            if f.name != ".gitignore":
                f.unlink() if f.is_file() else shutil.rmtree(f)
    dest = out / "CVTara_Preview_v3.3.zip"
    with zipfile.ZipFile(dest, "w", zipfile.ZIP_DEFLATED) as z:
        for p in sorted(st.rglob("*")):
            if p.is_dir():
                continue
            info = zipfile.ZipInfo.from_file(p, p.relative_to(work).as_posix())
            if p.name.endswith(".sh"):
                info.external_attr = 0o755 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(info, p.read_bytes())
    shutil.rmtree(work)
    print(f"Built {dest} ({dest.stat().st_size // 1024} KB)")


if __name__ == "__main__":
    sys.exit(main())
