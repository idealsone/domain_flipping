<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * One set of revenue definitions for both agents: confirmed money from the order ledger, refunds and chargebacks taken off,
 * variable costs (gateway fees as an estimate from the rate the owner enters, AI spending, campaign and link costs) taken off
 * again for contribution. Sales are shown by first touch, by last touch and as assisted, each view adding up to the same total,
 * so no sale is counted twice. Figures the site cannot know show as null ("Not tracked").
 */
class Scorecard
{
    /** What each autonomy level allows, per agent. Level 5 actions are listed so the owner sees they are never automated. */
    public const MATRIX = [
        'seo' => [
            1 => 'Monitor: scan pages, read Search Console, check crawlers and links, report.',
            2 => 'Recommend: draft titles, descriptions, internal links, content briefs and outreach pitches for you.',
            3 => 'Execute low-risk work: ask search engines to recrawl changed pages, re-check links, update keyword and page records.',
            4 => 'Approved changes: apply title/description and internal-link changes you approve one by one in the console.',
            5 => 'Never automated: new pages, redirects, canonical rules, deleting pages, bulk publishing, paid links.',
        ],
        'growth' => [
            1 => 'Diagnose: funnel, segments, revenue and campaign results.',
            2 => 'Prepare: segments, journey and campaign drafts, experiment plans.',
            3 => 'Execute low-risk work: refresh segments and events, send journey mails you switched on, stop mails for people who bought or opted out.',
            4 => 'Approved sends: campaigns and outreach you scheduled; budgets you set.',
            5 => 'Never automated: prices, payment records, refunds, credits or money to customers, overriding email consent.',
        ],
    ];

    public static function levels(): array
    {
        return ['seo' => Lifecycle::level('seo'), 'growth' => Lifecycle::level('growth')];
    }

    public static function saveLevel(string $agent, int $level): array
    {
        $l = (array) Settings::get('agentLevels', []);
        $l[$agent] = max(1, min(4, $level));
        Settings::put('agentLevels', $l);

        return self::levels();
    }

    public static function feePct(): ?float
    {
        $v = Settings::get('growthCosts', [])['gatewayFeePct'] ?? null;

        return $v === null || $v === '' ? null : (float) $v;
    }

    public static function report(int $days = 30): array
    {
        $now = now()->tz(Growth::TZ);
        $from = $now->copy()->subDays($days);
        $fromUtc = $from->copy()->utc();
        $m = Growth::metrics($from, $now);
        $sales = Growth::sales()->where('purchased_at', '>=', $fromUtc)->get(['id', 'owner_id', 'plan', 'amount_paise', 'refunded_paise', 'status', 'touch_source', 'touch_campaign', 'purchased_at']);
        $disputed = (int) $sales->where('status', 'disputed')->sum('amount_paise');
        $gross = (int) $sales->sum('amount_paise');
        $refunds = (int) $sales->sum('refunded_paise');
        $net = $gross - $refunds - $disputed;
        $fee = self::feePct();
        $fees = $fee === null ? null : (int) round($gross * $fee / 100);
        $ai = (int) DB::table('ai_usage')->where('created_at', '>=', $fromUtc)->sum('cost_paise');
        $aiBy = DB::table('ai_usage')->where('created_at', '>=', $fromUtc)->select('purpose', DB::raw('sum(cost_paise) as p'), DB::raw('count(*) as n'))->groupBy('purpose')->get()->map(fn ($x) => ['purpose' => $x->purpose, 'paise' => (int) $x->p, 'calls' => (int) $x->n])->all();
        $campaign = (int) DB::table('growth_campaign_plans')->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $from->toDateString()))->sum('cost_inr') * 100;
        $links = (int) DB::table('link_prospects')->where('updated_at', '>=', $fromUtc)->sum('cost_inr') * 100;
        $contribution = $net - ($fees ?? 0) - $ai - $campaign - $links;
        $buyers = $sales->pluck('owner_id')->unique();
        $users = DB::table('users')->whereIn('id', $buyers)->get(['id', 'signup_source', 'created_at'])->keyBy('id');
        $prior = Growth::sales()->where('purchased_at', '<', $fromUtc)->whereIn('owner_id', $buyers)->distinct()->pluck('owner_id')->flip();
        $repeatPaise = (int) $sales->filter(fn ($s) => isset($prior[$s->owner_id]))->sum(fn ($s) => $s->amount_paise - $s->refunded_paise);
        $referralPaise = (int) Growth::sales()->where('purchased_at', '>=', $fromUtc)->whereIn('id', DB::table('referral_commissions')->select('purchase_id'))->sum(DB::raw('amount_paise - refunded_paise'));

        // Attribution views. First touch: the buyer's first visit before signing up. Last touch: the visit in which they started checkout.
        // Assisted: sales whose first and last touch differ, credited once to each source that took part (shown apart; not added to totals).
        $first = [];
        $last = [];
        $assist = [];
        foreach ($sales as $s) {
            $v = $s->amount_paise - $s->refunded_paise - ($s->status === 'disputed' ? $s->amount_paise : 0);
            $f = $users[$s->owner_id]->signup_source ?? null ?: 'unknown';
            $l = $s->touch_source ?: 'unknown';
            $first[$f] = ['paise' => ($first[$f]['paise'] ?? 0) + $v, 'orders' => ($first[$f]['orders'] ?? 0) + 1];
            $last[$l] = ['paise' => ($last[$l]['paise'] ?? 0) + $v, 'orders' => ($last[$l]['orders'] ?? 0) + 1];
            if ($f !== 'unknown' && $l !== 'unknown' && $f !== $l) {
                foreach ([$f, $l] as $src) {
                    $assist[$src] = ['paise' => ($assist[$src]['paise'] ?? 0) + $v, 'orders' => ($assist[$src]['orders'] ?? 0) + 1];
                }
            }
        }
        $views = [];
        foreach (array_unique(array_merge(array_keys($first), array_keys($last), array_keys($assist))) as $src) {
            $views[] = ['source' => $src, 'firstPaise' => $first[$src]['paise'] ?? 0, 'firstOrders' => $first[$src]['orders'] ?? 0, 'lastPaise' => $last[$src]['paise'] ?? 0, 'lastOrders' => $last[$src]['orders'] ?? 0, 'assistedPaise' => $assist[$src]['paise'] ?? 0, 'assistedOrders' => $assist[$src]['orders'] ?? 0];
        }
        usort($views, fn ($a, $b) => $b['firstPaise'] <=> $a['firstPaise']);
        $organicFirst = ($first['google']['paise'] ?? 0) + ($first['bing']['paise'] ?? 0);
        $newPaid = max(0, $buyers->count() - count($prior));
        $runs = DB::table('agent_runs')->where('started_at', '>=', $fromUtc)->select('agent', 'status', DB::raw('count(*) as n'))->groupBy('agent', 'status')->get();
        $agents = [];
        foreach (AgentLog::AGENTS as $k => $label) {
            $ok = (int) $runs->where('agent', $k)->where('status', 'ok')->sum('n');
            $spend = (int) DB::table('ai_usage')->where('created_at', '>=', $fromUtc)->where('purpose', $k)->sum('cost_paise');
            $agents[] = ['agent' => $k, 'label' => $label, 'level' => Lifecycle::level($k), 'ok' => $ok, 'failed' => (int) $runs->where('agent', $k)->where('status', 'failed')->sum('n'), 'skipped' => (int) $runs->where('agent', $k)->where('status', 'skipped')->sum('n'),
                'spendPaise' => $spend, 'costPerTaskPaise' => $ok ? (int) round($spend / $ok) : null];
        }

        return ['days' => $days, 'period' => $from->toDateString().' to '.$now->toDateString().' (India time)',
            'money' => ['grossPaise' => $gross, 'refundsPaise' => $refunds, 'chargebacksPaise' => $disputed, 'netPaise' => $net, 'gatewayFeesPaise' => $fees, 'feePct' => $fee, 'aiPaise' => $ai, 'campaignPaise' => $campaign, 'linkPaise' => $links,
                'contributionPaise' => $contribution, 'contributionComplete' => $fees !== null, 'repeatPaise' => $repeatPaise, 'referralPaise' => $referralPaise, 'organicFirstTouchPaise' => $organicFirst,
                'recoveredPaise' => Lifecycle::checkout($days)['kpis']['recoveredPaise']],
            'customers' => ['registrations' => $m['signups'], 'activated' => $m['activated'], 'paidCustomers' => $buyers->count(), 'newPaidCustomers' => $newPaid, 'transactions' => $sales->count(),
                'variableCostPerNewCustomerPaise' => $newPaid ? (int) round(($ai + $campaign + $links + ($fees ?? 0)) / $newPaid) : null],
            'attribution' => $views, 'aiBy' => $aiBy, 'agents' => $agents, 'levels' => self::levels(), 'matrix' => self::MATRIX,
            'notTracked' => 'Ad spend is not connected. Gateway fees are an estimate from the rate you enter (exact fees are in the gateway dashboards). Infrastructure, staff and tax are not included, so contribution is not profit. Sales from visitors who did not allow analytics show as "unknown" source.'];
    }
}
