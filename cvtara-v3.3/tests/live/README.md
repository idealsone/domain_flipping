# Live site test (10 testers)

Ten scripted testers use cvtara.com in parallel like real people and the owner would, each in its own browser,
paced (about 2 s between steps, 15 s between tester start-ups) so the host is not overloaded.

| Tester | Who it acts like | What it covers |
|---|---|---|
| p01 | Visitor on a laptop | First half of every public page in the sitemap + extra pages; status, blank page, H1, meta, canonical, sideways scroll, broken images, JS errors, "no AI wording"; every internal link not in the sitemap |
| p02 | Visitor on a phone | Second half of the pages on an iPhone screen; /pricing redirects, 404, robots, sitemap, llms.txt, bad referral/partner links |
| p03 | New fresher | Sign-up form errors, real sign-up to the email-code page, wrong code, resend, sign-out, wrong password, sign-in, forgot/reset password, owner door refuses customers |
| p04 | Job seeker (free) | Workspace pages, resume import, upload button, edit/save, versions, quality score, primary, writing check, free download rule, one-resume rule, my-data export, delete |
| p05 | Job Hunt customer | ATS Match (read job, scan, history), tailoring, ATS screen in the UI, one-click cover letter, letter save/list/delete, paid PDF download |
| p06 | Fresher with Job Hunt | Interview roles, sample questions, start, answer, submit for evaluation, history, interview screen, application tracker add/move/delete |
| p07 | Customer asking for help | Contact form, Tara chat (2 questions), Tara window, conversations, support request + reply, review, notifications, What's new, help centre |
| p08 | Free customer shopping | Referral link, pricing page, packages and prices, quotes for every pack, fake coupon, add-ons, checkout page up to the pay button (never clicked), checkout start (unpaid order, removed later), orders/credits, refund and withdrawal refusals, profile, security |
| p09 | B2B partner + leaving customer | Partners page and application, honeypot, partner link tracking + open-redirect check, partner portal, account deletion |
| p10 | Owner | Sign-in with authenticator code, every console page in the menu, tabs, next page, downloads, Error Log text, Users search, console scripts |

Safety: accounts are `cvtara-qa-*@example.com` (a domain that never receives mail) and on the email suppression list,
so no email is sent to them; no real payment is made; QA records are removed by `server/qa_cleanup.php`.
Testers 5-7 get a ₹0 manual Job Hunt grant so paid tools can be tested; cleanup deletes those grants.

Steps on the live site (each through a temporary account cron that fetches `server/qa_get.sh` at a pinned commit):
1. `setup` → creates the accounts and prints passwords + authenticator secret in the cron output only; save them as `tests/live/.accounts.json` (git-ignored).
2. `node tests/live/run_all.cjs` → `results/report.md`.
3. `logs <start time>` → Error Log rows and laravel.log lines written during the run.
4. `cleanup` → removes every QA account and record. Delete the temporary cron.

Try locally: `python3 tests/live/local.py` (throw-away SQLite copy), then `BASE=<printed> QA_ACCOUNTS=tests/live/.accounts.local.json node tests/live/run_all.cjs`.
