# CVTara — project memory (everything decided so far)

Keep this file current. It records what the owner asked for, what was built, the rules behind it and what is still open, so any later session (or developer) can continue without asking again.

## Business
- Product: CVTara resume + cover-letter builder with ATS Match, by an HR team in Bangalore. Domain cvtara.com, support@cvtara.com. English only. All payments final and non-refundable. No AI-authorship mentions anywhere. Hosting: Hostinger shared (Apache, PHP 8.3, MySQL/MariaDB).
- Private URLs: installer `/setup/afsar`; owner login `/afsar` and `/sanaulla`; owner console hidden from everyone else (redirects to home); site not indexed until the owner switches indexing on.
- Payments: Cashfree primary (Razorpay optional). Payments are never paused by busy mode.

## Plans and limits (single formula for every plan)
- Fresher Basic/Standard/Pro ₹299/399/499 and Professional Basic/Standard/Pro ₹399/499/599. Validity **30 / 60 / 90 days**. Plan downloads **10 / 20 / 30 resume + 10 / 20 / 30 cover-letter** (separate pools). ATS scans **20 / 40 / 60**. Add-ons ₹149/249/399 = +10/+20/+30 resume + cover-letter downloads and +20/+40/+60 scans; **valid until the subscription ends** and move to the end of the plan on an early renewal; used after plan credits.
- Credits are used when a **clean download** happens (drafts and saving are free; same version in another format is free; an edited version needs a new credit). One primary resume download is free **once for life**, only after a first purchase. Credit notice popup after every download; downloaded jobs are added to Applications automatically (stage Saved, with credits used).
- Free accounts: 1 resume + 1 cover letter, watermarked previews with copy/print protection, a few ATS checks a day, no clean downloads. Paid previews and downloads are clean. Screenshots cannot be fully blocked by any website (stated honestly in the guide).

## Customer features
- Resume Builder (primary resume + job-tailored versions), Cover Letters (saved letters listed under the section), **ATS Match** (choose a saved resume from a dropdown — no upload; job by link / PDF-DOCX-text file / pasted text; server-side report with match estimate, keywords, gaps, job details; then choose tailored resume / cover letter / both), Applications tracker, Billing & Plans, Referral programme, My wallet, Account, Share feedback (testimonials), customer guide, **Feedback button** (signed-in customers only; right or left side; up to 5 screenshots of 2 MB; limit 10 reports an hour).
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
