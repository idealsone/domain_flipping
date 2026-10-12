# CVTara pre-launch check

## Package verification

- Source checkpoint: 5 October 2026; package assembled 10 October 2026.
- Automated Laravel suite: 40 tests passed, 211 assertions, using PHP 8.3.6 and SQLite locally.
- Production Composer dependencies are included; their Composer lockfile SHA-256 matches the source lockfile.
- Archive structure, ZIP integrity, deployment files and absence of local `.env`, SQLite database, test screenshots and QA folders were checked.

## Still required before public launch

- Hostinger installation, MariaDB migration acceptance, cron, permissions and backup/restore testing.
- Cashfree sandbox payment and webhook test, then one small live payment (refunded); email acceptance; Cashfree payout acceptance (payouts stay off). Razorpay remains available as a fallback provider.
- Real-device visual validation and complete Hindi/Bengali localization. Resume matching is English-focused.
- Confirm operator/contact/legal details, refund terms, referral disclosures, tax handling, identity evidence retention, fraud escalation and payout review ownership.
- Security review/penetration testing, DNS/SSL and public SEO review.

Keep the installation private until these checks pass.
