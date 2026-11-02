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
 * - Two lists, each with its own sequence: students (job seekers) and colleges (placement officers).
 *   Each list can send from its own alias of the mailbox (campus@ for students, placements@ for colleges), so one
 *   mailbox and one daily limit serve both; an alias on another domain than the mailbox is ignored.
 * - Part of each mailbox's daily amount (40% by default) goes to short warm-up notes to the owner's own addresses,
 *   so the mailbox keeps a steady, healthy history. With nothing else to send, warm-up may use the whole day.
 */
class Outreach
{
    public const STATUSES = ['queued', 'active', 'done', 'registered', 'unsubscribed', 'bounced', 'removed'];

    /** Statuses that never get mail again. */
    public const STOPPED = ['registered', 'unsubscribed', 'bounced', 'removed'];

    public const MAX_IMPORT = 20000;

    public const LISTS = ['students' => 'Students', 'colleges' => 'Colleges (TPOs)'];

    public const MAX_SEEDS = 50;

    /** Warm-up notes: short, varied, no links, so they read like ordinary mail between people. */
    private const WARMUP = [
        ['Quick check-in', "Hi,\n\nJust checking this address works on your side. No action needed.\n\nThanks"],
        ['Notes from today', "Hello,\n\nSharing a quick note: today's resume reviews went well and the new templates look good.\n\nRegards"],
        ['Re: schedule for this week', "Hi,\n\nTuesday and Thursday afternoons work for me this week. Let me know what suits you.\n\nThanks"],
        ['Following up', "Hello,\n\nFollowing up on our last conversation. Happy to talk whenever you're free.\n\nBest"],
        ['Thanks for yesterday', "Hi,\n\nThanks for your time yesterday. I'll send the summary by the end of the week.\n\nRegards"],
        ['Placement season dates', "Hello,\n\nMost campus drives seem to start next month. I'll keep you posted as dates come in.\n\nThanks"],
        ['Small update', "Hi,\n\nA small update: the resume checklist is finished. I'll share it soon.\n\nBest regards"],
        ['Re: the shortlist', "Hello,\n\nGot it, thanks. I'll go through the shortlist and come back to you tomorrow.\n\nRegards"],
    ];

    private const ROLE_PREFIXES = ['noreply', 'no-reply', 'donotreply', 'do-not-reply', 'mailer-daemon', 'postmaster', 'abuse', 'bounce', 'bounces'];

    private const KNOWN_DOMAINS = ['gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.in', 'yahoo.in', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'icloud.com', 'me.com', 'rediffmail.com', 'aol.com', 'proton.me', 'protonmail.com', 'zoho.com', 'zohomail.in'];

    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'windowStart' => 9,
            'windowEnd' => 19,
            'footer' => '',
            'warmup' => ['share' => 40, 'seeds' => []],
            'lists' => [
                'students' => ['design' => 'card', 'from' => 'campus@cvtara.in', 'steps' => [
                    ['subject' => '{{name}}, will your resume get shortlisted?', 'delayDays' => 0, 'body' => "# Will your resume get shortlisted?\n[preheader: A free one-minute check shows what your resume is missing for the job you want.]\nHi {{name}},\n\nMost companies that hire freshers now screen resumes with software (an ATS) before a recruiter reads them. If your resume misses the words the job asks for, it can be filtered out in seconds.\n\nCVTara's free ATS check compares your resume with any job description and shows you:\n- your match score for that job\n- the exact keywords you are missing\n- what to fix before you apply\n\n[button: Check my resume free | {{ats_url}}]\n\n[note: It takes about a minute: add your resume, paste the job description and see your score.]\n\nAll the best for placement season,\n{{sender_name}}"],
                    ['subject' => '{{name}}, one quick placement tip', 'delayDays' => 3, 'body' => "# One resume, tailored to every job\n[preheader: Same facts, the right words for each role, ready in a click.]\nHi {{name}},\n\nA quick placement tip: recruiters shortlist resumes that match the role. Sending the same resume everywhere is one of the most common reasons freshers hear nothing back.\n\nWith CVTara you build one resume and get:\n- a tailored version for each job, in one click\n- clean, ATS-friendly designs made for freshers\n- a matching cover letter for every application\n\n[button: Build my resume free | {{site_url}}]\n\n[button: Friends applying too? Share the free check on WhatsApp | {{share_url}}]\n\n{{sender_name}}"],
                    ['subject' => '{{name}}, your resume before the next interview', 'delayDays' => 5, 'body' => "# Your resume, ready before the next interview\n[preheader: Build and check for free. Pay only when you download, from {{from_price}}.]\nHi {{name}},\n\nThis is my last mail about it, so here is everything in one place:\n- building, editing and ATS checks on CVTara are free\n- you pay once, only to download a clean copy, from {{from_price}}\n- no auto-renewal, and a full refund within 7 days if you have not used it\n\n[offer]\n\n[button: See the packages | {{plans_url}}]\n\nAll the best for your placements,\n{{sender_name}}"],
                ]],
                'colleges' => ['design' => 'plain', 'from' => 'placements@cvtara.in', 'steps' => [
                    ['subject' => 'Free ATS resume check for {{college}} students', 'delayDays' => 0, 'body' => "Hello {{name}},\n\nI'm writing from CVTara, an Indian resume platform for freshers. Many capable students lose out at the shortlist stage because their resume is not written for the screening software (ATS) that most companies now use.\n\nThis placement season we would like to offer {{college}}'s final-year students our ATS resume check, free of cost:\n- each student sees how their resume scores against a real job description\n- they get the missing keywords and clear fixes in minutes\n- you share one link with the batch, with nothing to install or pay\n\nShall I send you the link with a short note you can forward to students? A one-line reply is enough.\n\nWarm regards,\n{{sender_name}}"],
                    ['subject' => 'Re: Free ATS resume check for {{college}} students', 'delayDays' => 4, 'body' => "Hello {{name}},\n\nFollowing up on my earlier note. Your students can start using the check right away:\n\n[button: Free ATS resume check | {{ats_url}}]\n\nIf it helps, we can also run a free 30-minute online session for the batch on writing a resume that clears ATS screening.\n\nWould a date in the next two weeks suit you? Just reply with a day that works.\n\nWarm regards,\n{{sender_name}}"],
                    ['subject' => 'Re: Free ATS resume check for {{college}} students', 'delayDays' => 7, 'body' => "Hello {{name}},\n\nI don't want to crowd your inbox, so this is my last note. If the free ATS check or a resume session would help {{college}}'s students this season, simply reply and we will set it up.\n\nYou can also forward the check to your students in one tap:\n\n[button: Share on WhatsApp | {{share_url}}]\n\nThank you for all you do for your students.\n\nWarm regards,\n{{sender_name}}"],
                ]],
            ],
        ];
    }

    /** Earlier recommended sequences (see fingerprint()), replaced by the current ones unless the owner edited them. */
    private const OLD_DEFAULTS = ['students' => ['0612dc5de71940f0ee2ecee4dfc65a46'], 'colleges' => ['54d0eb010703dd1f863a475a8bdf2d68']];

    public static function fingerprint(array $steps): string
    {
        return md5(implode("\x1f", array_map(fn ($s) => trim((string) ($s['subject'] ?? ''))."\x1e".trim(str_replace("\r", '', (string) ($s['body'] ?? ''))), $steps)));
    }

    public static function config(): array
    {
        $d = self::defaults();
        $saved = (array) Settings::get('outreach', []);
        $c = array_merge($d, array_intersect_key($saved, array_flip(['enabled', 'windowStart', 'windowEnd', 'footer'])));
        $c['warmup'] = array_merge($d['warmup'], (array) ($saved['warmup'] ?? []));
        $savedLists = (array) ($saved['lists'] ?? []);
        if (! $savedLists && ! empty($saved['steps'])) {
            $savedLists = ['students' => ['steps' => $saved['steps'], 'design' => 'plain']]; // saved before there were two lists
        }
        foreach ($d['lists'] as $k => $def) {
            $l = array_merge($def, (array) ($savedLists[$k] ?? []));
            $l['steps'] = array_values(array_slice((array) $l['steps'], 0, 4)) ?: $def['steps'];
            // A list still on an earlier recommended sequence, word for word, moves to the current one; edited mails are kept.
            if (in_array(self::fingerprint($l['steps']), self::OLD_DEFAULTS[$k] ?? [], true)) {
                $l['steps'] = $def['steps'];
            }
            $l['design'] = in_array($l['design'], ['card', 'plain'], true) ? $l['design'] : $def['design'];
            $l['from'] = mb_strtolower(trim((string) ($l['from'] ?? '')));
            $c['lists'][$k] = $l;
        }

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

    public static function sentLast24h(string $senderId, ?string $kind = null): int
    {
        return DB::table('outreach_sends')->where('sender_id', $senderId)->whereIn('status', ['sent', 'bounced'])->where('created_at', '>', now()->subDay())->when($kind, fn ($q) => $q->where('kind', $kind))->count();
    }

    /** The owner's own warm-up addresses, without this mailbox itself. */
    public static function warmupTargets(object $s, array $c): array
    {
        return array_values(array_filter((array) ($c['warmup']['seeds'] ?? []), fn ($e) => $e !== $s->email));
    }

    /** @return 'sent'|'failed' */
    private static function sendWarmup(object $s, array $seeds, $now): string
    {
        // Rotate through the addresses and the notes so no two mails in a row look alike.
        $n = DB::table('outreach_sends')->where('sender_id', $s->id)->where('kind', 'warmup')->count();
        $to = $seeds[$n % count($seeds)];
        [$subject, $body] = self::WARMUP[($n + crc32($s->id)) % count(self::WARMUP)];
        $text = $body.($s->from_name ? "\n".$s->from_name : '')."\n";
        $log = ['id' => (string) Str::uuid(), 'kind' => 'warmup', 'contact_id' => null, 'to_email' => $to, 'sender_id' => $s->id, 'step' => 0, 'subject' => $subject, 'created_at' => now()];
        try {
            self::mailer($s)->send([], [], fn ($m) => $m->from($s->email, $s->from_name ?: null)->to($to)->subject($subject)->text($text));
        } catch (\Throwable $e) {
            $msg = mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 250);
            DB::table('outreach_sends')->insert($log + ['status' => self::isRecipientBounce($msg) ? 'bounced' : 'failed', 'error' => $msg]);
            if (! self::isRecipientBounce($msg)) {
                self::failed($s, $msg);
            } else {
                DB::table('outreach_senders')->where('id', $s->id)->update(['last_sent_at' => now(), 'updated_at' => now()]);
            }

            return 'failed';
        }
        DB::table('outreach_sends')->insert($log + ['status' => 'sent', 'error' => '']);
        DB::table('outreach_senders')->where('id', $s->id)->update(['last_sent_at' => now(), 'failures' => 0, 'started_on' => $s->started_on ?: $now->copy()->timezone('Asia/Kolkata')->toDateString(), 'updated_at' => now()]);

        return 'sent';
    }

    /** Counts a failed send; three in a row pause the mailbox. */
    private static function failed(object $s, string $msg): void
    {
        $fails = (int) $s->failures + 1;
        DB::table('outreach_senders')->where('id', $s->id)->update(['failures' => $fails, 'last_sent_at' => now(), 'updated_at' => now()] + ($fails >= 3 ? ['enabled' => false, 'paused_reason' => 'Paused after 3 failed sends in a row: '.mb_substr($msg, 0, 180)] : []));
        if ($fails >= 3) {
            // People this mailbox never reached move to the other mailboxes; people mid-sequence wait for it to resume.
            DB::table('outreach_contacts')->where('sender_id', $s->id)->where('status', 'queued')->update(['sender_id' => null, 'next_at' => null, 'updated_at' => now()]);
        }
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
        $out = ['sent' => 0, 'warmup' => 0, 'stopped' => 0, 'failed' => 0];
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
                // Each gap is 70–100% of the even spacing, varied per send, so mail never leaves at a fixed beat.
                $gap = max(60, (int) round(intdiv($windowSeconds, $limit) * (70 + crc32($s->id.'|'.$s->last_sent_at) % 31) / 100));
                if ($s->last_sent_at && $now->getTimestamp() - \Carbon\Carbon::parse($s->last_sent_at)->getTimestamp() < $gap - 5) {
                    continue;
                }
                // Warm-up takes its share of the day, interleaved with the campaign; it may use the whole day when no one is waiting.
                $seeds = self::warmupTargets($s, $c);
                $share = $seeds ? max(0, min(60, (int) $c['warmup']['share'])) : 0;
                $sent = self::sentLast24h($s->id);
                $warmSent = self::sentLast24h($s->id, 'warmup');
                $warmQuota = (int) round($limit * $share / 100);
                $warmDue = $warmSent < $warmQuota && ($sent - $warmSent >= $limit - $warmQuota || $warmSent * 100 < $share * ($sent + 1));
                $contact = $warmDue ? null : self::nextContact($s, $now);
                if ($contact) {
                    $out[self::sendOne($s, $contact, $c, $now)]++;
                } elseif ($seeds) {
                    $r = self::sendWarmup($s, $seeds, $now);
                    $out[$r === 'sent' ? 'warmup' : $r]++;
                }
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
        $steps = self::listOf($c, $ct)['steps'];
        $step = $steps[$ct->step] ?? null;
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
            self::failed($s, $msg);

            return 'failed';
        }
        DB::table('outreach_sends')->insert($log + ['status' => 'sent']);
        $next = $steps[$ct->step + 1] ?? null;
        DB::table('outreach_contacts')->where('id', $ct->id)->update(['status' => $next ? 'active' : 'done', 'step' => $ct->step + 1, 'last_sent_at' => now(), 'next_at' => $next ? $now->copy()->addDays(max(1, (int) ($next['delayDays'] ?? 3))) : null, 'error' => '', 'updated_at' => now()]);
        DB::table('outreach_senders')->where('id', $s->id)->update(['last_sent_at' => now(), 'failures' => 0, 'started_on' => $s->started_on ?: $now->copy()->timezone('Asia/Kolkata')->toDateString(), 'updated_at' => now()]);

        return 'sent';
    }

    public static function deliver(object $s, string $to, array $mail): void
    {
        self::mailer($s)->send([], [], function ($m) use ($s, $to, $mail) {
            $from = $mail['from'] ?? $s->email;
            $m->from($from, $s->from_name ?: null)->to($to)->subject($mail['subject'])->text($mail['text'])->html($mail['html']);
            $h = $m->getSymfonyMessage()->getHeaders();
            if (! empty($mail['unsubscribe'])) {
                $h->addTextHeader('List-Unsubscribe', '<'.$mail['unsubscribe'].'>, <mailto:'.$from.'?subject=Unsubscribe>');
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
        $last = DB::table('outreach_sends')->where('sender_id', $s->id)->where('kind', 'campaign')->whereIn('status', ['sent', 'bounced'])->orderByDesc('created_at')->limit(40)->pluck('status');
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

    /** Pages a tracked link may open. "share" opens WhatsApp with a ready message about the free ATS check. */
    public const LANDINGS = ['home' => '/', 'ats' => '/ats-checker/', 'plans' => '/#pricing', 'register' => '/register', 'share' => '/ats-checker/'];

    /** A link that counts the click for this contact, then opens the page with campaign tags. */
    public static function clickUrl(string $contactId, int $step, string $to = 'home'): string
    {
        return URL::signedRoute('outreach.click', ['contact' => $contactId, 'step' => $step, 'to' => $to]);
    }

    /** The address a list sends from: its alias when that alias is on the mailbox's own domain, otherwise the mailbox itself. */
    public static function fromFor(object $s, array $list): string
    {
        $alias = mb_strtolower(trim((string) ($list['from'] ?? '')));
        $domain = fn ($e) => mb_strtolower((string) substr(strrchr($e, '@'), 1));

        return $alias !== '' && filter_var($alias, FILTER_VALIDATE_EMAIL) && $domain($alias) === $domain($s->email) ? $alias : $s->email;
    }

    public static function listOf(array $c, object $ct): array
    {
        return $c['lists'][$ct->list ?? 'students'] ?? $c['lists']['students'];
    }

    /** @return array{subject:string,text:string,html:string,unsubscribe:string,from:string} */
    public static function render(array $step, object $ct, object $s, array $c, ?string $design = null): array
    {
        $list = $ct->list ?? 'students';
        $from = self::fromFor($s, (array) ($c['lists'][$list] ?? []));
        $design ??= self::listOf($c, $ct)['design'];
        // "Dr. Ramesh Kumar" → "Ramesh": greet by first name without the title.
        $first = trim(explode(' ', trim(preg_replace('/^((dr|prof|mr|mrs|ms|miss|shri|sri|smt)\.?\s+)+/i', '', trim((string) $ct->name))))[0] ?? '');
        $unsub = self::unsubscribeUrl($ct->id);
        $site = rtrim((string) config('app.url'), '/');
        $step_no = (int) ($ct->step ?? 0) + 1;
        $vars = ['name' => $first !== '' ? $first : ($list === 'colleges' ? 'Sir/Madam' : 'there'), 'full_name' => trim((string) $ct->name), 'email' => $ct->email,
            'college' => trim((string) ($ct->college ?? '')) ?: 'your college', 'designation' => trim((string) ($ct->designation ?? '')), 'city' => trim((string) ($ct->city ?? '')),
            'site_url' => self::clickUrl($ct->id, $step_no), 'ats_url' => self::clickUrl($ct->id, $step_no, 'ats'), 'plans_url' => self::clickUrl($ct->id, $step_no, 'plans'), 'share_url' => self::clickUrl($ct->id, $step_no, 'share'),
            'home_url' => $site, 'sender_name' => $s->from_name ?: '', 'unsubscribe_url' => $unsub] + array_intersect_key(MailAutomation::sharedVars() + MailAutomation::liveVars(), array_flip(['from_price', 'tara_line', 'expert_line', 'offer_line', 'referral_reward', 'referral_discount', 'referral_min']));
        $fill = fn ($t) => preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), (string) $t);
        $subject = trim(preg_replace('/\s+/', ' ', $fill($step['subject'] ?? '')));
        $body = trim(str_replace("\r", '', $fill($step['body'] ?? '')));
        $sign = trim(($s->from_name ?: '')."\n".trim((string) ($c['footer'] ?? '')));
        // The body is "{{sender_name}}"-signed in most templates; avoid printing the name twice.
        if ($s->from_name && str_ends_with($body, $s->from_name)) {
            $sign = trim((string) ($c['footer'] ?? ''));
        }

        // Same template text as account mail ("# Heading", "- " bullets, "[button: Label | link]", "[offer]" ...).
        // Lines that are empty once filled (for example {{offer_line}} while no offer runs) are left out.
        $parsed = MailLayout::blocks($body);
        $head = $parsed['headline'];
        $card = $design === 'card';
        $c = MailLayout::C;
        if ($card) {
            $content = MailLayout::content($parsed, 'light');
            $html = $content['html'];
            $text = ($head !== '' ? $head."\n\n" : '').$content['text'];
        } else {
            // Plain: looks like a personal note (no frame, no button), which suits placement officers and the Primary tab.
            $text = ($head !== '' ? $head."\n\n" : '');
            $html = $head !== '' ? '<p style="margin:0 0 14px;font-weight:700">'.e($head).'</p>' : '';
            $link = fn ($x) => preg_replace('~(https?://[^\s<]+[^\s<.,;:!?)\]])~', '<a href="$1" style="color:'.$c['accent'].'">$1</a>', $x);
            foreach ($parsed['blocks'] as [$k, $v]) {
                if ($k === 'btn') {
                    $text .= $v[0].': '.$v[1]."\n\n";
                    $html .= '<p style="margin:0 0 14px"><a href="'.e($v[1]).'" style="color:'.$c['accent'].';font-weight:700">'.e($v[0]).' &rarr;</a></p>';
                } elseif ($k === 'ul') {
                    $text .= implode("\n", array_map(fn ($i) => '- '.$i, $v))."\n\n";
                    $html .= '<ul style="margin:0 0 14px;padding-left:20px">'.implode('', array_map(fn ($i) => '<li style="margin:0 0 6px">'.$link(e($i)).'</li>', $v)).'</ul>';
                } elseif (in_array($k, ['p', 'h2', 'note'], true)) {
                    $text .= $v."\n\n";
                    $html .= '<p style="margin:0 0 14px">'.nl2br($link(e($v)), false).'</p>';
                } elseif (in_array($k, ['offer', 'plans'], true)) {
                    $part = MailLayout::content(['blocks' => [[$k, null]]] + $parsed);
                    $text .= $part['text'];
                    $html .= $k === 'offer' && ($line = MailLayout::offerLine()) !== '' ? '<p style="margin:0 0 14px">'.e($line).'</p>' : ($k === 'plans' ? '<p style="margin:0 0 14px">'.nl2br(e(trim($part['text'])), false).'</p>' : '');
                }
            }
        }
        // Who is writing and from where, on every outreach mail: readers and mail filters both look for it.
        $postal = 'CVTara · '.EmailRenderer::postalAddress().' · '.EmailRenderer::supportAddress();
        $text = rtrim($text).($sign !== '' ? "\n\n".$sign : '')."\n\n--\n".$postal."\nDon't want these emails? Unsubscribe: ".$unsub."\n";
        $f = MailLayout::FONT;
        $foot = '<p class="cv-mut" style="margin:22px 0 0;font-family:'.$f.';font-size:12px;line-height:1.6;color:'.$c['muted'].'">'.e($postal).'<br>Don\'t want these emails? <a href="'.e($unsub).'" class="cv-mut" style="color:'.$c['muted'].'">Unsubscribe</a>.</p>';
        $signHtml = $sign !== '' ? '<p class="cv-txt" style="margin:18px 0 0;font-family:'.$f.';font-size:15px;line-height:1.6;color:'.$c['text'].'">'.nl2br(e($sign), false).'</p>' : '';
        if ($card) {
            // The light card: small logo and wordmark, no colour band or other images, so it reads like a note rather than a newsletter.
            $html = MailLayout::page(['subject' => $subject, 'preheader' => $parsed['preheader'], 'headline' => $head, 'content' => $html, 'signature' => $signHtml.$foot, 'footer' => '', 'variant' => 'light']);
        } else {
            $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($subject).'</title></head><body style="margin:0;padding:16px;font:15px/1.6 Arial,Helvetica,sans-serif;color:#1f2937">'
                .$html.$signHtml.$foot.'</body></html>';
        }

        return ['subject' => $subject, 'text' => $text, 'html' => $html, 'unsubscribe' => $unsub, 'from' => $from];
    }

    // ---------------------------------------------------------------- list import and clean-up

    /**
     * Imports "email,name" lines (a header row is optional; with one, college, designation and city columns are read too).
     * Every address is checked before it is added.
     *
     * @return array{received:int,added:int,invalid:int,role:int,noMail:int,duplicate:int,existing:int,users:int,suppressed:int,samples:array}
     */
    /**
     * The first worksheet of an Excel (.xlsx) file as tab-separated rows, ready for import(). Empty when the file cannot be read.
     * Reads the sheet as a stream, so a 20,000-row file stays light.
     */
    public static function sheetText(string $bytes): string
    {
        if (! class_exists(\ZipArchive::class) || ! str_starts_with($bytes, "PK")) {
            return '';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'cvx');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive;
        try {
            if ($zip->open($tmp) !== true) {
                return '';
            }
            $shared = [];
            if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $doc = @simplexml_load_string($xml);
                foreach ($doc ? $doc->si : [] as $si) {
                    $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]') ?: []));
                }
            }
            // The first sheet in the workbook's order, through its relationship id; sheet1.xml if that cannot be read.
            $path = 'xl/worksheets/sheet1.xml';
            $wb = @simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
            $rels = @simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
            if ($wb && $rels && isset($wb->sheets->sheet[0])) {
                $rid = (string) ($wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
                foreach ($rels->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $target = ltrim((string) $rel['Target'], '/');
                        $path = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                    }
                }
            }
            $sheet = $zip->getFromName($path);
            if ($sheet === false) {
                return '';
            }
            $r = new \XMLReader;
            $r->XML($sheet);
            $out = [];
            $row = null;
            while ($r->read() && count($out) <= self::MAX_IMPORT + 1) {
                if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'row') {
                    $row = [];
                } elseif ($r->nodeType === \XMLReader::END_ELEMENT && $r->localName === 'row') {
                    if ($row && implode('', $row) !== '') {
                        ksort($row);
                        $out[] = implode("\t", array_replace(array_fill(0, max(array_keys($row)) + 1, ''), $row));
                    }
                    $row = null;
                } elseif ($row !== null && $r->nodeType === \XMLReader::ELEMENT && $r->localName === 'c') {
                    $type = (string) $r->getAttribute('t');
                    $col = 0;
                    foreach (str_split(preg_replace('/[^A-Z]/', '', strtoupper((string) $r->getAttribute('r')))) as $ch) {
                        $col = $col * 26 + ord($ch) - 64;
                    }
                    $cell = new \SimpleXMLElement($r->readOuterXml());
                    $val = $type === 'inlineStr' ? implode('', array_map('strval', $cell->xpath('.//*[local-name()="t"]') ?: [])) : (string) ($cell->v ?? '');
                    if ($type === 's') {
                        $val = $shared[(int) $val] ?? '';
                    }
                    $row[max(0, $col - 1)] = trim(str_replace(["\t", "\r", "\n"], ' ', $val));
                }
            }

            return implode("\n", $out);
        } finally {
            $zip->close();
            @unlink($tmp);
        }
    }

    /** The column separator of an import: tab, semicolon or comma, whichever the first row uses most. */
    public static function separator(string $line): string
    {
        $n = ["\t" => substr_count($line, "\t"), ';' => substr_count($line, ';'), ',' => substr_count($line, ',')];
        arsort($n);

        return reset($n) > 0 ? (string) key($n) : ',';
    }

    /**
     * Finds the columns of an import and how many leading lines to skip (title rows and the header row).
     * Headers are matched loosely ("Email-ID", "Student E-mail", "Candidate Name"); without a usable
     * header, the column that holds the most addresses is used, so a sheet that starts with "S No" still works.
     */
    public static function columns(array $top, string $sep): array
    {
        $col = ['email' => 0, 'name' => 1, 'college' => false, 'designation' => false, 'city' => false];
        $rows = array_map(fn ($l) => str_getcsv((string) $l, $sep, '"', ''), $top);
        $isMail = fn ($v) => (bool) preg_match('/^[^@\s<>"\']+@[^@\s]+\.[a-z]{2,}$/i', trim((string) $v, " \t<>\"'"));
        $norm = fn ($v) => preg_replace('/[^a-z]/', '', mb_strtolower((string) $v));
        $find = function (array $head, array $exact, array $has, array $not = []) use ($norm) {
            $h = array_map($norm, $head);
            foreach ($exact as $n) {
                if (($i = array_search($n, $h, true)) !== false) {
                    return $i;
                }
            }
            foreach ($h as $i => $x) {
                if ($x === '') {
                    continue;
                }
                foreach ($has as $n) {
                    if (str_contains($x, $n)) {
                        foreach ($not as $bad) {
                            if (str_contains($x, $bad)) {
                                continue 3;
                            }
                        }

                        return $i;
                    }
                }
            }

            return false;
        };
        // A header row: one of the first rows that names an email column and holds no address itself.
        foreach (array_slice($rows, 0, 10, true) as $r => $cells) {
            if (array_filter($cells, $isMail)) {
                break;
            }
            $e = $find($cells, ['email', 'emailid', 'emailaddress', 'mailid', 'mail', 'emailids', 'mailaddress'], ['email', 'mail']);
            if ($e === false) {
                continue;
            }
            $col['email'] = $e;
            $col['name'] = $find($cells, ['name', 'firstname', 'fullname', 'contactname', 'tponame', 'studentname', 'candidatename', 'candidate', 'username', 'nameofthecandidate', 'nameofthestudent'], ['name'], ['college', 'institut', 'universit', 'company', 'father', 'mother', 'parent', 'school', 'branch', 'course', 'last', 'sur']);
            $col['college'] = $find($cells, ['college', 'collegename', 'institute', 'institution', 'university', 'institutename'], ['college', 'institut', 'universit']);
            $col['designation'] = $find($cells, ['designation', 'title', 'role', 'position', 'jobtitle'], ['designation', 'position']);
            $col['city'] = $find($cells, ['city', 'location', 'district', 'town'], ['city', 'district']);

            return [$col, $r + 1];
        }
        // No usable header: take the column with the most addresses, and skip the lines above the first address.
        $count = [];
        $firstMail = null;
        foreach ($rows as $r => $cells) {
            foreach ($cells as $i => $v) {
                if ($isMail($v)) {
                    $count[$i] = ($count[$i] ?? 0) + 1;
                    $firstMail ??= $r;
                }
            }
        }
        if (! $count) {
            return [$col, 0];
        }
        arsort($count);
        $col['email'] = (int) array_key_first($count);
        $col['name'] = false;
        $head = $firstMail > 0 ? $rows[$firstMail - 1] : [];
        if ($head) {
            $col['name'] = $find($head, ['name', 'fullname', 'studentname', 'candidatename'], ['name'], ['college', 'institut', 'universit', 'company', 'father', 'mother', 'parent']);
            $col['college'] = $find($head, ['college', 'collegename', 'institute', 'university'], ['college', 'institut', 'universit']);
            $col['designation'] = $find($head, ['designation', 'title', 'role', 'position'], ['designation', 'position']);
            $col['city'] = $find($head, ['city', 'location', 'district'], ['city', 'district']);
        }
        if ($col['name'] === false && $col['email'] === 0) {
            $col['name'] = 1;
        }

        return [$col, (int) $firstMail];
    }

    public static function import(string $raw, string $source, bool $checkDomains = true, string $list = 'students'): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));
        $res = ['received' => 0, 'added' => 0, 'invalid' => 0, 'role' => 0, 'noMail' => 0, 'duplicate' => 0, 'existing' => 0, 'users' => 0, 'suppressed' => 0, 'samples' => []];
        $list = isset(self::LISTS[$list]) ? $list : 'students';
        // Rows copied from Excel or Google Sheets are tab-separated; some exports use semicolons.
        $sep = self::separator(implode("\n", array_slice($lines, 0, 5)));
        [$col, $skip] = self::columns(array_slice($lines, 0, 60), $sep);
        $lines = array_slice($lines, $skip);
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
            $cells = str_getcsv($line, $sep, '"', '');
            $email = mb_strtolower(trim((string) ($cells[$col['email']] ?? ''), " \t<>\"'"));
            $cell = fn ($k, $max) => $col[$k] === false ? '' : mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) ($cells[$col[$k]] ?? '')))), 0, $max);
            $name = $cell('name', 120);
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
            $rows[$email] = ['name' => $name, 'college' => $cell('college', 160), 'designation' => $cell('designation', 80), 'city' => $cell('city', 80)];
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
                $insert[] = ['id' => (string) Str::uuid(), 'email' => $e, 'list' => $list] + $rows[$e] + ['source' => mb_substr($source, 0, 120), 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()];
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
