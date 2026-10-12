# CVTara on Hostinger "Unlimited" plan: step-by-step plan

Written for a non-technical owner. "Me" = the AI assistant working in Cursor. "You" = the owner.

## 0. Things to confirm first (5 minutes, in hPanel)

I cannot see your Hostinger account, so please check these and tell me the answers. Hostinger's own plan names change over time, so confirm the real limits rather than trusting the name "Unlimited".

| Check | Where in hPanel | Needed for CVTara |
|---|---|---|
| PHP version can be set to **8.3 or higher** | Websites > Manage > PHP Configuration | Required (the app will not run on older PHP) |
| PHP extensions on: `pdo_mysql, mbstring, openssl, fileinfo, curl, xml, zip, gd` | same page, Extensions tab | Required |
| **MySQL database** can be created | Databases > Management | Required |
| **Cron jobs** allow "every minute" | Advanced > Cron Jobs | Required for emails, follow-ups, reminders |
| Free **SSL** is active on the domain | Security > SSL | Required (payments and login need HTTPS) |
| **SSH access** available (optional) | Advanced > SSH Access | Nice to have, not required |
| Daily **outgoing email limit** of the plan | Emails (or Hostinger help article) | Decides how many marketing emails per day |

If cron cannot run every minute, tell me. I will change the schedule and some emails will arrive a few minutes later.

## 1. Two stages, same package

1. **Staging first**: a private test copy on a subdomain such as `staging.yourdomain.com` with its own empty database. Nobody sees it but you. Payments in **test mode**.
2. **Live later**: only after the checklist in section 5 passes. The same package is installed on the real domain with a **new** empty database. Never install over the staging data.

The Unlimited plan allows several websites and databases, so staging costs nothing extra.

## 2. How the code gets to Hostinger

There are two routes. Both use the same single file: `public_html/.cvtara-payload.zip` plus the small installer.

- **Route A, Hostinger MCP (what you want):** once you connect the Hostinger MCP in Cursor, I can try to upload the package and run the steps from here. I will check which tools your MCP offers first. If it can only do static sites or WordPress, it cannot install this Laravel app. Then I switch to Route B and give you exact click-by-click steps.
- **Route B, File Manager (always works):** you upload one ZIP in hPanel > File Manager, click Extract, open `/install.php` in the browser, fill in a form. About 15 minutes. No coding.

Either way the first install needs the one-time install key. It stays on your computer and you type it into the installer. **Do not paste it into chat or into the MCP.** After install, the installer locks itself.

### Connecting the MCP safely
- Create the Hostinger API token in hPanel, and paste it only into Cursor's MCP settings, not into chat.
- Give it a name like "cursor-deploy". Revoke it from hPanel when we finish the launch.
- I will ask you before any step that changes the live site, deletes anything, or touches DNS.

## 3. Staging install steps (what each of us does)

| # | Step | Who |
|---|---|---|
| 1 | Create subdomain `staging.yourdomain.com`, turn on SSL | You |
| 2 | Set PHP 8.3 and the extensions in section 0 | You |
| 3 | Create an empty MySQL database + user. Save the 3 values (name, user, password) | You |
| 4 | Upload and extract the package (Route A or B) | Me or You |
| 5 | Open `/install.php`, enter the key, domain, database values, and your owner email + strong password | You |
| 6 | Delete `install.php` and confirm `/install.php` no longer works | Me or You |
| 7 | Add the cron job: every minute, `php .../cvtara_app/private-cron.php` (I give you the exact line with your account path) | You |
| 8 | Smoke test with the list in section 4 | Me, with you checking on your phone |

## 4. Staging test list

1. Landing page loads. Run the ATS check with a real PDF and a Word file, with and without a job description.
2. Register, land in the builder with the resume filled in and the "Tailor to a job" panel visible.
3. Packages on the landing page match Admin > Plans & payments. Change a price, refresh, confirm it changed.
4. Admin > Funnel & packages shows your test visit.
5. Admin > System & growth: switch maintenance on/off from your phone. Open the site from another network to see the maintenance page. The error log loads.
6. Email: add SMTP under Integrations, send a test mail to yourself, and check the inbox and the spam folder.
7. Payment: Razorpay **test** payment, then check access is granted only after verification. Test the webhook.
8. Backup and restore: take a Hostinger backup, then confirm you know how to restore it.
9. Open on a real Android phone and an iPhone.

## 5. Before going live (not optional)

- Razorpay **live** account approved, and one real small payment made and refunded.
- Business name, address, support email and refund terms filled in under Footer & policies (these also appear in marketing emails).
- Privacy policy, terms and refund pages reviewed by you (a lawyer if you can).
- SMTP working and your domain's SPF, DKIM and DMARC records set, so emails do not land in spam.
- Backups turned on, and a restore tested.
- Payouts and the referral programme stay **off** until you have written rules for identity, tax and fraud checks. The landing page already leaves referrals out.
- Decide your final prices (they are editable in admin, so this does not block anything).

## 6. After you go live

- Hostinger backups stay on. I recommend a weekly manual download of the database as well.
- Updates: I rebuild the package, you or I upload it and click "update" in the installer or run the migrations. Your data is not touched. I will always back up first.
- Marketing mail: keep each sender's daily cap inside what your plan allows, and use a dedicated sending service if you grow past it.

## 7. What I need from you to start

1. Your real domain name (and the staging name you prefer).
2. The answers from section 0, or a screenshot of the PHP page and cron page.
3. Confirmation that you will connect the Hostinger MCP (or are happy with Route B).
4. Your new prices, if you have decided them. If not, the current ones stay.


## 8. Cashfree payment setup (you chose Cashfree)

Cashfree checkout is built in and passes my server-side tests against a simulated Cashfree. It has not yet talked to a real Cashfree account, so the sandbox test below is required before any live payment.

**What you do in the Cashfree dashboard (merchant.cashfree.com)**
1. Finish business verification (KYC) so you can go live. You can start in **Sandbox** straight away.
2. In Developers > API Keys, copy the **App ID (Client ID)** and **Secret key** for Sandbox first. Never paste them in chat. They go only into the admin form below.
3. In Developers > Webhooks, add the URL `https://YOUR-DOMAIN/webhooks/cashfree` and tick payment and refund events. Cashfree signs webhooks with the same secret key, so no separate webhook secret is needed.
4. For production, your live domain must be whitelisted in Cashfree (their docs call it domain whitelisting; the menu name may differ) or the checkout window will not open.

**What you do in CVTara admin**
1. Admin > Integrations > Payments: Provider = Cashfree, Mode = test, paste Client ID and Secret, save.
2. Switch the Payments toggle on.
3. Make a test purchase from `/checkout.html` (enter a 10-digit Indian mobile number). Use Cashfree's sandbox test cards/UPI from their docs.

**Sandbox acceptance checklist (I will run this with you on staging)**
- Test payment succeeds, you land in your account and the plan shows as active.
- Closing the popup without paying grants nothing and you can retry.
- A failed payment grants nothing.
- Refresh/back after paying does not give access twice.
- Webhook works: pay, close the page before it returns, and the purchase still turns to paid. A refund from the Cashfree dashboard marks the purchase refunded.
- Admin > Funnel & packages counts the test purchase as a test (not revenue).

**Going live:** change Mode to live, replace with production keys, confirm the domain is whitelisted, make one real small payment, refund it, then open to customers. Referral cash payouts (Cashfree Payouts) stay off.

Razorpay stays in the code as a fallback only. If you never use it, nothing else is needed.
