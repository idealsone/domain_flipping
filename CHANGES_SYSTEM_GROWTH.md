# What changed in this build (System & growth)

New owner-console tab: **System & growth** (`/admin.html?tab=system`).

| Area | What it does |
|---|---|
| Maintenance switch | One button turns maintenance on/off. Optional IP allow-list (single IPs or ranges like `203.0.113.0/24`) so you can test the live site while visitors see the maintenance page. Owner accounts, sign-in, payment webhooks and unsubscribe links always work. The older Business controls form still works and no longer erases the allow-list. |
| Error log | Shows recent warnings and errors from `storage/logs` (newest first, secrets hidden), with refresh, download and clear. |
| Marketing senders | Up to 6 extra SMTP senders for marketing mail, each with a daily limit. Passwords are stored encrypted and never shown again. Includes a test-email button. Account and payment email keeps using the main Integrations email. |
| Follow-up campaign | Editable emails sent on chosen days (default 1, 7, 21, 45, 90) to contacts who gave permission. At least 3 days between emails, a daily cap, an automatic unsubscribe link and your postal address in every email. Stops on unsubscribe or purchase. Paused until a sender and your address are set. |
| Contacts | Add one by one or import CSV. Each contact records its source and the permission given, and you must confirm permission. Unsubscribed people are kept on a suppression list and cannot be re-added. |

Other fixes
- Public unsubscribe page: `/unsubscribe/{token}` (also supports one-click unsubscribe from mail apps).
- `storage/framework/{views,cache,sessions}` are now included in the app package. Without them Laravel cannot render the login page on a fresh install.
- A path check in `ContentController` now also works on Windows (no change on Hostinger/Linux).

Deliberately not included
- Bulk emailing to scraped or bought lists, LinkedIn scraping, simulated "warm-up" email between your own mailboxes, and rotating mailboxes to get around sending limits. They break provider rules and India's data-protection expectations, and they damage the domain's ability to deliver email.
- Open-tracking pixels (not needed, and they reduce trust).

Already in the product (not rebuilt)
- Cashfree webhook with signature check at `/webhooks/cashfree`, exempt from CSRF.
- Abandoned-checkout reminders (only for users who opted in), referral wallet with a hold period, Search Console connection, sitemap and blog management.

Setup after install
1. Footer & policies: add your postal address (required for marketing email).
2. System & growth: add a marketing sender, send a test email, then set up the campaign.
3. The existing Hostinger cron job (`private-cron.php`, every minute) also runs the campaign. No new cron job is needed.
4. After updating an existing install, run `php artisan migrate --force` (a new `leads` table and a `sender_id` column on `mail_outbox`).


## Funnel: check -> build for the job -> package (added later on 2026-10-10)

Landing page (`/`) is now the funnel:
1. Visitor uploads a PDF/DOCX or pastes text, optionally pastes a job description, and runs the free ATS check in the browser (nothing is uploaded).
2. They see ATS readiness, job match, what to fix first and which job requirements are covered.
3. "Build my resume for this job" carries the text and job description into the builder (sign-up first if needed). The builder shows a Tailor-to-job panel with a live job-match score, requirement chips and top fixes.
4. Packages shown on the landing page and in the builder are read from Admin > Plans & payments, so a price or limit change appears everywhere at once. "Total access days" already includes bonus days.
5. Admin > Funnel & packages shows each step (ATS checks, build clicks, sign-ups, saved resumes, job tailoring, checkout started, paid), live revenue (test purchases excluded) and the packages with clicks and sales.

New/changed files: `resources/frontend/{index,workspace,admin}.html`, `ApiController` (`POST /api/funnel`), `SystemController` (`GET /api/admin/system/funnel`), migration `2026_10_10_000002_create_funnel_events_table`, and in `public_html`: `funnel.js`, `funnel.css`, `funnel-bridge.js`, `tailor-panel.js`, `owner-funnel.js` (plus a small change to `connected-ui.js`).

Privacy: funnel events store only a keyed hash of the session, the step name and the day. No resume text, email or file is sent. The "Visited" count needs analytics consent, so it is a minimum.


## Follow-up: plans and settings now flow everywhere (10 Oct 2026)

- Checkout plan cards (price, name, limits, access days) are now written from the saved plans, so a price edited in Admin > Plans & payments shows on the checkout page. Before, the three cards had fixed text. The workspace "Upgrade" button follows the monthly price too. The Terms text no longer quotes a price.
- The new home page again honours Admin > Website & design (headline, supporting text, both button labels, accent colour). Defaults now match the new home page copy.
- The referral section is back on the home page and follows the referral switch and thresholds in Business controls.
- Admin guide and customer guide updated for the new flow, Funnel & packages, System & growth and Cashfree checkout.
