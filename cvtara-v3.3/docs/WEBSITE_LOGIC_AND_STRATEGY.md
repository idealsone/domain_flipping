# CVTara — how the website works, what it sells, what can go wrong, and what to do next

Version 3.4.1 · prepared 14 Oct 2026 · companion files: `architecture_and_logic.md` (code-level map, every route/table/service), `market_research.md` (market, competitors, pricing, roadmap with sources), `CHANGELOG.md`, `README_HOSTINGER.md`.

## 1. The product in one paragraph
CVTara is an ATS-friendly resume and cover-letter builder run by an experienced HR team in Bangalore. A person registers (everyone starts as a **normal user** — there is no free package), builds **one premium primary resume**, then creates job-tailored versions and cover letters from job descriptions, checks ATS match, tracks applications, and pays once (one-time plan, no subscription) to unlock clean PDF/Word downloads and larger allowances. The owner runs everything from one private Owner Console.

## 2. Packages and rules
| Plan | Price | Access | Saved resumes | Saved letters | ATS scans | Notes |
|---|---|---|---|---|---|---|
| Fresher Basic / Standard / Pro | ₹299 / 399 / 499 | 60 days | 10 + 1 primary | 10 | 10 / unlimited / unlimited | Pro adds referral earning |
| Experienced (pro-basic / standard / pro) | ₹399 / 499 / 599 | per plan | as edited by owner | | | Pro tier includes a review call |
| Add-ons | +10 ₹149 · +20 ₹249 · +30 ₹399 | on top of an active plan | adds resumes **and** letters | | | cannot be bought without an active plan |
| Normal user (`free` key) | ₹0 | account only | draft building | | | baseline, never purchasable |

Every plan includes "1 premium primary resume". All prices, perks, limits, popular badge and ranks are editable in **Owner Console → Pricing Plans** and are reflected on the public site immediately.

Purchase rules (all verified by automated tests): the strongest plan wins; a cheaper plan never downgrades; the same plan renewed stacks time (+60 days); add-ons raise limits; a partial refund keeps access; a dispute or full refund removes that purchase (access falls back to the next valid one) and a won dispute restores it; webhooks are idempotent; coupons support plan lists, expiry, total and per-user limits, and always leave a minimum ₹1; Razorpay is the live provider (demo/manual payments for previews and "Mark as paid").

## 3. What a customer does (after the landing page)
1. **Landing page** → choose "Build my resume" / "Check my resume" / pricing. Visitors may arrive through a referral link `/r/<code>` (stored in session).
2. **Register / sign in** (email + password, ≥12 characters; reset by email). Night mode is available on these pages too.
3. **Onboarding** (experience level, profession, category) → personalises templates and plan suggestions.
4. **Primary resume**: pick a template (live previews), fill sections, AI-assisted writing helpers, ATS-friendly output; autosave with revision checks.
5. **Tailor**: paste a job description → a variant is created linked to the primary; the allowance counter shows how many remain.
6. **ATS match** and **cover letter** per application; **tracker** for status of each application.
7. **Checkout**: choose plan/add-on, apply coupon, pay through Razorpay → server verifies signature → purchase snapshot (limits frozen at purchase) → access activated, receipt/thank-you email.
8. **Download** clean PDF/Word (watermark-free) within plan limits; renew or add packs when allowance runs out.
9. **Refer and earn** (Pro plans): share link, friends buy, commission is held for the hold period, then appears as available; withdraw after identity review.
10. **Privacy**: export all data or delete the account (purchases are kept but anonymised).

## 4. What the owner/admin can do (Owner Console)
Overview (KPIs, signups and revenue charts, recent orders, live stats) · **Users** (search, edit, block, delete, **Mark as paid** with subscription details, export) · **Orders** (filters, CSV, detail popup, refunds) · **Resume Library / CL Library** (thumbnails, 70/30 preview popup) · **Templates & site design** (headline, buttons, announcement, page SEO) · **Business Ops** (offers, coupons, maintenance mode) · **Referral Ops** (*the single place for referral rules*, affiliates, creator applications, payout identity review, withdrawal queue) · **User Mailer** (always-on switch, support@cvtara.com sender, template editor, queue) · **Error Log** (server + browser + mail + health, downloadable) · **Blog & SEO** (post editor, focus keywords, word counts, sitemap/robots) · **Reviews** · **Pricing Plans** · **Settings & health** (daily health check, providers) · **Platform Guide** (owner-only, noindex, never public).

## 5. How the parts connect
Browser (static HTML templates + vanilla JS modules) → `ContentController` assembles each page (theme script, SEO tags, saved content) → `/api/*` (`ApiController`) for customers and `/api/admin/*` (`AdminController`, `AffiliateAdminController`) for the owner → services (`Settings`, `Payments`, `BusinessControls`, `Referrals`, `MailAutomation`, `AccountSecurity`, `Documents`, `ErrorLog`, `Health`) → database (SQLite or MariaDB; settings in `site_settings`, money in `purchases`, `referral_commissions`, `withdrawals`). Razorpay → webhook → purchase state; the cron command `cvtara:cron` sends mail, runs daily health and releases held commissions. Sensitive owner actions require a recent step-up (email code + password) in production.

## 6. Error catalogue — what was found and what was done
| Area | Symptom seen | Cause | Resolution |
|---|---|---|---|
| Python scripts | errors on repeated runs | legacy audit scripts: order-dependent shared state, MariaDB/SQLite mix, `$AUDIT_DIR` placeholders, print-only | replaced by `python3 tests/run_tests.py` (isolated SQLite, own server, exit codes, plain-language setup errors); legacy kept with a notice |
| Referral rules | shown in two sections | Business Ops and Referral Ops both edited them | one editor in Referral Ops; Business Ops/Orders stripped; server saves all fields atomically with validation |
| Light/night mode | not switching, flashes, black panels | theme class on one element only; auth pages had no theme | `html`+`body`, no-flash head script, device default, saved choice, toggle on every page including sign-in; contrast scans clean |
| Owner console | half-built UI during load, width jumps | late CSS/JS | loading cover, fixed layout, 8s safety timeout |
| Legal pages | saved text never appeared | wrong selector | fixed in `ContentController` |
| Sign-in copy | "free account" wording | legacy text | removed |
| Environment | "Server did not start", `vendor/` missing | PHP/composer not set up | runner prints exactly what to install (exit code 2) |
See `architecture_and_logic.md` for any further code-level findings.

## 7. Quality evidence (this release)
136 automated checks pass (`tests/run_tests.py`: pages, plans, owner endpoints, Referral Ops round-trip, static JS parse, production-mode security); earlier regression suites for billing, refunds, webhooks, coupons, account deletion, owner operations and a 94-page crawl passed; theme and contrast scans report zero issues in both themes; the three preview links were rebuilt from the same code.

## 8. What to do next (best-practice plan, details in `market_research.md`)
1. **Before launch:** switch `CVTARA_PREVIEW` off, set Razorpay live keys and webhook, SMTP/Resend, HTTPS, cron every minute, then run `python3 tests/run_tests.py` plus a real ₹1 test payment and refund.
2. **Quick wins (high impact, low effort):** explainable **JD keyword-gap report**; UPI-first checkout with **GST invoice**; creator/campus-ambassador referral tier (rules already editable in Referral Ops); LinkedIn headline helper.
3. **Differentiators using the HR team:** paid human resume review with fixed turnaround; interview-prep packs by role; campus and training-institute tie-ups.
4. **Pricing:** keep ₹299–₹599 one-time; test whether add-ons (cheaper per unit) cannibalise Standard/Pro; use MRP strikethrough sparingly and honestly.
5. **SEO:** the 68 guides each have a focus keyword; add internal links from guides to the checker, publish 4–8 new guides a month, submit the sitemap.
6. **Compliance (confirm with a lawyer/CA):** DPDP consent for marketing and WhatsApp, GST registration thresholds, refund wording, payout KYC/TDS for referral withdrawals.
7. **Operations:** watch Error Log daily, review the withdrawal queue within the payout window, back up the database weekly.

## 9. Honest limits of this report
Market numbers and competitor prices come from web research and several vendor sites could not be fetched; they are labelled cited/unverified/assumption in `market_research.md`. Payment-provider behaviour was tested with simulated providers, not live money. Nothing here is legal or tax advice.
