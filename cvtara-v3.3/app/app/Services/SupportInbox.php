<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 3.75.0 Support by email. Every two minutes the support mailbox (support@cvtara.com on Hostinger) is read over IMAP,
 * read-only: nothing in the mailbox is moved, deleted or marked as read. A new mail becomes a ticket in Owner console >
 * Support and is sorted (customer who needs a reply, sales pitch, automatic report, spam); a reply to one of our support
 * emails joins its ticket. Automatic reports (DMARC, bounces, no-reply senders) never become tickets.
 */
class SupportInbox
{
    public const PER_RUN = 15;

    private const MAX_BYTES = 400000;

    /** Saved settings with defaults: on, Hostinger IMAP, the support address, suggested replies written automatically. */
    public static function settings(): array
    {
        $s = (array) Settings::get('supportInbox', []);

        return [
            'enabled' => (bool) ($s['enabled'] ?? true),
            'host' => trim((string) ($s['host'] ?? '')) ?: 'imap.hostinger.com',
            'port' => (int) ($s['port'] ?? 993) ?: 993,
            'user' => mb_strtolower(trim((string) ($s['user'] ?? ''))) ?: mb_strtolower(EmailRenderer::supportAddress()),
            'password' => (string) ($s['password'] ?? ''), // encrypted
            'autoDraft' => (bool) ($s['autoDraft'] ?? true),
            'state' => (array) ($s['state'] ?? []),
        ];
    }

    public static function save(array $in): void
    {
        $s = self::settings();
        foreach (['enabled', 'autoDraft'] as $k) {
            if (array_key_exists($k, $in)) {
                $s[$k] = (bool) $in[$k];
            }
        }
        if (array_key_exists('host', $in)) {
            $s['host'] = trim((string) $in['host']);
        }
        if (array_key_exists('port', $in)) {
            $s['port'] = (int) $in['port'];
        }
        if (array_key_exists('user', $in) && mb_strtolower(trim((string) $in['user'])) !== $s['user']) {
            $s['user'] = mb_strtolower(trim((string) $in['user']));
            $s['state'] = []; // another mailbox starts from its last two days
        }
        if (isset($in['password']) && $in['password'] !== '') {
            $s['password'] = Crypt::encryptString((string) $in['password']);
        }
        Settings::put('supportInbox', $s);
    }

    /** The mailbox password: its own when saved, else the sending password when the mailbox is the sending mailbox. */
    private static function password(array $s): string
    {
        if ($s['password'] !== '') {
            try {
                return Crypt::decryptString($s['password']);
            } catch (\Throwable $e) {
                return '';
            }
        }
        $p = Settings::providers()['email'] ?? [];

        return mb_strtolower(trim((string) ($p['smtpUser'] ?? ''))) === $s['user'] ? (string) ($p['smtpPassword'] ?? '') : '';
    }

    /** What the console shows: on or off, which mailbox, whether a password is known, the last check and today's counts. */
    public static function status(): array
    {
        $s = self::settings();
        $since = now()->subDay();
        $count = fn ($o) => Schema::hasTable('support_inbox_seen') ? DB::table('support_inbox_seen')->where('created_at', '>=', $since)->where('outcome', $o)->count() : 0;

        return ['enabled' => $s['enabled'], 'host' => $s['host'], 'port' => $s['port'], 'user' => $s['user'], 'autoDraft' => $s['autoDraft'],
            'hasPassword' => $s['password'] !== '', 'usesSendingPassword' => $s['password'] === '' && self::password($s) !== '', 'ready' => self::password($s) !== '',
            'lastRun' => $s['state']['lastRun'] ?? null, 'lastError' => $s['state']['lastError'] ?? '', 'lastOk' => $s['state']['lastOk'] ?? null,
            'day' => ['tickets' => $count('ticket'), 'replies' => $count('reply'), 'skipped' => $count('skipped')]];
    }

    /** Called from the minute cron (every two minutes) and from "Check now". */
    public static function run(bool $force = false): array
    {
        $s = self::settings();
        if (! $s['enabled'] && ! $force) {
            return ['skipped' => 'off'];
        }
        $pw = self::password($s);
        if ($pw === '') {
            return self::finish($s, ['error' => 'No password for '.$s['user'].'. Type the mailbox password in Support > Support inbox.']);
        }
        $lock = Cache::lock('cvtara-support-inbox', 120);
        if (! $lock->get()) {
            return ['skipped' => 'busy'];
        }
        $out = ['tickets' => 0, 'replies' => 0, 'skipped' => 0, 'read' => 0];
        $imap = null;
        try {
            $imap = new ImapReader($s['host'], $s['port']);
            $imap->login($s['user'], $pw);
            $validity = $imap->examine('INBOX');
            $state = $s['state'];
            if (($state['validity'] ?? null) !== $validity || ! isset($state['lastUid'])) {
                // First run (or the mailbox was rebuilt): start from mail of the last two days.
                $uids = $imap->search('SINCE '.now()->subDays(2)->format('j-M-Y'));
            } else {
                $uids = array_values(array_filter($imap->search('UID '.((int) $state['lastUid'] + 1).':*'), fn ($u) => $u > (int) $state['lastUid']));
            }
            sort($uids);
            $last = (int) ($state['lastUid'] ?? 0);
            foreach (array_slice($uids, 0, self::PER_RUN) as $uid) {
                $raw = $imap->fetch($uid, self::MAX_BYTES);
                $out['read']++;
                try {
                    $res = self::ingest(MimeMessage::parse($raw), $raw);
                    $out[$res === 'ticket' ? 'tickets' : ($res === 'reply' ? 'replies' : 'skipped')]++;
                } catch (\Throwable $e) {
                    ErrorLog::exception($e);
                    $out['skipped']++;
                }
                $last = max($last, $uid);
            }
            if (! $uids && ! isset($state['lastUid'])) {
                $last = max(0, $imap->uidNext() - 1);
            }
            $imap->logout();
            $s['state'] = ['validity' => $validity, 'lastUid' => $last] + $s['state'];

            return self::finish($s, $out + ['more' => count($uids) > self::PER_RUN]);
        } catch (\Throwable $e) {
            $imap?->close();

            return self::finish($s, ['error' => self::why($e)]);
        } finally {
            $lock->release();
        }
    }

    private static function why(\Throwable $e): string
    {
        $m = $e->getMessage();

        return match (true) {
            str_contains($m, 'AUTH') || str_contains(strtoupper($m), 'LOGIN') => 'The mailbox refused the sign-in. Check the mailbox password in Support > Support inbox.',
            str_contains($m, 'connect') => 'The mail server could not be reached ('.mb_strimwidth($m, 0, 120, '…').').',
            default => 'Reading the mailbox failed: '.mb_strimwidth($m, 0, 160, '…'),
        };
    }

    private static function finish(array $s, array $out): array
    {
        $state = Settings::get('supportInbox', [])['state'] ?? [];
        $state = array_merge((array) $state, $s['state'] ?? [], ['lastRun' => now()->toIso8601String(), 'lastError' => $out['error'] ?? '']);
        if (! isset($out['error'])) {
            $state['lastOk'] = now()->toIso8601String();
        } elseif (($state['lastErrorLogged'] ?? '') !== now()->format('Y-m-d H')) {
            ErrorLog::record('warning', 'support-inbox', $out['error']);
            $state['lastErrorLogged'] = now()->format('Y-m-d H');
        }
        Settings::put('supportInbox', array_merge((array) Settings::get('supportInbox', []), ['state' => $state]));

        return $out;
    }

    /** Turns one mail into a ticket, a reply on its ticket, or nothing (automatic mail, our own mail, a mail read before). */
    public static function ingest(MimeMessage $m, string $raw = ''): string
    {
        $from = $m->fromAddress();
        $key = hash('sha256', $m->header('message-id') ?: $raw ?: ($from.$m->header('date').$m->subject()));
        if (DB::table('support_inbox_seen')->where('id', $key)->exists()) {
            return 'seen';
        }
        $seen = fn (string $o, ?string $tid = null) => DB::table('support_inbox_seen')->insertOrIgnore(['id' => $key, 'ticket_id' => $tid, 'outcome' => $o, 'sender' => mb_substr($from, 0, 190), 'subject' => mb_substr($m->subject(), 0, 255), 'created_at' => now()]);
        $own = array_filter([mb_strtolower(EmailRenderer::supportAddress()), mb_strtolower((string) ((Settings::providers()['email'] ?? [])['fromEmail'] ?? '')), self::settings()['user']]);
        if ($from === '' || in_array($from, $own, true) || self::automatic($m)) {
            $seen('skipped');

            return 'skipped';
        }
        $text = $m->replyText();
        if (mb_strlen(trim($text)) < 2) {
            $text = '(The email had no text. Open the mailbox to see it.)';
        }
        if ($m->attachments()) {
            $text .= "\n\n(".count($m->attachments()).' attachment'.(count($m->attachments()) > 1 ? 's' : '').' in the email: '.implode(', ', array_slice($m->attachments(), 0, 5)).'. Open the mailbox to see '.(count($m->attachments()) > 1 ? 'them' : 'it').'.)';
        }
        $ticket = self::threadOf($m, $from);
        if ($ticket) {
            SupportCentre::add($ticket->id, $ticket->owner_id, 'customer', $text);
            $msgId = trim($m->header('message-id'));
            if ($msgId !== '') {
                DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->orderByDesc('created_at')->limit(1)->update(['email_message_id' => mb_substr($msgId, 0, 255)]);
            }
            DB::table('support_reports')->where('id', $ticket->id)->update(['status' => 'new', 'resolved_at' => null, 'last_customer_at' => now(), 'reply_needed' => true, 'updated_at' => now()]);
            $seen('reply', $ticket->id);
            if (self::settings()['autoDraft']) {
                SupportAssist::draftQuietly($ticket->id);
            }

            return 'reply';
        }
        $user = User::where('email', $from)->first();
        $plan = null;
        if ($user) {
            $acc = Settings::access($user->id);
            $plan = $acc['paid'] ? (Settings::plans()[$acc['plan']]['name'] ?? $acc['plan']) : 'Free';
        }
        $id = (string) Str::uuid();
        $subject = mb_substr(trim($m->subject()), 0, 250);
        DB::table('support_reports')->insert(['id' => $id, 'owner_id' => $user?->id, 'name' => mb_substr($m->fromName() ?: ($user->name ?? ''), 0, 120), 'email' => mb_substr($from, 0, 190),
            'area' => 'email', 'kind' => 'question', 'page' => '', 'message' => ($subject !== '' ? 'Subject: '.$subject."\n\n" : '').mb_substr($text, 0, 5000), 'rating' => null,
            'status' => 'new', 'admin_note' => '', 'user_agent' => '', 'viewport' => null, 'plan' => $plan, 'channel' => 'email', 'email_subject' => $subject,
            'email_message_id' => mb_substr(trim($m->header('message-id')), 0, 255), 'created_at' => now(), 'updated_at' => now()]);
        SupportCentre::stamp($id);
        $seen('ticket', $id);
        SupportAssist::triage($id, $m);

        return 'ticket';
    }

    /** DMARC and delivery reports, bounces, out-of-office and no-reply senders. */
    public static function automatic(MimeMessage $m): bool
    {
        $from = $m->fromAddress();
        $local = strstr($from, '@', true) ?: $from;
        $auto = mb_strtolower($m->header('auto-submitted'));

        return preg_match('/^(no-?reply|do-?not-?reply|mailer-daemon|postmaster|bounces?|notifications?|dmarc.*)$/i', $local)
            || str_contains($from, 'dmarc')
            || ($auto !== '' && $auto !== 'no')
            || preg_match('/^\s*(\[preview\]\s*)?report domain:/i', $m->subject())
            || preg_match('/^(undeliver|delivery status notification|mail delivery failed|returned mail|automatic reply|out of office)/i', trim($m->subject()))
            || stripos($m->header('content-type'), 'multipart/report') !== false
            || in_array(mb_strtolower(trim($m->header('precedence'))), ['auto_reply'], true);
    }

    /** The ticket a mail answers: the reference in the subject, or the email it replies to. */
    private static function threadOf(MimeMessage $m, string $from): ?object
    {
        if (preg_match('/\bS-([0-9A-F]{6})\b/i', $m->subject(), $x)) {
            $t = DB::table('support_reports')->where('reference', 'S-'.strtoupper($x[1]))->first();
            if ($t && ($t->email === null || mb_strtolower((string) $t->email) === $from || ($t->owner_id && User::where('id', $t->owner_id)->value('email') === $from))) {
                return $t;
            }
        }
        preg_match_all('/<[^<>\s]+>/', $m->header('in-reply-to').' '.$m->header('references'), $ids);
        $ids = array_slice(array_unique($ids[0]), -20);
        if ($ids) {
            $tid = DB::table('support_ticket_messages')->whereIn('email_message_id', $ids)->value('ticket_id')
                ?: DB::table('support_reports')->whereIn('email_message_id', $ids)->value('id');
            if ($tid) {
                return DB::table('support_reports')->where('id', $tid)->first();
            }
        }

        return null;
    }
}
