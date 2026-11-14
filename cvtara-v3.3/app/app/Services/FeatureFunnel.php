<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3.75.0 (owner ask 14 Nov 2026): "full track" of who uses which feature, through to purchase.
 *
 * Read straight from the tables that already hold each fact (resumes, ATS checks, cover letters, interview questions ticked,
 * mock interviews, personal model answers, applications), so it is exact and needs no new tracking code or cookies.
 * Owner, staff and blocked accounts are left out. Money comes from Growth::sales(), the same paid-order rule every report uses
 * (live, paid, not referral rewards, not ₹0 grants), so these numbers agree with Revenue.
 */
class FeatureFunnel
{
    /** feature key => [label, table, time column, extra where]. */
    public const FEATURES = [
        'resume' => ['Made a resume (builder or upload)', 'resume_drafts', 'created_at', null],
        'ats' => ['Ran an ATS check', 'ats_scans', 'created_at', null],
        'tailored' => ['Tailored a resume to a job', 'resume_drafts', 'created_at', ['source', 'tailored']],
        'letter' => ['Made a cover letter', 'cover_letter_drafts', 'created_at', null],
        'download' => ['Downloaded a resume', 'resume_drafts', 'downloaded_at', null],
        'questions' => ['Practised interview questions', 'interview_practised', 'created_at', null],
        'mock' => ['Took a mock interview', 'interview_sessions', 'created_at', null],
        'answers' => ['Got a personal model answer', 'interview_model_answers', 'created_at', null],
        'application' => ['Tracked a job application', 'applications', 'created_at', null],
    ];

    /** Packs worth ₹299 or more (the owner's target), and what counts as an add-on. */
    public const PACKS = ['job-hunt', 'pro', 'complete-kit'];

    private static function customers()
    {
        return DB::table('users')->where('is_admin', false)->where('blocked', false)->select('id');
    }

    /** Query of [owner, at] rows for one feature in a period, or null when this install has no such table yet. */
    public static function uses(string $key, ?string $from = null, ?string $to = null)
    {
        [, $table, $col, $where] = self::FEATURES[$key];
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $col)) {
            return null;
        }
        if ($where && ! Schema::hasColumn($table, $where[0])) {
            return null;
        }
        $q = DB::table($table)->whereNotNull($col)->whereIn('owner_id', self::customers());
        if ($where) {
            $q->where($where[0], $where[1]);
        }
        if ($from) {
            $q->where($col, '>=', $from);
        }
        if ($to) {
            $q->where($col, '<', $to);
        }

        return $q->selectRaw("owner_id as owner, $col as at");
    }

    private static function bounds(int $days): array
    {
        return [now()->subDays($days)->startOfDay()->utc()->format('Y-m-d H:i:s'), now()->utc()->addMinute()->format('Y-m-d H:i:s')];
    }

    /** Per-feature usage and how many of those people paid, plus the sign-up funnel and the plan mix, for the last N days. */
    public static function report(int $days = 30): array
    {
        [$from, $to] = self::bounds($days);
        $sales = Growth::sales()->get(['owner_id', 'plan', 'amount_paise', 'refunded_paise', 'purchased_at', 'limits_json']);
        $firstPaid = [];
        $packBuyers = [];
        $addonBuyers = [];
        foreach ($sales as $s) {
            $o = (int) $s->owner_id;
            $at = (string) $s->purchased_at;
            if (! isset($firstPaid[$o]) || $at < $firstPaid[$o]) {
                $firstPaid[$o] = $at;
            }
            $type = (json_decode((string) $s->limits_json, true) ?: [])['type'] ?? 'plan';
            if (in_array($s->plan, self::PACKS, true)) {
                $packBuyers[$o] = true;
            }
            if ($type !== 'plan' || $s->plan === 'complete-kit') {
                $addonBuyers[$o] = true;
            }
        }

        $features = [];
        $everyone = [];
        foreach (self::FEATURES as $k => $f) {
            $q = self::uses($k, $from, $to);
            if (! $q) {
                $features[] = ['key' => $k, 'label' => $f[0], 'tracked' => false];

                continue;
            }
            $rows = DB::query()->fromSub($q, 'u')->select('owner', DB::raw('count(*) as n'), DB::raw('min(at) as first'))->groupBy('owner')->get();
            $paid = $after = $packs = $addons = 0;
            foreach ($rows as $r) {
                $o = (int) $r->owner;
                $everyone[$o] = ($everyone[$o] ?? 0) + 1;
                if (isset($firstPaid[$o])) {
                    $paid++;
                    // paid at any time after they first used this feature in the period (or they were already a customer)
                    $after += $firstPaid[$o] >= (string) $r->first ? 1 : 0;
                }
                $packs += isset($packBuyers[$o]) ? 1 : 0;
                $addons += isset($addonBuyers[$o]) ? 1 : 0;
            }
            $people = $rows->count();
            $features[] = ['key' => $k, 'label' => $f[0], 'tracked' => true, 'people' => $people, 'uses' => (int) $rows->sum('n'),
                'paid' => $paid, 'paidAfter' => $after, 'packs' => $packs, 'addons' => $addons, 'paidRate' => $people ? round($paid / $people * 100, 1) : null];
        }

        // Sign-up cohort of the period, step by step.
        $cohort = DB::table('users')->where('is_admin', false)->where('blocked', false)->where('created_at', '>=', $from)->where('created_at', '<', $to)->pluck('id')->map(fn ($x) => (int) $x)->all();
        $in = array_flip($cohort);
        $usedAny = $usedTwo = 0;
        $byOwner = [];
        foreach (array_keys(self::FEATURES) as $k) {
            $q = self::uses($k);
            if (! $q || ! $cohort) {
                continue;
            }
            foreach (DB::query()->fromSub($q, 'u')->whereIn('owner', $cohort)->distinct()->pluck('owner') as $o) {
                $byOwner[(int) $o][$k] = true;
            }
        }
        foreach ($byOwner as $set) {
            $usedAny++;
            $usedTwo += count($set) >= 2 ? 1 : 0;
        }
        $pricing = $cohort ? DB::table('page_views')->whereIn('owner_id', $cohort)->whereIn('path', CustomerEvents::PRICING_PATHS)->distinct()->count('owner_id') : 0;
        $checkout = $cohort ? DB::table('purchases')->whereIn('owner_id', $cohort)->where('provider', '!=', 'referral_reward')->where('amount_paise', '>', 0)->distinct()->count('owner_id') : 0;
        $paidCohort = count(array_intersect_key($firstPaid, $in));
        $steps = [
            ['Signed up', count($cohort)],
            ['Confirmed their email', $cohort ? DB::table('users')->whereIn('id', $cohort)->whereNotNull('email_verified_at')->count() : 0],
            ['Used at least one feature', $usedAny],
            ['Used two or more features', $usedTwo],
            ['Looked at prices (signed in)', $pricing],
            ['Started a checkout', $checkout],
            ['Paid for anything', $paidCohort],
            ['Bought Job Hunt, Pro or the Kit', count(array_intersect_key($packBuyers, $in))],
            ['Bought an add-on or the Kit', count(array_intersect_key($addonBuyers, $in))],
        ];
        $funnel = [];
        foreach ($steps as $i => [$label, $v]) {
            $funnel[] = ['label' => $label, 'value' => $v, 'ofSignups' => $steps[0][1] ? round($v / $steps[0][1] * 100, 1) : null, 'fromPrevious' => $i && $steps[$i - 1][1] ? round($v / $steps[$i - 1][1] * 100, 1) : null];
        }

        // What was bought in the period.
        $plans = Settings::plans();
        $mix = [];
        foreach ($sales as $s) {
            if ((string) $s->purchased_at < $from) {
                continue;
            }
            $k = (string) $s->plan;
            $mix[$k] ??= ['plan' => $k, 'name' => $plans[$k]['name'] ?? ((json_decode((string) $s->limits_json, true) ?: [])['name'] ?? $k), 'orders' => 0, 'netPaise' => 0];
            $mix[$k]['orders']++;
            $mix[$k]['netPaise'] += (int) $s->amount_paise - (int) $s->refunded_paise;
        }
        usort($mix, fn ($a, $b) => $b['netPaise'] <=> $a['netPaise']);
        $orders = array_sum(array_column($mix, 'orders'));
        foreach ($mix as &$m) {
            $m['share'] = $orders ? round($m['orders'] / $orders * 100, 1) : 0;
        }
        unset($m);

        return ['days' => $days, 'features' => $features, 'funnel' => $funnel, 'mix' => array_values($mix), 'activePeople' => count($everyone),
            'note' => 'Counted from the saved resumes, checks, letters, interview practice and applications themselves, so nothing depends on cookies. Owner and staff accounts are left out. "Paid" uses the same paid-order rule as Revenue (live payments only, no ₹0 grants or referral rewards).'];
    }

    /** One page of the people who used a feature in the period: what they did, their plan now and what they paid. */
    public static function people(Request $r, string $key, int $days = 30): array
    {
        abort_unless(isset(self::FEATURES[$key]), 422, 'Unknown feature.');
        [$from, $to] = self::bounds($days);
        $q = self::uses($key, $from, $to);
        abort_unless($q, 404, 'This feature is not on this site yet.');
        $paid = Growth::sales()->select('owner_id', DB::raw('sum(amount_paise - refunded_paise) as net'), DB::raw('count(*) as orders'), DB::raw('max(purchased_at) as last_paid'))->groupBy('owner_id');
        $base = DB::query()->fromSub(DB::query()->fromSub($q, 'u')->select('owner', DB::raw('count(*) as uses'), DB::raw('min(at) as first_at'), DB::raw('max(at) as last_at'))->groupBy('owner'), 'f')
            ->join('users as us', 'us.id', '=', 'f.owner')
            ->leftJoinSub($paid, 'p', 'p.owner_id', '=', 'f.owner')
            ->select('f.owner', 'us.email', 'us.name', 'us.created_at as joined', 'f.uses', 'f.first_at', 'f.last_at', DB::raw('coalesce(p.net, 0) as net'), DB::raw('coalesce(p.orders, 0) as orders'), 'p.last_paid');
        $label = self::FEATURES[$key][0];
        $t = AdminTable::query($r, $base, ['search' => ['us.email', 'us.name'], 'sorts' => ['last' => 'f.last_at', 'uses' => 'f.uses', 'paid' => 'net', 'joined' => 'us.created_at'], 'sort' => 'last', 'key' => 'f.owner',
            'filters' => ['paid' => fn ($w, $v) => $v === 'yes' ? $w->where('p.orders', '>', 0) : ($v === 'no' ? $w->whereNull('p.orders') : null)],
            'export' => ['title' => 'Feature use: '.$label, 'map' => fn ($rows) => array_map(fn ($x) => ['Customer' => $x->email, 'Name' => $x->name, 'Joined' => (string) $x->joined, 'Times used' => (int) $x->uses, 'First use' => (string) $x->first_at, 'Last use' => (string) $x->last_at, 'Paid orders' => (int) $x->orders, 'Paid in total (₹)' => round(((int) $x->net) / 100), 'Last payment' => (string) ($x->last_paid ?? '')], $rows)]]);
        $access = Settings::accessMany(array_map(fn ($x) => (int) $x->owner, $t['rows']));
        $t['rows'] = array_map(fn ($x) => ['id' => (int) $x->owner, 'email' => $x->email, 'name' => $x->name, 'joined' => (string) $x->joined, 'uses' => (int) $x->uses, 'firstAt' => (string) $x->first_at, 'lastAt' => (string) $x->last_at,
            'orders' => (int) $x->orders, 'netPaise' => (int) $x->net, 'lastPaid' => $x->last_paid, 'planNow' => Settings::planLabel($access[(int) $x->owner] ?? ['paid' => false])], $t['rows']);

        return $t + ['feature' => $key, 'label' => $label, 'days' => $days];
    }
}
