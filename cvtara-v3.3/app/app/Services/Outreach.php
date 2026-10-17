<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Outreach: mails opted-in contacts from separate sending mailboxes (another domain, e.g. Hostinger mailboxes on cvtara.in),
 * so CVTara's own account mail is never affected by their reputation.
 *
 * - Each mailbox warms up on its own: warmup_start mails on day 1, +warmup_step a day, up to daily_cap (rolling 24 hours).
 * - Mail goes out only inside the send window (India time), one at a time, spread evenly across the window.
 * - A contact keeps the same mailbox for every step, so follow-ups come from the same address.
 * - Right before each send the address is checked again: a CVTara account, an unsubscribe or a bounce stops it for good.
 * - A mailbox pauses itself after repeated failures or too many bounces; the others carry on.
 */
class Outreach
{
    public const STATUSES = ['queued', 'active', 'done', 'registered', 'unsubscribed', 'bounced', 'removed'];

    /** Statuses that never get mail again. */
    public const STOPPED = ['registered', 'unsubscribed', 'bounced', 'removed'];

    public const MAX_IMPORT = 20000;

    private const ROLE_PREFIXES = ['noreply', 'no-reply', 'donotreply', 'do-not-reply', 'mailer-daemon', 'postmaster', 'abuse', 'bounce', 'bounces'];

    private const KNOWN_DOMAINS = ['gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.in', 'yahoo.in', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'icloud.com', 'me.com', 'rediffmail.com', 'aol.com', 'proton.me', 'protonmail.com', 'zoho.com', 'zohomail.in'];

    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'windowStart' => 9,
            'windowEnd' => 19,
            'footer' => '',
            'steps' => [
                ['subject' => '{{name}}, a quicker way to a job-ready resume', 'body' => "Hi {{name}},\n\nCVTara builds an ATS-friendly resume and cover letter in minutes, and checks your resume against any job description so you know which keywords are missing.\n\nYou can try it free here: {{site_url}}\n\nIf this isn't useful, just ignore this email.", 'delayDays' => 0],
                ['subject' => 'Re: a quicker way to a job-ready resume', 'body' => "Hi {{name}},\n\nJust following up on my last note. Many freshers use CVTara's free ATS check to see why their applications get no reply.\n\nTry it here: {{site_url}}", 'delayDays' => 4],
            ],
        ];
    }

    public static function config(): array
    {
        $c = array_merge(self::defaults(), (array) Settings::get('outreach', []));
        $c['steps'] = array_values(array_slice((array) $c['steps'], 0, 4)) ?: self::defaults()['steps'];

        return $c;
    }

    // ---------------------------------------------------------------- mailboxes

    /** How many mails this mailbox may send in 24 hours today, following its warm-up. */
    public static function dailyLimit(object $s, $now = null): int
    {
        $now = ($now ?? now())->copy()->timezone('Asia/Kolkata')->startOfDay();
        $days = $s->started_on ? max(0, (int) \Carbon\Carbon::parse($s->started_on, 'Asia/Kolkata')->startOfDay()->diffInDays($now, false)) : 0;

        return max(1, min((int) $s->daily_cap, (int) $s->warmup_start + (int) $s->warmup_step * $days));
    }

    public static function sentLast24h(string $senderId): int
    {
        return DB::table('outreach_sends')->where('sender_id', $senderId)->whereIn('status', ['sent', 'bounced'])->where('created_at', '>', now()->subDay())->count();
    }

    /** Builds an SMTP mailer for one mailbox, separate from the site's own mail settings. */
    private static function mailer(object $s)
    {
        return Mail::build(['transport' => 'smtp', 'host' => $s->smtp_host, 'port' => (int) $s->smtp_port, 'username' => $s->smtp_user, 'password' => Crypt::decryptString($s->smtp_password), 'scheme' => (int) $s->smtp_port === 465 ? 'smtps' : 'smtp', 'timeout' => 15]);
    }

    // ---------------------------------------------------------------- the send loop (cron, every minute)

    public static function process($now = null): array
    {
        $now ??= now();
        $out = ['sent' => 0, 'stopped' => 0, 'failed' => 0];
        if (! \Illuminate\Support\Facades\Schema::hasTable('outreach_contacts')) {
            return $out + ['paused' => 'not installed'];
        }
        $lock = Cache::lock('cvtara-outreach', 300);
        if (! $lock->get()) {
            return $out + ['paused' => 'busy'];
        }
        try {
            $out['stopped'] += self::syncRegistered();
            $c = self::config();
            if (! $c['enabled']) {
                return $out + ['paused' => 'off'];
            }
            $local = $now->copy()->timezone('Asia/Kolkata');
            $start = (int) $c['windowStart'];
            $end = (int) $c['windowEnd'];
            if ($local->hour < $start || $local->hour >= $end) {
                return $out + ['paused' => 'outside window'];
            }
            $windowSeconds = max(1, $end - $start) * 3600;
            foreach (DB::table('outreach_senders')->where('enabled', true)->orderBy('created_at')->get() as $s) {
                $limit = self::dailyLimit($s, $now);
                if (self::sentLast24h($s->id) >= $limit) {
                    continue;
                }
                // Spread the day's mail evenly across the window instead of sending it in a burst.
                $gap = max(60, intdiv($windowSeconds, $limit));
                if ($s->last_sent_at && $now->getTimestamp() - \Carbon\Carbon::parse($s->last_sent_at)->getTimestamp() < $gap - 5) {
                    continue;
                }
                $contact = self::nextContact($s, $now);
                if (! $contact) {
                    continue;
                }
                $r = self::sendOne($s, $contact, $c, $now);
                $out[$r]++;
            }
        } finally {
            $lock->release();
        }

        return $out;
    }

    /** Follow-ups that are due for this mailbox first, then a fresh contact (claimed for this mailbox from now on). */
    private static function nextContact(object $s, $now): ?object
    {
        $due = DB::table('outreach_contacts')->where('sender_id', $s->id)->where('status', 'active')->where('next_at', '<=', $now)->orderBy('next_at')->first();
        if ($due) {
            return $due;
        }
        // Claimed by this mailbox but not sent yet (its last try failed): retry once the back-off has passed.
        $retry = DB::table('outreach_contacts')->where('sender_id', $s->id)->where('status', 'queued')->where(fn ($q) => $q->whereNull('next_at')->orWhere('next_at', '<=', $now))->orderBy('created_at')->first();
        if ($retry) {
            return $retry;
        }
        for ($i = 0; $i < 3; $i++) {
            $fresh = DB::table('outreach_contacts')->where('status', 'queued')->whereNull('sender_id')->orderBy('created_at')->orderBy('id')->first();
            if (! $fresh) {
                return null;
            }
            if (DB::table('outreach_contacts')->where('id', $fresh->id)->whereNull('sender_id')->update(['sender_id' => $s->id, 'updated_at' => now()])) {
                $fresh->sender_id = $s->id;

                return $fresh;
            }
        }

        return null;
    }

    /** @return 'sent'|'stopped'|'failed' */
    private static function sendOne(object $s, object $ct, array $c, $now): string
    {
        // Last check right before sending: anyone who has a CVTara account by now is never mailed again.
        if ($uid = DB::table('users')->where('email', $ct->email)->value('id')) {
            DB::table('outreach_contacts')->where('id', $ct->id)->update(['status' => 'registered', 'user_id' => $uid, 'next_at' => null, 'updated_at' => now()]);

            return 'stopped';
        }
        $step = $c['steps'][$ct->step] ?? null;
        if (! $step) {
            DB::table('outreach_contacts')->where('id', $ct->id)->update(['status' => 'done', 'next_at' => null, 'updated_at' => now()]);

            return 'stopped';
        }
        $mail = self::render($step, $ct, $s, $c);
        $log = ['id' => (string) Str::uuid(), 'contact_id' => $ct->id, 'sender_id' => $s->id, 'step' => $ct->step + 1, 'subject' => mb_substr($mail['subject'], 0, 200), 'created_at' => now()];
        try {
            self::deliver($s, $ct->email, $mail);
        } catch (\Throwable $e) {
            $msg = mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 250);
            if (self::isRecipientBounce($msg)) {
                DB::table('outreach_sends')->insert($log + ['status' => 'bounced', 'error' => $msg]);
                DB::table('outreach_contacts')->where('id', $ct->id)->update(['status' => 'bounced', 'error' => $msg, 'next_at' => null, 'updated_at' => now()]);
                DB::table('outreach_senders')->where('id', $s->id)->update(['last_sent_at' => now(), 'failures' => 0, 'updated_at' => now()]);
                self::guardBounces($s);

                return 'failed';
            }
            DB::table('outreach_sends')->insert($log + ['status' => 'failed', 'error' => $msg]);
            DB::table('outreach_contacts')->where('id', $ct->id)->update(['error' => $msg, 'next_at' => $now->copy()->addMinutes(30), 'updated_at' => now()]);
            $fails = (int) $s->failures + 1;
            DB::table('outreach_senders')->where('id', $s->id)->update(['failures' => $fails, 'last_sent_at' => now(), 'updated_at' => now()] + ($fails >= 3 ? ['enabled' => false, 'paused_reason' => 'Paused after 3 failed sends in a row: '.mb_substr($msg, 0, 180)] : []));
            if ($fails >= 3) {
                // People this mailbox never reached move to the other mailboxes; people mid-sequence wait for it to resume.
                DB::table('outreach_contacts')->where('sender_id', $s->id)->where('status', 'queued')->update(['sender_id' => null, 'next_at' => null, 'updated_at' => now()]);
            }

            return 'failed';
        }
        DB::table('outreach_sends')->insert($log + ['status' => 'sent']);
        $next = $c['steps'][$ct->step + 1] ?? null;
        DB::table('outreach_contacts')->where('id', $ct->id)->update(['status' => $next ? 'active' : 'done', 'step' => $ct->step + 1, 'last_sent_at' => now(), 'next_at' => $next ? $now->copy()->addDays(max(1, (int) ($next['delayDays'] ?? 3))) : null, 'error' => '', 'updated_at' => now()]);
        DB::table('outreach_senders')->where('id', $s->id)->update(['last_sent_at' => now(), 'failures' => 0, 'started_on' => $s->started_on ?: $now->copy()->timezone('Asia/Kolkata')->toDateString(), 'updated_at' => now()]);

        return 'sent';
    }

    public static function deliver(object $s, string $to, array $mail): void
    {
        self::mailer($s)->send([], [], function ($m) use ($s, $to, $mail) {
            $m->from($s->email, $s->from_name ?: null)->to($to)->subject($mail['subject'])->text($mail['text'])->html($mail['html']);
            $h = $m->getSymfonyMessage()->getHeaders();
            if (! empty($mail['unsubscribe'])) {
                $h->addTextHeader('List-Unsubscribe', '<'.$mail['unsubscribe'].'>, <mailto:'.$s->email.'?subject=Unsubscribe>');
                $h->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
        });
    }

    /** A permanent "this address does not exist" answer, as opposed to a problem with the mailbox or server. */
    public static function isRecipientBounce(string $msg): bool
    {
        if (preg_match('/\b(530|534|535|421|451|452)\b/', $msg)) {
            return false;
        }

        return (bool) preg_match('/\b55[013]\b|user unknown|no such user|unknown user|mailbox (unavailable|not found)|does not exist|invalid recipient|recipient address rejected|address rejected/i', $msg);
    }

    /** Too many bad addresses hurt the domain: pause the mailbox when 10% or more of its last 40 mails bounced. */
    private static function guardBounces(object $s): void
    {
        $last = DB::table('outreach_sends')->where('sender_id', $s->id)->whereIn('status', ['sent', 'bounced'])->orderByDesc('created_at')->limit(40)->pluck('status');
        if ($last->count() >= 10 && $last->filter(fn ($x) => $x === 'bounced')->count() / $last->count() >= 0.1) {
            DB::table('outreach_senders')->where('id', $s->id)->update(['enabled' => false, 'paused_reason' => 'Paused: too many addresses bounced. Clean the list before resuming.', 'updated_at' => now()]);
        }
    }

    /** Contacts who signed up on CVTara stop for good (and count as sign-ups). */
    public static function syncRegistered(): int
    {
        $rows = DB::table('outreach_contacts as c')->join('users as u', 'u.email', '=', 'c.email')->whereNotIn('c.status', self::STOPPED)->limit(1000)->get(['c.id', 'u.id as uid']);
        foreach ($rows as $r) {
            DB::table('outreach_contacts')->where('id', $r->id)->update(['status' => 'registered', 'user_id' => $r->uid, 'next_at' => null, 'updated_at' => now()]);
        }

        return $rows->count();
    }

    /** Called when an account is created, so the stop happens at once rather than on the next cron run. */
    public static function registered(string $email, int $userId): void
    {
        try {
            DB::table('outreach_contacts')->where('email', mb_strtolower($email))->whereNotIn('status', self::STOPPED)->update(['status' => 'registered', 'user_id' => $userId, 'next_at' => null, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            // Sign-up must never fail because of outreach bookkeeping; the cron sync catches it later.
        }
    }

    // ---------------------------------------------------------------- content

    public static function unsubscribeUrl(string $contactId): string
    {
        return URL::signedRoute('outreach.unsubscribe', ['contact' => $contactId]);
    }

    /** Pages a tracked link may open. */
    public const LANDINGS = ['home' => '/', 'ats' => '/resume-match', 'plans' => '/#pricing', 'register' => '/register'];

    /** A link that counts the click for this contact, then opens the page with campaign tags. */
    public static function clickUrl(string $contactId, int $step, string $to = 'home'): string
    {
        return URL::signedRoute('outreach.click', ['contact' => $contactId, 'step' => $step, 'to' => $to]);
    }

    /** @return array{subject:string,text:string,html:string,unsubscribe:string} */
    public static function render(array $step, object $ct, object $s, array $c): array
    {
        $first = trim(explode(' ', trim((string) $ct->name))[0] ?? '');
        $unsub = self::unsubscribeUrl($ct->id);
        $site = rtrim((string) config('app.url'), '/');
        $step_no = (int) ($ct->step ?? 0) + 1;
        $vars = ['name' => $first !== '' ? $first : 'there', 'full_name' => trim((string) $ct->name), 'email' => $ct->email, 'site_url' => self::clickUrl($ct->id, $step_no), 'ats_url' => self::clickUrl($ct->id, $step_no, 'ats'), 'plans_url' => self::clickUrl($ct->id, $step_no, 'plans'), 'home_url' => $site, 'sender_name' => $s->from_name ?: '', 'unsubscribe_url' => $unsub];
        $fill = fn ($t) => preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), (string) $t);
        $subject = trim(preg_replace('/\s+/', ' ', $fill($step['subject'] ?? '')));
        $body = trim(str_replace("\r", '', $fill($step['body'] ?? '')));
        $sign = trim(($s->from_name ?: '')."\n".trim((string) ($c['footer'] ?? '')));
        $text = $body.($sign !== '' ? "\n\n".$sign : '')."\n\nDon't want these emails? Unsubscribe: ".$unsub."\n";
        $link = fn ($s) => preg_replace('~(https?://[^\s<]+)~', '<a href="$1">$1</a>', $s);
        $paras = array_map(fn ($p) => '<p style="margin:0 0 14px">'.nl2br($link(e($p)), false).'</p>', preg_split("/\n{2,}/", $body));
        $html = '<!doctype html><html><body style="margin:0;padding:16px;font:15px/1.6 Arial,Helvetica,sans-serif;color:#1f2937">'.implode('', $paras)
            .($sign !== '' ? '<p style="margin:18px 0 0">'.nl2br(e($sign), false).'</p>' : '')
            .'<p style="margin:24px 0 0;font-size:12px;color:#6b7280">Don\'t want these emails? <a href="'.e($unsub).'" style="color:#6b7280">Unsubscribe</a>.</p></body></html>';

        return ['subject' => $subject, 'text' => $text, 'html' => $html, 'unsubscribe' => $unsub];
    }

    // ---------------------------------------------------------------- list import and clean-up

    /**
     * Imports "email,name" lines (a header row is optional). Every address is checked before it is added.
     *
     * @return array{received:int,added:int,invalid:int,role:int,noMail:int,duplicate:int,existing:int,users:int,suppressed:int,samples:array}
     */
    public static function import(string $raw, string $source, bool $checkDomains = true): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));
        $res = ['received' => 0, 'added' => 0, 'invalid' => 0, 'role' => 0, 'noMail' => 0, 'duplicate' => 0, 'existing' => 0, 'users' => 0, 'suppressed' => 0, 'samples' => []];
        $emailCol = 0;
        $nameCol = 1;
        $first = str_getcsv((string) ($lines[0] ?? ''), ',', '"', '');
        $head = array_map(fn ($x) => mb_strtolower(trim((string) $x)), $first);
        if (in_array('email', $head, true) || in_array('e-mail', $head, true)) {
            $emailCol = array_search('email', $head, true);
            $emailCol = $emailCol === false ? array_search('e-mail', $head, true) : $emailCol;
            $nameCol = array_search('name', $head, true);
            $nameCol = $nameCol === false ? array_search('first name', $head, true) : $nameCol;
            array_shift($lines);
        }
        $seen = [];
        $rows = [];
        $sample = function (string $why, string $e) use (&$res) {
            if (count($res['samples']) < 12) {
                $res['samples'][] = ['email' => mb_substr($e, 0, 120), 'reason' => $why];
            }
        };
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $res['received']++;
            if ($res['received'] > self::MAX_IMPORT) {
                break;
            }
            $cells = str_getcsv($line, str_contains($line, ',') || ! str_contains($line, ';') ? ',' : ';', '"', '');
            $email = mb_strtolower(trim((string) ($cells[$emailCol] ?? ''), " \t<>\"'"));
            $name = $nameCol === false ? '' : trim(strip_tags((string) ($cells[$nameCol] ?? '')));
            if (strlen($email) > 190 || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! preg_match('/^[^@\s]+@[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}$/', $email)) {
                $res['invalid']++;
                $sample('Not a valid address', $email);

                continue;
            }
            $local = explode('@', $email)[0];
            if (in_array($local, self::ROLE_PREFIXES, true)) {
                $res['role']++;
                $sample('System address (no-reply, postmaster…)', $email);

                continue;
            }
            if (isset($seen[$email])) {
                $res['duplicate']++;

                continue;
            }
            $seen[$email] = true;
            $rows[$email] = mb_substr($name, 0, 120);
        }
        $res['received'] = min($res['received'], self::MAX_IMPORT);
        // Domains that cannot receive mail (no MX or A record). Checked once per domain, within a time budget.
        if ($checkDomains && $rows) {
            $deadline = microtime(true) + 25;
            $bad = [];
            foreach (array_unique(array_map(fn ($e) => explode('@', $e)[1], array_keys($rows))) as $d) {
                if (in_array($d, self::KNOWN_DOMAINS, true) || microtime(true) > $deadline) {
                    continue;
                }
                $ok = Cache::remember('outreach-mx:'.$d, 86400, fn () => (checkdnsrr($d, 'MX') || checkdnsrr($d, 'A')) ? 1 : 0);
                if (! $ok) {
                    $bad[$d] = true;
                }
            }
            foreach (array_keys($rows) as $e) {
                if (isset($bad[explode('@', $e)[1]])) {
                    unset($rows[$e]);
                    $res['noMail']++;
                    $sample('Domain cannot receive mail', $e);
                }
            }
        }
        foreach (array_chunk(array_keys($rows), 500) as $chunk) {
            $known = DB::table('outreach_contacts')->whereIn('email', $chunk)->pluck('status', 'email');
            $users = DB::table('users')->whereIn('email', $chunk)->pluck('email')->flip();
            $insert = [];
            foreach ($chunk as $e) {
                if (isset($users[$e])) {
                    $res['users']++;

                    continue;
                }
                if (isset($known[$e])) {
                    in_array($known[$e], self::STOPPED, true) ? $res['suppressed']++ : $res['existing']++;

                    continue;
                }
                $insert[] = ['id' => (string) Str::uuid(), 'email' => $e, 'name' => $rows[$e], 'source' => mb_substr($source, 0, 120), 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()];
            }
            if ($insert) {
                $res['added'] += DB::table('outreach_contacts')->insertOrIgnore($insert);
            }
        }

        return $res;
    }

    /** Marks addresses as unsubscribed or bounced (for example bounce notices that arrived in the mailbox later). */
    public static function suppress(array $emails, string $status): int
    {
        $n = 0;
        foreach (array_chunk(array_values(array_unique(array_filter(array_map(fn ($e) => mb_strtolower(trim((string) $e, " \t<>\"'")), $emails), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)))), 500) as $chunk) {
            $n += DB::table('outreach_contacts')->whereIn('email', $chunk)->where('status', '!=', 'registered')->update(['status' => $status, 'next_at' => null, 'updated_at' => now()]);
            $missing = array_diff($chunk, DB::table('outreach_contacts')->whereIn('email', $chunk)->pluck('email')->all());
            if ($missing) {
                $n += DB::table('outreach_contacts')->insertOrIgnore(array_map(fn ($e) => ['id' => (string) Str::uuid(), 'email' => $e, 'name' => '', 'source' => 'suppression', 'status' => $status, 'created_at' => now(), 'updated_at' => now()], array_values($missing)));
            }
        }

        return $n;
    }
}
