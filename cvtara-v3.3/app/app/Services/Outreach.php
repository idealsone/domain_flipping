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
                'students' => ['design' => 'card', 'steps' => [
                    ['subject' => '{{name}}, will your resume get shortlisted?', 'delayDays' => 0, 'body' => "# Will your resume get shortlisted?\n[preheader: A one-minute check shows the keywords your resume is missing for any job.]\nHi {{name}},\n\nMany companies that hire freshers screen resumes with software (an ATS) before anyone reads them. If your resume does not use the words the job asks for, it can be passed over in seconds.\n\nCVTara checks your resume against any job description, at no cost, and shows you:\n- your match score for that job\n- the keywords you are missing\n- what to fix before you apply\n\n[button: Check my resume | {{ats_url}}]\n\nIt takes about a minute, and placement season is a good time to fix it.\n\n{{sender_name}}"],
                    ['subject' => 'Re: will your resume get shortlisted?', 'delayDays' => 3, 'body' => "Hi {{name}},\n\nA quick placement tip: send a slightly different resume for each role. Same facts, but the words that role asks for.\n\nCVTara keeps one primary resume and makes a tailored version for each job in a click, with fresher designs from the guided template library and a matching cover letter.\n\n{{tara_line}}\n\n[button: Make my job-ready resume | {{site_url}}]\n\nFriends applying too? Send them the check on WhatsApp: {{share_url}}"],
                    ['subject' => 'last note from me, {{name}}', 'delayDays' => 5, 'body' => "# Your resume, ready before the next interview\nHi {{name}},\n\nThis is my last mail about it. Building, editing and checking your resume on CVTara costs nothing. You pay only when you download a clean copy, from {{from_price}}, paid once with no auto-renewal and a full refund within 7 days if you have not used it.\n\n{{offer_line}}\n\n[button: See the packages | {{plans_url}}]\n\nAll the best for your placements.\n\n{{sender_name}}"],
                ]],
                'colleges' => ['design' => 'plain', 'steps' => [
                    ['subject' => 'Placement support for {{college}} students', 'delayDays' => 0, 'body' => "Hello {{name}},\n\nI'm writing from CVTara, a resume platform built by an HR team in Bengaluru. You will know how many capable students at {{college}} lose out at the resume shortlist stage.\n\nWe would like to give {{college}}'s final-year students our ATS resume check this placement season, at no cost:\n- each student sees how their resume scores against real job descriptions\n- they get the missing keywords and fixes in minutes\n- you share one link with the batch, with nothing to install\n\nShall I send you the link and a short note you can forward to students? A reply to this email is enough.\n\n{{sender_name}}"],
                    ['subject' => 'Re: Placement support for {{college}} students', 'delayDays' => 4, 'body' => "Hello {{name}},\n\nFollowing up on my note. Here is the link your students can use straight away: {{ats_url}}\n\nIf it helps, we can also run a 30-minute online session for the batch on writing a resume that passes ATS screening, at no cost to the college.\n\nWould a date in the next two weeks suit you? A one-line reply is enough.\n\n{{sender_name}}"],
                    ['subject' => 'Re: Placement support for {{college}} students', 'delayDays' => 7, 'body' => "Hello {{name}},\n\nI don't want to fill your inbox, so this is my last note. If the ATS check or a resume session would help {{college}}'s students this season, just reply and I'll set it up.\n\nYou can also forward the check to your students on WhatsApp: {{share_url}}\n\n{{sender_name}}"],
                ]],
            ],
        ];
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
            $l['design'] = in_array($l['design'], ['card', 'plain'], true) ? $l['design'] : $def['design'];
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

    public static function listOf(array $c, object $ct): array
    {
        return $c['lists'][$ct->list ?? 'students'] ?? $c['lists']['students'];
    }

    /** @return array{subject:string,text:string,html:string,unsubscribe:string} */
    public static function render(array $step, object $ct, object $s, array $c, ?string $design = null): array
    {
        $list = $ct->list ?? 'students';
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
        $text = rtrim($text).($sign !== '' ? "\n\n".$sign : '')."\n\nDon't want these emails? Unsubscribe: ".$unsub."\n";
        $f = MailLayout::FONT;
        $foot = '<p class="cv-mut" style="margin:22px 0 0;font-family:'.$f.';font-size:12px;line-height:1.6;color:'.$c['muted'].'">Don\'t want these emails? <a href="'.e($unsub).'" class="cv-mut" style="color:'.$c['muted'].'">Unsubscribe</a>.</p>';
        $signHtml = $sign !== '' ? '<p class="cv-txt" style="margin:18px 0 0;font-family:'.$f.';font-size:15px;line-height:1.6;color:'.$c['text'].'">'.nl2br(e($sign), false).'</p>' : '';
        if ($card) {
            // The light card: small wordmark, no colour band or images, so it reads like a note rather than a newsletter.
            $html = MailLayout::page(['subject' => $subject, 'preheader' => $parsed['preheader'], 'headline' => $head, 'content' => $html, 'signature' => $signHtml.$foot, 'footer' => '', 'variant' => 'light']);
        } else {
            $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($subject).'</title></head><body style="margin:0;padding:16px;font:15px/1.6 Arial,Helvetica,sans-serif;color:#1f2937">'
                .$html.$signHtml.$foot.'</body></html>';
        }

        return ['subject' => $subject, 'text' => $text, 'html' => $html, 'unsubscribe' => $unsub];
    }

    // ---------------------------------------------------------------- list import and clean-up

    /**
     * Imports "email,name" lines (a header row is optional; with one, college, designation and city columns are read too).
     * Every address is checked before it is added.
     *
     * @return array{received:int,added:int,invalid:int,role:int,noMail:int,duplicate:int,existing:int,users:int,suppressed:int,samples:array}
     */
    public static function import(string $raw, string $source, bool $checkDomains = true, string $list = 'students'): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));
        $res = ['received' => 0, 'added' => 0, 'invalid' => 0, 'role' => 0, 'noMail' => 0, 'duplicate' => 0, 'existing' => 0, 'users' => 0, 'suppressed' => 0, 'samples' => []];
        $list = isset(self::LISTS[$list]) ? $list : 'students';
        $col = ['email' => 0, 'name' => 1, 'college' => false, 'designation' => false, 'city' => false];
        $first = str_getcsv((string) ($lines[0] ?? ''), ',', '"', '');
        $head = array_map(fn ($x) => preg_replace('/[^a-z ]/', '', mb_strtolower(trim((string) $x))), $first);
        $aliases = ['email' => ['email', 'email id', 'email address', 'mail id', 'mail'], 'name' => ['name', 'first name', 'full name', 'contact name', 'tpo name', 'student name'],
            'college' => ['college', 'college name', 'institute', 'institution', 'university'], 'designation' => ['designation', 'title', 'role', 'position'], 'city' => ['city', 'location', 'district']];
        $pick = function ($names) use ($head) {
            foreach ($names as $n) {
                if (($i = array_search($n, $head, true)) !== false) {
                    return $i;
                }
            }

            return false;
        };
        if ($pick($aliases['email']) !== false) {
            foreach ($aliases as $k => $names) {
                $col[$k] = $pick($names);
            }
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
