#!/usr/bin/env python3
"""CVTara test runner.  Usage:  python3 tests/run_tests.py [--app DIR] [--public DIR] [--only NAME]

Needs PHP 8.2+ and the Laravel folder with `vendor/` installed.  Everything runs against a throw-away
SQLite database in a temp folder; production data and the shipped .env are never touched.
Exit code: 0 all passed, 1 a check failed, 2 the machine is not set up (message says what to install).
"""
import argparse, importlib, os, pkgutil, sys, time

HERE = os.path.dirname(os.path.abspath(__file__)); sys.path.insert(0, HERE)
from lib.env import Instance, Setup  # noqa: E402
from lib.http import Client  # noqa: E402


class T:
    def __init__(self): self.passed = self.failed = self.skipped = 0; self.fails = []

    def check(self, label, ok, extra=""):
        if ok: self.passed += 1; print("  PASS", label)
        else: self.failed += 1; self.fails.append(label); print("  FAIL", label, "|", extra)
        return ok

    def skip(self, label, why): self.skipped += 1; print("  SKIP", label, "-", why)


class Ctx:
    def __init__(self, inst, app, public): self.inst, self.app, self.public, self.preview = inst, app, public, inst.preview
    def client(self): return Client(self.inst.base)


def main():
    root = os.path.dirname(HERE)
    ap = argparse.ArgumentParser(); ap.add_argument("--app", default=os.environ.get("CVTARA_APP", os.path.join(root, "app")))
    ap.add_argument("--public", default=os.environ.get("CVTARA_PUBLIC", os.path.join(root, "public_html"))); ap.add_argument("--only")
    a = ap.parse_args(); app, public = os.path.abspath(a.app), os.path.abspath(a.public)
    mods = [importlib.import_module("suites." + m.name) for m in sorted(pkgutil.iter_modules([os.path.join(HERE, "suites")]), key=lambda m: m.name)]
    if a.only: mods = [m for m in mods if a.only in m.__name__ or a.only.lower() in m.NAME.lower()]
    t, started = T(), time.time(); inst = None
    try:
        inst = Instance(app, public, preview=True).start()
        for m in mods:
            print(f"\n== {m.NAME}")
            try: m.run(Ctx(inst, app, public), t)
            except Exception as e: t.check(f"{m.NAME} ran without crashing", False, f"{type(e).__name__}: {e}")
        inst.stop(); inst = Instance(app, public, preview=False).start(); c = Ctx(inst, app, public).client()
        print("\n== Production mode (preview off)")
        t.check("preview sign-in is closed", c.raw("GET", "/preview/go/admin")[0] in (403, 404), "open!")
        t.check("owner API needs login", c.api("GET", "admin/users")[0] in (401, 403))
        t.check("owner page needs login", c.raw("GET", "/admin.html")[0] in (302, 401, 403))
    except Setup as e:
        print("\nSETUP PROBLEM (not a CVTara bug):", e); return 2
    finally:
        if inst: inst.stop()
    print(f"\n{t.passed} passed, {t.failed} failed, {t.skipped} skipped in {time.time() - started:.0f}s")
    for f in t.fails: print("  FAILED:", f)
    return 1 if t.failed else 0


if __name__ == "__main__":
    sys.exit(main())
