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
- [ ] Razorpay live keys and the webhook secret saved; one real ₹1 payment and refund tested.
- [ ] E-mail provider saved; a test mail from support@cvtara.com received.
- [ ] Cloudflare in front, SSL mode "Full (strict)".
- [ ] Owner account uses a long unique password; nobody else has `is_admin`.
- [ ] Decide each plan perk you can really deliver (priority support, review call) — see "Open decisions".

## Open decisions for the owner
- Plan perks "Priority support" and "1-on-1 Resume Review Call" are promises made by people, not by code: keep them only if the HR team will fulfil them.
- MRP strike-through prices are permanent; show them only with a real time-limited offer.
- Findings still open (see `risks_and_bugs.md`): dashboard numbers computed in the browser (F-08), Orders view pagination (F-09), print layout watermark (F-11), retention of logs (F-22).
