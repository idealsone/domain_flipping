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
