# Hostinger plan guide — what minifying, caching, backups and security really give you

**Read this first.** I could not find a Hostinger plan called "Unlimited". Hostinger's shared plans are Premium, Business and Cloud Startup (names and numbers change; third-party comparison sites disagree on storage, PHP workers and prices, and none of the results I found came from Hostinger itself). Treat every figure below as **to be confirmed on hostinger.com or in hPanel** before you buy.

## Which plan to pick (what matters for CVTara)
| Need | Why | Premium | Business | Cloud Startup |
|---|---|---|---|---|
| Daily backups by Hostinger | recover from a bad update or a hacked account | weekly (reported) | **daily** (reported) | daily (reported) |
| PHP workers / CPU | how many visitors can use the site at once | lowest | about 1.5× Premium (reported) | highest of the three (reported) |
| SSH, cron, PHP 8.3, ZIP extension | the installer needs ZIP; cron runs mail, reminders, backups | confirm in hPanel | confirm in hPanel | confirm in hPanel |
| Free SSL | HTTPS is required for payments and sign-in | yes | yes | yes |
| Free CDN | faster pages for visitors far from the server | not listed (reported) | listed (reported) | confirm |

**Recommendation:** start on **Business** (daily backups, more PHP workers) and move to Cloud Startup when the owner Health page shows slow responses or hPanel shows you hitting PHP-worker or CPU limits. Whatever you choose, put the domain behind the free Cloudflare plan as well.

## What each thing gives you

### Minifying (done by `tools/build_package.py`)
- Removes comments and long names from the 38 site scripts and 6 owner-console scripts. Measured on this release: scripts shrink from about 429 KB to 379 KB (−12 %), and about 152 KB to 141 KB after gzip (−7 %).
- **Honest benefit:** a small speed gain and it makes the code harder to read. It does not make the site secure; the real protection is that rules and secrets live in PHP on the server.
- Bigger speed wins come from caching and compression (below), not from minifying.

### Caching
| Layer | What it does | Where it is set |
|---|---|---|
| Browser cache | Scripts/styles whose address carries `?v=<fingerprint>` are kept for a year (the address changes whenever the file changes); images and fonts for 30 days; other scripts/styles for an hour. Repeat visits load almost instantly. | `public_html/.htaccess` (needs Apache `mod_headers`/`mod_rewrite`, normal on Hostinger) |
| Compression | HTML, CSS, JS, JSON and SVG are compressed in transit. | `mod_deflate` rule in `.htaccess` |
| Settings cache | The app reads each setting once per request, and the home page fetches public content once. | in the code |
| Cloudflare (recommended) | Caches images/scripts at edge servers, absorbs bot traffic and attacks. Set Cache Level "Standard", leave HTML un-cached, and add a rule: never cache `/api/*`, `/afsar`, `/sanaulla`, `/setup/*`, `/workspace*`, `/checkout*`. | Cloudflare dashboard |
| Hostinger LiteSpeed cache | Mostly designed for WordPress; CVTara is a Laravel app, so do not depend on it. PHP OPcache (on by default on most hosts) speeds up the PHP code; check in hPanel → PHP configuration. | hPanel |
After the first deploy, check with `curl -I https://cvtara.com/styles.css` and confirm `Cache-Control` and `Content-Encoding: gzip` (or `br`) appear.

### Backups — three layers, because one is never enough
1. **Hostinger's own backups** (daily on Business/Cloud, weekly on Premium, as reported): restore the whole site from hPanel → Backups.
2. **CVTara's daily database backup** (new): the cron job writes a compressed SQL file to the private folder `storage/app/backups` (outside the web root), keeps the newest 14, and the owner Health page turns red if none was written in 36 hours. You can also run `php artisan cvtara:backup` over SSH at any time. The file holds personal data (payout details stay encrypted): keep it private.
3. **Your own copy off the server:** once a week download the newest file from `cvtara_app/storage/app/backups` (File Manager or SFTP) and keep it somewhere else (your computer, a cloud drive). Also keep `.env` (it holds the encryption key — without it encrypted payout data cannot be read) in a password manager.
- **Restore:** upload the `.sql.gz`, unzip, import it in phpMyAdmin into an empty database, then point `.env` at it. Test a restore once before launch.

### Security — what you get from where
| Protection | From Hostinger | From CVTara | From Cloudflare |
|---|---|---|---|
| HTTPS certificate | free SSL (force HTTPS in hPanel) | HSTS header, secure cookies | "Full (strict)" mode |
| Attack filtering / malware scans | account-level tools vary by plan — confirm in hPanel; I could not confirm Imunify360 or a WAF in the sources I found | request limits per address, sign-in lockout, script policy (CSP), noindex on private areas | DDoS protection, bot rules, rate-limit rules on `/api/*` and the sign-in addresses |
| Private areas | — | owner pages and scripts hidden from everyone but the signed-in owner; owner sign-in only at the private addresses; setup link dead after install | optional: Cloudflare Access (free for a few users) in front of `/afsar` and `/sanaulla` |
| Money safety | — | server confirms every payment with Cashfree, signed webhooks, step-up code for sensitive owner changes | — |
| Data safety | backups (above) | daily database backup, old logs pruned, payout details erased on account deletion | — |

## Limits to plan for
- **Cron:** the app needs a cron job every minute (`/usr/bin/php /home/ACCOUNT/domains/YOUR-DOMAIN/cvtara_app/private-cron.php`; the exact command is on the owner Setup tools page). If your plan restricts cron frequency, e-mails and reminders will be late; Health shows this.
- **Traffic:** shared plans limit simultaneous PHP processes. Cloudflare caching of static files and keeping the API un-cached means only real work reaches PHP.
- **Storage:** backups use space (about the size of your database, compressed). Fourteen copies are kept.
- **Not a load balancer:** a single shared server cannot be load-balanced; Cloudflare plus an upgrade path (Cloud/VPS) is the realistic scaling plan.

## Ready-made pages and traffic protection (v3.9.0)
- **What it is:** the public pages (home, templates, cover letters, blog, guides, calculators, policies) are saved as finished HTML in `public_html/_pages`. Apache (Hostinger) serves them directly to visitors who have no session cookie — no PHP, no database. If the web server rules are not active, PHP serves the same files before it starts a session, which is still very cheap.
- **Never stored:** signed-in visitors, addresses with a query string, checkout, workspace, owner pages, API, login and register. Pages contain no visitor-specific data; the security token is fetched on demand (`/api/csrf`).
- **After every upload of a new version:** open Owner Console → Speed & traffic → **Rebuild all pages now** (or run `php artisan cvtara:prebuild`). Old pages are replaced; until then the web server may serve the previous version's pages. The installer builds them on first install.
- **Rebuilds automatically** whenever you save content, plans, legal text, SEO or features.
- **Busy mode:** Off / Automatic / On. Automatic watches the server load per CPU core (default 2.5). It pauses only ATS reviews, job-link reading and PDF/Word creation for a minute at a time. On shared hosting that hides load figures, use On manually during a launch rush.
- **Sessions:** with ready-made pages, visitors who only read pages no longer create a session row. For the rest, consider `SESSION_DRIVER=file` on Hostinger if the database is the bottleneck.
- **Honest limits:** this removes most of the load from page views; it cannot make PDF generation, payments or the ATS review free. Cloudflare in front (free plan) is still the best protection against floods and bots.

