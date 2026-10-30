<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Growth page: the first-year target ladder, the funnel from visit to repeat purchase, daily snapshots, the 2 PM and 7 PM
 * owner reports (Mon–Sat, India time) and the monthly cap on Claude spending. Every number is counted by the database;
 * nothing here asks a model to do arithmetic. A number the site cannot measure is null and shows as "Data unavailable".
 */
class Growth
{
    public const TZ = 'Asia/Kolkata';

    /** Owner's first-year plan (Nov 2026 – Oct 2027): purchases per month. Gross target = purchases × average purchase. */
    public const LADDER = ['2026-11' => 100, '2026-12' => 200, '2027-01' => 330, '2027-02' => 600, '2027-03' => 800, '2027-04' => 1000, '2027-05' => 1300, '2027-06' => 1500, '2027-07' => 1850, '2027-08' => 1900, '2027-09' => 2000, '2027-10' => 2230];

    /** usdInr and the per-million-token prices are the owner's to keep current (Claude API pricing page). */
    public const DEFAULTS = ['ladder' => self::LADDER, 'avgPaise' => 12677, 'stretchPct' => 200, 'reports' => true, 'reportEmail' => '', 'budgetInr' => 2000, 'usdInr' => 88, 'inputUsdPerM' => 5, 'outputUsdPerM' => 25];

    public const SLOTS = ['midday' => 14, 'evening' => 19];

    public const STAGES = ['visitors' => 'Visitors', 'signups' => 'Sign-ups', 'verified' => 'Verified email', 'activated' => 'Started a resume', 'checkouts' => 'Opened checkout', 'buyers' => 'Paid'];

    public static function settings(): array
    {
        $s = array_replace(self::DEFAULTS, array_intersect_key((array) Settings::get('growth', []), self::DEFAULTS));
        $s['ladder'] = array_replace(self::LADDER, array_map('intval', array_intersect_key((array) $s['ladder'], self::LADDER)));

        return $s;
    }

    /** Successful payments that count as sales: live gateway money, not demo or free referral rewards. */
    private static function sales()
    {
        $q = DB::table('purchases')->whereNotNull('purchased_at')->whereIn('status', ['paid', 'captured', 'partially_refunded', 'refunded', 'disputed'])->where('amount_paise', '>', 0);

        return config('app.preview') ? $q->where('provider', '!=', 'referral_reward') : $q->where('is_live', true)->whereNotIn('provider', ['demo', 'referral_reward']);
    }

    private static function utc(Carbon $c): Carbon
    {
        return $c->copy()->utc();
    }

    /** The funnel and sales for one period (India-time boundaries). */
    public static function metrics(Carbon $from, Carbon $to): array
    {
        // The end is exclusive; a period that ends "now" includes this second, so rows written a moment ago count.
        [$a, $b] = [self::utc($from), $to->isFuture() || $to->diffInSeconds(now(), true) < 1 ? now()->addSecond()->utc() : self::utc($to)];
        $in = fn ($q, $col = 'created_at') => $q->where($col, '>=', $a)->where($col, '<', $b);
        $sales = $in(self::sales(), 'purchased_at');
        $rows = (clone $sales)->get(['owner_id', 'plan', 'amount_paise', 'refunded_paise']);
        $firstBuyers = $rows->pluck('owner_id')->unique()->filter(fn ($o) => ! self::sales()->where('owner_id', $o)->where('purchased_at', '<', $a)->exists())->count();
        $plans = $rows->groupBy(fn ($r) => str_starts_with($r->plan, 'addon-') ? 'add-ons' : $r->plan)->map(fn ($g) => ['count' => $g->count(), 'paise' => (int) $g->sum('amount_paise')])->all();
        $analytics = (bool) (Settings::get('integrations')['analytics'] ?? false);
        $visitors = $analytics ? $in(DB::table('page_views'))->distinct()->count('visitor_id') : null;
        $sources = $analytics ? $in(DB::table('page_views'))->select('source', DB::raw('count(distinct visitor_id) as n'))->groupBy('source')->pluck('n', 'source')->map(fn ($n) => (int) $n)->all() : [];

        return [
            'visitors' => $visitors,
            'signups' => $in(DB::table('users')->where('is_admin', false))->count(),
            'verified' => $in(DB::table('users')->where('is_admin', false), 'email_verified_at')->count(),
            'activated' => $in(DB::table('resume_drafts'))->distinct()->count('owner_id'),
            'checkouts' => $in(DB::table('purchases')->where('provider', '!=', 'referral_reward')->where('amount_paise', '>', 0))->distinct()->count('owner_id'),
            'buyers' => $rows->pluck('owner_id')->unique()->count(),
            'purchases' => $rows->count(),
            'newCustomers' => $firstBuyers,
            'repeatPurchases' => $rows->count() - $firstBuyers,
            'grossPaise' => (int) $rows->sum('amount_paise'),
            'refundedPaise' => (int) $rows->sum('refunded_paise'),
            'netPaise' => (int) ($rows->sum('amount_paise') - $rows->sum('refunded_paise')),
            'plans' => $plans,
            'sources' => $sources,
            'marketingSent' => $in(DB::table('mail_outbox')->where('status', 'sent')->whereIn('trigger', EmailRenderer::MARKETING), 'sent_at')->count(),
            'outreachSent' => $in(DB::table('outreach_sends')->where('status', 'sent'))->count(),
            'unsubscribes' => $in(DB::table('email_suppressions'))->count(),
            'adSpendPaise' => null,
        ];
    }

    /** Where the month stands against the plan: committed target, stretch target, pace for the days gone, and a band. */
    public static function month(?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->tz(self::TZ);
        $s = self::settings();
        $key = $now->format('Y-m');
        $start = $now->copy()->startOfMonth();
        $m = self::metrics($start, $now);
        $target = $s['ladder'][$key] ?? null;
        $days = $now->daysInMonth;
        $elapsed = min($days, max(1, $now->day - 1 + ($now->hour + $now->minute / 60) / 24));
        $pace = $target ? $target * $elapsed / $days : null;
        $att = $pace ? $m['purchases'] / $pace * 100 : null;
        $band = $target === null ? 'none' : ($att >= 90 ? 'green' : ($att >= 70 ? 'amber' : 'red'));
        $left = max(0, $days - $now->day + 1);

        return [
            'month' => $key, 'label' => $now->format('F Y'), 'metrics' => $m,
            'target' => $target, 'stretch' => $target ? (int) round($target * $s['stretchPct'] / 100) : null,
            'targetPaise' => $target ? $target * $s['avgPaise'] : null, 'stretchPaise' => $target ? (int) round($target * $s['avgPaise'] * $s['stretchPct'] / 100) : null,
            'pace' => $pace ? round($pace, 1) : null, 'attainment' => $att !== null ? round($att, 1) : null, 'band' => $band,
            'perDayNeeded' => $target ? (int) ceil(max(0, $target - $m['purchases']) / max(1, $left)) : null,
        ];
    }

    public static function yearPlan(): array
    {
        $s = self::settings();
        $done = DB::table('growth_snapshots')->get(['day', 'metrics_json'])->groupBy(fn ($r) => substr((string) $r->day, 0, 7))
            ->map(fn ($g) => ['purchases' => $g->sum(fn ($r) => json_decode($r->metrics_json, true)['purchases'] ?? 0), 'grossPaise' => $g->sum(fn ($r) => json_decode($r->metrics_json, true)['grossPaise'] ?? 0)]);
        $rows = [];
        foreach ($s['ladder'] as $k => $n) {
            $rows[] = ['month' => $k, 'target' => $n, 'targetPaise' => $n * $s['avgPaise'], 'purchases' => $done[$k]['purchases'] ?? null, 'grossPaise' => $done[$k]['grossPaise'] ?? null];
        }

        return ['rows' => $rows, 'totalTarget' => array_sum($s['ladder']), 'totalPaise' => array_sum($s['ladder']) * $s['avgPaise']];
    }

    /** The weakest step of the funnel (with enough people to judge), as a stage key and its conversion. */
    public static function bottleneck(array $m): ?array
    {
        $keys = array_keys(self::STAGES);
        $worst = null;
        for ($i = 1; $i < count($keys); $i++) {
            $from = $m[$keys[$i - 1]];
            if ($from === null || $from < 20) {
                continue;
            }
            $rate = $m[$keys[$i]] / $from;
            if (! $worst || $rate < $worst['rate']) {
                $worst = ['from' => $keys[$i - 1], 'to' => $keys[$i], 'rate' => round($rate * 100, 1)];
            }
        }

        return $worst;
    }

    private const ADVICE = [
        'visitors' => 'Visitors are not signing up. Check the landing page promise, mobile layout, speed and the sign-up button on the pages with the most visits.',
        'signups' => 'People sign up but do not verify their email. Check that the code mail arrives (Health page) and lands in the inbox, not spam.',
        'verified' => 'Verified users are not starting a resume. Make the first step after sign-up shorter and send the welcome mail sooner.',
        'activated' => 'Users build resumes but do not open checkout. Check that the plan page explains the ₹99 single download clearly and that the download button leads there.',
        'checkouts' => 'People open checkout but do not pay. Check payment failures with Razorpay/PhonePe and that the abandoned-checkout mail is switched on.',
    ];

    /** Plain next actions from the numbers (deterministic rules from the owner's blueprint). */
    public static function actions(array $month, array $today): array
    {
        $out = [];
        if (($b = self::bottleneck($month['metrics'])) && isset(self::ADVICE[$b['from']])) {
            $out[] = self::ADVICE[$b['from']].' ('.self::STAGES[$b['from']].' → '.self::STAGES[$b['to']].': '.$b['rate'].'% this month.)';
        }
        if ($month['metrics']['visitors'] === null) {
            $out[] = 'Visitor numbers are unavailable: switch on Analytics in Integrations so visits can be counted (only visitors who allow analytics are counted).';
        } elseif ($month['metrics']['visitors'] < 300 && now()->tz(self::TZ)->day > 7) {
            $out[] = 'Traffic is low. Push college outreach and referral sharing, and check Search Console for pages close to page 1.';
        }
        if ($month['band'] === 'red') {
            $out[] = 'Below 70% of pace: start a recovery plan today. Pick the weakest funnel step above, try two changes, and check the result in 3 days.';
        } elseif ($month['band'] === 'amber') {
            $out[] = 'Between 70% and 89% of pace: within one working day choose two actions for the weakest step and give each a date.';
        }
        $m = $month['metrics'];
        if ($m['purchases'] > 0 && $m['refundedPaise'] > $m['grossPaise'] * 0.1) {
            $out[] = 'Refunds are above 10% of sales this month. Read the refund reasons before spending on more traffic.';
        }
        if ($m['marketingSent'] > 50 && $m['unsubscribes'] > $m['marketingSent'] * 0.02) {
            $out[] = 'More than 2% of marketing mail led to an unsubscribe. Slow down campaigns and check the audience and wording.';
        }

        return $out ?: ['On pace. Keep going and check the plan mix and refunds before scaling anything.'];
    }

    /** Claude spending this month (India time) against the owner's cap. */
    public static function budget(): array
    {
        $s = self::settings();
        $start = now()->tz(self::TZ)->startOfMonth()->utc();
        $rows = DB::table('ai_usage')->where('created_at', '>=', $start)->select('purpose', DB::raw('sum(cost_paise) as p'), DB::raw('count(*) as n'))->groupBy('purpose')->get();
        $spent = (int) $rows->sum('p');
        $cap = (int) $s['budgetInr'] * 100;

        return ['spentPaise' => $spent, 'capPaise' => $cap, 'pct' => $cap ? round($spent / $cap * 100, 1) : null, 'byPurpose' => $rows->mapWithKeys(fn ($r) => [$r->purpose => ['paise' => (int) $r->p, 'calls' => (int) $r->n]])->all(), 'stopped' => $cap > 0 && $spent >= $cap];
    }

    /** Called after every Claude request. Customer add-ons are paid for, so they are counted but never stopped by the cap. */
    public static function recordAi(string $purpose, string $model, int $in, int $out): void
    {
        $s = self::settings();
        $usd = ($in * (float) $s['inputUsdPerM'] + $out * (float) $s['outputUsdPerM']) / 1e6;
        DB::table('ai_usage')->insert(['purpose' => mb_substr($purpose, 0, 40), 'model' => mb_substr($model, 0, 60), 'input_tokens' => $in, 'output_tokens' => $out, 'cost_paise' => (int) ceil($usd * (float) $s['usdInr'] * 100), 'created_at' => now()]);
        $b = self::budget();
        foreach ([90, 75, 50] as $step) {
            if ($b['pct'] !== null && $b['pct'] >= $step) {
                ErrorLog::record('warning', 'ai', "Claude spending has reached {$step}% of this month's cap (₹".number_format($b['spentPaise'] / 100).' of ₹'.number_format($b['capPaise'] / 100).'). Growth tasks stop at 100%; customer add-ons keep working.', 'ai-budget:'.now()->tz(self::TZ)->format('Y-m').':'.$step);
                break;
            }
        }
    }

    /** Growth tasks (not customer add-ons) may call Claude only while this month's spending is under the cap. */
    public static function aiAllowed(string $purpose): bool
    {
        return $purpose === 'customer' || ! self::budget()['stopped'];
    }

    /** Yesterday's numbers are stored once a day so the year plan has history that never changes. */
    public static function snapshot(?Carbon $day = null): array
    {
        $day = ($day ?? now()->tz(self::TZ)->subDay())->copy()->tz(self::TZ)->startOfDay();
        $m = self::metrics($day, $day->copy()->addDay());
        DB::table('growth_snapshots')->updateOrInsert(['day' => $day->toDateString()], ['metrics_json' => json_encode($m), 'created_at' => now(), 'updated_at' => now()]);

        return $m;
    }

    private static function inr(?int $paise): string
    {
        return $paise === null ? 'Data unavailable' : '₹'.number_format($paise / 100);
    }

    private static function num($n): string
    {
        return $n === null ? 'Data unavailable' : number_format($n);
    }

    /** The 2 PM or 7 PM report: committed and stretch targets, actuals, funnel, risks, decisions and next actions. */
    public static function report(string $slot, ?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->tz(self::TZ);
        $month = self::month($now);
        $today = self::metrics($now->copy()->startOfDay(), $now);
        $m = $month['metrics'];
        $health = Health::snapshot();
        $budget = self::budget();
        $bandName = ['green' => 'Green: on pace', 'amber' => 'Amber: 70–89% of pace', 'red' => 'Red: below 70% of pace', 'none' => 'No target for this month'][$month['band']];
        $L = ['# '.($slot === 'midday' ? 'Midday' : 'End-of-day').' growth report, '.$now->format('D j M Y, g:i A').' IST', ''];
        $L[] = '## '.$month['label'].': '.$bandName;
        if ($month['target']) {
            $L[] = '- Purchases: '.number_format($m['purchases']).' of '.number_format($month['target']).' committed ('.number_format($month['stretch']).' stretch). Pace for today: '.$month['pace'].' ('.$month['attainment'].'%).';
            $L[] = '- Gross sales: '.self::inr($m['grossPaise']).' of '.self::inr($month['targetPaise']).' committed ('.self::inr($month['stretchPaise']).' stretch). Net after refunds: '.self::inr($m['netPaise']).'.';
            $L[] = '- Needed from here: '.$month['perDayNeeded'].' purchases a day.';
        } else {
            $L[] = '- Purchases so far: '.number_format($m['purchases']).', gross '.self::inr($m['grossPaise']).'.';
        }
        $L[] = '- New paying customers: '.number_format($m['newCustomers']).'. Repeat purchases: '.number_format($m['repeatPurchases']).'.';
        $L[] = '';
        $L[] = '## Today so far';
        foreach (self::STAGES as $k => $label) {
            $L[] = '- '.$label.': '.self::num($today[$k]);
        }
        $L[] = '- Sales: '.number_format($today['purchases']).' purchases, '.self::inr($today['grossPaise']);
        $L[] = '- Marketing mails sent: '.number_format($today['marketingSent']).'. Outreach mails sent: '.number_format($today['outreachSent']).'.';
        $L[] = '- Ad spend and cost per customer: Data unavailable (no ad account connected).';
        $L[] = '';
        $L[] = '## Risks';
        $risks = array_map(fn ($p) => '- '.$p, $health['problems'] ?? []);
        if (($health['metrics']['mailFailed24h'] ?? 0) > 0) {
            $risks[] = '- '.$health['metrics']['mailFailed24h'].' mails failed in the last 24 hours (Health page).';
        }
        if ($budget['pct'] !== null && $budget['pct'] >= 50) {
            $risks[] = '- Claude spending is at '.$budget['pct'].'% of the monthly cap.';
        }
        array_push($L, ...($risks ?: ['- None found.']));
        $L[] = '';
        $L[] = '## Decisions needed from you';
        $L[] = $budget['stopped'] ? '- Claude cap reached: raise it on the Growth page or wait for next month.' : '- None.';
        $L[] = '';
        $L[] = '## Next actions';
        foreach (self::actions($month, $today) as $a) {
            $L[] = '- '.$a;
        }
        $L[] = '';
        $L[] = '[button: Open the Growth page | '.rtrim((string) config('app.url'), '/').'/admin.html#growth]';

        return ['band' => $month['band'], 'body' => implode("\n", $L)];
    }

    /** Owner addresses for the reports: the one set on the Growth page, else every owner account. */
    private static function recipients(): array
    {
        $to = trim((string) self::settings()['reportEmail']);

        return $to !== '' ? [$to] : DB::table('users')->where('is_admin', true)->where('blocked', false)->pluck('email')->all();
    }

    public static function sendReport(string $slot, ?Carbon $now = null, bool $force = false): ?string
    {
        $now = ($now ?? now())->copy()->tz(self::TZ);
        $day = $now->toDateString();
        if (! $force && DB::table('growth_reports')->where('day', $day)->where('slot', $slot)->exists()) {
            return null;
        }
        $r = self::report($slot, $now);
        $id = (string) Str::uuid();
        DB::table('growth_reports')->upsert([['id' => $id, 'day' => $day, 'slot' => $slot, 'band' => $r['band'], 'body' => $r['body'], 'created_at' => now(), 'updated_at' => now()]], ['day', 'slot'], ['id', 'band', 'body', 'updated_at']);
        $subject = ($slot === 'midday' ? '2 PM' : '7 PM').' growth report: '.['green' => 'on pace', 'amber' => 'behind pace', 'red' => 'well behind pace', 'none' => 'no target yet'][$r['band']];
        foreach (self::recipients() as $to) {
            DB::table('mail_outbox')->insert(['id' => (string) Str::uuid(), 'dedupe' => (string) Str::uuid(), 'owner_id' => null, 'trigger' => 'growth_report', 'recipient' => $to, 'subject' => $subject, 'body' => $r['body'], 'due_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    /** Fills profiles.college / target_role / last_company from each person's newest resume, a few hundred at a time. */
    public static function segments(int $limit = 300): int
    {
        $since = (string) (Settings::get('growthSegmentsAt', [])['at'] ?? '1970-01-01 00:00:00');
        $rows = DB::table('resume_drafts')->where('updated_at', '>', $since)->orderBy('updated_at')->limit($limit)->get(['owner_id', 'role', 'content_json', 'updated_at']);
        foreach ($rows as $r) {
            $d = json_decode((string) $r->content_json, true) ?: [];
            $edu = array_values(array_filter((array) ($d['education'] ?? []), 'is_array'));
            $job = array_values(array_filter((array) ($d['experience'] ?? []), 'is_array'));
            $clean = fn ($v, $n) => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) $v))), 0, $n);
            DB::table('profiles')->where('owner_id', $r->owner_id)->update([
                'college' => $clean($edu[0]['school'] ?? $edu[0]['institution'] ?? '', 160),
                'target_role' => $clean($r->role ?: ($job[0]['role'] ?? ''), 120),
                'last_company' => $clean($job[0]['company'] ?? '', 160),
            ]);
        }
        if ($rows->isNotEmpty()) {
            Settings::put('growthSegmentsAt', ['at' => (string) $rows->last()->updated_at]);
        }

        return $rows->count();
    }

    /** Colleges with the most consenting users: totals only, to choose which placement cells to contact. */
    public static function topColleges(int $n = 20): array
    {
        return DB::table('profiles')->where('college', '!=', '')->where('marketing_consent', true)
            ->select('college', DB::raw('count(*) as users'))->groupBy('college')->orderByDesc('users')->limit($n)->get()
            ->map(fn ($r) => ['college' => $r->college, 'users' => (int) $r->users])->all();
    }

    /** One cron run: yesterday's snapshot after midnight, segments, and the report whose hour has come (Mon–Sat). */
    public static function run(?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->tz(self::TZ);
        $done = [];
        if (! DB::table('growth_snapshots')->where('day', $now->copy()->subDay()->toDateString())->exists()) {
            self::snapshot($now->copy()->subDay());
            $done[] = 'snapshot';
        }
        if (\Illuminate\Support\Facades\Cache::add('growth-segments', 1, 900)) {
            self::segments();
        }
        if (self::settings()['reports'] && ! $now->isSunday()) {
            foreach (array_reverse(self::SLOTS, true) as $slot => $hour) {
                if ($now->hour >= $hour) {
                    // Only the latest slot that is due: a cron that was down all day sends one report, not two.
                    if ($now->hour < 23 && self::sendReport($slot, $now)) {
                        $done[] = $slot;
                    }
                    break;
                }
            }
        }

        return $done;
    }
}
