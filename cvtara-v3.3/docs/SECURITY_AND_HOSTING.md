# CVTara — security model and hosting checklist

## What protects the site (and what does not)
Hiding code does not protect a site; **server-side checks do**. Every secret and every rule lives in PHP on the server, in the private `cvtara_app` folder outside the web root. What visitors can download is only the page scripts needed to draw the screens, and those are minified (comments removed, names shortened) in the release package.

| Layer | What it does |
|---|---|
| Owner area | `/admin*` pages and the six console scripts (`/console/*.js`) answer only to a signed-in, unblocked owner. Everyone else (visitors and customers) is sent to the home page; the console scripts return 404. |
| Owner confirmation | Payment/email keys, plan prices, coupons, business controls and key content pages need a fresh email code + password (valid 10 minutes). |
| Sign-in | Passwords ≥ 12 characters, hashed; 5 failed attempts lock the address for 15 minutes; sessions are reset on password change; blocked accounts lose their session. |
| Redirects | Unsafe `next` values are ignored; password-reset links are built from `APP_URL`, not the request's Host header. |
| Broken pages | Any missing or failing page (not API, not a file) redirects to the home page; if the home page itself fails a plain "back soon" page is shown. A page that keeps throwing script errors sends visitors home; working pages (workspace, checkout, owner) show a calm Reload/Home bar instead, so no work or payment is interrupted. |
| Headers | CSP (scripts only from this site, Razorpay, OneSignal, Google Analytics), frame-ancestors, nosniff, HSTS on HTTPS, no server/PHP version header. |
| Abuse limits | API 400 requests/min per address, pages 240/min, sign-in 20/min, sign-up 5/min, password mail 3/min, exports 10/min. |
| Demo mode | Cannot switch on in production: it needs a development `APP_ENV` and a local address. The Health page flags it if the variable is present. |
| Data | Payout details are encrypted; account deletion erases them; Error Log never stores passwords or card data. |

## Private addresses
- **Setup:** `https://cvtara.com/setup/afsar` (the installer; `install.php` itself cannot be opened directly and the link is dead once the site is installed).
- **Owner sign-in:** `https://cvtara.com/afsar` and `https://cvtara.com/sanaulla`. Owner accounts **cannot** sign in on the public `/login` page, customers cannot sign in on these pages, and the addresses are not in `robots.txt`, the sitemap, or any page. They are `noindex`, rate-limited (5 wrong attempts lock the address for 15 minutes) and still need the password plus the email-code confirmation for sensitive actions.
- To change them, edit `OWNER_LOGIN_PATHS=afsar,sanaulla` in `.env` (3–40 letters, digits, `-` or `_`). The setup address is the `setup/afsar` rule in `public_html/.htaccess` and `public_html/index.php`.
- A secret address is a second lock, not the only lock: the password, lockout and confirmation codes do the real protecting. Use a long unique password for each owner.

## Privacy from search engines and strangers
- Admin pages (`/admin*`), the owner scripts (`/console/*`), the platform guide, sign-in pages, workspace, checkout, API, preview and install URLs all send `X-Robots-Tag: noindex, nofollow` and are disallowed in `robots.txt`; they are never in the sitemap.
- Anyone who opens an owner URL without being the signed-in owner is sent to the home page.
- A broken or missing page shows a short notice ("This page is not available", with a **Go to the home page** button) and returns to the home page after three seconds; it is marked noindex.

## Payments policy
All payments are final and non-refundable (the policy page `/refund.html`, the terms and the checkout consent say so). The console has no refund button. If a bank reverses a payment the gateway reports it and the site removes that access. Check with a lawyer that this wording works for Indian consumer-protection rules before launch.

## About "load balancing"
Hostinger shared hosting is one server, so a true load balancer is not possible there. What to do instead (all free or cheap):
1. Put the domain behind **Cloudflare** (free plan): DDoS protection, bot filtering, caching of images/scripts, "Under Attack" mode, rate-limit rules on `/login` and `/api/*`.
2. Keep the built-in limits above; raise PHP workers/memory in hPanel if traffic grows.
3. When traffic outgrows one plan, move to Hostinger Cloud/VPS: run two app servers behind a load balancer, with the database and `storage/` on shared services. The app is stateless apart from sessions (database driver) and uploads (storage).

## Before you go live (all must be ticked)
- [ ] Upload the package and run the one-link installer; delete `INSTALL_KEY.txt` afterwards.
- [ ] `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://cvtara.com`, **no** `CVTARA_PREVIEW`.
- [ ] HTTPS forced in hPanel; the owner Health page shows no red items (HTTPS, domain, scheduler, e-mail, payments, demo mode off).
- [ ] Cron job `* * * * *` for `cvtara:cron` is running (Health shows a recent heartbeat).
- [ ] Razorpay live Key ID, Key secret and Webhook secret saved (mode Live); webhook URL `https://cvtara.com/webhooks/razorpay` added in the Razorpay dashboard; one small real payment and one refund tested end to end.
- [ ] `TRUSTED_PROXIES=*` in `.env` once Cloudflare is in front (so rate limits see the visitor's real address).
- [ ] E-mail provider saved; a test mail from support@cvtara.com received.
- [ ] Cloudflare in front, SSL mode "Full (strict)".
- [ ] Owner account uses a long unique password; nobody else has `is_admin`.
- [ ] Decide each plan perk you can really deliver (priority support, review call) — see "Open decisions".

## Open decisions for the owner
- Plan perks "Priority support" and "1-on-1 Resume Review Call" are promises made by people, not by code: keep them only if the HR team will fulfil them.
- MRP strike-through prices are permanent; show them only with a real time-limited offer.
- Findings still open (see `risks_and_bugs.md`): dashboard numbers computed in the browser (F-08), Orders view pagination (F-09), print layout watermark (F-11), retention of logs (F-22).
