<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Read-only customer view (3.40.0, "Mode A"). A staff member with customers.view_as opens a customer's workspace from a
 * ticket, with a reason, for at most 30 minutes. While it runs, that staff member's customer API reads are answered as
 * the customer, but only for the pages listed in READ (deny by default); every other read and every change is refused
 * by the server. Each request is written to support_session_events. The session ends on Exit, on expiry, when the
 * owner ends it, or when the staff member's role is removed.
 */
class SupportView
{
    public const MINUTES = 30;

    /** Customer API reads allowed in the view (path regexes). Nothing about passwords, security codes, payouts or wallets. */
    private const READ = [
        '#^(profile|allowance|credits/history|ai/status|notifications|whats-new|purchases|refunds|applications|ats/scans|support/mine|service-requests|downloads|interviews)$#',
        '#^(resumes|letters|applications|ats/scans|service-requests|interviews)/[a-f0-9-]{36}$#',
        '#^(resumes|letters)$#',
        '#^resumes/[a-f0-9-]{36}/quality$#',
    ];

    public static function current(Request $r): ?object
    {
        $sid = $r->hasSession() ? $r->session()->get('support_view') : null;
        if (! $sid || ! $r->user()) {
            return null;
        }
        $s = DB::table('support_sessions')->where('id', $sid)->where('staff_id', $r->user()->id)->whereNull('ended_at')->first();
        if (! $s || strtotime((string) $s->expires_at) <= time() || ! Access::can($r->user(), 'customers.view_as')) {
            if ($s) {
                self::close($s->id, null);
            }
            $r->session()->forget('support_view');

            return null;
        }

        return $s;
    }

    /**
     * Called first in the customer API. Returns the customer to answer as, null when no view is open (or the path is the
     * staff member's own: the console API and the view's own controls), or refuses the request.
     */
    public static function swap(Request $r, string $path): ?User
    {
        if (str_starts_with($path, 'admin/') || str_starts_with($path, 'support-view') || $path === 'csrf') {
            return null;
        }
        $s = self::current($r);
        if (! $s) {
            return null;
        }
        $allowed = $r->isMethod('GET') && collect(self::READ)->contains(fn ($re) => preg_match($re, $path));
        $c = $allowed ? User::whereKey($s->customer_id)->first() : null;
        $ok = $c && ! $c->blocked && ! Access::isStaff($c);
        self::event($s->id, $r, $path, $ok ? 200 : 403);
        abort_unless($ok, 403, 'This is a read-only view of the customer\'s account. Changes, downloads and private settings are not available here.');
        Auth::setUser($c);
        $r->setUserResolver(fn () => $c);

        return $c;
    }

    public static function start(Request $r, User $staff, int $customerId, ?string $ticketId, string $reason): array
    {
        $c = User::find($customerId);
        abort_unless($c, 404);
        abort_if(Access::isStaff($c), 422, 'Console accounts cannot be opened in the customer view.');
        abort_if($c->blocked, 422, 'This account is blocked.');
        if ($ticketId) {
            abort_unless(DB::table('support_reports')->where('id', $ticketId)->where('owner_id', $c->id)->exists(), 422, 'That ticket is not from this customer.');
        }
        // One open view per staff member.
        foreach (DB::table('support_sessions')->where('staff_id', $staff->id)->whereNull('ended_at')->pluck('id') as $old) {
            self::close($old, $staff->id);
        }
        $id = (string) Str::uuid();
        DB::table('support_sessions')->insert(['id' => $id, 'ticket_id' => $ticketId, 'staff_id' => $staff->id, 'customer_id' => $c->id, 'mode' => 'read_only', 'reason' => mb_substr(trim($reason), 0, 500),
            'started_at' => now(), 'expires_at' => now()->addMinutes(self::MINUTES), 'created_at' => now(), 'updated_at' => now()]);
        $r->session()->put('support_view', $id);
        if ($ticketId) {
            SupportCentre::add($ticketId, $staff->id, 'system', $staff->name.' opened a read-only view of this account. Reason: '.mb_substr(trim($reason), 0, 300), true);
        }
        Audit::record($staff, 'support:view_as_start', (string) $c->id, null, ['session' => $id, 'ticket' => $ticketId, 'minutes' => self::MINUTES], $reason, 200, $r);

        return self::status($r);
    }

    public static function end(Request $r, ?int $by = null): void
    {
        if ($s = self::current($r)) {
            self::close($s->id, $by ?? $r->user()?->id);
            Audit::record($r->user(), 'support:view_as_end', (string) $s->customer_id, null, ['session' => $s->id], '', 200, $r);
        }
        if ($r->hasSession()) {
            $r->session()->forget('support_view');
        }
    }

    public static function close(string $id, ?int $by): void
    {
        DB::table('support_sessions')->where('id', $id)->whereNull('ended_at')->update(['ended_at' => now(), 'ended_by' => $by, 'updated_at' => now()]);
    }

    public static function status(Request $r): array
    {
        $s = self::current($r);
        if (! $s) {
            return ['active' => false];
        }
        $c = User::find($s->customer_id);

        return ['active' => true, 'customer' => ['id' => $c?->id, 'name' => $c?->name, 'email' => $c?->email], 'ticketId' => $s->ticket_id, 'secondsLeft' => max(0, strtotime((string) $s->expires_at) - time())];
    }

    private static function event(string $sid, Request $r, string $path, int $status): void
    {
        DB::table('support_session_events')->insert(['session_id' => $sid, 'method' => $r->method(), 'path' => mb_substr($path, 0, 190), 'status' => $status, 'ip' => (string) $r->ip(), 'created_at' => now()]);
    }
}
