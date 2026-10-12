# CVTara Hostinger package

Self-contained PHP 8.3 SaaS. No Composer or npm is required. The application lives in `cvtara_app/` (private) and is served from `public_html/`.

## Install

1. Upload this folder to the domain directory that contains `public_html`, not inside `public_html`.
2. Enable SSL and PHP 8.3+. Enable `pdo_mysql`, `mbstring`, `openssl`, `fileinfo` and `curl`.
3. Create an empty MySQL/MariaDB database in hPanel.
4. Open `https://YOUR-DOMAIN/install.php`, enter the key from `INSTALL_KEY.txt`, the HTTPS domain, database credentials and owner details (password 12+ characters).
5. Delete `public_html/install.php` and the local `INSTALL_KEY.txt`. Sign in at `/login`.
6. Owner console: `/admin`. Customer workspace: `/workspace`.

## After install

1. **Operations** — switch maintenance on/off, whitelist your IP, read/filter/clear the error log, clear cache.
2. **Mailboxes** — add one transactional SMTP box on your main domain, then extra marketing boxes. Campaigns rotate through enabled marketing accounts.
3. **Leads** — import a CSV of people who already consented, or collect opt-ins from `/contact`. Do not scrape LinkedIn. Every campaign message includes unsubscribe.
4. **Settings** — brand, policies, sitemap and referral hold days (default 14).
5. **Payments** — add Razorpay keys to `cvtara_app/.env`. Webhooks: `/webhooks/razorpay` and `/webhooks/cashfree` (also `/api/v1/payments/cashfree-webhook`). For acceptance testing, mark an order paid from the owner overview.

## Cron

Every minute:

`/usr/bin/php /home/ACCOUNT/domains/YOUR-DOMAIN/cvtara_app/private-cron.php`

This sends due mail, releases referral holds, runs consented outreach and abandoned-scan follow-ups.

## Honest limits

Checkout records an order. Live card capture needs Razorpay keys and a verified webhook. Cashfree checkout is not a hosted widget in this build; the Cashfree webhook can still activate access if you send a signed success event. Overlap scores are not hiring guarantees. Marketing is opt-in / recorded-consent only.
