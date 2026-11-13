# CVTara v3.70.0 — Smoother, lighter, more visual

## 3.70.0
Owner (12 Nov 2026): smoother, lighter, more attractive UI across the site, dashboard and console; a landing page that sells; less text, more visuals and motion; charts that respond; fast on every device; collect the 10 testers' feedback first. Built on 3.69.0. One migration (`2026_12_02_000001_simpler_homepage_copy`, copy only).
- **Tester feedback first**: the 10 hands-on reviews of 13 Nov (`/mnt/project-files/qa-2026-11-13/`) drove the list below. Bug-level findings (Pro 20 vs 30, console revenue counts, ?view crash) are in 3.69.1 from the testing thread.
- **Tara stays quiet**: the chat never opens by itself (6 of 10 testers); a small greeting bubble shows once a day for 8 s. On phones the button is a 48 px round icon and the page keeps 72 px free above it. The closed panel is `inert`, so it is no longer a hidden Tab stop; focus returns to the button on close.
- **Dashboard: one next step**: the welcome card holds the single next step (`#wsNext`); Tara's nudge replaces its text in place instead of adding a box. The "One application, a clear next step" roadmap and the tips box are hidden. Layout shift on the dashboard: phone 0.97 → 0.002, laptop 0.46 → 0.015 (throttled, measured locally).
- **Your progress** (new, dashboard): a line chart of ATS match scores (hover, tap or Tab onto a point for the job, score and date; click opens ATS Match) and an applications funnel (Saved → Applied → Interview → Offer, click opens the tracker). Drawn last on the page so nothing moves.
- **Reminder cards** show one at a time and step aside after 12 s (they stay under the bell); on phones they sit above the chat button instead of over content.
- **Interactive charts, no library** (`cv-motion.js`, ~5 KB): `CVChart.bars/line` SVG with one tooltip, keyboard arrows, click-through, and draw-in animation. Used on the console Overview (signups by day, revenue by day), console traffic (page views by day) and the customer dashboard.
- **Landing page**: shorter headline and intro (moved by migration only if the owner never edited them), one solid button plus a text link, three proof ticks, floating documents with keyword chips, a "numbers at a glance" strip (templates, cover letters, from-price, 7-day refund) with icons, icon cards for How it works, shorter ATS copy, an animated sample report ring and ticks that now pass contrast (green was 2.3:1). Sections fade in as you scroll; all motion is off under reduced motion. On phones the bottom "Check free / View plans" bar waits until the hero has scrolled away.
- **Offer bar floats** at the bottom instead of pushing the page down (homepage shift 0.5 → 0.03 on laptops) and comes after "Skip to content" for keyboard users.
- **Checkout** keeps room for the plan cards while they load (shift 0.49 → 0.002).
- **Accessibility**: "Skip to main content" in the workspace; choosing a sidebar section moves focus to its heading; a new ATS Match report takes focus; dark-mode Tara link contrast.
- Night mode checked on the homepage and dashboard.
- Customer What's new: two notes (calmer dashboard, quiet chat).

# CVTara v3.69.0 — Stronger pages for the 60-day index push

## 3.69.0
Owner (12 Nov 2026, 18:31 UTC): "all pages should rank on the engine so do what you want but it should be there in 60 days". Built on 3.68.0. No migration.
- **Every page in the sitemap stays in.** The open idea of dropping the 130 city+role pages for 4 weeks is not used, because the goal is every page indexed.
- **More words of each page's own** on the most templated sections (measured as 6-word phrases not shared with other pages in the section):
  - Interview questions (82 pages): every role question now has a sample answer written for that role (410 answers, about 11,000 words). Own words per page, median 204 → 354.
  - Resume words (28 pages): three more before-and-after lines from different fields and a "When it is still fine" note. Median 131 → 227.
  - Cover letter examples (26 pages): an opening for freshers in that field and three mistakes letters in that field often make. Median 196 → 275.
  - Content lives in `tools/seo/answers_*.py`, `words_more.py` and `cover_more.py`; `build_seo_data.py` refuses to build if a role, word or field is missing its new content.
- **The sprint runs 60 days** instead of 30. A sprint started under 3.68.0 runs the full 60 days from its start (the live one now ends 11 Jan 2027). The plan has 8 weeks: weeks 5 to 8 cover a second wave for pages still not indexed, more outside links, a second position 4-30 review and the two-month report.
- **One hop to /pricing/**: `/pricing.html` and `/plans` now go straight to `/pricing/` (they went to `/pricing` first, then `/pricing/`).
- A page section with no content for that page is left out instead of showing an empty heading.
- `s80_index_sprint` covers the sample answers, word examples, cover letter notes, one-hop redirect and the 60-day sprint.

# CVTara v3.68.0 — Index sprint and pricing pages

## 3.68.0
Owner brief (12 Nov 2026): the SEO and Growth agents should work about 10x harder for one month so ~600 pages get indexed and rank; every approval must say which page, which section and which link; build transactional pages because revenue is the goal. Built on 3.67.2. No migration.
- **Search Console review** (exports of 12 Nov): 456 of 526 sitemap pages are "Discovered - currently not indexed" and Google has not crawled any of them yet (resume 130, resume-examples 108, resume-builder 46, interview-questions 42, guides 34, calculators 31, resume-words 29, cover-letter-examples 21, resume-templates 15). A full crawl of the live site found no technical blocker: every page answers 200, one H1, self canonical, index,follow, valid structured data, no duplicate titles or descriptions. The cause is a new domain with almost no outside links, so Google is slow to spend crawl time; the fix is authority (links), stronger pages and a clear crawl priority.
- **One pricing page** (owner, 12 Nov: "make one page /pricing"): `/pricing/` has a section per plan (`#quick-download`, `#job-hunt`, `#pro`, each with what you get, who it is for and a buy button), a comparison table, `#free-vs-paid`, how paying works and a refund FAQ. Prices, limits and validity come from the live plan settings; a plan taken off sale drops out. Each plan is Product structured data with its INR price and the 7-day return window. `/pricing`, `/pricing.html` and `/plans` move permanently to `/pricing/`, and `/pricing/{plan}/` addresses to their section. The footer and every programmatic page link to `/pricing/` ("See plans from ₹99"). Pricing visits count as pricing views for the customer journeys.
- **"Updated <date>"** (owner: show the current date when a page or post is updated): guides, calculators and all programmatic pages show "Updated 12 Nov 2026" from the last content change (owner edit of a guide, approved SEO change, a person's review, or the page scan noticing new content) and send it as `dateModified`. Saving a guide whose words changed stamps it with today; saving without changes keeps the old date. No date is shown when none is known.
- **30-day index sprint** (Growth > SEO agent, Start/Stop): raises pages scanned per run 40→200, Google URL Inspection 60→600 a day (Google allows 2,000), recrawl requests 10→50 per run, title and link drafts 10→50 a day, and acts on smaller signals (5 impressions). Not raised: Google Indexing API (Google's 200 a day), outreach sends. Ends by itself after 30 days. The settings form still shows and saves the owner's own numbers. New drafts pause while 60 already wait for a decision. Week-by-week plan in `resources/data/seo-sprint.json` shown on the tab.
- **Approval detail**: every SEO approval card has a Where row: the full page address, the section of the page that changes (title tag and meta description, main heading, FAQ box, structured data, or the "Popular with job seekers" link box), every page a link is added on and the link text.
- **Sitemap `<lastmod>`**: each page's last content change from the agent's page scan, so engines re-read changed pages first.
- Test suite `s80_index_sprint`; `s23` updated for the pricing redirect and link.

# CVTara v3.67.2 — Night mode readability

## 3.67.2
Owner report (12 Nov 2026): night mode looked wrong on the customer dashboard. No migration.
- Cards that used `--panel` ("What would you like CVTara to help you with?" tiles, "What brings you to CVTara?", parts of Interview practice, Resume hub and Account) fell back to white with light text. `--panel` now follows the page colours in both modes.
- Every secondary button turned pink in night mode; they now use the normal text colour, and only delete or danger buttons stay red. The dashboard banner's buttons use white text.

# CVTara v3.67.1 — Review editor and What's new

## 3.67.1
Review editor and What's new, on 3.67.0 (PR #55).
Owner report (12 Nov 2026): the console "Edit review" window had no card, and customers had seen no update notes since 1 Nov. No migration.
- **Console pop-ups fixed**: Edit review, Review history and "Confirm it's you" had no styles of their own, so the site-wide rule that makes every `<dialog>` transparent with no padding (for the template windows) left the form floating over the page with a sideways scrollbar. They now get a card, backdrop, phone layout and dark mode (`cvtara-ds.css`).
- **Why customers got no updates**: notes were only ever added by hand in the console, and none had been added since 1 Nov, so the pop-up had nothing new to show (and customers who joined after 1 Nov had never seen one).
- **Release notes ship with the code**: `WhatsNew::shipped()` holds each release's customer-facing notes. After a deploy they are added on the first page load, stamped with that time, so every customer who joined before sees them once. The owner can edit, hide or delete them; a deleted one never comes back (`removed` list in the `whatsNew` setting).
- Shipped notes (alongside the eight 6-12 Nov notes 3.67.0 added to the seed, no duplicate ids): for 3.67.0 (account menu moved, resume journey, one-question-at-a-time mock interviews, shorter Fix for this job), for 12 Nov (interview practice, full mock interview, company research, check before download, tested ATS labels) and catch-up notes for 7-8 Nov (help centre, support replies, Ask Tara).
- Console What's new warns when the newest note is more than 14 days old. The customer pop-up waits up to 3 minutes (was 30 seconds) for another window to close before giving up for that visit.
- Test suite `s40_whats_new` extended.
- **Blog & SEO page error** "e.toLocaleDateString is not a function": the Job, city & state pages list passed the Google check time to the date formatter as text once a page had been checked. Fixed there and in the Interview practice recent sessions list.

## 3.67.0
Owner's feedback of 12 Nov 2026, audited and approved ("approved", then "complete"). Built on 3.65.0 and 3.66.0. Additive migrations `2026_12_01_000001_whats_new_november` (adds the 6-12 Nov notes to a saved What's new list and hides the old phone note) and `2026_12_01_000002_template_letter_domains` (files each built-in cover letter under its real role group). No tables change.
- **Sign-up**: passwords need 8 characters (was 12) on sign-up and reset; 25 job categories (was 9) on sign-up, profile and onboarding, checked on the server.
- **My account menu** (top right): Account, Billing, Refer & earn, Wallet and Referral history live only there; the sidebar group and the duplicate referral links are gone. Keyboard and Escape work.
- **Fix for this job**: a score ring, two steps (keywords to add, what the job asks for) and one action row, in about half the height.
- **Career tools need Job Hunt or Pro**: one-click cover letters, whole-resume tailoring and mock interviews answer 402 for Free and Quick Download (Rs 99) accounts, and an upgrade card with icons opens. Staff are not limited.
- **Resume journey card**: V1 (first version, real score, original file) → V2 (primary now, score and gain) → V3 (one copy per job: tailored copies and frozen application copies, with their cover letter). Scores are the normal strict ATS-friendly score; nothing is adjusted. The oldest version is never pruned.
- **Price question after a save**: Great value / Fair / Too high, at most once in 30 days; the owner sees the latest answer per customer on the Reviews page.
- **Reviews**: only verified reviews show on the home page, with a "Verified customer" badge.
- **View as customer or partner** (owner choice: view only, logged): People and Partners have a View button that asks for a reason and opens a 30-minute read-only view; partner portals are owner-only.
- **Interview room**: one question at a time with an interviewer panel, Previous/Next and progress dots; questions are not repeated across the last 200 sessions (was 5); practice tiles show streak, minutes today and this week, and mock interviews done.
- **Colours**: teal sidebar and hero, coral and indigo accents, faint pattern behind the chat only.
- **What's new**: eight notes for 6-12 Nov; the 26 Oct phone note is hidden.
- **Templates**: Clinical Pharmacist resume and three letters (HR Generalist, Senior Staff Nurse, Assistant Professor). Coverage now counts letters in their real role group and adds a fresher / mid-career / experienced table; every role group and level has at least five resumes and five letters.
- Test suite `s79_customer_journey`; s1, s14, s27, s32, s40, s61 and s65 updated for the 8-character password, verified reviews, the Job Hunt gate, newer What's new notes and the kept first version.

# CVTara v3.66.0 — Interview question bulk upload

## 3.66.0
Owner request (12 Nov 2026): bulk upload and bulk approval for the interview question bank. No migration.
- **Bulk upload** in console Interview practice > Question bank: a CSV or Excel (.xlsx) sheet, one question per row (Role, Question, Guidance, Example, Skill, Difficulty, From, To). Role is a role name or slug, or "general" for every role; blank experience means fresher to 12+. Choose "Keep as drafts" or "Approve straight away". Rows with an unknown role, wrong length, wrong experience range or wording that mentions AI are listed by row number; wording already in the bank is skipped. Up to 2,000 rows per file. A blank sheet with two example rows downloads from the same panel.
- **Approve all drafts (every role)** next to the role filter (the per-role button stays).
- **Load any new starter questions** is always shown and safe to press again: it adds starter questions that are missing on the site and never duplicates.
- Routes `GET admin/interviews/template`, `POST admin/interviews/import` (content.edit); `interviews/approve` takes `occupation: "*"` for every role. Test suite `s78_question_upload`.

# CVTara v3.64.0 — Lower AI costs

## 3.64.0
Owner report (12 Nov 2026): a few chats on claude-opus-5-5 cost about Rs 100. Cause: every request (customer Tara chat, writing, SEO titles) ran on Opus at $4/$20 per million tokens, with no prompt caching, and one chat answer can take up to 5 requests that each re-send the rules (~2,000 tokens), 15 tool definitions (~2,500), the history and every tool result read so far (the customer guide alone is ~4,000). Thinking is billed as output. Additive migration `2026_11_30_000001_ai_cache_tokens` (`ai_usage.cache_read_tokens`, `cache_write_tokens`).
- **Model per kind of work** (config/ai.php, owner boxes in Integrations > AI): customer Tara chat on the chat model (default claude-haiku-5-5, about 40x cheaper than Opus), customer writing and the Business assistant on the writing model (default claude-sonnet-5-5, half the Opus price), SEO titles, chat summaries, job parsing and interview scoring on the quick model (default claude-haiku-5-5). A model the owner types in a box wins; Tara's own model box still wins for the chat.
- **Thinking effort per tier**: chat and quick jobs low, writing medium (Sonnet's own default would be high).
- **Prompt caching**: Tara's and the Business assistant's fixed rules (with the tool list ahead of them) are sent as one cached block; the date, name and conversation summary follow it, so steps 2-5 of an answer and the next message within 5 minutes read it at a tenth of the price or less.
- **Smaller requests**: customer chat keeps the last 10 messages (was 16) and at most 4 steps (was 5); the Business assistant 12 messages and 6 steps (was 20 and 8). Saved console settings are kept. A tool result longer than 6,000 characters is cut (the customer guide is kept whole).
- **Exact spending**: cached input is recorded per request and costed at 1.25x input for a cache write and the model's cache-read price for a read.
- **SEO agent**: title and description drafts run on the quick model at low effort with room to finish (1,500 tokens instead of 400, which thinking could use up and leave an empty answer, so the plain rule was used instead).
- Tara chat shows "CVTara team is typing…" while she works. The separate "CVTara team writing help" pop-up was already retired in 3.51.0 (its actions are Tara's writing_help).
- Test suite `s76_ai_costs`; s27, s28, s47 and s57 expect the new default models.

# CVTara v3.61.0 — Owner decisions of 12 November

## 3.61.0
Owner's confirmed decisions (12 Nov 2026). Additive migrations `2026_11_27_000001_full_mock` (`interview_sessions.mode`, `sections_json`), `2026_11_27_000002_resume_originals` (new table `resume_originals`), `2026_11_27_000003_payment_marks` (new table `payment_marks`). Nothing existing changes.
- **Full mock interview** (V5.8 §5, V5.10 §2, D04): an optional type of about 60 minutes in four 15-minute parts (about you and your motivation, knowledge of the role, your past experience, situations and closing), three questions each, least-seen first, ending with a closing question. Uses one mock interview credit, like a standard one. One part at a time with a guide clock per part; running over is shown, never marked down. Feedback covers every answer plus an average for each part from the rubric scores (no extra request). The owner can stop offering it (Interview practice > Switch and limits). Customer guide updated.
- **Original uploaded resume file kept 90 days** (V7 §11 R1): after a resume is built from an upload, a private copy of the original PDF, Word or text file (up to 5 MB, checked by its first bytes) is kept outside the public folder. Account > Your data lists it with Download and Delete now; it is erased after 90 days (hourly check), with the account, or when a new file replaces it for the same resume. Listed in the data download; privacy policy default updated.
- **Test payment review** (V4.8 §6): Costs & margin lists every order made up to 9 Nov 2026 (India time) with a suggestion from fixed rules (test mode, demo checkout, owner or staff account, ₹10 or less, test-looking email, full refund within 2 days; unfinished checkouts and ₹0 grants apart). The owner marks orders Test or Real (one by one, ticked, or accept the suggestion). Orders marked Test are left out of revenue figures; no order is changed or deleted. Owner only, audited, downloadable.
- **Template import from a Word file** (V5.0 §6): Template quality accepts one .docx with design name, type, role group, licence, source and author. Its section headings and order, main font (closest available font, with a note), heading colour and one or two columns are read; it becomes a draft built on the closest CVTara design, takes the text-reading test and goes live only after approval. Macros, missing headings or missing licence details are refused; the sample text is not stored; the same design saved again is a duplicate.
- Test suites `s75_full_mock`, `s72_resume_originals`, `s73_test_payments`, `s74_docx_templates`.


## 3.60.1
Search engine index status on the console Overview (owner request 2026-11-12). No migration.
- **Search engines box** on the Overview, after Business numbers, for the owner and staff who may view SEO: **Google** pages indexed out of live pages (from the URL Inspection results the SEO agent stores), why the others are not indexed, pages not checked yet, and Search Console impressions and clicks; **Bing** pages in index, pages crawled, crawl errors and last-30-day impressions and clicks from Bing Webmaster Tools; **IndexNow** pages sent, pages waiting and the last send. Refresh button.
- **Bing Webmaster Tools API key** field in Integrations > Analytics (owner only, stored encrypted, never sent back). Bing numbers are kept for six hours; a new key is used at once. Refused keys and unverified sites get a plain explanation.
- New `App\Services\IndexStatus`, routes `GET admin/indexing/engines` (seo.view) and `POST admin/indexing/engines/refresh` (seo.approve), console script `indexing.js`. Test suite `s71_index_status`; the Google stand-in also answers the Bing Webmaster API.


## 3.60.0
Website and search (V7 §31, §34; V5.0 §2, §9; V5.5 §2; V6 SEO §4-§5, §13). Additive migration `2026_11_26_000001_site_search_register`: new table `brand_profiles`, column `seo_keywords.cluster`; loads the keyword backlog and the listings register.
- **For colleges, coaching and partners** page (`/partners.html`): what students get, how a partnership works (form, written agreement, campaign link and seats, reports without students' documents unless they consent), what we do not promise, FAQ, and the application form (sends to the existing partner applications inbox). In the sitemap, footer links and llms.txt.
- **About page** rewritten with the approved V5.5 positioning ("From resume building to interview readiness", the problems, how CVTara helps, our approach), keeping the HR-team and pricing facts. Mock interviews are described as "structured written feedback, prepared automatically". If the owner has already edited About in the console, that text stays until changed there.
- **og:locale en_IN** on every page.
- **Interview hub** gains "Practise in your CVTara account" (role search, timer, mock interviews, company research, revision).
- **Keyword backlog**: the 118 searches in 6 clusters from the V6 SEO pack are loaded into the keyword store, each mapped to the one page that answers it; 39 with no page yet stay unassigned. The SEO Head's weekly report turns them into one planning task per cluster (validate demand first, no thin pages).
- **Directory and brand listings register**: platform, type, public URL, state (not listed, pending, live, needs a fix), claimed, verified, last checked. Seeded with search consoles, the four social profiles and software directories. "Check" opens the public page only; a live listing that stops opening is marked as needing a fix. Paged, filtered and downloadable.
- Growth page gains a **Listings & keywords** tab; the SEO reports show the backlog by cluster.
- Test suite `s70_site_search`; `s20` follows the new About headings.


## 3.59.0
Template library (V7 §15, V5.0 §5-§7). Additive migration `2026_11_25_000001_template_library`: new tables `template_library`, `template_versions`, `template_packages`; every built-in resume and cover-letter design is listed as a CVTara original.
- **Metadata for every design**: licence, source, author, version, preview, export formats, role group, status.
- **Text-reading test**: each design is exported to PDF and Word with clearly labelled demo data, and the text is read back (new `PdfText` reader for the site's own PDFs; Word text from the file itself). It checks every word, section order, dates next to the employer and special characters (? – [ ] ( ) & " ' ₹ →). The scheduler tests a few designs a minute; the console can test on demand.
- **ATS label rule**: a design shows the ATS label only when it is a text-first, single-column layout and passed the reading test. Two-column designs never show it. Until the scheduler has tested a design (about 30 minutes after deploy), its ATS label is hidden.
- **Package import**: upload a ZIP with `manifest.json` and template JSON files (V5.0 §6 contract). Checked: allowed file types only, no path tricks, no scripts, event handlers, frames, remote files or unsafe CSS, no Word macros, valid images, manifest fields, checksum (SHA-256 of the template files in path order), template count, required template fields, licence/source/author, colour and font, section names, export formats, and a CVTara design to build on. Designs arrive as drafts, are tested, and go live only when approved. Duplicates are skipped; a changed design becomes a new version; every version is kept and can be rolled back. Documents customers already made keep working while a new version waits.
- **Coverage report**: designs per role group against five resumes and five cover letters, with gaps. Downloadable.
- New console page **Template quality** (Content & pages). Imported designs reach the public gallery through the live site data.
- Test suite `s69_template_library`.


## 3.58.0
Stronger agents (V7 §29, §37; Growth plan §11.2, §12.1-§12.2). Additive migration `2026_11_24_000001_agent_cadence`: five columns on `agent_runs` (`run_uid`, `prompt_version`, `model`, `sources_json`, `knowledge_version`) and new tables `agent_reports`, `agent_corrections`, `config_changes`.
- **Daily, weekly and monthly reports** for the SEO Head and the Growth & Revenue agent, written once per period after 7 AM India time. SEO: pages that do not open, important pages Google has not indexed, changes waiting, searches close to page 1, searches shown but rarely clicked, section coverage, organic sign-ups. Growth: registrations, verified, resumes started, payments ok and failed, paid and new customers, revenue and order value, abandoned checkouts, the month's pace and band, email and outreach, experiments, sign-ups by source, forecast, refund rate, referral purchases, contribution margin, partner sign-ups, interview use and plan mix. Numbers come from the site's records; anything not measured is shown as Not tracked. No model is called.
- **Corrective actions**: when a target is missed (orders behind pace, refund rate over 5%, a page that does not open, searches shown but not clicked) the agent writes target, actual, variance, evidence, root cause, corrective action, owner, deadline, expected result and review date, and opens one task in Approvals. Nothing on the site changes without approval. Paged, filtered and downloadable (CSV/Excel).
- **Run evidence**: every agent run keeps a run id, rules version, model (none for rule-based work), the sources it read and the knowledge version it worked from.
- **Change feed**: every saved change to plans, public wording, referral and refund rules, offers, partner programme, interview settings, cost limits, legal pages, emails, growth targets and agent rules is recorded. The console shows each agent's knowledge version and flags an agent that is behind.
- Growth page gains an **Agent reports** tab (reports, corrective actions, what the agents know, recent runs, a "Write this report now" button).
- Test suite `s68_agent_cadence`.


## 3.57.0
Interview preparation (V7 §16-§20, §31; V5.6-V5.7 §5). Additive migration `2026_11_23_000001_interview_vertical`: three columns on `career_occupations` (`domain`, `isco_code`, `seniority`), `interview_answers.seconds`, and a new table `company_research`. It also loads 12 more non-technical roles (inside sales/telecaller, receptionist, data entry, insurance advisor, collections, store manager, warehouse supervisor, content writer, travel consultant, back office, pharmacy assistant, event coordinator) with 24 original role questions as drafts (nothing is shown before the owner approves them).
- **India role taxonomy**: every role has an industry, business domain, job family, NCO/ISCO-08 code (4-digit unit group; check against the official NCO 2015 list before quoting it publicly), a seniority ladder and common Indian designations as synonyms. Customers find their role by typing their job title ("telecaller" finds Inside Sales Executive). Existing owner edits are kept.
- **Research a company**: the customer pastes the company's own website; one public page is read (robots.txt respected, no logins, no bypassing, private addresses refused), a short summary is kept with its source and time, and six original practice questions are made from templates (motivation, product, customer, stakeholder, ethics, first 90 days), labelled "CVTara practice questions based on publicly available company information; not confirmed questions from the employer." No AI. Five a day per customer; erased with the account.
- **Timer**: optional; shows the time on each answer (aim for about 2 minutes) and saves it with the answer. Nothing is penalised.
- **Revision mode**: questions from the customer's finished mock interviews with their earlier answer and what to improve, to write a better one. Free, not scored, counts as a practice day.
- **Badges renamed to V7**: First Practice, 5 Sessions, Clear Communication, STAR Practice, Consistency Milestone (derived from the customer's own practice; no rankings).
- **Public interview pages** (one per role) gain "Practise your answers" linking to practice in the account; the full bank stays inside the account. Dashboard gains a "Practise interviews" button. Customer guide updated.
- Test suite `s67_interview_vertical`; `s58` now expects 24 roles; `s59` uses the new badge names.

# CVTara v3.56.0 — Evidence check, job confirmation, costs and margin

## 3.56.0
Trust and money controls (V7 §12-§14, §24-§26, AT-10, AT-11). Additive migration `2026_11_22_000001_costs_evidence`: new tables `evidence_confirmations` and `ai_invoices`. Nothing existing changes.
- **Evidence check before a clean download**: a job version of a resume, or a cover letter made from a resume, is compared with the customer's own resume. Numbers, years, tools and names that are not in it (the employer and role typed for that version are allowed) are listed in the Download window; the customer removes them or presses "These are true, download". The confirmation is kept per version; a new version with new facts asks again. No credit is used until then; watermarked previews and the customer's own resumes are never held up. Plain comparison, no AI.
- **"Is this the right job?"**: ATS Match first reads the posting (link, file or text) into job fields: title, company, location, type, level, experience, education, skills asked for, good-to-have skills, and pay only when the posting states it. No scan is used until the customer confirms (title and company can be corrected). The confirmed fields and time are stored with the check. A link is opened once; the check reuses that reading.
- **Usage ledger mirror**: every clean download and ATS scan also writes a row to the append-only usage ledger against its order. Today's counters are unchanged.
- **Costs & margin** (new console page, Money & plans): AI spend this month by feature against its cap, today's highest-spending customers, caps (per customer per day, per feature per month, per order as a share of its price) with alerts in the Error Log. Paid features are never stopped by a cap; only Tara chat and writing help pause for a customer who reached the daily cap (default ₹30). Invoice check: enter the provider's monthly bill and see the difference from our cost log. Contribution margin per plan and add-on: net collected minus GST share (set to 0 if not registered), gateway fees (default 2.36%), the buyers' AI cost while each order was active, and fulfilment and support cost per order the owner enters. Download as CSV or Excel (Reports > Contribution margin).
- Test suite `s66_costs_evidence` (37 checks).

# CVTara v3.55.0 — Review edit history and feedback after save

## 3.55.0
Reviews and feedback (V7 §28, AT-21, R13; V5.1 guide fix). Additive migration `2026_11_21_000001_review_edits`: four nullable columns on `feedback` (`original_content`, `usage_ref`, `edit_state`, `edited_at`) and a new table `feedback_edits`. Nothing existing changes.
- **Edit history**: in console > User Reviews each review has Edit and History. A spelling or formatting fix, or replacing personal details with [removed], applies at once; a "spelling fix" that changes the words, adds a negation or adds words is refused. The customer's original words, the editor, reason and before/after text are kept for every change.
- **Wording changes need the customer**: any other change is sent to the customer (notification, and in Share feedback they choose "Approve this wording" or "Keep my words"). The review is off the website until they answer and cannot be published while waiting. Sample and owner-added reviews cannot get such a change. Ratings and names are never edited.
- **What was used** is stored with each new review (latest paid order, else latest saved resume). Published reviews carry an "edited" flag.
- **Feedback after a resume save**: a small card after a successful save (never while typing), at most once every 14 days, and never once feedback was sent. "Not now" is respected.
- **Customer guide**: the ATS-friendly score is explained as a guide, not a promise; new question "Does CVTara guarantee a 70% score or a job?" (No.)
- Account deletion also removes the review edit history.
- Test suite `s65_review_edits` (34 checks).

# CVTara v3.54.1 — V7 PartnerHub part 2

## 3.54.1
PartnerHub part 2 (PartnerHub V2 §4-§19). Additive migration `2026_11_20_000002_partner_hub_campaigns`: four nullable columns on `partner_links` (`starts_on`, `ends_on`, `seats`, `target_paid`) and new tables `partner_agreements`, `partner_invites`, `partner_payouts`, `partner_commission_reversals`, `partner_rankings`, `partner_rewards`. Nothing existing changes.
- **Agreement versions**: the owner sends a draft filled from the partner's terms (or edited text); the partner's manager reads it in the portal and accepts with a tick and a typed name. The exact text fingerprint, time, IP and method are kept. A campaign link takes no sign-ups until an agreement is accepted (or the owner recorded a signed paper agreement). A new version waits for fresh acceptance while the accepted one stays in force.
- **Campaigns**: start date, 15/30/45/60/90 days, seats and a paid-customer target per link; pause, resume, extend or close. When the seats are used (verified sign-ups, or all sign-ups while email verification is off), the link stops. The portal shows seats, invited, signed up, paid and refunded.
- **Student invites**: the manager uploads a sheet (Excel or CSV) or pastes name, email lines after confirming they may share them. Rows are checked and de-duplicated, people who already have an account are not counted, and each student gets a personal link. No account is created from the list; a student who signs up with their link is marked registered. Seat limits apply.
- **Attribution**: the first valid link wins (customer referral or partner), switchable to last link by the owner. V7 sources: Influencer and Direct B2B (CVTara sales desk) are B2B; a creator's customer referral link stays B2C Referral.
- **Refunds** now add a reversal entry instead of only changing the status, so the ledger keeps the original amount.
- **Payouts**: the manager asks for the amount ready to pay (minimum ₹5,000, one request at a time); the owner marks it paid with a reference or rejects it.
- **Monthly ranking** (paid customers 40%, revenue 40%, sign-ups 20%) on the 1st of each month; a partner sees only its own place, never other partners' names.
- **Rewards**: the owner adds a gift, the manager confirms the delivery address, the owner records dispatch with courier and tracking.
- **View as partner**: the owner can open any partner's portal read-only; each view is logged.
- Test suite `s64_partnerhub_campaigns` (50 checks).

# CVTara v3.54.0 — V7 PartnerHub (B2B)

## 3.54.0
PartnerHub, the B2B vertical (V7 §38-39). Additive migration `2026_11_20_000001_partner_hub`: new tables `partners`, `partner_links`, `partner_members`, `partner_commissions`, `partner_applications`. Nothing existing changes.
- **Partners and agreements** (console > B2B Partners): college, placement cell (TPO), coaching institute, company, influencer/creator or other; contact, city, agreement reference and dates, commission rates (default 30% plans, 40% add-ons). Active, paused or ended.
- **Campaign links** `/p/{code}` (not indexed): a sign-up within 30 days of the click is locked to the partner (B2B college/coaching/corporate/partner, or B2C influencer for creators), with link and campaign. The later of a customer referral link and a partner link wins, so a sign-up never earns both. Links stop when paused or after the agreement ends. Visits counted once per visitor.
- **Commission ledger**, separate from customer referrals: 30% of plans, 40% of add-ons (Expert Review, Profile Boost, credit packs), nothing on the ₹99 Quick Download. Live gateway payments only. Pending for the 7-day refund window, then ready to pay (daily cron); any refund or chargeback cancels it (restored if the chargeback is won). Owner marks it paid with a payout reference (owner only). Downloadable.
- **Partner portal** (workspace > Partner portal, only for members the owner adds): sign-ups, buyers, revenue, commission, results per link and by month. Numbers only, never a student's details. A person can belong to one partner.
- **Partner applications**: public endpoint `/api/partners/apply` (form arrives with the website pages in 3.59), spam-trap field, 3 per hour per visitor, owner notified; approve creates the partner as paused.
- New permission `partners.manage` (Admin role). Users list filter by partner. Account deletion removes portal membership and keeps the money records on the placeholder.
- Test suite `s63_partnerhub` (44 checks).

# CVTara v3.53.0 — V7 signup attribution

## 3.53.0
Where each account came from, decided once at sign-up (V7 WS-02). Additive migration `2026_11_19_000001_signup_attribution`: nullable columns on `users` (`acq_class`, `acq_subtype`, `acq_partner_id`, `acq_campaign`, `acq_link`, `acq_rule_version`, `acq_first_seen_at`, `acq_locked_at`).
- **Locked at sign-up**: B2C or B2B, and a source (Direct, Referral, Influencer for an approved creator's link; College, Coaching, Corporate or Other partner from a partner campaign link, which arrives with PartnerHub). Later visits, clicks and links never change it. Rule version `v7-1` and the time are stored.
- **Older accounts are filled in** from who referred them (rule version `backfill-1`), so every account has a value.
- **Users list**: new "Came as" column and filters (B2C/B2B, source); the Users download and the Users report carry "Came as" and the sign-up campaign.
- Test suite `s62_signup_attribution`.


## 3.52.0
Resume version history and document lineage (V7 WS-05). Additive migration `2026_11_18_000001_resume_versions`: new table `resume_versions`, `applications.resume_version_id`, and three nullable columns on `cover_letter_drafts` (`source_resume_id`, `source_scan_id`, `source_application_id`). Nothing existing is changed or dropped.
- **Every save keeps the previous content** as a version (the last 30 edits per resume). A save that conflicts with another tab is refused as before and keeps nothing.
- **The resume sent with an application is frozen**: when a job in Applications is marked Applied, Interview or Offer with a resume chosen, that exact copy is kept and linked to the application; later edits never change it. Choosing a different resume freezes that one.
- **History on each resume card** (My Resumes): list of versions with date and why it was kept, View, and "Put this version back". A restore first keeps what is on screen, so it can be undone.
- **Cover letters remember what they were made from**: the resume, ATS check and application (only the customer's own ids are stored), from the cover-letter page and from the combined resume + letter service.
- Read-only support view can see the history; account deletion removes it. Test suite `s61_resume_versions` (27 checks).


## 3.51.0
One assistant, and Profile Boost as a draft the customer approves (V7 WS-07 and WS-13). No database change (Profile Boost reuses `service_orders`).
- **Writing help is part of Tara chat.** New Tara action `writing_help` (clearer wording, achievement bullets, summary, tailoring, cover-letter draft, check against a job, interview questions) from the customer's own text. Same daily writing allowance as before; nothing saved by itself. The separate "CVTara team" writing pop-up is no longer loaded; `tara-help.js` stays as a tiny file that opens Tara chat for any cached page. `/api/ai/suggest` is unchanged. The owner can turn the action off in Tara settings; an action list saved before 3.51 gets it switched on once.
- **Profile Boost draft.** After payment, a LinkedIn and Naukri profile draft (headlines, About, summary, key skills, experience highlights) is written from the customer's saved resume, using only their facts. The customer reads it in Account & downloads and either approves it (order delivered) or asks for one round of changes in their own words; the second draft is final. A notification tells them when a draft is ready. Failures retry up to 3 times, then go to the Error Log for the owner. The owner can still edit or finish any order by hand in Refunds & services, and can switch automatic drafts off there (orders then wait for the owner as before).
- Wording: Profile Boost perks say "A draft you approve, with one round of changes"; the account page no longer promises a person within 48 working hours. Customer guide updated. No mention of AI, and no claim that a person wrote the draft.
- Tests: `s60_v7_step3` (32 checks); s27, s28 and s41 updated.


## 3.50.0
Practice progress on the Interview practice page (V7 WS-11). No database change: everything is worked out from the customer's own mock interviews and practice visits (one `interview_practice` activity event per role per day).
- **Levels**: Beginner, Developing, Confident, Interview ready, from the latest mock interview, with "up from last time" when it changes. Always labelled as a practice guide, not a prediction.
- **Streak with rest days**: a practice day is a day the customer opens practice questions or finishes a mock interview; one rest day between practice days keeps the streak. Current and best streak are shown.
- **Six badges**: First step, Explorer (3 roles), Real examples (full marks for evidence), Steady practice (5-day streak), Well prepared (3 mock interviews), Interview ready.
- Private to each customer: no rankings or leaderboards. Test suite `s59_practice_progress`.


## 3.49.0
Second V7 release: interview practice. New tables only (additive migration `2026_11_17_000001_interview_practice`: career_occupations, interview_questions, interview_question_versions, interview_sessions, interview_answers, interview_evaluations, usage_ledger) plus `2026_11_17_000002_interview_bank_seed`, which loads the starter bank as drafts. **Off until the owner approves questions and switches it on** (console > Interview practice).
- **Question practice** (workspace > Interview practice): pick a role and experience to see approved questions with what a good answer covers. Free and Quick Download see 3 per role; plans with mock interviews see all. Stored content only.
- **Mock interviews**: a locked set of 5 approved questions (an introduction, about two for the role, the rest general; least-recently-seen first), written answers saved as typed, then written feedback against a fixed rubric (relevance, structure, evidence, communication, role fit, each 1-4), strengths, improvements, a rewrite using only the customer's own facts, a next step and a level (Beginner, Developing, Confident, Interview ready; a practice guide, never a hiring prediction). Runs on the quick-task model when set. Customers can challenge feedback once; it opens a support ticket.
- **Allowance** (owner decision 2026-11-11): Job Hunt 4 for 30 days, Pro and Complete Job Kit 12 for 90 days, one pool, no monthly reset (`mockInterviews` on the plan, editable in Pricing, frozen on each new order). Orders bought before 3.49.0 keep what they bought (no mock interviews); the owner can give complimentary ones with a reason.
- **Credit ledger** (`usage_ledger`, append-only, idempotent): a credit is held at the start, used when feedback is ready, given back after 3 failed attempts (also written to the Error Log) or when a session is not submitted within a day. A held or used mock interview blocks the 7-day "unused" refund; a returned one does not.
- **Owner console > Interview practice**: approve questions one by one or a whole role, edit (new version, back to draft, older sessions keep their wording), retire, on/off switch, questions per session, daily start limit, recent sessions with errors and challenges, complimentary credits.
- Plan cards show "N mock interviews with written feedback" only while mock interviews are on. Customer guide chapter 10 added. Account deletion removes sessions, answers and feedback. Read-only support view can see the Interview practice page.
- Starter bank: 12 non-technical roles (NCO 2015 codes to be verified), 90 original questions written for CVTara (18 general, 6 per role).


## 3.48.0
First V7 release (owner approved "all the updates as per the document step by step", 2026-11-11). Two small additive migrations: `ai_usage.prompt_version`, and a one-time update of the saved Expert Review tagline if it is still the old wording.
- **Tara Expert Review wording is honest.** The review is written automatically, so the tagline, checkout line, delivery email, order note and customer guide now say "instant written review … prepared automatically" instead of "the CVTara team reviews your resume". The review itself no longer writes as a person. Still no mention of AI on the website (owner rule).
- **Quick-task model.** Integrations > AI has a second box, "Quick-task model", for short routine jobs (SEO titles, chat summaries, and interview scoring and job parsing once built). Empty = the main model. Customer writing (reviews, tailoring, cover letters, chat answers) always uses the main model. Haiku requests are sent without the refusal fallback setting, which Haiku does not support. Emptying either model box now really goes back to the default.
- **Cost log.** Each AI request is costed at the list price of the model that answered (Opus 5.5, Sonnet 5.5, Haiku 5.5 in `config/ai.php`; other models use the Growth page prices) and records a short fingerprint of the prompt wording (`prompt_version`).
- Tara chat summaries are counted separately as `customer:chat_summary`.


## 3.47.0
Fixes from the owner's 8 November list. No database change.
- Customer menu: one help entry (Help centre opens the customer guide; the extra "Customer guide" link is no longer added) and no separate "Ask Tara" item; Career assistance and the floating button open the chat.
- "On this page" lists on guides and calculators no longer open the home page: in-page links keep the visitor on the page despite `<base href="/">`, and article tables of contents carry the article's own address.
- User Mailer: Save templates no longer fails with "The selected trigger is invalid"; every trigger a built-in template uses is accepted (activation_nudge, ats_nudge, pricing_help, support_reply were missing).
- Blog & SEO > Job, city & state pages: the list was empty ("No pages match") after visiting the Resume Library, whose grid used the same element ids. The list now has its own ids, 20 rows per page, a link to each live page, Google's index status (URL Inspection) with the date checked, 4-week clicks from Search Console, a filter by Google status, CSV export and "Ask Google about more pages" for the pages on screen.
- Integrations > AI: new "Test the key" button asks Anthropic whether the saved key and model work and says why not (invalid key, model not available, no credit, busy). Business assistant and Tara errors point to it.

## 3.46.1
- SEO pages: the trust line now says "Made by an HR team in Bangalore" instead of "Built by working recruiters" (owner decision 2026-11-08), matching the footer and emails. No database change.

## 3.46.0
Eleventh V2 release. No database change.
- **Customer guide rewritten in 16 chapters** (registration, dashboard, resume creation, upload, improvement, ATS matching, cover letters, the combined resume and cover-letter service, applications, Ask Tara, CVTara team services, plans and billing, downloads, referrals, account, help and support). The package grid now shows the packages really on sale (the old one filtered on fresher / experienced and showed nothing); outdated plan names, scan counts and the "No-Refund" footer link are gone. No mention of AI.
- **Owner guide rewritten in 21 chapters** for the V2 console (login and security, dashboard, Command Centre, customers, orders and payments with reconciliation, libraries, templates, offers, referrals, communications, reports, support, assisted customer access, AI assistants, SEO Control Centre, Workforce Management, growth, Approval Centre, integrations, security and backups, troubleshooting).
- Both guides: search, a last-reviewed date, a guide version and version history, and a slot for a short video in every chapter (videos are an owner content task).
- **"?" help button** in the top bar of the owner console and the customer workspace opens the guide chapter for the page on screen.
- Tara's product guide reads the new customer guide (without the video slots and history).
- Test suite `s55_guides` (32 checks), plus a browser check (desktop and phone).

## 3.45.0
Tenth V2 release. **Database change:** run `php artisan migrate --force` (new table `seo_content_versions`; new columns `seo_proposals.created_by`, `seo_proposals.task_id`, `seo_pages.reviewed_at`, `seo_pages.reviewed_by`). Nothing existing changes.
- **More kinds of SEO change to approve:** besides titles, descriptions and internal links, a new main heading (H1), extra FAQ questions (also added to the FAQ structured data), structured data (Article, or SoftwareApplication for the free tools only, built from the page's own heading and description, author "CVTara team") and content briefs. Only the ready-made SEO pages can be changed.
- **Drafting:** SEO Managers, Admins and the owner draft from a page's record (Propose a change). Drafts are cleaned of markup and refused if they mention AI, other brands or promises. A different person with SEO approve rights decides in Approvals & tasks (the owner excepted); the review shows the before and after in plain lists.
- **Content versions:** every change that goes live is kept with its before and after, who applied it and when, and can be undone in one click. About a minute after it goes live (or is undone) the live page is fetched and checked; the result (Seen live / Not live) shows on the page record, and on the SEO task's evidence when the draft was linked to a task.
- **Content briefs:** the SEO agent drafts up to two a day for topics missing from the topic groups, each once. An approved brief becomes an approved writing task; it changes no page.
- **Editorial review line** on SEO pages ("Written by the CVTara team"), and "Reviewed by the CVTara team on <date>" (also on guides) only after a person presses "I reviewed this page today". No invented authors or dates.
- Test suite `s54_seo_changes` (50 checks), plus a browser check (desktop and phone).

## 3.44.0
Ninth V2 release. **Database change:** run `php artisan migrate --force` (new table `seo_page_audits`; new columns on `seo_pages`). Nothing existing changes.
- **Page record** (Growth → SEO → Pages → Record): what each page is for (page type, primary and secondary keywords, search intent, content status, who looks after it, a note) and what the scan read from it (H2/H3 outline, images and ALT text coverage, links in, main call to action). Type and keyword are filled in by rule and from Search Console until a person saves them; the agent never overwrites a saved record. Saving needs SEO draft rights and is in the audit log.
- **Checklist audit (24 checks, spec 10.5)** after every scan and once a day for all pages: HTTP status, redirects, HTTPS, canonical, robots.txt, noindex, sitemap, crawlability, title, description, H1, outline, duplicates, internal links, links in (orphans), broken links, structured data, breadcrumbs, mobile viewport, image ALT text, content readable without JavaScript, hreflang, pagination and Core Web Vitals. Each page gets a score (0-100) and Good / Needs work / Poor; a failed indexing check always means Poor. Checks that do not apply are left out of the score.
- **Audit history:** a dated row whenever the result changes (and at least weekly), plus every check a person starts ("Scan and audit this page now"). The page list can be filtered by audit result and sorted by lowest score.
- **Site lists:** orphan pages, pages linked from the site but missing from the sitemap, sitemap pages marked noindex, and broken pages with the pages that link to them.
- **Core Web Vitals** only from the Chrome UX Report (real Chrome visitors on phones), for the important pages, weekly. Add the optional key in Integrations → Analytics; without it, or without enough visitors, they show "not tracked", never a number. The key is stored encrypted and never shown back.
- Scans and the daily audit wait while the site is in busy mode; a one-page check is limited to 10 a minute, the full audit and the vitals check to once every 5 minutes.
- Test suite `s53_seo_audit` (48 checks), plus a browser check (desktop and phone).

## 3.43.0
Eighth V2 release. **Database change:** run `php artisan migrate --force` (new table `payment_reconciliation_records`). Nothing existing changes.
- **One set of business numbers (`App\Services\Metrics`):** registrations, verified sign-ups, paying customers, first-time buyers, paid orders, gross collections, refunds, net revenue, payment success rate, refund requests, resumes built, ATS scans, cover letters, organic and marketing visitors, support backlog, AI service success, approvals waiting, open and overdue tasks. Each has a written definition next to the code that counts it; `docs/METRICS.md` is generated from the same list (`php artisan cvtara:metrics-doc`) and a test checks they match. Money and customer counts use the same function as the reports and the Business assistant, so a period gives the same numbers everywhere.
- **Overview → Business numbers:** today, 7, 30 or 90 days, the time the numbers were worked out, and "not tracked" (never 0) when something is not measured, such as visitors while site analytics is off. Each tile says how it is counted.
- **Owner alerts** on the Overview and, once a day each, in the Error Log: many failed payments, AI service failing, approvals waiting more than 48 hours, overdue tasks, payment mismatches, and campaign spend with no paying customer in 30 days.
- **Daily payment reconciliation** (after 6 AM India time, for the day before): captured Razorpay payments are compared with CVTara's orders (paid at Razorpay but not recorded, recorded but not captured, amounts differ) and PhonePe orders still open are checked with PhonePe. Each mismatch is stored once and written to the Error Log; the owner marks it checked with a note (Overview → Payment reconciliation). Nothing is charged, refunded or granted by it. Finance can see it; only the owner can run it or mark items checked.
- The cron's newer jobs (3.41-3.43) now each run on their own, so one failure never stops the others.
- Test suite `s52_metrics` (39 checks), plus a browser check.

## 3.42.0
Seventh V2 release. **Database change:** run `php artisan migrate --force` (new tables `approval_events`, `ai_tasks`, `ai_task_events`, `ai_task_evidence`; new column `agent_runs.task_id`). Nothing existing changes.
- **Approvals & tasks** (console menu, with a count of what is waiting): one list of everything waiting for a decision, from SEO changes, refunds, Business assistant actions and agent / team tasks. Each item shows its ID, who submitted it, the agent, the action, why, cost, impact, risk, the proposed change (before and after) and the evidence. Items waiting more than 48 hours are flagged.
  - Each kind is decided by its own existing code, so every rule still applies (an SEO change goes live through the SEO agent, a refund through the refund checks, an assistant action through its confirm step).
  - Permission per kind in `config/approvals.php`: SEO changes and SEO tasks need SEO approve, refunds need refund rights (with a fresh sign-in check), Growth tasks need Growth approve, assistant actions and team tasks are owner only. Staff see only what they may decide. Rejecting needs a reason. Every decision is stored (Decided tab) and in the audit log.
- **Tasks** with a real lifecycle: Draft, Submitted, Under review, Approved, In progress, Completed, Verified, plus Rejected, Blocked, Overdue, Failed, Cancelled. Rules are in the server: nobody approves or verifies a task they created; Verified needs at least one piece of evidence (a link, a number or a note) and a reviewer other than the person who did the work; Blocked, Failed and Cancelled need a reason; every move is kept in the task's history. A task can only be given to someone who works in that area.
- **Agents create tasks:** the Growth agent's daily high and medium findings, and the SEO agent's site findings (duplicate titles, pages nothing links to, changes waiting) become submitted tasks, one open task per finding. The cron marks approved or in-progress tasks past their due date as Overdue (hourly).
- Test suite `s51_approvals` (52 checks), plus a browser check.

## 3.41.0
Sixth V2 release. **Database change:** run `php artisan migrate --force` (new tables `service_requests`, `service_request_events`; new column `profiles.career_goal`). Nothing existing changes.
- **"What would you like CVTara to help you with?"** on the customer dashboard: create a resume, improve my resume (upload), create a cover letter, resume + cover letter for a job, check ATS compatibility, get career assistance (Tara, shown only when Tara is on) and track applications. Each opens the existing tool.
- **Resume + cover letter for one job:** the customer picks a saved resume and a job (an earlier ATS check, or a pasted job that goes through the normal ATS check and its scan allowance). The CVTara team tailors the resume and writes the letter, then the server checks that both agree (name, email, company, role, no figure that is not in the resume), checks formatting and the ATS match, and shows a preview.
  - **Real progress tracker:** Information received, Resume generation, Cover-letter generation, Quality checks, ATS checks, Preview ready, Payment pending, Payment confirmed, Download available. Each step is a stored event with its time; nothing is animated. One step runs per call while the page is open, and the cron finishes a request the customer left (one per minute, after 90 seconds without activity). A lock stops a step running twice.
  - **Payment:** a free account sees the order summary (what is delivered, the plans that include resumes and letters, price, validity, one-time payment with no automatic renewal) and pays through the normal checkout. Nothing is saved before payment. Once a payment is verified, or straight away on a plan that covers it, the customer saves both: a new job version of the resume (or, by choice, the tailored text into the resume they started from) and the cover letter, through the normal save rules. Downloads then use the plan's credits as usual.
  - **Failures:** a failed step is stored with a plain message, the writing suggestion it used is given back, and the customer can try again (up to 6 runs). At most 5 requests a day, one running at a time.
- **Grouped customer menu:** My career workspace, Create & improve (with Career assistance when Tara is on), My account, Help & support (Support requests, Help centre, Share feedback). No page was duplicated.
- **Downloads** in Account: every clean download with its document and version.
- **Career goal** asked once on the dashboard (first job, new job, career change, growth, abroad, exploring; optional target role), saved to the profile.
- **New dashboard next steps:** a failed payment (retry or contact support), a plan that ended in the last 30 days (renewal options), a fresh application without a follow-up date (track it).
- Account deletion now also removes ticket conversations (3.40.0) and these requests; staff view logs stay, detached from the person. The data export includes support requests and these requests.
- Test suite `s50_service` (54 checks), plus a browser check (desktop and phone).

## 3.40.0
Fifth V2 release. **Database change:** run `php artisan migrate --force` (new columns on `support_reports`: `reference`, `priority`, `channel`, `assigned_to`, `first_response_at`, `last_customer_at`; new tables `support_ticket_messages`, `support_sessions`, `support_session_events`). Existing owner notes become each ticket's first internal note; nothing existing is removed.
- **Ticket conversations:** every request gets a reference (S-XXXXXX). Staff reply to the customer (sent by email as "CVTara team", template `support-reply`, editable under Emails) or add internal notes the customer never sees. First response time is recorded. Priority (low, normal, high, urgent) and "Handled by" (only people who can answer tickets). Owner-only delete.
- **Staff open tickets** for a customer who phoned or wrote in (channel "staff").
- **Support profile:** plan, orders, document counts, recent activity, errors and tickets for one customer, without document text, passwords or payout details.
- **Customers see their requests:** "My requests" in the Feedback panel shows each request with the team's replies and a reply box (20 replies an hour). The reply email links straight to it.
- **Read-only customer view:** Support Managers and admins (new permission `customers.view_as`) can open a customer's workspace from a ticket, with a written reason and a fresh sign-in check, for 30 minutes. The server answers only listed reads (profile, resumes, letters, applications, scans, purchases, notifications); every change, download, payout, wallet, referral and security page is refused. Every request is logged; the ticket gets a note and the audit log an entry. The view ends on Exit, after 30 minutes, when the owner ends it (Support Inbox > Customer view log), or at once when the staff member loses the permission. A banner shows whose account is open and the time left.
- Test suite `s49_support` (54 checks), plus a browser check of the customer panel and the view banner.


## 3.39.0
Fourth V2 release. No database change.
- **Grouped side menu:** Overview and Business assistant stay at the top. Everything else sits in five groups (Customers & orders, Content & pages, Growth & marketing, Money & plans, System) that open and close. The choice is remembered in this browser. The page you are on is never hidden inside a closed group. Groups a staff role cannot use are hidden. The menu is regrouped in the browser from the existing buttons, so every page and link works as before.
- **Ctrl+K (⌘K on Mac) or the Search button:** go to any menu or Settings page, and search customers (name, email), orders (number, payment reference, email) and support requests.
  - New read-only endpoint `GET /api/admin/find?q=`. It returns at most 5 of each kind, and only the kinds the person's role may see. A Content Editor gets no customer data, and a Finance Manager gets orders only.
  - Choosing a customer opens Users filtered to them. Choosing an order opens its details, and choosing a support request opens the Support Inbox filtered to that person.
  - The owner can hand a question to the Business assistant: it is typed into the assistant's box, never sent by itself, so any change still waits for Confirm.
  - Recent pages and ☆ favourites are kept in this browser.
- Test suite `s48_command`, plus a browser check (owner, all staff roles, phone width).


## 3.38.0
Third V2 release. **Database change:** run `php artisan migrate --force` (new columns on `ai_usage`: `status`, `latency_ms`, `request_id`, `error`; nothing existing changes).
- **One path to Claude (`App\Services\AiGateway`):** writing help, customer Tara, the Business assistant, Expert Review / Profile Boost, ATS tailoring, cover letters and the SEO agent all go through it. Nothing else creates a Claude client (a test checks this).
- **Real time limits:** the Claude SDK does not enforce its `timeout` option, so until now a stuck AI request could hold a PHP worker until the server killed it. The gateway gives every request an HTTP client with a hard time limit (per assistant in `config/ai.php`; writing help 50 s, chats 60 s, ATS tailoring 150 s, SEO 40 s).
- **Model in one place:** the owner's model setting, else `config/ai.php` (`AI_DEFAULT_MODEL`). The model name no longer sits in the code.
- **Every request recorded:** successful or not, with outcome (ok, declined, empty, busy, could not be reached, key refused, error), time taken and the request reference. Requests that never got an answer cost nothing.
- **AI health (Setup & health):** last 24 hours overall and per assistant: requests, success rate, time within which 95% answered, last problem. A new health check "AI service working". Once an hour the cron writes one Error Log warning (repeats count up) when fewer than 80% of at least 20 requests succeeded; a refused key is reported at once. Customers keep seeing plain "try again" messages.
- Test suite `s47_ai_gateway` (fake Claude returning 500, 429, 401, empty and a slow answer).


## 3.37.0
Second V2 release. **Database change:** run `php artisan migrate --force` (new tables `user_roles`, `audit_logs`; new columns `users.totp_secret`, `totp_enabled_at`, `totp_last_step`). Nothing existing is changed.
- **Staff roles (Owner console > Settings > Team & security):** the owner adds a person by email with one of seven roles: Admin, Support Manager, Support Agent, SEO Manager, Growth Manager, Content Editor, Finance Manager. A new person gets an email link to set their own password. Their open sessions end when the role changes, so it applies at once.
  - Every `/api/admin/...` call is checked on the server against `config/permissions.php` **before** any controller runs. A path not listed there is owner only, so a new console page is never open to staff by accident.
  - Owner only, whatever the role: keys and providers, prices and plans, business switches, setup, roles, the business assistant, deletes and blocks, agent levels and costs, clearing the Error Log and the audit log.
  - Staff cannot change the owner's or another staff member's account.
  - Staff sign in at the private console address only; `/login` refuses console accounts. The console menu hides the parts a role cannot use.
  - Emergency switch: `ROLES_ENFORCE=off` in `.env` turns every staff role off (the owner keeps full access).
- **Audit log:** every console change (and every refused or failed one) is recorded with who, which role, what, settings before and after, IP and the request reference. Secrets are masked. There is no way to edit or delete an entry from CVTara. Each entry is chained to the one before by a hash; a daily check (and the "Check the log is intact" button) reports a broken chain in the Error Log.
- **Authenticator app:** the owner and each staff member can switch on Google Authenticator, Microsoft Authenticator or Authy. Console sign-in then asks for the app's 6-digit code instead of an emailed one. The key is stored encrypted and each code works only once.
- Test suite `s46_roles` (62 checks, including a live-mode sign-in), plus a browser check of the console signed in as each role.


## 3.36.0
First release of the V2 plan (`/mnt/project-files/v2-discovery/`). No database change.
- **Error references:** every request gets a random 12-character reference, sent back in the `X-Request-Id` header. A reference sent by the browser is ignored. Every Error Log entry keeps the reference of the request that caused it, and a repeated error keeps the latest one. When something fails, customers see "quote reference XXXX to support". The owner can paste that reference into the Error Log search to find the exact entry.
- **One approved writing service:** the old second writing-provider path (`WritingAssistant`, the "standard" writing service and its API-key field) is removed. Writing help now runs only through the CVTara team service.
- Test suite `s45_v2_foundations`.

## 3.35.2
- **Job Hunt (₹299, 30 days)** now gives 10 tailored resumes and 10 cover letters (was 5 and 5); 20 ATS scans are unchanged. Owner decision, 7 Nov 2026.
- A migration updates the saved price list where Job Hunt still held 5 and 5 (and its "5 tailored resumes and 5 cover letters" card line), and gives customers on a running Job Hunt the same 10 and 10.
- The Quick Download upgrade messages and the purchase email sample say 10 and 10.

## 3.35.1
- **Billing showed "Free" for a paid customer:** the package name was looked up only by the order's package key in today's price list, and fell back to "Free" when the key was not there. A paid plan now shows its current package name, then an older key's replacement (for example an old Fresher plan reads Job Hunt), then the name saved on the order, and never "Free". The same fallback is used in the Business assistant's package names.
- **A second package no longer goes unused:** when a customer buys a package (for example Quick Download) while a different package of the same level is still running, its credits are now added after the running plan's instead of being hidden until that plan ends. Renewals of the same package work as before.
- **Privacy policy:** a saved privacy policy holding the old analytics-consent sentences is updated by a migration; other owner wording is untouched.
- **No analytics consent box:** the "Allow optional analytics" box is removed and Google Analytics is no longer loaded. Page visits and their sources are counted on CVTara's own server for every visitor (no third-party tools, no tracking cookies, no IP address stored). Privacy policy section 5 and the owner console's analytics switch text describe this.


## 3.35.0
Includes 3.34.0 (not deployed separately). Built from the owner's "SEO Director and Chief Growth and Revenue Manager" brief, reusing the existing services; only the missing parts were added.
- **Attribution:**
  - Visits from AI assistants are recognised from the referring site or `utm_source` and recorded as "AI assistants". This happens on the server, so the public script names no platform.
  - The referring site of each visit and the signed-in account of a pricing visit are kept.
  - Every order keeps the last outside arrival before checkout (last touch). First touch stays on the account.
- **Failed payments:** a signed Razorpay `payment.failed` event marks the order failed. Nothing is granted, and a later successful payment on the same order still completes it. The first payment reminder for a failed payment can use a new "payment failed, no money taken" template (off by default).
- **Customer event store:** about 18 kinds of action are copied every 30 minutes from the tables that already hold them, idempotently, with no resume text: sign-up, verification, resume, download, ATS check, cover letter, pricing visit, checkout, payment failed or paid, refund, chargeback, plan ended, referred sign-up, referred payment, support request, lifecycle mail and unsubscribe. A per-customer timeline shows them with first touch, segments and money paid.
- **Behaviour segments (13):** new, inactive, resume without ATS check, ATS without purchase, pricing visitor, checkout abandoned, payment failed, first purchase, heavy user, expiring, expired, happy reviewer and referrer. Each shows how many people it holds and how many can be emailed. Eight of them are also Campaign audiences, for opted-in customers only.
- **Journeys:** three behaviour mails (getting started, first ATS check, plan questions).
  - They ship switched off. The owner switches each one on in Emails, and that is the approval.
  - They go to opted-in people only, once each, with at most one marketing mail in 3 days and a daily cap per journey.
  - A mail is dropped before sending if the person already did what it asks or bought.
  - They run only while the growth agent is at level 3 or above.
  - Results: sent, stopped, did it within 7 days, bought within 14 days. The existing automatic mails are listed alongside.
- **Checkout recovery:** every checkout in the period is shown with its outcome: paid, recovered after a reminder, in progress, not finished, failed, refunded or chargeback. The page also shows reminders sent, recovered revenue, recovery rate and possible double payments. It has paging, filters and a CSV download. It is read-only and never marks anything paid.
- **Referral activation:** eligible and invited customers, link clicks, referred sign-ups and paid referrals (kept apart), referred revenue, bonus paid and reversed, and top referrers. Flags for review cover many referred sign-ups in a day, mostly unverified referred accounts and repeated refunds on referred orders.
- **Link register (SEO):**
  - Each prospect site records how it was approached (ten earned-link methods), the contact and where the contact came from, relevance, quality, spam risk, score, status, follow-up date, pitch, reply, published page, link type, anchor and cost.
  - The published page is opened and checked: live link with its rel, unlinked mention, link missing or page gone. Published links are re-checked weekly.
  - Visits, sign-ups and buyers from each site are shown.
  - A paid placement cannot be saved as a followed link, and obvious link-selling sites are marked high risk.
  - Pitch drafts are personal notes for the owner to send.
- **AI search checks:** a standard prompt list (editable), one record per platform, prompt and date, with model, region and conditions. Each record says whether CVTara was mentioned, recommended, accurate or linked, which page was cited, which competitors and sources appeared instead, and what was missing. The tab shows mention rates per platform, the latest result per prompt, checks that are due, and visits, sign-ups and buyers from AI assistants. Nothing is scraped; each check is an observation entered from the platform itself.
- **Crawler access audit (daily):** robots.txt rules for Google, Bing and the AI search, user and training crawlers, and private areas kept closed. It also checks how the server answers each crawler and that private pages answer noindex. Training crawlers are a separate owner choice (allow or block), written into robots.txt. By default robots.txt is unchanged.
- **Scorecard:**
  - Confirmed collections, refunds, chargebacks, net, gateway fees (an estimate from the owner's rate, otherwise "not tracked"), AI spending, campaign and link costs, and contribution after variable costs.
  - Repeat, referral, recovered and organic revenue.
  - Paying and new customers, and variable cost per new customer.
  - Revenue by source three ways (first touch, last touch, assisted), with no double counting.
  - Each agent's tasks, failures, AI spending and cost per task.
- **Autonomy levels 1-4 per agent** with a permission matrix. Level 5 actions are never automated: new pages, redirects, canonicals, deleting pages, prices, payment records, refunds and consent. The SEO agent asks for recrawls only at level 3 or above.
- The Business assistant's agent status now includes checkout recovery, journeys, referrals, live links, AI answer checks and crawler problems.
- Migration `2026_11_07_000002_growth_engine` adds the following. Run `php artisan migrate`.
  - New tables: `customer_events`, `link_prospects` and `ai_visibility_tests`.
  - `page_views.owner_id` and `page_views.ref_host`.
  - `users.signup_ref_host`.
  - `purchases.touch_source`, `purchases.touch_landing` and `purchases.touch_campaign`.

# CVTara v3.34.0 — SEO and growth agents that work towards revenue

## 3.34.0
- **Where sign-ups come from:** the first page, source (Google, Bing, social, referral, direct) and `utm_campaign` of the visit in which someone signs up are saved on the account. This only happens for visitors who allowed analytics; everyone else shows as "unknown", never guessed. Clicks between our own pages no longer count as "referral" visits. Sales are tied to the page and source through the buyer's account, and are shown as attributed, not as proof of cause.
- **Growth page tabs:** Overview (as before), SEO agent, Growth agent and Agent log.
- **SEO agent, now operational:**
  - **Page inventory and technical scan:** every sitemap page and the important pages are fetched as a visitor sees them. Important pages are scanned daily, the rest weekly, 40 pages every 10 minutes. Each scan records the title, description, H1, canonical, robots, word count, structured data and internal links. Issues are reported in plain words: missing or long titles and descriptions, H1 problems, canonical pointing elsewhere, noindex on a sitemap page, thin pages, redirects, missing structured data, duplicate titles and descriptions, orphan pages, pages with one link in, and linked pages missing from the sitemap. Fixed issues count as resolved.
  - **Google index status per page** (URL Inspection API): verdict, Google's reason (for example "Crawled - currently not indexed"), last crawl and Google's canonical. Important and changed pages are checked first, 15 per half hour, within a daily cap (60 by default; Google allows 2,000).
  - **Recrawl requests only for changed pages:** a page Google has not indexed is sent once to IndexNow, and to Google's Indexing API if switched on. It is sent again only after it changes. Each page links to Search Console, where "Request indexing" lives.
  - **Sitemap status** from Search Console: URLs submitted, errors, warnings and when Google last read it.
  - **Keyword database:** the owner's target keywords are seeded, and every Search Console search is added with its numbers, its movement since the week before, the page Google shows and the planned page. Each search is classified by rule as brand, product, commercial, informational, question or long-tail, with an intent and a business value. Other brands stay out (owner rule). Opportunities: seen but rarely clicked, page 2, beyond page 2, and wrong page shown. Search volume and difficulty are not tracked.
  - **Brand result for "CVTara":** brand vs other searches, a home page checklist (title, Organization/WebSite data, indexed, favicon, links to main pages) and each main page that could be a sitelink. Google picks sitelinks; this only makes the pages eligible. Resume vs JD Matcher and a separate Plans page do not exist yet and would need approval.
  - **Topic clusters:** Resume Builder, ATS Checker, Cover Letter Generator and Resume vs Job Description, with link checks both ways and suggested topics. Nothing is published without approval.
  - **Did it work?** For every approved change, the page's clicks, impressions and position before and 4+ weeks after.
  - **Page history:** weekly numbers and every agent action on the page.
  - **KPIs:** important pages indexed, issues open and resolved, clicks, impressions, CTR, brand vs other searches, organic sign-ups, buyers and attributed revenue, sign-ups and sales by first page, and approval rate.
- **Growth agent, now operational:**
  - **Customer journey in 11 steps,** from visitors, 2+ pages, sign-up, verified, used, paid step, checkout, paid, used after paying, bought again, to add-on or bigger pack. Steps after sign-up follow that period's sign-ups up to today.
  - **Revenue diagnosis (daily, or on demand):** each finding gives the likely cause, the evidence, the fix, the expected effect, how to measure it and the ₹ at stake where it can be counted. It covers visitors not signing up, pages with traffic but no sign-ups, sign-ups but no sales, email verification, activation, free users not buying, checkout drop-off and payment errors, refunds, renewals, campaigns that sold nothing, outreach bounces and click rates (including contacts with no recorded permission), reviews, and buyers per channel. Below minimum sample sizes it says there is not enough data.
  - **Campaign register** for every channel (email, outreach, SEO page, social, LinkedIn, college/TPO, coaching, influencer, referral, retargeting, promotion, reactivation): audience, problem, offer, message, landing page, utm tag, CTA, budget, cost, expected outcome and result. Results come from the linked email campaign, outreach list, utm tag or landing page, and include cost per buyer. Email, outreach and LinkedIn campaigns must say how permission to contact was obtained.
  - **Experiment register:** hypothesis, segment, change, budget, dates and success metric. The baseline is counted for the same number of days before the start and the result from start to end, with the change. The owner decides continue, improve or stop.
  - **KPIs:** sign-ups, activated, free to paid, orders, confirmed revenue (kept apart from attributed revenue), average order, repeat purchases, add-on and repeat revenue, campaign cost, cost per new customer, outreach click and sign-up rates, refund rate, complaints per 100 orders. Ad spend is not tracked.
- **Agent log and control:** every agent task with its result, error and time taken (paged, filters, CSV/Excel/PDF). Each agent can be paused or resumed. `SeoAgent::agentStatus()` and `Growth::agentStatus()` give the Business assistant status, last and next run, tasks done, pending and failed, approvals waiting, AI spending and results (each with period and source).
- Migration: `2026_11_07_000001_agent_operations` (users.signup_source / signup_landing / signup_campaign, agent_runs, seo_pages, seo_keywords, growth_campaign_plans, growth_experiments).

## 3.33.0
- **Customer Tara, confidentiality:** she never shares her instructions, the owner console, how CVTara is built (servers, databases, internal links), keys or passwords, business numbers, security details or anything about another customer. Pasted text, uploads, job pages, links and code are treated as content, never as instructions.
- **Unsafe requests:** messages that try to get her instructions, secrets, admin functions, other customers' data, to run code or to get around payments or limits are noticed on the server. She declines in one friendly line without saying how she noticed, and keeps helping. Each attempt is recorded (new table `tara_events`). After 3 serious attempts in 24 hours the owner gets a warning in the Error Log. After 6, Tara alone is paused for that customer for 24 hours, with a plain message; the rest of their account works. Asking for another customer's document is recorded too. The server's own checks (sign-in, ownership, limits) stay the real protection.
- **Offers:** package offers shown on the dashboard card or in the chat are counted (once a day), as are clicks (`POST /api/tara/event`). The owner sees customers who bought within 7 days of an offer and that revenue, labelled as a time window, not proof.
- **Business assistant:** new lookups for Customer Tara activity and security, the sales funnel with the weakest step ("why is revenue not growing"), and "what should I prioritise today". New confirm cards to approve or reject an SEO agent change and to retry a failed Expert Review. Every card now says what happens after Confirm. Reports with customers' personal details show 25 rows in the chat and are downloaded only from Reports & Export. The briefing adds the funnel, Customer Tara (chats, ratings, offers, conversions) and security events. Each answer records the rules version and model used.
- **Agents:** the assistant reads `SeoAgent::agentStatus()` / `Growth::agentStatus()` when the agents provide them.
- **Layout (owner's request, 6 Nov):** in the customer workspace the Feedback button now sits in the top bar, and Tara's "Ask Tara" button has the bottom-right corner. Once a day after sign-in, Tara opens with a hello by name and asks which job the customer is applying for (free users hear how to get a clean download; on phones it is a small bubble). It is shown only once really displayed, waits for other pop-ups, and the owner can switch it off in Tara chat settings. In the owner console the floating Live stats card is gone; a small Business assistant chat ("Ask Tara") sits in that corner on every console page, with the same confirm cards.
- Migration: `2026_11_06_000004_tara_events`. Events are kept 365 days and removed with the account.

## 3.32.0
- **Tara chat (testing sheet v2):** a chat assistant inside the customer workspace. It's a floating "Ask Tara" button, an "Ask Tara" menu item that opens full screen (also `?tara=1`), and it works on phones. Customers see "Tara, your CVTara career assistant" and never any mention of AI.
  - **Features:** welcome message and suggested questions, past conversations (open or delete them), resume upload through the normal importer (paperclip), typing and "looking into it" status, credits left and messages left, links to the right page, 👍/👎 on answers, and "Talk to our team".
  - **Actions she can take for the signed-in customer:** plan and credits, packages on sale with real prices, orders and payment status, list saved resumes and letters, read a resume, set the primary resume, read a job link, run an ATS check (uses a scan, same rules as ATS Match), past checks, open the builder to tailor a resume for a checked job, open the cover-letter page to write a letter, and create a support ticket (reference CVT-XXXXXXXX, area "Tara chat" in Support).
  - **How actions run:** every action goes through the customer's own API on the server (`Services/TaraChat.php`). Sign-in, ownership, credits, daily caps and rate limits apply exactly as on the normal pages. She cannot see another customer's documents or change payments.
  - **Costs:** chatting is free for customers. Each customer gets 10 messages a day on a free account and 60 on a paid one, with a whole-site daily cap and daily and monthly spending limits in ₹. A failed answer does not use up a message.
  - **Context:** older messages are summarised. Costs are logged as "Tara chat" in Reports › AI spending.
  - **Privacy:** chats are kept 180 days, then their words are wiped. They are included in the customer's data export and removed on account deletion.
- **Owner console › Tara chat:**
  - Switch Tara on or off (needs the AI key), set the model, extra instructions, and which actions she may use.
  - Set message limits, spending limits, how many recent messages she reads, actions per answer, the hand-off message and suggested questions.
  - Today and 7-day numbers.
  - A conversations list with search, dates and download. Opening a chat is recorded in the audit trail.
  - Failed actions with their reason, also downloadable.
- **Dashboard "next step" card (owner's sales request):** instead of relying on emails, the dashboard shows one card that fits where the customer is. No resume yet: upload one or start from a template in one click. A resume but no package: a one-click download offer with the real price (Job Hunt, or Quick Download). It also shows real numbers only, such as how many people from the same college or company use CVTara or bought a plan, and how many days the customer has been with CVTara. Groups smaller than 3 (set in the console) are never shown, so no one can be identified, and nothing is made up. The card can be hidden for the day, and can be switched off in Tara chat settings.
- **Tara knows the customer:** a "my profile" action (college, last company, target role, days with CVTara, what they have used) and the customer guide. She suggests the right package from these facts only.
- **Admin stays private:** Tara never describes the owner console, staff tools, website setup, hosting, settings, limits, suppliers or costs. Asked about them, she says she can help with the customer's own account.
- **Owner console › Business assistant (Tara in owner mode, testing sheet "Super Admin" PRD):** a new page at the top of the console menu.
  - **Ask in plain words** about revenue, paid orders, packages, refunds, unpaid checkouts and failed payment notices; customers (new, free, paid, complimentary, expired, never bought, conversion, package mix, plans ending in 7 days, most active); one customer's account; credits used and the heaviest users; AI spending; the SEO agent; the growth agent and campaigns; site health; referrals, reviews and support; everything waiting for the owner; and any report from Reports & Export (first 25 rows plus an Excel download link).
  - **Numbers are counted by the database, never by the AI.** Important figures carry the period, the source and when they were worked out, and are compared with the same stretch before (today with yesterday up to the same time, this month with the same days of last month). Revenue follows the Reports rule: no unpaid checkouts, failed or test payments, ₹0 grants or referral rewards. Anything the site cannot measure (email opens and clicks, ad spend, organic sign-ups per keyword, Core Web Vitals) is said to be not tracked instead of guessed.
  - **AI spending** in ₹ and $ with the exchange rate and prices from Growth settings, split into SEO agent, marketing & growth agent, resume improvement, resume generation, cover letters, job matching, Tara chat, the business assistant and other; by model; the costliest per request; and the average per customer by package. ATS checks are CVTara's own rules and cost ₹0 in AI fees. AI usage now records the customer's account (from this version on), so cost per package can be worked out; account deletion unlinks it.
  - **Agents:** the SEO agent's status, last and next run, Search Console clicks and impressions with dates, keywords up and down, top pages, pages with no impressions, proposals waiting, errors and spending. The growth agent's reports, month against plan, suggested actions, and each campaign's sent, failed, unsubscribed and purchases within 14 days of sending (shown as a time window, not proof). Activity and results are kept apart.
  - **Site health by priority:** live checks, unresolved errors (payment problems first), failed emails, disk, scheduled jobs and backups, each with the page to fix it.
  - **Changes only after Confirm:** Tara can prepare: give a package or add-on pack (complimentary or manual payment), end a grant, change a support ticket, approve or reject a refund request, pause/resume/cancel a campaign, switch the SEO agent's daily runs, switch growth reports, change the monthly AI cap, switch customer Tara on or off. Each shows as a card with the values now and Confirm / Cancel. Confirm needs the fresh sign-in check, re-checks the values, runs through the same console action as the normal page, and keeps who confirmed it, the values before and after and the result. Cards expire after 30 minutes and cannot run twice. Payment keys, passwords, security, deleting records or accounts, blocking people and plan prices are not available to the assistant.
  - **Daily briefing** beside the chat (today, yesterday, 7 days, this month, 30 days, last month): KPIs with change, sales by package, refunds, agents, AI spending, problems to fix, what is waiting, recommended next steps and what is not connected. It is worked out without the AI (free) and kept 5 minutes; "Ask Tara to explain" sends it to the chat.
  - **Actions record** (paged, filters, CSV/Excel/PDF download), **saved chats**, and **settings** (model, monthly ₹ limit, answer length, standing instructions). Every lookup is logged; owner chats are kept apart from customer chats and never counted as customer Tara messages.
- Migration: `2026_11_06_000003_tara_chat` (tara_conversations, tara_messages, tara_tool_calls with a customer/owner mode, tara_admin_actions, ai_usage.owner_id).

# CVTara v3.31.1 — Clearer Free and Quick Download settings

## 3.31.1
- **Packages page, Free:** the Free card no longer shows "Resume downloads 1" and "Cover-letter downloads 1". Those numbers were never used: a free account has no download credits and cannot download a clean file (it is offered Quick Download or a plan). The card now says what Free gets (build, edit, preview with a watermark, and the daily free ATS checks) and shows only the daily checks setting. Saving sets Free's download counts to 0. The default is 0 as well.
- **Packages page, Quick Download:** the "ATS scans (0 = unlimited)" box is replaced by a note: Quick Download has no scan allowance of its own, and buyers get the daily checks. The box had no effect for this plan.
- No migration.

# CVTara v3.31.0 — Plan items from the testing sheet

## 3.31.0
- **Owner console, Back button:** moving between console pages now adds a browser history step, so Back returns to the previous console page instead of leaving the console. Forward works too.
- **Free plan grants:** Users › Manage subscription now lists every plan given from the console (plan, amount, dates, note, who gave it, whether it is still active). An active grant can be ended at once with "End now" (asks for a note and a fresh sign-in check); the end is written to the audit trail. ₹0 grants stay out of revenue and referrals, as before.
- **Credits view:** Billing & Plans has a "Credit history" section listing the last 50 uses (resume, cover letter, ATS check, primary resume) with what each was for and how many credits it used, beside the balance and expiry. Only finished work uses a credit.
- **Referrals:** unchanged, by the owner's choice: ₹50 wallet credit, no cash withdrawals.
- **Reviews:** customers can add their job title and company (optional). Sending the same feedback twice is refused politely. The console shows job title and company, a "Verified buyer" badge (a real paid order on the account), "Possible duplicate (N)" when the same words were sent before, and "No public consent". Published reviews on the homepage show job title, company and the Verified buyer badge.
- **AI spending report:** Reports & Export › AI spending gives one row per month, feature and model with requests, input and output tokens, cost in INR, cost per request and cost per paying customer that month; totals at the top; CSV, Excel and PDF downloads. Growth › AI spending links to it. Each customer feature is now recorded under its own name (Expert Review, ATS tailoring, Cover letters, Writing help in the editor); earlier requests show as "Customer features (before 3.31)". Paid customer features still keep working over the monthly cap.
- Migration: `2026_11_06_000002_review_company_designation` (adds two optional columns to feedback).

# CVTara v3.30.0 — Fixes from the testing sheet

## 3.30.0
- **Resume upload, "?" instead of bullets:** bullets a PDF font could not map ("?" or "�" at the start of a line), emoji and symbol bullets (✔ ➤ ★ 🏆), and dash bullets now import as normal bullets, one per line. A "?" standing in for an apostrophe ("Amazon?s") becomes "’" again. Dash bullets no longer glue a project's lines into one long line (that line was then dropped). "Awards & Volunteering" and "Trainings or Certifications" are read as their own sections. Cover letters built from such a resume no longer copy section headings or "?" into the letter. Re-upload an affected resume to get the clean version.
- **ATS Match, own resume uploaded as the job:** when the job text is clearly a resume (contact details at the top, resume headings, none of a job posting's words), the check stops with "This looks like a resume, not a job posting…" and no scan is used. Testers who did this got their own name, "University" and "CGPA" back as missing keywords. "University", "College", "CGPA", "LinkedIn", "GitHub" and "Sample" are no longer counted as job keywords.
- **Homepage pricing:** the add-ons (Expert Review, Profile Boost) are no longer shown under the homepage plans. They stay on Billing & Plans and at checkout.
- **Footer:** a new row under the link columns with Contact, Secure payments (Razorpay, and PhonePe once it is switched on), Follow CVTara (LinkedIn, Instagram, Facebook, X) and "Proudly built in India" with the flag. This fills the empty space next to Contact. Social links are editable in Owner console › Footer, policies & contact.
- **Contact details:** address IndiQube Park, Domlur 1st Stage, Bengaluru, Karnataka 560071; support hours Monday–Friday, 10:00 AM–6:00 PM IST; the old phone number removed (the Call us card hides when no number is set). The migration changes only values still equal to the old defaults. Email footers use the new address when no postal address is set under Integrations › Email.
- **Phones:** the resume and cover-letter preview always fits the screen width (the 100% option cut the letter off on the right); the zoom choice is hidden on phones.
- **Recent-activity notices** no longer appear inside the dashboard/editor or at checkout.
- **Old notification prompt:** every page now removes any leftover OneSignal service worker, and the old OneSignal worker file names unregister themselves.
- Migration: `2026_11_06_000001_contact_details_from_testing_sheet` (settings only).

# CVTara v3.29.9 — Attractive outreach mails

## 3.29.9
- **Outreach mails, new "Campaign" look (now the default for both lists):** a coloured header with the CVTara mark, a small label, the headline and a one-line summary; a "How it works" row of three numbered steps; and one big rounded button in a highlighted panel with small print under it. Students get a blue-violet header with an orange button ("For students & freshers"); placement cells a calmer navy header with a green button ("For placement cells"). Works on phones and in dark mode, and passes the spam-style check. New template lines: `[steps: A | B | C]` and `[small: text]` under a button; the Designed and Plain looks show steps as a list and leave the small print out.
- Both recommended sequences rewritten for it (headings, tick lists, one clear call to action per mail, the live offer as a highlighted box, short links instead of long addresses, proper sign-offs; the unverified "built by an HR team" line removed). The other ready-made templates got the same polish. A list still on the earlier recommended mails, word for word, moves to the new mails and the Campaign look by itself; a list the owner edited is kept as it is. No migration.

# CVTara v3.29.8 — Outreach finds the email column in any sheet

## 3.29.8
- **Outreach › Add contacts:** the email column is found even when the sheet starts with a title row, has an S No column first, or names it "Email-ID", "Student E-mail" and similar. If no heading names it, the column holding the addresses is used. Before, such a sheet was read from the first column and every row came back as "Not a valid address". A **Download blank sheet** button gives the right columns for the chosen list. The mail editor now folds away under "Show and edit the mails", so the page is shorter. No migration.

# CVTara v3.29.7 — Outreach import takes Excel files

## 3.29.7
- **Outreach › Add contacts:** Excel (.xlsx) files upload directly (first sheet, up to 20,000 rows, columns found by their headings). Rows copied from Excel or Google Sheets (tab-separated) and semicolon CSVs now import; before, they were all rejected as invalid addresses. A "Candidate name", "Student name" or "User name" column is read as the name. An old .xls file asks to be saved as .xlsx or CSV, and pressing Check and add without the consent box ticked shows a clear message. No migration.

# CVTara v3.29.6 — Outreach sends from campus@ and placements@

## 3.29.6
- **Outreach:** each list can send from its own alias of the mailbox. Students get mail from campus@cvtara.in and placement cells from placements@cvtara.in by default (change it under Outreach › your lists › Send from). Replies and one-click unsubscribe use the same alias. Because every alias is the same mailbox, add the mailbox once: one daily limit covers both lists. An alias on another domain than the mailbox is ignored. No migration.

# CVTara v3.29.5 — Growth page downloads

## 3.29.5

- Growth page lists now download as CSV, Excel or PDF with the page's filters: growth reports and SEO proposals (with a date range), tracked pages and searches (with the Pages/Searches choice, filter and search box), and the "Which pages Google is showing" table (CSV).
- Downloads use plain column names (Page, Main search, Impressions, Change vs week before, Position, Searches it shows for and so on).

# CVTara v3.29.4 — What's new for customers

## 3.29.4
- **What's new window:** when a signed-in customer opens their workspace after the owner publishes a note, a small window lists the notes they have not seen yet (up to 4, newest first, with dates and New / Improved / Fixed / Safety labels), signed by the CVTara team. "Got it" closes it for good on every device; it never covers another open window (the welcome form or a plan dialog go first). Brand-new accounts are not shown notes from before they joined, and notes older than 60 days are not pushed.
- **What's new button:** a bell button in the workspace top bar (with a dot while there is something unread) and a link in the sidebar footer open the full list, grouped by month, any time.
- **Owner console > What's new:** add, edit, hide or delete notes, preview them as a customer, and see how many customers read updates in the last 30 days. Editing a note that is already shown does not show it again. Text that mentions AI or the tools behind it is refused.
- **Seeded** with 11 plain-language notes from 23 Oct to 1 Nov (ATS fairness for Indian jobs, account and payment protection, fewer errors at busy times, payment help, new look, faster pages, support phone, one-click tailoring, one-click cover letters, steadier ATS scores, better resume upload). Owner-only changes are left out.
- No migration: notes live in site settings, and "seen" is one activity row per customer.

# CVTara v3.29.3 — Count revenue and offers from launch

## 3.29.3
- **Start counting from now (Owner console > Overview):** one button sets the moment revenue, orders, paid users, the Growth page (actual vs target, 2 PM and 7 PM reports), offers seen/clicks/coupon sales and Outreach revenue start counting from. Nothing is deleted: older orders stay in the Orders list and exports for refunds, accounts and tax. Needs the owner's email security code. Pressing it again moves the start. No migration.
- **Real money only:** "Paid users" and conversion no longer count test-mode payments or free plans given by hand, and the live "Revenue today" figure no longer counts test-mode payments. Abandoned and failed checkouts were never counted; a test now guards that.

# CVTara v3.29.2 — Pre-launch check: fairer ATS Match for Indian job posts

## 3.29.2
- **ATS Match, degrees:** B.Com, M.Com, BCA, MCA, BBA, B.Sc, M.Sc, B.E., M.E., B.A., PGDM, Diploma, Graduate, MBBS, B.Pharm and similar are now recognised, so the education requirement no longer shows "Not found" for an Indian degree. "Scrum master" is no longer read as a master's degree.
- **ATS Match, experience:** "3 to 5 years" asks for 3 years (it asked for 5). A fresher post ("0 to 2 years") asks for no minimum, so freshers are no longer marked down for experience.
- **ATS Match, keywords:** location, salary/LPA, job type, shift, notice period and how-to-apply lines are no longer counted as keywords (Mumbai, Maharashtra, LPA, WhatsApp, "Title" were showing as "missing"). A list of acceptable degrees ("B.Com / M.Com / BBA") is checked once as the education requirement instead of marking the other degrees as missing. The employer named on the title line ("Java Developer - TCS") is not a keyword. Seniority words (Senior, Junior) are not keywords.
- **ATS Match, requirements:** when a job names a skill twice, the sentence that says it is required sets its priority ("Must have: Java" makes Java Required). "Good to have", "is a plus" and "added advantage" mark a requirement as preferred. An education line ("Education: B.Tech from a recognised university") no longer adds an "Education and edtech" requirement to a non-teaching job. "Data analyst" counts as data analysis.
- **ATS-friendly score:** an empty resume now scores 0 (it got 5 for "standard headings").
- **No AI wording in public files:** the owner's service-queue script (it names the writing service) moved from a public file to the owner-only /console/refunds.js. "ai resume builder" was taken out of the home page keywords (also removed from saved keywords by a migration).
- **ATS checker page:** the first button is the check itself; "Use this template" is only on template, example and role pages.
- **Migration:** 2026_11_01_000001_no_ai_keyword. Run `php artisan migrate --force` and rebuild ready-made pages. Delete public_html/refunds-admin.js on the live site.
# CVTara v3.29.0 — Weekly report, page and search tracking, on-page SEO fixes

## 3.29.0
- **Weekly owner report (default):** one report every Sunday from 9 AM India time covering Sunday to Saturday of the week before: sales against the plan, last week's funnel, Search (Google clicks and impressions, searches close to page 1, pages seen but rarely clicked, the pages with most impressions and the searches they show for, searches that moved 3+ places, SEO changes that went live), risks, decisions and next actions. The 2 PM / 7 PM daily reports remain a choice under Targets and settings. The weekly report keeps the "Revenue follow-ups (last 7 days)" section from 3.28.0. Report risks are now written in plain words instead of internal check names.
- **Tracked pages and searches (Growth → SEO agent):** every Search Console check keeps this week's numbers per page and per search (up to 1,000 searches), so each page shows impressions, clicks, click rate, position, the change since the week before, the searches it shows for, its main search and whether its title carries it. Filters: on page 1, close to page 1, beyond page 2, seen but rarely clicked, title misses its main search, and searches naming other brands. Paged and searchable.
- **On-page SEO audit of all 522 sitemap pages:** every page has one title, one description, one H1, a canonical address and alt text on every image. Fixed: the footer's site links were missing on Templates, About, Contact, Privacy, Terms and Refund (the server only filled an empty footer written on one line); those pages and Cover letters / Guides now carry page and breadcrumb schema; the Templates, Cover letters, Guides and Blog headings now name what the page is.
- **New landing pages for high-intent searches:** /resume-builder/ (free resume builder, resume maker; signed-in customers still go straight to the builder), /cover-letter-generator/ (cover letter from your saved resume and the job post) and /cover-letter-examples/how-to-write/ (with a sample letter). The ATS checker now also targets "resume score". All three are in the sitemap, the footer and llms.txt, with FAQ and breadcrumb schema.
- **Which pages Google shows:** the SEO agent lists every sitemap page Google has not shown yet next to those it has, and a per-section table (resume examples, city pages, interview questions, guides, calculators…) of pages shown out of pages in the sitemap. The weekly report gives the same count.
- **Calculators:** each calculator page now carries WebApplication schema (free, runs in the browser) beside its article, breadcrumb and FAQ schema, and short calculator titles say "for India" unless the owner has written a title.
- **Answer engines:** new /llms.txt summarising CVTara, its tools, guides, every calculator and the plans. robots.txt already lets every crawler in.
- **Migration:** adds seo_track. Run `php artisan migrate --force` after updating.
# CVTara v3.28.0 — Honest follow-ups from sign-up to purchase

## 3.28.0
- **Package mail on day 14:** the plain "what each package includes" nudge now goes 14 days after sign-up instead of day 300, and the fresher tips on day 30, when people are actually deciding. The other nudges keep their days; the editor names show each nudge's day.
- **Referral invite for Job Hunt and Pro buyers:** one mail, 8 days after the purchase (past the 7-day refund window), inviting them to share their link: the friend gets ₹50 off ₹299 and up, they get ₹50 credit. Once per customer, opted-in only, only while the referral programme is on; never after the ₹99 Quick Download or add-ons.
- **"Your work is still saved":** one mail 14 days after a plan ends when nothing was bought again: their resumes are kept, a fresh-ATS-check tip and packages from ₹99. Opted-in only.
- **₹99 Quick Download reminders:** a 7-day plan no longer gets "your plan ends in 7 days" on the day it is bought plus a 4-day reminder; it gets only "ends tomorrow". Longer plans keep 7, 4 and 1 day reminders.
- **Unfinished payments (Razorpay and PhonePe):** when someone opens the payment window and does not pay, a reminder goes out about an hour later with a link straight back to the same package (and the same coupon while it still works), saying no money was taken. If still unpaid a day later, one last reminder with the packages. Then nothing more for a week, however many times they open checkout. A waiting reminder is dropped if they pay in the meantime (before, a reminder could arrive after they had paid). Opted-in customers only, as before.
- **Logo in every mail:** the CVTara mark (PNG, so Gmail and Outlook show it) next to the name at the top of every customer and outreach mail; the name still reads when images are off.
- **Outreach mail footer:** says who is writing and from where (CVTara, postal address, support address) above the unsubscribe link.
- **Growth reports: revenue follow-ups:** the 2 PM and 7 PM reports show unfinished payments in the last 7 days, reminders sent, how many came back and paid (and how much), new free sign-ups and how many agreed to tips, plus referral invites and "work is still saved" mails. Two new next actions: when few unfinished payments come back, and when most new sign-ups did not agree to tips.
- Both new mails can be edited or switched off in Email templates. No migration.

# CVTara v3.27.1 — Owner console stays on the page you were on

## 3.27.1
- **Owner console:** reloading the page (or coming back to it after fetching the security code from email) reopened the Overview for the Settings pages reached from cards: Integrations (payment keys), Footer & policies, SEO, Product controls, Offers & popup, Keyword intelligence and Health. They now reopen where you were, like the side-menu pages already did. No migration.

# CVTara v3.27.0 — SEO agent and AI spending without a limit

## 3.27.0
- **SEO agent (Growth page):** every day from 11 AM India time, Monday to Saturday, it reads Search Console (searches, pages, and which page each search showed) and lists searches close to page 1 (positions 4 to 20), pages that are seen often but rarely clicked, searches split across two pages, and searches with no good page yet. Searches naming other brands (job sites, other resume makers, Word/Google Docs, big employers) are left out.
- **Proposed changes, approved by the owner:** a new title and description for low-click resume pages, written for the searches that show them, and links to pages close to page 1 from up to 5 related pages (a "Popular with job seekers" block). Each proposal says why, with the numbers, and shows the before and after. Nothing changes on the site until the owner presses Approve; Undo takes a change off again; the page is refreshed straight away and search engines are told within the hour. A page that was decided on rests for 30 days. Daily limits and the minimum impressions are editable, and "Check Search Console now" runs it on demand.
- **Reports:** the 2 PM and 7 PM reports gain a Search section (Google clicks and impressions, the top searches close to page 1, changes waiting for approval), or "Data unavailable" until Search Console is connected.
- **AI spending is monitored, not limited, by default:** the cap now defaults to 0 (no limit). Spending is still counted per request and shown on the Growth page; setting a cap brings back the alerts and the stop for growth tasks.
- **Migration:** adds seo_proposals. Run `php artisan migrate --force` after updating.

# CVTara v3.26.1 — A calmer, more human look across the site

## 3.26.1
- **Homepage:** rewritten in a plain, personal voice and cut from 13 sections to 10, with a short founder note. The blue-to-purple gradient, glows, pill labels, emoji icons and rotating headline are gone; one solid blue, serif headings and warm backgrounds instead. Reviews stay hidden until one is approved.
- **Every public page:** the same serif headings and solid blue actions (no amber buttons or gradient covers); the generated article cover that only repeated the title is hidden.
- **Night mode:** blue links on dark panels lifted to a readable blue on 5 pages.
- **Customer workspace and owner console:** one set of line icons in the sidebars instead of emoji, neutral sidebars, a solid welcome panel, and Clear cache is no longer the loudest button.
- **Migration:** saved copies of the old default homepage text move to the new text (owner edits are kept). Run `php artisan migrate --force`.

# CVTara v3.26.0 — Growth page, owner reports and the AI spending cap

## 3.26.0
- **Growth page (owner console):** this month against the first-year plan (Nov 2026 to Oct 2027, 13,810 purchases, ₹17.5 lakh gross), with committed and stretch (200%) targets, pace for the days gone, a green / amber / red band and the purchases needed per day. The funnel (visitors, sign-ups, verified, started a resume, opened checkout, paid) for today, the last 7 days and the month, the weakest step, plan mix, visitor sources, new vs repeat buyers, and net after refunds. Anything the site cannot measure (ad spend, visitors while analytics is off) shows as "Data unavailable". Monthly targets, the stretch percentage and the average purchase are editable.
- **2 PM and 7 PM reports, Monday to Saturday (India time):** saved on the Growth page (paged list with filters) and emailed to the owner: targets vs actuals, today's funnel, risks from the Health check, decisions needed, and next actions from fixed rules (weakest funnel step, low traffic, amber/red recovery, refunds, unsubscribes). One report per slot per day; a "Send a report now" button for checks.
- **Daily snapshots:** yesterday's numbers are stored once a day, so the year plan fills in month by month.
- **Monthly cap on AI spending:** every writing-help, Expert Review and Profile Boost request records its tokens and cost (prices and ₹ rate editable). Default cap ₹2,000 a month, alerts at 50%, 75% and 90% in the Error Log and the reports. At 100% growth tasks stop; paid customer add-ons keep working.
- **Campaign segments:** an email campaign to customers can be limited to one college and/or target role, read from each consenting customer's newest resume. The Growth page shows college totals (users who agreed to emails) to choose which placement cells to contact. The privacy policy now says how these resume details are used.
- **Migration:** adds growth_snapshots, growth_reports, ai_usage, profiles.college / target_role / last_company and email_campaigns.seg_college / seg_role, and updates the saved privacy text if it still has the default sentence. Run `php artisan migrate --force` after updating.

# CVTara v3.25.2 — Push notifications removed

## 3.25.2
- **No more push-notification invitation:** the site already reaches people by email and the offer popup, so the OneSignal "Get career updates" box and its script are gone from every page, guide and ready-made SEO page.
- **Browsers that subscribed earlier are cleaned up:** `/OneSignalSDKWorker.js` now unsubscribes and unregisters itself the next time the browser checks it, so nobody keeps getting pushes.
- **Owner console:** the Push notifications page, the Push switch in Integrations, the OneSignal key fields and the "Push setup" shortcut are removed. Saved OneSignal settings are no longer sent to the website and are dropped the next time integrations are saved.
- **Security headers:** OneSignal is no longer an allowed script or worker source.
- No migrations. Rebuild the ready-made pages after updating so the old script tag disappears from them.

# CVTara v3.25.0 — Owner console lists load one page at a time

## 3.25.0
- **Every owner console list is paged:** lists show 20 rows with page numbers, Previous/Next and a rows-per-page choice (10, 20, 50 or 100). Users, Orders, Error Log, Reviews, Referral Ops (withdrawals, payout identities, creator applications, affiliate balances), User Mailer emails, Keyword intelligence (page checklist and Search Console queries and pages), Customer purchase history, Support Inbox, campaign contacts, outreach contacts and Reports all ask the server for one page, so nothing downloads or draws a whole table.
- **One filter bar per list:** search plus the filters that fit the list (status, plan, payment source, section, checklist score, email type, date range), with sortable column headers. Search, filters and sorting run in the database, and the total always counts everything that matches.
- **Smaller tables stay light too:** any other table in the console (blog posts, website URLs, analytics, campaigns, health history) shows 20 rows at a time with the same page links.
- **Faster console start:** opening the console no longer loads the full user list three times. The Users list, the dashboard numbers and the live stats card are now separate small requests; the dashboard numbers are shared for 30 seconds and the live card asks for five counts. The old second Users screen opens the paged Users view.
- **Error Log:** downloads include every entry that matches the filters, and "Delete resolved" removes all resolved entries, not only those on screen.
- **Migration:** adds indexes for list sorting (users, purchases, reviews, emails and withdrawals by date). Run `php artisan migrate --force` after updating.

# CVTara v3.24.0 — Search Console connection fixes and fast indexing

## 3.24.0
- **Fast indexing (Owner console > Keyword intelligence):** new box that sends new and changed public pages to IndexNow (Bing, Yandex and other engines index within minutes), resubmits the sitemap to Google through the Search Console API, and can optionally ask Google to recrawl pages through the Google Indexing API (off by default; Google officially supports it only for job-posting and livestream pages; at most 200 a day and 40 per run). An hourly run sends changes automatically when switched on. Only sitemap pages are sent, and nothing while search indexing is off.
- **IndexNow key file:** served at `/{key}.txt`; the key is made the first time the box is opened.
- **Search Console sync errors are plain:** a bad paste, the wrong kind of key, a switched-off Google API, a missing permission or a mistyped property now each say what to fix, instead of a server error.
- **Integrations > Analytics help:** says which Google APIs to enable, to add the service account as an Owner, and how to write the property.
- No migrations. Rebuild the ready-made pages after updating.

# CVTara v3.23.0 — PhonePe payment gateway beside Razorpay

## 3.23.0
- **PhonePe checkout (PG API v2, Standard Checkout):** the server creates the order and sends the customer to PhonePe's payment page. Access is granted only after PhonePe's order-status API says the payment is complete, checked when the customer comes back, when PhonePe's callback arrives, and every 5 minutes by the scheduled job for orders whose customer closed the page.
- **Callback:** `POST /webhooks/phonepe`, authorised with the username and password set in the PhonePe dashboard (PhonePe sends SHA256 of `username:password`). Subscribe to checkout.order.completed, checkout.order.failed, pg.refund.completed and pg.refund.failed.
- **Refunds:** approving a 7-day refund on a PhonePe order sends the full amount back through PhonePe's refund API; a failed refund callback is logged in the Error Log for the owner.
- **Owner console > Integrations > Payments:** "Gateways shown at checkout" is Razorpay, PhonePe or both. PhonePe needs the Client ID, Client version, Client secret, webhook username and password, and Test (sandbox) or Live. Credentials are checked with PhonePe when saved and stored encrypted.
- **Checkout:** with both gateways on, the customer picks one. Referral credit, wallet, coupons and add-on rules work the same for PhonePe payments. Receipts say which gateway was used.
- **Policies:** the default privacy policy and terms name PhonePe beside Razorpay. A saved copy in Footer & policies is not changed: update it before switching PhonePe on.
- **Contact us:** the contact page shows the support phone number +91 82178 23192 (tap to call). A number saved under Footer, policies & contact replaces it.
- No migrations. Rebuild the ready-made pages after updating.

# CVTara v3.22.2 — Owner console pages load reliably, lighter pages, unused code removed

## 3.22.2
- **Blank owner console pages:** the site-wide limit of 240 requests a minute also counted the console's own data calls (about 40 per load), so after a minute of clicking, pages stayed on "Loading…". API and download calls keep their own limits, the owner is not page-limited, and the console shares repeat calls and retries a busy answer.
- **Lighter owner console:** the SEO page list and the page audit (about 1.1 MB) load only when SEO & sitemap or Keyword intelligence is opened.
- **Owner scripts are private:** the two owner console scripts moved from public files to `/console/owner.js` and `/console/advanced.js`, served only to a signed-in owner. Delete `public_html/owner-console.js` and `public_html/owner-advanced.js` when updating.
- **Visitors:** public pages no longer ask for an account allowance when nobody is signed in (a wasted request on every page view).
- **Unused code removed:** the old audit scripts (written for removed providers), unused owner script tags on 82 public pages, a missing controller import and a dead route branch. The installer page says "optional writing help".
- No migrations. Rebuild the ready-made pages after updating.

# CVTara v3.22.1 — Pricing and referral rules, small fixes, landing spacing

## 3.22.1
- **Pricing (#23):** the home page says plans start at ₹99 everywhere (the "A plan, paid once" card said ₹299). Only packages count for "Give ₹50, get ₹50": Job Hunt (₹299), Pro (₹599) and up. Quick Download (₹99) and the add-on services (₹499, ₹699) get no referral discount and never earn credit.
- **Carry-over fixes (#16, 3.21.1):** OneSignal App ID from the push panel, plain password-reset wording, branded mailer preview. On the 3.22.0 download dialog, free accounts no longer get a "uses 1 credit" warning on the reward-download link, a 402 offers "Download for ₹99" and "See plans", and a blank print tab is closed on error.
- **Home page spacing (#17):** no empty bands between sections.
- **Owner mailer (#3):** templates added in later releases show up, and every email type is labelled.
- No migrations. Rebuild the ready-made pages after updating.

# CVTara v3.22.0 — CVTara team wording, daily limit on services

## 3.22.0
- **CVTara team:** customers see Tara Expert Review, Profile Boost, the writing help, ATS "Fix my resume" and the cover-letter writer as work by the CVTara team. No page, email, policy or message names AI or the provider. The owner console keeps its internal wording.
- **Daily limit:** each paid service can be ordered 3 times per customer per day, then it is hidden for that customer until tomorrow.
- The workspace script `tara-ai.js` is now `tara-help.js`.
- Includes the live fixes from PRs #18 (indexing), #20 (ATS match), #21 (resume import) and #22 (cover letters). Run `php artisan migrate --force` and rebuild all pages after uploading.

# CVTara v3.21.0 — Resume upload, readable referral links, new landing page, owner console fixes

## 3.21.0 (workspace)
- **Resume upload (My Resumes):** the file is read first; if a free account already has its one resume, the customer can put the upload into that resume ("Use this file instead") or see plans. Paid accounts at their limit pick which resume to replace.
- **Readable referral links:** first name plus digits (e.g. `/r/priya-4821`). Old codes are kept in `old_referral_code`, so links already shared keep working.
- **Dashboard plan card** ("Your plan: Free" with Upgrade) replaces the empty ring; template thumbnails no longer split long names; the workspace menu button sits beside the page title on phones and tablets.

## 3.21.0 (landing page and speed)
- **Fixed header** that stays on screen while scrolling, and a back-to-top button. Menu: "Examples" is now "Resume by Role"; new "By City" link.
- **Faster public pages:** head stylesheets are joined into one cached file (`assets/bundle/`), public data is fetched once per page instead of five times, Google Fonts load from a head link. Home page: 51 → 33 requests, about 1.15 MB → 0.64 MB.
- **New home page design** (`home-v3.css`): new hero with a fading role word, proof cards, a "what you get" grid and rounded referral and closing cards. Owner platform guide restyled like the customer guide, with search.

## 3.21.0 (owner console)
- **Search text:** blank saved page titles, descriptions and keywords fall back to the defaults; the 10 main page defaults are rewritten around the non-branded keyword plan.
- **Blog & SEO** lists the ~508 built-in job, city and state pages next to the posts, with a type filter and search.
- **Referral Ops** edits the live wallet rules (give ₹50, get ₹50); the unused cash-commission fields fold away.
- **Pricing** tabs follow the packages (Packages, Services, Add-ons, Free) and show whether Tara Expert Review is on sale.
- **Delete user is now permanent:** the account and its content are removed; purchases, refunds and wallet records move to one "Deleted customers" placeholder with personal details erased. Accounts that earlier deletes only anonymised are removed by the migration. Run `php artisan migrate --force` after uploading.

# CVTara v3.20.0 — Guided template library, automatic Tara Expert Review, SEO audit, new email design

## 3.20.0 (mail)
- **One email design for every mail** (`MailLayout`): wordmark, a white 600px card with a thin brand line, one clear button, a preview line, a plain-text copy, a footer with the reason, the support address, your postal address (new field: Integrations → Email → Postal address) and, on marketing mail only, Unsubscribe. Table layout and inline styles for Gmail, Outlook and phone apps; full width on phones; readable in dark mode. Receipts show a details table; package lists and offers are drawn from Pricing and Offers & festivals.
- **All templates rewritten for the current product:** Free, Quick Download ₹99, Job Hunt ₹299 (30 days), Pro ₹599 (90 days), paid once with no auto-renewal, 7-day refund if unused, Razorpay. The referral nudge is now "Give ₹50, get ₹50" on packages of ₹299 and up (it is skipped while the wallet referral is off). Tara AI and Tara Expert Review lines appear only while those are switched on. New placeholders: `[plans]`, `[offer]`, `[note: …]`, `[preheader: …]`, `{{checkout_url}}`, `{{offer_line}}`, `{{tara_line}}`, `{{expert_line}}`, `{{referral_reward}}`. The offer is read when the mail is sent, so an ended offer is never mailed. Saved templates that still had the old ready-made wording get the new wording; your own edits are kept.
- **On time:** sign-up codes, security codes and password resets are sent inside the request that creates them, never behind campaigns or the next cron run (the cron retries a minute later if the provider is down). The cron now sends due mail first (before the daily backup and scans) and again after queuing new mail; each pass sends at most 40 mails and stops after 45 seconds so the next minute starts on time. Rows are claimed atomically, so an instant send and a cron run never send the same mail twice.
- **Mailbox limit:** new "Mailbox limit per 24 hours" (default 1,000, Hostinger Business Starter) in Email campaigns → Sending limits. Tips and campaigns pause 100 short of it, so codes and receipts always go out.
- **Inbox placement:** From is your sender address, Reply-To your reply address, Message-ID on your own domain, Feedback-ID for Gmail Postmaster Tools, one-click List-Unsubscribe on marketing mail only. Setup & health now checks that the From address is on your domain, that the postal address is filled in and that account mail is not stuck. `tools/mail_lint.py` scores every template (subject wording, capitals, text-to-link balance, shorteners, images, size, unsubscribe).
- **Outreach (cvtara.in mailboxes) is now part of 3.20** (it had been built on an older version and never merged): up to 5 mailboxes, each with its own warm-up (first-day amount + daily step, up to its cap), send window, spacing that varies between 70 and 100% of the even gap, warm-up share to your own addresses, stop on sign-up, unsubscribe and bounce, auto-pause after 3 failures or 10% bounces. Its templates use the live lowest price and offer, links point to the public ATS checker, and the designed card uses the shared design. Run `php artisan migrate` after uploading.

## 3.20.0 (Tara Expert Review)

- **Tara Expert Review is now written automatically by Tara AI.** When an order opens (right after payment) and on every minute cron, up to 3 waiting orders are picked up, marked In review, written by Tara AI from the customer's primary resume (or the one edited last), stored on the order, marked Delivered and emailed (new "Tara Expert Review delivered" template). The review has a fixed structure: overall verdict, section-by-section feedback, ATS issues, a rewritten summary, 3 to 5 rewritten bullets and a fix list in priority order. The customer reads it under Account → Purchase history. It never uses the customer's daily Tara AI suggestions or the site-wide suggestion cap.
- **Owner control.** Refunds & services has a new "Automatic expert reviews" switch (on by default; it only works while Tara AI is set up and switched on, otherwise orders wait as before). In the reviewer queue the owner can view, edit and resend any review, write one by hand (it is emailed when marked delivered) and ask Tara AI to try again.
- **Failures are retried, then handed to the owner.** A failed or declined attempt goes back to Not started (so the order stays refundable) and is retried after 10, then 20 minutes; after 3 attempts it stays in the queue flagged "Needs you" with the reason, and a warning goes to the error log. A per-order lock and a conditional claim mean two cron runs never write the same review; an attempt cut off half-way is put back after 15 minutes.
- **Honest copy.** Tara Expert Review no longer promises "a working recruiter within 48 working hours": the plan text, checkout line, dashboard, refund policy and privacy policy now say Tara AI writes it from the saved resume. Saved plan text that still had the old default wording is updated by the migration. Run `php artisan migrate` after uploading.

# CVTara v3.19.0 — Razorpay only, Tara AI and email campaigns

## 3.19.0
- **Razorpay is the only payment gateway.** Cashfree checkout, its webhook and Cashfree Payouts are removed. Checkout no longer asks for a mobile number. Referral cash withdrawals are paid by the owner by hand and marked paid with the bank or UPI reference. Signup stays email-only with a 6-digit email code.
- **Tara AI writing help, powered by Claude.** A "Tara AI" button in the workspace offers seven tasks: improve clarity, turn duties into achievement bullets, write a profile summary, tailor to a job, draft a cover letter, check against a job and practise interview questions. Off until the owner saves a Claude API key (Settings → Integrations → AI) and switches AI on. Free accounts get 3 suggestions a day, plans 20, with a site-wide daily cap (default 300) to keep the Claude bill in check. Only the text the customer picks is sent, after they tick consent; a failed or declined request does not use up a suggestion; a wrong key is logged for the owner. The privacy policy names Anthropic.
- **Email campaigns (Owner console → Email campaigns).** Send newsletters and offers to customers who agreed to marketing email (all, never bought, plan active, plan ended) or to imported contacts (CSV with a permission tick, repeats merged). Branded preview, test send to yourself, live recipient count, send now or schedule in India time, pause, resume and cancel, and a report of sent, failed and unsubscribed. Sends through the existing mailer and cron, 20 a minute and 300 a day by default. Every campaign email has a one-click unsubscribe; unsubscribed contacts go on a do-not-mail list. Run `php artisan migrate` after uploading.
- **Application status reminders now actually go out.** They were only sent by a command the host cron never ran; the regular cron now sends them every hour.

# CVTara v3.18.0 — Clearer, more trustworthy pages and less unused code

## 3.18.0
- **Plainer trust points:** the footer now says "Secure payment by Razorpay · 7-day refund if unused · No auto-renewal" instead of technical badges. Checkout explains that card and UPI details are entered only in Razorpay's window.
- **Home page:** removed the leftover "all payments final" line (the 7-day refund applies), the "under 15 minutes" claim and the vague "70%+" stat (replaced with the 7-day refund). The ATS note names the current scan counts (Job Hunt 20, Pro 60). The employer list no longer claims what customers do.
- **Privacy policy:** names Razorpay as the payment processor and drops phone verification.
- **Removed unused code:** the old phone verification for referral rewards (Twilio settings, send/check endpoints, held-review panel and launch-check line), the unused 10-friend reward setting, the unlinked guide.js script and an audit script for the removed feature.

## 3.17.0
- **Check my writing** in the resume and cover-letter builders. One click lists suggestions in three groups, each with the line it is about and words to try:
  - **Stronger writing:** weak openings such as "responsible for", "helped" and "worked on"; buzzwords such as "hard-working" and "team player"; bullets with no number or result; long bullets and sentences; "I" and "my" in a resume; three or more bullets starting with the same verb; Indian-English phrases that read oddly to many recruiters ("revert back", "do the needful", "Myself Asha").
  - **Tone:** unsure or negative wording ("tried to", "unfortunately", "just") and, in cover letters, casual or pushy lines ("Hey", "hire me", "ASAP").
  - **Grammar & spelling:** common misspellings, repeated words, double spaces, a lowercase "i", sentences that start in lowercase, spaces around punctuation and "!!".
- The check runs on CVTara's own server from a word list (`resources/data/writing-rules.json`), costs nothing and sends text nowhere. It never changes the document.
- **Optional full grammar check (LanguageTool):** a new GRAMMAR switch under Integrations, off by default. When on, customers see a "Full grammar check" button that sends that document's text to LanguageTool (the free public service, a Premium key or your own server). Limit 20 a day per customer; a failed check is not counted.
- Privacy Policy text names LanguageTool and says the basic check sends nothing.

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

## 3.15.0
- **508 new server-rendered SEO pages** built from `resources/data/seo-pages.json` (source and generator in `tools/seo/`): resume examples by field and role (`/resume-examples/{field}/{role}/`), template styles (`/resume-templates/{style}/`), cover-letter examples by field, interview questions by role, India / state / city resume guides (`/resume-builder/...`), city × role pages (`/resume/{city}/{role}/`), state government-jobs pages, resume word swaps (`/resume-words/{word}/`) and an ATS checker landing page.
- Every page has a title under 60 characters ending "| CVTara", a meta description under 155, one H1, visible breadcrumbs, an "On this page" list, an illustration with alt text, a filled sample, 4–5 FAQs, BreadcrumbList + FAQPage + WebPage JSON-LD (no LocalBusiness), one amber "Build my resume free" button and links to the ATS checker and pricing.
- **Interlinking:** India → state → city → city × role; city × role → role example, interview page and the same role in two other cities; role → field, interview, cover letter and city pages; every page → existing guides on the topic.
- **Keyword targeting:** pages follow the patterns that drive competitor traffic (role resume examples, templates, cover letters, interview questions, resume words) with all brand terms removed. Full keyword list in the project files (`seo-keywords/`).
- **The 68 existing guides and calculators:** visible breadcrumbs, an "Explore next" box into the new hubs, and up to 3 contextual links on first mention of key phrases. The browser no longer adds a second, generic FAQPage block on top of the server's own.
- Ready-made page cache and Apache rules cover the new paths; the sitemap lists them. Place facts to check before indexing are in `docs/SEO_VERIFY.md`. No salary figures are published.
- Tests: `tests/suites/s23_seo_pages.py` crawls every new page and every internal link.

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
