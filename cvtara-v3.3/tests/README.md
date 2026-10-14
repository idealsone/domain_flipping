# Tests

`audit-harness/` holds the scripts used to verify v3.3 (see its README for setup):

- `scenario_v33_plans_addons_admin.py` — ~50 checks: plans, add-ons, stacking, best-plan, ATS-scan limits, primary/variant resumes, partial refund, disputes, coupons, referral Pro gating, user blocking, account export/delete, admin plan editor.
- `browser_*.cjs` — Playwright runs: click every control on every public, customer and admin page; sign-up → login → checkout; the 40-second rotating popup; the three-view `/preview` shell.

They stub Razorpay, Twilio, Resend and OpenAI through `FakeProviders_AppServiceProvider.php`; no live provider is contacted.
