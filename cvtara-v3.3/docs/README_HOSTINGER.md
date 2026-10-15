# CVTara v3.4 — Hostinger package

**Private acceptance build.** Live provider acceptance (Cashfree checkout, email, Twilio, Cashfree Payouts), Hostinger staging tests and real-device checks are still pending — see `LAUNCH_READINESS.md`.

## Install
1. Back up any existing site. Do not install over an existing CVTara database.
2. Upload this ZIP to the domain folder that **contains** `public_html` (not inside it) and extract it. `INSTALL_KEY.txt` stays outside the web root.
3. Enable SSL and PHP 8.3+ with `pdo_mysql, mbstring, openssl, fileinfo, curl, xml, zip, gd`. Create an empty MySQL/MariaDB database + user.
4. Open **`https://cvtara.com/install.php`** — the single setup link. Enter the key from `INSTALL_KEY.txt`, the HTTPS domain, DB credentials, owner details (password ≥ 12 chars) and the **support@cvtara.com** mailbox password (create the mailbox in hPanel → Emails first; SMTP defaults to smtp.hostinger.com:465). Tick "index now" only when every page has been reviewed. The installer writes the `.env`, migrates, creates the owner, switches the mailer on and locks itself.
5. Delete `public_html/install.php` and `INSTALL_KEY.txt`. Confirm `/install.php` is locked.
6. Sign in at `/login`. Owner console `/admin.html`, customer workspace `/workspace.html`.

## First 30 minutes after install (Admin)
1. **Setup & health** → save business details, review the checklist.
2. **Integrations** → Payment gateway **Cashfree** (App ID + Secret key, mode Test or Live) and email (SMTP or Resend). In the Cashfree dashboard add the webhook URL `https://YOUR-DOMAIN/webhooks/cashfree` for payment and refund events; the Secret key also verifies webhooks. Razorpay is still available as a second gateway (`/webhooks/razorpay`).
3. **Pricing & add-ons** → confirm plan prices, validity, limits and perks.
4. **Offers & popup** → choose the delay (default 40 s) and add your own messages.
5. **Email automation** → review templates (welcome, receipt, expiry, follow-up and password reset are on by default).
6. Cron every minute: `/usr/bin/php /home/ACCOUNT/domains/YOUR-DOMAIN/cvtara_app/private-cron.php`.

## Preview / demo (staging only)
Add `CVTARA_PREVIEW=true` to `cvtara_app/.env`, run `php artisan cvtara:demo`, then open `/preview` for the three-view shell (Website · Customer dashboard · Admin dashboard) with demo logins. Remove the flag and demo users before going live.

## Notes
- Checkout uses Cashfree (Razorpay optional). Cashfree Payouts for referral withdrawals need provider acceptance and the payout policy (Admin → Setup & health). All payments are final and non-refundable.
- Keep a backup of `cvtara_app/.env` (APP_KEY encrypts provider secrets and payout details).
- English only. No job, ranking, uptime or error-free-operation guarantee is made.


## After installing (10 minutes)
1. **Cron:** add a cron job every minute — the exact command is shown in Owner console → Settings → Setup & health → Setup tools. It sends e-mails and runs the daily health check.
2. **Payments:** Settings → Integrations → Payments → choose Cashfree, add the App ID and Secret key, then switch Payments on and make one small live test payment.
3. **Mail deliverability:** add the SPF and DKIM records Hostinger shows for `support@cvtara.com` to the cvtara.com DNS; use User Mailer → "Send me a test email".
4. **Review every page:** Settings → Footer & policies → *All website URLs* (tick each as you confirm it). Adjust the policy texts if your lawyer asks.
5. **Go live for search:** Blog & SEO → turn on "Let search engines index", save, and submit `https://cvtara.com/sitemap.xml` in Google Search Console.
6. Check **Setup & health** is green. It re-checks itself every day.
