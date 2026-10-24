<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class MailAutomation
{
    /** Days after sign-up for the 12 yearly plan nudges (sent only until the first purchase, only with consent). */
    public const NURTURE_DAYS = [2, 7, 14, 30, 60, 90, 120, 150, 180, 240, 300, 360];

    /** Triggers that carry a one-time code: the code is removed from the outbox once the mail is sent. */
    private const CODE_TRIGGERS = ['security_verification', 'email_verification'];

    /** Sent the moment they are created (in the same request), not on the next cron run. */
    public const IMMEDIATE = ['security_verification', 'email_verification', 'password_reset'];

    /** Placeholders filled when the mail is sent, not when it is queued, so an offer that has ended is never mailed. */
    private const LATE = ['checkout_url', 'offer_line'];

    /** Most mails one cron run sends, and the seconds it may spend sending, so the next minute's run always starts on time. */
    public const PER_RUN = 40;

    public const RUN_SECONDS = 45;

    /** Mails kept back each day for sign-up codes, receipts and resets, whatever the campaigns and tips have used. */
    public const RESERVE = 100;

    /**
     * Fingerprints of the ready-made template texts shipped before 3.20. A saved template that still has one of these
     * (the owner saved the list without changing that mail) gets the current wording; mails the owner rewrote stay theirs.
     */
    private const OLD_DEFAULTS = ['0e9beb2b379c6f45', '121a1323cb7f8c6e', '15f01e04b4a1f527', '1a0a2d0fe8656347', '1b786670e26db375', '1e1fa935c24bb8cd', '20c12f055186c63e', '22226f5760aa66c2', '2ad703b6187e321e', '301969a83f780ca5', '34340dbdc3adaa67', '350f81515b68d4b6', '38a7b377d07ef64e', '43ee5fc3895d71cc', '44f6cc954b9bab37', '468f39c27dffe39f', '47189b11304ac4be', '4a2e4b43a6f14154', '530d447bc0487fae', '5837eacb55b2bf34', '5969de550b2cc894', '5c5ad1d564ab506b', '5f8e81477fbf58a6', '69774f38455ba7fc', '6a50173668f9d84f', '6ffe69b9a069ed6d', '71c5254ee37f3e2d', '7352caa80d3b9bfd', '739183cfb874c9a1', '74b9452bc8e360ed', '76e97211eece049f', '811d70b27e2ce3b6', '83bfc725750b2ddd', '8e25e1f5f3d05be8', '966aba9eea62cb11', '9865ce1fdcb4025b', '9a718f4bfb3905ef', '9da28846a37919cf', 'a1dca5c1b1f3eacb', 'a5d28fc84054b947', 'b4dce094be9faa3a', 'b514af29fe94762f', 'b7a0479e439776cc', 'bddf46b18dc47eac', 'cb9c255e7963f7d2', 'cba90649bdf20a0a', 'd111d1e45f5e1627', 'dd94ed800a0c7801', 'e6c1df3b151197e8', 'e98871faf1a2fd9c'];

    public static function isOldDefault(array $t): bool
    {
        return in_array(substr(sha1(($t['subject'] ?? '')."\n".($t['body'] ?? '')), 0, 16), self::OLD_DEFAULTS, true);
    }

    /** The owner's saved template list with untouched pre-3.20 texts replaced by the current wording, plus any template added in a later release (for the editor). */
    public static function upgradeTemplates(array $list): array
    {
        $defaults = collect(Settings::defaults()['emails'])->keyBy('id');
        $list = array_map(fn ($t) => is_array($t) && isset($defaults[$t['id'] ?? '']) && self::isOldDefault($t) ? array_merge($t, ['subject' => $defaults[$t['id']]['subject'], 'body' => $defaults[$t['id']]['body']]) : $t, $list);
        $have = array_column(array_filter($list, 'is_array'), 'id');

        return array_merge($list, $defaults->reject(fn ($t, $id) => in_array($id, $have, true))->values()->all());
    }

    private static function template(string $trigger, ?string $id = null): ?array
    {
        $pick = fn ($list) => collect($list)->first(fn ($t) => ($id ? ($t['id'] ?? '') === $id : ($t['trigger'] ?? '') === $trigger));
        $saved = $pick(Settings::get('emails', []));
        $default = $pick(Settings::defaults()['emails']);
        if ($saved && $default && self::isOldDefault($saved)) {
            return array_merge($saved, ['subject' => $default['subject'], 'body' => $default['body']]);
        }

        return $saved ?: $default;
    }

    /** Values every template may use. */
    public static function sharedVars(): array
    {
        $vars = ['site_url' => rtrim((string) config('app.url'), '/'), 'from_price' => self::fromPrice()];
        try {
            $w = Wallet::rules();
            $vars += ['referral_reward' => '₹'.number_format($w['rewardPaise'] / 100), 'referral_discount' => '₹'.number_format($w['friendDiscountPaise'] / 100), 'referral_min' => '₹'.number_format($w['minQualifyingPaise'] / 100)];
        } catch (\Throwable $e) {
            $vars += ['referral_reward' => '₹50', 'referral_discount' => '₹50', 'referral_min' => '₹299'];
        }

        // Optional lines: empty (and so left out of the mail) while the feature is switched off.
        try {
            $vars['tara_line'] = TaraAI::enabled() ? 'Stuck on wording? Tara AI, the writing helper in the builder, turns duties into clear achievement bullets and drafts a profile summary from your own facts.' : '';
        } catch (\Throwable $e) {
            $vars['tara_line'] = '';
        }
        try {
            $x = Settings::plans()['expert-review'] ?? null;
            $vars['expert_line'] = $x && ! empty($x['sale']) ? 'Want a second pair of eyes? '.$x['name'].': '.rtrim((string) $x['tagline'], '.').', ₹'.number_format((float) $x['price']).(! empty($x['planPrice']) && $x['planPrice'] < $x['price'] ? ' (₹'.number_format((float) $x['planPrice']).' with Job Hunt or Pro)' : '').'.' : '';
        } catch (\Throwable $e) {
            $vars['expert_line'] = '';
        }

        return $vars;
    }

    /** The live offer, read when the mail is sent: checkout link with the coupon, and a one-line summary. */
    public static function liveVars(): array
    {
        $site = rtrim((string) config('app.url'), '/');
        $o = MailLayout::offer();
        $q = $o && $o['code'] !== '' ? array_filter(['plan' => $o['plan'] ?: null, 'coupon' => $o['code']]) : [];

        return ['checkout_url' => $site.'/checkout.html'.($q ? '?'.http_build_query($q) : ''), 'offer_line' => MailLayout::offerLine($o)];
    }

    private static function fill(string $s, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : (in_array($m[1], self::LATE, true) ? $m[0] : ''), $s);
    }

    /** Queues one mail and returns its outbox id (null when the template is off, consent is missing, or it was already queued). */
    public static function queue(User $u, string $trigger, string $ref, array $vars = [], bool $essential = false, ?string $templateId = null, int $extraDelayHours = 0): ?string
    {
        $template = self::template($trigger, $templateId);
        if ($essential && $trigger === 'password_reset') {
            $template = $template ?: ['id' => 'reset', 'subject' => 'Reset your CVTara password', 'body' => 'Reset your password: {{reset_url}}', 'delayHours' => 0];
            $template['body'] = str_contains($template['body'], '{{reset_url}}') ? $template['body'] : "Use this link to reset your password: {{reset_url}}\nThis link expires in 60 minutes.";
        }
        if (! $template || (! $essential && ! ($template['enabled'] ?? false))) {
            return null;
        }
        if (EmailRenderer::isMarketing($trigger) && ! DB::table('profiles')->where('owner_id', $u->id)->value('marketing_consent')) {
            return null;
        }
        $vars = array_merge(['name' => trim(explode(' ', trim($u->name))[0]) ?: 'there', 'email' => $u->email, 'payment_reference' => $ref], self::sharedVars(), $vars);
        $id = (string) Str::uuid();
        $n = DB::table('mail_outbox')->insertOrIgnore(['id' => $id, 'dedupe' => hash('sha256', "$u->id:$trigger:$ref:".$template['id']), 'owner_id' => $u->id, 'trigger' => $trigger, 'recipient' => $u->email, 'subject' => self::fill($template['subject'], $vars), 'body' => self::fill($template['body'], $vars), 'due_at' => now()->addHours($essential ? 0 : ($template['delayHours'] ?? 0) + $extraDelayHours), 'created_at' => now(), 'updated_at' => now()]);
        if (! $n) {
            return null;
        }
        if (in_array($trigger, self::IMMEDIATE, true)) {
            self::sendNow($id);
        }

        return $id;
    }

    private static function fromPrice(): string
    {
        $min = collect(Settings::plans())->filter(fn ($p) => ($p['type'] ?? '') === 'plan' && ($p['price'] ?? 0) > 0 && ! empty($p['sale']))->min('price');

        return '₹'.number_format((float) ($min ?: 99));
    }

    private static function configureTransport(array $p): void
    {
        if (($p['provider'] ?? 'smtp') === 'resend') {
            config(['mail.default' => 'resend', 'services.resend.key' => $p['resendApiKey'] ?? '']);
        } else {
            config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => $p['smtpHost'] ?? config('mail.mailers.smtp.host'), 'mail.mailers.smtp.port' => (int) ($p['smtpPort'] ?? config('mail.mailers.smtp.port', 587)), 'mail.mailers.smtp.username' => $p['smtpUser'] ?? config('mail.mailers.smtp.username'), 'mail.mailers.smtp.password' => $p['smtpPassword'] ?? config('mail.mailers.smtp.password'), 'mail.mailers.smtp.scheme' => ($p['smtpPort'] ?? config('mail.mailers.smtp.port', 587)) == 465 ? 'smtps' : null, 'mail.mailers.smtp.timeout' => 15]);
        }
        config(['mail.from.address' => $p['fromEmail'] ?? config('mail.from.address'), 'mail.from.name' => $p['fromName'] ?? 'CVTara']);
        app('mail.manager')->purge();
    }

    /** The From address, the Reply-To address and the domain they should share. */
    public static function sender(array $p): array
    {
        $from = mb_strtolower((string) ($p['fromEmail'] ?? config('mail.from.address')));
        $reply = EmailRenderer::supportAddress();

        return ['from' => $from, 'name' => (string) ($p['fromName'] ?? 'CVTara') ?: 'CVTara', 'replyTo' => $reply, 'domain' => substr(strrchr($from, '@') ?: '', 1)];
    }

    /** Sends one outbox row as a branded HTML + plain-text message. */
    private static function deliver(object $j, array $p): void
    {
        // A campaign test looks exactly like the real campaign mail, footer and unsubscribe link included.
        $marketing = EmailRenderer::isMarketing($j->trigger) || $j->trigger === 'campaign_test';
        $security = in_array($j->trigger, EmailRenderer::SECURITY, true);
        $contact = $j->contact_id ?? null;
        $unsub = null;
        if ($marketing && $contact) {
            $tok = DB::table('email_contacts')->where('id', $contact)->value('token');
            $unsub = $tok ? EmailRenderer::contactUnsubscribeUrl($tok, $j->campaign_id ?? null) : null;
        } elseif ($marketing && $j->owner_id) {
            $unsub = EmailRenderer::unsubscribeUrl((int) $j->owner_id, $j->campaign_id ?? null);
        }
        $late = self::liveVars();
        $subject = self::fill($j->subject, $late);
        $mail = EmailRenderer::render($subject, self::fill($j->body, $late), $marketing, $contact ? null : ((int) $j->owner_id ?: null), '', $unsub, $security);
        $s = self::sender($p);
        // Lets Gmail Postmaster Tools report on each kind of mail separately (not shown to the reader).
        $feedback = ($j->campaign_id ?? null ? 'c'.substr(str_replace('-', '', $j->campaign_id), 0, 12) : 'm').':'.preg_replace('/[^a-z0-9_]/', '', $j->trigger).':cvtara';
        if (($p['provider'] ?? 'smtp') === 'resend') {
            $payload = ['from' => $s['name'].' <'.$s['from'].'>', 'to' => [$j->recipient], 'subject' => $subject, 'html' => $mail['html'], 'text' => $mail['text'], 'reply_to' => $s['replyTo'], 'headers' => ['Feedback-ID' => $feedback]];
            if ($unsub) {
                $payload['headers'] += ['List-Unsubscribe' => '<'.$unsub.'>, <mailto:'.$s['replyTo'].'?subject=Unsubscribe>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
            }
            Http::withToken($p['resendApiKey'] ?? '')->withHeaders(['Idempotency-Key' => $j->id])->timeout(15)->post('https://api.resend.com/emails', $payload)->throw();

            return;
        }
        Mail::send([], [], function ($m) use ($j, $mail, $unsub, $s, $subject, $feedback) {
            $m->from($s['from'], $s['name'])->to($j->recipient)->subject($subject)->html($mail['html'])->text($mail['text'])->replyTo($s['replyTo'], 'CVTara Support');
            $sym = $m->getSymfonyMessage();
            $h = $sym->getHeaders();
            // Message-ID on the sender's own domain (some servers otherwise use the machine name).
            if ($s['domain'] !== '') {
                $h->addIdHeader('Message-ID', bin2hex(random_bytes(16)).'@'.$s['domain']);
            }
            $h->addTextHeader('Feedback-ID', $feedback);
            if ($unsub) {
                $h->addTextHeader('List-Unsubscribe', '<'.$unsub.'>, <mailto:'.$s['replyTo'].'?subject=Unsubscribe>');
                $h->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            } else {
                $h->addTextHeader('X-Auto-Response-Suppress', 'OOF, AutoReply');
            }
        });
    }

    /** Mail of any kind sent in the last 24 hours (Hostinger counts a rolling day per mailbox). */
    public static function sentLast24h(): int
    {
        return DB::table('mail_outbox')->where('status', 'sent')->where('sent_at', '>', now()->subDay())->count();
    }

    /** The sending mailbox's limit for 24 hours (Owner console → Email campaigns → Sending limits). */
    public static function mailboxCap(): int
    {
        return Campaigns::settings()['mailboxCap'];
    }

    /** Takes one row for sending; false when another run (or the instant send) already has it. */
    private static function claim(object $j): bool
    {
        return DB::table('mail_outbox')->where('id', $j->id)->whereIn('status', ['queued', 'failed'])->where('attempts', $j->attempts)->update(['status' => 'sending', 'attempts' => $j->attempts + 1, 'updated_at' => now()]) === 1;
    }

    /** @return bool true when it was sent */
    private static function sendRow(object $j, array $p): bool
    {
        if (! self::claim($j)) {
            return false;
        }
        try {
            self::deliver($j, $p);
            DB::table('mail_outbox')->where('id', $j->id)->update(['status' => 'sent', 'sent_at' => now(), 'error' => '', 'body' => in_array($j->trigger, self::CODE_TRIGGERS, true) ? 'Security code delivered and removed' : $j->body]);

            return true;
        } catch (\Throwable $e) {
            DB::table('mail_outbox')->where('id', $j->id)->update(['status' => 'failed', 'due_at' => now()->addMinutes(in_array($j->trigger, self::IMMEDIATE, true) ? 1 : 15 * ($j->attempts + 1)), 'error' => 'Delivery failed. Check sender, credentials and provider logs.']);
            report($e);

            return false;
        }
    }

    /**
     * Sends one mail now, inside the request that created it (sign-up codes, security codes, password resets), so it never
     * waits behind campaigns or for the next cron run. If the provider is slow or down, the row stays in the queue and the
     * cron retries it a minute later.
     */
    public static function sendNow(string $id): bool
    {
        if (! (Settings::get('integrations')['email'] ?? false)) {
            return false;
        }
        $j = DB::table('mail_outbox')->where('id', $id)->whereIn('status', ['queued', 'failed'])->first();
        if (! $j) {
            return false;
        }
        try {
            $p = Settings::providers()['email'] ?? [];
            self::configureTransport($p);

            return self::sendRow($j, $p);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    public static function process(): array
    {
        $lock = Cache::lock('cvtara-mail', 120);
        if (! $lock->get()) {
            return ['sent' => 0, 'processed' => 0];
        }
        $started = microtime(true);
        try {
            if (! (Settings::get('integrations')['email'] ?? false)) {
                return ['sent' => 0, 'processed' => 0, 'paused' => true];
            }
            $p = Settings::providers()['email'] ?? [];
            self::configureTransport($p);
            DB::table('mail_outbox')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'failed', 'due_at' => now()]);
            DB::table('mail_outbox')->whereIn('trigger', self::CODE_TRIGGERS)->where('created_at', '<', now()->subMinutes(15))->whereIn('status', ['queued', 'failed'])->update(['status' => 'cancelled', 'body' => 'Expired security code removed']);
            // Tips and campaigns stop for the day before the mailbox limit, so codes and receipts can always go out.
            $held = self::sentLast24h() >= max(0, self::mailboxCap() - self::RESERVE);
            $jobs = DB::table('mail_outbox')->whereIn('status', ['queued', 'failed'])->where('attempts', '<', 5)->where('due_at', '<=', now())
                ->when($held, fn ($q) => $q->whereNotIn('trigger', EmailRenderer::MARKETING))
                ->orderByRaw("CASE WHEN `trigger` IN ('security_verification','email_verification','password_reset') THEN 0 WHEN `trigger` IN ('purchase_verified','profile_created','refund_requested','refund_processed','refund_rejected','campaign_test') THEN 1 WHEN `trigger` IN ('campaign','nurture','monthly_digest','blog_published','abandoned_purchase') THEN 3 ELSE 2 END")
                ->orderBy('due_at')->limit(self::PER_RUN)->get();
            $sent = 0;
            $processed = 0;
            foreach ($jobs as $j) {
                if (microtime(true) - $started > self::RUN_SECONDS) {
                    break; // the rest goes on the next minute's run
                }
                $processed++;
                if (in_array($j->trigger, self::CODE_TRIGGERS, true) && strtotime($j->created_at) < now()->subMinutes(15)->timestamp) {
                    DB::table('mail_outbox')->where('id', $j->id)->update(['status' => 'cancelled', 'body' => 'Expired security code removed']);

                    continue;
                }
                if (EmailRenderer::isMarketing($j->trigger) && ! self::marketingAllowed($j)) {
                    DB::table('mail_outbox')->where('id', $j->id)->update(['status' => 'cancelled']);

                    continue;
                }
                $sent += (int) self::sendRow($j, $p);
            }

            return ['sent' => $sent, 'processed' => $processed] + ($held ? ['held' => 'marketing waits: mailbox limit for 24 hours is nearly used'] : []);
        } finally {
            $lock->release();
        }
    }

    /** Checked again just before sending: consent can be withdrawn while a mail waits in the queue. */
    private static function marketingAllowed(object $j): bool
    {
        if ($j->trigger === 'campaign') {
            if (DB::table('email_suppressions')->where('email', mb_strtolower($j->recipient))->exists()) {
                return false;
            }
            if (! empty($j->contact_id)) {
                return DB::table('email_contacts')->where('id', $j->contact_id)->whereNull('unsubscribed_at')->exists();
            }
        }

        return (bool) DB::table('profiles')->where('owner_id', $j->owner_id)->value('marketing_consent');
    }

    /** Renders a template with sample values (for the owner's "send me a test" and for design checks). @return array{subject:string,html:string,text:string} */
    public static function preview(string $templateId, ?User $u = null): ?array
    {
        $t = self::template('', $templateId);
        if (! $t) {
            return null;
        }
        $site = rtrim((string) config('app.url'), '/');
        $vars = array_merge(self::sharedVars(), self::liveVars(), ['name' => $u ? (explode(' ', trim($u->name))[0] ?: 'there') : 'Asha', 'email' => $u?->email ?? 'asha@example.com', 'plan_name' => 'Job Hunt', 'amount' => '₹299.00', 'resumes' => '5', 'letters' => '5', 'access_until' => '30 Nov 2026', 'days' => '7', 'code' => '482915', 'reset_url' => $site.'/reset-password/sample', 'company' => 'Acme Corp', 'role' => 'Data Analyst', 'status' => 'Applied', 'n' => '1', 'question' => 'Have you heard back from the recruiter yet?', 'tracker_url' => $site.'/workspace.html?view=tracker', 'blog_title' => 'Take-home salary calculator', 'blog_url' => $site.'/blog.html', 'blog_summary' => 'See what lands in your bank account each month.', 'payment_reference' => 'pay_Q1w2E3r4T5y6', 'order' => 'ORD-1A2B3C', 'reason' => 'The resume from this order was already downloaded.', 'renew_url' => $site.'/checkout.html', 'review' => "## Overall verdict\nA clear, well-ordered resume. Sample review for the preview.\n\n## Fix list, most important first\n- Add a number to your strongest bullet\n- Name the role you are aiming for in the summary", 'dashboard_url' => $site.'/workspace.html?view=account']);
        $subject = self::fill((string) $t['subject'], $vars);

        return ['subject' => $subject] + EmailRenderer::render($subject, self::fill((string) $t['body'], $vars), EmailRenderer::isMarketing($t['trigger'] ?? ''), $u?->id ?? ($u ? null : 1), '', null, in_array($t['trigger'] ?? '', EmailRenderer::SECURITY, true));
    }

    // Reminder scans run once an hour; the cron heartbeat still updates every minute.
    public static function reminders(): void
    {
        if (! \Illuminate\Support\Facades\Cache::add('reminders-run', 1, 3600)) {
            return;
        }
        self::expiryReminders();
        foreach (DB::table('purchases')->where('status', 'created')->whereBetween('created_at', [now()->subDays(7), now()->subHour()])->get() as $p) {
            if (DB::table('purchases')->where('owner_id', $p->owner_id)->whereIn('status', ['paid', 'partially_refunded'])->where('created_at', '>', $p->created_at)->exists()) {
                continue;
            }
            if ($u = User::find($p->owner_id)) {
                self::queue($u, 'abandoned_purchase', $p->id);
            }
        }foreach (DB::table('applications')->where('follow_up', today())->get() as $a) {
            if ($u = User::find($a->owner_id)) {
                self::queue($u, 'followup_due', $a->id.':'.today()->toDateString());
            }
        }
        self::nurture();
        self::monthlyDigest();
    }

    /** One mail 7 days, 4 days and 1 day before a plan ends (never twice, never when the plan has already been renewed). */
    private static function expiryReminders(): void
    {
        $steps = [7 => 3, 4 => 3, 1 => 1];
        foreach (DB::table('purchases')->whereIn('status', ['paid', 'partially_refunded'])->whereBetween('access_until', [now(), now()->addDays(7)])->where('plan', 'not like', 'addon-%')->where('provider', '!=', 'referral_reward')->get() as $p) {
            if (DB::table('purchases')->where('owner_id', $p->owner_id)->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', $p->access_until)->exists()) {
                continue;
            }
            $u = User::find($p->owner_id);
            if (! $u || $u->blocked) {
                continue;
            }
            $left = (strtotime($p->access_until) - time()) / 86400;
            foreach ($steps as $day => $window) {
                if ($left <= $day && $left > $day - $window) {
                    $plans = Settings::plans();
                    self::queue($u, 'access_expiring_'.$day, $p->id, ['days' => (string) $day, 'plan_name' => $plans[$p->plan]['name'] ?? (string) $p->plan, 'access_until' => date('j M Y', strtotime($p->access_until)), 'renew_url' => rtrim((string) config('app.url'), '/').'/checkout.html']);
                    break;
                }
            }
        }
    }

    /** Twelve plan nudges over a year for people who have never bought a plan and opted in. One mail per person per run, each step once. */
    private static function nurture(): void
    {
        $template = collect(Settings::get('emails', []))->contains(fn ($t) => ($t['trigger'] ?? '') === 'nurture' && ($t['enabled'] ?? false)) || collect(Settings::defaults()['emails'])->contains(fn ($t) => ($t['trigger'] ?? '') === 'nurture' && ($t['enabled'] ?? false));
        if (! $template) {
            return;
        }
        $bought = DB::table('purchases')->whereNotNull('purchased_at')->select('owner_id');
        $users = User::where('is_admin', false)->where('blocked', false)->where('created_at', '<=', now()->subDays(self::NURTURE_DAYS[0]))->where('created_at', '>=', now()->subDays(max(self::NURTURE_DAYS) + 14))
            ->whereIn('id', DB::table('profiles')->where('marketing_consent', true)->select('owner_id'))->whereNotIn('id', $bought)->limit(500)->get();
        foreach ($users as $u) {
            $age = (time() - strtotime((string) $u->created_at)) / 86400;
            $step = null;
            foreach (self::NURTURE_DAYS as $i => $d) {
                if ($age >= $d) {
                    $step = $i;
                }
            }
            if ($step === null || self::recentMarketing($u->id, 3)) {
                continue;
            }
            if ($step === 10 && ! Wallet::enabled()) {
                continue; // the referral nudge only goes out while "Give ₹50, get ₹50" is switched on
            }
            self::queue($u, 'nurture', 'n'.($step + 1), [], false, 'nurture-'.($step + 1));
        }
    }

    /** One career-guide email a month for every opted-in customer, free or paid, with a different guide for different people. */
    private static function monthlyDigest(): void
    {
        $on = collect(Settings::get('emails', []))->first(fn ($t) => ($t['trigger'] ?? '') === 'monthly_digest') ?: collect(Settings::defaults()['emails'])->first(fn ($t) => ($t['trigger'] ?? '') === 'monthly_digest');
        if (! $on || ! ($on['enabled'] ?? false)) {
            return;
        }
        $posts = collect(Settings::get('blog', []))->filter(fn ($p) => ! empty($p['published']) && ! empty($p['title']))->sortBy('slug')->values();
        if ($posts->isEmpty()) {
            return;
        }
        $ym = date('Ym');
        $users = User::where('is_admin', false)->where('blocked', false)->where('created_at', '<=', now()->subDays(10))->whereIn('id', DB::table('profiles')->where('marketing_consent', true)->select('owner_id'))
            ->whereNotIn('id', DB::table('mail_outbox')->where('trigger', 'monthly_digest')->where('created_at', '>=', now()->subDays(25))->select('owner_id'))->limit(500)->get();
        foreach ($users as $u) {
            if (self::recentMarketing($u->id, 3)) {
                continue;
            }
            $months = (int) floor((time() - strtotime((string) $u->created_at)) / (30 * 86400));
            $post = $posts[($u->id + $months) % $posts->count()];
            $summary = trim((string) ($post['description'] ?? ''));
            self::queue($u, 'monthly_digest', 'd'.$ym, ['blog_title' => $post['title'], 'blog_summary' => mb_strimwidth($summary, 0, 200, '…'), 'blog_url' => rtrim((string) config('app.url'), '/').'/article?slug='.$post['slug']]);
        }
    }

    private static function recentMarketing(int $uid, int $days): bool
    {
        return DB::table('mail_outbox')->where('owner_id', $uid)->whereIn('trigger', EmailRenderer::MARKETING)->where('created_at', '>=', now()->subDays($days))->exists();
    }
}
