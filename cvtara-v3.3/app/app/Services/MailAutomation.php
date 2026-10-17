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

    private static function template(string $trigger, ?string $id = null): ?array
    {
        $pick = fn ($list) => collect($list)->first(fn ($t) => ($id ? ($t['id'] ?? '') === $id : ($t['trigger'] ?? '') === $trigger));

        return $pick(Settings::get('emails', [])) ?: $pick(Settings::defaults()['emails']);
    }

    public static function queue(User $u, string $trigger, string $ref, array $vars = [], bool $essential = false, ?string $templateId = null, int $extraDelayHours = 0): void
    {
        $template = self::template($trigger, $templateId);
        if ($essential && $trigger === 'password_reset') {
            $template = $template ?: ['id' => 'reset', 'subject' => 'Reset your CVTara password', 'body' => 'Reset your password: {{reset_url}}', 'delayHours' => 0];
            $template['body'] = str_contains($template['body'], '{{reset_url}}') ? $template['body'] : "Use this link to reset your password: {{reset_url}}\nThis link expires in 60 minutes.";
        }
        if (! $template || (! $essential && ! ($template['enabled'] ?? false))) {
            return;
        }
        if (EmailRenderer::isMarketing($trigger) && ! DB::table('profiles')->where('owner_id', $u->id)->value('marketing_consent')) {
            return;
        }
        $vars = array_merge(['name' => trim(explode(' ', trim($u->name))[0]) ?: 'there', 'email' => $u->email, 'site_url' => rtrim((string) config('app.url'), '/'), 'payment_reference' => $ref, 'from_price' => self::fromPrice()], $vars);
        $replace = fn ($s) => preg_replace_callback('/\{\{([a-z_]+)\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $s);
        DB::table('mail_outbox')->insertOrIgnore(['id' => (string) Str::uuid(), 'dedupe' => hash('sha256', "$u->id:$trigger:$ref:".$template['id']), 'owner_id' => $u->id, 'trigger' => $trigger, 'recipient' => $u->email, 'subject' => $replace($template['subject']), 'body' => $replace($template['body']), 'due_at' => now()->addHours($essential ? 0 : ($template['delayHours'] ?? 0) + $extraDelayHours), 'created_at' => now(), 'updated_at' => now()]);
    }

    private static function fromPrice(): string
    {
        $min = collect(Settings::plans())->filter(fn ($p, $k) => ! str_starts_with((string) $k, 'addon') && ($p['price'] ?? 0) > 0 && ($p['enabled'] ?? true))->min('price');

        return '₹'.number_format((float) ($min ?: 299));
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

    /** Sends one outbox row as a branded HTML + plain-text message. */
    private static function deliver(object $j, array $p): void
    {
        $marketing = EmailRenderer::isMarketing($j->trigger);
        $mail = EmailRenderer::render($j->subject, $j->body, $marketing, (int) $j->owner_id, preg_match('/^(.{20,140}?[.!?])(\s|$)/u', preg_replace('/\s+/', ' ', strip_tags(str_replace('#', '', $j->body))), $m) ? $m[1] : '');
        $fromEmail = $p['fromEmail'] ?? config('mail.from.address');
        $unsub = $marketing && $j->owner_id ? EmailRenderer::unsubscribeUrl((int) $j->owner_id) : null;
        if (($p['provider'] ?? 'smtp') === 'resend') {
            $payload = ['from' => ($p['fromName'] ?? 'CVTara').' <'.$fromEmail.'>', 'to' => [$j->recipient], 'subject' => $j->subject, 'html' => $mail['html'], 'text' => $mail['text'], 'reply_to' => 'support@cvtara.com'];
            if ($unsub) {
                $payload['headers'] = ['List-Unsubscribe' => '<'.$unsub.'>, <mailto:support@cvtara.com?subject=Unsubscribe>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
            }
            Http::withToken($p['resendApiKey'] ?? '')->withHeaders(['Idempotency-Key' => $j->id])->timeout(15)->post('https://api.resend.com/emails', $payload)->throw();

            return;
        }
        Mail::send([], [], function ($m) use ($j, $mail, $unsub) {
            $m->to($j->recipient)->subject($j->subject)->html($mail['html'])->text($mail['text'])->replyTo('support@cvtara.com', 'CVTara Support');
            $h = $m->getSymfonyMessage()->getHeaders();
            if ($unsub) {
                $h->addTextHeader('List-Unsubscribe', '<'.$unsub.'>, <mailto:support@cvtara.com?subject=Unsubscribe>');
                $h->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            } else {
                $h->addTextHeader('X-Auto-Response-Suppress', 'OOF, AutoReply');
            }
        });
    }

    public static function process(): array
    {
        $lock = Cache::lock('cvtara-mail', 300);
        if (! $lock->get()) {
            return ['sent' => 0, 'processed' => 0];
        }try {
            if (! (Settings::get('integrations')['email'] ?? false)) {
                return ['sent' => 0, 'processed' => 0, 'paused' => true];
            }$p = Settings::providers()['email'] ?? [];
            self::configureTransport($p);
            DB::table('mail_outbox')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'failed', 'due_at' => now()]);
            DB::table('mail_outbox')->whereIn('trigger', self::CODE_TRIGGERS)->where('created_at', '<', now()->subMinutes(15))->whereIn('status', ['queued', 'failed'])->update(['status' => 'cancelled', 'body' => 'Expired security code removed']);
            $jobs = DB::table('mail_outbox')->whereIn('status', ['queued', 'failed'])->where('attempts', '<', 5)->where('due_at', '<=', now())->orderByRaw("CASE WHEN `trigger` IN ('security_verification','email_verification') THEN 0 WHEN `trigger` IN ('purchase_verified','password_reset','profile_created') THEN 1 ELSE 2 END")->orderBy('due_at')->limit(40)->get();
            $sent = 0;
            foreach ($jobs as $j) {
                if (in_array($j->trigger, self::CODE_TRIGGERS, true) && strtotime($j->created_at) < now()->subMinutes(15)->timestamp) {
                    DB::table('mail_outbox')->where('id', $j->id)->update(['status' => 'cancelled', 'body' => 'Expired security code removed']);

                    continue;
                }if (EmailRenderer::isMarketing($j->trigger) && ! DB::table('profiles')->where('owner_id', $j->owner_id)->value('marketing_consent')) {
                    DB::table('mail_outbox')->where('id', $j->id)->update(['status' => 'cancelled']);

                    continue;
                }DB::table('mail_outbox')->where('id', $j->id)->update(['status' => 'sending', 'attempts' => $j->attempts + 1, 'updated_at' => now()]);
                try {
                    self::deliver($j, $p);
                    DB::table('mail_outbox')->where('id', $j->id)->update(['status' => 'sent', 'sent_at' => now(), 'error' => '', 'body' => in_array($j->trigger, self::CODE_TRIGGERS, true) ? 'Security code delivered and removed' : $j->body]);
                    $sent++;
                } catch (\Throwable $e) {
                    DB::table('mail_outbox')->where('id', $j->id)->update(['status' => 'failed', 'due_at' => now()->addMinutes(in_array($j->trigger, self::CODE_TRIGGERS, true) ? 1 : 15 * ($j->attempts + 1)), 'error' => 'Delivery failed. Check sender, credentials and provider logs.']);
                    report($e);
                }
            }

            return ['sent' => $sent, 'processed' => $jobs->count()];
        } finally {
            $lock->release();
        }
    }

    /** Renders a template with sample values (for the owner's "send me a test" and for design checks). @return array{subject:string,html:string,text:string} */
    public static function preview(string $templateId, ?User $u = null): ?array
    {
        $t = self::template('', $templateId);
        if (! $t) {
            return null;
        }
        $vars = ['name' => $u ? (explode(' ', trim($u->name))[0] ?: 'there') : 'Asha', 'site_url' => rtrim((string) config('app.url'), '/'), 'from_price' => self::fromPrice(), 'plan_name' => 'Fresher Standard', 'resumes' => '20', 'letters' => '20', 'access_until' => '30 Nov 2026', 'days' => '7', 'code' => '482915', 'reset_url' => rtrim((string) config('app.url'), '/').'/reset-password/sample', 'company' => 'Acme Corp', 'role' => 'Data Analyst', 'status' => 'Applied', 'n' => '1', 'question' => 'Have you heard back?', 'tracker_url' => rtrim((string) config('app.url'), '/').'/workspace.html?view=tracker', 'blog_title' => 'Take-home salary calculator', 'blog_url' => rtrim((string) config('app.url'), '/').'/blog.html', 'blog_summary' => 'See what lands in your bank account each month.', 'payment_reference' => 'ORD-1001'];
        $replace = fn ($s) => preg_replace_callback('/\{\{([a-z_]+)\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), (string) $s);
        $subject = $replace($t['subject']);

        return ['subject' => $subject] + EmailRenderer::render($subject, $replace($t['body']), EmailRenderer::isMarketing($t['trigger'] ?? ''), $u?->id);
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
