# CVTara v3.3 pre-launch check

## Verified in the audit environment (2026-10-14)
- Installer run end-to-end on MariaDB 10.11; all migrations pass in strict mode (also SQLite).
- ~100 backend checks for plans, add-ons, stacking, refunds, disputes, coupons, primary/variant resumes, ATS-scan limits, user blocking, account export/delete and admin plan editing.
- Browser click-through of every public page, customer dashboard view and admin view: no JavaScript errors and no failing requests (providers stubbed).
- `composer audit`: no advisories.

## Still required before public launch
- Hostinger installation, cron, permissions and backup/restore testing.
- Live Razorpay payment + webhook (including disputes), email delivery and Cashfree payout acceptance.
- Real-device visual checks.
- Confirm operator/contact/legal details, refund terms, referral disclosures, tax handling, identity retention and payout review ownership.
- Perks shown on plan cards that are **not product features yet** — fulfil them or edit the text in Admin → Pricing: *Naukri / LinkedIn export, LinkedIn profile tips, priority support, 1-on-1 resume review call, lifetime updates, email support*.
- Security review / penetration testing, DNS/SSL and SEO review.
