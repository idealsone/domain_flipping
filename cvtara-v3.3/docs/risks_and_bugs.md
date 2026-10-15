# CVTara engineering review: risks and bugs

Package reviewed: `cvtara-v3.3` (v3.4.0 per BUILD_INFO.json). Method: every PHP file, migration, installer, template and JS file in `public_html/` was read; JS files were syntax-checked with `node --check` (no errors); catalog/template counts were evaluated by running `data.js`, `reference-templates.js`, `premium-collection.js`, `template-curation.js` in Node; the PHP regexes in `ContentController` were run against the real templates. No Laravel runtime or database was available, so nothing was exercised against a live server. Anything that depends on the real server, browser or provider is marked **needs runtime check**. Paths are relative to `cvtara-v3.3/`; line numbers are real file lines.

No Critical finding was verified. There is no unauthenticated code execution, SQL injection, or authorisation bypass in the code that was read. The most serious problems are an admin step-up gap and billing-entitlement logic.

## Status after v3.4.2

| Finding | Status |
|---|---|
| F-01 step-up for provider keys, plans, coupons/business controls, integrations/emails/legal/site content | **Fixed** (server check + automatic confirm dialog in the console); test `s7_stepup` |
| F-02 add-ons expire before the renewed plan | **Fixed** (renewal extends live add-ons); test `s6_billing` |
| F-03 coupon limits | **Fixed/mitigated** (lock around start, open orders of others reserve the coupon for 2 days, extra redemptions are granted but flagged in the Error Log) |
| F-06 "Mark as paid" defaults to plan price | **Fixed** (amount defaults to 0; commission only on gateway payments) |
| F-07 "Revoke access" fakes a refund | **Fixed** (access ends, no refund recorded, no commission reversal) |
| F-12 deletion keeps payout details | **Fixed** (encrypted payout details erased, masked destination kept; export now includes payout and referral records) |
| F-14 open redirect via `next` | **Checked at runtime: not exploitable** (stays on the same host); control characters now rejected (a newline caused a 500) |
| F-15 reset link host | **Confirmed and fixed** (links use `APP_URL`) |
| F-17 watermark for paying customers | **Fixed** (`/api/profile` returns `isPaid`) |
| F-18 shared CSS removed for customers | **Confirmed and fixed** (file is no longer stripped) |
| F-04 lower plan purchases | **Fixed** (refused with a message) |
| F-05 webhook errors for unmatched events | **Fixed** |
| F-10 hidden template blocks saves | **Fixed** |
| F-13 payout approval not bound to reviewed version | **Fixed** |
| F-16 withdrawal minimum hard-coded | **Fixed** |
| F-19 export of unsaved edits | **Fixed** |
| F-23 plan perks | **Partly fixed** (lifetime updates removed; owner must confirm support/call perks) |
| F-24 demo mode in production | **Fixed** |
| F-08 dashboard numbers in the browser | **Fixed** (server totals) |
| F-09 Orders/Users pagination and filters | **Fixed** (server-side) |
| F-11 print watermark | **Improved** (tiled in the document, restored if removed; a determined user can still screenshot) |
| F-20 reminder scans, F-21 settings reads, F-22 retention | **Fixed** |
| F-25 verified reviews | **Fixed** |
| Low: F-26, F-27, F-28, F-31, F-33, F-34, F-35, F-36, F-39, F-40, F-41, F-42, F-43, F-44, F-53, F-55, F-56, F-60, F-61, F-65 | **Fixed** |
| Low: F-37 | **Superseded** (Cashfree checkout is now the primary gateway) |
| Low, deliberately left for later (UI refactors or product decisions): F-29, F-30, F-32, F-38, F-45–F-52, F-54, F-57–F-59, F-62–F-64 | Open |

## Summary

| Severity | Count |
|---|---|
| Critical | 0 |
| High | 2 |
| Medium | 22 |
| Low | 40 |

Severity reflects impact times likelihood for a small paid SaaS about to take real Razorpay payments.

---

# High

### F-01 Step-up verification is skipped for the highest-impact admin writes
**Severity:** High
**Where:** `app/app/Http/Controllers/AdminController.php:75-120` (step-up only at `:90-104` for the `verification` and `payouts` services), `:121-127` (business controls and coupons), `:128-144` (plans), `:37-66` (content including `integrations`, `emails`, `legal`), `:235` (`email/process`), `:240` (`media`); `app/app/Services/BusinessControls.php:18-34`. Login itself is password-only (`app/app/Http/Controllers/AuthController.php:11`).
**Why it matters:** `AccountSecurity::requireRecent` guards user block/grant/delete and payouts, but the `payments` provider (Razorpay key id, key secret, webhook secret), the `email` provider (SMTP host/credentials, Resend key), `ai`, `analytics`, `push`, coupons, plan prices and the maintenance switch can be changed with only a logged-in admin session. There is no MFA and the CSP has no `script-src` (F-33).
**Scenario:** An attacker with a hijacked admin session (stolen cookie, XSS in any admin-rendered field, shared computer) posts `{service:'payments', values:{razorpayKeyId:'rzp_live_ATTACKER', ...}}`; every new order is created on the attacker's Razorpay account. Or sets `smtpHost` to a server they run and then triggers password resets for any user, receiving the reset links and security codes (reset mails are queued through the same outbox, `AuthController.php:13`).
**Fix:** Call `AccountSecurity::requireRecent` for every `providers` service, `plans`, `business-controls`, `content/integrations|emails|legal|site`, maintenance and `media`; add TOTP/WebAuthn for owner accounts; log provider changes with old key id (never the secret) and email the owner on change.

### F-02 Add-on packs expire with the old plan row, not with the renewed plan
**Severity:** High
**Where:** `app/app/Services/Payments.php:73-77` (add-on `access_until` = `Settings::access()['accessUntil']` at purchase time), `app/app/Services/Settings.php:128-133` (only rows with `access_until > now` count), promise at `public_html/plans-ui.js:75` ("last as long as it does").
**Why it matters:** A renewal creates a second same-plan purchase whose end date is later, but existing add-on rows keep their original end date.
**Scenario:** Day 0 buy Pro Standard (60 days, ends day 60). Day 10 buy add-on-10: `access_until` = day 60. Day 30 renew Pro Standard: new row ends day 120. On day 61 the plan is active but the add-on row is expired: `savedResumes`/`savedLetters`/`exports` drop by the pack size, new drafts are refused ("Your resume allowance is full") and the customer has paid for capacity that silently vanished.
**Fix:** When a same-plan renewal is captured, extend every active add-on to the new plan end (or compute add-on validity at read time as "while any plan is active"); add a regression test for plan + add-on + renewal.

---

# Medium

### F-03 Coupon redemption limits can be bypassed (concurrency and time-window gaps)
**Severity:** Medium
**Where:** `app/app/Services/BusinessControls.php:44-56`, `app/app/Services/Payments.php:27-30,63-89`.
**Why:** Limits are checked only when an order is *created*. `perUser` counts only `paid|partially_refunded` rows (`:51-54`), `maxUses` counts `created` rows only if younger than one hour (`:47-50`), nothing is re-checked in `capture()`, and there is no lock around quote-then-insert.
**Scenario:** A single-use coupon (`perUser=1`): the user opens `/checkout.html` in two tabs, starts two orders (both pass the check because neither is paid yet), pays both. Or `maxUses=1`: user A starts an order, the hour passes, user B starts and pays, user A then pays the still-valid Razorpay order: two redemptions.
**Fix:** Re-check limits inside `capture()` under a lock on a coupon counter row (or a unique index on `(coupon_code, owner_id)` when `perUser=1`), count `created` rows regardless of age while the Razorpay order is open, and expire stale `created` orders.

### F-04 Plans are not reconciled across ranks; lower purchases waste money and upgrades are not prorated
**Severity:** Medium
**Where:** `app/app/Services/Settings.php:90-105` (highest rank wins), `app/app/Services/Payments.php:73` (stacking only for the same plan), `public_html/plans-ui.js:40-43` (every non-current plan shows "Get ..."), `app/app/Http/Controllers/ApiController.php:263-275` (only add-ons are guarded).
**Scenario:** A Pro Pro customer (rank 3) clicks "Get Fresher Basic": the purchase succeeds, runs concurrently, and is invisible because the higher rank always wins; both clocks run. An upgrade from Basic to Standard on day 5 makes Standard effective immediately but Basic's remaining 55 days simply evaporate.
**Fix:** Refuse (or warn on) purchases of a plan ranked at or below the current one, and either prorate or queue the lower plan to start when the higher ends.

### F-05 Webhook endpoint answers with an error to events it cannot match (needs runtime check)
**Severity:** Medium
**Where:** `app/app/Services/Payments.php:66-69` (unknown order throws `ValidationException`), `:121-123,127-130` (unknown payment), `app/bootstrap/app.php:27-31` (only `api/*` gets JSON rendering, so a webhook validation error becomes a redirect response).
**Why:** If the Razorpay account is shared with another site, or events arrive out of order, every unmatched `payment.captured`/refund/dispute event returns a non-2xx response. Razorpay retries for a day and may then disable the webhook.
**Scenario:** The owner uses the same Razorpay account for another storefront; its payments hit this webhook, fail forever, the webhook is deactivated, and CVTara stops receiving refund and dispute events: refunded customers keep access and commissions are not reversed.
**Fix:** For orders whose receipt is not a CVTara purchase id return 200 and log; only return an error for CVTara-owned orders that are genuinely not found yet, and give the response an explicit 4xx JSON status instead of a redirect.

### F-06 "Mark as paid" defaults the amount to the plan price, producing revenue and cash commission without any payment
**Severity:** Medium
**Where:** `app/app/Http/Controllers/AdminOpsController.php:102,107-108` (`amount ?? plan price`, `is_live = paise > 0`), `app/app/Services/Referrals.php:45-58` (credit checks only `is_live` and status), `public_html/admin-people.js:69,81` (blank amount allowed).
**Why:** A manual grant with a blank amount is stored as a live purchase for the full price, counted in revenue, and, if the user was referred by a Pro user, creates a real withdrawable commission. Referral *rewards* exclude manual purchases (`ReferralRewards.php:76-86`) but cash commission does not.
**Scenario:** The owner comps a referred friend by leaving the amount blank; the referrer later withdraws 20% of a payment that never happened.
**Fix:** Require an explicit amount (0 allowed), default the field to 0, and make `Referrals::credit` ignore `provider` values other than the live gateway.

### F-07 "Revoke access" records a refund that never happened
**Severity:** Medium
**Where:** `app/app/Http/Controllers/AdminOpsController.php:141-147` (sets `status=refunded`, `refunded_paise=amount_paise`, reverses commission), `public_html/admin-people.js:86-89`.
**Why:** No money moves through Razorpay, yet reports show a full refund; a later real refund webhook is clamped by `min()` but the books no longer reconcile with settlements.
**Fix:** Add a separate `revoked` status (or `access_revoked_at`) that removes access without touching `refunded_paise`; only reverse commission when a real refund exists.

### F-08 Owner dashboard numbers are computed in the browser from truncated and unfiltered data
**Severity:** Medium
**Where:** `public_html/admin-ops.js:105-113` (`/api/admin/users` returns only page 1 = 100 users; `/api/admin/purchases` returns the latest 500, `AdminController.php:145`), `:129-140` (revenue, weekly signups, conversion, 30-day chart), `:113` (`paidOnes` filters by status text only).
**Why:** After 100 signups or 500 orders the KPIs silently undercount; test-mode (`is_live=0`), `demo`, `manual` and zero-value `referral_reward` purchases are included in "Revenue".
**Fix:** Compute KPIs server-side with SQL (`SUM(amount_paise-refunded_paise) WHERE is_live=1 AND provider IN (...)`), return aggregates from `admin/overview`, and drop the 500-row client aggregation.

### F-09 Orders view has no pagination; user status filters work only on the current page
**Severity:** Medium
**Where:** `app/app/Http/Controllers/AdminController.php:146` (`limit(500)`), `public_html/admin-people.js:114` (orders), `:36-38` (filter applied client-side to the 100 rows of the current page; the "Blocked" KPI counts only those rows).
**Scenario:** Order 501 and older cannot be found for a refund dispute; selecting "Paid" shows only paid users on the visible page while the counter says "N match".
**Fix:** Server-side filters and `forPage` pagination for orders and users.

### F-10 Unpublishing a template breaks saving existing drafts
**Severity:** Medium
**Where:** `app/app/Http/Controllers/ApiController.php:467` (aborts 422 when `library.templates[slug].published === false`, for create *and* update), `app/bootstrap/app.php:35` (empty 422 message becomes "Check the request and retry.").
**Scenario:** The owner hides a template from the library; every customer who already built a resume on it gets "Profile save failed: Check the request and retry." with no explanation and cannot save further edits unless they change the template.
**Fix:** Allow updates for drafts that already use the template; apply the published check only on new drafts and on template changes; return a clear message.

### F-11 The "preview" print layout is the full document with a removable watermark
**Severity:** Medium
**Where:** `app/app/Http/Controllers/ExportController.php:9-11` (non-clean print is free for everyone), `app/resources/views/document-print.blade.php:1` (watermark is a `position:fixed` div; the page ships a "Print / Save as PDF" button).
**Why:** A free user deletes the div (DevTools) or prints the in-app preview and gets a clean PDF without a plan. PDF and DOCX previews bake the watermark on the server, so only the print path leaks.
**Fix:** Serve preview print layouts as watermarked images/PDF only, or accept the leakage knowingly; at minimum render the watermark into the document layout rather than a removable overlay.

### F-12 Account deletion leaves full payout PII in `withdrawals`; export is incomplete
**Severity:** Medium
**Where:** `app/app/Http/Controllers/ApiController.php:396` (table list omits `withdrawals`, `referral_commissions`, `error_logs`), `app/app/Services/Referrals.php:93-100` (withdrawal stores the whole decrypted payout profile: name, phone, address, bank/UPI, PAN), export at `ApiController.php:375-387` (omits payout profile, withdrawals, commissions, referral identity, creator application, mail log).
**Why:** The UI promises that personal data is erased and only "purchase records" are kept (`public_html/workspace-ui.js:55`); encrypted bank details and PAN of withdrawn users remain indefinitely.
**Fix:** After the legal retention period (or immediately for rejected rows) null `destination_encrypted`, keep only masked destination and reference; include payout/referral data in the export; document retention.

### F-13 Payout profile approval is not bound to the version the admin reviewed
**Severity:** Medium
**Where:** `app/app/Services/AffiliateProfiles.php:25-53` (user can resubmit while pending, status resets), `:55-65` and `app/app/Http/Controllers/AffiliateAdminController.php:49-61` (approve takes no hash/revision).
**Scenario:** The admin opens a pending profile (decrypt is audited), the user submits different bank details a minute later, the admin clicks Approve and the *new*, unreviewed destination becomes `approved` (the 48-hour `eligible_at` restarts but approval is already recorded).
**Fix:** Send `destination_hash` or `updated_at` with the decision and reject with 409 when it differs.

### F-14 Open redirect through `next` is probably still possible (needs runtime check)
**Severity:** Medium
**Where:** `app/app/Http/Controllers/AuthController.php:10` (register) and `:11` (login): accepted when it starts with `/`, not `//`, contains no `\`.
**Why:** Values such as `/<TAB>/evil.example` pass these checks; browsers strip tab/newline characters when parsing a `Location` header URL, turning it into `//evil.example`.
**Fix:** Reject any control character (`preg_match('/[\x00-\x1f\x7f]/')`) and require the pattern `^/[A-Za-z0-9_\-./?=&%]*$`; or map `next` to an allow-list of internal paths.

### F-15 Host-header poisoning of password-reset links (needs runtime check)
**Severity:** Medium
**Where:** `app/app/Http/Controllers/AuthController.php:13` (`url('/reset-password/'.$token)`), `app/bootstrap/app.php:14-19` (no `trustHosts`).
**Why:** `url()` derives the host from the request. If the vhost answers requests with an arbitrary `Host`, an attacker who requests a reset for a victim with `Host: evil.example` makes the system email the victim a link to the attacker's domain; clicking leaks the token.
**Fix:** `->trustHosts(at: [APP_URL host])` or build links from `config('app.url')`; also set `URL::forceRootUrl`.

### F-16 Withdrawal minimum is configurable but hard-coded in the API
**Severity:** Medium
**Where:** `app/app/Http/Controllers/ApiController.php:313` (`min:100000`), `app/app/Services/BusinessControls.php:20` (`growth.minimumPaise` min 100), `app/app/Services/Referrals.php:96`, `public_html/referral-center.js:11` and `referral-dashboard.js` (UI shows the configured minimum).
**Scenario:** The owner sets the minimum to Rs 500; the UI invites a Rs 500 request; the API answers "The amount paise field must be at least 100000" and the error text in `Referrals.php:96` still says Rs 1,000.
**Fix:** Validate `min` from `Referrals::config()['minimumPaise']` and build the message from it.

### F-17 Paying customers see the "Preview" watermark; the paid flag is read from a field that does not exist
**Severity:** Medium
**Where:** `public_html/owner-public.js:15` (`applyWatermark(p.isPaid)`), `app/app/Http/Controllers/ApiController.php:160` (profile response has `profile`, `isOwner`, `user` only), watermark CSS `public_html/premium-products.css:29` and `owner-console.css`.
**Why:** `p.isPaid` is always `undefined`, so `free-resume-preview` is applied to every user; the cover-letter live preview always shows the diagonal watermark (CSS fallback text even says "Free preview").
**Fix:** Return `isPaid` (from `Settings::access`) in `GET /api/profile`, or have `workspace-ui.js` toggle the class from `/api/allowance`.

### F-18 `owner-console.css` is stripped for non-admins although public UI depends on it (needs runtime check)
**Severity:** Medium
**Where:** `app/app/Http/Controllers/ContentController.php:194-196` removes `<link href="owner-console.css">` unless admin. Classes defined only in that file: `owner-consent` (analytics banner), `owner-form`, `owner-fields`, `owner-help`, `owner-preview-dialog` (profile onboarding dialog), `owner-article-cover`, `owner-faq`, `owner-review-grid`, `owner-unavailable`, and the resume-studio watermark rule (`grep` over all CSS files shows no other definition).
**Why:** The consent banner, feedback form, profile forms and article covers are rendered by public scripts (`owner-public.js`, `profile-onboarding.js`) but are probably unstyled for visitors and customers.
**Fix:** Move the shared rules into `cvtara-ui.css` (loaded for everyone) and keep admin-only rules in the stripped file.

### F-19 "Download options" exports the last saved server copy, not the edits on screen
**Severity:** Medium
**Where:** `public_html/resume-studio.js:106` (`save()` writes only localStorage, then `CVTaraExport('resume', data._profileId)`), `public_html/launch-ui.js:3` (server renders from the stored row), `public_html/cover-letter-studio.js:46`.
**Scenario:** A paying customer fixes a typo, clicks Download, receives the previous version and has spent one of their limited clean exports (`ExportController.php:12`).
**Fix:** Call `saveProfile()` (await it) before opening the export dialog, or warn when the draft has unsaved changes.

### F-20 Cron reminders re-scan and re-queue everything every minute (N+1)
**Severity:** Medium (performance)
**Where:** `app/app/Services/MailAutomation.php:81-96` called by `cvtara:cron` every minute (`app/routes/console.php:12-22`): for each expiring purchase, abandoned order and today's follow-up it runs `User::find` and `queue()`, and each `queue()` does `Settings::get('emails')` plus a defaults-file read before the dedupe `insertOrIgnore` rejects it.
**Scenario:** 2,000 applications with today's follow-up date means about 2,000 x (user query + settings query + JSON read + insert attempt) every minute, all day.
**Fix:** Pre-join users, load templates once per run, restrict each reminder to rows not yet in `mail_outbox` (anti-join on `dedupe`), and run reminders hourly rather than per minute.

### F-21 No settings memoisation; several endpoints are heavy and called repeatedly
**Severity:** Medium (performance)
**Where:** `app/app/Services/Settings.php:16-29` (one query per call; defaults file decoded when the row is missing; the 314 KB editorial file is decoded on every `get('blog')`), `app/app/Http/Middleware/OwnerMaintenance.php:11-26` (three settings queries on every web request), `app/app/Http/Controllers/ContentController.php:103,126,134` (guide page: blog + features + legal + seo queries), `public_html/home-ui.js:50,56` plus `owner-public.js:3` (the home page calls `/api/public-content` **three** times, each decoding the editorial file and returning legal text and all post metadata), `ContentController.php:38-50` (`audit()` decodes the editorial file once per page, about 77 times, via `AdminController.php:252`).
**Fix:** Memoise `Settings::get` per request (static array) and `Cache::remember` the assembled public-content payload; fetch it once on the client; read the editorial file once.

### F-22 Unbounded growth: audit, page-view, usage, webhook and mail tables have no retention
**Severity:** Medium
**Where:** `Settings::audit` is called on almost every action (`app/app/Services/Settings.php:147-150`; `activity_events` indexed only on `owner_id`, `2026_10_03_000001_roledost.php:12`); `page_views` (`:17`), `product_usage`, `webhook_events`, and `mail_outbox` rows keep full bodies after sending (`MailAutomation.php:67`), including reset links and receipts. Only `error_logs` is pruned (`ErrorLog.php:28-30`). The popup counters query `activity_events` by `event` and date without a matching index (`ApiController.php:55`).
**Fix:** Add a nightly prune (events 90 days, sent mail bodies 30 days, page views 400 days), an index on `(event, created_at)`, and null `body` after delivery.

### F-23 Plan perks promise features that do not exist
**Severity:** Medium (consumer-protection risk)
**Where:** `app/app/Services/Settings.php:54-59` ("Email support", "Priority support", "1-on-1 Resume Review Call", "Referral Programme + Lifetime Updates"); BUILD_INFO.json lists the same under `known_launch_blockers`; `docs/LAUNCH_READINESS.md`.
**Why:** There is no code to book a call, prioritise support or deliver "lifetime updates"; MRP strike-through prices are permanent (`mrp` never expires) and the popup says "save Rs X today" (`ApiController.php:64`).
**Fix:** Remove or fulfil each perk before launch; show MRP only with a real time-bound offer.

### F-24 Preview-mode footguns: step-up bypass, passwordless login route, fixed demo password, no health flag
**Severity:** Medium
**Where:** `app/app/Services/AccountSecurity.php:50` (returns early when `app.preview`), `app/routes/web.php:21-22` (`/preview/go/{who}` logs in as the demo admin with a GET and no password, so any page can log a visitor in by an image tag), `app/config/app.php:72` (fixed `demo_password`), `app/app/Services/Health.php:12-45` (no check that preview is off).
**Scenario:** `CVTARA_PREVIEW=true` copied to production once (the README suggests adding it to `.env` for staging) removes the step-up for every admin action and fakes payments.
**Fix:** Refuse to boot with preview enabled when `APP_ENV=production` or `APP_URL` is https non-local; add a red Health check; remove the GET login or require a POST token.

### F-25 Review testimonials can be authored by the admin and marked "verified" with a free-text note
**Severity:** Low-Medium (compliance)
**Where:** `app/app/Http/Controllers/AdminController.php:224-231` (owner id = admin, `source=owner_verified`, consent reference is free text), `:213-223` (only `dummy` is blocked from publishing).
**Why:** Public reviews with names, roles and cities can be fabricated without a link to a real customer.
**Fix:** Require a `user_id` of an actual customer for `owner_verified` reviews and keep an audit trail of the consent source.

---

# Low

## Billing, referrals and mail

### F-26 Same-plan captures running concurrently overlap instead of stacking
**Severity:** Low
**Where:** `app/app/Services/Payments.php:63-74`. The lock is on the purchase being captured only; the `max(access_until)` read for other rows is a non-locking snapshot.
**Scenario:** Two orders for the same plan are paid within milliseconds (verify + webhook for two different orders): both compute the same start date. **Fix:** lock the user row (`User::whereKey(...)->lockForUpdate()`) first, as the document endpoints do.

### F-27 Commission credit skips silently when the referrer is not on a referral plan, and has no minimum
**Severity:** Low
**Where:** `app/app/Services/Referrals.php:45-58` (returns without a trace; no `minQualifyingPaise` check, so a Rs 1 coupon purchase earns a commission row).
**Fix:** Audit skipped credits; apply the minimum qualifying amount to commissions too.

### F-28 Expiry and abandoned-cart reminders ignore later purchases
**Severity:** Low
**Where:** `app/app/Services/MailAutomation.php:83-90`. A customer who renewed still gets "access expiring" for the older purchase row; one who completed a second order still gets "abandoned purchase" for the first.
**Fix:** Skip when the user has any active purchase with a later `access_until` / any later paid purchase.

### F-29 Promotional and upsell mail is not consent-gated; the welcome mail goes to unverified addresses
**Severity:** Low
**Where:** `resources/data/defaults.json` (`download-locked`, `expiry`, `welcome` enabled), `app/app/Services/MailAutomation.php:25` (consent check only for `blog_published` and `abandoned_purchase`), `app/app/Http/Controllers/ExportController.php:9`, `AuthController.php:10` (registration needs no email proof).
**Scenario:** Someone registers other people's addresses (5 per minute per IP) and each receives a CVTara welcome mail. **Fix:** gate promotional triggers on `marketing_consent`, send the welcome mail only after verification.

### F-30 Security codes depend on the cron; the "test email" is a real security code
**Severity:** Low
**Where:** `app/app/Services/AccountSecurity.php:14-24` only inserts into `mail_outbox`; `app/app/Http/Controllers/SetupController.php:14` ("email-test" calls `AccountSecurity::send`), which consumes one of three codes per 15 minutes (`:15` of `AccountSecurity`).
**Fix:** Process security mail inline after queuing; send a plain test message instead.

### F-31 Manual "process mail now" refreshes the scheduler heartbeat
**Severity:** Low
**Where:** `app/app/Services/MailAutomation.php:95` (`reminders()` writes `cronHeartbeat`), `AdminController.php:235-238`.
**Why:** Health shows "scheduler OK" for 10 minutes after the owner clicks the button even if cron is dead. **Fix:** write the heartbeat only from `cvtara:cron`.

### F-32 OTP budgets can be exhausted by a handful of accounts
**Severity:** Low
**Where:** `app/app/Services/ReferralRewards.php:41-43` (100 SMS per day globally, hit counted before Twilio is called).
**Scenario:** An attacker with ten accounts uses the 100 daily sends; no legitimate user can verify a phone for the rest of the day. **Fix:** per-IP budgets only, or CAPTCHA, and count only successful sends globally.

### F-33 CSP carries no `script-src`, so any injected script runs (hardening)
**Severity:** Low
**Where:** `app/app/Http/Middleware/SecurityHeaders.php:17`. The code escapes consistently (see "What is solid"), so this is defence in depth; admin pages use inline `onerror` handlers (`admin-publish.js:76`) that a strict CSP would need removed first.
**Fix:** Add `script-src 'self' https://checkout.razorpay.com https://www.googletagmanager.com` and drop inline handlers.

### F-34 Coupon codes can be enumerated
**Severity:** Low
**Where:** `app/app/Http/Controllers/ApiController.php:263-269`, `BusinessControls.php:44-45`. Only the global 400/min API limit applies and the error distinguishes unknown from unavailable codes. **Fix:** a dedicated `5/min` throttle on `checkout/quote` with a single generic message.

### F-35 Sensitive text lingers in mail and error logs
**Severity:** Low
**Where:** `MailAutomation.php:67` keeps the body of sent mails (password reset URL with token) in `mail_outbox`; `ErrorLog.php:38` stores `getMessage()`, which for query exceptions contains SQL with bound values (emails, mail bodies including security codes); `routes/web.php:20` lets anyone create up to 30 distinct `error_logs` rows a minute, evicting real entries when the 5,000-row cap prunes (`ErrorLog.php:28-30`).
**Fix:** blank bodies of `password_reset` and `security_verification` mails after sending, redact SQL bindings, and cap browser-sourced rows separately.

### F-36 Reward-review self-approval check can be bypassed with a string id
**Severity:** Low
**Where:** `app/app/Http/Controllers/SetupController.php:17` (`abort_if($v['ownerId'] === $r->user()->id, 403)`); `integer` validation accepts `"12"` and returns the string, so `"12" === 12` is false. **Fix:** cast to int first.

### F-37 Cashfree checkout remnants keep an unused payment surface alive
**Severity:** Low
**Where:** `app/routes/web.php:25` (live webhook route), `app/app/Services/CashfreeCheckout.php` (whole class, never started), `ApiController.php:283-288,301` (`provider=cashfree` branch and counting), `AdminController.php:77,111-113` (credential fields plus two contradictory `payments` validation blocks), `SetupController.php:10` (`cashfreeConfigured`, `cashfreeWebhookReceived`), `admin-ops.js:85-86`, `app/resources/frontend/setup.html:5` ("Cashfree and Paytm credentials"). The Razorpay-only restriction is enforced at `AdminController.php:112-113`.
**Fix:** Delete the checkout class, route and fields; keep only Cashfree Payouts.

### F-38 Payout reconciliation polls settled transfers every minute for 30 days
**Severity:** Low
**Where:** `app/app/Services/CashfreePayouts.php:102-111` (processing rows and `paid` rows younger than 30 days, 10 per run). With more than ten such rows the cron makes ten Cashfree API calls (20 s timeout each) every minute, delaying mail processing that follows in `routes/console.php:12-21`. **Fix:** poll `processing` only; check `paid` once after 24 hours.

### F-39 Manual payout `reference` uniqueness surfaces as a server error
**Severity:** Low
**Where:** unique index `withdrawals.reference` (migration `2026_10_03_000001_roledost.php:21`), `app/app/Services/Referrals.php:107-125` (no pre-check). A repeated UPI reference gives HTTP 500 and an error-log entry. **Fix:** validate uniqueness and return a message.

### F-40 Concurrent duplicate webhook deliveries end in a 500 (needs runtime check)
**Severity:** Low
**Where:** `app/app/Services/Payments.php:109-143`: two simultaneous deliveries both pass the `exists()` check; the second hits the primary key at insert and rolls back (data stays correct) but returns 500 and writes an error-log row. **Fix:** `insertOrIgnore` the key first and return 200 when already present.

## Privacy and data handling

### F-41 Drafts stay in `localStorage` after logout
**Severity:** Low
**Where:** `public_html/resume-studio.js:14,22,79`, `cover-letter-studio.js:3,6`; nothing clears them on logout (`public_html/launch-ui.js:10` posts the logout form). On a shared computer the next person with the same account id or a guest session sees name, email, phone and experience. **Fix:** clear both keys on logout and after account deletion.

### F-42 Device tracking cookie and analytics identifiers need disclosure
**Severity:** Low
**Where:** `AuthController.php:9` sets a one-year `cvtara-device` identifier on every auth page without consent; `ReferralRewards.php:51-74` uses its hash for fraud checks. **Fix:** list it in the privacy policy or limit it to the referral-phone step.

### F-43 Admin email change keeps the "verified" flag
**Severity:** Low
**Where:** `AdminOpsController.php:120-135` updates `users.email` without clearing `email_verified_at`; referral and payout gates trust that flag. **Fix:** null it on change and notify the old address.

## UI and JavaScript defects (found statically)

### F-44 Literal `'+r.resumes.length+'` printed in the Account page
**Severity:** Low
**Where:** `public_html/connected-ui.js:40`. The count was concatenated inside a template literal, so the heading renders as "My saved resumes '+r.resumes.length+'" (verified by evaluating the template in Node). **Fix:** use `${r.resumes.length}`.

### F-45 Legacy and dead admin modules fight over the same containers
**Severity:** Low
**Where:** `connected-ui.js:48-58` (`admin()` renders `#admin-users` with `u.resume_count`, a field the API never returns, so the column shows `undefined`), `admin-extras.js:30-53,163` ("other scripts render #admin-users too": a MutationObserver re-renders it; its pill still says "Free"), `admin-ops.js:79-95,165-180` (`errors()`/`orders()` overridden by `admin-people.js:195-197`, and the legacy error view reads `j.template`, `j.to_email`, `j.last_error` which the API does not send), `app/resources/frontend/admin/preview/index.html` (stale copy of the console), `preview.js:196` (contact form stub says "SMTP delivers it after Hostinger setup").
**Fix:** Delete the superseded renderers and the duplicate page; keep one owner per view.

### F-46 Event listeners are added again on re-initialisation
**Severity:** Low
**Where:** `public_html/preview.js:198` re-runs `initHome()`/`initTemplates()` on `cvtara-library-refresh`; `initTemplates` re-binds the filter buttons and search input (`:102`) and `initHome` re-adds a `mouseout` exit-intent handler (`:93`) and tab handlers; `public_html/referral-dashboard.js:37-38` registers the same listener twice (de-duplicated only by the `inFlight` guard). **Fix:** bind once with delegation or guard with a data flag.

### F-47 Client limits and server limits disagree
**Severity:** Low
**Where:** tracker job link `maxlength="2000"` (`workspace-activity.js:11`) against server `max:1000` (`ApiController.php:234`); registration accepts any category string (`AuthController.php:10`) while `profile` requires the enumerated list (`ApiController.php:163`), so editing the profile of such a user fails until they pick a listed category. **Fix:** share the limits.

### F-48 Export dialog and checkout button edge cases
**Severity:** Low
**Where:** `launch-ui.js:3` opens a blank tab for "print" before the request and never closes it when the export fails (e.g. 402); `checkout-options.js:63` re-enables the pay button in `finally` while the Razorpay modal is open, so a double click creates a second order and a second `abandoned_purchase` candidate. **Fix:** close the window on failure; keep the button disabled until the modal is dismissed.

### F-49 Article pages are rendered three times, and the client renderer overrides the server one
**Severity:** Low
**Where:** server markdown at `ContentController.php:112-124`; `preview.js:171-176` rebuilds the article with a generic boilerplate and widget; `owner-public.js:9-11` replaces the body again with `content-format.mjs markdown()`, which supports no ordered lists, tables or code blocks. Current content uses only the supported subset, so this is latent.
**Scenario:** visitors see a flash of boilerplate before the real body; an owner who writes a numbered list gets different output for crawlers (CommonMark) and browsers.
**Fix:** render once on the server and let JS only enhance the calculator widget.

### F-50 ATS "allowance" is enforced only after a client-side analysis
**Severity:** Low (business logic)
**Where:** `public_html/connected-ui.js:34` calls `/api/features/use` after `match-engine.js` has produced results in the browser; the engine is a public static file, so limits are a courtesy. If the count request fails the report is hidden, but nothing stops direct use of `CVTaraMatch.analyse`. **Fix:** accept that scans are unmetered, or move analysis server-side.

### F-51 The stored job description is never shown or reused
**Severity:** Low (feature gap)
**Where:** `ApiController.php:366` stores `job_description`; `GET /api/resumes/{id}` returns it, but no JS reads it (grep over `public_html`); the match view asks the user to paste the job text again. The "tailored variant" is a plain copy of the primary resume. **Fix:** prefill the match view from the variant's JD and make the copy semantics explicit in the UI.

### F-52 Word export is an unformatted dump that ignores the template and section labels
**Severity:** Low
**Where:** `app/app/Services/Documents.php:15` prints each row field value as its own paragraph (including `link`, `start`, `end`), ignores `sectionLabels`, template and bullets structure; newlines inside `bullets` are plain text runs (needs runtime check in Word). **Fix:** build proper bullet lists and headings.

## Configuration, operations and copy

### F-53 Inconsistent copy: leftover "Free" wording, "preview" wording, Hindi/Bengali remnants
**Severity:** Low
**Where:** CHANGELOG says "Free" was removed, but: `workspace-ui.js:35,43` (ring shows "Free plan", status pill "Free"), `ApiController.php:185` (`?? 'Free'`), `admin-extras.js:40`, `auth.blade.php:32`, `preview.js:18,23,93` ("Start free", "Check free", "save drafts ... for free"), `owner-console.css`/`premium-products.css` ("Free preview" watermark fallback), `app/resources/frontend/admin.html:836`. "Private preview" strings in production UI: `cover-letter-studio.js:12` ("Clean PDF / Word exports are not active in this preview"), `resume-studio.js:36` (unused dialog with the same claim), `referral-dashboard.js:21`, `admin.html:7` (title "Owner Console Preview"). The product is English-only but still contains Hindi/Bengali code: section labels in `reference-templates.js:13`, the language note in `match-engine.js:49`, `documentLanguage hi/bn` checks in `ExportController.php:11`, sample resumes list "Hindi" as a language (`reference-templates.js:11`, `resume-studio.js:53,81`), and `business-ui.js:3` removes language selectors at runtime that still exist in `guide.js`.
**Fix:** one copy pass; delete the hi/bn branches.

### F-54 Static code and config that does nothing
**Severity:** Low
**Where:** admin fields `oneSignalRestApiKey`, `replyTo`, Razorpay `mode` are stored but never read (`AdminController.php:77`; Razorpay live/test comes from the key prefix, `Payments.php:30`); `Payments::start` ignores its `$phone` argument; `bonusDays` in the quote (`BusinessControls.php:59`); `beneficiaryId` in `AffiliateProfiles::approve` (never validated, always null); `web.php:7` loop; `X-CVTara-Request` header sent by `profile-api.js:3` is not checked; stale selectors in `business-ui.js:17`; `window.CVTaraLanguage?.set` (no such object); `$p->email` mutation of a query-builder row (`ApiController.php:158`). **Fix:** remove or implement.

### F-55 Health and setup checks hard-code the production domain and disagree with each other
**Severity:** Low
**Where:** `app/app/Services/Health.php:29` (fails when `APP_URL` lacks `cvtara.com`, so staging is permanently "attention"); heartbeat windows 10 minutes (`Health.php:?`/`snapshot`) versus 5 minutes (`SetupController.php:10`); `cronCommand` in the status API uses `PHP_BINARY` (the console UI replaces it with `/usr/bin/php`, `admin-extras.js:131`, but other consumers would show the web SAPI binary and `private-cron.php` exits unless the SAPI is `cli`). **Fix:** read the expected domain from config and share one threshold.

### F-56 No trusted-proxy configuration; IP-based limits depend on the server layout (needs runtime check)
**Severity:** Low
**Where:** `app/bootstrap/app.php:14-19`. Behind Cloudflare or another proxy, `$request->ip()` is the proxy's address, so the login/register/forgot throttles, the referral-click key and the OTP IP budget would be shared by all visitors; `isSecure()` also depends on forwarded headers. **Fix:** configure `trustProxies` for the actual front end.

### F-57 Admin media uploads have no cleanup
**Severity:** Low
**Where:** `AdminController.php:240-250` stores validated WebP files under random UUID names (good) but nothing lists, reuses or deletes them; every re-upload during editing orphan-files up to 500 KB. **Fix:** add a media list/delete or purge files not referenced by `site_settings`.

### F-58 Date handling is UTC-only and date-only values are parsed as UTC
**Severity:** Low
**Where:** `app/config/app.php:68`; daily match limits reset at 05:30 IST (`ApiController.php:217`, copy says "00:00 UTC" in `profile-onboarding.js`); `workspace-activity.js:4` parses `YYYY-MM-DD` with `new Date()`, which shifts by a day in western time zones; follow-up reminders use the UTC date (`MailAutomation.php:91`). **Fix:** store a user timezone or label the cut-off clearly.

### F-59 Writes on GET and per-request writes
**Severity:** Low
**Where:** `ApiController.php:295-297` (`GET referrals` creates a referral code), `:106` (an `insertOrIgnore` into `profiles` on every authenticated API call), database sessions are written on every request including anonymous page views (`ContentController` sets a CSRF meta and cookie on every page) so crawlers create `sessions` rows. **Fix:** create the code at registration (already done for new users), drop the per-request insert, and skip sessions for public GETs.

### F-60 `Settings::put` is not atomic and rewrites `created_at`
**Severity:** Low
**Where:** `app/app/Services/Settings.php:36-39`: `revision` is read then written (two writers can produce the same number); the `updateOrInsert` update array includes `created_at`. Content endpoints are protected by the admin row lock and revision check (`AdminController.php:47-50`), but `plans`, `settings`, `providers` and `business-controls` are last-write-wins. **Fix:** `increment('revision')` in SQL and move `created_at` to insert only.

### F-61 `cvtara:admin` ignores the password for an existing account
**Severity:** Low
**Where:** `app/routes/console.php:23-32`: `firstOrCreate` does not update the password of an existing user but still promotes them to owner and prints "Owner account is ready"; email is not lower-cased although login lower-cases it (works on MySQL collation, fails on SQLite). **Fix:** update the password explicitly and lower-case the email.

### F-62 Affiliate admin listing issues thousands of queries
**Severity:** Low
**Where:** `AffiliateAdminController.php:24` maps up to 500 affiliates through `Referrals::balance` (4 queries) and `rate` (2 queries plus a join), and returns all creators and payout profiles without limits. **Fix:** compute balances with one grouped query.

### F-63 The owner console renders fake data if its APIs fail
**Severity:** Low
**Where:** `app/resources/frontend/admin.html` ships about 1,100 lines of static demo markup (for example "Priya K. · Free account", `:836`) that JS replaces; on a failed request some panels keep the mock. **Fix:** ship empty containers with skeletons.

### F-64 Accessibility gaps
**Severity:** Low
**Where:** only `index.html` has a skip link; `contact.html` has no `<h1>` in markup (JS replaces `main`), `admin-guide.html` has no `<main>`; admin actions use `prompt()` and `confirm()` (`admin-extras.js:47,98`, `primary-resume.js:33`); success/failure toasts rely on a transient `role=status` element; icon-only buttons exist in `cover-letters.html` and `workspace.html` (heuristic scan, needs runtime check with a screen reader). **Fix:** replace native dialogs with labelled dialogs and add landmarks.

### F-65 `.env.example` defaults are unsafe if copied for production
**Severity:** Low
**Where:** `app/.env.example` sets `APP_ENV=local`, `APP_DEBUG=true`, `SESSION_ENCRYPT=false`. The installer writes correct production values (`install.php:29`), so this only matters for manual installs. **Fix:** comment the file with production values.

---

# What is solid (verified)

* **Payments**: signature checks with `hash_equals` (`Payments.php:96-99,105`), amount/currency/status verification, row lock plus idempotent short-circuit in `capture()`, replay protection through the `webhook_events` primary key and separate `refund:<id>` keys, refunds are cumulative and clamped, disputes reverse and restore commissions, out-of-order refund-before-capture is retried rather than lost.
* **Payouts**: provider transfer id is reserved before the HTTP call, unknown outcomes stay reserved, sync verifies id and amount before paying, manual path requires review, reference and non-negative balance (`CashfreePayouts.php:50-70`, `Referrals.php:107-125`).
* **Secrets**: provider secrets encrypted with `Crypt`, masked on read (`AdminController.php:78-86,116`); payout details encrypted and audited on view (`AffiliateAdminController.php:49-61,62-70`).
* **Authorisation**: every customer resource is scoped by `owner_id` (documents, applications, feedback, exports, purchases, verify); admin routes enforce `is_admin` at the dispatcher and again in affiliate and setup controllers; blocked users are rejected on every API call and page gate; `users.is_admin`, `blocked`, `referral_code` are never mass-assignable (`User.php:9-10`).
* **Injection**: query builder with bindings throughout, LIKE terms escaped, raw SQL fragments are static, JSON-LD uses `JSON_HEX_*`, markdown rendered with `html_input: strip` and `allow_unsafe_links: false`, every client template that inserts data uses an `E()/esc()` helper (a scan of all JS template expressions found no unescaped server-controlled text outside admin-only labels), CSV exports neutralise formula characters (`AdminOpsController.php:46`, `admin-ops.js:239`).
* **Concurrency control**: documents and applications use revision checks (409), usage counters (matches, exports, AI, tracker, drafts) run under a user-row lock, referral claims and withdrawals lock the user.
* **Uploads**: `/api/admin/media` accepts only decodable WebP up to 500 KB and 4000 px, stores under random UUIDs on the private disk, serves through a route with a fixed content type and `nosniff` (`AdminController.php:240-250`, `routes/web.php:19`); user photos are validated as JPEG/PNG/WebP data URIs up to 1024 px (`ApiController.php:453-458`); dompdf runs with remote access and PHP disabled (`ExportController.php:11`).
* **Installer**: one-time key, CSRF, lock file, HTTPS-only URL, empty-database check, refuses a second owner, `.env` mode 0600 with escaped values, ZIP traversal check, key and payload deleted after success (`install.php:6-30`); `.htaccess` blocks dotfiles and zip files.
* **Account security**: 12+ character passwords, login throttles per route and per email+IP, session regeneration, DB sessions purged on reset and block, email-code plus password step-up with attempt limits (`AccountSecurity.php`).
* **Data hygiene**: error log fingerprinting without query strings, `client-error` rate limited, health history retention, anonymising deletion keeps FK integrity.
