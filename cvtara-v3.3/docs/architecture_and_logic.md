# CVTara platform: architecture and logic

Scope: package `cvtara-v3.3` (BUILD_INFO.json says version 3.4.0, packaged 2026-10-14, status "Private Hostinger acceptance package; not approved for public paid launch"). Everything below was read in the source; statements carry `path:line` citations. All paths are relative to `/home/user/domain_flipping/cvtara-v3.3/`. Where behaviour depends on a live server or provider and could not be proven statically it is marked **needs runtime check**.

---

## 1. System overview

| Item | Fact | Source |
|---|---|---|
| Stack | PHP ^8.3, Laravel ^13.17, dompdf ^3.1, phpoffice/phpword ^1.4 | `app/composer.json:9-13` |
| Data access | Query builder everywhere; the only Eloquent model is `User` (mass-assignable: name, email, password; hidden: password, remember_token) | `app/app/Models/User.php:9-18` |
| Frontend | Static HTML templates in `app/resources/frontend/*.html`, assembled per request by `ContentController`, plus ~45 vanilla JS files and CSS in `public_html/` | `app/app/Http/Controllers/ContentController.php:52` |
| Hosting layout | `public_html/index.php` boots the app from a sibling `cvtara_app` folder (or the parent dir); redirects to `/install.php` when `.env` or `storage/installed.lock` is missing | `public_html/index.php:5-6` |
| Timezone | `UTC`; "daily" limits and reminders roll over at 00:00 UTC (05:30 IST) | `app/config/app.php:68` |
| Sessions / cache / queue | `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database` written by the installer. No queue worker exists; mail is its own table processed by cron | `public_html/install.php:29`, `app/app/Services/MailAutomation.php:33` |
| Language | English only. The profile endpoint accepts `language` only as `en` and documents only `documentLanguage=en` | `app/app/Http/Controllers/ApiController.php:163,440` |

### 1.1 Request pipeline

1. Apache/LiteSpeed rewrites every non-file, non-directory request to `public_html/index.php` (`public_html/.htaccess:6-8`). Dotfiles and `*.zip|env|sqlite|log` are denied (`.htaccess:20-26`).
2. `bootstrap/app.php` registers: CSRF exemption for `webhooks/razorpay` and `webhooks/cashfree` (`app/bootstrap/app.php:15`); `SecurityHeaders` appended to every response (`:16`); `OwnerMaintenance` appended to the `web` group (`:18`); guests redirected to `/login` (`:17`). No trusted-proxy or trusted-host configuration exists.
3. `SecurityHeaders` sets nosniff, X-Frame-Options SAMEORIGIN, Referrer-Policy, Permissions-Policy, a CSP containing only `base-uri 'self'; object-src 'none'; frame-ancestors 'self'`, HSTS (max-age 1 year, secure requests only) and `Cache-Control: private, no-store` on `api/*` (`app/app/Http/Middleware/SecurityHeaders.php:12-21`).
4. `OwnerMaintenance` lets `admin*`, `api/admin/*`, `login`, `logout`, `webhooks/*`, `up` through. For everyone else, if maintenance is enabled and the user is not an unblocked admin, it returns a 503 (JSON for `api/*`, a built HTML page otherwise) with `Retry-After: 3600` (`app/app/Http/Middleware/OwnerMaintenance.php:11-26`). `/maintenance-preview` forces the page for preview (`:16`).
5. Exceptions: non-4xx, non-validation, non-auth exceptions are written to the `error_logs` table (`bootstrap/app.php:21-26`); `api/*` validation errors and HTTP errors are rendered as `{ok:false,error,...}` JSON with fixed English messages (`:27-38`). 422 with an empty message becomes "Check the request and retry." (`:35`).

---

## 2. Route inventory

Global notes: all routes sit in the `web` group (session + CSRF) except the Razorpay webhook, which is CSRF-exempt and authenticated by HMAC signature. `GET /{path?}` is the last GET route and renders pages. Razorpay is the only payment gateway (3.19.0 removed Cashfree checkout and payouts).

### 2.1 `routes/web.php`

| Method + path | Auth | Throttle | Purpose | Line |
|---|---|---|---|---|
| GET `/login`, `/register`, `/forgot-password`, `/reset-password/{token}` | none | none | `AuthController::page` renders `resources/views/auth.blade.php`; sets the 1-year httpOnly `cvtara-device` cookie | `web.php:6-8`, `AuthController.php:9` |
| POST `/login` | none | `20/min` per IP + 5 failures per email+IP per 15 min | Sign in, `blocked=false` required; `next` honoured if it starts with `/`, not `//`, no `\`; `/admin*` only for admins | `web.php:9`, `AuthController.php:11` |
| POST `/register` | none | `5/min` | Create account, attach referral, create profile, log in, queue welcome mail | `web.php:10`, `AuthController.php:10` |
| POST `/forgot-password` | none | `3/min` | Create reset token and queue an "essential" mail with the link | `web.php:11`, `AuthController.php:13` |
| POST `/reset-password` | none | `5/min` | Reset password via Laravel broker, delete the user's DB sessions | `web.php:12`, `AuthController.php:14` |
| POST `/logout` | session | none | Logout + invalidate session | `web.php:13` |
| GET `/signin-with-chatgpt` | none | none | Redirect to `/login` (legacy) | `web.php:14` |
| GET `/r/{code}` | none | `60/min` | Referral landing: 404 unless code belongs to an unblocked user; stores `session.referral` and a 30-day `cvtara_ref` cookie, records one `referral_clicks` row per session per day, redirects to `/register` | `web.php:15` |
| POST `/webhooks/razorpay` | HMAC `X-Razorpay-Signature` | `120/min` | `Payments::webhook` (body limit 1 MB) | `web.php:16` |
| GET/POST/DELETE `/api/{path?}` | per path, see 2.2 | `API_RATE_LIMIT` (default 400)/min per user or IP | The JSON API | `web.php:17` |
| POST `/export/{type}/{id}/{format}` | `auth` | `10/min` | PDF / DOCX / print-HTML export | `web.php:18` |
| GET `/media/{file}` | public | none | Serves `media/<uuid>.webp` from the private `local` disk, `Cache-Control: public,max-age=86400` | `web.php:19` |
| POST `/client-error` | public (CSRF) | `30/min` | Browser JS errors into `error_logs` | `web.php:20` |
| GET `/preview` | only if `CVTARA_PREVIEW` | none | Three-view demo shell (`preview.blade.php`) | `web.php:21` |
| GET `/preview/go/{who}` | only if `CVTARA_PREVIEW`; no password | none | Logs in as `demo@cvtara.test` or `owner@cvtara.test`, redirects to workspace/admin | `web.php:22` |
| GET `/{path?}` | page rules | none | `ContentController::show` | `web.php:23` |
| GET `/up` | public | none | Laravel health route | `bootstrap/app.php:13` |

`web.php:7` contains a dead loop (`'reset-password'` is never in the array).

### 2.2 `ApiController::run` (`/api/*`)

Guards at the top: GET is rejected (405) for `checkout/*`, `features/use`, `traffic`, `security/send-code|verify-code`, `referrals/withdraw|creator|payout-profile`, `account/delete`, `feedback/*/withdraw` (`ApiController.php:28-29`); DELETE only for `resumes|letters|applications/<uuid>` (`:30-31`). Authenticated paths require a session (401) and an unblocked user (403) (`:104-105`); a `profiles` row is `insertOrIgnore`d on every authenticated call (`:106`).

| Path | Method | Auth | Purpose | Line |
|---|---|---|---|---|
| `health` | any | public | `{ok, storage}` | 34 |
| `business-public` | GET | public | brand text, referral rules, all plans | 37 |
| `public-config` | any | public | public hero/accent/push settings | 42 |
| `public-activity` | any | public | popup settings + live messages, cached 60 s | 45 |
| `public-content` | any | public | legal, features, library overrides, blog (body only for `?slug=`), integrations flags, plans, published reviews | 74 |
| `traffic` | POST | public | page-view record (needs `consent` true and analytics integration on; path regex; 1/min per visitor+path) | 91 |
| `admin/*` | GET/POST | admin | delegates to `AdminController` | 107 |
| `referral-rewards` | GET | user | milestone status | 112 |
| `referral-rewards/claim` | POST | user | Claim the 5-friend reward (one free Job Hunt month) | 115-129 |
| `security/status` | any | user | `{verified, recent}` | 130 |
| `security/send-code` / `verify-code` | POST | user | step-up code flow | 133-142 |
| `referrals/creator` | POST | user | creator application (1-5 https links) | 143 |
| `referrals/payout-profile` | POST | user + step-up | save payout details | 149 |
| `profile` | GET / POST | user | read / write profile (marks `onboarding_done`) | 156-170 |
| `ai/suggest` | POST | user | OpenAI writing help (10/day) | 171 |
| `allowance` | any | user | plan, limits, usage | 176 |
| `resumes/{id}/primary` / `variant` | POST | user | make primary / create JD variant | 187 |
| `account/export` | GET | user | JSON export of own data | 192 |
| `account/delete` | POST | user + password + typed `DELETE` | anonymise account | 195 |
| `resumes`, `resumes/{id}`, `letters`, `letters/{id}` | GET/POST/DELETE | user | draft CRUD with revision locking | 206-208, 408 |
| `features/use` | POST | user | count one ATS review | 209 |
| `applications`, `applications/{id}` | GET/POST/DELETE | user | tracker CRUD | 230-259 |
| `purchases` | any | user | own purchases (200 latest) | 260 |
| `checkout/quote` / `start` | POST | user | price quote / create order | 263-275 |
| `checkout/verify` | POST | user | demo (preview only) or Razorpay signature verify | 276-293 |
| `referrals` | GET | user | dashboard payload; **creates `referral_code` on first read** | 294-310 |
| `referrals/withdraw` | POST | user + step-up | request withdrawal | 311 |
| `feedback` | GET / POST, `feedback/{id}/withdraw` POST | user | own reviews | 317-331 |
| anything else | any | user | `{ok:false,error}` 404 | 333 |

### 2.3 `AdminController::run` (`/api/admin/*`, admin only; method must be GET or POST, `:24`)

Step-up column: **yes** means `AccountSecurity::requireRecent` (fresh email code + password within 10 minutes) is called.

| Path | Method | Step-up | Purpose | Line |
|---|---|---|---|---|
| `content/{legal,features,library,blog,integrations,emails,seo,popup,site}` | GET / POST | no | read / save a settings blob with optimistic `revision`; blog publish queues `blog_published` mail to consenting users | 37-66 |
| `settings` | GET / POST | no | homepage hero, accent, push | 67-74 |
| `providers` | GET / POST | only `verification` and `payouts` services | encrypted provider credentials (secrets masked on GET) | 75-120 |
| `business-controls` | GET / POST | no | maintenance, referral rules, coupons | 121-127 |
| `plans` | GET / POST | no | plan/add-on editor | 128-144 |
| `purchases` | GET | n/a | latest 500 orders with user | 145 |
| `overview`, `dashboard`, `users` | GET | n/a | stats; `users` paginated 100/page with search | 148-185 |
| `users/block` | POST | yes | block / unblock with note; deletes DB sessions | 186 |
| `letter-summary`, `reports`, `reviews` (GET), `email/jobs` | GET | n/a | counts, analytics, moderation list, mail queue (200) | 203-234 |
| `reviews` (POST), `feedback/add` | POST | no | moderate; add dummy/verified testimonials | 213-231 |
| `email/process` | POST | no | runs `reminders()` + `process()` now | 235 |
| `media` | POST | no | base64 WebP upload (<= 500 KB, <= 4000x4000) | 240 |
| `seo`, `page-audit` | GET | n/a | page list and SEO audit | 251 |
| `search/import`, `search/sync` | POST | no | Search Console rows (CSV import or API) | 254-265 |
| anything starting `referrals` | GET / POST | see below | `AffiliateAdminController` | 34 |
| anything starting `setup/` | GET / POST | see below | `SetupController` | 31 |
| `users/(grant\|update\|revoke\|delete)`, `orders/{uuid}`, `errors`, `errors/clear`, `errors/download`, `site-urls`, `legal/generate`, `health/(history\|run)` | per `AdminOpsController::ROUTES` | below | `AdminOpsController` | 28 |

**AdminOpsController** (`app/app/Http/Controllers/AdminOpsController.php`): GET `orders/{id}` (order detail + timeline), GET `errors` (filters, 300 rows + failed mails), GET `errors/download` (CSV/JSON, 5000 rows, formula characters stripped), POST `errors/clear` (resolve or delete, max 500 ids or all), GET `site-urls`, GET `legal/generate`, GET `health/history`, POST `health/run` (`:27-90`). Everything after line 92 is POST **and** step-up (`:92-93`): `users/grant` (manual purchase), `users/update`, `users/revoke`, `users/delete`.

**AffiliateAdminController** (`AffiliateAdminController.php`): GET `referrals` (rules, 500 withdrawals, creators, payout profiles, 500 affiliates with balances; no step-up, `:23-25`). Every other call requires step-up (`:29`): POST `referrals/settings` (enabled, requirePan, holdDays), POST `referrals/creators/{id}` (approve/reject + rate), GET/POST `referrals/profiles/{id}` (decrypts details and audits; POST approves/rejects), GET `referrals/withdrawals/{uuid}` (decrypts destination, audited), POST `.../review`, `.../send` (Cashfree payout), `.../sync`, and bare POST for manual `processing|paid|rejected` (`:37-99`). Owners cannot act on their own records (`:41,67`, `AffiliateProfiles.php:55-65`).

**SetupController** (`SetupController.php`): GET `status` (checklist, webhook URLs, cron command, held reward identities), POST `business`, `cache` (clears view/config/route caches), `email-test` (sends a security code mail and processes the queue; no step-up) (`:10-14`); step-up required for `payout-policy` and `reward-review` (`:15-17`).

### 2.4 `ExportController`

`POST /export/{resume|letter}/{uuid}/{pdf|docx|print}` with body `clean` (`ExportController.php:9`). Preview (`clean` false) always carries a watermark. `clean=true` requires an active plan or an unused referral reward export, else 402 and a `download_locked` mail (`:9`). Rendering happens before the credit is consumed (`:11-12`).

### 2.5 `ContentController::show` (all GET pages)

See section 3.

---

## 3. How pages are assembled (`ContentController::show`)

Order of operations (`app/app/Http/Controllers/ContentController.php`):

1. `sitemap.xml` (only if `seo.sitemapEnabled`, entries from `pages()`, `:54-64`) and `robots.txt` (disallows admin/workspace/api/login/register/checkout/install/preview; whole site `Disallow: /` while `searchIndexing` is off, `:66-68`).
2. Aliases (`/resume-builder` to `workspace.html?view=builder`, etc., `:69-72`); `article`/`article.html?slug=` 301-redirects to `/guides|calculators/<slug>/` using the catalog type (`:73-80`).
3. Gate: any path starting with `admin`, or `workspace.html`, redirects guests to `/login`, 403 for blocked users, and for `admin*` also 403 for non-admins (`:81-89`). `setup.html` redirects to `/admin.html`; `index.html` 301s to `/` (`:90-94`).
4. Resolve the file: `guides|calculators/...` gets a trailing slash, `index.html` appended; if a blog post exists in `Settings::get('blog')` it must be `published` (404 otherwise) and `features.blog.enabled` must be true (`:95-106`). `realpath` must stay inside `resources/frontend/` and end in `.html`; unknown slugs with a post fall back to `article.html` (`:107-110`).
5. Article injection: title replaced via `e()`, body rendered with `Str::markdown(... html_input: strip, allow_unsafe_links: false)`, FAQ lines `question|answer` rendered through `e()` (`:112-124`). Legal pages (`about|privacy|terms|refund`) replace the `<main class="legal-shell">` or first `<article>` with the saved markdown (`:126-133`).
6. SEO: custom title/description from `seo.pages[/uri]`, canonical, `robots` meta (index only when `searchIndexing` and not admin/workspace/checkout), `csrf-token` meta, `cvtara-user` meta (user id or `guest`), four stylesheets, `error-report.js`, keywords, Open Graph/Twitter tags, JSON-LD (WebSite on `/`, BlogPosting on posts) with `JSON_HEX_*` flags (`:134-169`).
7. Scripts: for non-admins it **removes** the `owner-console.js` / `owner-advanced.js` script tags (`:171`) and, outside the workspace, `workspace-activity|profile-onboarding|product-roadmap` (`:172-173`); it **appends** `experience.js` always, `launch-ui.js` on every page but `/`, `referral-center.js` on workspace/admin, `reward-paywall.js` on workspace, `fomo-popup.js` on public pages except `/`, workspace, checkout (`:175-187`), optionally `setup-assistant.js` for admins if the file exists (`:191`, it does not exist in the package), removes the `owner-console.css` link for non-admins (`:194-196`), forces `defer` on external scripts (`:197`) and appends `?v=<12-char sha256>` to local JS/CSS references (`:198-205`).
8. Response header: `private, no-store` for admin/workspace, `no-cache` elsewhere (`:206`).

Other server-rendered pieces: `auth.blade.php` (login/register/forgot/reset, a single template switched by `$mode`), `document-print.blade.php` (print preview, renders client-side from embedded JSON), `preview.blade.php`, `OwnerMaintenance` HTML.

---

## 4. Database (migrations in `app/database/migrations/`)

`users` (id, name, email unique, email_verified_at, password, remember_token) plus Laravel `password_reset_tokens` (email PK) and `sessions` (id PK, user_id index, last_activity index) (`0001_01_01_000000`); `cache`, `cache_locks`, `jobs` (unused) tables.

`2026_10_03_000001_roledost.php` adds to `users`: `is_admin`, `blocked`, `referral_code` (unique, 24), `referred_by` (FK users), `referral_attached_at`; and creates:

| Table | Key columns | Keys / indexes | Notes |
|---|---|---|---|
| `profiles` | owner_id (PK, FK cascade), name, phone, profession, category, city, experience_level, language, experience_years, onboarding_done, marketing_consent | PK owner_id | one row per user |
| `site_settings` | key (PK), value_json (longText), revision | PK key | every owner-editable blob |
| `resume_drafts`, `cover_letter_drafts` | id uuid PK, owner_id FK cascade, title, template, company, role, content_json, revision | index (owner_id, updated_at) | `resume_drafts` later gains `is_primary` (index), `parent_id` (uuid, no FK, no index), `job_description` (`2026_10_14_000001`) |
| `applications` | id uuid, owner_id FK cascade, company, role, stage, job_url(1000), applied_at, follow_up, resume_id (uuid, no FK), notes, revision | index (owner_id, updated_at) | `resume_id` dangles after a resume is deleted |
| `activity_events` | id uuid, owner_id (nullable, indexed), event, reference | owner_id only | audit + analytics; no retention |
| `purchases` | id uuid, owner_id FK, plan(30), amount_paise, refunded_paise, currency, status(30, default `created`), provider(30, default `razorpay`), provider_order_id (unique), payment_reference (unique), is_live, limits_json (plan snapshot), access_days, purchased_at, access_until; later `coupon_code`(30), `discount_paise` (`2026_10_05`) | unique order id and payment ref | statuses used: created, paid, partially_refunded, refunded, disputed. Providers used: razorpay, cashfree, demo, manual, referral_reward |
| `webhook_events` | id string PK, type | PK id | idempotency ledger (`refund:<id>` rows too) |
| `product_usage` | id uuid, owner_id FK, action (match / export / ai), reference | index (owner_id, action, created_at) | usage counters |
| `feedback` | id uuid, owner_id FK, display_name, rating, content, consent, consented_at, status (default pending), source (user / owner_verified / dummy), consent_reference | none | reviews |
| `page_views` | id, visitor_id, path, source | index (created_at, path) | no retention |
| `mail_outbox` | id uuid, dedupe (unique), owner_id (no index), trigger, recipient, subject, body, status, attempts, error, due_at, sent_at | unique dedupe; index (status, due_at) | queue + log |
| `referral_clicks` | referrer_id FK, visitor_hash, day | unique (referrer, visitor_hash, day) | |
| `referral_commissions` | referrer_id, buyer_id, purchase_id (unique), amount_paise, reversed_paise, rate_bps; later `available_at` (index) | unique purchase_id | one commission per purchase |
| `withdrawals` | id uuid, owner_id FK, amount_paise, destination_encrypted, method, destination_masked, status, reference (unique, nullable), note, due_at, paid_at; later provider (default `manual`), provider_transfer_id (unique), provider_status, provider_checked_at, review_checklist_json, approved_by, approved_at | | |

`2026_10_04_050637`: `creator_applications` (owner_id unique FK, social_links_json, audience, status, rate_bps, review fields) and `payout_profiles` (owner_id PK, details_encrypted, destination_hash/pan_hash indexed, status, risk_flags_json, eligible_at, beneficiary_id). `2026_10_04_172918`: `referral_identities` (owner_id PK, phone_hash unique, device_hash/ip_hash indexed, status verified|held|rejected) and `referral_rewards` (unique (owner_id, milestone), exports_remaining). `2026_10_15_000001`: `error_logs` (fingerprint unique, level, source, message, occurrences, first/last_seen indexed, resolved_at). `2026_10_04_050150` rewrites saved site content from the old "RoleDost" brand (data fix only).

Relationships are by `owner_id` / `*_id` columns; there are no Eloquent relations. Deleting a user is an anonymisation (section 5.9) because `purchases`, `withdrawals`, `referral_commissions` keep FK references.

---

## 5. Services

### 5.1 `Settings` (`app/app/Services/Settings.php`)

* `get($key)` reads `site_settings.value_json` or falls back to `resources/data/defaults.json`; every call is one DB query (no memoisation) and reads/decodes the defaults file when the row is missing (`:16-29`). For `blog` it additionally merges `resources/data/editorial-content.json` (314 KB, 68 posts): any post whose stored `body` is empty is replaced by the editorial one (`:20-26`).
* `put($key,$data)` upserts JSON and sets `revision = previous + 1` (`:36-39`).
* `providers()` decrypts the `providers` row with `Crypt` (`:41-46`).
* **Plans**: defaults in code (`planDefaults()`): `free` ("Free", price 0, 1 saved resume, 2 letters, 3 matches/day, 0 exports), four packages, two services (`serviceDefaults()`) and three add-on packs (`addonDefaults()`). `plans()` deep-merges saved overrides over the defaults, restricted to known keys. Old keys (`fresher-*`, `pro-basic/standard/pro`) resolve through `planAlias()` so coupons and links keep working; old purchases keep their `limits_json` snapshot.

| Key | Name | Price | MRP | Days | Resumes / letters | ATS scans | Rank | Flags |
|---|---|---|---|---|---|---|---|---|
| quick | Quick Download | 99 | | 7 | 1 resume (any revision), no letters | daily free checks | 1 | `oneResume`; price counts as upgrade credit within 7 days |
| job-hunt | Job Hunt | 299 | | 30 | 5 / 5 | 20 | 2 | popular |
| pro | Pro | 599 | | 90 | 20 / 20 | 60 | 3 | bestValue |
| complete-kit | Complete Job Kit | 1,299 | 1,797 | 90 | 20 / 20 | 60 | 3 | includes expert-review + profile-boost; off sale by default |
| expert-review / profile-boost | Services | 499 / 699 (399 / 599 with Job Hunt or Pro) | | | | | | type `service`, off sale by default, tracked in `service_orders` |
| addon-10 / 20 / 30 | Add-on packs | 149 / 249 / 399 | 0 | 0 | +10 / +20 / +30 each | n/a | 0 | type `addon`, off sale by default |

**Checkout order (`BusinessControls::quote`)**: list price, service discount with a plan, coupon, upgrade credit from an unused Quick Download, the friend's ₹50 first-purchase discount (never with the upgrade credit), then wallet credit; at least ₹1 is always paid.

**Refunds (`Refunds.php`)**: one refund per customer, within 7 days, only while the purchase is unused (no clean download, ATS scan, tailored draft, started service or upgrade). Approve calls the gateway refund API (demo/manual are marked directly), then `settled()` returns spent wallet credit and cancels services not started.

**Wallet referral (`Wallet.php`, mode `wallet`)**: only orders of `minQualifyingPaise` (₹299) or more count; the friend gets ₹50 off their first such order; the referrer gets ₹50 wallet credit once the friend's purchase is 7 days old and not refunded; credit expires after 90 days; ₹1,000 a month cap; 5 qualified friends unlock one free Job Hunt month. Same email inbox, browser, phone or payment reference counts as self-referral.

`exports` = 4 x the resume count (add-ons: 4 x n). `dailyMatches` 100 for paid plans.

* **Plan resolution (`access()`)**: load the user's purchases with status `paid` or `partially_refunded` and `access_until > now` (`:128-133`). `pick()` ignores `addon-*` rows and chooses the row with the highest `limits_json.rank`, ties broken by latest `access_until` (`:90-105`). `shape()` merges the winning snapshot over the free limits, then adds `savedResumes`, `savedLetters` and `exports` from every active add-on (`:107-125`). So: the strongest-rank active plan wins; a full refund (`refunded`) or `disputed` drops the row out of the query; a partial refund keeps access; limits come from the purchase-time snapshot (`purchases.limits_json`), not the current plan editor; add-ons only count while their own `access_until` is in the future.
* `audit($user,$event,$ref)` inserts into `activity_events` (`:147-150`).

### 5.2 `Payments` (`app/app/Services/Payments.php`)

* `start()` (`:13-33`): requires a paid plan key; in preview mode with no Razorpay keys it creates a `demo` purchase (`:19-20,36-46`); otherwise requires `integrations.payments`, provider `razorpay` and both keys. It computes the price via `BusinessControls::quote`, requires `0 < price < 1e8` paise (`:27`), creates a Razorpay order (receipt = purchase uuid), and inserts a `created` purchase with the plan snapshot, coupon and `is_live = key starts with rzp_live_`.
* `guardAddon()` forbids add-on checkout without an active plan (`:56-60`).
* `capture($entity,$provider)` (`:63-89`), in one transaction: lock the purchase by `(provider, provider_order_id)` (missing means a retryable validation error, `:66-69`); require currency, amount and `status=captured` to match (`:69-70`); if already `paid|refunded|partially_refunded|disputed` return (idempotent, `:71-72`). **Same-plan renewals stack**: the new `access_until` is `max(now, latest access_until of the user's other active purchases of the same plan) + access_days` (`:73-79`). **Add-ons** get `access_until` equal to the strongest active plan's `access_until`, or now + 30 days when none (`:75-77`). Then `Referrals::credit`, audit `purchase_verified`, queue the receipt mail.
* `verify()` (client path): finds the user's order, checks the HMAC `order_id|payment_id` with the Razorpay secret (`hash_equals`), fetches the payment from Razorpay, checks order and payment ids, then `capture` (`:90-100`).
* `webhook()` (`:102-144`): HMAC of the raw body with the webhook secret (`hash_equals`, 403 on failure); event key = `X-Razorpay-Event-Id` or sha256 of the body; inside a transaction it skips seen keys (`webhook_events` PK) and handles `payment.captured` / `order.paid` (capture), `refund.processed` (cumulative `refunded_paise = min(amount, previous + refund)`, status `refunded` when equal else `partially_refunded`, `Referrals::reverse`), and `payment.dispute.*` (non-won events set `disputed` and reverse the whole commission; `won` restores the status and re-computes the reversal). Both the event key and a `refund:<id>` key are written at the end (`:142-143`). An unknown order or a refund for an unknown payment raises a validation exception so the provider retries.
* `demoVerify()` captures a `demo` purchase and is only reachable when `app.preview` is true (`:48-54`, `ApiController.php:276`).

### 5.3 `BusinessControls` (coupon quote, maintenance)

> **Changed in v3.4.1:** referral rules are no longer read or written here. They are saved only through `POST /api/admin/referrals/settings` (Owner console → Referral Ops, `public_html/admin-referral.js`).

`get()` merges defaults (maintenance off, brand text, referral config, no coupons) with `site_settings.business_controls` (`BusinessControls.php:10-16`); `save()` validates and writes both `business_controls` and `referrals` (`:18-34`).

`quote($plan,$code,$userId)` (`:36-60`): subtotal = price x 100; with a code (upper-cased) it must match an enabled coupon covering the plan and not past `expiresAt` (UTC date compare, `:44`); `maxUses` counts purchases with that code that are `paid|partially_refunded` or `created` within the last hour (`:47-50`); `perUser` counts the user's `paid|partially_refunded` purchases (`:51-54`); discount is `min(subtotal - 100, percent or fixed)` so the minimum payable is Rs 1 (`:55`). Percent coupons are capped at 90 by `save()` (`:21-26`). The quote returns `totalPaise`; coupon use is *recorded* only by the purchase row (`coupon_code`, `discount_paise`).

### 5.4 Referrals

`Referrals::config()` defaults (`Referrals.php:13-15`): enabled, rate 20% (2000 bps), tier 30% after 100 distinct successful buyers, minimum withdrawal Rs 1,000, `payoutHours` 24, hold 7 days, `requirePan` false, reward thresholds 5 and 10 friends, premium reward 30 days, `minQualifyingPaise` 9900, `proOnly` true.

* `credit($purchase)` (`:45-58`), called from `capture`: requires `is_live`, status `paid` and the programme on; buyer must have `referred_by` (not self) and the referrer must exist, be unblocked, and (if `proOnly`) hold a plan whose snapshot has `referral=true`. It inserts one `referral_commissions` row (unique per purchase) with `amount = floor(amount_paise x rate / 10000)`, `rate_bps` frozen, `available_at = now + holdDays`. It does **not** check provider or a minimum amount.
* `rate($userId)` = max(approved creator rate or base rate, tier rate when `successfulBuyers >= tierThreshold`) (`:25-31`); `successfulBuyers` counts distinct buyers with live, fully paid, unrefunded purchases whose hold has passed (`:18-23`).
* `balance($id)` (`:33-42`): earned = sum(amount - reversed); pending = same for rows with `available_at` in the future; paid and reserved from `withdrawals`; **available = earned - pending - paid - reserved**.
* `reverse($purchase,$refunded)` sets `reversed = min(amount, floor(refunded x rate / 10000))` (monotonic) (`:61-69`); `restore` recomputes after a dispute is won (`:71-77`). A reversal after payout makes `available` negative; later commissions offset it.
* `request($user,$data)` (`:79-105`): transaction under the user lock; requires programme on, verified email, an approved payout profile past its 48-hour `eligible_at`, no open withdrawal, `amount >= config.minimumPaise` and `<= available`; stores the decrypted destination re-encrypted on the withdrawal (`:93-100`), status `requested`, `due_at = now + 24h`.
* `transition()` (`:107-125`): allowed `requested -> processing|rejected`, `processing -> paid|rejected`; `processing` needs the review (`approved_at`); `paid` needs a reference of 6+ chars and non-negative balance; `rejected` needs a note; cannot act on own withdrawal; blocked beneficiaries only to `rejected`.

`ReferralRewards` (`ReferralRewards.php`): `enabled()` follows the referral switch. `count` is the number of friends whose purchase passed the 7-day refund window (see `Wallet`). `claim` gives one free Job Hunt month and one clean resume download when 5 friends qualify, once per customer, after any running plan ends. Phone verification (Twilio) was removed in 3.18.0; `referral_identities` is kept because `Wallet::selfReferral` still reads it.

`AffiliateProfiles` stores payout details encrypted, HMAC hashes of the destination and PAN for duplicate flags, a 48-hour `eligible_at`, and resets status to `pending` on every change; blocks edits while a withdrawal is open (`AffiliateProfiles.php:25-53`). `approve` locks and sets the status with the reviewer's note (`:55-65`).

Withdrawals are paid by the owner outside the site (bank transfer or UPI) and recorded with `transition()`; there is no payout API.

`TaraAI` (`TaraAI.php`): writing help through the Claude API (`anthropic-ai/sdk`, default model `claude-opus-5-5`, server-side fallback on). Off unless `integrations.ai` is on and `providers.ai.claudeApiKey` is set. Seven tasks; 3 suggestions a day free, 20 on a paid plan, plus the owner's site-wide `dailyLimit` (default 300). A usage row is reserved in `product_usage` (action `ai`) before the call and removed if the call fails or is declined. Routes: GET `ai/status`, POST `ai/suggest` (consent required, 6 a minute per user). UI: `public_html/tara-ai.js`.

### 5.5 `MailAutomation` (`MailAutomation.php`)

* **Queue**: `queue($user,$trigger,$ref,$vars,$essential)` loads the template for the trigger from `site_settings.emails` or defaults, returns if missing/disabled (unless `essential`, used for password reset), skips `blog_published` and `abandoned_purchase` when the user has no `marketing_consent` (`:14-27`), replaces `{{var}}` tokens and inserts into `mail_outbox` with `dedupe = sha256(userId:trigger:ref:templateId)` via `insertOrIgnore` (`:28-30`). Dedupe therefore is per user, trigger, reference and template.
* **Process** (`:33-79`): guarded by a 5-minute cache lock; paused when `integrations.email` is false; configures SMTP or Resend from provider settings; marks `sending` rows older than 10 minutes as `failed`; cancels `security_verification` mails older than 10 minutes; picks up to 40 `queued|failed` rows with `attempts < 5` and `due_at <= now`, ordered security codes first, then receipts/reset/welcome, then the rest; sets `sending`, increments `attempts`, sends (Resend with `Idempotency-Key = job id`, or SMTP raw text). Success stores `sent` and replaces the body of security mails with a stub; failure stores a generic error and retries after `15 x attempts` minutes (1 minute for security codes) (`:48-72`).
* **Reminders** (`:81-96`): `access_expiring` for paid non-add-on purchases ending within 7 days; `abandoned_purchase` for `created` purchases between 1 hour and 7 days old; `followup_due` for applications whose `follow_up` is today (ref includes the date); then writes `cronHeartbeat`.
* Default templates (`resources/data/defaults.json`): welcome (profile_created), receipt (purchase_verified), expiry (24 h delay), follow-up, reset, download-locked are **on**; resume-saved, abandoned and blog-published are **off**.

### 5.6 `AccountSecurity` (step-up)

`send()` requires email delivery enabled, allows 3 codes per 15 min, stores a bcrypt hash of a 6-digit code in the session (10-minute expiry, 5 attempts) and inserts a `security_verification` mail (`AccountSecurity.php:14-24`). `verify()` limits to 5 attempts per 10 min, needs the code **and** the current password, then sets `sensitive_until = now + 600` and `sensitive_user`, and marks `email_verified_at` (`:26-45`). `requireRecent()` throws a validation error unless the user is verified and the session flag is fresh and belongs to them; it returns immediately when `app.preview` is true (`:48-53`). Used by: payout profile and withdrawal, provider save for `verification` and `payouts`, user block/grant/update/revoke/delete, all referral admin writes except the listing, payout policy and reward review.

### 5.7 `Documents` and exports

`Documents::html()` builds escaped HTML for dompdf: fonts DejaVu Sans/Serif, accent validated as hex, photo only as validated data URI, sidebar layout when the template name matches `sidebar|suite-(prism|folio|vector|forma)` (`Documents.php:4-14`). `Documents::word()` builds a PhpWord document with output escaping enabled; resume sections are dumped as plain paragraphs without template styling (`:15`). `ExportController` renders PDF with `isRemoteEnabled=false`, `isPhpEnabled=false`, `chroot=storage` (`ExportController.php:11`), refuses PDFs containing Devanagari characters or `documentLanguage` hi/bn, and consumes the allowance only after rendering. Allowance logic for clean exports: reward credit first (resumes only), else `product_usage` rows with `reference LIKE '<purchaseId>:%'` must be below `limits.exports` (`:12`).

### 5.8 `ErrorLog`, `Health`, `SearchConsole`, `WritingAssistant`

* `ErrorLog::record` fingerprints `source|message-with-digits-masked|location`, upserts with an occurrence counter, strips query strings from URLs, and prunes the 500 oldest rows when the table exceeds 5,000 (`ErrorLog.php:11-35`).
* `Health::snapshot()` checks DB, writable storage, HTTPS `APP_URL`, `APP_URL` containing `cvtara.com`, private app folder, heartbeat within 10 minutes, email and payment configuration, `APP_DEBUG` off, and reports counts (`Health.php:12-48`); `run()` keeps 60 daily snapshots in `site_settings.healthHistory` and logs a warning when not healthy (`:50-66`).
* `WritingAssistant` calls OpenAI Responses (`store=false`), 10 requests/user/day, prompt instructs preserving facts (`WritingAssistant.php:8-20`). `SearchConsole::sync` signs a service-account JWT and stores 28 days of query/page rows in `searchData`.

### 5.9 Account export and deletion (`ApiController.php:375-406`)

Export returns profile, resumes and letters (decoded), applications, purchases (selected columns), own reviews and the latest 500 activity rows. Deletion refuses admins and users with an open withdrawal, then in one transaction deletes drafts, applications, feedback, profile, payout profile, creator application, referral identity and rewards, usage, mail_outbox, referral clicks, activity events, sessions and reset tokens, and **renames** the `users` row to `Deleted user` / `deleted-<id>@deleted.invalid` with a random password and `blocked=1`. Purchases, withdrawals and commissions stay.

---

## 6. Data and configuration precedence

1. **Code constants**: plan defaults (`Settings.php:48-68`), referral defaults (`Referrals.php:13-15`), business defaults (`BusinessControls.php:12`), templates/letters/blog catalog (`resources/data/catalog.json`: 91 templates, 26 letters, 68 blog entries).
2. **`resources/data/defaults.json`** (seo, legal, integrations, features, library, blog, emails, popup, site) applies only while the matching `site_settings` row does not exist (`Settings.php:19`). The first owner save of a key creates the row and the file stops mattering for that key.
3. **`site_settings` rows** (JSON, `revision`): `seo, legal, integrations, features, library, blog, emails, popup, site, plans, providers (encrypted), public, business_controls, referrals, operator, payoutPolicyReviewed, cronHeartbeat, healthHistory, searchData, cashfreeWebhookSeen`.
4. **Special merges**: `plans` = defaults deep-merged with saved values (known keys only); `blog` = saved post unless its `body` is empty, in which case the editorial body returns (so an owner cannot blank a body and cannot permanently delete an editorial post, `Settings.php:20-26`); `referrals` is written by both the Business controls form and the Referral Ops form.
5. **`.env`** (installer-written): app key/url, DB, `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, SMTP defaults; `CVTARA_PREVIEW`, `API_RATE_LIMIT`. Provider secrets are **not** in `.env`; they live encrypted in `site_settings.providers` with the app key (`AdminController.php:116`).
6. **Client-side overlay**: the browser fetches `public-content` and `owner-public.js` overrides template names/categories/accent/sample text, letter copy, blog metadata and visibility in `window.CVTARA_DATA` (`owner-public.js:3-7`); `template-curation.js` independently hides 40 of the first 50 templates and 7 of 8 sidebar variants, leaving 44 of 91 visible (`template-curation.js:1`).

**Editable in the owner console**: homepage copy and hero roles, per-page SEO titles/descriptions/keywords, sitemap/indexing, resume/letter library (name, category, accent, sample text, published), blog posts (editor with SEO checklist and WebP upload), legal pages and company details, email templates and enable switches, popup messages and timings, product feature switches and limits (`features`), plans/add-ons (price, MRP, validity, limits, perks, popular, referral), coupons, referral rules, maintenance page, integration credentials (Razorpay, SMTP/Resend, OpenAI, analytics, push, Twilio, Cashfree payouts), operator details, payout policy, reviews. **Not editable**: plan ranks, plan keys, rate defaults not exposed in the form (payout hours, PAN requirement is exposed), the catalog of templates (code), cron timing, daily limits that live in plans.

---

## 7. Customer journeys (step by step)

### 7.1 Visitor to landing page
1. `GET /` -> `ContentController::show('')` serves `index.html` with head/script injection (section 3).
2. Browser loads `data.js` (50 base templates, 12 letters, 68 blog entries, features, sample profiles), `reference-templates.js` (+27 templates and the renderer), `premium-collection.js` (+14 "suite" templates, +14 letters, letter renderer), `template-curation.js` (hides 47), then `preview.js` (header/footer, template spotlight, feature tabs, exit-intent dialog), `profile-api.js` (`CVTaraAPI.request`), `connected-ui.js` (import + ATS widget on the home page), `owner-public.js` (fetches `/api/public-content`, applies overrides, analytics consent banner), `plans-ui.js` (mounts pricing from `/api/business-public`), `home-ui.js` (rotating headline, announce bar, reviews), `business-ui.js` (referral offer text from `business-public`), `fomo-popup.js`.
3. Other public pages: `/templates.html` and `/cover-letters.html` (galleries and preview dialogs from `preview.js`), `/blog.html`, `/guides.html`, guide and calculator pages (`/guides/<cat>/<slug>/`, `/calculators/<slug>/`: server renders the markdown body for crawlers; `preview.js initArticle` rebuilds a calculator or guide shell; `owner-public.js` finally re-renders the body with the client markdown renderer).

### 7.2 Register
`/register` (auth.blade.php) -> POST `/register` (`AuthController.php:10`): lower-cases email; requires name, unique email, password 12-200 chars + confirmation, experience level, profession, category, terms; creates the user, a 16-char `referral_code`, attaches `referred_by` from `session.referral` or the `cvtara_ref` cookie when within 30 days and the referrer is unblocked, inserts a `profiles` row with `onboarding_done=true`, logs in, regenerates the session, queues the welcome mail (`profile_created`, dedupe ref `signup`), audits `registered`, and redirects to a safe `next` or `/workspace.html?view=resumes`. No email verification is required to use the product.

### 7.3 Onboarding and profile
Registration already collects level, profession and category. `profile-onboarding.js` shows a "Tell us about you" dialog only when `profile.onboarding_done` is false (accounts created without the register form, e.g. a profile row created by `ApiController.php:106`). The Account view (`connected-ui.js renderProfile`, then `profile-onboarding.js` replaces `#profileForm` with the full form) posts `/api/profile` (`ApiController.php:162-170`): validates the category list, sets experience years 0 for freshers, saves the marketing-consent flag that gates blog and abandoned-cart mail.

### 7.4 Build the primary resume
1. `/workspace.html?view=builder` (the workspace router is `preview.js initWorkspace`, which toggles `.workspace-view` sections and fires `cvtara-workspace-view`).
2. `resume-studio.js`: stages template -> details -> review; picker over 44 visible templates with search/category/layout filters; six edit tabs (personal, experience, education, skills/summary, more, design/order); live preview via `CVTaraReferenceTemplates.render`; photo is centre-cropped to 320x320 JPEG client-side; undo/redo (20 snapshots); the draft is mirrored to `localStorage` key `cvtara-resume-studio-v1-<userId|guest>`.
3. "Save to profile" posts `{id, revision, data}` to `/api/resumes` (`resume-studio.js:123`). `ApiController::documents` (`:408-491`) validates structure and limits (`:439-461`), rejects drafts larger than 450 KB, validates the photo data URI and 1024-pixel limit, then in a transaction under the user row lock checks the feature flag, template published state and catalog membership, and either updates with `revision` check (409 on mismatch) or inserts after a limit check. The **first resume becomes primary** (`:481`). A `resume_saved` audit event and (if enabled) mail are produced.
4. `primary-resume.js` renders "My resumes": primary card, variant list, edit/download/make-primary/delete.

### 7.5 Tailored variant from a job description
"Create tailored version" asks for company, role and an optional JD (<= 40,000 chars) and posts `/api/resumes/{primaryId}/variant` (`primary-resume.js:41`). `primaryTools` (`ApiController.php:336-373`) copies the source document content, sets title `<role> · <company>`, `parent_id`, `job_description`, enforces `min(savedResumes+1, builder.limit)` under the user lock, and returns the new id; the UI opens the builder with `draft=<id>`. Nothing rewrites the content from the JD; the JD is stored and returned by `GET /api/resumes/{id}` but no screen displays it. "Make primary" flips `is_primary` for all drafts in one transaction.

### 7.6 ATS match
`#view-match` is built by `connected-ui.js`: PDF (pdf.js, <= 30 pages) or DOCX (JSZip, macros refused, size caps) <= 5 MB is read on the device; user must tick "I reviewed the extracted text"; `match-engine.js` (a curated concept dictionary plus structure checks; runs in `match-worker.js` when `CVTaraMatch` is not already loaded, 15 s timeout) produces requirement evidence, parameters and an action plan; only after the analysis succeeds does the browser call `POST /api/features/use` (`connected-ui.js:34`), which counts the review: under the user lock it enforces `features.match.enabled`, the daily cap (free users `min(features.match.limit, plan dailyMatches=3)`, paid users `dailyMatches` default 100) and, for paid plans, `atsScans` per purchase (`ApiController.php:209-229`).

### 7.7 Cover letter
`cover-letter-studio.js` (stages: design -> write -> review): 26 designs, import name/contact/evidence from a saved resume, local draft `cvtara-cover-letter-v1-<user>`, save posts to `/api/letters` (validation requires company, role, opening, evidence; letter limit `min(savedLetters, features.letter.draftLimit)`).

### 7.8 Application tracker
`workspace-activity.js` renders a board (saved/applied/interview/offer/closed) from `/api/applications` and `/api/resumes`; create/update post with `revision` (409 when stale), delete sends `revision`; tracker limit `features.tracker.limit` (default 100); `follow_up` dates trigger the `followup_due` reminder.

### 7.9 Checkout (Razorpay; demo in preview)
1. Plans appear in the billing view or on `/checkout.html` via `plans-ui.js`; audience (fresher/experienced) comes from the URL, `localStorage cvtara-audience` or the profile.
2. `checkout-options.js`: user must tick the policy checkbox; guests are sent to `/login?next=`; coupon entry (`business-ui.js`) calls `POST /api/checkout/quote`.
3. "Continue to secure payment" posts `/api/checkout/start` (`ApiController.php:270`). Preview mode without keys returns `provider:'demo'`, then `/api/checkout/verify {provider:'demo'}` captures immediately (`checkout-options.js:50-55`). Live mode returns `{orderId, keyId, amount}`; the script loads `https://checkout.razorpay.com/v1/checkout.js` and opens Razorpay.
4. Success handler posts the three Razorpay fields to `/api/checkout/verify` (`Payments::verify`). Razorpay also calls the webhook; whichever arrives first activates, the second is a no-op (section 5.2).

### 7.10 Plan activation
`capture()` sets `paid`, `purchased_at`, `access_until`, then commission credit, `purchase_verified` audit and the receipt mail. The UI redirects to `/workspace.html?view=billing`; `workspace-ui.js` loads `/api/allowance` (plan name, days left, caps, usage) and renders the dashboard, sidebar plan box and billing view.

### 7.11 Clean download
Download options (`launch-ui.js CVTaraExport`) require a saved draft id; each "Clean ..." button posts `/export/{type}/{id}/{format}` with `clean:true`; "Preview" buttons post `clean:false` (watermarked). Print format opens a new tab with the HTML blob (`document-print.blade.php` renders client-side); PDF/DOCX download as files. A clean export consumes one reward export or one plan export (section 5.7). Unpaid users receive 402 and a once-a-day `download_locked` mail; `reward-paywall.js` adds "view plans" and a referral panel to the dialog.

### 7.12 Renewal and add-ons
Billing view (`plans-ui.js` mode `billing`): the current plan shows "Renew +N days" (same-plan purchase stacks, section 5.2); other plans show "Get ..." even if lower-ranked; add-on packs show "Add on" and are refused at quote/start unless an active plan exists (`ApiController.php:266,272`).

### 7.13 Refer and earn
1. `referral-center.js` (Account view) and `referral-dashboard.js` (Referrals, Wallet, History views) read `GET /api/referrals`: link `/r/<code>`, balances, commissions, referred friends (anonymised "Friend xxxxxx" labels), withdrawals.
2. Visitor opens `/r/<code>` -> cookie/session -> registers within 30 days -> `referred_by` set.
3. When the friend's live Razorpay payment is captured, a commission is created (rate frozen, hold 7 days) if the referrer holds a plan with referral access (Pro tiers by default).
4. The referrer may apply as a creator (admin sets a custom rate), submits a payout profile (step-up), waits 48 hours and admin approval, then requests a withdrawal (min Rs 1,000, step-up). Admin reviews checklist, then marks processing and either pays manually (UPI/bank reference) or sends through Cashfree and syncs.
5. Separately, milestone rewards (`reward-paywall.js`): verify phone by SMS, then at 5 qualifying friends claim one clean export, at 10 claim a free Standard month (only after any paid plan has lapsed).

### 7.14 Export or delete data
Account view -> "Your data" panel (`workspace-ui.js privacy()`): JSON download via `GET /api/account/export`; deletion form (password + type DELETE) posts `/api/account/delete` and redirects to `/`.

---

## 8. Admin journeys (`/admin.html`, admin only)

`admin.html` ships static mock markup that the JS replaces. The shell and navigation live in `admin-ops.js` (`CVTaraAdminKit`, view registry `register(key, fn)`, banners, a floating "live stats" card refreshed every 30 s), step-up dialog in `admin-extras.js` (`ensureRecent`).

| Area | Nav key | Owning file | Backend |
|---|---|---|---|
| Overview | dashboard | `admin-ops.js dashboard()` (KPIs computed in the browser from `admin/users` (page 1), `admin/purchases` (latest 500) and `admin/dashboard`) | `AdminController.php:148-185`, `:145` |
| Users: search, edit, mark paid, revoke, block, delete, CSV | people | `admin-people.js` | `users`, `users/update`, `users/grant`, `users/revoke`, `users/block`, `users/delete` (step-up) |
| Orders list and detail modal | orders | `admin-people.js` | `purchases`, `orders/{id}` |
| Resume / cover-letter library | resumelib, letterlib | `admin-content.js library()` | `content/library` |
| Site content, per-page SEO | sitedesign, seo | `admin-content.js`, `owner-console.js` | `content/site`, `content/seo`, `seo` |
| Mailer: templates, switch, queue, test | mailer | `admin-content.js` | `content/emails`, `content/integrations`, `email/jobs`, `email/process`, `setup/email-test` |
| Error log | errors | `admin-people.js` | `errors`, `errors/clear`, `errors/download` |
| Blog editor and SEO files | bloghub | `admin-publish.js` | `content/blog`, `content/seo`, `media` |
| Policies and URLs | policies | `admin-publish.js` | `content/legal`, `legal/generate`, `site-urls` |
| Setup and health | health, setup | `admin-publish.js`, `admin-extras.js` | `health/history`, `health/run`, `setup/status|business|cache|email-test|payout-policy|reward-review` |
| Referral ops (affiliates, creators, identity, withdrawals) | referral, commerce | `referral-center.js owner()` (the older `admin-ops.js referral()` is a read-only summary) | `referrals*` |
| Reviews | desk / reviews | `admin-ops.js desk()` | `reviews`, `feedback/add` |
| Pricing and add-ons | pricing | `admin-extras.js pricingPanel()` | `plans` |
| Business controls (maintenance, referral rules, coupons) | business | `business-ui.js` | `business-controls` |
| Integrations, analytics, Search Console | integrations, analytics, keywords | `owner-advanced.js`, `owner-console.js` | `providers`, `reports`, `search/*` |
| Popup, push, features | popup, push, features | `admin-extras.js`, `connected-ui.js`, `owner-console.js` | `content/popup`, `settings`, `content/features` |

Typical "mark paid" journey: Users -> Mark paid -> choose plan, optional days and amount, note -> step-up dialog (email code + password) -> `POST users/grant` creates a `manual` purchase with the plan snapshot and calls the same `Payments::capture`, optionally e-mails the thank-you immediately (`AdminOpsController.php:94-119`). Revoke marks every active purchase `refunded` and reverses commissions (`:136-152`). Delete runs the same anonymisation as self-service deletion (`:153-161`).

---

## 9. Frontend architecture

**Script ownership** (all vanilla JS, no bundler; the shared bootstrap order on public pages is `data.js`, `reference-templates.js`, `premium-collection.js`, `template-curation.js`, `preview.js`, `profile-api.js`, `onesignal-push.js`, `connected-ui.js`, `workspace-activity.js`, `owner-*.js`, `profile-onboarding.js`, `business-ui.js`, plus page scripts; `ContentController` removes/appends some, section 3):

| File | Owns |
|---|---|
| `data.js` | catalog data for templates, letters, blog, features, sample profiles (global `CVTARA_DATA`) |
| `reference-templates.js`, `premium-collection.js` | extra templates, document renderers `CVTaraReferenceTemplates.render`, `CVTaraPremium.renderLetter` |
| `preview.js` | shared shell: header/footer/theme, galleries, article builder, calculators, workspace and admin view router, dialogs |
| `profile-api.js` | `CVTaraAPI.request` (JSON, `X-CSRF-TOKEN` from the meta tag, error normalisation) |
| `connected-ui.js` | import (PDF/DOCX) and ATS-match UI, Account/profile view, public settings, legacy admin panels |
| `match-engine.js`, `match-worker.js` | deterministic role-evidence engine |
| `resume-studio.js`, `cover-letter-studio.js`, `primary-resume.js` | builder, letter builder, resume list/variants |
| `workspace-ui.js` | dashboard, sidebar plan box, billing, privacy tools |
| `workspace-activity.js` | purchase history, tracker, admin purchase list |
| `plans-ui.js`, `checkout-options.js`, `business-ui.js` | pricing cards, checkout flow, coupon box, admin business controls, public referral copy |
| `launch-ui.js`, `reward-paywall.js` | export dialog, TOC, writing-help dialog; referral milestone panel |
| `referral-center.js`, `referral-dashboard.js` | referral/payout UI for customers and owner |
| `owner-public.js` | applies owner content to public pages; analytics consent; feedback form |
| `owner-console.js`, `owner-advanced.js`, `admin-*.js`, `product-roadmap.js` | owner console |
| `home-ui.js`, `fomo-popup.js`, `experience.js`, `error-report.js`, `onesignal-push.js` | landing page, popup, motion/fetch progress bar and help link, error reporting, push |

**Cross-script contracts**: custom events `cvtara-workspace-view`, `cvtara-profile-rendered`, `cvtara-resume-saved`, `cvtara-letter-saved`, `cvtara-owner-content`, `cvtara-library-refresh`, `cvtara-allowance`, `cvtara-settings`; globals `CVTaraAPI`, `CVTaraStudio`, `CVTaraExport`, `CVTaraPlans`, `CVTaraBusiness`, `CVTaraCoupon`, `CVTaraOwnerContent`, `CVTaraProfile`, `CVTaraAdminKit`, `CVTaraEnsureRecent`.

**Browser storage**: `cvtara-resume-studio-v1-<user>`, `cvtara-cover-letter-v1-<user>` (drafts, contain personal data), `cvtara-theme`, `cvtara-audience`, `cvtara-analytics-consent`, `cvtara-url-review` (admin), sessionStorage `cvtara-fomo`, `cvtara-exit`, `cvtara-import-pending`.

**Preview mode (`CVTARA_PREVIEW=true`)**: enables `/preview` and `/preview/go/*`, bypasses `AccountSecurity::requireRecent` and the email requirement for codes (`AccountSecurity.php:16,50`), returns `recent:true` from `security/status` (`ApiController.php:131`), and turns checkout into a demo payment when no Razorpay keys exist (`Payments.php:19`, `ApiController.php:276`). `php artisan cvtara:demo` seeds `demo@cvtara.test` (Fresher Pro), `owner@cvtara.test` and 12 sample users with the fixed password in `config/app.php:72` (`routes/console.php:35-114`). `tools/build_preview.py` and `tools/static-preview/` build self-contained demo bundles.

---

## 10. Installer, cron, packaging

* **Installer** (`public_html/install.php`): refuses when `storage/installed.lock` exists (`:6`), CSRF-protected form with session cookie SameSite=Strict, requires the one-time key (either sha256 in `public_html/.install-key-hash` or plaintext `.setup-key`) (`:12-13`), optionally unpacks `.cvtara-payload.zip` to `cvtara_app` rejecting traversal entries (`:15-20`), checks PHP 8.3 and extensions, requires an `https://host` URL without path, owner password 12+, refuses a database that already has users, writes `.env` (mode 0600, values escaped) with production defaults (`:29`), runs `migrate --force`, creates the owner (`is_admin`), seeds `seo` (indexing off unless ticked) and `integrations` (email on), writes the lock and deletes the key files and payload (`:30`).
* **Packaging** (`tools/build_package.py`): generates `secrets.token_urlsafe(18)` as install key, ships `INSTALL_KEY.txt` at the ZIP root (outside `public_html`), `public_html/.install-key-hash` and the payload zip; `dist/` is git-ignored.
* **Cron**: the documented entry is `/usr/bin/php .../cvtara_app/private-cron.php` every minute (`docs/README_HOSTINGER.md`). `private-cron.php` exits unless `PHP_SAPI==='cli'` and calls `artisan cvtara:cron`: reminders, daily health, Cashfree payout reconcile, mail processing (`routes/console.php:12-21`). `Schedule::command('cvtara:cron')->everyMinute()->withoutOverlapping()` is also registered (`:22`) but only applies if `schedule:run` is the cron target. `cvtara:admin {email} {name}` creates/promotes an owner (`:23-32`).
* **Tests/harness**: `tests/audit-harness` holds Python/Playwright scripts and a `FakeProviders` service provider that stubs Razorpay, Twilio, Resend and OpenAI; there is no PHPUnit suite in the package.
