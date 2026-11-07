<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One place for the business numbers (3.43.0). Every KPI has a written definition next to the code that counts it, and
 * docs/METRICS.md is generated from the same list (php artisan cvtara:metrics-doc), so the page and the numbers cannot
 * drift apart. Money and customer counts come from Growth::metrics, the function the reports and the Business assistant
 * already use, so the same period gives the same numbers on every screen. A number that is not tracked is null, never 0.
 */
class Metrics
{
    /** key => [label, unit, definition]. Order is the order on the Overview. */
    public const DEFS = [
        'registrations' => ['Registrations', 'count', 'New customer accounts created in the period (console accounts excluded).'],
        'verified' => ['Verified sign-ups', 'count', 'Accounts whose email was confirmed in the period.'],
        'paidCustomers' => ['Paying customers', 'count', 'Different customers with at least one paid purchase in the period (refunded purchases included; referral rewards and ₹0 grants excluded).'],
        'newCustomers' => ['First-time buyers', 'count', 'Paying customers in the period who had never paid before it.'],
        'orders' => ['Paid orders', 'count', 'Paid purchases in the period, counted by payment date.'],
        'grossPaise' => ['Gross collections', 'money', 'Total charged for paid purchases in the period, including tax, before refunds.'],
        'refundedPaise' => ['Refunds', 'money', 'Amount refunded on the purchases paid in the period.'],
        'netPaise' => ['Net revenue', 'money', 'Gross collections minus refunds. Gateway fees are not taken off (not recorded).'],
        'paymentSuccessRate' => ['Payment success rate', 'percent', 'Paid ÷ (paid + failed) checkouts started in the period. Checkouts still open are left out. Not tracked when nobody finished a checkout.'],
        'refundRequests' => ['Refund requests', 'count', 'Refund requests made in the period, whatever their outcome.'],
        'resumesBuilt' => ['Resumes built', 'count', 'Resumes saved for the first time in the period (new drafts and job versions).'],
        'atsScans' => ['ATS scans', 'count', 'Finished ATS checks in the period.'],
        'coverLetters' => ['Cover letters', 'count', 'Cover letters saved for the first time in the period.'],
        'organicVisitors' => ['Organic visitors', 'count', 'Different visitors arriving from search engines (Google, Bing and others), from the site\'s own visit log. Not tracked when site analytics is off.'],
        'marketingVisitors' => ['Marketing visitors', 'count', 'Different visitors from email, social, referral links and campaigns (everything except search, direct and internal). Not tracked when site analytics is off.'],
        'supportBacklog' => ['Support backlog', 'count', 'Support tickets open right now (new, seen or being handled), whatever the period.'],
        'aiSuccessRate' => ['AI service success', 'percent', 'Share of AI requests in the last 24 hours that got an answer. Not tracked with no requests.'],
        'approvalsWaiting' => ['Approvals waiting', 'count', 'SEO changes, refunds and tasks waiting for a decision right now.'],
        'tasksOpen' => ['Open SEO and Growth tasks', 'count', 'Tasks not yet verified, rejected or cancelled, right now.'],
        'tasksOverdue' => ['Overdue tasks', 'count', 'Tasks past their due date and not finished, right now.'],
    ];

    private const SEARCH = ['google', 'bing', 'yahoo', 'duckduckgo', 'yandex', 'baidu', 'ecosia', 'search', 'organic'];

    public static function compute(Carbon $from, Carbon $to): array
    {
        $g = Growth::metrics($from, $to);
        [$a, $b] = [$from->copy()->utc(), $to->copy()->utc()];
        $in = fn (string $t, string $col = 'created_at') => Schema::hasTable($t) ? DB::table($t)->where($col, '>=', $a)->where($col, '<', $b->copy()->addSecond()) : null;
        $count = fn ($q) => $q ? $q->count() : null;
        $checkouts = $in('purchases');
        $paid = $checkouts ? (clone $checkouts)->whereIn('status', ['paid', 'refunded', 'partially_refunded', 'disputed'])->where('amount_paise', '>', 0)->where('provider', '!=', 'referral_reward')->count() : 0;
        $failed = $checkouts ? (clone $checkouts)->where('status', 'failed')->count() : 0;
        $sources = $g['visitors'] === null ? null : (array) $g['sources']; // analytics off: not tracked
        $search = $sources === null ? null : array_sum(array_intersect_key($sources, array_flip(self::SEARCH)));
        $marketing = $sources === null ? null : array_sum(array_diff_key($sources, array_flip([...self::SEARCH, 'direct', 'internal', ''])));
        $ai = AiGateway::health();
        $tasks = Schema::hasTable('ai_tasks') ? DB::table('ai_tasks')->whereNotIn('status', ['verified', 'rejected', 'cancelled']) : null;
        $waiting = (Schema::hasTable('seo_proposals') ? DB::table('seo_proposals')->where('status', 'pending')->count() : 0) + DB::table('refund_requests')->where('status', 'requested')->count()
            + (Schema::hasTable('ai_tasks') ? DB::table('ai_tasks')->whereIn('status', ['submitted', 'under_review', 'completed'])->count() : 0);
        $v = [
            'registrations' => $g['signups'], 'verified' => $g['verified'], 'paidCustomers' => $g['buyers'], 'newCustomers' => $g['newCustomers'], 'orders' => $g['purchases'],
            'grossPaise' => $g['grossPaise'], 'refundedPaise' => $g['refundedPaise'], 'netPaise' => $g['netPaise'],
            'paymentSuccessRate' => ($paid + $failed) > 0 ? round($paid / ($paid + $failed) * 100, 1) : null,
            'refundRequests' => $count($in('refund_requests')), 'resumesBuilt' => $count($in('resume_drafts')),
            'atsScans' => ($q = $in('ats_scans')) ? $q->where('status', 'done')->count() : null, 'coverLetters' => $count($in('cover_letter_drafts')),
            'organicVisitors' => $search, 'marketingVisitors' => $marketing,
            'supportBacklog' => DB::table('support_reports')->whereIn('status', ['new', 'seen', 'working'])->count(),
            'aiSuccessRate' => ($ai['calls'] ?? 0) > 0 ? ($ai['successPct'] ?? null) : null,
            'approvalsWaiting' => $waiting,
            'tasksOpen' => $tasks ? (clone $tasks)->count() : null,
            'tasksOverdue' => $tasks ? (clone $tasks)->where(fn ($q) => $q->where('status', 'overdue')->orWhere(fn ($w) => $w->whereIn('status', ['approved', 'in_progress'])->where('due_at', '<', now())))->count() : null,
        ];
        $out = [];
        foreach (self::DEFS as $k => [$label, $unit, $def]) {
            $out[] = ['key' => $k, 'label' => $label, 'unit' => $unit, 'definition' => $def, 'value' => $v[$k] ?? null, 'tracked' => ($v[$k] ?? null) !== null];
        }

        return ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'at' => now()->toIso8601String(), 'metrics' => $out];
    }

    /**
     * Things the owner should look at, worked out from the same numbers. Each is also written once a day to the Error
     * Log as a warning (repeats count up there), so it reaches the owner's existing error alerts.
     */
    public static function alerts(bool $log = false): array
    {
        $out = [];
        $day = now()->subDay();
        $p = DB::table('purchases')->where('created_at', '>=', $day)->where('amount_paise', '>', 0);
        $failed = (clone $p)->where('status', 'failed')->count();
        $paid = (clone $p)->whereIn('status', ['paid', 'refunded', 'partially_refunded'])->count();
        if ($failed >= 5 && $failed > $paid) {
            $out[] = ['key' => 'payments', 'level' => 'high', 'text' => $failed.' payments failed in the last 24 hours against '.$paid.' that went through. Check the payment gateway.'];
        }
        $ai = AiGateway::health();
        if (($ai['calls'] ?? 0) >= (int) config('ai.health.min_calls', 20) && ($ai['successPct'] ?? 100) < (int) config('ai.health.min_success_pct', 80)) {
            $out[] = ['key' => 'ai', 'level' => 'high', 'text' => 'Only '.$ai['successPct'].'% of AI requests worked in the last 24 hours. Customers see "try again" messages.'];
        }
        $hours = (int) config('approvals.waitingHours', 48);
        $old = (Schema::hasTable('seo_proposals') ? DB::table('seo_proposals')->where('status', 'pending')->where('created_at', '<', now()->subHours($hours))->count() : 0)
            + DB::table('refund_requests')->where('status', 'requested')->where('created_at', '<', now()->subHours($hours))->count()
            + (Schema::hasTable('ai_tasks') ? DB::table('ai_tasks')->whereIn('status', ['submitted', 'under_review', 'completed'])->where('updated_at', '<', now()->subHours($hours))->count() : 0);
        if ($old > 0) {
            $out[] = ['key' => 'approvals', 'level' => 'medium', 'text' => $old.' approval'.($old === 1 ? ' has' : 's have').' waited more than '.$hours.' hours. See Approvals & tasks.'];
        }
        $overdue = Schema::hasTable('ai_tasks') ? DB::table('ai_tasks')->where('status', 'overdue')->count() : 0;
        if ($overdue > 0) {
            $out[] = ['key' => 'tasks', 'level' => 'medium', 'text' => $overdue.' task'.($overdue === 1 ? ' is' : 's are').' overdue.'];
        }
        if (Schema::hasTable('payment_reconciliation_records') && ($m = DB::table('payment_reconciliation_records')->whereNull('resolved_at')->count())) {
            $out[] = ['key' => 'reconciliation', 'level' => 'high', 'text' => $m.' payment'.($m === 1 ? '' : 's').' do not match between the gateway and CVTara. See Orders > Reconciliation.'];
        }
        $spend = Schema::hasTable('growth_campaign_plans') ? (int) DB::table('growth_campaign_plans')->where('start_date', '>=', now()->subDays(30)->toDateString())->sum('cost_inr') : 0;
        if ($spend > 0 && ! Growth::metrics(now()->subDays(30), now())['buyers']) {
            $out[] = ['key' => 'spend', 'level' => 'medium', 'text' => '₹'.number_format($spend).' of campaign spend in 30 days and no paying customer. Review the campaigns.'];
        }
        if ($log) {
            foreach (array_filter($out, fn ($a) => $a['key'] !== 'ai') as $a) { // the AI one is written by AiGateway::watch
                ErrorLog::record('warning', 'alert', $a['text'], 'alert:'.$a['key'].':'.now()->toDateString());
            }
        }

        return $out;
    }

    /** docs/METRICS.md, made from DEFS. */
    public static function markdown(): string
    {
        $md = "# CVTara metrics\n\nGenerated from `app/Services/Metrics.php` (`php artisan cvtara:metrics-doc`). Do not edit by hand.\n\n"
            ."Periods run in India time. Money is in rupees; refunds and taxes are as stated per line. A number shown as \"not tracked\" is unknown, not zero.\n\n| Metric | Unit | Definition |\n|---|---|---|\n";
        foreach (self::DEFS as $k => [$label, $unit, $def]) {
            $md .= '| '.$label.' (`'.$k.'`) | '.$unit.' | '.$def." |\n";
        }

        return $md;
    }
}
