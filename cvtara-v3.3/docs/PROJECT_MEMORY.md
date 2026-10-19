# CVTara — project memory (everything decided so far)

Keep this file current. It records what the owner asked for, what was built, the rules behind it and what is still open, so any later session (or developer) can continue without asking again.

## Business
- Product: CVTara resume + cover-letter builder with ATS Match, by an HR team in Bangalore. Domain cvtara.com, support@cvtara.com. English only. Refund within 7 days if unused, once per customer. No AI-authorship mentions anywhere. Hosting: Hostinger shared (Apache, PHP 8.3, MySQL/MariaDB).
- Private URLs: installer `/setup/afsar`; owner login `/afsar` and `/sanaulla`; owner console hidden from everyone else (redirects to home); site not indexed until the owner switches indexing on.
- Payments: Cashfree primary (Razorpay optional). Payments are never paused by busy mode.

## Plans and limits (single formula for every plan)
- Packages (3.16.0): Free; **Quick Download ₹99 / 7 days** (one resume, re-download free, price counts toward an upgrade); **Job Hunt ₹299 / 30 days** (5 resumes, 5 letters, 20 scans); **Pro ₹599 / 90 days** (20/20/60). Services Tara Expert Review ₹499 (₹399 with a plan) and Profile Boost ₹699 (₹599), Complete Job Kit ₹1,299 (worth ₹1,797): off sale until the owner switches them on. Add-on packs ₹149/249/399 also off sale by default; valid until the subscription ends.
- Credits are used when a **clean download** happens (drafts and saving are free; same version in another format is free; an edited version needs a new credit). One primary resume download is free **once for life**, only after a first purchase. Credit notice popup after every download; downloaded jobs are added to Applications automatically (stage Saved, with credits used).
- Free accounts: 1 resume + 1 cover letter, watermarked previews with copy/print protection, a few ATS checks a day, no clean downloads. Paid previews and downloads are clean. Screenshots cannot be fully blocked by any website (stated honestly in the guide).

## Customer features
- Resume Builder (primary resume + job-tailored versions), Cover Letters (saved letters listed under the section), **ATS Match** (choose a saved resume from a dropdown — no upload; job by link / PDF-DOCX-text file / pasted text; server-side report with match estimate, keywords, gaps, job details; then choose tailored resume / cover letter / both), Applications tracker, Billing & Plans, Referral programme ("Give ₹50, get ₹50" wallet: 7-day qualification, 90-day expiry, ₹1,000/month cap, 5 friends = free Job Hunt month), My wallet, Request refund (Billing), Account, Share feedback (testimonials), customer guide, **Feedback button** (signed-in customers only; right or left side; up to 5 screenshots of 2 MB; limit 10 reports an hour).
- Sidebar and page layout are identical on every view (no zoom/shift), including scrollbar space.

## Owner console
- Overview, Users, Orders, Reports & Export (datasets include ATS checks), Referral Ops, Pricing Plans (editable, validity/credits/scans), libraries, Business Ops, Integrations, Settings, **Support Inbox**, **Speed & traffic** (ready-made pages, busy mode, job link reader), Errors/health, backups, step-up confirmation for risky actions.

## Architecture rules worth remembering
- Laravel 13, query-builder style, static HTML templates rewritten by `ContentController`; owner console modules are JS files served only to the owner at `/console/{name}.js` (whitelist in routes/web.php).
- Credits: `App\Services\Credits` (pools from the best plan + active add-ons; usage rows in `product_usage`). Scans reserved in the same transaction that stores the report (`ats_scans`).
- ATS engine: `App\Services\AtsEngine` (PHP port of the original browser engine; golden test `tests/fixtures/ats_golden.json`). Job pages: `JobPage` (SSRF-safe, canonical LinkedIn/Indeed links, structured job details) + optional `JobReader` (owner-configured page-rendering service).
- Ready-made pages: `PageCache` + `ServeCachedPage` middleware + `.htaccess` rules; settings writes purge the cache; installer builds it. `Traffic` busy mode guards ATS scan and PDF/Word exports only.
- Feedback: `SupportController` (customer submit, image re-encode to WebP, private storage) and `SupportAdminController`.
- Tests: `python3 tests/run_tests.py` (isolated instance, own PHP server). Run `bash` sync into the dev app if the vendor symlink points at a different tree (vendor classmap is authoritative).
- Static previews: `tools/static-preview` (snapshot → build_static → publish three artifacts). Package: `tools/build_package.py` (terser minification, install key, ZIP).

## Honest limits / open items
- Nothing was run on a real Hostinger account, real Cashfree/Razorpay, real email/SMS, or real job portals (LinkedIn/Naukri/Indeed block automated reading; the optional reading service is unverified against live services).
- Some plan perks are listed text only (Naukri/LinkedIn export, 1-on-1 call, priority support, lifetime updates).
- Career Content Library: design only (`docs/CAREER_CONTENT_LIBRARY_DESIGN.md`); the owner chose to skip building it for now.
- Open audit items: F-29, F-30, F-32, F-38, F-45–F-52, F-54, F-57–F-59, F-62–F-64 (see `docs/risks_and_bugs.md`).
- A grammar pass over site text, code strings, data and articles was started in v3.9.0; results are listed in the changelog.

## v3.10.0 decisions (resume hub)

- Resume import is text based: the browser reads PDF/DOCX/TXT (and old DOC best-effort), `POST /api/resumes/import` parses headings with `App\Services\ResumeImport` and saves through the normal draft rules. Scanned PDFs and image-only files cannot be read; the customer is told to paste text or start from a template.
- "ATS-friendly" (`ResumeQuality`) is job-independent and deterministic. It cannot promise a match score for a given job: the match depends on the job. The 70%+ promise applies to the friendliness score of a properly filled resume.
- Customers cannot delete resumes (owner switch `features.builder.allowDelete`). Letters can be deleted until downloaded.
- Tracker reminders: 3 maximum per auto-added job (days 3, 7, 14 after adding), `Tracker::remind()`, `notifications` table, email trigger `application_status`.
- Machine learning is deliberately not built yet. Reasons: shared hosting has no model runtime; there is no outcome data yet to learn from; the current engine is explainable, which customers need ("what am I lacking"). Plan when there is data: (1) log anonymised outcomes (stage reached per job) with the keyword and requirement evidence of the scan, (2) after a few thousand outcomes fit a small logistic model offline and ship only its weights as JSON that `AtsEngine::score()` can use, (3) optional embedding service for synonym matching behind a provider switch like the job reader. Do not send resume content to a third party without consent and a policy update.

## v3.11.0 decisions (growth and content)

- Offers: `App\Services\Promos` (settings key `promos`), `PromoAdminController`, public `GET /api/promo` (cached 30 s, cache key version bumped on every save and coupon change), `promo.js` + `promo.css`. One live campaign at a time; scheduled campaigns run inside their window; a campaign whose coupon is unusable is never shown.
- Recent-activity notice: we deliberately do not show invented names or fake purchases (misleading to customers and risky under Indian consumer-protection rules). Only buyers who opted in at checkout (`profiles.show_in_activity`) are named, first name and city only. Do not add synthetic notices.
- The 68 ready-made article files in `resources/frontend/guides` and `calculators` are only templates for the shell; the visible text comes from the editorial content and the owner's edits (server-rendered). Their old fixed note and anchor list are replaced by the shared structure.
- Contact form posts to `/contact/message` (public) and lands in `support_reports` with area `contact`.

## Referral and offer economics (check before changing thresholds or discounts)

Defaults: 5 paying friends unlock one free resume download; 10 unlock 30 days of the Standard plan (Fresher Standard Rs 399 or Professional Standard Rs 499 list price); a friend counts only for a paid purchase of at least Rs 99; creators may also earn a 20% cash commission (separate programme, 7-day hold).
- 10 friends on the cheapest plan (Rs 299): Rs 2,990 collected (about Rs 2,534 before 18% GST). Reward value at list price Rs 399 to 499 is the worst-case cost, so 13% to 17% of money collected. With the 20% commission on top: about Rs 1,000 to 1,100, or 33% to 37% (40% to 43% of money before GST).
- The same 10 friends buying with a 25% festival coupon (Rs 224 each): Rs 2,243 collected; commission Rs 449 plus reward Rs 399 to 499 gives 38% to 42% of money collected.
- The cash cost of the reward itself is close to zero (it is access, not cash); the figures above are the revenue given up. Keep festival coupons at 25% or less and do not stack them with a high commission rate.

## v3.15.0 decisions (programmatic SEO)

- Programmatic pages live in `App\Services\SeoPages` + `resources/views/seo/page.blade.php` + `public_html/seo-pages.css`. Content is authored in `tools/seo/*.py` and compiled to `resources/data/seo-pages.json` by `tools/seo/build_seo_data.py` (it refuses unknown categories/roles and exclamation marks). Illustrations: `tools/seo/build_images.py` → `public_html/assets/seo/`.
- URL patterns follow the owner's master prompt section 6.3; paths always end with "/" so `PageCache` and `.htaccess` can serve them. Brand keywords (competitors, Word, Google Docs, company names) are deliberately not targeted, so `/resume-templates/word/` and `/resume-templates/google-docs/` do not exist.
- No salary figures until a sourced range exists. Place facts to check: `docs/SEO_VERIFY.md`.
- Existing guides get breadcrumbs, an "Explore next" box and up to 3 contextual links (`ContentController::articleLinks`).
