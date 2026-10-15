# CVTara — how the website works, what it sells, what can go wrong, and what to do next

Version 3.4.1 · prepared 14 Oct 2026 · companion files: `architecture_and_logic.md` (code-level map, every route/table/service), `market_research.md` (market, competitors, pricing, roadmap with sources), `CHANGELOG.md`, `README_HOSTINGER.md`.

## 1. The product in one paragraph
CVTara is an ATS-friendly resume and cover-letter builder run by an experienced HR team in Bangalore. A person registers (everyone starts as a **normal user** — there is no free package), builds **one premium primary resume**, then creates job-tailored versions and cover letters from job descriptions, checks ATS match, tracks applications, and pays once (one-time plan, no subscription) to unlock clean PDF/Word downloads and larger allowances. The owner runs everything from one private Owner Console.

## 2. Packages and rules
**Download credits (the allowance model):** drafts are free; credits are used only for clean (watermark-free) downloads. Resume downloads and cover-letter downloads are **separate pools**, so using all 10 resume downloads never touches the 10 letter downloads. Every plan also includes **1 premium primary resume that is always free to download**.

| Plan | Price | Access | Resume downloads | Cover-letter downloads | ATS scans |
|---|---|---|---|---|---|
| Fresher Basic / Standard / Pro | ₹299 / 399 / 499 | 60 / 60 / 90 days | 10 / 20 / 30 | 10 / 20 / 30 | 10 / unlimited / unlimited |
| Experienced (pro-basic / standard / pro) | ₹399 / 499 / 599 | 60 / 60 / 90 days | 10 / 20 / 30 | 10 / 20 / 30 | 10 / unlimited / unlimited |
| Add-ons +10 / +20 / +30 | ₹149 / 249 / 399 | **45 days from purchase** | +10 / +20 / +30 | +10 / +20 / +30 | — |
| Normal user (`free`) | ₹0 | account only | none (previews only) | none | 3 a day |

**Rules**
1. Saving, editing and previewing are free (a draft cap per kind prevents abuse). Previews on screen always carry a watermark with the customer's account code and cannot be copied or printed from the page.
2. A credit is used when a clean file of a **tailored resume** or a **cover letter** is downloaded for the first time. The same version in another format (PDF then Word) is free; an edited version is a new version and needs a new credit. The primary resume is free.
3. Each pool is its own counter. When one is empty the customer buys an add-on; the other pool is unaffected.
4. **Add-on packs** add the same number to the resume pool **and** the letter pool, valid 45 days from purchase (editable per pack), counted whenever a plan is active, and carried into a renewal inside the 45 days. Credits are spent from the plan first, then from the add-on that expires soonest, so add-on credits last as long as possible.
5. A stronger plan replaces a weaker plan's credits; add-ons stay on top. A renewed plan gives a fresh set of plan credits.
Example (Fresher Basic): 1 free primary + 10 resume downloads + 10 letter downloads; with "+10 resumes & +10 cover letters" that becomes 20 + 20 (plus the free primary resume) until the add-on's 45 days end.
All prices, credits, validity, perks, popular badge and ranks are editable in **Owner Console → Pricing Plans**.

**Screenshot protection (honest limits):** the site discourages casual copying — traceable watermark on every preview, no selecting/copying/dragging/printing of previews, previews blur when the window loses focus or Print Screen is pressed. No website can fully stop screenshots, screen recordings or phone cameras; the watermark with the account code makes a leak traceable, and only a paid download gives a clean file.

Other purchase rules (verified by automated tests): the strongest plan wins; a cheaper plan is refused while a higher one is active; the same plan renewed stacks time; a partial bank reversal keeps access, a full reversal or dispute removes that purchase; webhooks are idempotent; coupons support plan lists, expiry, total and per-user limits and always leave a minimum ₹1; Cashfree is the primary gateway (Razorpay optional); all payments are final and non-refundable.

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
