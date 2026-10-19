# CVTara v3.16.0 — New packages, 7-day refund, Give ₹50 get ₹50

## 3.16.0
- **New packages:** Free, Quick Download ₹99 for 7 days (one resume, edit and re-download it as often as you like), Job Hunt ₹299 for 30 days (5 resumes, 5 cover letters, 20 ATS scans) and Pro ₹599 for 90 days (20/20/60). Plan cards show the price per day, "Most popular" and "Best value". Old plans keep working for customers who bought them; old plan links and coupons map to the new plans.
- **Upgrade credit:** a Quick Download bought in the last 7 days and not refunded counts toward Job Hunt or Pro.
- **Services:** Tara Expert Review ₹499 (₹399 with Job Hunt or Pro), Profile Boost ₹699 (₹599 with a plan) and the Complete Job Kit ₹1,299 (worth ₹1,797). They are off sale until the owner switches them on in Pricing; orders show up in a new reviewer queue.
- **7-day refund if unused**, once per customer: "Request refund" in Billing, an owner queue to approve or decline (with optional auto-approve), gateway refunds for Cashfree and Razorpay, and three new emails. Refund Policy, Terms and About text updated; saved copies that still said "non-refundable" are replaced automatically.
- **Give ₹50, get ₹50:** on orders of ₹299 or more (Job Hunt, Pro or bigger), the friend gets ₹50 off their first such order and the referrer gets ₹50 wallet credit once that purchase is 7 days old. A ₹99 Quick Download earns neither. Saved referral settings below ₹299 are raised to ₹299. Credit lasts 90 days, at most ₹1,000 a month, and can be used at checkout. 5 qualified friends unlock a free Job Hunt month. Same inbox, browser, phone or payment counts as self-referral. Earlier cash commission stays withdrawable.
- **Paywall:** the download dialog offers two choices, Quick Download or Job Hunt.
- Checkout shows each discount line and "You pay".
- **Offers:** the countdown timer is gone. The offer pop-up opens 20 seconds into a visit instead; saved offers are switched to this automatically.
- Add-on packs are off sale by default (switch on in Pricing).

## 3.14.4
- **Owner console: "Clear cache" button** in the top bar of every owner page and in Speed & traffic ("Clear cache & refresh site"). One click removes the stored pages, clears compiled views and cached settings, rebuilds every public page and reloads the page fresh.
- **New customers land on the Dashboard** after sign-up and after confirming their email (they used to land on My Resumes). The Dashboard retries once if plan details are slow to load and shows a clear message instead of a blank area.

## 3.14.3
- **Sample resume previews:** two-column designs (Prism, Folio and others) no longer squeeze the side column, so "Contact", job titles and e-mail addresses are no longer broken letter by letter; the photo placeholder is a neutral silhouette instead of "Photo optional"; the "fictional sample" note is a clean label that does not collide with coloured headers.
- Template cards and the full-preview window show readable layout names ("Prism layout") and a properly capitalised "Best for" line.

## 3.14.2
- **Template and cover-letter previews fixed:** section headings inside the sample documents ("Profile", "Experience", "Contact", "Application for…") had been forced to page-heading size by the shared page styles. They are small and proper again.
- **One H1 per page:** sample resumes and letters no longer use an H1 for the name (templates page 13 headings, cover-letter page 27, now 1 each). Styles carried over.
- **Contact page** now has a real H1. **Footer** lists "Contact & support" once.

## 3.14.1
- **Referral reward simplified:** refer 5 friends who sign up, confirm their email and buy a plan, and you get one free resume download plus one month of subscription, free. The 10-friend tier is removed everywhere (site, workspace, owner console, server). Referral link, dashboard and commission stay.
- Phone (SMS) verification is no longer needed to claim. Friends must pay for a plan, which keeps it abuse-proof.
- Owner console: "Paying friends needed for the reward" and "Free subscription length" are editable under Referral Ops.

## 3.14.0
- **Home page** now says plainly what is free and what a plan adds ("What you get"), with the price from your plans and a clear route to sign up or buy. Flatter cards, shorter copy.
- **Removed:** the template-invitation pop-up that appeared when the mouse left the page.
- **Footer logo** sits on a white tile so it reads on the dark footer.
- Download dialog for accounts without a plan has one clear path: view plans and upgrade.
- Plan-nudge email 11 now explains the 5-friend reward.

## 3.13.0
- **Branded email.** Every mail is sent as HTML plus plain text with a CVTara header, a signature (Team CVTara, HR team in Bangalore, support@cvtara.com) and, for tips, a one-click unsubscribe link and header.
- **Sign-up email code.** New customers confirm a 6-digit code before buying or running ATS checks (only once email delivery is switched on). Existing customers are not affected.
- **Automatic mail:** resume-saved follow-up, abandoned-plan mail, plan ending in 7, 4 and 1 days, twelve plan nudges over a year (until the first purchase, opted-in only), a monthly career guide for paid and free customers, forgot-password, receipt and application follow-ups spread over about a month (day 7, 15, 30).
- **Sign-up form** shows each mistake (mismatched or weak password, taken email) next to the field.
- **Plan-needed dialog** explains, step by step, that a plan comes before clean downloads and more ATS checks.
- **Job field switcher** on My Resumes: move from a non-technical to a technical job and suggestions follow.

## 3.12.1
- **Front page restructured step by step:** hero with a real resume and cover-letter preview, design showcase (resumes and matching cover letters), employer-names strip, five-step "how it works", primary-resume explainer, ATS Match, who it is for, comparison, pricing, referral, FAQ. Repeated sections removed.
- **Public resume library grows** with role-specific single-column designs (nurse, accountant, QA, DevOps, security, data scientist, product manager, customer success, supply chain, academic and more). Narrow side-column layouts were switched to single column for ATS readability.
- **Sign-in and sign-up redesigned** to match the home page: two-column sign-up that fits one screen, show/hide password, benefits panel, mobile layout.
- **One look across public pages:** shared page heading, spacing and legal-page styles (`site-polish.css`).
- Section headings no longer split mid-word in narrow template columns; template previews show the top of the sheet; stat counts match what is published.

## 3.12.0
- Job-link pasting is off for customers; the owner switches it on under Speed & traffic.
- "Employer names" strip (plain names, no logos, owner-editable) with a no-affiliation note; flow graphic `assets/brand/why-cvtara.svg`.
- ATS keywords: two-word tools such as Power BI are matched as one keyword.
- New favicon set. `.mjs` files are served as JavaScript.
- Package hygiene: vendor Markdown removed; release check rejects tool names.

---
# CVTara v3.11.0 — offers & festivals, recent-activity notices, home page, blog, About and Contact

- **Offers & festivals (owner console).** Start from 15 festival and occasion templates (Diwali, Holi, Navratri, Ganesh Chaturthi, Pooja, Eid, Pongal, Onam, Christmas, New Year, Republic/Independence Day, Early-bird, Flash sale, Graduation, Hiring season) or a blank offer. Edit heading, text, year, colours, button, picture (uploaded as WebP) and attach or create a coupon on the spot. **Make live now** switches the whole site promotion in one click (one live offer at a time); **Run by dates** uses start/end times in India time. Visitors see a top bar and three pop-ups with separate wording: after a delay, when about to leave (mouse leaves the page, or a fast scroll up on phones) and when idle. A coupon click goes through sign-up to checkout with the plan and coupon applied. Offer link (`/?offer=CODE`), printable coupon card, duplicate, and per-offer statistics (seen, clicks, codes copied, orders, revenue). Offers whose coupon is off or expired hide themselves. Offers are never shown on checkout or the dashboard.
- **Recent-activity notice rebuilt.** Real buyers only: a buyer who ticks an optional, unticked-by-default box at checkout is shown by first name and city (for example “Priya from Pune started Fresher Pro · 5 minutes ago · Verified purchase”). Nobody else is ever named and nothing is invented; when nobody has opted in, the notice shows real counts (resumes saved today and so on). First notice after 90 seconds, then every 25 seconds, never on top of an offer pop-up. Owner switch: Offers & social-proof pop-up > “Show real purchases”.
- **Home page rewritten.** Benefit-led numbers; “why job seekers switch” (six problems and how they are solved); three audience cards with what each gets; the primary-resume story; three steps; an automation section; the ATS Match section with an illustrative report; template showcase that loads when scrolled to, with field filters (six designs at a time); plan section with what every plan includes; referral section (free resume download, then a free month); one reviews section (the duplicate was removed); eight short, honest FAQs.
- **Blog.** Calculators and guides as separate tabs with counts, a “most used” strip, topic chips built from the real topics (new topics appear by themselves), search, “New” label on posts the owner publishes, and CollectionPage data for search engines.
- **Articles.** Author, reading time and type instead of an internal note; “On this page” built from the real headings (works without JavaScript); a next-step box and related reading; BlogPosting, BreadcrumbList and FAQPage data; FAQ shown once; the layout no longer collapses when the list was hidden.
- **About** rewritten (who is behind it, what you can do, how we work, where we are). **Contact** now works: the form sends to the owner **Support Inbox** (listed as “Contact form”), with topic, reference and one optional screenshot, a hidden-field bot check and rate limits per email and address. Support hours read Monday–Saturday, 10:00–18:00 IST everywhere. The privacy policy mentions the optional activity notice and the contact form.
- **Home page: employer screening and comparison.** New “Made the way employers' systems and recruiters read a resume” section (real selectable text, standard section names, ATS-conscious layouts, the job's own words, a score you can act on) and a comparison of CVTara with typical resume builders, free ATS checkers and paid writers. It names no company and shows no employer logos: nothing implies that an employer, portal or ATS vendor endorses CVTara.
- **Fixes found in a full hands-on check.** (1) All 32 calculators had lost their input form on server-rendered articles; they are back, and every calculator was run and its figures checked by hand (gratuity 15/26 rule, CTC to in-hand, SIP future value and withdrawal, notice buyout, increment, overtime, hourly to annual). (2) Article covers could show as black or broken boxes: they are now a drawn card in the topic colours (or the owner's picture with a gradient fallback). (3) An uploaded resume now starts in a plain, ATS-conscious layout, not the first (creative, photo) template. (4) Nine templates with a side column were labelled ATS-conscious; they are now labelled as visual designs. (5) The older “right template” exit invitation no longer appears on top of or beside an offer's own leaving pop-up. (6) Paid customers no longer see the “unlock a clean download” help in the Download dialog. (7) Removed unused files and code (two unreferenced stylesheets, an unused credits method).
- Database: `promo_stats` table, `profiles.show_in_activity`.
- Tests: new suites s19 (offers, notices) and s20 (content, contact, articles). Static previews support the new pages.

# CVTara v3.10.0 — resume upload, ATS-friendly score on every resume, no deleting, application reminders

- **Upload a resume you already have.** My Resumes, the Resume Builder template step and ATS Match (when there is no resume yet) offer "Upload your resume". PDF, Word (DOCX, and DOC on a best-effort basis) and text files are read in the browser, the text is sent to the server and turned into a normal editable draft: name, role, email, phone, city, links, summary, each job (role, company, dates, bullets), education, projects, certificates, skills and languages. The customer sees what was found, what to check and the ATS-friendly score, then reviews it in the builder. It counts toward the saved-resume limit like any resume (a free account still keeps one).
- **Or choose a template and build, then download.** Unchanged, now offered next to upload as three equal options (Upload, Choose a template, Tailor for a job).
- **ATS-friendly score on every resume.** A job-independent score from 0 to 100 (contact details, summary, experience with dates, bullets, action verbs, numbers, education, skills, length) is stored with each resume, recalculated on every save and shown as a ring with the label Excellent (85+), Good (70+), Needs work or Low, plus a "What affects this score?" breakdown with a tip for each missing point. It is also shown live in the builder after each save and in the ATS Match resume dropdown. A properly filled resume scores 70% or more; a thin one does not. The job match from ATS Match (latest and best, per resume) is shown next to it.
- **Resume list as Primary, Resume 2, Resume 3...** each card has Edit, Download, ATS check (opens ATS Match with that resume selected) and Make primary.
- **Saved resumes cannot be deleted** by the customer (so credits and the free-primary rule cannot be gamed). Edit instead. The owner can allow deleting again by setting `allowDelete` to true in the builder feature settings. Cover letters can still be deleted until they are downloaded; downloaded letters stay too. Account deletion and the owner console are unchanged.
- **Prepared resumes go into the tracker.** Creating a tailored resume (from an ATS check or the tailor form) adds the job to Applications at once (origin "ATS Match" or "prepared"); downloads still add their credits to the same entry.
- **Three status reminders, then silence.** For jobs added automatically (stage Saved, Applied or Interview) a reminder is created 3 days after the job is added, then 4 and 7 days later: an in-app card ("Update status") and an email (template "Application status reminder", editable in the owner console). The wording asks the right question for the stage. After the third reminder nothing more is sent for that job. Reminders stop earlier when the job reaches Offer or Closed, when the customer ticks "Stop status reminders for this job", or for jobs the customer added by hand. `php artisan cvtara:remind` runs them (the hourly cron already does).
- Database: `ats_score`, `downloaded_at`, `source` on resumes, `downloaded_at` on cover letters, reminder columns on applications, new `notifications` table.
- Tests: new suite s18 (63 checks); s16 updated for tracker-on-prepare. Static previews updated.
- Machine learning: not added in this version. See docs/PROJECT_MEMORY.md for the reasoning and the plan.

# CVTara v3.9.0 — server-side ATS Match, job-link reading, feedback inbox, ready-made pages and busy mode

- **ATS Match runs on the server.** The review (requirement evidence, document checks, keyword coverage, match estimate, what you are lacking) is built in PHP in the same step that reserves the scan, from the saved resume, so it cannot be produced without using the plan's scans and the browser no longer ships the review engine. The server engine reproduces the original engine exactly (golden test). Saved checks reopen with the full report and can no longer be re-run for free.
- **Job links, with details.** Every check re-reads the posting. LinkedIn and Indeed links are cleaned to the posting itself. The details a posting publishes (location, job type, salary, posted and closing dates, industry, skills) appear under "About this job". If a portal refuses automatic reading, the owner can switch on a page-rendering service (ScrapingBee, ZenRows or a custom address) in Owner Console → Speed & traffic; only the job link is sent, a daily cap limits cost, and nothing is sent unless a plain read failed. Without it customers get a clear "paste or upload" message and no scan is used.
- **Feedback button for signed-in customers.** A floating button (right side, movable to the left) opens a side panel: choose what it is about, the type, a message, a star rating and up to 5 screenshots (2 MB each; JPG, PNG, WebP). Images are verified, re-encoded to WebP (hidden data removed) and stored privately; the page and screen size are attached. Limit 10 reports an hour per customer.
- **Support Inbox (owner console).** New reports with counts by status, type and area, search and filters, full message, customer plan and page, screenshots, status workflow (New → Seen → In progress → Resolved/Closed), internal notes, reply-by-email link, delete, and a badge in the menu. A "Support" dataset is not exported yet; the inbox is the record.
- **Ready-made pages (pre-build).** Public pages are stored as finished files in `public_html/_pages`. Visitors without a session cookie get them straight from the web server (rules in `.htaccess`) or, failing that, from PHP before any session or database work, so a rush of visitors costs almost nothing. They rebuild when the owner saves content, on "Rebuild all pages now", with `php artisan cvtara:prebuild`, and at the end of installation. Signed-in visitors, queries, checkout, workspace and owner pages are never stored; no personal data is ever in a stored page (security tokens are requested on demand).
- **Busy mode.** Off, On, or Automatic (when the server load per core passes a limit): pauses only ATS reviews, job-link reading and PDF/Word creation with a polite "try again in a minute" message (HTTP 503 with Retry-After). Pages, sign-in, payments and saved work keep working.
- **Speed & traffic** page in the owner console: pages ready, size, last build, server load, switches, rebuild/clear buttons and the job reader settings with a test button.
- Version 3.9.0; installer builds the pages; package excludes `_pages`; static previews and tests updated (ATS server flow, support inbox, ready-made pages, busy mode: 150+ new checks).

# CVTara v3.8.0 — ATS Match flow, scan limits, automatic application tracking

- **ATS Match rebuilt.** No resume upload: the customer picks a saved resume from a dropdown (primary first) and adds a job by **link** (read fresh on every check), **PDF / DOCX / text file**, or **pasted text**. The report shows a match estimate, the job's keywords found and missing, every requirement lacking (with what to fix) and the full requirement-by-requirement evidence. The estimate is labelled as guidance, not a real employer ATS result.
- **Then build:** the customer chooses a tailored resume, a cover letter or both; the resume copy is created with the job description and link, the cover letter opens with role and company filled in. Saved checks are listed with their summaries.
- **Scan limits, same formula as downloads:** Basic 20, Standard 40, Pro 60 scans (editable per plan); add-on packs add 2 scans per resume credit, valid until the subscription ends, used after the plan's scans. A scan is reserved on the server before the review and given back if the review cannot finish; unfinished scans are released after 15 minutes. Free accounts keep the small daily limit. Old purchases that said "unlimited" now use their plan's current limit.
- **Safe job links:** only public http(s) pages; private, loopback and reserved addresses are refused (address pinned per request, redirects re-checked), 2.5 MB / 12 s caps, 10 link reads a minute per customer. Login-only portals fail with a clear message and use no scan.
- **Credit notice after every clean download:** "1 resume credit used" / "1 cover-letter credit used" (or free primary, same version, reward), credits left in both pools, a running total for the visit (for example 1 resume + 2 letters = 3 credits), and whether the job was added to Applications.
- **Applications are added automatically** when a clean resume or letter is downloaded for a job with a company and role (one entry per company + role, stage "Saved"), with the job link and the credits used per job. Primary-resume downloads without a job are not added.
- **Add-ons expire with the subscription:** an add-on is valid until the end of the plan it was bought on (no separate 45 days). Renewing early moves unused add-on credits to the end of the renewed plan.
- **Sidebar no longer changes size** between pages (ATS Match, Resume Builder and Cover Letters were resizing the menu, its text, the page padding and the page width; every view now has identical layout and the scrollbar space is always reserved).
- Owner console: new **ATS checks** report (filter, columns, CSV/Excel/print export); plan editor wording; customer guide, admin guide, front page and package details describe the flow and limits. Account export includes ATS checks; old unfinished checks are pruned after 7 days and finished ones after a year.

# CVTara v3.7.0 — credits are spent on download, separate pools, preview protection

- **Credits move to download time:** saving drafts is free; the first clean download of a tailored resume or a cover letter uses 1 credit of its own kind. One primary resume download is free once for life, after the first purchase only (no repeat on renewals, none without a plan); the same version in another format is free; an edited version needs a new credit.
- **Separate pools (replaces the shared pool of v3.6.0):** Fresher Basic = 10 resume downloads + 10 cover-letter downloads; using one pool never touches the other. Add-ons add to each pool (+10 & +10), valid 45 days from purchase, carried into renewals, spent after plan credits.
- **Free-account previews are watermarked** (paid accounts see clean previews and clean downloads) with an account code, and cannot be selected, copied, dragged or printed from the page; they blur when the window loses focus or Print Screen is pressed. This deters casual copying; it cannot stop every screenshot.
- The download dialog shows how many credits are left and asks before using one.
- Credit meters (dashboard, billing, My Resumes, Cover Letters, Account), plan and add-on cards, checkout, front page FAQ, customer guide, Pricing Plans editor and admin guide all describe the new rules.
- Tests: `Download credits (separate pools)` (25 checks) plus preview-protection checks.

# CVTara v3.6.0 — shared document credits

- **One shared pool for every plan:** 1 free premium primary resume + document credits (tailored-resume credits + cover-letter credits) used by tailored resumes and cover letters alike. Fresher Basic = 1 + 20 (for example 15 resumes + 5 letters, or 10 + 10); the same rule applies to every plan and add-on.
- **Add-ons add to the pool and carry forward:** "+10 resumes & +10 letters" = +20 credits (20 + 20 = 40 total), valid **45 days from purchase** (editable per pack), counted whenever a plan is active, and kept through a renewal inside the 45 days.
- Dashboard, My Resumes, Cover Letters and Account show "Document credits used / total" with the split; the separate "downloads / exports" meter is gone (clean downloads of saved documents are no longer counted separately).
- Pricing Plans editor and public plan cards describe credits; the customer guide explains the model.
- Tests: `Document credits (shared pool)` checks 1 + 20, 15 + 5, blocking at 20, +20 add-on to 40, and the 45-day validity.

# CVTara v3.5.2 — customer portal polish

- **My saved cover letters** (with "x / 30 draft slots used") now appears under the **Cover Letters** section and refreshes after you save a letter.
- **Share feedback** has its own page in the customer menu: display name, star rating, feedback text with a counter, optional permission to publish, and your earlier feedback with withdraw-consent.
- **Downloads label explained:** "Clean PDF / Word downloads 0 / 120" means how many watermark-free downloads your plan allows (each one uses 1); previews are free.
- **Customer guide rebuilt** (`/customer-guide.html`): hero with a 3-step quick start, sticky chapter menu, step-by-step cards, live package prices, FAQ, help and privacy; works in light and night mode and on phones.
- **cvtara.com details** in the customer portal (menu footer, page title, contact page, guide).

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
- **Release package:** scripts are minified and `composer.lock` is not shipped; no tool or authorship notes anywhere in shipped source (checked by a test). Removed an unused option from the writing-service list.
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
