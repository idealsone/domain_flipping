<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Email campaigns: one-off marketing mail the owner writes, sent to customers who said yes to emails or to an imported
 * contact list. Recipients are fixed when the campaign starts, then handed to mail_outbox a small batch per cron run and
 * never more than the daily cap, so the normal mailer (MailAutomation::process) sends them with the unsubscribe footer.
 */
class Campaigns
{
    public const AUDIENCES = [
        'customers_all' => 'All customers who said yes to emails',
        'customers_free' => 'Customers who have never bought a plan',
        'customers_paid' => 'Customers with a plan that is active now',
        'customers_expired' => 'Customers whose plan has ended',
        'contacts' => 'Your imported contacts',
    ];

    /** mailboxCap: the sending mailbox's own limit for 24 hours (Hostinger Business Starter allows 1,000). */
    public const DEFAULTS = ['batchSize' => 20, 'dailyCap' => 300, 'mailboxCap' => 1000];

    public const STATUSES = ['draft', 'scheduled', 'sending', 'paused', 'done', 'cancelled'];

    public static function settings(): array
    {
        $s = (array) Settings::get('campaignSettings', []);

        return ['batchSize' => (int) ($s['batchSize'] ?? self::DEFAULTS['batchSize']), 'dailyCap' => (int) ($s['dailyCap'] ?? self::DEFAULTS['dailyCap']), 'mailboxCap' => (int) ($s['mailboxCap'] ?? self::DEFAULTS['mailboxCap'])];
    }

    private static function suppressed()
    {
        return DB::table('email_suppressions')->select('email');
    }

    /** Plan purchases that count as "paid": no add-ons and no free referral rewards. */
    private static function paidPlans()
    {
        return DB::table('purchases')->whereIn('status', ['paid', 'partially_refunded'])->where('provider', '!=', 'referral_reward')->where('plan', 'not like', 'addon-%');
    }

    /** Everyone the audience reaches right now, already without opted-out, blocked and suppressed addresses. */
    public static function audience(string $aud, string $college = '', string $role = '')
    {
        if ($aud === 'contacts') {
            // People with a CVTara account are reached through the customer audiences only, so their own email choice wins.
            return DB::table('email_contacts as c')->whereNull('c.unsubscribed_at')->whereNotIn('c.email', self::suppressed())->whereNotIn('c.email', DB::table('users')->select('email'))
                ->select('c.email', 'c.name', DB::raw('NULL as owner_id'), 'c.id as contact_id');
        }
        $q = DB::table('users as u')->join('profiles as p', 'p.owner_id', '=', 'u.id')->where('p.marketing_consent', true)->whereNotNull('u.email_verified_at')->where('u.is_admin', false)->where('u.blocked', false)->whereNotIn('u.email', self::suppressed());
        if ($aud === 'customers_free') {
            $q->whereNotIn('u.id', DB::table('purchases')->whereNotNull('purchased_at')->where('provider', '!=', 'referral_reward')->select('owner_id'));
        } elseif ($aud === 'customers_paid') {
            $q->whereIn('u.id', self::paidPlans()->where('access_until', '>', now())->select('owner_id'));
        } elseif ($aud === 'customers_expired') {
            $q->whereIn('u.id', self::paidPlans()->select('owner_id'))->whereNotIn('u.id', self::paidPlans()->where('access_until', '>', now())->select('owner_id'));
        }

        // Segments read the college and target role from each person's newest resume (Growth::segments), consenting customers only.
        foreach (['p.college' => $college, 'p.target_role' => $role] as $col => $v) {
            if (($v = trim($v)) !== '') {
                $q->where($col, 'like', '%'.addcslashes(mb_strtolower($v), '%_\\').'%');
            }
        }

        return $q->select('u.email', 'u.name', 'u.id as owner_id', DB::raw('NULL as contact_id'));
    }

    public static function count(string $aud, string $college = '', string $role = ''): int
    {
        return self::audience($aud, $college, $role)->count();
    }

    public static function counts(): array
    {
        return collect(self::AUDIENCES)->map(fn ($l, $k) => self::count($k))->all();
    }

    public static function firstName(string $name): string
    {
        return trim(explode(' ', trim($name))[0]) ?: 'there';
    }

    public static function fill(string $text, string $name, string $email): string
    {
        $vars = ['name' => self::firstName($name), 'email' => $email] + MailAutomation::sharedVars();

        // {{checkout_url}} and {{offer_line}} stay as they are here and are filled with the live offer when the mail is sent.
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : (in_array($m[1], ['checkout_url', 'offer_line'], true) ? $m[0] : ''), $text);
    }

    /** India time "2026-11-01T10:00" in and out; stored as UTC. */
    public static function toUtc(string $local): Carbon
    {
        return Carbon::parse($local, 'Asia/Kolkata')->utc();
    }

    public static function local($utc): ?string
    {
        return $utc ? Carbon::parse($utc, 'UTC')->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i') : null;
    }

    /** Fixes the recipient list. Called when a campaign starts (now, or when its scheduled time comes). */
    public static function start(object $c): int
    {
        $n = 0;
        self::audience($c->audience, (string) ($c->seg_college ?? ''), (string) ($c->seg_role ?? ''))->orderBy('email')->chunk(500, function ($rows) use ($c, &$n) {
            $ins = [];
            foreach ($rows as $r) {
                $ins[] = ['campaign_id' => $c->id, 'email' => mb_strtolower($r->email), 'name' => mb_substr((string) $r->name, 0, 120), 'owner_id' => $r->owner_id, 'contact_id' => $r->contact_id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()];
            }
            $n += DB::table('campaign_recipients')->insertOrIgnore($ins);
        });
        DB::table('email_campaigns')->where('id', $c->id)->update(['status' => 'sending', 'started_at' => now(), 'updated_at' => now()]);

        return $n;
    }

    public static function sentToday(): int
    {
        return DB::table('mail_outbox')->where('trigger', 'campaign')->where('created_at', '>=', Carbon::now('Asia/Kolkata')->startOfDay()->utc())->count();
    }

    /** One cron run: start what is due, then queue at most one batch, never past the daily cap. */
    public static function run(): array
    {
        $lock = Cache::lock('cvtara-campaigns', 120);
        if (! $lock->get()) {
            return ['queued' => 0];
        }
        try {
            foreach (DB::table('email_campaigns')->where('status', 'scheduled')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->get() as $c) {
                self::start($c);
            }
            $s = self::settings();
            $room = min($s['batchSize'], max(0, $s['dailyCap'] - self::sentToday()));
            $queued = 0;
            foreach (DB::table('email_campaigns')->where('status', 'sending')->orderBy('started_at')->get() as $c) {
                if ($room > 0) {
                    $q = self::queueBatch($c, $room);
                    $room -= $q;
                    $queued += $q;
                }
                if (! DB::table('campaign_recipients')->where('campaign_id', $c->id)->where('status', 'pending')->exists()) {
                    DB::table('email_campaigns')->where('id', $c->id)->update(['status' => 'done', 'finished_at' => now(), 'updated_at' => now()]);
                }
            }

            return ['queued' => $queued];
        } finally {
            $lock->release();
        }
    }

    private static function queueBatch(object $c, int $limit): int
    {
        $n = 0;
        foreach (DB::table('campaign_recipients')->where('campaign_id', $c->id)->where('status', 'pending')->orderBy('id')->limit($limit)->get() as $r) {
            // Choices can change between the start and this batch: check again.
            $ok = ! DB::table('email_suppressions')->where('email', $r->email)->exists() && ($r->contact_id
                ? DB::table('email_contacts')->where('id', $r->contact_id)->whereNull('unsubscribed_at')->exists()
                : DB::table('users as u')->join('profiles as p', 'p.owner_id', '=', 'u.id')->where('u.id', $r->owner_id)->where('u.blocked', false)->where('p.marketing_consent', true)->exists());
            if (! $ok) {
                DB::table('campaign_recipients')->where('id', $r->id)->update(['status' => 'skipped', 'updated_at' => now()]);

                continue;
            }
            $oid = (string) Str::uuid();
            DB::table('mail_outbox')->insertOrIgnore(['id' => $oid, 'dedupe' => hash('sha256', 'campaign:'.$c->id.':'.$r->email), 'owner_id' => $r->owner_id, 'contact_id' => $r->contact_id, 'campaign_id' => $c->id, 'trigger' => 'campaign', 'recipient' => $r->email, 'subject' => mb_substr(self::fill($c->subject, $r->name, $r->email), 0, 180), 'body' => self::fill($c->body, $r->name, $r->email), 'due_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('campaign_recipients')->where('id', $r->id)->update(['status' => 'queued', 'outbox_id' => $oid, 'updated_at' => now()]);
            $n++;
        }

        return $n;
    }

    /** Stops new batches and takes back mail that is still waiting in the queue. */
    public static function pause(object $c): void
    {
        $ids = DB::table('mail_outbox')->where('campaign_id', $c->id)->whereIn('status', ['queued', 'failed'])->pluck('id')->all();
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('mail_outbox')->whereIn('id', $chunk)->delete();
            DB::table('campaign_recipients')->whereIn('outbox_id', $chunk)->update(['status' => 'pending', 'outbox_id' => null, 'updated_at' => now()]);
        }
        DB::table('email_campaigns')->where('id', $c->id)->update(['status' => 'paused', 'updated_at' => now()]);
    }

    public static function resume(object $c): void
    {
        $status = $c->started_at ? 'sending' : 'scheduled';
        DB::table('email_campaigns')->where('id', $c->id)->update(['status' => $status, 'scheduled_at' => $c->started_at ? $c->scheduled_at : ($c->scheduled_at ?: now()), 'updated_at' => now()]);
    }

    public static function cancel(object $c): void
    {
        DB::table('mail_outbox')->where('campaign_id', $c->id)->whereIn('status', ['queued', 'failed'])->update(['status' => 'cancelled', 'updated_at' => now()]);
        DB::table('campaign_recipients')->where('campaign_id', $c->id)->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);
        DB::table('email_campaigns')->where('id', $c->id)->update(['status' => 'cancelled', 'finished_at' => now(), 'updated_at' => now()]);
    }

    /** Real counts from the recipient list and the mail queue. */
    public static function stats(string $id): array
    {
        $r = DB::table('campaign_recipients')->where('campaign_id', $id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $m = DB::table('mail_outbox')->where('campaign_id', $id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'recipients' => (int) $r->sum(),
            'waiting' => (int) ($r['pending'] ?? 0) + (int) ($m['queued'] ?? 0) + (int) ($m['sending'] ?? 0),
            'sent' => (int) ($m['sent'] ?? 0),
            'failed' => (int) ($m['failed'] ?? 0),
            'notSent' => (int) ($r['skipped'] ?? 0) + (int) ($r['cancelled'] ?? 0) + (int) ($m['cancelled'] ?? 0),
            'unsubscribed' => DB::table('campaign_recipients')->where('campaign_id', $id)->whereNotNull('unsubscribed_at')->count(),
        ];
    }

    /** The owner's own copy, sent through the normal mail queue straight away. */
    public static function test(User $u, string $subject, string $body): void
    {
        DB::table('mail_outbox')->insert(['id' => (string) Str::uuid(), 'dedupe' => (string) Str::uuid(), 'owner_id' => $u->id, 'trigger' => 'campaign_test', 'recipient' => $u->email, 'subject' => mb_substr('[Test] '.self::fill($subject, $u->name, $u->email), 0, 180), 'body' => self::fill($body, $u->name, $u->email), 'due_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Reads pasted or uploaded CSV: an email in any column, an optional name in the next text column. */
    public static function parse(string $csv): array
    {
        $good = [];
        $bad = [];
        foreach (preg_split('/\R/', $csv) as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $cells = array_map(fn ($x) => trim($x, " \t\"'"), str_getcsv($line, str_contains($line, ';') && ! str_contains($line, ',') ? ';' : (str_contains($line, "\t") && ! str_contains($line, ',') ? "\t" : ',')));
            $email = null;
            $name = '';
            foreach ($cells as $cell) {
                if ($email === null && str_contains($cell, '@')) {
                    $email = mb_strtolower($cell);
                } elseif ($name === '' && $cell !== '' && ! str_contains($cell, '@')) {
                    $name = $cell;
                }
            }
            if ($email === null) {
                if ($i === 0 && preg_match('/e-?mail/i', $line)) {
                    continue; // header row
                }
                $bad[] = mb_substr($line, 0, 80);

                continue;
            }
            if (strlen($email) > 190 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $bad[] = mb_substr($email, 0, 80);

                continue;
            }
            $good[$email] ??= mb_substr(preg_replace('/[\x00-\x1f<>]/u', '', $name), 0, 120);
        }

        return ['rows' => $good, 'invalid' => $bad];
    }

    public static function import(int $ownerId, string $csv, string $source): array
    {
        $p = self::parse($csv);
        $emails = array_keys($p['rows']);
        $sup = $exists = $acct = [];
        foreach (array_chunk($emails, 500) as $chunk) {
            $sup = array_merge($sup, DB::table('email_suppressions')->whereIn('email', $chunk)->pluck('email')->all());
            $exists = array_merge($exists, DB::table('email_contacts')->whereIn('email', $chunk)->pluck('email')->all());
            $acct = array_merge($acct, DB::table('users')->whereIn('email', $chunk)->pluck('email')->all());
        }
        $skip = array_flip(array_merge($sup, $exists));
        $ins = [];
        foreach ($p['rows'] as $email => $name) {
            if (! isset($skip[$email])) {
                $ins[] = ['email' => $email, 'name' => $name, 'source' => $source, 'token' => Str::random(48), 'confirmed_by' => $ownerId, 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()];
            }
        }
        $added = 0;
        foreach (array_chunk($ins, 500) as $chunk) {
            $added += DB::table('email_contacts')->insertOrIgnore($chunk);
        }

        return ['added' => $added, 'alreadyListed' => count(array_unique($exists)), 'doNotMail' => count(array_unique($sup)), 'haveAccount' => count(array_intersect(array_keys($p['rows']), $acct)), 'invalid' => count($p['invalid']), 'invalidSamples' => array_slice($p['invalid'], 0, 10), 'read' => count($p['rows'])];
    }

    /** A contact's own link: marks them unsubscribed and keeps the address on the do-not-mail list. */
    public static function unsubscribeContact(object $contact, ?string $campaignId): void
    {
        DB::table('email_contacts')->where('id', $contact->id)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now(), 'updated_at' => now()]);
        DB::table('email_suppressions')->insertOrIgnore(['email' => $contact->email, 'reason' => 'unsubscribed', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mail_outbox')->where('contact_id', $contact->id)->where('trigger', 'campaign')->whereIn('status', ['queued', 'failed'])->update(['status' => 'cancelled', 'updated_at' => now()]);
        if ($campaignId && preg_match('/^[a-f0-9-]{36}$/', $campaignId)) {
            DB::table('campaign_recipients')->where('campaign_id', $campaignId)->where('contact_id', $contact->id)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now(), 'updated_at' => now()]);
        }
    }

    public static function noteUserUnsubscribe(int $userId, ?string $campaignId): void
    {
        if ($campaignId && preg_match('/^[a-f0-9-]{36}$/', $campaignId)) {
            DB::table('campaign_recipients')->where('campaign_id', $campaignId)->where('owner_id', $userId)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now(), 'updated_at' => now()]);
        }
    }
}
