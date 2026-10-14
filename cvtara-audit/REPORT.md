# CVTara 3.2.0-RC1 (Hostinger pre-launch package, 2026-10-10): audit report

Audit date: 2026-10-14. Package: `CVTara_Hostinger_Final_Prelaunch_2026-10-10.zip`
(Laravel 13.34, PHP 8.3, dompdf 3.1.6, phpword 1.4.0).

## How this was tested

I did not only read the code. I ran the package.

- Installed it with the real `install.php` on a fresh MariaDB 10.11.
- Ran the full app on SQLite and on MariaDB with the app's strict SQL mode.
- Drove well over a hundred HTTP scenarios through the real routes, with CSRF and sessions, as a customer and as the owner.
- Drove the admin, workspace and public pages in headless Chromium.
- Stubbed the outside providers (Razorpay, Cashfree, Twilio, Resend, OpenAI) so the payment, referral, payout and mail logic ran for real. **No live provider was contacted.**

The harness is in `harness/`. Verified patches are in `fixes/`.

**Not tested** (needs your accounts or hosting): live Razorpay, Cashfree, Twilio, Resend and OpenAI behaviour; Hostinger/LiteSpeed (`.htaccess`, cron binary, file permissions); real phones and tablets; Hindi/Bengali rendering; `install.php` on Hostinger itself.

The package contains **no `tests/` folder**, so the "40 PHPUnit tests / 211 assertions" in `LAUNCH_READINESS.md` cannot be reproduced from this zip.

## Bottom line

The core system works. These all behaved correctly, including under concurrency:

- Auth and password reset.
- Documents with optimistic locking.
- Razorpay signature, amount and replay checks.
- Refund reversal.
- The referral commission, hold and withdrawal lifecycle.
- Step-up security (email code plus password).
- Maintenance mode.

Migrations pass on MariaDB 10.11 with identical results to SQLite. `composer audit` reports no advisories.

There are **3 launch-relevant defects** (one of them a 1-line fix), **1 missing feature set** and **several design gaps** you should decide on before charging customers. Details follow.

## Findings

Severity is my judgement. "Patched" means the fix is in `fixes/` and I re-tested it.

### High

| # | Finding | Evidence | Status |
|---|---|---|---|
| H1 | **Word (.docx) export is corrupt whenever any text contains `&`, `<` or `>`**, for example "R&D", "A&B Corp", "Q&A", "<10 people". Word reports unreadable content. Paid clean exports use this path and consume an export credit. | `word/document.xml` failed XML parsing. After `Settings::setOutputEscapingEnabled(true)` all parts were valid. | **Patched** (`Documents.php`, 1 line) |
| H2 | **There is no way to block a user.** Every guard checks `users.blocked`, but nothing in the PHP or JS ever writes it. The admin Users view is read-only. | Code search, admin UI walk-through | Open |
| H3 | **The "Guided setup" has no UI.** The README says "open Guided setup". The endpoints `admin/setup/*` (business details, email test, payout policy, reward-review, cache clear) are called by no frontend file. `setup-assistant.js`, which `ContentController` loads, is not in the package, and `setup.html` just redirects to admin. **Consequences:** Cashfree Payouts can never be sent, because it requires the `payoutPolicyReviewed` setting. Reward identities that are held for review can never be released. The manual payout path (review, processing, paid) does work and was verified. | `grep` across `public_html` and `resources/frontend`; `ls` | Open |
| H4 | **Referral attribution is lost after 120 minutes of inactivity.** The UI says "last referral link opened within 30 days". The code stores the referral in the PHP session, whose lifetime is `SESSION_LIFETIME=120`. A friend who returns the next day registers unattributed. | Aged the session 3 h: `referred_by = NULL`. | **Patched** (30-day cookie fallback), verified |
| H5 | **Cashfree checkout is a configuration trap.** The admin UI offers Cashfree as the checkout provider. The backend half works (order, webhook signature, reconcile, refunds all passed in simulation). The customer checkout JS only knows Razorpay: no SDK, no `paymentSessionId`, no return handler, no phone field. Selecting Cashfree breaks checkout. `README` admits "not implemented", but the UI does not stop you. | `checkout-options.js` vs `owner-advanced.js` | Open. Hide the option until built. |

### Medium

| # | Finding | Evidence | Status |
|---|---|---|---|
| M1 | **API error messages are swallowed.** `abort(422, 'message')` texts (invalid coupon, referral/reward errors, admin explanations) are replaced with "Check the request and retry." Every non-401 error also shows a stray "Sign in" link. | Coupon `NOPE` returned the generic text. | **Patched** (`bootstrap/app.php`) |
| M2 | **16 editorial guides throw a JS error on load.** The server rewrites the description meta tag and drops `id="articleDescription"`, so `preview.js` crashes. Title and category updates are skipped. | Playwright over 68 articles: 16 failing. After the patch, 0. | **Patched** (`ContentController.php`) |
| M3 | **Match-engine requirement rules are wrong.** It takes the first "N years" anywhere in the job text ("founded 25 years ago" became "25+ years"). Priority is derived from the whole job text, so one "preferred" anywhere downgrades it. | Node case: "25+ years / Preferred" instead of "5+ years / Required". | **Patched** (`match-engine.js`) |
| M4 | **Overlapping purchases:** the latest purchase wins the limits. Buying a ₹99 single while on Annual drops you to 1 resume and 1 export for 30 days. Renewing early does not extend the existing access. | Annual then Single: limits went 12/72 to 1/1. | Open (policy) |
| M5 | **A partial refund removes all access.** Status becomes `partially_refunded`, and access requires `paid`. | ₹50 of ₹199 refunded: plan went to free. | Open (policy) |
| M6 | **Coupons have no redemption cap or per-user limit.** A fixed coupon can take a plan to ₹1. Paid referrals only need `amount_paise > 0`, so ₹1 purchases qualify toward the free-export and free-month rewards. | Same coupon reused; `FREEISH` gave a ₹1 purchase that counted as a "Qualifying paid referral". | Open. Add usage limits and a minimum qualifying amount. |
| M7 | **Disputes and chargebacks are not handled.** Only `refund.processed` reverses commissions or access. | `Payments::webhook` | Open |
| M8 | **The admin console shows a false banner.** "Preview controls · these settings are not connected to live services yet." appears on 11 views that are connected (Plans, Integrations, Emails, Legal, Blog and others). | `connected-ui.js` loop; screenshot | Open (copy fix) |
| M9 | **Admin Users view is capped at 500 users** with no search or pagination, and costs about 5 queries per user per load. With 2,004 users the dashboard showed 500. `overview` computes the list and then discards it. | Seeded 2,004 users | Open |
| M10 | **All lifecycle emails are disabled by default.** Welcome, resume saved, purchase receipt, access expiring, follow-up, abandoned checkout and blog are all off until you enable them. | `admin/content/emails` | Launch checklist |
| M11 | **Mail queue limits.** 10 mails are sent per cron run (about 600 per hour). A blog broadcast ahead of the queue delays purchase receipts. Reminder scans take 200 rows per run, unordered. A mail marked `sending` when a worker dies is never retried. | `MailAutomation::process` and `reminders` | Open |
| M12 | **The privacy policy promises export and deletion, but no tool exists.** User FKs on purchases also block deleting a customer. | Code search | Open (compliance) |
| M13 | **A shared 120/min/IP API limit** covers every `/api` call, and each public page makes 3 API calls. Crawling 40 pages tripped HTTP 429 and broke pages. Colleges and offices behind one NAT are at risk. | Crawl | Open |

### Low and informational

- The export 402 response body is `{"message":""}`, so the user is not told to upgrade. Exports also use the last server-saved draft, not unsaved local edits (the dialog warns).
- `/api/traffic` accepts any path matching its regex, such as `/../x`, and client-asserted consent. This can pollute analytics.
- Calculator labels and edge cases:
  - "Risk-adjusted package" is simply fixed + variable + benefits.
  - Entering an ending value of 0 or less in the CAGR calculator yields NaN.
  - The gratuity (wage ÷ 26 × 15 × years, 6-month round-up) and SIP formulas are correct.
- All timestamps and daily limits are UTC, so the daily match allowance resets at 05:30 IST.
- The cron command shown in Setup uses `PHP_BINARY`. Under web PHP that may be `lsphp`, which cannot run the CLI. Use `/usr/bin/php` as in the README.
- Dead code: `language-ui.js` (30 KB, unreferenced), the `#studioPlans` dialog saying "private preview", an `InstallController` import that does not exist, and the demo `data-toast` handlers in `preview.js`. The live admin removes all `data-save` and `data-toast` stubs, which I confirmed in the DOM.
- Separation-of-duties checks use `===` on ints. `setup/reward-review` accepts `ownerId` as JSON, so a string would bypass the "not yourself" check. Low, since only the owner can call it.
- The admin cookie banner overlaps the lower sidebar at 1400×900.
- `Content-Security-Policy` has no `script-src`. This is a hardening gap, not a bug.
- The calculators and editorial copy are English. Resume matching is English-focused, and PDF export correctly refuses Hindi/Bengali with a clear message.

## Connection map (frontend to backend)

Every `/api/*` call found in the 20+ frontend JS files has a backend handler. The admin endpoints exercised were:

- dashboard, overview, users, reports, reviews
- email jobs and process, seo and page-audit, plans, providers, purchases
- business-controls, referrals (creators, profiles, withdrawals, settings)
- content/* for blog, features, library, legal, emails, seo and integrations
- search/import, media, feedback/add, letter-summary

The reverse is not true: the `admin/setup/*` endpoints (H3) have no caller.

Page-level results:

- 153 HTML pages and 37 unique assets were crawled with 0 broken links or assets.
- All 68 articles load. 16 had the JS error in M2 (now patched).
- No console errors on the public, customer-workspace and admin pages I loaded, apart from M2.
- XSS probes in names, reviews, applications and drafts did not execute anywhere.

## Algorithms and models

| Area | Result |
|---|---|
| Pricing and coupons | Correct. Percent is capped at 90%, the minimum charge is ₹1, and the total is recomputed server-side. Gaps are M4 and M6. |
| Access and limits | Per-purchase snapshot of limits and exports. The "latest purchase wins" rule is M4. |
| Razorpay verification | Signature check, then a server-side fetch, then order, amount, currency and status checks. Replayed events are idempotent. Refunds partial and full were correct. |
| Referral commission | 20% default, 30% tier at 100 buyers, 7-day hold, reversed pro-rata on refund. Verified. Balance formula verified. 8 parallel withdrawals produced 1 success. |
| Rewards | Twilio OTP, unique phone, device-hold, milestone claims and the export credit all worked. |
| Payout approval | Step-up auth required. The owner cannot approve their own payout. Review, processing and paid states were enforced. Cashfree Payouts is blocked by H3. |
| Resume match engine | Deterministic and honest: it gives evidence, not a score. Fixed M3. It is dictionary-based ("Screened 300 candidates" missed "Candidate screening"). It uses regex lookbehind, so Safari older than 16.4 will fail (not tested on devices). |
| Calculators | Formulas for all 29 reviewed by reading the code (not executed one by one); gratuity and SIP are correct. See the low-severity items. |
| Database models | One Eloquent model (`User`); the rest use the query builder. The 8 migrations pass on MariaDB strict mode. There is no pruning of `activity_events`, `page_views` or `mail_outbox`. |

## Fixes provided

```bash
# inside cvtara_app/ (or the extracted payload)
patch -p1 < fixes/cvtara_app.patch
# inside public_html/
patch -p1 < fixes/public_html.patch
```

Both patches apply cleanly to the pristine package. They cover H1, H4, M1, M2 and M3. I did not patch H2, H3, H5 and M4–M13 because they need product decisions or new UI.

## Suggested order before taking money

1. Apply the patches (H1, H4, M1, M2, M3).
2. Hide or build Cashfree checkout (H5), and decide the refund and overlap rules (M4, M5).
3. Add a block-user action and the setup UI, or drop the payout-policy gate (H2, H3).
4. Add coupon limits and a minimum qualifying amount for referrals (M6).
5. Enable the email templates and test one real receipt (M10).
6. Do the live provider, Hostinger and device acceptance listed in `LAUNCH_READINESS.md`.

---

## Addendum — status in CVTara v3.3.0 (2026-10-14)

Everything above was reworked into **`cvtara-v3.3/`** (source, build script, docs). Status of each finding:

| Finding | Status in v3.3 |
|---|---|
| **NEW — H0: fresh install returns HTTP 500 on `/login`, `/register`, `/forgot-password`** | Found while testing the v3.3 build; **the original 3.2.0-RC1 package has the same defect** (it ships without `storage/framework/{views,cache,sessions}`; my first audit missed it because my test copy had those folders). Fixed: folders now ship in the package and `install.php` creates them. Fresh-install test passes. |
| H1 docx export corrupt | Fixed |
| H2 no way to block users | Fixed — Admin → Users & access (search, pagination, block/unblock with audit note and re-verification) |
| H3 Guided setup had no UI | Fixed — Admin → Setup & health |
| H4 referral attribution lost | Fixed |
| H5 Cashfree checkout trap | Fixed — Razorpay only; Cashfree rejected server-side |
| M1 swallowed API errors | Fixed |
| M2 guide-page JS error | Fixed |
| M3 match-engine years/priority | Fixed |
| M4 overlapping purchases | Fixed — strongest plan wins; same-plan renewals stack; add-ons need an active plan |
| M5 partial refund removes access | Fixed — partial refund keeps access; full refund or dispute removes it |
| M6 coupon limits / ₹1 referrals | Fixed — total and per-customer limits; minimum qualifying purchase (default ₹99) |
| M7 chargebacks | Fixed — `payment.dispute.*` revokes access and reverses commission; a won dispute restores |
| M8 false "Preview controls" banner | Fixed |
| M9 users capped at 500 / N+1 | Fixed — 100 per page, server-side search, batched queries |
| M10 emails off by default | Fixed — welcome, receipt, expiry, follow-up, password reset on by default |
| M11 mail queue limits | Fixed — 40/run, transactional first, stuck rows recovered, no reminder cap |
| M12 data export / deletion | Fixed — "Download my data" and "Delete my account" in the customer account page |
| M13 shared API rate limit | Raised to 400/min (`API_RATE_LIMIT`); logged-in users are limited per account |
| Low: traffic path, export 402 message, language options, calculators | Traffic path fixed; export message fixed; Hindi/Bengali removed; calculator wording left as is |

Not done / still open: live-provider acceptance, Hostinger and real-device testing, and plan perks that are listed text rather than features (Naukri/LinkedIn export, 1-on-1 call, priority support, lifetime updates).
