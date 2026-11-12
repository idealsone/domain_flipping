# Hands-on testers: briefs

Ten experienced testers, each playing one real kind of CVTara user. They use cvtara.com in a real browser
(Playwright, driven step by step: look at each screenshot before deciding the next click, the way a person would),
try to get their own job done, and write an honest review. No sugar-coating: if something is confusing, slow,
ugly, broken or not worth paying for, say so plainly, with the screenshot that shows it.

## Rules for every tester
- Site: `BASE` (https://cvtara.com). Your account is in `tests/live/.accounts.json` (index given in your brief). Use only your account.
- Never pay. You may open checkout and look at the payment button, but never click it or enter card/UPI details.
- Never send email to anyone; never use the contact or support forms more than once, and start any message with "QA TEST".
- In the owner console: look, search, filter and download only. Never Save, Send, Approve, Publish, Delete, Run or change a setting.
- Be gentle with the server: one browser, a pause of 2 seconds or more between actions, no loops of rapid requests.
- Do not write "AI", "Claude" or vendor names into the site. Do flag it if the site shows them to customers.
- Competitors: read their public pages only (WebSearch/WebFetch or browsing logged out). Do not sign up or pay on other sites.
- Helpers: `tests/live/lib.cjs` (Persona: open({mobile}), login, goto, pageChecks, api, totp for the owner code).
  Save screenshots to `tests/live/results/agents/<your id>/` and look at them before judging.

## What to hand in: `tests/live/results/agents/<your id>.md`
1. Who I am and what I came to do (2 lines).
2. First 10 seconds after landing: what I understood, what I felt, would I stay? (honest)
3. Task log: every task I tried, step by step, with the result (done / done with trouble / gave up) and minutes taken.
4. Problems, most serious first. Each: where (page + section + link), what happened, screenshot file, severity (blocker / major / minor / polish), suggested fix.
5. Scores out of 10 with one line why: first impression, look and feel (UI), ease of use (UX), navigation, speed, trust, value for money, mobile (if used).
6. Compared with competitors I would normally use (name 3, with what they give that CVTara does not, and what CVTara does better).
7. Would I pay? Which plan, and what would make me pay sooner.
8. Is this the best site for me? Yes/no and why, in plain words.
9. Top 5 changes I would make first.

## The ten testers
| Id | Account | Device | Who |
|---|---|---|---|
| t01 | customers[10] (free) | Android phone (Pixel 7), slow 4G | Priya, 21, B.Com fresher in Mysuru. First resume ever, price-sensitive, English is her second language. Builds a resume from a template, runs the ATS check on a real fresher job ad (Accounts Executive), tries to download. |
| t02 | customers[11] (free) | Laptop, Chrome | Rahul, 29, Java developer in Pune with 6 years. Impatient. Uploads his existing resume (paste/upload), checks how well it was read, runs ATS against a real Senior Java Developer posting, judges whether the score is believable. Compares with Naukri resume tools and Jobscan. |
| t03 | customers[12] (Job Hunt) | Laptop | Meena, 38, school teacher moving into HR. Low tech comfort. Needs a resume for an HR Executive job and a cover letter. Uses tailoring and one-click cover letter, then downloads both. |
| t04 | customers[13] (Job Hunt) | Laptop, large screen | Vikram, 44, operations head with 18 years. Judges whether the templates look senior and premium enough, whether Pro at ₹599 is worth it versus Zety, Resume.io, Kickresume. Tries interview practice for a manager role. |
| t05 | customers[14] (Job Hunt) | iPhone 13 | Arjun, 22, final-year B.E. student. Does mock interview practice end to end on the phone, then Tara chat for help, then the tracker for 3 applications. Checks refer-and-earn. |
| t06 | customers[19] (partner manager) | Laptop | Mrs. Kulkarni, placement officer of an engineering college with 400 students. Evaluates partnering: partners page, the application form (once, QA TEST), what the college gets, then the partner portal with her login. Would she sign up her college? |
| t07 | customers[15] (free) | Laptop, keyboard only, then 200% zoom and night mode | Accessibility and clarity tester. Can every main flow be done with the keyboard? Is text readable in night mode? Are buttons and errors clear for someone with weak English? |
| t08 | none, then customers[16] (free) | Laptop, comes from Google | Sceptical shopper. Lands on SEO pages (a city/role resume page, a guide, /pricing/), decides if the site is trustworthy (reviews, company details, refund, privacy), compares prices and benefits with Canva, Naukri, Zety, Resume Worded. Then signs up flow only as far as needed. |
| t09 | owner2 (QA owner, read-only) | Laptop | Busy founder using the console at 10 AM: find a customer, check today's orders and revenue, read the Error Log, check the SEO sprint panel and approvals, download a report. Judges admin UX: can the owner run the business from here quickly? |
| t10 | customers[17] (free) | Phone + laptop, throttled network | Speed tester. Measures how fast key pages feel (first paint, largest paint, layout shift, time until usable) on fast and slow networks, finds the slowest pages and heaviest files, and checks nothing lags while typing in the builder. |
