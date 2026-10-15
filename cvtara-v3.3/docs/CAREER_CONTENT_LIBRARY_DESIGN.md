# Career Content Library — review and implementation design

Status: design for approval (nothing is built yet). It turns the taxonomy / matching / template brief into functions and tables that fit the CVTara system as it is today (Laravel 13, query-builder style, SQLite/MariaDB, vanilla JS workspace, owner console modules, Python test runner).

## 1. Review of the brief — what to keep and what to change

Keep as written
- Six separate dimensions (industry, function, role/level, candidate context, intent, presentation). One engine, many content records.
- Eligibility first, ranking second; never an empty result (fallback ladder); labelled general templates.
- Placeholders instead of invented achievements; admin-managed content; usage analytics.

Change before building (each one avoids a real problem)
1. **Intent and language are eligibility filters, so they must not be in the score.** In the formula `25I` and `5L` are always 1 for an eligible template, which wastes 30 points and compresses the real differences. The brief also uses `L` for *length* while language is the mandatory one — rename to avoid bugs. New weights below add to 100 over seven soft dimensions.
2. **"No rule" must mean "generic", not "mismatch".** A template with no function listed is a general template. It gets partial credit (0.2–0.4), never zero and never full, so a specific template always outranks a generic one but a generic one still shows up.
3. **Use one taxonomy table, not seven.** The brief's `industries`, `specializations`, `job_roles`, `seniority_levels`, `content_categories` are all "typed terms with a parent". One `lib_terms` table (type + parent + slug + aliases) plus one mapping table gives one admin screen, one validator and one search box. Multi-valued fields become rows, not JSON arrays.
4. **Do not show five near-duplicates.** Return the best template **per purpose** (balanced, achievement-focused, concise, technical, …) so the five results are five different jobs, as in the brief's own Versions 1–5.
5. **Separate spoken from written.** Channel is a real dimension (`spoken_interview`, `email`, `linkedin`, `whatsapp`); a spoken introduction and an email introduction are different records.
6. **Authoring cost is the real project risk.** 190–300 hand-written templates is weeks of HR time. Add bulk import (CSV/JSON) and a linter so reviewed content can be loaded in batches, and start with 25–30 templates before the full inventory.
7. **Invented numbers are the biggest quality risk.** The linter must reject digits/percentages in a template body unless they are inside a placeholder (`{{achievement_metric}}`), and every placeholder shows a hint and a clearly marked example the user must replace.
8. **Language:** English only, as already decided for CVTara. The `language` column exists for later but the UI and linter enforce `en`.

## 2. Where it lives in the product

- New workspace page **Career Toolkit** (working name; one sidebar item, same layout as every other page — no per-page sizing). Flow: *pick what you need → answer 3–6 questions → see the best match + other versions → fill placeholders with live preview → copy / save*.
- New owner console module **Content Library** (`app/resources/console/library.js`, served by the existing `/console/{name}.js` whitelist) for taxonomy, templates, review queue, import, quality report and usage.
- Reports & Export gets two datasets: *Library usage* and *Library content*.
- Customer guide, admin guide, front page and package details get a section, exactly like ATS Match.
- No AI call anywhere in the path. Everything is database + deterministic functions, so it is fast, cheap and testable.

## 3. Data model (Laravel migration `2026_10_18_*`)

```
lib_terms            id, type, slug, label, parent_id NULL, aliases_json, sort, active
                     type ∈ intent | industry | specialization | role | level | experience_band |
                            channel | tone | length | stage
                     unique(type, slug); index(type, parent_id)

lib_templates        id, code (INT-TECH-EXP-001), intent_id → lib_terms, title, purpose, subject NULL,
                     body, words, language='en', tier ∈ free|premium, status ∈ draft|review|published|archived,
                     revision, reviewed_by NULL, published_at NULL, note NULL, timestamps
                     index(status, intent_id)

lib_template_terms   template_id, type, term_id          -- the rule rows: "this template fits these terms"
                     unique(template_id, type, term_id); index(type, term_id)
                     (no rows for a type = generic for that dimension)

lib_placeholders     template_id, key, label, required, kind ∈ text|number|list, hint, example, max_len, sort

lib_saved            id, owner_id, template_id, revision, title, values_json, rendered_text, timestamps
                     (cap per customer, see §7)

lib_events           id, template_id, owner_id NULL, event ∈ view|copy|save|rate, context_json, created_at
                     pruned by Retention after 400 days (add to Retention::run)
```

Seed data: the 20 industries from the brief with their example specializations, the six experience bands (0–1, 1–3, 3–5, 5–8, 8–12, 12+), levels (student/fresher, individual contributor, team lead, manager, senior manager, director+), channels, tones, lengths, and the five intents of the launch inventory.

## 4. The core functions

All in `App\Services\ContentLibrary` (pure PHP, no framework state, easy to unit-test through the API).

### 4.1 `match(array $profile, string $intent, array $opts = []): array`

```
profile = {
  industry: slug|null,           specializations: [slug…],   role: slug|free text|null,
  experienceBand: slug|null,     targetLevel: slug|null,
  channel: slug|null,            tone: slug|null,            length: slug|null,
  context: { transition?, gap?, achievement? }               // used for placeholders and hints, not scoring
}
```

Steps
1. **Eligibility (SQL):** `status = published` AND `language = 'en'` AND `intent_id = :intent`. Result size is small (hundreds), so load the rows and their rule rows in two queries and score in memory. Cache the eligible set per intent for 10 minutes (invalidate on publish).
2. **Score each template (0–100):**

   | Dimension | Weight | Credit |
   |---|---|---|
   | Function / specialization | 22 | 1.0 if any of the customer's specializations is in the template's rules; 0.6 if it shares a parent specialization; 0.2 if the template is generic for function; 0 if it lists other functions only |
   | Communication channel | 20 | 1.0 match; 0.3 generic; 0 other channel |
   | Experience band | 18 | 1.0 same band; 0.5 adjacent band; 0.3 generic; 0 otherwise |
   | Target level | 14 | 1.0 same; 0.4 adjacent; 0.3 generic; 0 otherwise |
   | Industry | 10 | 1.0 same industry; 0.6 same parent group; 0.4 generic; 0 other |
   | Tone | 8 | 1.0 match; 0.5 generic; 0.2 other (tone is editable, so it is never a hard no) |
   | Length | 8 | 1.0 match; 0.5 adjacent; 0.5 generic |

   A dimension the customer left blank gives every template full credit for it (we do not punish unanswered questions). Tie-breakers, in order: more specific rule rows first, higher copy-rate (from `lib_events`), newer `published_at`.
3. **Pick per purpose:** group scored templates by `purpose`; keep the best of each; order groups by their best score; return the top 5 (configurable). The very best is flagged `recommended`.
4. **Fallback ladder** — run when the best score < 45 or fewer than 3 results; each step relaxes the filters and the result carries a `fallback` label:
   1. same function, neighbouring experience band;
   2. same level in the same industry;
   3. general template (no function/industry rules) for the same intent and channel;
   4. general template for the same intent, any channel (labelled "general — adjust to your channel").
   The ladder always ends with at least one template, because every intent ships with a general template per channel (enforced by the linter, §4.4).
5. **Output:** `{ results: [ {id, code, title, purpose, score, recommended, locked, fallback, why:[…], words, placeholders:[…]} ], total, fallbackUsed }`. `why` lists the matched dimensions in plain words ("Matches Data & Analytics · 3–5 years · Individual contributor") so customers (and the admin) can see the logic. `locked` is true for a premium template when the customer has no active plan; locked results show the title, purpose and first sentence only.

Worked example (the brief's profile — Technology & IT, Software + Data, 3–5 years, IC, email, professional-confident, length unspecified):
- *Data & Analytics* template: function 22 + channel 20 + experience 18 + level 14 + industry 10 + tone 8 + length 8 = **100**.
- *Balanced professional* (generic function, tech industry): 4.4 + 20 + 18 + 14 + 10 + 8 + 8 = **82**.
- *Concise, any industry*: 4.4 + 20 + 18 + 14 + 4 + 8 + 8 = **76**.
The specific template wins, the general ones still appear as alternative versions.

### 4.2 `questions(string $intent): array`

Returns the ordered question list for an intent, so a referral request never asks interview questions. Defined as data (a `lib_terms` row per intent with `questions_json`), not code: each question has `key`, `label`, `type` (single/multi/search/text), `options` source (term type), `required`, `showIf`. Interview introduction asks: industry → specialization (multi, searchable) → experience → target level → channel → tone → length. Thank-you asks: interview stage → channel → tone. Adding an intent needs no code change.

### 4.3 `render(int $templateId, array $values): array`

- Syntax: `{{key}}` for a value, `[[optional sentence with {{key}}]]` that is dropped when its value is empty.
- Validates each value against its placeholder definition (type, `max_len`, no HTML, no control characters), then HTML-escapes for display and returns plain text for copy.
- Required placeholders left empty are returned as `missing` and shown highlighted in the preview; the customer can still copy (we do not block), but the preview says "3 blanks left to fill".
- Never fills a value the customer did not type. Hints and examples are separate fields, shown greyed and marked "example — replace with your own".

### 4.4 `lint(array $template): array` (runs on every save and in a batch "Quality report")

Errors block publishing; warnings are shown. Checks: unknown or unused placeholders; digits or `%` outside placeholders (invented metrics); body length outside the channel's range (spoken 60–170 words, email 90–260); seniority words that contradict the template's level rules ("senior", "lead", "manage a team" in fresher/IC content); non-English characters; banned claims ("guaranteed", "best in the world"); email templates without a subject; near-duplicates (normalised trigram similarity > 0.85 against published templates in the same intent); missing general fallback for the intent + channel; missing rule rows for required dimensions (intent, channel, level). Returns `[{level, code, message, field}]`.

### 4.5 Supporting functions

- `search(string $type, string $q)`: type-ahead over `lib_terms` (label + aliases), used for industry / specialization / job title; unmatched job titles are accepted as free text and mapped to the nearest `role` by alias for ranking, never rejected ("Other / not listed" is always an option).
- `track(int $templateId, ?int $ownerId, string $event, array $ctx)`: rate-limited, stores view / copy / save, feeds tie-breaking and the admin "improve or expand" report.
- `save(int $uid, int $templateId, array $values)`: stores the rendered text and the values; capped per customer (§7).
- `import(array $rows, bool $dryRun)`: CSV/JSON bulk load into `draft`, returns per-row lint results; nothing is published automatically.
- `coverage()`: matrix of intent × function × level × channel showing published counts and the gaps — the tool that tells the HR team what to write next.

## 5. API (all behind the existing session, CSRF, throttling and step-up rules)

Customer
- `GET  /api/library/meta` — taxonomy tree (cached) and the question list per intent
- `GET  /api/library/search?type=specialization&q=…`
- `POST /api/library/match` — `{intent, profile}` → results as in §4.1
- `GET  /api/library/templates/{id}` — full text if allowed, teaser if locked
- `POST /api/library/render` — `{templateId, values}`
- `POST /api/library/saved`, `GET /api/library/saved`, `DELETE /api/library/saved/{id}`
- `POST /api/library/event` — view / copy

Owner (step-up required for publish, import and delete)
- `GET/POST/DELETE /api/admin/library/terms`
- `GET/POST /api/admin/library/templates`, `POST …/{id}/publish|archive|review`, `POST …/{id}/lint`
- `POST /api/admin/library/import`, `GET /api/admin/library/coverage`, `GET /api/admin/library/usage`

## 6. Customer experience (workspace page)

1. **Choose a tool**: five cards (interview introduction, thank-you & follow-up, cold outreach, referral request, offer & salary) — launch with the first only; the others show "coming soon" until content is published.
2. **Questions**: one screen, progressive; type-ahead for industry and specialization; "Other / not listed" and a free-text job title always available.
3. **Results**: the recommended template large, the other versions as tabs ("Balanced · Achievement-focused · Technical · Concise"), a "General template" badge when a fallback was used, and the "why this matched" line.
4. **Fill and preview**: placeholder fields beside a live preview; blanks highlighted; hints and examples marked as examples.
5. **Copy / save**: one-click copy (plain text; email shows subject + body), save to "My saved content" (cap below).
6. Mobile layout and light/night mode follow the existing design system; no layout changes between pages.

## 7. Access, limits and how it connects to plans (decision needed — recommendation)

- Library content is **not** a download credit: copying text costs nothing, so it does not touch the resume / cover-letter / ATS-scan pools.
- Free account: browse and use templates marked `free` (about 20% of the library); premium ones show a teaser and an upgrade prompt.
- Paid plan: everything, unlimited copy; saved items capped at 50 (30 for free accounts' free templates), configurable per plan like the other limits, so it can become a sellable package feature later ("Career Toolkit: 200+ templates").
- The package details, plan cards, checkout and the owner plan editor get one new line each once decided.

## 8. Performance, security, quality

- Library is small (hundreds to low thousands of rows): in-memory scoring after two indexed queries; target < 50 ms warm. Cache keyed by intent + a `library_version` counter bumped on publish.
- All user input validated and escaped; saved text stored as plain text; events rate-limited; no external calls; no user data leaves the site.
- Content changes (publish/archive/import) are audited like other owner actions and require recent step-up.
- Account export includes saved items; account deletion cascades.

## 9. Test plan (a new suite in the existing Python runner)

Scoring and ranking (exact beats generic, adjacent bands get partial credit, ties broken by specificity), purpose-grouping (no two results with the same purpose), each rung of the fallback ladder, blank answers not penalised, wrong language / draft / archived never returned, locked content hides the body for free accounts, placeholder rendering (optional sentences dropped, HTML escaped, required blanks reported, nothing auto-filled), the linter (invented metrics, duplicates, seniority conflicts, missing fallback, email subject), import dry-run vs commit, usage events rate-limit, owner-only endpoints, step-up on publish, saved cap, account export and delete.

## 10. Build sequence and effort

1. **Foundation (≈ 1 build session):** migration, seed taxonomy, `ContentLibrary` (match, render, lint, search, track), API, tests.
2. **Owner module:** terms, template editor with live lint, review/publish workflow, import, coverage matrix, usage report.
3. **Customer page:** questionnaire → results → fill → copy/save, guide and front-page copy, plan-card line.
4. **Content:** load the five reviewed interview introductions (from the brief) plus the general fallbacks, then 25–30 templates across the five families; HR team writes the rest from the coverage matrix.
5. **Tune:** after real usage, adjust weights from copy / save / rating data (weights live in site settings so the owner can change them without code).

## 11. Decisions needed from the owner before building

1. Name of the feature on the site (Career Toolkit / Career Library / Interview Toolkit).
2. Free vs premium split and whether it becomes a plan feature (§7 recommendation: free sample, paid full library, no credits).
3. Launch scope: only *interview introduction* first (recommended), or all five families.
4. Who writes and approves the content, and in what format — CSV/Google Sheet import is supported.
5. Whether job titles from customers should feed a "request a template for my role" list (cheap to add, useful for deciding what to write next).
