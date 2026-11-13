# CVTara live test report

Run 2026-11-13T07:46:13.953Z to 2026-11-13T07:55:12.146Z. 365 checks: 352 pass, 8 warn, 5 fail.

## Testers

- **p01-public-desktop**: Visitor on a laptop: first half of all public pages + link check (1 fail, 0 warn of 103)
- **p02-public-phone**: Visitor on a phone: second half of all public pages, redirects, 404, robots (1 fail, 0 warn of 101)
- **p03-signup-auth**: New visitor: sign-up form, email code page, sign-in, wrong password, forgot/reset password, owner door (0 fail, 1 warn of 14)
- **p04-resume-builder**: Job seeker: workspace pages, resume import, builder, versions, writing check, download rules (0 fail, 0 warn of 19)
- **p05-ats-letters**: Job Hunt customer: ATS Match, scans history, one-click tailoring, cover letters (0 fail, 0 warn of 14)
- **p06-interview-tracker**: Fresher with Job Hunt: interview practice (start, answer, submit), question pages, application tracker (0 fail, 1 warn of 12)
- **p07-tara-support**: Customer asking for help: Tara chat, support requests, feedback/review, contact form, notifications, What's new (0 fail, 2 warn of 12)
- **p08-checkout-referrals**: Free customer: packages, price quotes, coupon, checkout up to the payment step (no payment), referrals, wallet, profile (0 fail, 1 warn of 22)
- **p09-b2b-partner**: B2B: partner page + application, partner link tracking, partner portal, and account deletion (2 fail, 0 warn of 10)
- **p10-admin-console**: Owner: sign-in with authenticator code, every console page, tabs, paging, filters, downloads, Error Log (1 fail, 1 warn of 41)
- **p11-security**: Attacker: exposed files, headers, cookies, sign-in guessing, cross-account access, CSRF, XSS, open redirects, uploads, console access (0 fail, 2 warn of 17)

## Pages (264: 258 pass, 2 warn, 4 fail, 0 skipped)

| | Item | Tester | Notes |
|---|---|---|---|
| ❌ | /interview-questions/store-manager/ | p01-public-desktop | page.evaluate: Target page, context or browser has been closed |
| ❌ | /resume-examples/retail/retail-sales-associate/ | p02-public-phone | page.evaluate: Target page, context or browser has been closed |
| ❌ | partner portal screen | p09-b2b-partner | no menu item for "partner" |
| ❌ | console: errors | p10-admin-console | error text visible on the page |
| ⚠️ | /contact.html contact form (signed out) | p07-tara-support | http: 422 POST /contact/message |
| ⚠️ | console: tara | p10-admin-console | sideways scroll 274px |
| ✅ | / | p01-public-desktop |  |
| ✅ | /register | p01-public-desktop |  |
| ✅ | /checkout.html | p01-public-desktop |  |
| ✅ | /customer-guide.html | p01-public-desktop |  |
| ✅ | /partners.html | p01-public-desktop |  |
| ✅ | /templates.html | p01-public-desktop |  |
| ✅ | /blog.html | p01-public-desktop |  |
| ✅ | /about.html | p01-public-desktop |  |
| ✅ | /terms.html | p01-public-desktop |  |
| ✅ | /calculators/contractor-vs-full-time-salary-calculator/ | p01-public-desktop |  |
| ✅ | /calculators/how-to-calculate-gratuity-amount/ | p01-public-desktop |  |
| ✅ | /calculators/education-loan-calculator-roi/ | p01-public-desktop |  |
| ✅ | /calculators/ctc-to-in-hand-salary-calculator/ | p01-public-desktop |  |
| ✅ | /calculators/hourly-to-salary-finder/ | p01-public-desktop |  |
| ✅ | /calculators/gratuity-calculator-online/ | p01-public-desktop |  |
| ✅ | /calculators/notice-period-buyout-calculator/ | p01-public-desktop |  |
| ✅ | /calculators/sip-calculator-monthly-income/ | p01-public-desktop |  |
| ✅ | /calculators/salary-increment-percentage-calculator/ | p01-public-desktop |  |
| ✅ | /calculators/cost-of-living-calculator-by-city/ | p01-public-desktop |  |
| ✅ | /calculators/current-salary-in-resume/ | p01-public-desktop |  |
| ✅ | /calculators/compensation-package-builder/ | p01-public-desktop |  |
| ✅ | /calculators/bonus-net-payout-estimator/ | p01-public-desktop |  |
| ✅ | /calculators/maternity-paternity-leave-pay-calculator/ | p01-public-desktop |  |
| ✅ | /calculators/dual-employment-moonlighting-tax-estimator/ | p01-public-desktop |  |
| ✅ | /calculators/fire-career-milestone-tracker/ | p01-public-desktop |  |
| ✅ | /guides/resume/achievements-bullet-points/ | p01-public-desktop |  |
| ✅ | /guides/resume/skills-section-keywords/ | p01-public-desktop |  |
| ✅ | /guides/resume/action-verbs-list/ | p01-public-desktop |  |
| ✅ | /guides/resume/pdf-vs-word-ats/ | p01-public-desktop |  |
| ✅ | /guides/resume/ats-friendly-format-india/ | p01-public-desktop |  |
| ✅ | /guides/resume/professional-summary-guide/ | p01-public-desktop |  |
| ✅ | /guides/jobs/follow-up-email-template/ | p01-public-desktop |  |
| ✅ | /guides/jobs/how-to-withdraw-application/ | p01-public-desktop |  |
| ✅ | /guides/jobs/job-application-status-meaning/ | p01-public-desktop |  |
| ✅ | /guides/jobs/best-time-to-apply/ | p01-public-desktop |  |
| ✅ | /guides/jobs/application-tracker-method/ | p01-public-desktop |  |
| ✅ | /guides/salary/what-is-good-starting-salary/ | p01-public-desktop |  |
| ✅ | /guides/salary/asking-for-raise-script/ | p01-public-desktop |  |
| ✅ | /guides/salary/negotiation-counter-percentage/ | p01-public-desktop |  |
| ✅ | /guides/salary/is-overtime-taxed-more/ | p01-public-desktop |  |
| ✅ | /guides/interview/thank-you-email-template/ | p01-public-desktop |  |
| ✅ | /guides/interview/questions-to-ask-them/ | p01-public-desktop |  |
| ✅ | /guides/interview/salary-expectations-answer/ | p01-public-desktop |  |
| ✅ | /resume-templates/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/how-to-write/ | p01-public-desktop |  |
| ✅ | /interview-questions/ | p01-public-desktop |  |
| ✅ | /resume-builder/india/ | p01-public-desktop |  |
| ✅ | /ats-checker/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/information-technology/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/engineering/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/accounting-finance/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/sales/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/marketing/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/human-resources/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/education/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/government/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/retail/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/legal/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/maintenance-repair/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/administrative/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/real-estate/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/production/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/business-management/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/security-protective-services/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/hospitality-catering/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/transport-logistics/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/transportation/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/medical/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/sport-fitness/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/social-work/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/construction/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/beauty-wellness/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/student/ | p01-public-desktop |  |
| ✅ | /cover-letter-examples/creative-artistic/ | p01-public-desktop |  |
| ✅ | /interview-questions/software-engineer/ | p01-public-desktop |  |
| ✅ | /interview-questions/python-developer/ | p01-public-desktop |  |
| ✅ | /interview-questions/front-end-developer/ | p01-public-desktop |  |
| ✅ | /interview-questions/data-analyst/ | p01-public-desktop |  |
| ✅ | /interview-questions/data-scientist/ | p01-public-desktop |  |
| ✅ | /interview-questions/it-support-engineer/ | p01-public-desktop |  |
| ✅ | /interview-questions/ui-ux-designer/ | p01-public-desktop |  |
| ✅ | /interview-questions/product-manager/ | p01-public-desktop |  |
| ✅ | /interview-questions/business-analyst/ | p01-public-desktop |  |
| ✅ | /interview-questions/project-manager/ | p01-public-desktop |  |
| ✅ | /interview-questions/operations-manager/ | p01-public-desktop |  |
| ✅ | /interview-questions/mechanical-engineer/ | p01-public-desktop |  |
| ✅ | /interview-questions/automotive-design-engineer/ | p01-public-desktop |  |
| ✅ | /interview-questions/production-engineer/ | p01-public-desktop |  |
| ✅ | /interview-questions/chemical-engineer/ | p01-public-desktop |  |
| ✅ | /interview-questions/civil-engineer/ | p01-public-desktop |  |
| ✅ | /interview-questions/electrical-engineer/ | p01-public-desktop |  |
| ✅ | /interview-questions/site-engineer/ | p01-public-desktop |  |
| ✅ | /interview-questions/construction-supervisor/ | p01-public-desktop |  |
| ✅ | /interview-questions/accountant/ | p01-public-desktop |  |
| ✅ | /interview-questions/chartered-accountant/ | p01-public-desktop |  |
| ✅ | /interview-questions/financial-analyst/ | p01-public-desktop |  |
| ✅ | /interview-questions/bank-jobs/ | p01-public-desktop |  |
| ✅ | /interview-questions/sales-executive/ | p01-public-desktop |  |
| ✅ | /interview-questions/sales-manager/ | p01-public-desktop |  |
| ✅ | /interview-questions/business-development-executive/ | p01-public-desktop |  |
| ✅ | /interview-questions/medical-representative/ | p01-public-desktop |  |
| ✅ | /interview-questions/retail-sales-associate/ | p01-public-desktop |  |
| ✅ | /login | p02-public-phone |  |
| ✅ | /forgot-password | p02-public-phone |  |
| ✅ | /refund.html | p02-public-phone |  |
| ✅ | /pricing/ | p02-public-phone |  |
| ✅ | /contact.html | p02-public-phone |  |
| ✅ | /cover-letters.html | p02-public-phone |  |
| ✅ | /guides.html | p02-public-phone |  |
| ✅ | /privacy.html | p02-public-phone |  |
| ✅ | /calculators/remote-work-savings-calculator/ | p02-public-phone |  |
| ✅ | /calculators/gratuity-calculation-formula/ | p02-public-phone |  |
| ✅ | /calculators/side-hustle-tax-estimator/ | p02-public-phone |  |
| ✅ | /calculators/take-home-salary-calculator/ | p02-public-phone |  |
| ✅ | /calculators/salary-breakup-calculator/ | p02-public-phone |  |
| ✅ | /calculators/paycheck-tax-deduction-calculator/ | p02-public-phone |  |
| ✅ | /calculators/leave-encashment-taxable-formula/ | p02-public-phone |  |
| ✅ | /calculators/resignation-paycheck-estimator/ | p02-public-phone |  |
| ✅ | /calculators/step-up-sip-calculator/ | p02-public-phone |  |
| ✅ | /calculators/mutual-fund-return-calculator/ | p02-public-phone |  |
| ✅ | /calculators/salary-slip-format-excel/ | p02-public-phone |  |
| ✅ | /calculators/expected-salary-interview-calculator/ | p02-public-phone |  |
| ✅ | /calculators/average-salary-job-title/ | p02-public-phone |  |
| ✅ | /calculators/debt-to-income-career-planner/ | p02-public-phone |  |
| ✅ | /calculators/overtime-pay-calculator/ | p02-public-phone |  |
| ✅ | /calculators/expat-net-salary-tax-calculator/ | p02-public-phone |  |
| ✅ | /guides/resume/how-to-write-current-salary/ | p02-public-phone |  |
| ✅ | /guides/resume/no-experience-format/ | p02-public-phone |  |
| ✅ | /guides/resume/gap-in-employment-explanation/ | p02-public-phone |  |
| ✅ | /guides/resume/references-available-upon-request/ | p02-public-phone |  |
| ✅ | /guides/cover-letter/short-email-format/ | p02-public-phone |  |
| ✅ | /guides/resume/fresher-projects-guide/ | p02-public-phone |  |
| ✅ | /guides/jobs/how-many-jobs-to-apply/ | p02-public-phone |  |
| ✅ | /guides/jobs/linkedin-open-to-work-setting/ | p02-public-phone |  |
| ✅ | /guides/jobs/signs-you-will-get-the-job/ | p02-public-phone |  |
| ✅ | /guides/jobs/how-to-reject-job-offer/ | p02-public-phone |  |
| ✅ | /guides/jobs/remote-job-scams-signs/ | p02-public-phone |  |
| ✅ | /guides/salary/counter-offer-email/ | p02-public-phone |  |
| ✅ | /guides/salary/exempt-vs-nonexempt-pay/ | p02-public-phone |  |
| ✅ | /guides/salary/benefits-package-value/ | p02-public-phone |  |
| ✅ | /guides/salary/pay-transparency-laws/ | p02-public-phone |  |
| ✅ | /guides/salary/glassdoor-alternatives/ | p02-public-phone |  |
| ✅ | /guides/interview/tell-me-about-yourself/ | p02-public-phone |  |
| ✅ | /guides/interview/star-method-examples/ | p02-public-phone |  |
| ✅ | /resume-examples/ | p02-public-phone |  |
| ✅ | /cover-letter-examples/ | p02-public-phone |  |
| ✅ | /cover-letter-generator/ | p02-public-phone |  |
| ✅ | /resume-builder/ | p02-public-phone |  |
| ✅ | /resume-words/ | p02-public-phone |  |
| ✅ | /resume-examples/information-technology/ | p02-public-phone |  |
| ✅ | /resume-examples/engineering/ | p02-public-phone |  |
| ✅ | /resume-examples/accounting-finance/ | p02-public-phone |  |
| ✅ | /resume-examples/sales/ | p02-public-phone |  |
| ✅ | /resume-examples/marketing/ | p02-public-phone |  |
| ✅ | /resume-examples/human-resources/ | p02-public-phone |  |
| ✅ | /resume-examples/education/ | p02-public-phone |  |
| ✅ | /resume-examples/government/ | p02-public-phone |  |
| ✅ | /resume-examples/retail/ | p02-public-phone |  |
| ✅ | /resume-examples/legal/ | p02-public-phone |  |
| ✅ | /resume-examples/maintenance-repair/ | p02-public-phone |  |
| ✅ | /resume-examples/administrative/ | p02-public-phone |  |
| ✅ | /resume-examples/real-estate/ | p02-public-phone |  |
| ✅ | /resume-examples/production/ | p02-public-phone |  |
| ✅ | /resume-examples/business-management/ | p02-public-phone |  |
| ✅ | /resume-examples/security-protective-services/ | p02-public-phone |  |
| ✅ | /resume-examples/hospitality-catering/ | p02-public-phone |  |
| ✅ | /resume-examples/transport-logistics/ | p02-public-phone |  |
| ✅ | /resume-examples/transportation/ | p02-public-phone |  |
| ✅ | /resume-examples/medical/ | p02-public-phone |  |
| ✅ | /resume-examples/sport-fitness/ | p02-public-phone |  |
| ✅ | /resume-examples/social-work/ | p02-public-phone |  |
| ✅ | /resume-examples/construction/ | p02-public-phone |  |
| ✅ | /resume-examples/beauty-wellness/ | p02-public-phone |  |
| ✅ | /resume-examples/student/ | p02-public-phone |  |
| ✅ | /resume-examples/creative-artistic/ | p02-public-phone |  |
| ✅ | /resume-examples/information-technology/software-engineer/ | p02-public-phone |  |
| ✅ | /resume-examples/information-technology/python-developer/ | p02-public-phone |  |
| ✅ | /resume-examples/information-technology/front-end-developer/ | p02-public-phone |  |
| ✅ | /resume-examples/information-technology/data-analyst/ | p02-public-phone |  |
| ✅ | /resume-examples/information-technology/data-scientist/ | p02-public-phone |  |
| ✅ | /resume-examples/information-technology/it-support-engineer/ | p02-public-phone |  |
| ✅ | /resume-examples/creative-artistic/ui-ux-designer/ | p02-public-phone |  |
| ✅ | /resume-examples/business-management/product-manager/ | p02-public-phone |  |
| ✅ | /resume-examples/business-management/business-analyst/ | p02-public-phone |  |
| ✅ | /resume-examples/business-management/project-manager/ | p02-public-phone |  |
| ✅ | /resume-examples/business-management/operations-manager/ | p02-public-phone |  |
| ✅ | /resume-examples/engineering/mechanical-engineer/ | p02-public-phone |  |
| ✅ | /resume-examples/engineering/automotive-design-engineer/ | p02-public-phone |  |
| ✅ | /resume-examples/engineering/production-engineer/ | p02-public-phone |  |
| ✅ | /resume-examples/engineering/chemical-engineer/ | p02-public-phone |  |
| ✅ | /resume-examples/engineering/civil-engineer/ | p02-public-phone |  |
| ✅ | /resume-examples/engineering/electrical-engineer/ | p02-public-phone |  |
| ✅ | /resume-examples/construction/site-engineer/ | p02-public-phone |  |
| ✅ | /resume-examples/construction/construction-supervisor/ | p02-public-phone |  |
| ✅ | /resume-examples/accounting-finance/accountant/ | p02-public-phone |  |
| ✅ | /resume-examples/accounting-finance/chartered-accountant/ | p02-public-phone |  |
| ✅ | /resume-examples/accounting-finance/financial-analyst/ | p02-public-phone |  |
| ✅ | /resume-examples/government/bank-jobs/ | p02-public-phone |  |
| ✅ | /resume-examples/sales/sales-executive/ | p02-public-phone |  |
| ✅ | /resume-examples/sales/sales-manager/ | p02-public-phone |  |
| ✅ | /resume-examples/sales/business-development-executive/ | p02-public-phone |  |
| ✅ | /resume-examples/sales/medical-representative/ | p02-public-phone |  |
| ✅ | /reset-password/<bad token> | p03-signup-auth |  |
| ✅ | workspace: dashboard | p04-resume-builder |  |
| ✅ | workspace: resumes | p04-resume-builder |  |
| ✅ | workspace: builder | p04-resume-builder |  |
| ✅ | workspace: account | p04-resume-builder |  |
| ✅ | workspace: feedback | p04-resume-builder |  |
| ✅ | builder opens the imported resume | p04-resume-builder | first field: PG84bYi3IEpf2YUYcA7NjyinSoOKx91yur0A9bHM |
| ✅ | workspace: match | p05-ats-letters |  |
| ✅ | workspace: letter | p05-ats-letters |  |
| ✅ | workspace: interview | p06-interview-tracker |  |
| ✅ | workspace: tracker | p06-interview-tracker |  |
| ✅ | /interview-questions/ | p06-interview-tracker |  |
| ✅ | /workspace.html?whats-new=1 | p07-tara-support |  |
| ✅ | /customer-guide.html | p07-tara-support |  |
| ✅ | /pricing/ (signed out) | p08-checkout-referrals |  |
| ✅ | workspace: billing | p08-checkout-referrals |  |
| ✅ | workspace: account | p08-checkout-referrals |  |
| ✅ | workspace: referrals | p08-checkout-referrals |  |
| ✅ | workspace: wallet | p08-checkout-referrals |  |
| ✅ | workspace: referral-history | p08-checkout-referrals |  |
| ✅ | /partners.html | p09-b2b-partner |  |
| ✅ | console: dashboard | p10-admin-console |  |
| ✅ | console: assistant | p10-admin-console |  |
| ✅ | console: approvals | p10-admin-console | 3 tabs opened<br>next page ok |
| ✅ | console: people | p10-admin-console | next page ok |
| ✅ | console: orders | p10-admin-console |  |
| ✅ | console: feedback | p10-admin-console |  |
| ✅ | console: referral | p10-admin-console | next page ok |
| ✅ | console: partners | p10-admin-console | 6 tabs opened |
| ✅ | console: resumelib | p10-admin-console |  |
| ✅ | console: letterlib | p10-admin-console |  |
| ✅ | console: sitedesign | p10-admin-console |  |
| ✅ | console: bloghub | p10-admin-console | next page ok |
| ✅ | console: desk | p10-admin-console |  |
| ✅ | console: whatsnew | p10-admin-console | next page ok |
| ✅ | console: interviews | p10-admin-console |  |
| ✅ | console: growth | p10-admin-console | 9 tabs opened<br>next page ok<br>download: cvtara-agent-log-20261113-132052.csv |
| ✅ | console: promos | p10-admin-console |  |
| ✅ | console: campaigns | p10-admin-console |  |
| ✅ | console: outreach | p10-admin-console | next page ok |
| ✅ | console: mailer | p10-admin-console | next page ok |
| ✅ | console: pricing | p10-admin-console | 5 tabs opened |
| ✅ | console: business | p10-admin-console |  |
| ✅ | console: reports | p10-admin-console | 14 tabs opened |
| ✅ | console: speed | p10-admin-console |  |
| ✅ | console: settings | p10-admin-console |  |
| ✅ | console: setup | p10-admin-console |  |
| ✅ | console: users | p10-admin-console |  |
| ✅ | console: analytics | p10-admin-console | next page ok |
| ✅ | console: reviews | p10-admin-console |  |
| ✅ | console: design | p10-admin-console |  |
| ✅ | console: features | p10-admin-console |  |
| ✅ | console: templates | p10-admin-console |  |
| ✅ | console: letters | p10-admin-console |  |
| ✅ | console: overview | p10-admin-console | next page ok |
| ✅ | console: emails | p10-admin-console |  |

## Functions (101: 94 pass, 6 warn, 1 fail, 0 skipped)

| | Item | Tester | Notes |
|---|---|---|---|
| ❌ | partner portal numbers | p09-b2b-partner | GET /api/partner → 404 {"ok":false,"error":"Record or page not found."}<br>GET /api/partner answered 404: {"ok":false,"error":"Record or page not found."} |
| ⚠️ | sign out | p03-signup-auth | http: 401 GET /api/service-requests<br>http: 401 GET /api/downloads |
| ⚠️ | submit for the automatic evaluation | p06-interview-tracker | status Please answer every question in at least a sentence (question 3, 4, 5).<br>http: 422 POST /api/interviews/dec36204-6262-4c97-9ab6-b32a1c97486d/submit |
| ⚠️ | Tara chat window in the UI | p07-tara-support | no Tara button found on the workspace |
| ⚠️ | add-ons quote (Expert Review / Profile Boost) | p08-checkout-referrals | complete-kit: The selected plan is invalid.<br>expert-review: 499<br>profile-boost: 699<br>addon-10: The selected plan is invalid.<br>addon-20: The selected plan is invalid.<br>addon-30: The selected plan is invalid.<br>http: 422 POST /api/checkout/quote<br>http: 422 POST /api/checkout/quote<br>http: 422 POST /api/checkout/quote<br>http: 422 POST /api/checkout/quote |
| ⚠️ | security headers on pages | p11-security | CSP allows 'unsafe-inline' scripts (weakens XSS protection) |
| ⚠️ | sign-in guessing is slowed down (6 wrong passwords) | p11-security | not blocked after 6 tries<br>no lock-out after 6 wrong passwords |
| ✅ | sign-up: empty form shows errors | p03-signup-auth |  |
| ✅ | sign-up: passwords that do not match | p03-signup-auth |  |
| ✅ | sign-up: password under 8 characters | p03-signup-auth |  |
| ✅ | sign-up: email already used | p03-signup-auth |  |
| ✅ | sign-up: new account reaches the email-code page | p03-signup-auth | landed on /verify-email |
| ✅ | email-code page: wrong code is refused with a message | p03-signup-auth |  |
| ✅ | email-code page: resend code button | p03-signup-auth |  |
| ✅ | sign-in with a wrong password is refused | p03-signup-auth |  |
| ✅ | sign-in with the right password opens the workspace | p03-signup-auth |  |
| ✅ | forgot password form accepts an email | p03-signup-auth |  |
| ✅ | owner door refuses a customer account | p03-signup-auth |  |
| ✅ | customer cannot open the console scripts | p03-signup-auth |  |
| ✅ | sign in | p04-resume-builder |  |
| ✅ | resume import from pasted text (PDF/Word reader path) | p04-resume-builder | read: 2 jobs, 1 education, skills 57 |
| ✅ | too little text is refused politely | p04-resume-builder |  |
| ✅ | resume upload button in the UI (PDF file) | p04-resume-builder |  |
| ✅ | edit and save the resume (new revision) | p04-resume-builder |  |
| ✅ | resume versions list | p04-resume-builder |  |
| ✅ | resume quality score | p04-resume-builder | score 89 |
| ✅ | set as primary resume | p04-resume-builder |  |
| ✅ | check my writing | p04-resume-builder | 0 writing notes |
| ✅ | free account download is gated (no clean PDF without a plan) | p04-resume-builder | export answered 200 application/pdf |
| ✅ | account data export (my data) | p04-resume-builder |  |
| ✅ | free account keeps one resume (second import asks to replace) | p04-resume-builder |  |
| ✅ | delete a resume (downloaded resumes are kept by design) | p04-resume-builder | kept: Saved resumes stay in your account so your download credits stay accurate. Edit this resume instead, or contact support  |
| ✅ | sign in | p05-ats-letters |  |
| ✅ | plan shows Job Hunt (allowance) | p05-ats-letters | {"ok":true,"plan":"job-hunt","paid":true,"limits":{"type":"plan","name":"Job Hunt","audience":"all","tagline":"Tailor your resume and a cover letter for each jo |
| ✅ | resume ready for checking | p05-ats-letters |  |
| ✅ | "Is this the right job?" reads pasted job text | p05-ats-letters | title "" company "" |
| ✅ | ATS Match scan (pasted job text) | p05-ats-letters | score 62 |
| ✅ | ATS scans history and one scan | p05-ats-letters |  |
| ✅ | unknown resume is refused | p05-ats-letters |  |
| ✅ | one-click tailoring for this job | p05-ats-letters | summary,skills,experience,notShown,check,left |
| ✅ | ATS Match screen in the UI (paste job, run check) | p05-ats-letters |  |
| ✅ | one-click cover letter for this job | p05-ats-letters | 2284 chars |
| ✅ | save, list and delete a cover letter | p05-ats-letters |  |
| ✅ | paid download of the resume works (Job Hunt) | p05-ats-letters | 200 application/pdf 23941 bytes |
| ✅ | sign in | p06-interview-tracker |  |
| ✅ | interview roles list | p06-interview-tracker | 0 roles |
| ✅ | sample questions for a role | p06-interview-tracker | 20 questions shown of 20 |
| ✅ | start a mock interview | p06-interview-tracker |  |
| ✅ | answer two questions | p06-interview-tracker |  |
| ✅ | interview history | p06-interview-tracker |  |
| ✅ | interview practice screen in the UI | p06-interview-tracker |  |
| ✅ | add, move and delete a job application | p06-interview-tracker |  |
| ✅ | sign in | p07-tara-support |  |
| ✅ | Tara chat status | p07-tara-support | {"enabled":true,"nudges":true,"name":"Tara","welcome":"Hi, I'm Tara, your CVTara career assistant. I can help you improv |
| ✅ | Tara chat: ask about my plan | p07-tara-support | {"ok":true,"conversationId":"8c3aad7d-bebf-42bf-bb49-4ba8a79dcad4","message":{"id":"b1081a1b-b29b-4ac4-b0d6-5c1a52b4e8af","role":"assistant","content":"Hi QA, you're on the **Job Hunt** plan, and it's |
| ✅ | Tara chat: writing help (bullets) | p07-tara-support |  |
| ✅ | Tara conversations list and delete | p07-tara-support |  |
| ✅ | support request from the workspace | p07-tara-support | 200 {"ok":true,"id":"0d45b1fb","reference":"S-0D45B1","message":"Thank you \u2014 your feedback reached our team. You can follow it under My requests."} |
| ✅ | leave a review (feedback) | p07-tara-support |  |
| ✅ | notifications and What's new | p07-tara-support |  |
| ✅ | referral link /r/<code> remembers the referrer (signed out) | p08-checkout-referrals | 302 → https://cvtara.com/register |
| ✅ | sign in | p08-checkout-referrals |  |
| ✅ | packages and prices (business-public) | p08-checkout-referrals | free ₹0, quick ₹99, job-hunt ₹299, pro ₹599, complete-kit ₹1299, expert-review ₹499, profile-boost ₹699, addon-10 ₹149, addon-20 ₹249, addon-30 ₹399<br>gateways razorpay,phonepe |
| ✅ | price quote: quick | p08-checkout-referrals | subtotal 99 discount 0 total 99 |
| ✅ | price quote: job-hunt | p08-checkout-referrals | subtotal 299 discount 0 total 299 |
| ✅ | price quote: pro | p08-checkout-referrals | subtotal 599 discount 0 total 599 |
| ✅ | wrong coupon is refused with a message | p08-checkout-referrals | {"ok":false,"error":"This coupon is invalid, expired or unavailable for this plan."} |
| ✅ | checkout page up to the payment button (NO payment) | p08-checkout-referrals | pay button: "Continue to secure payment" (not clicked) |
| ✅ | checkout start creates an unpaid gateway order (then abandoned) | p08-checkout-referrals | ok,id,provider,orderId,keyId,amount,currency,test |
| ✅ | orders, allowance, credits history | p08-checkout-referrals |  |
| ✅ | refund without a paid order is refused | p08-checkout-referrals |  |
| ✅ | referrals, rewards and wallet | p08-checkout-referrals |  |
| ✅ | withdrawal below the minimum is refused | p08-checkout-referrals |  |
| ✅ | profile update (name, target role) | p08-checkout-referrals |  |
| ✅ | security status (sign-in codes) | p08-checkout-referrals |  |
| ✅ | partner application form (placement cell) | p09-b2b-partner | 422 {"ok":false,"error":"We already have your application and will reply by email."} |
| ✅ | partner form in the UI shows its fields | p09-b2b-partner | 9 fields |
| ✅ | bot honeypot on the partner form | p09-b2b-partner |  |
| ✅ | partner link /p/qa-test-partner tracks and redirects | p09-b2b-partner | 302 → https://cvtara.com/register |
| ✅ | partner manager signs in | p09-b2b-partner |  |
| ✅ | partner invites and payout details | p09-b2b-partner |  |
| ✅ | customer deletes their account (password + DELETE) | p09-b2b-partner |  |
| ✅ | owner sign-in (password + authenticator code) | p10-admin-console |  |
| ✅ | console menu lists 37 pages | p10-admin-console | dashboard, assistant, approvals, people, orders, feedback, referral, partners, resumelib, letterlib, sitedesign, bloghub, desk, whatsnew, interviews, growth, promos, campaigns, outreach, mailer, tara, pricing, business, reports, speed, errors, settings, setup, users, analytics, reviews, design, features, templates, letters, overview, emails |
| ✅ | find the QA test customers in Users (search) | p10-admin-console |  |
| ✅ | console scripts load for the owner | p10-admin-console |  |
| ✅ | secret files are not downloadable | p11-security |  |
| ✅ | session cookies are Secure + HttpOnly | p11-security |  |
| ✅ | console and admin API refuse visitors | p11-security |  |
| ✅ | sign in as tester B | p11-security |  |
| ✅ | tester B creates a private resume | p11-security | id 81805dcc-13cb-4c2d-9742-8be1a003104f |
| ✅ | sign in as tester A (attacker) | p11-security |  |
| ✅ | cross-account: A cannot read, edit, delete, scan or export B's resume | p11-security |  |
| ✅ | POST without CSRF token is refused (signed-in cookie, no token) | p11-security | answered 419 |
| ✅ | stored XSS: script in name and resume is shown as text | p11-security |  |
| ✅ | reflected XSS in query strings | p11-security |  |
| ✅ | open redirect after sign-in (next=) | p11-security |  |
| ✅ | uploads: a script disguised as a resume is refused or made harmless | p11-security | import of PHP text answered 200 |
| ✅ | customer cannot reach owner features | p11-security |  |
| ✅ | price cannot be changed from the browser | p11-security | quote total 299 |
| ✅ | payment webhooks reject unsigned calls | p11-security | /webhooks/razorpay → 403<br>/webhooks/phonepe → 403 |
