<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The customer event store both agents read. Each meaningful action becomes one row in customer_events, written from the tables that
 * already hold the fact (accounts, resumes, ATS checks, orders, refunds, referrals, support), so nothing in the product has to change
 * and a missed run is caught up by the next one. Rows are idempotent (unique dedupe key) and hold no resume text or personal details.
 * Dynamic segments are worked out from the same facts at the moment they are read, so a customer who buys leaves the free-user
 * segments at once.
 */
class CustomerEvents
{
    public const TYPES = ['registered' => 'Signed up', 'verified' => 'Verified email', 'resume_created' => 'Created a resume', 'resume_downloaded' => 'Downloaded a resume', 'ats_check' => 'Ran an ATS / job match check',
        'cover_letter' => 'Created a cover letter', 'pricing_view' => 'Viewed checkout / pricing', 'checkout_started' => 'Started checkout', 'payment_failed' => 'Payment failed', 'payment_paid' => 'Payment confirmed',
        'refunded' => 'Refunded', 'disputed' => 'Chargeback opened', 'plan_expired' => 'Plan ended', 'referred_signup' => 'A friend signed up with their link', 'referral_paid' => 'A referred friend paid',
        'support_request' => 'Asked for help', 'journey_mail' => 'Lifecycle email sent', 'unsubscribed' => 'Turned off emails'];

    /** Customer pages that mean "looking at prices". */
    public const PRICING_PATHS = ['/checkout.html', '/checkout', '/pricing', '/plans', '/pricing/', '/pricing/quick-download/', '/pricing/job-hunt/', '/pricing/pro/', '/pricing/free-vs-paid/'];

    private const BATCH = 3000;

    /** type => [query(since), time column alias 'at']. Each query selects owner, ref, meta, at. */
    private static function sources(): array
    {
        $paidStatuses = ['paid', 'captured', 'partially_refunded', 'refunded', 'disputed'];
        $orders = fn () => DB::table('purchases')->where('provider', '!=', 'referral_reward')->where('amount_paise', '>', 0);

        return [
            'registered' => fn ($s) => DB::table('users')->where('is_admin', false)->where('created_at', '>=', $s)->selectRaw("id as owner, id as ref, coalesce(signup_source, '') as meta, created_at as at"),
            'verified' => fn ($s) => DB::table('users')->where('is_admin', false)->whereNotNull('email_verified_at')->where('email_verified_at', '>=', $s)->selectRaw("id as owner, id as ref, '' as meta, email_verified_at as at"),
            'resume_created' => fn ($s) => DB::table('resume_drafts')->where('created_at', '>=', $s)->selectRaw("owner_id as owner, id as ref, coalesce(source, '') as meta, created_at as at"),
            'resume_downloaded' => fn ($s) => DB::table('resume_drafts')->whereNotNull('downloaded_at')->where('downloaded_at', '>=', $s)->selectRaw("owner_id as owner, id as ref, '' as meta, downloaded_at as at"),
            'ats_check' => fn ($s) => DB::table('ats_scans')->where('created_at', '>=', $s)->selectRaw("owner_id as owner, id as ref, source as meta, created_at as at"),
            'cover_letter' => fn ($s) => DB::table('cover_letter_drafts')->where('created_at', '>=', $s)->selectRaw("owner_id as owner, id as ref, '' as meta, created_at as at"),
            'pricing_view' => fn ($s) => DB::table('page_views')->whereNotNull('owner_id')->whereIn('path', self::PRICING_PATHS)->where('created_at', '>=', $s)->selectRaw("owner_id as owner, path as ref, '' as meta, created_at as at"),
            'checkout_started' => fn ($s) => $orders()->where('created_at', '>=', $s)->selectRaw("owner_id as owner, id as ref, plan as meta, created_at as at"),
            'payment_failed' => fn ($s) => $orders()->where('status', 'failed')->where('updated_at', '>=', $s)->selectRaw("owner_id as owner, id as ref, plan as meta, updated_at as at"),
            'payment_paid' => fn ($s) => $orders()->whereIn('status', $paidStatuses)->whereNotNull('purchased_at')->where('purchased_at', '>=', $s)->selectRaw("owner_id as owner, id as ref, plan as meta, purchased_at as at"),
            'refunded' => fn ($s) => $orders()->where('refunded_paise', '>', 0)->where('updated_at', '>=', $s)->selectRaw("owner_id as owner, id as ref, plan as meta, updated_at as at"),
            'disputed' => fn ($s) => $orders()->where('status', 'disputed')->where('updated_at', '>=', $s)->selectRaw("owner_id as owner, id as ref, plan as meta, updated_at as at"),
            'plan_expired' => fn ($s) => $orders()->whereIn('status', ['paid', 'partially_refunded'])->where('plan', 'not like', 'addon-%')->whereNotNull('access_until')->where('access_until', '>=', $s)->where('access_until', '<=', now())->selectRaw("owner_id as owner, id as ref, plan as meta, access_until as at"),
            'referred_signup' => fn ($s) => DB::table('users')->whereNotNull('referred_by')->where('created_at', '>=', $s)->selectRaw("referred_by as owner, id as ref, '' as meta, created_at as at"),
            'referral_paid' => fn ($s) => DB::table('referral_commissions')->where('created_at', '>=', $s)->selectRaw("referrer_id as owner, purchase_id as ref, '' as meta, created_at as at"),
            'support_request' => fn ($s) => DB::table('support_reports')->whereNotNull('owner_id')->where('created_at', '>=', $s)->selectRaw("owner_id as owner, id as ref, area as meta, created_at as at"),
            'journey_mail' => fn ($s) => DB::table('mail_outbox')->whereNotNull('owner_id')->where('status', 'sent')->whereIn('trigger', array_merge(EmailRenderer::MARKETING, ['purchase_verified']))->where('trigger', '!=', 'campaign')->where('sent_at', '>=', $s)->selectRaw('owner_id as owner, id as ref, `trigger` as meta, sent_at as at'),
            'unsubscribed' => fn ($s) => DB::table('activity_events')->whereNotNull('owner_id')->where('event', 'unsubscribed')->where('created_at', '>=', $s)->selectRaw("owner_id as owner, id as ref, '' as meta, created_at as at"),
        ];
    }

    /** Copies new facts into the event store. Safe to run any number of times. */
    public static function sync(): array
    {
        $cursor = (array) Settings::get('eventCursor', []);
        $added = 0;
        foreach (self::sources() as $type => $q) {
            $since = isset($cursor[$type]) ? date('Y-m-d H:i:s', strtotime($cursor[$type]) - 7200) : '2000-01-01 00:00:00';
            try {
                $rows = $q($since)->orderBy('at')->limit(self::BATCH)->get();
            } catch (\Throwable $e) {
                ErrorLog::record('warning', 'growth', "Customer events: could not read $type: ".mb_strimwidth($e->getMessage(), 0, 160, '…'));

                continue;
            }
            $insert = [];
            foreach ($rows as $r) {
                if (! $r->owner || ! $r->at) {
                    continue;
                }
                $at = date('Y-m-d H:i:s', strtotime((string) $r->at));
                $key = $type === 'pricing_view' ? "pricing_view:$r->owner:".substr($at, 0, 10) : "$type:$r->ref";
                $insert[] = ['owner_id' => (int) $r->owner, 'type' => $type, 'ref' => mb_substr((string) $r->ref, 0, 80), 'meta' => mb_substr((string) $r->meta, 0, 300), 'occurred_at' => $at, 'dedupe' => mb_substr($key, 0, 120), 'created_at' => now()];
            }
            foreach (array_chunk($insert, 300) as $chunk) {
                $added += DB::table('customer_events')->insertOrIgnore($chunk);
            }
            if ($rows->count()) {
                $cursor[$type] = date('c', strtotime((string) $rows->last()->at));
            }
        }
        Settings::put('eventCursor', $cursor);

        return ['summary' => "$added new customer event(s).", 'added' => $added];
    }

    /** One customer's timeline, newest first. */
    public static function timeline(int $ownerId, int $limit = 200): array
    {
        $u = DB::table('users')->where('id', $ownerId)->first(['id', 'name', 'email', 'created_at', 'signup_source', 'signup_landing', 'signup_campaign', 'signup_ref_host', 'referred_by']);
        if (! $u) {
            return [];
        }
        $events = DB::table('customer_events')->where('owner_id', $ownerId)->orderByDesc('occurred_at')->limit($limit)->get()->map(fn ($e) => ['type' => $e->type, 'label' => self::TYPES[$e->type] ?? $e->type, 'meta' => $e->meta, 'at' => $e->occurred_at])->all();
        $segments = [];
        foreach (array_keys(self::SEGMENTS) as $k) {
            if (self::segment($k)->where('id', $ownerId)->exists()) {
                $segments[] = ['key' => $k, 'label' => self::SEGMENTS[$k]['label']];
            }
        }
        $sales = Growth::sales()->where('owner_id', $ownerId)->get(['amount_paise', 'refunded_paise', 'touch_source']);

        return ['user' => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'since' => $u->created_at, 'firstTouch' => $u->signup_source ? ['source' => $u->signup_source, 'landing' => $u->signup_landing, 'campaign' => $u->signup_campaign, 'site' => $u->signup_ref_host] : null, 'referred' => (bool) $u->referred_by],
            'lifetimeNetPaise' => (int) ($sales->sum('amount_paise') - $sales->sum('refunded_paise')), 'orders' => $sales->count(), 'segments' => $segments, 'events' => $events,
            'consent' => (bool) DB::table('profiles')->where('owner_id', $ownerId)->value('marketing_consent')];
    }

    /** Event counts per type over a period. */
    public static function counts(int $days = 30): array
    {
        $rows = DB::table('customer_events')->where('occurred_at', '>=', now()->subDays($days))->select('type', DB::raw('count(*) as n'), DB::raw('count(distinct owner_id) as people'))->groupBy('type')->get()->keyBy('type');

        return collect(self::TYPES)->map(fn ($l, $k) => ['type' => $k, 'label' => $l, 'events' => (int) ($rows[$k]->n ?? 0), 'people' => (int) ($rows[$k]->people ?? 0)])->values()->all();
    }

    // ---------------------------------------------------------------- dynamic segments

    public const SEGMENTS = [
        'new' => ['label' => 'New sign-ups (last 7 days)', 'action' => 'Welcome and first-resume help (welcome mail; getting-started journey)'],
        'inactive' => ['label' => 'Signed up, never made a resume or ran a check', 'action' => 'Getting-started journey with a two-minute first step'],
        'resume_no_ats' => ['label' => 'Made a resume, never ran an ATS check', 'action' => 'ATS check journey: show what a job match finds'],
        'ats_no_purchase' => ['label' => 'Ran ATS checks, never bought', 'action' => 'Explain what a paid download adds; plan comparison'],
        'pricing_visitor' => ['label' => 'Looked at checkout in the last 14 days, did not buy', 'action' => 'Plan questions answered: pricing-help journey'],
        'checkout_abandoned' => ['label' => 'Started checkout in the last 7 days, not paid', 'action' => 'Payment reminder (two at most) with the same package link'],
        'payment_failed' => ['label' => 'Payment failed in the last 7 days, not paid since', 'action' => 'Payment help and a safe retry link'],
        'first_paid' => ['label' => 'First purchase in the last 30 days', 'action' => 'Onboarding: get the first download and ATS check done'],
        'heavy' => ['label' => 'Heavy users (5+ ATS checks in 30 days)', 'action' => 'Suggest the next useful package or add-on'],
        'expiring' => ['label' => 'Plan ends within 7 days', 'action' => 'Renewal reminders (7, 4 and 1 day before)'],
        'expired' => ['label' => 'Plan ended, nothing active now', 'action' => 'Plan-ended mail; reactivation campaign'],
        'happy' => ['label' => 'Left a 4 or 5 star review', 'action' => 'Invite to the referral programme'],
        'referrers' => ['label' => 'Shared their link and a friend signed up', 'action' => 'Thank and remind how the referral bonus works'],
    ];

    /** Query of users.id for a segment (customers only; consent is applied separately where mail is sent). */
    public static function segment(string $key)
    {
        $users = DB::table('users')->where('is_admin', false)->where('blocked', false);
        $paidOrders = fn () => DB::table('purchases')->whereIn('status', ['paid', 'partially_refunded'])->where('provider', '!=', 'referral_reward')->where('amount_paise', '>', 0);
        $bought = fn () => DB::table('purchases')->whereNotNull('purchased_at')->where('provider', '!=', 'referral_reward')->select('owner_id');
        $plans = fn () => $paidOrders()->where('plan', 'not like', 'addon-%');

        return match ($key) {
            'new' => $users->where('created_at', '>=', now()->subDays(7)),
            'inactive' => $users->where('created_at', '<', now()->subDays(1))->whereNotIn('id', DB::table('resume_drafts')->select('owner_id'))->whereNotIn('id', DB::table('ats_scans')->select('owner_id')),
            'resume_no_ats' => $users->whereIn('id', DB::table('resume_drafts')->select('owner_id'))->whereNotIn('id', DB::table('ats_scans')->select('owner_id')),
            'ats_no_purchase' => $users->whereIn('id', DB::table('ats_scans')->select('owner_id'))->whereNotIn('id', $bought()),
            'pricing_visitor' => $users->whereIn('id', DB::table('customer_events')->where('type', 'pricing_view')->where('occurred_at', '>=', now()->subDays(14))->select('owner_id'))->whereNotIn('id', $paidOrders()->where('purchased_at', '>=', now()->subDays(14))->select('owner_id')),
            'checkout_abandoned' => $users->whereIn('id', DB::table('purchases')->where('status', 'created')->where('created_at', '>=', now()->subDays(7))->where('created_at', '<=', now()->subHour())->select('owner_id'))->whereNotIn('id', $paidOrders()->where('purchased_at', '>=', now()->subDays(7))->select('owner_id')),
            'payment_failed' => $users->whereIn('id', DB::table('purchases')->where('status', 'failed')->where('updated_at', '>=', now()->subDays(7))->select('owner_id'))->whereNotIn('id', $paidOrders()->where('purchased_at', '>=', now()->subDays(7))->select('owner_id')),
            'first_paid' => $users->whereIn('id', $paidOrders()->where('purchased_at', '>=', now()->subDays(30))->select('owner_id'))->whereNotIn('id', DB::table('purchases')->whereNotNull('purchased_at')->where('provider', '!=', 'referral_reward')->where('purchased_at', '<', now()->subDays(30))->select('owner_id')),
            'heavy' => $users->whereIn('id', DB::table('ats_scans')->where('created_at', '>=', now()->subDays(30))->groupBy('owner_id')->havingRaw('count(*) >= 5')->select('owner_id')),
            'expiring' => $users->whereIn('id', $plans()->whereBetween('access_until', [now(), now()->addDays(7)])->select('owner_id'))->whereNotIn('id', $plans()->where('access_until', '>', now()->addDays(7))->select('owner_id')),
            'expired' => $users->whereIn('id', $plans()->select('owner_id'))->whereNotIn('id', $plans()->where('access_until', '>', now())->select('owner_id')),
            'happy' => $users->whereIn('id', DB::table('feedback')->where('rating', '>=', 4)->whereNotNull('owner_id')->select('owner_id')),
            'referrers' => $users->whereIn('id', DB::table('users')->whereNotNull('referred_by')->select('referred_by')),
            default => abort(422, 'Unknown segment.'),
        };
    }

    /** People in a segment who may get marketing mail: opted in, verified, not suppressed. */
    public static function reachable($segment)
    {
        return $segment->whereNotNull('email_verified_at')->whereIn('id', DB::table('profiles')->where('marketing_consent', true)->select('owner_id'))->whereNotIn('email', DB::table('email_suppressions')->select('email'));
    }

    public static function segments(): array
    {
        $out = [];
        foreach (self::SEGMENTS as $k => $s) {
            $out[] = ['key' => $k, 'label' => $s['label'], 'action' => $s['action'], 'people' => self::segment($k)->count(), 'reachable' => self::reachable(self::segment($k))->count()];
        }

        return $out;
    }
}
