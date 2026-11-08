# CVTara metrics

Generated from `app/Services/Metrics.php` (`php artisan cvtara:metrics-doc`). Do not edit by hand.

Periods run in India time. Money is in rupees; refunds and taxes are as stated per line. A number shown as "not tracked" is unknown, not zero.

| Metric | Unit | Definition |
|---|---|---|
| Registrations (`registrations`) | count | New customer accounts created in the period (console accounts excluded). |
| Verified sign-ups (`verified`) | count | Accounts whose email was confirmed in the period. |
| Paying customers (`paidCustomers`) | count | Different customers with at least one paid purchase in the period (refunded purchases included; referral rewards and ₹0 grants excluded). |
| First-time buyers (`newCustomers`) | count | Paying customers in the period who had never paid before it. |
| Paid orders (`orders`) | count | Paid purchases in the period, counted by payment date. |
| Gross collections (`grossPaise`) | money | Total charged for paid purchases in the period, including tax, before refunds. |
| Refunds (`refundedPaise`) | money | Amount refunded on the purchases paid in the period. |
| Net revenue (`netPaise`) | money | Gross collections minus refunds. Gateway fees are not taken off (not recorded). |
| Payment success rate (`paymentSuccessRate`) | percent | Paid ÷ (paid + failed) checkouts started in the period. Checkouts still open are left out. Not tracked when nobody finished a checkout. |
| Refund requests (`refundRequests`) | count | Refund requests made in the period, whatever their outcome. |
| Resumes built (`resumesBuilt`) | count | Resumes saved for the first time in the period (new drafts and job versions). |
| ATS scans (`atsScans`) | count | Finished ATS checks in the period. |
| Cover letters (`coverLetters`) | count | Cover letters saved for the first time in the period. |
| Organic visitors (`organicVisitors`) | count | Different visitors arriving from search engines (Google, Bing and others), from the site's own visit log. Not tracked when site analytics is off. |
| Marketing visitors (`marketingVisitors`) | count | Different visitors from email, social, referral links and campaigns (everything except search, direct and internal). Not tracked when site analytics is off. |
| Support backlog (`supportBacklog`) | count | Support tickets open right now (new, seen or being handled), whatever the period. |
| AI service success (`aiSuccessRate`) | percent | Share of AI requests in the last 24 hours that got an answer. Not tracked with no requests. |
| Approvals waiting (`approvalsWaiting`) | count | SEO changes, refunds and tasks waiting for a decision right now. |
| Open SEO and Growth tasks (`tasksOpen`) | count | Tasks not yet verified, rejected or cancelled, right now. |
| Overdue tasks (`tasksOverdue`) | count | Tasks past their due date and not finished, right now. |
