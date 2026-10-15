# CVTara v3.5.1 — private addresses, backups, caching

- **Private setup link:** `cvtara.com/setup/afsar` runs the installer (the file `install.php` cannot be opened directly; after installation the link sends visitors to the home page).
- **Private owner sign-in:** `cvtara.com/afsar` and `cvtara.com/sanaulla` (set in `.env` as `OWNER_LOGIN_PATHS`). Owners can no longer sign in on the public `/login`; customers cannot sign in on the owner pages; the addresses appear nowhere in pages, `robots.txt` or the sitemap, are `noindex` and rate-limited.
- **Daily database backup** (`cvtara:backup`, also run by the cron once a day, newest 14 kept in the private storage folder) and a Health check that turns red when no backup is newer than 36 hours.
- **Browser caching:** fingerprinted scripts/styles are cached for a year, images for 30 days; more file types are compressed.
- New guide `docs/HOSTING_PLAN_GUIDE.md` (what Hostinger plans, minifying, caching, backups and security really give you).

# CVTara v3.5.0 — Cashfree checkout, reports and export, no-refund policy

## Payments
- **Cashfree is the primary gateway** (Razorpay stays available as a second option). Checkout opens Cashfree's secure payment window, the server confirms the payment with Cashfree before granting access, signed webhooks handle later changes, and events for orders this site never created are acknowledged instead of failing. Choose the gateway and enter the App ID / Secret key in Settings → Integrations → Payments.
- **All payments are final and non-refundable:** new policy page text, terms, checkout consent and note, contact form, admin guide and owner screens (the console has no refund control; bank reversals show as "Reversed by bank").

## Owner console
- **Reports & Export:** build custom reports on orders, users, referral commissions, withdrawals, errors and email; filter by dates, status, plan, source and amount, choose and order columns, sort, and export the full result as CSV, Excel, JSON or print; save report setups.
- **Every data table** in the console now has a filter box, sortable columns, a column chooser and export (CSV, Excel, JSON, print, copy).
- **Dashboard numbers come from the server** (full database, real money only); **Orders and Users filters and pages are server-side**, so nothing is cut off after 100 users or 500 orders.

## Search engines and privacy
- Private areas (admin, owner scripts, sign-in, workspace, checkout, API) send `noindex`; broken pages show a notice with a Go to home page button and return home automatically.

## Fixed (audit follow-ups)
- Print preview watermark is drawn into the document (and restored if removed); settings are read once per request; the public content is fetched once per page; reminder scans run hourly and skip customers who already renewed; old logs and sent-mail bodies are pruned daily; verified reviews must belong to a paying customer; coupon attempts are throttled; manual payout references are checked for duplicates; changing a user's email clears its verified flag; leftover "Free plan" wording removed; `.env.example` defaults are production-safe; draft copies are cleared from the browser at sign-out; privacy policy discloses the device cookie; commissions respect the minimum order; `cvtara:admin` resets the password; trusted proxies are configurable (`TRUSTED_PROXIES`).

## Tests
`python3 tests/run_tests.py` — 305 checks, including a Cashfree stand-in (order, verify, refund webhook, unknown orders), reports/exports, a crawl of the public site for broken links, and access-control checks.

# CVTara v3.4.3 — access control, crash protection, launch fixes

## Security and protection
- **Owner area is invisible to everyone else:** visitors and signed-in customers who open an owner URL are sent to the home page. The six console scripts moved out of the public folder and are served only to the signed-in owner (`/console/*.js`).
- **Broken pages fall back to the home page** (missing pages, server errors); files still return a plain 404. A "back soon" page shows if the home page itself fails.
- **UI crash guard** (`crash-guard.js`): repeated script errors on public pages send the visitor home once; workspace, checkout and owner pages show a Reload/Home bar instead.
- Script-source policy (CSP), HSTS with subdomains, no server version headers, 240 page requests/min per address.
- **Release package:** scripts are minified and `composer.lock` is not shipped; no tool or authorship notes anywhere in shipped source (checked by a test). Removed a leftover "Anthropic" option from the AI provider list (not implemented).
- Demo mode can no longer run on a production site; Health flags it.

## Fixed (audit)
- Webhook events for payments this site never saw are acknowledged after 6 hours instead of failing for days; recent unmatched events answer 409 so the gateway retries.
- Buying a lower plan while a higher one is active is refused with an explanation.
- Hidden templates no longer block saving drafts that already use them.
- Withdrawal minimum follows the Referral Ops setting (message and API).
- Payout approval is bound to the details the owner reviewed (409 if the customer changed them).
- "Download options" saves the draft first so the export matches the screen.
- Removed the "Lifetime Updates" promise from the Pro plan text.

## Tests
`python3 tests/run_tests.py` — 250 checks (access control, fallbacks, launch checks added). Docs: `SECURITY_AND_HOSTING.md`.

# CVTara v3.4.2 — audit fixes (billing, security, privacy)

## Fixed
- **Owner step-up (F-01):** payment and email provider keys, plan prices, coupons/business controls and the integrations/email/legal/site content now require the email-code confirmation in production. The console opens the confirm dialog automatically and retries.
- **Add-ons (F-02):** renewing a plan extends the customer's live add-on packs to the new end date.
- **Watermark (F-17):** paying customers no longer see the "Preview" watermark (`/api/profile` returns `isPaid`).
- **Coupons (F-03):** limits are checked under a lock; an open order from another customer reserves a single-use coupon; an extra redemption is flagged in the Error Log.
- **Mark as paid (F-06):** the amount received defaults to 0; referral commission is paid only on real gateway payments.
- **Revoke access (F-07):** ends access without recording a refund that never happened.
- **Account deletion (F-12):** bank/UPI/PAN details inside past withdrawals are erased; the data export now includes payout and referral records.
- **Password-reset links (F-15):** built from `APP_URL`, not from the request Host header (confirmed exploitable before the fix).
- **Login redirect (F-14):** control characters in `next` rejected (a newline caused a server error).
- **Shared styles (F-18):** `owner-console.css` is no longer removed for customers, so the consent banner, forms and article covers are styled.

## Tests
`python3 tests/run_tests.py` now runs 175 checks including add-on renewal, step-up confirmation (production mode), coupon limits, grants/revoke, account deletion and the security re-checks.
Full audit status: `docs/risks_and_bugs.md`.

# CVTara v3.4.1 — Referral Ops consolidation, working night mode, test runner

## Fixed
- **Referral rules were in two places** (Business Ops and Referral Ops). They now live only in **Referral Ops**: commission %, tier, minimum withdrawal, hold days, payout window, qualifying order amount, free-download and premium rewards, PAN requirement and "paid plans only". Referral Ops also holds affiliates, creator applications, payout identity reviews and the withdrawal queue (review, send, mark paid/rejected, sync). Business Ops and Orders no longer show referral boxes.
- **Night mode** is applied to both `html` and `body` on every page, follows the device preference first, remembers the choice, and no longer flashes light first. The sign-in / register / password pages (server-rendered) now have the same switch. Low-contrast text in night mode (blue links, Delete buttons, template filters, guide table of contents) fixed; the contrast scan now reports zero issues in both themes.
- Sign-up copy no longer says "free" ("Create your account").
- **Python test errors:** the old `tests/audit-harness` scripts were order-dependent, mixed MariaDB and SQLite and contained `$AUDIT_DIR` placeholders. New `python3 tests/run_tests.py` is self-contained (throw-away SQLite DB, own PHP server, exit codes 0/1/2, clear message if PHP or `vendor/` is missing) and also checks production mode (preview sign-in closed, owner API needs login). Browser checks: `tests/ui/`.

# CVTara v3.4.0 — owner-console rebuild, content and operations

Built on 3.3.0. Everything below was verified against the PHP app (preview mode and production mode) and in a headless browser.

## Owner console (admin)
- **New menu:** Overview · Users · Orders · Operations ▸ Resume Library, CL Library, Templates, Business Ops, Referral Ops, User Mailer, Error Log, Blog & SEO, Reviews, Pricing Plans, Settings, Platform Guide. Everything else (integrations, push, policies, product controls, popup, keywords, analytics, setup tools) is inside Settings.
- **No flash of half-built UI:** the console stays covered until its first data is rendered, with an 8-second safety timeout. Fixed layout (220 px sidebar, stable scrollbar gutter) so menu clicks never change widths; page transitions fade instead of flashing white.
- **Sidebar contrast:** menu text is readable in the normal and the night theme.
- **Users:** search/filter/export; **Mark as paid** with plan, days, amount received and a note (creates a verified manual purchase, activates access at once and emails the customer "Thank you for subscribing"); edit details; revoke access; block/unblock; delete (erases personal data, keeps purchase records). Sensitive actions need the fresh e-mail-code check in production.
- **Orders:** filters (status, plan, payment source, date range, amount range, search), CSV export, and a detail popup (payment, customer, plan snapshot, timeline, e-mails).
- **Overview:** KPI cards, 7-day signups chart, 30-day revenue chart, recent orders and activity, floating live-stats card.
- **Resume Library / CL Library:** thumbnail grids with search and filters; the preview popup is 70 % document / 30 % details on desktop and stacks on phones, with the document scaled to fit.
- **Templates & site design:** homepage headline, rotating job titles, buttons and announcement bar plus title/description/keywords for every main page — pre-filled with what the site says today and editable. The public homepage reads this content.
- **User Mailer:** active/paused switch, sender `support@cvtara.com`, template editor with variables and preview, delivery queue. New template "Subscribe to download" is e-mailed (once a day) when a normal user tries a clean download.
- **Error Log:** server exceptions, browser errors (`/client-error`), failed e-mails and daily-health warnings are collected, de-duplicated and counted; filter, download CSV/JSON, mark resolved.
- **Blog & SEO:** every post with its web address (`/guides/<category>/<slug>/`), focus keyword, word count and status; premium editor (toolbar, live preview, image upload to WebP, links, Q&A, 13-point SEO checklist); sitemap/robots switches; `sitemap.xml` and `robots.txt` use `https://cvtara.com`.
- **Footer & policies:** company details, About / Terms / Privacy / Refund pages (written for CVTara, Bangalore, support@cvtara.com) with **Auto-generate**, and a list of **every website URL** with a review checkbox. The legal pages now actually display the saved text (previously the saved text was never shown). "Credits & licences" page removed.
- **Setup & health:** a health check runs daily from the cron (database, storage, HTTPS, domain, scheduler, e-mail, payments, debug flag) and keeps 60 days of history; on-demand "Run check now".
- **Platform guide:** rewritten handbook; owner-only (requires the owner login), `noindex`, not in the sitemap, not linked publicly.

## Product wording
- No free package: everyone who registers is a **normal user**; a plan unlocks downloads. "Free" removed from plan names, dashboards and marketing copy. "Naukri / LinkedIn export" perks removed.

## Content
- All **68 guide and calculator pages** now have unique bodies of 428–557 words, a focus keyword, 6–8 advanced keywords, three FAQs, internal links and image alt text.

## Hostinger
- `install.php` is the single setup link: domain (defaults to cvtara.com), database, owner, and the support@cvtara.com SMTP mailbox. It enables the mailer, writes the `.env`, migrates, creates the owner and locks itself.
- `APP_URL`, `MAIL_*` defaults point to cvtara.com / support@cvtara.com.

## Tools
- `tools/static-preview/` builds the browser-only demo (three entry pages) from a running preview server (`snapshot.py` + `build_static.py`).

# CVTara v3.3.0 — what changed since 3.2.0-RC1

Source: the 3.2.0-RC1 Hostinger package (2026-10-10), audited and extended on 2026-10-14.

## New product model
- **Primary resume + job-tailored variants.** Every account has one *primary resume* (the master copy). From it, users create a variant per job description (company, role, optional JD text). Variants link back to the primary and can be promoted.
- **Six plans + three add-on packs, all editable in Admin → Pricing & add-ons** (name, tagline, price, MRP, validity days, resumes, cover letters, ATS scans — 0 = unlimited —, exports, perks, "popular" and "referral access" flags).

  | Plan | Audience | Price | Validity | Tailored resumes | Cover letters | ATS scans |
  |---|---|---|---|---|---|---|
  | Fresher Basic | Fresher | ₹299 | 60 d | 10 | 10 | 10 |
  | Fresher Standard | Fresher | ₹399 | 60 d | 20 | 20 | unlimited |
  | Fresher Pro | Fresher | ₹499 | 90 d | 30 | 30 | unlimited |
  | Professional Basic | Experienced | ₹399 | 60 d | 10 | 10 | 10 |
  | Professional Standard | Experienced | ₹499 | 60 d | 20 | 20 | unlimited |
  | Professional Pro | Experienced | ₹599 | 90 d | 30 | 30 | unlimited |
  | Add-on 10 / 20 / 30 | any | ₹149 / ₹249 / ₹399 | with current plan | +10 / +20 / +30 | +10 / +20 / +30 | — |

  Every plan also includes **1 premium primary resume**. Clean-download allowance is set to 4 × the resume count (editable).
- Access rules: the strongest active plan wins; renewing the same plan **stacks** days; add-ons need an active plan and last as long as it does; a **partial refund keeps access**; a full refund or a payment dispute removes it.

## Fixes from the audit (all verified)
- Word (.docx) export no longer corrupts when text contains `&`, `<`, `>`.
- Referral attribution survives session expiry (30-day cookie).
- API error messages (e.g. invalid coupon) are shown instead of "Check the request and retry"; stray "Sign in" link removed.
- 16 editorial guides no longer throw a JavaScript error.
- Resume-match engine reads the real experience requirement and its priority.
- Cashfree can no longer be selected as checkout provider (it was never wired into the customer checkout).
- Coupons have total-use and per-customer limits; referrals need a minimum purchase to qualify.
- Referral cash commission is a Pro-plan benefit (switchable in Business controls).
- Chargebacks / disputes (`payment.dispute.*`) revoke access and reverse commission; a won dispute restores it.
- Mail queue: 40 per run, transactional mail first, stuck "sending" rows recovered, reminder scans no longer capped.
- New admin tools: **block / unblock users** (with audit note and re-verification), searchable paginated user list, **Setup & health** (launch checklist, business details, payout policy, held reward reviews, test email, cache clear).
- Customer data tools: download my data (JSON) and delete my account (anonymises personal data, keeps purchase records).
- English only: Hindi/Bengali options removed.
- Misc: traffic path validation, export "plan required" message, API rate limit 400/min (env `API_RATE_LIMIT`), emails on by default for welcome, receipt, expiry, follow-up and password reset.

## New experience
- Redesigned landing page, checkout, sign-in/sign-up, customer dashboard (welcome banner, usage, billing & plans, primary-resume panel) and admin console (new design system `cvtara-ui.css`).
- **Social-proof popup**: appears bottom-left after 40 seconds and rotates. Messages come from your *real* activity counts, real plan savings and messages you write in Admin → Offers & popup. No invented customers or counts.
- **/preview shell** with three views — Website home, Customer dashboard, Admin dashboard — plus demo accounts. Enable with `CVTARA_PREVIEW=true` and `php artisan cvtara:demo`. Never enable on a live site.

## Preview bundle
`python3 tools/build_preview.py --vendor <vendor>` builds `CVTara_Preview_v3.3.zip`: SQLite + demo data + demo payments, started with `start-preview.bat` / `start-preview.sh`, opened at `/preview` (Website home · Customer dashboard · Admin dashboard). In preview mode (`CVTARA_PREVIEW=true`) payments are clearly-labelled demos and the email-code re-check is skipped. Never use it on a live site.

## Fixed after first v3.3 test
- Fresh installs returned HTTP 500 on `/login`, `/register`, `/forgot-password` (missing `storage/framework/views`). The folders now ship and the installer creates them. (The original 3.2.0-RC1 package had the same defect.)
