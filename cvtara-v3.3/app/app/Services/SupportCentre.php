<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Support Centre (3.40.0): ticket references, conversations, replies by email and the customer support profile. */
class SupportCentre
{
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public static function ref(string $id): string
    {
        return 'S-'.strtoupper(substr(str_replace('-', '', $id), 0, 6));
    }

    /** Called after a ticket is created (web form, contact page or staff). */
    public static function stamp(string $id, bool $fromCustomer = true): void
    {
        if (Schema::hasColumn('support_reports', 'reference')) {
            DB::table('support_reports')->where('id', $id)->update(['reference' => self::ref($id)] + ($fromCustomer ? ['last_customer_at' => now()] : []));
        }
    }

    /** The conversation; customers never see internal notes or who on the team wrote. */
    public static function messages(string $ticketId, bool $staff): array
    {
        $q = DB::table('support_ticket_messages as m')->leftJoin('users as u', 'u.id', '=', 'm.author_id')->where('m.ticket_id', $ticketId)->orderBy('m.created_at');
        if (! $staff) {
            $q->where('m.internal', false);
        }

        $mailCol = $staff && Schema::hasColumn('support_ticket_messages', 'mail_id');
        if ($mailCol) {
            $q->leftJoin('mail_outbox as o', 'o.id', '=', 'm.mail_id');
        }

        return $q->get(array_merge(['m.id', 'm.author_type', 'm.body', 'm.internal', 'm.created_at', 'u.name as author', 'u.email as author_email'], $mailCol ? ['o.status as mail_status', 'o.recipient as mail_to', 'o.sent_at as mail_sent_at'] : []))
            ->map(fn ($m) => ['id' => $m->id, 'from' => $m->author_type, 'body' => $m->body, 'internal' => (bool) $m->internal, 'at' => (string) $m->created_at]
                + ($mailCol && ($m->mail_status ?? null) ? ['mail' => ['status' => $m->mail_status, 'to' => $m->mail_to, 'at' => (string) $m->mail_sent_at]] : [])
                + ($staff ? ['author' => (string) ($m->author ?: ($m->author_type === 'customer' ? 'Customer' : 'CVTara team'))] : ['author' => $m->author_type === 'customer' ? 'You' : 'CVTara team']))->all();
    }

    public static function add(string $ticketId, ?int $authorId, string $type, string $body, bool $internal = false): string
    {
        $id = (string) Str::uuid();
        DB::table('support_ticket_messages')->insert(['id' => $id, 'ticket_id' => $ticketId, 'author_id' => $authorId, 'author_type' => $type, 'body' => mb_substr(trim($body), 0, 5000), 'internal' => $internal, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /**
     * A staff reply the customer can see: stored, response time recorded, and emailed to the customer at once (3.75.0: also
     * to people without an account who emailed or used the contact page). Returns the delivery state of that email.
     */
    public static function staffReply(object $ticket, User $staff, string $body): array
    {
        $mid = self::add($ticket->id, $staff->id, 'staff', $body);
        $set = ['updated_at' => now(), 'draft' => null, 'draft_at' => null];
        if (! $ticket->first_response_at) {
            $set['first_response_at'] = now();
        }
        if (in_array($ticket->status, ['new', 'seen'], true)) {
            $set['status'] = 'working';
        }
        DB::table('support_reports')->where('id', $ticket->id)->update($set);
        $customer = $ticket->owner_id ? User::find($ticket->owner_id) : null;
        if ($customer && $customer->blocked) {
            return ['mail' => 'not sent', 'why' => 'The account is blocked, so no email was sent.'];
        }
        $to = $customer->email ?? (string) $ticket->email;
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['mail' => 'not sent', 'why' => 'This ticket has no email address.'];
        }
        $ref = $ticket->reference ?: self::ref($ticket->id);
        $site = rtrim((string) config('app.url'), '/');
        $first = trim(explode(' ', trim((string) ($customer->name ?? $ticket->name)))[0] ?? '') ?: 'there';
        $text = trim($body);
        if (! preg_match('/^(hi|hello|dear|hey|namaste|good (morning|afternoon|evening))\b/i', $text)) {
            $text = 'Hi '.$first.",\n\n".$text;
        }
        if (! preg_match('/cvtara\s+(support\s+)?team\W*$/i', $text)) {
            $text .= "\n\nRegards,\nCVTara team";
        }
        $vars = ['ticket_ref' => $ref, 'reply' => mb_substr($text, 0, 6000), 'ticket_url' => $site.'/workspace.html?support=mine',
            'continue_line' => 'Just reply to this email to continue'.($customer ? ', or open Feedback → My requests in your CVTara account' : '').'. Your request number is '.$ref.'.'];
        $subject = null;
        $thread = [];
        if (($ticket->channel ?? '') === 'email') {
            $orig = trim(preg_replace('/^((re|fwd?|aw)\s*:\s*)+/i', '', (string) ($ticket->email_subject ?? '')));
            // Exactly "Re: <their subject>": Gmail only keeps a reply in the same conversation when the subject matches. The
            // ticket reference is in the email text; their answer finds the ticket through In-Reply-To/References.
            $subject = 'Re: '.($orig !== '' ? mb_substr($orig, 0, 200) : 'Your CVTara request');
            $ids = DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->whereNotNull('email_message_id')->orderBy('created_at')->pluck('email_message_id')->all();
            $ids = array_values(array_unique(array_filter(array_merge([(string) ($ticket->email_message_id ?? '')], $ids))));
            if ($ids) {
                $thread = ['in_reply_to' => end($ids), 'references' => implode(' ', array_slice($ids, -10))];
            }
        }
        $q = MailAutomation::queueSupport($to, $customer?->id, (string) ($customer->name ?? $ticket->name ?? ''), $vars, $subject, $thread);
        if (! $q) {
            return ['mail' => 'not sent', 'why' => 'The support reply email is switched off.'];
        }
        DB::table('support_ticket_messages')->where('id', $mid)->update(['mail_id' => $q['id'], 'email_message_id' => $q['messageId']]);
        $st = (string) DB::table('mail_outbox')->where('id', $q['id'])->value('status');

        return ['mail' => $st, 'to' => $to, 'why' => match ($st) {
            'sent' => 'Emailed to '.$to.'.',
            'failed' => 'The email could not be sent yet; it is retried every minute. Check Owner console → Integrations → Email if this stays.',
            default => (Settings::get('integrations')['email'] ?? false) ? 'Queued for '.$to.'; it goes out within a minute.' : 'Email sending is switched off in Integrations, so it was only queued.',
        }];
    }

    /** The customer's own tickets with what they may see. */
    public static function mine(User $u): array
    {
        return DB::table('support_reports')->where('owner_id', $u->id)->orderByDesc('created_at')->limit(30)
            ->get(['id', 'reference', 'area', 'kind', 'message', 'status', 'created_at', 'resolved_at'])
            ->map(fn ($t) => ['id' => $t->id, 'reference' => $t->reference ?: self::ref($t->id), 'area' => $t->area, 'kind' => $t->kind, 'status' => $t->status,
                'message' => $t->message, 'at' => (string) $t->created_at, 'messages' => self::messages($t->id, false)])->all();
    }

    /** Everything support needs about one customer, without document contents, passwords or payout details. */
    public static function profile(User $c): array
    {
        $p = DB::table('profiles')->where('owner_id', $c->id)->first();
        $count = fn (string $t) => Schema::hasTable($t) ? DB::table($t)->where('owner_id', $c->id)->count() : 0;
        $access = Settings::access($c->id);

        return [
            'user' => ['id' => $c->id, 'name' => $c->name, 'email' => $c->email, 'joined' => (string) $c->created_at, 'verified' => (bool) $c->email_verified_at, 'blocked' => (bool) $c->blocked,
                'source' => (string) ($c->signup_source ?? ''), 'landing' => (string) ($c->signup_landing ?? ''), 'marketingConsent' => (bool) ($p->marketing_consent ?? false)],
            'plan' => ['paid' => (bool) ($access['paid'] ?? false), 'plan' => (string) ($access['plan'] ?? 'free'), 'until' => (string) ($access['accessUntil'] ?? '')],
            'orders' => DB::table('purchases')->where('owner_id', $c->id)->orderByDesc('created_at')->limit(10)
                ->get(['id', 'plan', 'amount_paise', 'refunded_paise', 'status', 'provider', 'created_at', 'access_until'])->map(fn ($o) => (array) $o)->all(),
            'documents' => ['resumes' => $count('resume_drafts'), 'letters' => $count('cover_letter_drafts'), 'applications' => $count('applications'), 'atsScans' => $count('ats_scans')],
            'activity' => DB::table('product_usage')->where('owner_id', $c->id)->orderByDesc('created_at')->limit(15)->get(['action', 'created_at'])->map(fn ($a) => (array) $a)->all(),
            'errors' => DB::table('error_logs')->where('user_id', $c->id)->orderByDesc('last_seen')->limit(10)
                ->get(['id', 'level', 'source', 'message', 'occurrences', 'last_seen'])->map(fn ($e) => ['message' => mb_strimwidth((string) $e->message, 0, 200, '…')] + (array) $e)->all(),
            'tickets' => DB::table('support_reports')->where('owner_id', $c->id)->orderByDesc('created_at')->limit(10)->get(['id', 'reference', 'area', 'kind', 'status', 'created_at'])->map(fn ($t) => (array) $t)->all(),
            'taraEvents' => Schema::hasTable('tara_events') ? DB::table('tara_events')->where('owner_id', $c->id)->orderByDesc('created_at')->limit(10)->get(['kind', 'category', 'severity', 'detail', 'created_at'])->map(fn ($e) => (array) $e)->all() : [],
        ];
    }
}
