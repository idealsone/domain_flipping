> **Legacy.** These scripts are order-dependent, print-only and mix MariaDB with SQLite, which is why they raised
> errors on repeated runs. Use `python3 tests/run_tests.py` instead (self-contained, SQLite, repeatable, exit codes).
> Browser checks live in `tests/ui/` (`BASE=http://127.0.0.1:8000 node tests/ui/contrast_scan.cjs`).

# Audit harness

Scripts used to exercise the CVTara 3.2.0-RC1 package. Everything here ran against a
**scratch copy**, never against production.

## Setup used

1. Extract `public_html/.cvtara-payload.zip` into a work folder (`run/`) and copy `public_html/`
   (without the zip) to `run/public_html/`.
2. `cp .env.example .env && php artisan key:generate && php artisan migrate --force`
   (SQLite first, then MariaDB 10.11 with the app's strict SQL mode).
3. Create an owner: `User::create([...])->forceFill(['is_admin' => true])->save()` in tinker.
4. Replace `app/Providers/AppServiceProvider.php` with `FakeProviders_AppServiceProvider.php`
   and set `CVTARA_FAKE_HTTP=1` plus `CVTARA_FAKE_STATE=<dir>/fake_state.json` in `.env`.
   This stubs Razorpay, Cashfree, Twilio Verify, Resend and OpenAI so no real provider is called.
5. Serve with `php -S 127.0.0.1:8000 -t run/public_html router.php`
   (router returns `false` for existing files, otherwise includes `index.php`).

## Files

| File | Purpose |
|---|---|
| `client.py` | Tiny cookie/CSRF-aware HTTP client |
| `dbq.py` | MariaDB query helper (SQLite-style snippets are translated) |
| `scenario_t1..t16.py` | Backend scenarios: auth, referrals, Razorpay + webhooks, documents/exports, payouts, coupons, admin endpoints, password reset, mail, maintenance, rewards, Cashfree, load, XSS |
| `match_engine_check.mjs` | Resume-match engine cases (run with Node) |
| `browser_smoke.cjs`, `article_crawl.cjs`, `xss_check.cjs` | Playwright page, article and XSS checks |
| `link_check.py` | Internal link and asset crawler |

Run a scenario with `python3 -I scenario_t2.py <work-dir>` where `<work-dir>` contains
`run/` and `client.py`. The scenarios share state, so run them in numeric order on a fresh database.
Replace `$AUDIT_DIR` in the files with your work directory.
