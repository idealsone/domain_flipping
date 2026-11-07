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

        return $q->get(['m.id', 'm.author_type', 'm.body', 'm.internal', 'm.created_at', 'u.name as author', 'u.email as author_email'])
            ->map(fn ($m) => ['id' => $m->id, 'from' => $m->author_type, 'body' => $m->body, 'internal' => (bool) $m->internal, 'at' => (string) $m->created_at]
                + ($staff ? ['author' => (string) ($m->author ?: ($m->author_type === 'customer' ? 'Customer' : 'CVTara team'))] : ['author' => $m->author_type === 'customer' ? 'You' : 'CVTara team']))->all();
    }

    public static function add(string $ticketId, ?int $authorId, string $type, string $body, bool $internal = false): string
    {
        $id = (string) Str::uuid();
        DB::table('support_ticket_messages')->insert(['id' => $id, 'ticket_id' => $ticketId, 'author_id' => $authorId, 'author_type' => $type, 'body' => mb_substr(trim($body), 0, 5000), 'internal' => $internal, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** A staff reply the customer can see: stored, response time recorded, the customer told by email. */
    public static function staffReply(object $ticket, User $staff, string $body): void
    {
        self::add($ticket->id, $staff->id, 'staff', $body);
        $set = ['updated_at' => now()];
        if (! $ticket->first_response_at) {
            $set['first_response_at'] = now();
        }
        if (in_array($ticket->status, ['new', 'seen'], true)) {
            $set['status'] = 'working';
        }
        DB::table('support_reports')->where('id', $ticket->id)->update($set);
        $customer = $ticket->owner_id ? User::find($ticket->owner_id) : null;
        if ($customer && ! $customer->blocked) {
            MailAutomation::queue($customer, 'support_reply', 'support-reply:'.$ticket->id.':'.now()->timestamp.':'.Str::random(4), [
                'ticket_ref' => $ticket->reference ?: self::ref($ticket->id), 'reply' => mb_strimwidth($body, 0, 2000, '…'),
                'ticket_url' => rtrim((string) config('app.url'), '/').'/workspace.html?support=mine',
            ], true);
        }
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
