# CVTara v3.3 — Hostinger package

**Private acceptance build.** Live provider acceptance (Razorpay, email, Twilio, Cashfree Payouts), Hostinger staging tests and real-device checks are still pending — see `LAUNCH_READINESS.md`.

## Install
1. Back up any existing site. Do not install over an existing CVTara database.
2. Upload this ZIP to the domain folder that **contains** `public_html` (not inside it) and extract it. `INSTALL_KEY.txt` stays outside the web root.
3. Enable SSL and PHP 8.3+ with `pdo_mysql, mbstring, openssl, fileinfo, curl, xml, zip, gd`. Create an empty MySQL/MariaDB database + user.
4. Open `https://YOUR-DOMAIN/install.php`, enter the key from `INSTALL_KEY.txt`, the HTTPS domain, DB credentials and owner details (password ≥ 12 chars).
5. Delete `public_html/install.php` and `INSTALL_KEY.txt`. Confirm `/install.php` is locked.
6. Sign in at `/login`. Owner console `/admin.html`, customer workspace `/workspace.html`.

## First 30 minutes after install (Admin)
1. **Setup & health** → save business details, review the checklist.
2. **Integrations** → Razorpay keys + webhook secret, email (SMTP or Resend). Webhook URL: `https://YOUR-DOMAIN/webhooks/razorpay` with events `payment.captured`, `refund.processed`, `payment.dispute.created/won/lost/closed`.
3. **Pricing & add-ons** → confirm plan prices, validity, limits and perks.
4. **Offers & popup** → choose the delay (default 40 s) and add your own messages.
5. **Email automation** → review templates (welcome, receipt, expiry, follow-up and password reset are on by default).
6. Cron every minute: `/usr/bin/php /home/ACCOUNT/domains/YOUR-DOMAIN/cvtara_app/private-cron.php`.

## Preview / demo (staging only)
Add `CVTARA_PREVIEW=true` to `cvtara_app/.env`, run `php artisan cvtara:demo`, then open `/preview` for the three-view shell (Website · Customer dashboard · Admin dashboard) with demo logins. Remove the flag and demo users before going live.

## Notes
- Checkout is Razorpay only. Cashfree Payouts exist but need provider acceptance and the payout policy (Admin → Setup & health).
- Keep a backup of `cvtara_app/.env` (APP_KEY encrypts provider secrets and payout details).
- English only. No job, ranking, uptime or error-free-operation guarantee is made.
