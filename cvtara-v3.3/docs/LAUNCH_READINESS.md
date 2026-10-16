# CVTara v3.9 pre-launch check

## Verified in the audit environment (2026-10-14)
- Installer run end-to-end on MariaDB 10.11; all migrations pass in strict mode (also SQLite).
- ~100 backend checks for plans, add-ons, stacking, refunds, disputes, coupons, primary/variant resumes, ATS-scan limits, user blocking, account export/delete and admin plan editing.
- Browser click-through of every public page, customer dashboard view and admin view: no JavaScript errors and no failing requests (providers stubbed).
- `composer audit`: no advisories.

## Verified in the build environment (2026-10-16, v3.9.0)
- Automated suite `python3 tests/run_tests.py` (500+ checks: pages, plans, owner console, billing, credits and scans, ATS Match server flow, feedback inbox, ready-made pages, busy mode, security, customer portal, link crawl) in an isolated instance, plus the same suite against the minified package build.
- `python3 tools/validate.py --mariadb`: PHP and JavaScript syntax, JSON, console wiring, page links, route loading, migrations on empty SQLite and MariaDB (utf8mb4), page pre-build, no AI-authorship text, no keys or debug leftovers.
- Browser checks (headless Chromium): sidebar and layout identical on every portal view at four widths, Feedback button (open, move left/right, select area, add screenshots, 2 MB refusal, send), ATS Match flow, credit popup, tracker.
- Not verified: anything on a real Hostinger account, real payment gateways, real email/SMS, real job portals, real phones.

## Still required before public launch
- Hostinger installation, cron, permissions and backup/restore testing.
- Live Cashfree payment + webhook, email delivery and Cashfree payout acceptance.
- Real-device visual checks.
- Confirm operator/contact/legal details, refund terms, referral disclosures, tax handling, identity retention and payout review ownership.
- Perks shown on plan cards that are **not product features yet** — fulfil them or edit the text in Admin → Pricing: *Naukri / LinkedIn export, LinkedIn profile tips, priority support, 1-on-1 resume review call, lifetime updates, email support*.
- Security review / penetration testing, DNS/SSL and SEO review.
- Job-link reading for login-protected portals needs a page-rendering service key (Owner Console → Speed & traffic) and a check of each job site's terms of use; direct reading works for public postings only.
- After every upload of a new version press **Rebuild all pages now** (Speed & traffic) so visitors are not served the previous version's ready-made pages.

