# Tests

`python3 tests/run_tests.py [--only NAME]` runs the main suite (suites in `tests/suites/`, s1–s17) against a throw-away instance with its own PHP server and SQLite database; it needs PHP 8.2+, the Laravel `vendor/` folder and (for the browser-driven suites) Playwright. `python3 tools/validate.py --mariadb` checks syntax, wiring, routes and migrations on SQLite and MariaDB. `tests/fixtures/ats_golden.json` holds the expected ATS engine output.

`audit-harness/` holds the scripts used to verify v3.3 (see its README for setup):

- `scenario_v33_plans_addons_admin.py` — ~50 checks: plans, add-ons, stacking, best-plan, ATS-scan limits, primary/variant resumes, partial refund, disputes, coupons, referral Pro gating, user blocking, account export/delete, admin plan editor.
- `browser_*.cjs` — Playwright runs: click every control on every public, customer and admin page; sign-up → login → checkout; the 40-second rotating popup; the three-view `/preview` shell.

They stub Razorpay, Twilio, Resend and OpenAI through `FakeProviders_AppServiceProvider.php`; no live provider is contacted.
