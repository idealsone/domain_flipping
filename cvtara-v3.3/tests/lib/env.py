"""Starts a throw-away CVTara instance (SQLite, preview or production mode) for the test suites."""
import os, shutil, socket, subprocess, sys, tempfile, time, urllib.request

ROUTER = "<?php $p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH); $f=$_SERVER['DOCUMENT_ROOT'].$p; if($p!=='/'&&is_file($f)&&substr($f,-4)!=='.php')return false; require $_SERVER['DOCUMENT_ROOT'].'/index.php';"


class Setup(Exception):
    """A problem with the machine (missing PHP, vendor folder...), not with CVTara."""


def free_port():
    s = socket.socket(); s.bind(("127.0.0.1", 0)); p = s.getsockname()[1]; s.close(); return p


class Instance:
    def __init__(self, app, public, preview=True):
        self.app, self.public, self.preview = app, public, preview
        self.tmp = tempfile.mkdtemp(prefix="cvtara-test-")
        self.db = os.path.join(self.tmp, "test.sqlite"); open(self.db, "w").close()
        self.port = free_port(); self.base = f"http://127.0.0.1:{self.port}"; self.proc = None
        self.env = dict(os.environ, DB_CONNECTION="sqlite", DB_DATABASE=self.db, APP_ENV="local", APP_DEBUG="false",
                        CVTARA_PREVIEW="true" if preview else "false", API_RATE_LIMIT="6000", SESSION_DRIVER="database",
                        CACHE_STORE="database", MAIL_MAILER="log", APP_URL=self.base, QUEUE_CONNECTION="sync")

    def artisan(self, *a):
        return subprocess.run(["php", "artisan", *a], cwd=self.app, env=self.env, capture_output=True, text=True, timeout=300)

    def start(self):
        if not shutil.which("php"): raise Setup("PHP 8.2+ is not installed or not on PATH.")
        if not os.path.isfile(os.path.join(self.app, "vendor", "autoload.php")):
            raise Setup(f"{self.app}/vendor is missing. Run `composer install --no-dev` in that folder first.")
        if not os.path.isfile(os.path.join(self.app, ".env")) and not self.env.get("APP_KEY"):
            self.env["APP_KEY"] = "base64:" + __import__("base64").b64encode(os.urandom(32)).decode()
        r = self.artisan("migrate", "--force")
        if r.returncode: raise Setup("migrate failed:\n" + (r.stdout + r.stderr)[-800:])
        if self.preview: self.artisan("cvtara:demo")
        router = os.path.join(self.tmp, "router.php"); open(router, "w").write(ROUTER)
        self.proc = subprocess.Popen(["php", "-S", f"127.0.0.1:{self.port}", "-t", self.public, router], cwd=self.app, env=self.env,
                                     stdout=subprocess.DEVNULL, stderr=open(os.path.join(self.tmp, "server.log"), "w"))
        for _ in range(60):
            try: urllib.request.urlopen(self.base + "/login", timeout=2); return self
            except Exception: time.sleep(0.25)
        log = open(os.path.join(self.tmp, "server.log")).read()[-600:]
        raise Setup("Server did not start (does --public belong to the same install as --app?).\n" + log)

    def stop(self):
        if self.proc: self.proc.terminate(); self.proc.wait(timeout=10)
        shutil.rmtree(self.tmp, ignore_errors=True)
