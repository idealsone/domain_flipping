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
    public static function queue(User $u, string $trigger, string $ref, array $vars = [], bool $essential = false): void
    {
        $templates = Settings::get('emails', []);
        $template = collect($templates)->firstWhere('trigger', $trigger) ?: collect(Settings::defaults()['emails'])->firstWhere('trigger', $trigger);
        if ($essential) {
            $template = $template ?: ['id' => 'reset', 'subject' => 'Reset your CVTara password', 'body' => 'Reset your password: {{reset_url}}', 'delayHours' => 0];
            $template['body'] = str_contains($template['body'], '{{reset_url}}') ? $template['body'] : "Use this link to reset your password: {{reset_url}}\nThis link expires in 60 minutes.";
        }
        if (! $template || (! $essential && ! ($template['enabled'] ?? false))) {
            return;
        }
        if (in_array($trigger, ['blog_published', 'abandoned_purchase']) && ! DB::table('profiles')->where('owner_id', $u->id)->value('marketing_consent')) {
            return;
        }
        $vars = array_merge(['name' => $u->name, 'email' => $u->email, 'site_url' => config('app.url'), 'payment_reference' => $ref], $vars);
        $replace = fn ($s) => preg_replace_callback('/\{\{([a-z_]+)\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $s);
        DB::table('mail_outbox')->insertOrIgnore(['id' => (string) Str::uuid(), 'dedupe' => hash('sha256', "$u->id:$trigger:$ref:".$template['id']), 'owner_id' => $u->id, 'trigger' => $trigger, 'recipient' => $u->email, 'subject' => $replace($template['subject']), 'body' => $replace($template['body']), 'due_at' => now()->addHours($essential ? 0 : ($template['delayHours'] ?? 0)), 'created_at' => now(), 'updated_at' => now()]);
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
            if (($p['provider'] ?? 'smtp') === 'resend') {
                config(['mail.default' => 'resend', 'services.resend.key' => $p['resendApiKey'] ?? '']);
            } else {
                config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => $p['smtpHost'] ?? config('mail.mailers.smtp.host'), 'mail.mailers.smtp.port' => (int) ($p['smtpPort'] ?? config('mail.mailers.smtp.port', 587)), 'mail.mailers.smtp.username' => $p['smtpUser'] ?? config('mail.mailers.smtp.username'), 'mail.mailers.smtp.password' => $p['smtpPassword'] ?? config('mail.mailers.smtp.password'), 'mail.mailers.smtp.scheme' => ($p['smtpPort'] ?? config('mail.mailers.smtp.port', 587)) == 465 ? 'smtps' : null, 'mail.mailers.smtp.timeout' => 15]);
            }config(['mail.from.address' => $p['fromEmail'] ?? config('mail.from.address'), 'mail.from.name' => $p['fromName'] ?? 'CVTara']);
            app('mail.manager')->purge();
            DB::table('mail_outbox')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'failed', 'due_at' => now()]);
            DB::table('mail_outbox')->where('trigger', 'security_verification')->where('created_at', '<', now()->subMinutes(10))->whereIn('status', ['queued', 'failed'])->update(['status' => 'cancelled', 'body' => 'Expired security code removed']);
            $jobs = DB::table('mail_outbox')->whereIn('status', ['queued', 'failed'])->where('attempts', '<', 5)->where('due_at', '<=', now())->orderByRaw("CASE WHEN `trigger`='security_verification' THEN 0 WHEN `trigger` IN ('purchase_verified','password_reset','profile_created') THEN 1 ELSE 2 END")->orderBy('due_at')->limit(40)->get();
            $sent = 0;
            foreach ($jobs as $j) {
                if ($j->trigger === 'security_verification' && strtotime($j->created_at) < now()->subMinutes(10)->timestamp) {
                    DB::table('mail_outbox')->where('id', $j->id)->update(['status' => 'cancelled', 'body' => 'Expired security code removed']);

                    continue;
                }if (in_array($j->trigger, ['blog_published', 'abandoned_purchase']) && ! DB::table('profiles')->where('owner_id', $j->owner_id)->value('marketing_consent')) {
                    DB::table('mail_outbox')->where('id', $j->id)->update(['status' => 'cancelled']);

                    continue;
                }DB::table('mail_outbox')->where('id', $j->id)->update(['status' => 'sending', 'attempts' => $j->attempts + 1, 'updated_at' => now()]);
                try {
                    if (($p['provider'] ?? 'smtp') === 'resend') {
                        Http::withToken($p['resendApiKey'] ?? '')->withHeaders(['Idempotency-Key' => $j->id])->timeout(15)->post('https://api.resend.com/emails', ['from' => ($p['fromName'] ?? 'CVTara').' <'.($p['fromEmail'] ?? config('mail.from.address')).'>', 'to' => [$j->recipient], 'subject' => $j->subject, 'text' => $j->body])->throw();
                    } else {
                        Mail::raw($j->body, fn ($m) => $m->to($j->recipient)->subject($j->subject));
                    }DB::table('mail_outbox')->where('id', $j->id)->update(['status' => 'sent', 'sent_at' => now(), 'error' => '', 'body' => $j->trigger === 'security_verification' ? 'Security code delivered and removed' : $j->body]);
                    $sent++;
                } catch (\Throwable $e) {
                    DB::table('mail_outbox')->where('id', $j->id)->update(['status' => 'failed', 'due_at' => now()->addMinutes($j->trigger === 'security_verification' ? 1 : 15 * ($j->attempts + 1)), 'error' => 'Delivery failed. Check sender, credentials and provider logs.']);
                    report($e);
                }
            }

            return ['sent' => $sent, 'processed' => $jobs->count()];
        } finally {
            $lock->release();
        }
    }

    public static function reminders(): void
    {
        foreach (DB::table('purchases')->where('status', 'paid')->whereBetween('access_until', [now(), now()->addDays(7)])->where('plan', 'not like', 'addon-%')->get() as $p) {
            if ($u = User::find($p->owner_id)) {
                self::queue($u, 'access_expiring', $p->id);
            }
        }foreach (DB::table('purchases')->where('status', 'created')->whereBetween('created_at', [now()->subDays(7), now()->subHour()])->get() as $p) {
            if ($u = User::find($p->owner_id)) {
                self::queue($u, 'abandoned_purchase', $p->id);
            }
        }foreach (DB::table('applications')->where('follow_up', today())->get() as $a) {
            if ($u = User::find($a->owner_id)) {
                self::queue($u, 'followup_due', $a->id.':'.today()->toDateString());
            }
        }Settings::put('cronHeartbeat',['at' => now()->toIso8601String()]);
    }
}
