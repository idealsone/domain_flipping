<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The owner's business numbers for Tara's owner mode and the "Business assistant" page. Every figure is counted by the
 * database from the real tables (orders, users, usage, AI usage, agents, errors, support), with the period, the source and
 * when it was worked out. Nothing here asks a model to do arithmetic, and a number the site cannot measure is said to be
 * unavailable rather than guessed. Revenue follows the same rule as Growth and Reports: live gateway money and manual
 * payments with an amount received; demo, test, ₹0 grants, referral rewards and unpaid checkouts never count.
 */
class TaraBusiness
{
    public const PERIODS = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'custom'];

    /** India-time period with the previous period of the same length, for comparisons. */
    public static function period(?string $p, ?string $from = null, ?string $to = null): array
    {
        $now = now()->tz(Growth::TZ);
        $p = in_array($p, self::PERIODS, true) ? $p : 'today';
        $date = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? Carbon::parse($d, Growth::TZ) : null;
        [$a, $b, $label] = match ($p) {
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->startOfDay(), 'Yesterday'],
            'last_7_days' => [$now->copy()->subDays(6)->startOfDay(), $now->copy(), 'Last 7 days'],
            'last_30_days' => [$now->copy()->subDays(29)->startOfDay(), $now->copy(), 'Last 30 days'],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy(), $now->format('F Y').' so far'],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->startOfMonth(), $now->copy()->subMonthNoOverflow()->format('F Y')],
            'custom' => ($date($from) && $date($to) && $date($from)->lte($date($to)))
                ? [$date($from)->startOfDay(), min($date($to)->addDay()->startOfDay(), $now->copy()), $date($from)->format('j M Y').' to '.$date($to)->format('j M Y')]
                : [$now->copy()->startOfDay(), $now->copy(), 'Today'],
            default => [$now->copy()->startOfDay(), $now->copy(), 'Today'],
        };
        if ($p === 'custom' && $a->diffInDays($b) > 1100) {
            $a = $b->copy()->subDays(1100);
        }
        // Compared with the same stretch one step back: today so far with yesterday up to the same time, this month so far with
        // the same days of last month, last month with the month before, and a custom range with the days just before it.
        $back = fn (Carbon $c) => match ($p) {
            'today', 'yesterday' => $c->copy()->subDay(),
            'last_7_days' => $c->copy()->subDays(7),
            'last_30_days' => $c->copy()->subDays(30),
            'this_month', 'last_month' => $c->copy()->subMonthNoOverflow(),
            default => $c->copy()->subSeconds(max(60, (int) $a->diffInSeconds($b))),
        };
        $pa = $back($a);
        $pb = $p === 'last_month' ? $a->copy() : $back($b);

        return ['key' => $p, 'label' => $label, 'from' => $a, 'to' => $b, 'prevFrom' => $pa, 'prevTo' => $pb,
            'text' => $a->format('j M Y, g:i A').' to '.$b->format('j M Y, g:i A').' India time'];
    }

    private static function stamp(array $per, string $source): array
    {
        return ['period' => $per['label'], 'range' => $per['text'], 'comparedWith' => $per['prevFrom']->format('j M Y, g:i A').' to '.$per['prevTo']->format('j M Y, g:i A'),
            'source' => $source, 'workedOutAt' => now()->tz(Growth::TZ)->format('j M Y, g:i:s A').' India time'];
    }

    private static function utc(Carbon $c): Carbon
    {
        return $c->copy()->utc();
    }

    private static function inr(int $paise): string
    {
        return '₹'.number_format($paise / 100, $paise % 100 ? 2 : 0);
    }

    private static function change($now, $before): ?string
    {
        if ($now === null || $before === null) {
            return null;
        }
        if ((float) $before === 0.0) {
            return (float) $now === 0.0 ? 'no change' : 'up from 0';
        }
        $pct = round(((float) $now - (float) $before) / (float) $before * 100, 1);

        return ($pct > 0 ? '+' : '').$pct.'%';
    }

    public static function planName(string $k): string
    {
        $p = Settings::plans()[$k] ?? Settings::plans()[Settings::planAlias($k)] ?? null;

        return $p ? (string) ($p['name'] ?? $k).(isset($p['price']) && $p['price'] ? ' (₹'.$p['price'].')' : '') : $k;
    }

    /** Customers, not owner accounts or erased placeholders. */
    private static function customers()
    {
        return DB::table('users')->where('is_admin', false)->where('email', '!=', AccountEraser::PLACEHOLDER);
    }

    private static function paidLive()
    {
        $q = DB::table('purchases')->where('amount_paise', '>', 0)->where('provider', '!=', 'referral_reward');

        return config('app.preview') ? $q : $q->where('is_live', true)->where('provider', '!=', 'demo');
    }

    public static function revenue(array $per): array
    {
        $m = Growth::metrics($per['from'], $per['to']);
        $p = Growth::metrics($per['prevFrom'], $per['prevTo']);
        [$a, $b] = [self::utc($per['from']), self::utc($per['to'])->addSecond()];
        $in = fn ($q, $col = 'created_at') => $q->where($col, '>=', $a)->where($col, '<', $b);
        $unpaid = $in(Settings::sinceStart(DB::table('purchases'), 'created_at')->where('status', 'created')->where('provider', '!=', 'referral_reward')->where('amount_paise', '>', 0));
        $refunds = $in(DB::table('refund_requests')->where('status', 'processed'), 'processed_at');
        $failed = $in(DB::table('webhook_events')->where(fn ($w) => $w->where('type', 'like', '%fail%')->orWhere('id', 'like', '%fail%')));
        $comp = $in(DB::table('purchases')->where('provider', 'manual')->where('amount_paise', 0));
        $plans = collect($m['plans'])->map(fn ($v, $k) => ['package' => $k === 'add-ons' ? 'Add-ons' : self::planName($k), 'orders' => $v['count'], 'revenue' => self::inr($v['paise'])])->sortByDesc('orders')->values()->all();

        return [
            'ok' => true,
            'successfulTransactions' => $m['purchases'], 'previous' => $p['purchases'], 'transactionsChange' => self::change($m['purchases'], $p['purchases']),
            'grossRevenue' => self::inr($m['grossPaise']), 'refundedOrReversed' => self::inr($m['refundedPaise']), 'netRevenue' => self::inr($m['netPaise']),
            'previousNetRevenue' => self::inr($p['netPaise']), 'netRevenueChange' => self::change($m['netPaise'], $p['netPaise']),
            'payingCustomers' => $m['buyers'], 'newPayingCustomers' => $m['newCustomers'], 'repeatPurchases' => $m['repeatPurchases'],
            'averageRevenuePerPayingCustomer' => $m['buyers'] ? self::inr(intdiv($m['netPaise'], $m['buyers'])) : null,
            'byPackage' => $plans,
            'checkoutsOpenedNotPaid' => (clone $unpaid)->count(), 'checkoutsOpenedNotPaidValue' => self::inr((int) (clone $unpaid)->sum('amount_paise')),
            'failedPaymentNoticesFromGateway' => $failed->count(),
            'refundsProcessed' => (clone $refunds)->count(), 'refundsProcessedValue' => self::inr((int) (clone $refunds)->sum('amount_paise')),
            'refundRequestsWaiting' => DB::table('refund_requests')->where('status', 'requested')->count(),
            'complimentaryGrantsNotCounted' => $comp->count(),
            'rule' => 'Counts live gateway payments and manual payments with money received, by payment time. Not counted: unpaid checkouts, failed payments, test or demo payments, ₹0 complimentary grants and referral rewards.'
                .(Settings::statsSince() ? ' Counting starts '.Settings::statsSince()->tz(Growth::TZ)->format('j M Y, g:i A').' (the owner\'s "count from now" setting).' : ''),
            'link' => '/admin.html?tab=orders',
        ] + self::stamp($per, 'CVTara orders, refunds and payment-gateway notices');
    }

    public static function customersReport(array $per): array
    {
        [$a, $b] = [self::utc($per['from']), self::utc($per['to'])->addSecond()];
        [$pa, $pb] = [self::utc($per['prevFrom']), self::utc($per['prevTo'])];
        $total = self::customers()->count();
        $new = self::customers()->where('created_at', '>=', $a)->where('created_at', '<', $b)->count();
        $newPrev = self::customers()->where('created_at', '>=', $pa)->where('created_at', '<', $pb)->count();
        $active = self::paidLive()->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now());
        $paidNow = (clone $active)->distinct()->count('owner_id');
        $everPaid = self::paidLive()->whereIn('status', ['paid', 'partially_refunded', 'refunded', 'captured', 'disputed'])->whereNotNull('purchased_at')->distinct()->count('owner_id');
        $expired = self::customers()->whereIn('id', self::paidLive()->whereNotNull('purchased_at')->select('owner_id'))->whereNotIn('id', (clone $active)->select('owner_id'))->count();
        $compActive = DB::table('purchases')->where('provider', 'manual')->where('amount_paise', 0)->where('access_until', '>', now())->distinct()->count('owner_id');
        $byPlan = (clone $active)->where('plan', 'not like', 'addon-%')->select('plan', DB::raw('count(distinct owner_id) as n'))->groupBy('plan')->pluck('n', 'plan')
            ->map(fn ($n, $k) => ['package' => self::planName($k), 'customers' => (int) $n])->values()->all();
        $soon = DB::table('purchases as p')->join('users as u', 'u.id', '=', 'p.owner_id')->whereIn('p.status', ['paid', 'partially_refunded'])->where('p.access_until', '>', now())->where('p.access_until', '<', now()->addDays(7))
            ->where('p.plan', 'not like', 'addon-%')->orderBy('p.access_until')->limit(20)->get(['u.id', 'u.email', 'p.plan', 'p.access_until', 'p.amount_paise'])
            ->map(fn ($r) => ['userId' => $r->id, 'email' => $r->email, 'package' => self::planName($r->plan), 'endsOn' => Carbon::parse($r->access_until)->tz(Growth::TZ)->format('j M Y'), 'complimentary' => (int) $r->amount_paise === 0])->all();
        $busy = DB::table('activity_events as e')->join('users as u', 'u.id', '=', 'e.owner_id')->where('u.is_admin', false)->where('e.created_at', '>=', $a)->where('e.created_at', '<', $b)
            ->select('u.id', 'u.email', DB::raw('count(*) as n'))->groupBy('u.id', 'u.email')->orderByDesc('n')->limit(10)->get()->map(fn ($r) => ['userId' => $r->id, 'email' => $r->email, 'actions' => (int) $r->n])->all();

        return [
            'ok' => true, 'registeredCustomers' => $total, 'newRegistrations' => $new, 'previousNewRegistrations' => $newPrev, 'registrationsChange' => self::change($new, $newPrev),
            'verifiedEmailInPeriod' => self::customers()->where('email_verified_at', '>=', $a)->where('email_verified_at', '<', $b)->count(),
            'activePaidCustomers' => $paidNow, 'activeComplimentary' => $compActive, 'expiredCustomers' => $expired, 'neverPurchased' => max(0, $total - $everPaid), 'freeAccountsNow' => max(0, $total - $paidNow - $compActive),
            'everPaid' => $everPaid, 'freeToPaidConversion' => $total ? round($everPaid * 100 / $total, 1).'%' : null,
            'activePackageMix' => $byPlan, 'endingWithin7Days' => $soon, 'mostActiveInPeriod' => $busy,
            'note' => 'Customers excludes owner accounts. "Most active" counts actions recorded in the workspace (resumes, checks, downloads and so on).',
            'link' => '/admin.html?tab=people',
        ] + self::stamp($per, 'CVTara users, orders and workspace activity');
    }

    public static function findCustomer(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return ['ok' => false, 'error' => 'Give at least 2 letters of an email address or name.'];
        }
        $rows = self::customers()->where(fn ($w) => ctype_digit($q) ? $w->where('id', (int) $q) : $w->where('email', 'like', '%'.addcslashes($q, '%_').'%')->orWhere('name', 'like', '%'.addcslashes($q, '%_').'%'))
            ->orderByDesc('created_at')->limit(5)->get(['id', 'name', 'email', 'created_at', 'email_verified_at', 'blocked']);

        return ['ok' => true, 'matches' => $rows->map(function ($u) {
            $acc = Settings::access($u->id);
            $orders = DB::table('purchases')->where('owner_id', $u->id)->orderByDesc('created_at')->limit(8)->get(['id', 'plan', 'amount_paise', 'status', 'provider', 'created_at', 'access_until', 'is_live']);
            $cr = Credits::status($u->id);

            return ['userId' => $u->id, 'name' => $u->name, 'email' => $u->email, 'joined' => Carbon::parse($u->created_at)->tz(Growth::TZ)->format('j M Y'), 'emailVerified' => (bool) $u->email_verified_at, 'blocked' => (bool) $u->blocked,
                'currentPackage' => self::planName($acc['plan']), 'accessUntil' => $acc['accessUntil'] ? Carbon::parse($acc['accessUntil'])->tz(Growth::TZ)->format('j M Y') : null,
                'credits' => $cr,
                'orders' => $orders->map(fn ($o) => ['orderId' => $o->id, 'package' => self::planName($o->plan), 'amount' => self::inr((int) $o->amount_paise), 'status' => $o->status, 'source' => $o->provider === 'manual' ? ((int) $o->amount_paise ? 'manual payment' : 'complimentary grant') : $o->provider, 'realMoney' => (bool) $o->is_live, 'date' => Carbon::parse($o->created_at)->tz(Growth::TZ)->format('j M Y'), 'activeUntil' => $o->access_until && now()->lt($o->access_until) ? Carbon::parse($o->access_until)->tz(Growth::TZ)->format('j M Y') : null])->all(),
                'resumes' => DB::table('resume_drafts')->where('owner_id', $u->id)->count(), 'atsChecks' => DB::table('activity_events')->where('owner_id', $u->id)->where(fn ($w) => $w->where('event', 'like', '%ats%')->orWhere('event', 'like', '%compared%'))->count(),
                'lastActive' => ($l = DB::table('activity_events')->where('owner_id', $u->id)->max('created_at')) ? Carbon::parse($l)->tz(Growth::TZ)->format('j M Y, g:i A') : null,
                'openSupportTickets' => DB::table('support_reports')->where('owner_id', $u->id)->whereNotIn('status', ['resolved', 'closed'])->count(),
                'link' => '/admin.html?tab=people'];
        })->all(), 'source' => 'CVTara users, orders, credits and activity', 'workedOutAt' => now()->tz(Growth::TZ)->format('j M Y, g:i A')];
    }

    public static function creditUsage(array $per, int $limit = 20): array
    {
        [$a, $b] = [self::utc($per['from']), self::utc($per['to'])->addSecond()];
        $q = fn () => DB::table('product_usage as u')->where('u.created_at', '>=', $a)->where('u.created_at', '<', $b);
        $byAction = $q()->select('u.action', DB::raw('count(*) as n'))->groupBy('u.action')->orderByDesc('n')->pluck('n', 'action')->map(fn ($n) => (int) $n)->all();
        $users = $q()->join('users as x', 'x.id', '=', 'u.owner_id')->select('x.id', 'x.email', DB::raw('count(*) as n'))->groupBy('x.id', 'x.email')->orderByDesc('n')->limit(max(1, min(100, $limit)))->get();
        $people = (int) $q()->distinct()->count('u.owner_id');
        $total = array_sum($byAction);
        $avg = $people ? $total / $people : 0;
        $prev = (int) DB::table('product_usage')->where('created_at', '>=', self::utc($per['prevFrom']))->where('created_at', '<', self::utc($per['prevTo']))->count();

        return ['ok' => true, 'creditsUsed' => $total, 'previous' => $prev, 'change' => self::change($total, $prev), 'customersWhoUsedCredits' => $people, 'averagePerCustomer' => round($avg, 1), 'byKind' => $byAction,
            'topCustomers' => $users->map(fn ($r) => ['userId' => $r->id, 'email' => $r->email, 'creditsUsed' => (int) $r->n, 'unusuallyHigh' => $avg > 0 && $r->n >= max(5, 3 * $avg), 'current' => self::planName(Settings::access((int) $r->id)['plan'])])->all(),
            'activePaidPacks' => self::paidLive()->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now())->count(),
            'note' => 'A credit is used only by finished work (a clean download, a new resume or letter slot, a primary resume). ATS checks have their own daily allowance and are not credits. Credits left per customer are shown by find_customer. Credits that expired unused are not recorded separately, so they cannot be counted.',
        ] + self::stamp($per, 'CVTara credit use records (product_usage)');
    }

    /** What each AI request was for, in the owner's eight groups. */
    public static function aiGroup(string $purpose): string
    {
        return match (true) {
            $purpose === 'seo' => 'SEO agent',
            str_starts_with($purpose, 'growth') || str_starts_with($purpose, 'marketing') => 'Marketing & growth agent',
            $purpose === 'customer:ats_tailor' => 'Resume improvement (ATS tailoring)',
            $purpose === 'customer:writing_help' => 'Resume generation (writing help)',
            $purpose === 'customer:expert_review' => 'Resume improvement (Expert Review)',
            $purpose === 'customer:cover_letter' => 'Cover letter generation',
            $purpose === 'customer:job_match' => 'Job description analysis and matching',
            $purpose === 'customer:tara_chat' => 'Tara chat (customers)',
            $purpose === TaraOwner::PURPOSE => 'Business assistant (owner)',
            default => 'Other background operations',
        };
    }

    public static function aiCosts(array $per): array
    {
        [$a, $b] = [self::utc($per['from']), self::utc($per['to'])->addSecond()];
        $g = Growth::settings();
        $rate = (float) $g['usdInr'];
        $rows = DB::table('ai_usage')->where('created_at', '>=', $a)->where('created_at', '<', $b)->select('purpose', 'model', DB::raw('count(*) as calls'), DB::raw('sum(input_tokens) as i'), DB::raw('sum(output_tokens) as o'), DB::raw('sum(cost_paise) as p'))->groupBy('purpose', 'model')->get();
        $prev = (int) DB::table('ai_usage')->where('created_at', '>=', self::utc($per['prevFrom']))->where('created_at', '<', self::utc($per['prevTo']))->sum('cost_paise');
        $money = fn (int $paise) => ['inr' => self::inr($paise), 'usd' => '$'.number_format($paise / 100 / max(0.01, $rate), 2)];
        $groups = [];
        foreach ($rows as $r) {
            $k = self::aiGroup((string) $r->purpose);
            $groups[$k] ??= ['calls' => 0, 'inputTokens' => 0, 'outputTokens' => 0, 'paise' => 0];
            $groups[$k]['calls'] += (int) $r->calls;
            $groups[$k]['inputTokens'] += (int) $r->i;
            $groups[$k]['outputTokens'] += (int) $r->o;
            $groups[$k]['paise'] += (int) $r->p;
        }
        uasort($groups, fn ($x, $y) => $y['paise'] <=> $x['paise']);
        $models = $rows->groupBy('model')->map(fn ($g2, $k) => ['model' => $k ?: 'not recorded', 'calls' => (int) $g2->sum('calls'), 'paise' => (int) $g2->sum('p')])->sortByDesc('paise')->values();
        $total = (int) $rows->sum('p');
        // Per-customer cost is known only for requests that carry the customer's account (recorded from 3.32.0 on).
        $tiers = [];
        if (\Illuminate\Support\Facades\Schema::hasColumn('ai_usage', 'owner_id')) {
            $per2 = DB::table('ai_usage')->where('created_at', '>=', $a)->where('created_at', '<', $b)->whereNotNull('owner_id')->select('owner_id', DB::raw('sum(cost_paise) as p'))->groupBy('owner_id')->pluck('p', 'owner_id');
            $tier = [];
            foreach ($per2 as $uid => $p) {
                $t = Settings::access((int) $uid)['plan'];
                $tier[$t] ??= ['customers' => 0, 'paise' => 0];
                $tier[$t]['customers']++;
                $tier[$t]['paise'] += (int) $p;
            }
            foreach ($tier as $t => $v) {
                $tiers[] = ['package' => self::planName($t), 'customersWithAiUse' => $v['customers'], 'total' => self::inr($v['paise']), 'averagePerCustomer' => self::inr(intdiv($v['paise'], max(1, $v['customers'])))];
            }
        }
        $top = collect($groups)->map(fn ($v, $k) => ['what' => $k, 'perCall' => $v['calls'] ? $v['paise'] / $v['calls'] : 0, 'outPerCall' => $v['calls'] ? intdiv($v['outputTokens'], $v['calls']) : 0, 'inPerCall' => $v['calls'] ? intdiv($v['inputTokens'], $v['calls']) : 0])->sortByDesc('perCall')->first();

        return [
            'ok' => true, 'total' => $money($total), 'previousTotal' => $money($prev), 'change' => self::change($total, $prev),
            'requests' => (int) $rows->sum('calls'), 'inputTokens' => (int) $rows->sum('i'), 'outputTokens' => (int) $rows->sum('o'),
            'byGroup' => collect($groups)->map(fn ($v, $k) => ['group' => $k, 'requests' => $v['calls'], 'inputTokens' => $v['inputTokens'], 'outputTokens' => $v['outputTokens']] + $money($v['paise']) + ['perRequestInr' => self::inr($v['calls'] ? intdiv($v['paise'], $v['calls']) : 0)])->values()->all(),
            'byModel' => $models->map(fn ($m) => ['model' => $m['model'], 'requests' => $m['calls']] + $money($m['paise']))->all(),
            'averageByPackage' => $tiers ?: 'Not available yet: per-customer AI cost is recorded from version 3.32.0 on.',
            'mostExpensivePerRequest' => $top ? ['group' => $top['what'], 'averageInputTokens' => $top['inPerCall'], 'averageOutputTokens' => $top['outPerCall']] : null,
            'atsScanner' => 'ATS checks are scored by CVTara\'s own rules, not by the AI service, so they cost ₹0 in AI fees.',
            'exchangeRate' => '1 USD = ₹'.$g['usdInr'].' (Growth settings)', 'prices' => '$'.$g['inputUsdPerM'].' per million input tokens, $'.$g['outputUsdPerM'].' per million output tokens (Growth settings; the provider\'s invoice is final)',
            'monthlyCap' => $g['budgetInr'] ? '₹'.number_format((int) $g['budgetInr']).' a month for growth and owner tasks (customer features are never stopped)' : 'No monthly cap set',
            'thisMonth' => self::inr(Growth::budget()['spentPaise']),
            'link' => '/admin.html?tab=reports',
        ] + self::stamp($per, 'CVTara AI usage log (every request is recorded with its tokens)');
    }

    public static function seoAgent(): array
    {
        $s = SeoAgent::settings();
        $sum = SeoAgent::summary();
        $run = (array) Settings::get('seoAgentRun', []);
        $now = now()->tz(Growth::TZ);
        $next = $now->copy();
        if ($run && ($run['day'] ?? '') === $now->toDateString() || $now->hour >= 11) {
            $next = $next->addDay();
        }
        while ($next->isSunday()) {
            $next->addDay();
        }
        $q = collect(SeoAgent::tracked('query'))->filter(fn ($r) => $r['positionChange'] !== null && ! $r['brand']);
        $pages = collect(SeoAgent::tracked('page'));
        $week = now()->subDays(7);
        $decided = DB::table('seo_proposals')->where('decided_at', '>=', $week)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $errors = DB::table('error_logs')->where('last_seen', '>=', now()->subDays(14))->where(fn ($w) => $w->where('source', 'seo')->orWhere('message', 'like', 'SEO agent%')->orWhere('message', 'like', '%Search Console%'))->orderByDesc('last_seen')->limit(5)->get(['level', 'message', 'occurrences', 'last_seen']);
        $spend = (int) DB::table('ai_usage')->where('purpose', 'seo')->where('created_at', '>=', now()->tz(Growth::TZ)->startOfMonth()->utc())->sum('cost_paise');

        return [
            'ok' => true, 'status' => ! $s['auto'] ? 'Paused (automatic runs are off)' : (! $sum['ready'] ? 'Not connected to Search Console' : 'On'),
            'lastRun' => isset($run['at']) ? Carbon::parse($run['at'])->tz(Growth::TZ)->format('j M Y, g:i A') : 'Never', 'nextRun' => $s['auto'] && $sum['ready'] ? $next->format('D j M').' after 11:00 AM (Mon–Sat)' : 'Not scheduled',
            'searchConsoleData' => ['clicks' => $sum['clicks'], 'impressions' => $sum['impressions'], 'dates' => $sum['startDate'] ? $sum['startDate'].' to '.$sum['endDate'] : null, 'lastSynced' => $sum['updatedAt']],
            'proposalsWaitingForApproval' => $sum['pending'], 'changesLive' => $sum['live'], 'decidedLast7Days' => $decided,
            'keywordsImproved' => $q->sortByDesc('positionChange')->filter(fn ($r) => $r['positionChange'] > 0)->take(8)->map(fn ($r) => ['keyword' => $r['key'], 'position' => round($r['position'], 1), 'placesGained' => $r['positionChange'], 'clicks' => $r['clicks']])->values()->all(),
            'keywordsDropped' => $q->sortBy('positionChange')->filter(fn ($r) => $r['positionChange'] < 0)->take(8)->map(fn ($r) => ['keyword' => $r['key'], 'position' => round($r['position'], 1), 'placesLost' => -$r['positionChange'], 'clicks' => $r['clicks']])->values()->all(),
            'topPagesByClicks' => $pages->filter(fn ($r) => ($r['clicks'] ?? 0) > 0)->sortByDesc('clicks')->take(8)->map(fn ($r) => ['page' => $r['key'], 'clicks' => $r['clicks'], 'impressions' => $r['impressions']])->values()->all(),
            'sitemapPagesWithNoImpressions' => $pages->filter(fn ($r) => ($r['impressions'] ?? 0) === 0)->count(),
            'nearPageOne' => array_slice(array_map(fn ($r) => array_intersect_key((array) $r, array_flip(['query', 'key', 'position', 'impressions', 'page'])), $sum['striking']), 0, 5),
            'errorsLast14Days' => $errors->map(fn ($e) => ['level' => $e->level, 'message' => mb_strimwidth($e->message, 0, 200, '…'), 'times' => (int) $e->occurrences, 'lastSeen' => $e->last_seen])->all(),
            'aiSpendThisMonth' => self::inr($spend),
            'proposalsWaiting' => DB::table('seo_proposals')->where('status', 'pending')->orderByDesc('impressions')->limit(10)->get(['id', 'kind', 'path', 'query', 'reason', 'impressions', 'position', 'created_at'])
                ->map(fn ($x) => ['proposalId' => $x->id, 'kind' => $x->kind, 'page' => $x->path, 'keyword' => $x->query, 'why' => mb_strimwidth((string) $x->reason, 0, 200, '…'), 'impressions' => (int) $x->impressions, 'position' => round((float) $x->position, 1), 'proposed' => substr((string) $x->created_at, 0, 10)])->all(),
            'agentReport' => self::agentStatus(SeoAgent::class),
            'notMeasured' => 'Registrations and paid orders that came from Google search are not tracked per keyword. Domain Authority is a third-party score and is not proof of Google ranking; it is not used here.',
            'link' => '/admin.html?tab=growth',
            'source' => 'Google Search Console (synced data), CVTara SEO proposals, error log and AI usage', 'workedOutAt' => now()->tz(Growth::TZ)->format('j M Y, g:i A'),
        ];
    }

    public static function growthAgent(): array
    {
        $g = Growth::settings();
        $now = now()->tz(Growth::TZ);
        $month = Growth::month();
        $reports = DB::table('growth_reports')->orderByDesc('created_at')->limit(3)->get(['day', 'slot', 'band', 'body', 'created_at']);
        $next = ! $g['reports'] ? 'Reports are switched off' : ($g['reportEvery'] === 'weekly' ? $now->copy()->next(Carbon::SUNDAY)->format('D j M').' at '.Growth::WEEKLY_HOUR.':00 AM (weekly report)' : 'Today or the next working day at 2 PM and 7 PM');
        $camps = DB::table('email_campaigns')->orderByDesc('created_at')->limit(8)->get(['id', 'name', 'status', 'audience', 'started_at', 'finished_at', 'created_at']);
        $fu = Growth::followUps();
        $rows = $camps->map(function ($c) {
            $st = Campaigns::stats($c->id);
            $from = $c->started_at ? Carbon::parse($c->started_at) : null;
            $buyers = $from ? self::paidLive()->whereIn('status', ['paid', 'partially_refunded', 'refunded'])->whereNotNull('purchased_at')->where('purchased_at', '>=', $from)->where('purchased_at', '<', $from->copy()->addDays(14))
                ->whereIn('owner_id', DB::table('campaign_recipients')->where('campaign_id', $c->id)->whereNotNull('owner_id')->select('owner_id')) : null;

            return ['campaign' => $c->name, 'status' => $c->status, 'audience' => $c->audience, 'started' => $from?->tz(Growth::TZ)->format('j M Y'), 'recipients' => $st['recipients'], 'sent' => $st['sent'], 'failed' => $st['failed'], 'unsubscribed' => $st['unsubscribed'],
                'boughtWithin14DaysOfSending' => $buyers ? (clone $buyers)->distinct()->count('owner_id') : 0, 'revenueWithin14Days' => self::inr($buyers ? (int) (clone $buyers)->sum(DB::raw('amount_paise - refunded_paise')) : 0)];
        })->all();

        return [
            'ok' => true, 'reports' => $g['reports'] ? 'On ('.$g['reportEvery'].')' : 'Off', 'nextReport' => $next,
            'latestReports' => $reports->map(fn ($r) => ['day' => (string) $r->day, 'report' => $r->slot, 'band' => $r->band, 'text' => mb_strimwidth((string) $r->body, 0, 700, '…')])->all(),
            'monthAgainstPlan' => ['month' => $month['label'], 'purchases' => $month['metrics']['purchases'], 'target' => $month['target'], 'pace' => $month['pace'], 'attainment' => $month['attainment'] !== null ? $month['attainment'].'%' : null, 'band' => $month['band'], 'perDayNeeded' => $month['perDayNeeded']],
            'suggestedActions' => Growth::actions($month, Growth::metrics($now->copy()->startOfDay(), $now)),
            'campaigns' => $rows, 'campaignsWaitingOrScheduled' => DB::table('email_campaigns')->whereIn('status', ['draft', 'scheduled', 'paused'])->count(),
            'followUpsLast7Days' => $fu,
            'marketingMailSentThisMonth' => $month['metrics']['marketingSent'], 'outreachSentThisMonth' => $month['metrics']['outreachSent'], 'unsubscribesThisMonth' => $month['metrics']['unsubscribes'],
            'aiSpendThisMonth' => self::inr((int) DB::table('ai_usage')->where('created_at', '>=', $now->copy()->startOfMonth()->utc())->where(fn ($w) => $w->where('purpose', 'like', 'growth%')->orWhere('purpose', 'like', 'marketing%'))->sum('cost_paise')),
            'agentReport' => self::agentStatus(Growth::class),
            'adSpend' => 'Not connected (no ad account is linked), so ad spend is unknown.',
            'notMeasured' => 'Email opens and clicks are not tracked for campaigns (no tracking pixels or tracked links), so they are unknown. "Bought within 14 days of sending" counts recipients who paid in the 14 days after the campaign started: it is a time window, not proof that the email caused the sale.',
            'activityIsNotRevenue' => 'Reports written and emails sent are activity. Only paid orders count as results.',
            'link' => '/admin.html?tab=growth', 'source' => 'Growth reports, email campaigns, mail queue, orders and AI usage', 'workedOutAt' => $now->format('j M Y, g:i A'),
        ];
    }

    public static function siteHealth(): array
    {
        $h = Health::snapshot();
        $labels = ['database' => 'Database not answering', 'storageWritable' => 'Server storage not writable', 'https' => 'Site address is not https', 'domain' => 'Site address is not the main domain', 'backupFresh' => 'No backup in the last 36 hours',
            'previewOff' => 'Preview mode is on', 'privateFolder' => 'App files are inside the public folder', 'scheduler' => 'Scheduled jobs (cron) have not run in 10 minutes', 'emailConfigured' => 'Email sending is not set up', 'emailSenderAligned' => 'Sender address is not on the site\'s domain',
            'emailPostalAddress' => 'Postal address missing from emails', 'mailQueueMoving' => 'Emails are stuck in the queue', 'paymentsConfigured' => 'No payment gateway is set up', 'debugOff' => 'Debug mode is on'];
        $sev = ['database' => 1, 'storageWritable' => 1, 'paymentsConfigured' => 1, 'scheduler' => 2, 'mailQueueMoving' => 2, 'https' => 2, 'debugOff' => 2, 'previewOff' => 2, 'privateFolder' => 2, 'backupFresh' => 3, 'emailConfigured' => 3, 'emailSenderAligned' => 4, 'emailPostalAddress' => 4, 'domain' => 4];
        $issues = [];
        foreach ($h['problems'] as $p) {
            $issues[] = ['severity' => ['', 'critical', 'high', 'medium', 'low'][$sev[$p] ?? 3], 'issue' => $labels[$p] ?? $p, 'where' => '/admin.html?tab=setup'];
        }
        $errs = DB::table('error_logs')->whereNull('resolved_at')->where('last_seen', '>=', now()->subDays(7))->orderByRaw("CASE WHEN source = 'payment' THEN 0 WHEN level = 'error' THEN 1 ELSE 2 END")->orderByDesc('occurrences')->limit(10)
            ->get(['level', 'source', 'message', 'occurrences', 'last_seen', 'url']);
        foreach ($errs as $e) {
            $issues[] = ['severity' => $e->source === 'payment' ? 'high' : ($e->level === 'error' ? ($e->occurrences >= 20 ? 'high' : 'medium') : 'low'), 'issue' => '['.$e->source.'] '.mb_strimwidth($e->message, 0, 220, '…'), 'times' => (int) $e->occurrences, 'lastSeen' => $e->last_seen, 'page' => $e->url, 'where' => '/admin.html?tab=errors'];
        }
        if ($h['metrics']['mailFailed24h'] > 0) {
            $issues[] = ['severity' => $h['metrics']['mailFailed24h'] >= 10 ? 'high' : 'medium', 'issue' => $h['metrics']['mailFailed24h'].' emails failed to send in the last 24 hours', 'where' => '/admin.html?tab=reports'];
        }
        if ($h['metrics']['diskFreePct'] !== null && $h['metrics']['diskFreePct'] < 15) {
            $issues[] = ['severity' => $h['metrics']['diskFreePct'] < 5 ? 'critical' : 'high', 'issue' => 'Server disk is '.(100 - $h['metrics']['diskFreePct']).'% full', 'where' => '/admin.html?tab=setup'];
        }
        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($issues, fn ($x, $y) => $rank[$x['severity']] <=> $rank[$y['severity']]);

        return [
            'ok' => true, 'overall' => $h['status'], 'checkedAt' => Carbon::parse($h['at'])->tz(Growth::TZ)->format('j M Y, g:i:s A'),
            'issuesByPriority' => $issues ?: 'No open problems found.',
            'counts' => ['unresolvedErrors7Days' => DB::table('error_logs')->whereNull('resolved_at')->where('last_seen', '>=', now()->subDays(7))->count(), 'serverErrors24h' => $h['metrics']['errors24h'], 'emailsFailed24h' => $h['metrics']['mailFailed24h'], 'emailsWaiting' => $h['metrics']['mailQueued'],
                'paymentProblems7Days' => DB::table('error_logs')->where('source', 'payment')->whereNull('resolved_at')->where('last_seen', '>=', now()->subDays(7))->count(), 'diskFreePct' => $h['metrics']['diskFreePct']],
            'scheduledJobsLastRan' => ($hb = Settings::get('cronHeartbeat', [])) && isset($hb['at']) ? Carbon::parse($hb['at'])->tz(Growth::TZ)->format('j M Y, g:i A') : 'unknown',
            'lastBackup' => ($b = Settings::get('lastBackup', [])) && isset($b['at']) ? Carbon::parse($b['at'])->tz(Growth::TZ)->format('j M Y, g:i A') : 'unknown',
            'pageSpeed' => 'The site does not store Core Web Vitals. Check PageSpeed Insights or Search Console › Core Web Vitals. Busy mode and page cache are under Speed & traffic.',
            'pushNotifications' => 'Removed by the owner\'s decision (email and on-site popups are used instead), so there is nothing to check.',
            'notMeasured' => 'Uptime from outside the server, broken links, duplicate pages and mobile usability are not checked by the site itself.',
            'source' => 'Live health checks on the server and the CVTara error log', 'workedOutAt' => now()->tz(Growth::TZ)->format('j M Y, g:i:s A'),
        ];
    }

    public static function referralsReviewsSupport(array $per): array
    {
        [$a, $b] = [self::utc($per['from']), self::utc($per['to'])->addSecond()];
        $in = fn ($q, $col = 'created_at') => $q->where($col, '>=', $a)->where($col, '<', $b);
        $com = $in(DB::table('referral_commissions'));
        $wd = DB::table('withdrawals')->select('status', DB::raw('count(*) as n'), DB::raw('sum(amount_paise) as p'))->groupBy('status')->get()->keyBy('status');
        $rev = DB::table('feedback')->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $dupes = (int) DB::table('feedback')->select('content')->groupBy('content')->havingRaw('count(*) > 1')->get()->count();
        $sup = DB::table('support_reports')->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $open = DB::table('support_reports')->whereNotIn('status', ['resolved', 'closed']);
        $areas = $in(DB::table('support_reports'))->select('area', 'kind', DB::raw('count(*) as n'))->groupBy('area', 'kind')->orderByDesc('n')->limit(8)->get()->map(fn ($r) => ['area' => $r->area, 'kind' => $r->kind, 'reports' => (int) $r->n])->all();
        $latest = (clone $open)->orderBy('created_at')->limit(8)->get(['id', 'email', 'area', 'kind', 'status', 'message', 'created_at'])
            ->map(fn ($r) => ['ticketId' => $r->id, 'email' => $r->email, 'area' => $r->area, 'kind' => $r->kind, 'status' => $r->status, 'waitingSince' => Carbon::parse($r->created_at)->tz(Growth::TZ)->format('j M, g:i A'), 'message' => mb_strimwidth((string) $r->message, 0, 160, '…')])->all();

        return [
            'ok' => true,
            'referrals' => ['rule' => 'Customers get a ₹50 wallet credit for a referral that buys a ₹299 or ₹599 pack (owner\'s rule). There are no cash withdrawals.',
                'commissionsInPeriod' => (clone $com)->count(), 'commissionValueInPeriod' => self::inr((int) (clone $com)->sum('amount_paise')), 'reversedInPeriod' => self::inr((int) (clone $com)->sum('reversed_paise')),
                'referralClicksInPeriod' => $in(DB::table('referral_clicks'))->count(), 'customersWithReferralCode' => DB::table('users')->whereNotNull('referral_code')->count(),
                'withdrawalsByStatus' => $wd->map(fn ($r) => ['count' => (int) $r->n, 'value' => self::inr((int) $r->p)])->all() ?: 'None', 'link' => '/admin.html?tab=referral'],
            'reviews' => ['byStatus' => $rev, 'duplicateTexts' => $dupes, 'waitingForModeration' => (int) ($rev['pending'] ?? 0), 'link' => '/admin.html?tab=desk'],
            'support' => ['byStatus' => $sup, 'open' => (clone $open)->count(), 'overdue' => (clone $open)->where('created_at', '<', now()->subHours(48))->count(), 'overdueRule' => 'open for more than 48 hours',
                'paymentIssuesOpen' => (clone $open)->where('area', 'payment')->count(), 'downloadOrDocumentIssuesOpen' => (clone $open)->whereIn('area', ['download', 'resume', 'letter', 'ats'])->count(),
                'commonInPeriod' => $areas, 'oldestOpen' => $latest, 'link' => '/admin.html?tab=feedback'],
        ] + self::stamp($per, 'Referral commissions, withdrawals, reviews and the support inbox');
    }

    public static function pendingApprovals(): array
    {
        $svc = DB::table('service_orders')->whereIn('status', ['not_started', 'in_review'])->count();

        return ['ok' => true,
            'refundRequests' => DB::table('refund_requests')->where('status', 'requested')->count(),
            'expertReviewAndProfileWork' => $svc,
            'seoChangesToApprove' => DB::table('seo_proposals')->where('status', 'pending')->count(),
            'reviewsToModerate' => DB::table('feedback')->where('status', 'pending')->count(),
            'supportNotAnswered' => DB::table('support_reports')->where('status', 'new')->count(),
            'withdrawalRequests' => DB::table('withdrawals')->where('status', 'requested')->count(),
            'campaignsPausedOrScheduled' => DB::table('email_campaigns')->whereIn('status', ['scheduled', 'paused'])->count(),
            'expertReviewsFailed' => DB::table('service_orders')->where('service', ExpertReview::SERVICE)->where('status', 'not_started')->where('review_attempts', '>=', ExpertReview::MAX_ATTEMPTS)->orderBy('created_at')->limit(10)->get(['id', 'owner_id', 'review_error', 'created_at'])
                ->map(fn ($o) => ['orderId' => $o->id, 'customer' => DB::table('users')->where('id', $o->owner_id)->value('email'), 'reason' => mb_strimwidth((string) $o->review_error, 0, 160, '…'), 'ordered' => substr((string) $o->created_at, 0, 16)])->all(),
            'tasksWaitingForYourConfirmation' => DB::table('tara_admin_actions')->where('status', 'pending')->where('created_at', '>=', now()->subMinutes(TaraOwner::ACTION_MINUTES))->count(),
            'links' => ['refunds' => '/admin.html?tab=business', 'seo' => '/admin.html?tab=growth', 'reviews' => '/admin.html?tab=desk', 'support' => '/admin.html?tab=feedback', 'campaigns' => '/admin.html?tab=campaigns'],
            'workedOutAt' => now()->tz(Growth::TZ)->format('j M Y, g:i A')];
    }

    /** The CEO briefing: every block above in one answer, kept 5 minutes so opening the page is instant. */
    public static function briefing(string $p = 'today', bool $fresh = false): array
    {
        $per = self::period($p);
        $build = function () use ($per) {
            $rev = self::revenue($per);
            $cus = self::customersReport($per);
            $ai = self::aiCosts($per);
            $seo = self::seoAgent();
            $gr = self::growthAgent();
            $h = self::siteHealth();
            $m = Growth::metrics($per['from'], $per['to']);
            $ct = TaraGuard::stats($per['from'], $per['to']);
            $critical = is_array($h['issuesByPriority']) ? array_values(array_filter($h['issuesByPriority'], fn ($i) => in_array($i['severity'], ['critical', 'high'], true))) : [];

            return [
                'ok' => true, 'period' => $per['label'], 'range' => $per['text'], 'comparedWith' => $rev['comparedWith'],
                'kpis' => [
                    ['label' => 'Net revenue', 'value' => $rev['netRevenue'], 'change' => $rev['netRevenueChange']],
                    ['label' => 'Paid orders', 'value' => $rev['successfulTransactions'], 'change' => $rev['transactionsChange']],
                    ['label' => 'Sign-ups', 'value' => $cus['newRegistrations'], 'change' => $cus['registrationsChange']],
                    ['label' => 'Free → paid (all time)', 'value' => $cus['freeToPaidConversion']],
                    ['label' => 'AI spending', 'value' => $ai['total']['inr'], 'change' => $ai['change']],
                    ['label' => 'Problems to fix', 'value' => count($critical)],
                ],
                'registrations' => $cus['newRegistrations'], 'verified' => $m['verified'], 'newPaidCustomers' => $rev['newPayingCustomers'], 'activePaidCustomers' => $cus['activePaidCustomers'],
                'byPackage' => $rev['byPackage'], 'refunds' => $rev['refundsProcessed'].' processed ('.$rev['refundsProcessedValue'].'), '.$rev['refundRequestsWaiting'].' waiting', 'failedPayments' => $rev['failedPaymentNoticesFromGateway'], 'unpaidCheckouts' => $rev['checkoutsOpenedNotPaid'],
                'seo' => ['status' => $seo['status'], 'clicks' => $seo['searchConsoleData']['clicks'], 'impressions' => $seo['searchConsoleData']['impressions'], 'dates' => $seo['searchConsoleData']['dates'], 'waiting' => $seo['proposalsWaitingForApproval'], 'lastRun' => $seo['lastRun']],
                'growth' => ['reports' => $gr['reports'], 'month' => $gr['monthAgainstPlan'], 'campaigns' => count($gr['campaigns']), 'nextReport' => $gr['nextReport']],
                'ai' => ['total' => $ai['total'], 'byGroup' => array_slice($ai['byGroup'], 0, 5), 'exchangeRate' => $ai['exchangeRate']],
                'customerTara' => array_intersect_key($ct, array_flip(['enabled', 'conversations', 'customerMessages', 'people', 'thumbsUp', 'thumbsDown', 'supportTicketsFromTara', 'offersShown', 'offersClicked', 'boughtWithin7DaysOfAnOffer', 'revenueWithin7DaysOfAnOffer', 'spend'])),
                'security' => ['events' => $ct['securityEvents'], 'serious' => $ct['seriousSecurityEvents'], 'byKind' => $ct['securityByKind'], 'customersWithRepeatedAttempts' => count($ct['customersWithRepeatedAttempts'])],
                'funnel' => self::funnel($per)['bottleneck'],
                'problems' => array_slice($critical, 0, 5), 'health' => $h['overall'],
                'pending' => self::pendingApprovals(),
                'nextActions' => array_slice($gr['suggestedActions'], 0, 3),
                'notConnected' => array_values(array_filter([$m['visitors'] === null ? 'Visitor numbers (Analytics is off in Integrations)' : null, $seo['searchConsoleData']['clicks'] === null ? 'Search Console clicks and impressions (not synced yet)' : null, 'Organic sign-ups and orders per keyword (not tracked)', 'Email opens and clicks (not tracked)', 'Ad spend (no ad account linked)'])),
                'workedOutAt' => now()->tz(Growth::TZ)->format('j M Y, g:i A').' India time',
            ];
        };

        if ($fresh) {
            Cache::put('tara-briefing:'.$p, $r = $build(), 300);

            return $r;
        }

        return Cache::remember('tara-briefing:'.$p, 300, $build);
    }

    /** Small numbers for the strip at the top of the page (60 seconds). */
    public static function strip(): array
    {
        return Cache::remember('tara-strip', 60, function () {
            $per = self::period('today');
            $m = Growth::metrics($per['from'], $per['to']);
            $ai = (int) DB::table('ai_usage')->where('created_at', '>=', self::utc($per['from']))->sum('cost_paise');
            $pend = self::pendingApprovals();

            return ['revenueToday' => self::inr($m['netPaise']), 'ordersToday' => $m['purchases'], 'signupsToday' => $m['signups'], 'aiToday' => self::inr($ai),
                'errors24h' => DB::table('error_logs')->whereNull('resolved_at')->where('level', 'error')->where('last_seen', '>=', now()->subDay())->count(),
                'waiting' => $pend['refundRequests'] + $pend['seoChangesToApprove'] + $pend['reviewsToModerate'] + $pend['supportNotAnswered'] + $pend['expertReviewAndProfileWork'],
                'at' => now()->tz(Growth::TZ)->format('g:i A')];
        });
    }

    /** Reports that hold customers' personal details: a few rows in the chat, the full file only from Reports & Export. */
    public const PERSONAL = ['users', 'commissions', 'withdrawals', 'reviews', 'support', 'mail', 'activity', 'orders'];

    /** What an agent reports about itself, when the agent offers it (SeoAgent::agentStatus / Growth::agentStatus). */
    private static function agentStatus(string $class): ?array
    {
        if (! method_exists($class, 'agentStatus')) {
            return null;
        }
        try {
            return (array) $class::agentStatus();
        } catch (\Throwable $e) {
            ErrorLog::exception($e);

            return ['error' => 'The agent could not report its status; see the Error Log.'];
        }
    }

    /** Customer Tara for a period: chats, satisfaction, offers and what followed, and security events. */
    public static function customerTara(array $per): array
    {
        return ['ok' => true] + TaraGuard::stats($per['from'], $per['to']) + ['link' => '/admin.html?tab=tara'] + self::stamp($per, 'Tara chat records, offers, security events, orders and AI usage');
    }

    /** The funnel for a period, each step's rate, the weakest step, and the same for the period before. */
    public static function funnel(array $per): array
    {
        $now = Growth::metrics($per['from'], $per['to']);
        $was = Growth::metrics($per['prevFrom'], $per['prevTo']);
        $steps = ['visitors' => 'Visitors', 'signups' => 'Sign-ups', 'verified' => 'Verified email', 'activated' => 'Made a resume', 'checkouts' => 'Opened checkout', 'buyers' => 'Paid'];
        $rows = [];
        $prev = null;
        $worst = null;
        foreach ($steps as $k => $label) {
            $v = $now[$k];
            $rate = $prev !== null && $v !== null && $now[$prev] ? round($v / $now[$prev] * 100, 1) : null;
            $rows[] = ['step' => $label, 'count' => $v, 'previousPeriod' => $was[$k], 'fromStepBefore' => $rate !== null ? $rate.'%' : null];
            if ($rate !== null && $k !== 'verified' && ($worst === null || $rate < $worst[1])) {
                $worst = [$label, $rate, $steps[$prev]];
            }
            $prev = $k;
        }
        $bottleneck = $worst ? 'The weakest step is '.$worst[2].' → '.$worst[0].': '.$worst[1].'% move on.' : 'Not enough data in this period to find the weakest step.';

        return ['ok' => true, 'steps' => $rows, 'bottleneck' => $bottleneck, 'trafficSources' => $now['sources'] ?: 'Not tracked (Analytics is off)',
            'notMeasured' => 'Which landing pages lead to sign-ups without sales, and which keywords bring paying customers, are not tracked per page or keyword yet. Visitors need Analytics switched on.'] + self::stamp($per, 'CVTara accounts, resumes, checkouts, orders and on-site analytics');
    }

    /** What to do today: the most important items from health, money waiting, approvals, the funnel and the month's pace, in order. */
    public static function priorities(): array
    {
        $per = self::period('last_7_days');
        $h = self::siteHealth();
        $p = self::pendingApprovals();
        $month = Growth::month();
        $items = [];
        foreach (is_array($h['issuesByPriority']) ? $h['issuesByPriority'] : [] as $i) {
            if (in_array($i['severity'], ['critical', 'high'], true)) {
                $items[] = ['priority' => $i['severity'] === 'critical' ? 1 : 2, 'do' => 'Fix: '.$i['issue'], 'why' => 'Site health ('.$i['severity'].')', 'link' => $i['where']];
            }
        }
        $sec = TaraGuard::stats(now()->subDay(), now())['customersWithRepeatedAttempts'];
        if ($sec) {
            $items[] = ['priority' => 2, 'do' => 'Look at '.count($sec).' customer account(s) with repeated unsafe requests to Tara', 'why' => 'Security', 'link' => '/admin.html?tab=errors'];
        }
        $add = function ($n, $pr, $do, $why, $link) use (&$items) {
            if ($n) {
                $items[] = ['priority' => $pr, 'do' => sprintf($do, $n), 'why' => $why, 'link' => $link];
            }
        };
        $add($p['refundRequests'], 2, 'Decide %d refund request(s)', 'Customers are waiting for money', '/admin.html?tab=business');
        $add(count($p['expertReviewsFailed']), 2, 'Retry or finish %d Expert Review order(s) that failed', 'Paid work not delivered', '/admin.html?tab=business');
        $add(DB::table('support_reports')->whereNotIn('status', ['resolved', 'closed'])->where('area', 'payment')->count(), 2, 'Answer %d open payment ticket(s)', 'Payment problems lose sales', '/admin.html?tab=feedback');
        $add(DB::table('support_reports')->whereNotIn('status', ['resolved', 'closed'])->where('created_at', '<', now()->subHours(48))->count(), 3, 'Reply to %d support ticket(s) open over 48 hours', 'Overdue support', '/admin.html?tab=feedback');
        $add($p['seoChangesToApprove'], 3, 'Approve or reject %d SEO change(s)', 'SEO work waits for you', '/admin.html?tab=growth');
        $add($p['reviewsToModerate'], 4, 'Moderate %d review(s)', 'Social proof waits for you', '/admin.html?tab=desk');
        if (($month['band'] ?? '') === 'red' || ($month['attainment'] !== null && $month['attainment'] < 70)) {
            $items[] = ['priority' => 2, 'do' => 'Sales are behind plan this month ('.($month['attainment'] ?? '?').'% of pace): act on the growth suggestions', 'why' => $month['label'].': '.$month['metrics']['purchases'].' of '.$month['target'].' orders', 'link' => '/admin.html?tab=growth'];
        }
        $items[] = ['priority' => 3, 'do' => 'Work on the weakest funnel step', 'why' => self::funnel($per)['bottleneck'], 'link' => '/admin.html?tab=growth'];
        usort($items, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        return ['ok' => true, 'today' => array_slice($items, 0, 8), 'rule' => 'Order: site down or payments broken, then money and paid work waiting, then sales pace, then growth work.', 'workedOutAt' => now()->tz(Growth::TZ)->format('j M Y, g:i A')];
    }

    /** A report from Reports & Export: the first rows here and a link that downloads the full file. */
    public static function report(User $owner, string $dataset, array $q): array
    {
        $params = array_filter(['dataset' => $dataset, 'from' => $q['from'] ?? null, 'to' => $q['to'] ?? null, 'q' => $q['search'] ?? null, 'status' => $q['status'] ?? null, 'plan' => $q['plan'] ?? null, 'per' => 25], fn ($v) => $v !== null && $v !== '');
        $out = TaraOwner::admin($owner, 'GET', 'report', $params);
        if (empty($out['ok'])) {
            return $out;
        }
        $file = '/api/admin/report?'.http_build_query(array_diff_key($params, ['per' => 1]) + ['format' => 'xlsx']);
        $personal = in_array($dataset, self::PERSONAL, true);

        return ['ok' => true, 'report' => $out['dataset'] ?? $dataset, 'totalRows' => $out['total'] ?? null, 'columns' => array_column($out['columns'] ?? [], 'label', 'key'), 'firstRows' => array_slice($out['rows'] ?? [], 0, 25), 'sums' => $out['sums'] ?? null,
            '_links' => $personal ? [['label' => 'Open Reports & Export', 'url' => '/admin.html?tab=reports']] : [['label' => 'Download as Excel', 'url' => $file], ['label' => 'Open Reports & Export', 'url' => '/admin.html?tab=reports']],
            'note' => $personal ? 'Only the first 25 rows are here. This report holds customers\' personal details, so the full file is downloaded only from Reports & Export (bulk personal-data exports are not done from the chat).' : 'Only the first 25 rows are here. The download link has every row with the same filters.'];
    }
}
