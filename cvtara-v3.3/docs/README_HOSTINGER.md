# CVTara v3.12 — Hostinger package

Before announcing: make one real test payment, send a test email, add the cron job and check the site on a real phone. See `LAUNCH_READINESS.md`.

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

## Speed, traffic and tools
1. **Ready-made pages:** the installer builds them. After every later upload of a new version open Owner console → **Speed & traffic → Rebuild all pages now** (or run `php artisan cvtara:prebuild`). If an old version's pages are still served, press **Clear ready-made pages** and rebuild. The `public_html/_pages` folder must be writable by PHP.
2. **Busy mode:** leave on **Off**; choose **Automatic** to protect the server during a rush (default threshold 2.5 load per CPU core) or **On** to pause ATS reviews, job-link reading and PDF/Word creation manually.
3. **Job links:** pasting a job link is OFF for customers by default (they paste the job text or upload a PDF). To offer it, open Speed & traffic → *Job-link pasting for customers* and choose On. For portals that refuse automatic reading, set up the *Job link reader* (ScrapingBee, ZenRows or a custom service) and press **Test the reader**. Check each job site's terms before relying on this.
4. **Support Inbox:** customers' feedback (with screenshots up to 5 × 2 MB) arrives in Owner console → Support Inbox. Screenshots are stored privately under `cvtara_app/storage/app/support`; include that folder in backups.
5. **PHP limits:** `upload_max_filesize` and `post_max_size` should be at least 12 MB (five 2 MB screenshots). Hostinger's default is higher.
6. **Cloudflare (free plan)** in front of the site is still the best defence against floods and bots; do not cache HTML for signed-in users.



## Updating an existing site
Upload the update ZIP to the domain folder (the one that contains `public_html` and `cvtara_app`) and extract it over the existing files. It never touches `.env`, `storage` or `vendor`. Then open Owner console → Speed & traffic → **Clear ready-made pages**, then **Rebuild all pages now**.
