<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PartnerHub (3.54.0, V7 §38-39): the B2B vertical. Colleges, coaching institutes, placement cells (TPO), companies and
 * influencers bring students and job seekers to CVTara through their own campaign links.
 *
 *  - partners            the organisation, its agreement (dates, reference) and commission rates
 *  - partner_links       campaign links /p/{code}; a sign-up within 30 days of the click is locked to the partner
 *  - partner_members     CVTara accounts that may open the partner portal (their own organisation only)
 *  - partner_commissions a ledger kept apart from customer referrals: 30% of plans, 40% of add-ons, the ₹99 Quick
 *                        Download earns nothing; each entry is pending through the 7-day refund window, then available;
 *                        a refund or chargeback voids it; the owner marks it paid with a payout reference
 *  - partner_applications organisations asking to become partners (public form), approved or declined by the owner
 *
 * The portal shows numbers only (sign-ups, buyers, revenue, commission by month and by link). It never shows a
 * student's name, email or documents.
 */
class Partners
{
    public const KINDS = ['college' => 'College', 'tpo' => 'Placement cell (TPO)', 'coaching' => 'Coaching institute', 'corporate' => 'Company', 'influencer' => 'Influencer / creator', 'other' => 'Other partner'];

    /** How each kind of partner is counted at sign-up (Attribution::lock). */
    public const ACQ = ['college' => ['b2b', 'college'], 'tpo' => ['b2b', 'college'], 'coaching' => ['b2b', 'coaching'], 'corporate' => ['b2b', 'corporate'], 'influencer' => ['b2c', 'influencer'], 'other' => ['b2b', 'partner']];

    public const CLICK_DAYS = 30;

    public const PLAN_BPS = 3000;

    public const ADDON_BPS = 4000;

    /** Plans at or below this list price earn no partner commission (the ₹99 Quick Download). */
    public const EXCLUDED_UP_TO_INR = 99;

    public const COOKIE = 'cvtara_partner';

    // ---------------------------------------------------------------- links and sign-up

    /** /p/{code}: remembers the partner link for this visit (session and a 30-day cookie) and counts the click. */
    public static function click(Request $r, string $code): ?string
    {
        $l = self::liveLink($code);
        if (! $l) {
            return null;
        }
        if (! $r->user()) {
            $r->session()->put('partner', ['code' => $l->code, 'at' => time()]);
            $seen = 'partner-click:'.$l->id.':'.hash_hmac('sha256', $r->session()->getId(), (string) config('app.key'));
            if (\Illuminate\Support\Facades\Cache::add($seen, 1, 86400)) {
                DB::table('partner_links')->where('id', $l->id)->increment('clicks');
            }
        }

        return $l->code;
    }

    private static function liveLink(string $code): ?object
    {
        $code = strtolower(trim($code));
        if (! preg_match('/^[a-z0-9-]{3,40}$/', $code)) {
            return null;
        }
        $l = DB::table('partner_links as l')->join('partners as p', 'p.id', '=', 'l.partner_id')->where('l.code', $code)->where('l.active', true)->where('p.status', 'active')
            ->where(fn ($q) => $q->whereNull('p.agreement_to')->orWhere('p.agreement_to', '>=', today()->toDateString()))
            ->first(['l.id', 'l.code', 'l.partner_id', 'l.campaign', 'p.kind']);

        return $l ?: null;
    }

    /** The acquisition fields for a sign-up that came through a partner link in the last 30 days, or null. */
    public static function attributionFor(Request $r): ?array
    {
        $s = $r->hasSession() ? (array) $r->session()->get('partner', []) : [];
        $code = $s['code'] ?? $r->cookie(self::COOKIE);
        $at = (int) ($s['at'] ?? 0) ?: null;
        if (! $code || ($at && $at < now()->subDays(self::CLICK_DAYS)->timestamp)) {
            return null;
        }
        // The later of a customer referral link and a partner link wins (last click before sign-up).
        $refAt = $r->hasSession() ? (int) $r->session()->get('referral.at', 0) : 0;
        if ($refAt && $at && $refAt > $at) {
            return null;
        }
        $l = self::liveLink((string) $code);
        if (! $l) {
            return null;
        }
        [$class, $sub] = self::ACQ[$l->kind] ?? ['b2b', 'partner'];

        return ['acq_class' => $class, 'acq_subtype' => $sub, 'acq_partner_id' => (int) $l->partner_id, 'acq_campaign' => $l->campaign ? mb_substr($l->campaign, 0, 80) : null, 'acq_link' => $l->code, 'acq_first_seen_at' => $at ? date('Y-m-d H:i:s', $at) : null];
    }

    // ---------------------------------------------------------------- commission ledger

    /** Called from verified payment processing (same transaction as Referrals::credit). */
    public static function credit(object $p): void
    {
        if ($p->status !== 'paid' || ! $p->is_live || ! in_array($p->provider, ['razorpay', 'phonepe', 'cashfree'], true) || (int) $p->amount_paise <= 0) {
            return;
        }
        $pid = DB::table('users')->where('id', $p->owner_id)->value('acq_partner_id');
        $partner = $pid ? DB::table('partners')->where('id', $pid)->first() : null;
        if (! $partner || $partner->status !== 'active') {
            return;
        }
        $when = $p->purchased_at ?: now();
        if (($partner->agreement_from && $when < $partner->agreement_from) || ($partner->agreement_to && substr((string) $when, 0, 10) > $partner->agreement_to)) {
            return;
        }
        [$kind, $bps] = self::rateFor($p->plan, $partner);
        if ($kind === null) {
            return;
        }
        DB::table('partner_commissions')->insertOrIgnore(['partner_id' => $partner->id, 'purchase_id' => $p->id, 'buyer_id' => $p->owner_id, 'plan' => mb_substr((string) $p->plan, 0, 60), 'kind' => $kind,
            'base_paise' => (int) $p->amount_paise, 'rate_bps' => $bps, 'amount_paise' => intdiv((int) $p->amount_paise * $bps, 10000), 'status' => 'pending',
            'available_at' => now()->addDays(Refunds::DAYS), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** [kind, rate] for a plan key, or [null, 0] when it earns nothing. */
    public static function rateFor(?string $plan, object $partner): array
    {
        $pl = Settings::plans()[(string) $plan] ?? null;
        if (! $pl || $plan === 'free' || ($pl['type'] ?? 'plan') === 'plan' && (int) ($pl['price'] ?? 0) <= self::EXCLUDED_UP_TO_INR) {
            return [null, 0];
        }
        $addon = in_array($pl['type'] ?? 'plan', ['addon', 'service'], true);

        return $addon ? ['addon', (int) ($partner->addon_bps ?? self::ADDON_BPS)] : ['plan', (int) ($partner->plan_bps ?? self::PLAN_BPS)];
    }

    /** A refund (full or part) or a chargeback voids the commission while it is not paid out yet. */
    public static function reverse(object $p): void
    {
        DB::table('partner_commissions')->where('purchase_id', $p->id)->whereIn('status', ['pending', 'available'])
            ->update(['status' => 'void', 'void_reason' => 'Order refunded or disputed', 'updated_at' => now()]);
    }

    /** A chargeback that was won brings the commission back (pending again for the rest of the window). */
    public static function restore(object $p): void
    {
        DB::table('partner_commissions')->where('purchase_id', $p->id)->where('status', 'void')->where('void_reason', 'Order refunded or disputed')
            ->update(['status' => 'pending', 'void_reason' => null, 'updated_at' => now()]);
    }

    /** Cron: commissions whose refund window passed (and whose order is still paid) become available. */
    public static function release(): int
    {
        $ids = DB::table('partner_commissions as c')->join('purchases as p', 'p.id', '=', 'c.purchase_id')->where('c.status', 'pending')->where('c.available_at', '<=', now())
            ->where('p.status', 'paid')->where('p.refunded_paise', 0)->limit(500)->pluck('c.id');

        return $ids->isEmpty() ? 0 : DB::table('partner_commissions')->whereIn('id', $ids)->update(['status' => 'available', 'updated_at' => now()]);
    }

    public static function markPaid(User $admin, array $ids, string $ref): int
    {
        $n = DB::table('partner_commissions')->whereIn('id', $ids)->where('status', 'available')->update(['status' => 'paid', 'paid_at' => now(), 'payout_ref' => mb_substr($ref, 0, 120), 'updated_at' => now()]);
        Settings::audit($admin->id, 'partner_commissions_paid', $n.' · '.mb_substr($ref, 0, 60));

        return $n;
    }

    // ---------------------------------------------------------------- owner side

    public static function save(User $admin, array $v, ?int $id = null): int
    {
        $row = ['name' => trim($v['name']), 'kind' => $v['kind'], 'status' => $v['status'] ?? 'active', 'contact_name' => $v['contactName'] ?? null, 'contact_email' => isset($v['contactEmail']) ? strtolower(trim($v['contactEmail'])) : null,
            'contact_phone' => $v['contactPhone'] ?? null, 'city' => $v['city'] ?? null, 'plan_bps' => (int) round(((float) ($v['planPercent'] ?? 30)) * 100), 'addon_bps' => (int) round(((float) ($v['addonPercent'] ?? 40)) * 100),
            'agreement_ref' => $v['agreementRef'] ?? null, 'agreement_from' => $v['agreementFrom'] ?? null, 'agreement_to' => $v['agreementTo'] ?? null, 'notes' => $v['notes'] ?? null, 'updated_at' => now()];
        if ($id) {
            abort_unless(DB::table('partners')->where('id', $id)->update($row), 404);
        } else {
            $id = (int) DB::table('partners')->insertGetId($row + ['created_by' => $admin->id, 'created_at' => now()]);
        }
        Settings::audit($admin->id, 'partner_saved', (string) $id);

        return $id;
    }

    public static function addLink(User $admin, int $partnerId, array $v): string
    {
        $p = DB::table('partners')->where('id', $partnerId)->first();
        abort_unless($p, 404);
        $code = strtolower(trim((string) ($v['code'] ?? '')));
        if ($code === '') {
            $code = Str::limit(Str::slug($p->name), 24, '').'-'.strtolower(Str::random(5));
        }
        abort_unless(preg_match('/^[a-z0-9-]{3,40}$/', $code), 422, 'Use 3 to 40 lowercase letters, numbers or dashes for the link.');
        abort_if(DB::table('partner_links')->where('code', $code)->exists(), 422, 'That link is already taken. Choose another.');
        DB::table('partner_links')->insert(['partner_id' => $partnerId, 'code' => $code, 'campaign' => $v['campaign'] ?? null, 'label' => $v['label'] ?? null, 'active' => true, 'clicks' => 0, 'created_at' => now(), 'updated_at' => now()]);
        Settings::audit($admin->id, 'partner_link_added', $partnerId.':'.$code);

        return $code;
    }

    public static function addMember(User $admin, int $partnerId, string $email, string $role): void
    {
        abort_unless(DB::table('partners')->where('id', $partnerId)->exists(), 404);
        $u = User::where('email', strtolower(trim($email)))->first();
        abort_unless($u, 422, 'No CVTara account uses that email. Ask the partner to sign up first, then add them.');
        abort_if($u->is_admin, 422, 'The owner account cannot be a partner member.');
        $other = DB::table('partner_members')->where('user_id', $u->id)->value('partner_id');
        abort_if($other && (int) $other !== $partnerId, 422, 'That account already belongs to another partner.');
        DB::table('partner_members')->updateOrInsert(['user_id' => $u->id], ['partner_id' => $partnerId, 'role' => $role, 'created_at' => now()]);
        Settings::audit($admin->id, 'partner_member_added', $partnerId.':'.$u->id);
    }

    /** One partner's numbers (owner view and partner portal share it). Counts only, never people. */
    public static function stats(int $partnerId): array
    {
        $users = DB::table('users')->where('acq_partner_id', $partnerId);
        $paid = DB::table('purchases as p')->join('users as u', 'u.id', '=', 'p.owner_id')->where('u.acq_partner_id', $partnerId)->whereIn('p.status', ['paid', 'partially_refunded'])->where('p.is_live', true)->where('p.amount_paise', '>', 0);
        $c = DB::table('partner_commissions')->where('partner_id', $partnerId)->selectRaw('status, SUM(amount_paise) s, COUNT(*) n')->groupBy('status')->get()->keyBy('status');
        $month = DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', created_at)" : "DATE_FORMAT(created_at, '%Y-%m')";
        $pmonth = DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', p.created_at)" : "DATE_FORMAT(p.created_at, '%Y-%m')";
        $signups = (clone $users)->selectRaw("$month m, COUNT(*) n")->groupByRaw($month)->pluck('n', 'm');
        $buyers = (clone $paid)->selectRaw("$pmonth m, COUNT(DISTINCT p.owner_id) n, SUM(p.amount_paise - p.refunded_paise) r")->groupByRaw($pmonth)->get()->keyBy('m');
        $months = collect(array_unique(array_merge($signups->keys()->all(), $buyers->keys()->all())))->sort()->values()->take(-12);
        $links = DB::table('partner_links')->where('partner_id', $partnerId)->orderBy('created_at')->get(['id', 'code', 'campaign', 'label', 'active', 'clicks']);
        $perLink = DB::table('users')->where('acq_partner_id', $partnerId)->whereNotNull('acq_link')->selectRaw('acq_link, COUNT(*) n')->groupBy('acq_link')->pluck('n', 'acq_link');

        return [
            'signups' => (clone $users)->count(),
            'buyers' => (clone $paid)->distinct('p.owner_id')->count('p.owner_id'),
            'revenuePaise' => (int) (clone $paid)->sum(DB::raw('p.amount_paise - p.refunded_paise')),
            'commission' => ['pendingPaise' => (int) ($c['pending']->s ?? 0), 'availablePaise' => (int) ($c['available']->s ?? 0), 'paidPaise' => (int) ($c['paid']->s ?? 0), 'voidPaise' => (int) ($c['void']->s ?? 0)],
            'months' => $months->map(fn ($m) => ['month' => $m, 'signups' => (int) ($signups[$m] ?? 0), 'buyers' => (int) ($buyers[$m]->n ?? 0), 'revenuePaise' => (int) ($buyers[$m]->r ?? 0)])->all(),
            'links' => $links->map(fn ($l) => ['id' => $l->id, 'code' => $l->code, 'url' => url('/p/'.$l->code), 'campaign' => $l->campaign, 'label' => $l->label, 'active' => (bool) $l->active, 'clicks' => (int) $l->clicks, 'signups' => (int) ($perLink[$l->code] ?? 0)])->all(),
        ];
    }

    // ---------------------------------------------------------------- partner portal

    public static function membership(?User $u): ?object
    {
        if (! $u) {
            return null;
        }
        $m = DB::table('partner_members as m')->join('partners as p', 'p.id', '=', 'm.partner_id')->where('m.user_id', $u->id)->first(['m.partner_id', 'm.role', 'p.name', 'p.kind', 'p.status', 'p.plan_bps', 'p.addon_bps', 'p.agreement_from', 'p.agreement_to']);

        return $m ?: null;
    }

    public static function portal(User $u): array
    {
        $m = self::membership($u);
        abort_unless($m, 404);

        return ['partner' => ['name' => $m->name, 'kind' => self::KINDS[$m->kind] ?? $m->kind, 'status' => $m->status, 'role' => $m->role, 'planPercent' => $m->plan_bps / 100, 'addonPercent' => $m->addon_bps / 100,
            'agreementFrom' => $m->agreement_from, 'agreementTo' => $m->agreement_to, 'holdDays' => Refunds::DAYS, 'excludedUpToInr' => self::EXCLUDED_UP_TO_INR]] + self::stats((int) $m->partner_id);
    }

    // ---------------------------------------------------------------- applications

    public static function apply(array $v, Request $r): int
    {
        $email = strtolower(trim($v['email']));
        abort_if(DB::table('partner_applications')->where('email', $email)->where('status', 'new')->exists(), 422, 'We already have your application and will reply by email.');
        $id = (int) DB::table('partner_applications')->insertGetId(['org_name' => trim($v['organisation']), 'kind' => $v['kind'], 'contact_name' => trim($v['name']), 'email' => $email,
            'phone' => $v['phone'] ?? null, 'city' => $v['city'] ?? null, 'size' => $v['size'] ?? null, 'message' => $v['message'] ?? null, 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        try {
            DB::table('notifications')->insertOrIgnore(['id' => (string) Str::uuid(), 'owner_id' => (int) DB::table('users')->where('is_admin', true)->orderBy('id')->value('id'), 'kind' => 'partner_application',
                'title' => 'New partner application', 'body' => trim($v['organisation']).' ('.(self::KINDS[$v['kind']] ?? $v['kind']).') wants to partner with CVTara.', 'link' => '/admin.html#partners', 'ref' => 'partner-app:'.$id, 'created_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            ErrorLog::exception($e);
        }

        return $id;
    }

    /** Approve makes the partner (paused until the owner adds the agreement and a link); decline just closes it. */
    public static function decide(User $admin, int $id, string $decision, string $note = ''): ?int
    {
        $a = DB::table('partner_applications')->where('id', $id)->where('status', 'new')->first();
        abort_unless($a, 409, 'This application was already decided.');
        $pid = null;
        if ($decision === 'approved') {
            $pid = self::save($admin, ['name' => $a->org_name, 'kind' => $a->kind, 'status' => 'paused', 'contactName' => $a->contact_name, 'contactEmail' => $a->email, 'contactPhone' => $a->phone, 'city' => $a->city, 'notes' => trim('From application #'.$id.'. '.$note)]);
        }
        DB::table('partner_applications')->where('id', $id)->update(['status' => $decision, 'partner_id' => $pid, 'review_note' => $note ?: null, 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'updated_at' => now()]);
        Settings::audit($admin->id, 'partner_application_'.$decision, (string) $id);

        return $pid;
    }
}
