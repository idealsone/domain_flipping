<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The SEO Head's and the Growth & Revenue agent's fixed cadences (Growth plan §12.1-§12.2): a daily, weekly and monthly
 * report each, written once per period after 7 AM India time and kept in agent_reports. Every number comes from the
 * site's own records; anything the site cannot measure is null ("Not tracked"). No model is called, so nothing here
 * costs money. When a target is missed the agent writes a corrective record with the §11.2 fields (target, actual,
 * variance, evidence, root cause, corrective action, owner, deadline, expected result, review date) and opens a task
 * for approval; it never changes the site by itself.
 */
class AgentCadence
{
    public const RULES_VERSION = 'cadence-2026-11-12';

    public const CADENCES = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];

    /** Runs whatever report is due. Called from the scheduler every minute; does real work at most once per 10 minutes. */
    public static function due(?Carbon $now = null, bool $force = false): array
    {
        if (! Schema::hasTable('agent_reports')) {
            return [];
        }
        $now = ($now ?? now())->copy()->tz(Growth::TZ);
        if (! $force && $now->hour < 7) {
            return [];
        }
        $done = [];
        foreach (['seo', 'growth'] as $agent) {
            foreach (array_keys(self::CADENCES) as $c) {
                $period = self::period($c, $now);
                if (! DB::table('agent_reports')->where(['agent' => $agent, 'cadence' => $c, 'period' => $period])->exists()) {
                    $done[] = self::run($agent, $c, $now);
                }
            }
        }

        return $done;
    }

    /** The period a report covers: the day, ISO week or month before $now. */
    public static function period(string $cadence, Carbon $now): string
    {
        return match ($cadence) {
            'daily' => $now->copy()->subDay()->format('Y-m-d'),
            'weekly' => $now->copy()->startOfWeek()->subWeek()->format('o-\WW'),
            default => $now->copy()->startOfMonth()->subMonth()->format('Y-m'),
        };
    }

    /** [from, to) in India time for a cadence ending before $now. */
    public static function range(string $cadence, Carbon $now): array
    {
        return match ($cadence) {
            'daily' => [$now->copy()->subDay()->startOfDay(), $now->copy()->startOfDay()],
            'weekly' => [$now->copy()->startOfWeek()->subWeek(), $now->copy()->startOfWeek()],
            default => [$now->copy()->startOfMonth()->subMonth(), $now->copy()->startOfMonth()],
        };
    }

    /** Builds one report, stores it (replacing an earlier one for the same period) and writes corrections. */
    public static function run(string $agent, string $cadence, ?Carbon $now = null): array
    {
        abort_unless(in_array($agent, ['seo', 'growth'], true) && isset(self::CADENCES[$cadence]), 422, 'Unknown report.');
        $now = ($now ?? now())->copy()->tz(Growth::TZ);
        $period = self::period($cadence, $now);
        [$from, $to] = self::range($cadence, $now);

        return AgentLog::run($agent, $cadence.'-report', function () use ($agent, $cadence, $period, $from, $to, $now) {
            $data = $agent === 'seo' ? self::seo($cadence, $from, $to) : self::growth($cadence, $from, $to, $now);
            $data += ['period' => $period, 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'knowledgeVersion' => KnowledgeSync::version()];
            $corr = self::corrections($agent, $cadence, $period, $data, $now);
            $data['corrections'] = $corr;
            DB::table('agent_reports')->updateOrInsert(['agent' => $agent, 'cadence' => $cadence, 'period' => $period], ['data_json' => json_encode($data), 'updated_at' => now(), 'created_at' => now()]);

            return ['summary' => self::CADENCES[$cadence].' report for '.$period.' written'.($corr ? ', '.count($corr).' corrective action'.(count($corr) === 1 ? '' : 's') : ''), 'period' => $period,
                '_meta' => ['promptVersion' => self::RULES_VERSION, 'model' => '', 'sources' => $data['sources'] ?? [], 'knowledgeVersion' => $data['knowledgeVersion']]];
        });
    }

    // ------------------------------------------------------------------ SEO Head

    private static function seo(string $cadence, Carbon $from, Carbon $to): array
    {
        $pages = DB::table('seo_pages');
        $d = ['sources' => ['seo_pages', 'searchData', 'seo_proposals']];
        // Daily: pages that break, important pages Google has not indexed, changes waiting.
        $broken = (clone $pages)->whereNotNull('http_status')->where('http_status', '>=', 400)->orderByDesc('important')->limit(20)->pluck('http_status', 'path')->all();
        $d['brokenPages'] = $broken;
        $d['importantNotIndexed'] = (clone $pages)->where('important', true)->where('coverage', 'like', '%not indexed%')->limit(20)->pluck('path')->all();
        $d['pagesTracked'] = (clone $pages)->count();
        $d['proposalsWaiting'] = Schema::hasTable('seo_proposals') ? DB::table('seo_proposals')->where('status', 'pending')->count() : 0;
        $search = Settings::get('searchData', []);
        $q = is_array($search['query'] ?? null) ? $search['query'] : [];
        $d['searchWindow'] = isset($search['startDate']) ? $search['startDate'].' to '.($search['endDate'] ?? '') : null;
        $d['clicks'] = $q ? (int) array_sum(array_column($q, 'clicks')) : null;
        $d['impressions'] = $q ? (int) array_sum(array_column($q, 'impressions')) : null;
        if ($cadence !== 'daily') {
            // Weekly: searches on page 1-3 that a better page could win, and pages Google shows but people skip.
            $near = array_values(array_filter($q, fn ($r) => ($r['position'] ?? 0) >= 4 && ($r['position'] ?? 0) <= 30 && ($r['impressions'] ?? 0) >= 20));
            usort($near, fn ($a, $b) => $b['impressions'] <=> $a['impressions']);
            $d['nearWins'] = array_map(fn ($r) => ['query' => $r['key'], 'position' => round((float) $r['position'], 1), 'impressions' => (int) $r['impressions']], array_slice($near, 0, 15));
            $low = array_values(array_filter($q, fn ($r) => ($r['position'] ?? 99) <= 10 && ($r['impressions'] ?? 0) >= 100 && ($r['ctr'] ?? 1) < 0.02));
            usort($low, fn ($a, $b) => $b['impressions'] <=> $a['impressions']);
            $d['lowClickRate'] = array_map(fn ($r) => ['query' => $r['key'], 'position' => round((float) $r['position'], 1), 'impressions' => (int) $r['impressions'], 'ctrPct' => round((float) $r['ctr'] * 100, 1)], array_slice($low, 0, 15));
        }
        if ($cadence === 'monthly') {
            // Monthly: coverage per section (pillar) and sign-ups that arrived from search.
            $d['sections'] = (clone $pages)->select('section', DB::raw('count(*) as pages'), DB::raw("sum(case when coverage like 'Submitted and indexed%' then 1 else 0 end) as indexed"))->groupBy('section')->get()
                ->map(fn ($r) => ['section' => $r->section ?: 'other', 'pages' => (int) $r->pages, 'indexed' => (int) $r->indexed])->all();
            $d['withoutKeyword'] = Schema::hasColumn('seo_pages', 'primary_keyword') ? (clone $pages)->where('primary_keyword', '')->count() : null;
            $d['organicSignups'] = Schema::hasColumn('users', 'signup_source') ? DB::table('users')->where('is_admin', false)->whereIn('signup_source', Attribution::ORGANIC)->where('created_at', '>=', $from->copy()->utc())->where('created_at', '<', $to->copy()->utc())->count() : null;
            $d['sources'][] = 'users';
        }

        return $d;
    }

    // ------------------------------------------------------------------ Growth & Revenue

    private static function growth(string $cadence, Carbon $from, Carbon $to, Carbon $now): array
    {
        $m = Growth::metrics($from, $to);
        [$a, $b] = [$from->copy()->utc(), $to->copy()->utc()];
        $failed = DB::table('purchases')->where('status', 'failed')->where('created_at', '>=', $a)->where('created_at', '<', $b)->count();
        $abandoned = DB::table('purchases')->where('status', 'created')->where('created_at', '>=', $a)->where('created_at', '<', $b)->distinct()->count('owner_id');
        $d = ['sources' => ['purchases', 'users', 'resume_drafts', 'mail_outbox'],
            'signups' => $m['signups'], 'verified' => $m['verified'], 'startedResume' => $m['activated'], 'checkouts' => $m['checkouts'], 'payments' => $m['purchases'], 'paymentsFailed' => $failed,
            'abandonedCheckouts' => $abandoned, 'paidCustomers' => $m['buyers'], 'newCustomers' => $m['newCustomers'], 'grossPaise' => $m['grossPaise'], 'netPaise' => $m['netPaise'],
            'aovPaise' => $m['purchases'] ? (int) round($m['grossPaise'] / $m['purchases']) : null, 'visitors' => $m['visitors'], 'bottleneck' => Growth::bottleneck($m)];
        $month = Growth::month($now);
        $d['month'] = ['label' => $month['label'], 'target' => $month['target'], 'pace' => $month['pace'], 'purchases' => $month['metrics']['purchases'], 'attainment' => $month['attainment'], 'band' => $month['band'], 'perDayNeeded' => $month['perDayNeeded']];
        if ($cadence !== 'daily') {
            $d['emailsSent'] = $m['marketingSent'];
            $d['outreachSent'] = $m['outreachSent'];
            $d['unsubscribes'] = $m['unsubscribes'];
            $d['experimentsRunning'] = Schema::hasTable('growth_experiments') ? DB::table('growth_experiments')->where('status', 'running')->count() : null;
            $d['signupsBySource'] = Schema::hasColumn('users', 'acq_class') ? DB::table('users')->where('is_admin', false)->where('created_at', '>=', $a)->where('created_at', '<', $b)->select('acq_class', DB::raw('count(*) as n'))->groupBy('acq_class')->pluck('n', 'acq_class')->map(fn ($n) => (int) $n)->all() : null;
            // Forecast: this month's purchases at the current daily rate.
            $day = max(1, $now->day - 1);
            $d['forecastPurchases'] = (int) round($month['metrics']['purchases'] / $day * $now->daysInMonth);
        }
        if ($cadence === 'monthly') {
            $d['refundRatePct'] = $m['grossPaise'] ? round($m['refundedPaise'] / $m['grossPaise'] * 100, 1) : null;
            $d['referralPurchases'] = DB::table('purchases')->where('referral_discount_paise', '>', 0)->whereNotNull('purchased_at')->where('purchased_at', '>=', $a)->where('purchased_at', '<', $b)->count();
            $d['plans'] = $m['plans'];
            $d['margin'] = class_exists(CostControls::class) && Schema::hasTable('ai_invoices') ? CostControls::margin($from, $to) : null;
            $d['partnerSignups'] = Schema::hasColumn('users', 'acq_partner_id') ? DB::table('users')->whereNotNull('acq_partner_id')->where('created_at', '>=', $a)->where('created_at', '<', $b)->count() : null;
            $d['interviewSessions'] = Schema::hasTable('interview_sessions') ? DB::table('interview_sessions')->where('created_at', '>=', $a)->where('created_at', '<', $b)->count() : null;
            array_push($d['sources'], 'ai_usage', 'interview_sessions');
        }

        return $d;
    }

    // ------------------------------------------------------------------ if/else corrective rules

    private const FIX = [
        'visitors' => ['Visitors are not signing up.', 'Rework the sign-up promise and button on the 5 most-visited landing pages.', 'Visitor to sign-up rate up by 2 points'],
        'signups' => ['People sign up but do not verify their email.', 'Check the code mail lands in the inbox (Health page) and resend codes to unverified accounts from the last 7 days.', 'Verification rate above 70%'],
        'verified' => ['Verified users are not starting a resume.', 'Shorten the first step after sign-up and send the welcome mail within 10 minutes.', 'Half of verified users start a resume'],
        'activated' => ['Users build resumes but do not open checkout.', 'Make the ₹99 single download clearer on the plan page and at the download button.', 'Checkout opens up by 20%'],
        'checkouts' => ['People open checkout but do not pay.', 'Check payment failures with the gateway and switch on the abandoned-checkout mail.', 'Checkout to paid rate up by 10 points'],
    ];

    /** Rules for a missed target. Each record dedupes per agent, period and metric; each opens one task. */
    private static function corrections(string $agent, string $cadence, string $period, array $d, Carbon $now): array
    {
        $out = [];
        if ($agent === 'growth' && $cadence !== 'monthly' && in_array($d['month']['band'] ?? 'none', ['amber', 'red'], true)) {
            $mo = $d['month'];
            $bn = $d['bottleneck'] ?? null;
            $fix = $bn ? self::FIX[$bn['from']] ?? null : null;
            $out[] = self::record($agent, $cadence, $period, 'Paid orders vs pace', [
                'target' => (string) $mo['pace'].' orders by now ('.$mo['target'].' this month)', 'actual' => (string) $mo['purchases'].' orders',
                'variance' => round((float) $mo['purchases'] - (float) $mo['pace'], 1).' orders ('.$mo['attainment'].'% of pace)',
                'evidence' => $mo['label'].' paid orders from the payments table; weakest step: '.($bn ? Growth::STAGES[$bn['from']].' to '.Growth::STAGES[$bn['to']].' at '.$bn['rate'].'%' : 'not enough people yet to judge'),
                'root_cause' => $fix[0] ?? 'Too few visitors to show where people drop off.', 'action' => $fix[1] ?? 'Bring more visitors: publish two search pages and send one approved campaign.',
                'owner' => 'Growth & Revenue agent', 'expected' => $fix[2] ?? 'Back to 90% of pace', 'days' => 7, 'priority' => $mo['band'] === 'red' ? 'high' : 'normal',
            ], $now);
        }
        if ($agent === 'growth' && $cadence === 'monthly' && ($d['refundRatePct'] ?? 0) > 5) {
            $out[] = self::record($agent, $cadence, $period, 'Refund rate', ['target' => 'At most 5%', 'actual' => $d['refundRatePct'].'%', 'variance' => '+'.round($d['refundRatePct'] - 5, 1).' points',
                'evidence' => 'Refunded and gross amounts of '.$period.' orders', 'root_cause' => 'Customers ask for money back within 7 days of buying.',
                'action' => 'Read the refund reasons, fix the top one, and make the plan page say exactly what each pack includes.', 'owner' => 'Growth & Revenue agent', 'expected' => 'Refund rate under 5% next month', 'days' => 14, 'priority' => 'high'], $now);
        }
        if ($agent === 'seo' && $cadence === 'daily' && ($n = count($d['brokenPages'] ?? [])) > 0) {
            $out[] = self::record($agent, $cadence, $period, 'Pages that do not open', ['target' => '0 pages', 'actual' => $n.' page'.($n === 1 ? '' : 's'), 'variance' => '+'.$n,
                'evidence' => implode('; ', array_map(fn ($p, $s) => $p.' ('.$s.')', array_keys(array_slice($d['brokenPages'], 0, 5, true)), array_slice($d['brokenPages'], 0, 5, true))),
                'root_cause' => 'The last scan got an error status for these pages.', 'action' => 'Fix or redirect each page, then run the SEO scan again.', 'owner' => 'SEO Head', 'expected' => 'Every listed page opens with status 200', 'days' => 2, 'priority' => 'high'], $now);
        }
        if ($agent === 'seo' && $cadence === 'weekly' && count($d['lowClickRate'] ?? []) > 0) {
            $top = $d['lowClickRate'][0];
            $out[] = self::record($agent, $cadence, $period, 'Searches shown but not clicked', ['target' => 'At least 2% clicks on page-1 searches', 'actual' => count($d['lowClickRate']).' searches under 2%',
                'variance' => 'Top: "'.$top['query'].'" '.$top['ctrPct'].'% at position '.$top['position'], 'evidence' => 'Search Console '.($d['searchWindow'] ?? ''),
                'root_cause' => 'The page title and description do not match what people searched for.', 'action' => 'Draft a new title and description for the top 3 searches and send them for approval.',
                'owner' => 'SEO Head', 'expected' => 'Clicks on those searches up by a third in 4 weeks', 'days' => 7, 'priority' => 'normal'], $now);
        }

        return $out;
    }

    private static function record(string $agent, string $cadence, string $period, string $metric, array $f, Carbon $now): array
    {
        $deadline = $now->copy()->addDays($f['days'])->toDateString();
        $review = $now->copy()->addDays($f['days'] + 7)->toDateString();
        $row = ['target' => mb_substr($f['target'], 0, 80), 'actual' => mb_substr($f['actual'], 0, 80), 'variance' => mb_substr($f['variance'], 0, 80), 'evidence' => mb_substr($f['evidence'], 0, 1000),
            'root_cause' => mb_substr($f['root_cause'], 0, 600), 'action' => mb_substr($f['action'], 0, 600), 'owner' => $f['owner'], 'deadline' => $deadline, 'expected' => mb_substr($f['expected'], 0, 300), 'review_date' => $review];
        $key = ['agent' => $agent, 'period' => $period, 'metric' => $metric];
        $old = DB::table('agent_corrections')->where($key)->first();
        if ($old) {
            DB::table('agent_corrections')->where('id', $old->id)->update($row + ['updated_at' => now()]);
            $task = $old->task_id;
        } else {
            $task = Schema::hasTable('ai_tasks') ? AgentTasks::create(null, ['agent' => $agent, 'title' => mb_substr('Fix: '.$metric.' ('.$period.')', 0, 200), 'priority' => $f['priority'],
                'fingerprint' => $agent.':corr:'.sha1($period.$metric), 'dueAt' => $deadline,
                'detail' => "Target: {$row['target']}\nActual: {$row['actual']}\nVariance: {$row['variance']}\nEvidence: {$row['evidence']}\nRoot cause: {$row['root_cause']}\nCorrective action: {$row['action']}\nOwner: {$row['owner']}\nDeadline: {$deadline}\nExpected result: {$row['expected']}\nReview date: {$review}"]) : null;
            DB::table('agent_corrections')->insert($key + $row + ['cadence' => $cadence, 'task_id' => $task, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        }

        return ['metric' => $metric, 'taskId' => $task] + $row;
    }

    /** Console view: latest report per agent and cadence, open corrections, knowledge sync and change feed. */
    public static function overview(): array
    {
        $latest = [];
        foreach (['seo', 'growth'] as $a) {
            foreach (array_keys(self::CADENCES) as $c) {
                $r = DB::table('agent_reports')->where(['agent' => $a, 'cadence' => $c])->orderByDesc('period')->first();
                $latest[] = ['agent' => $a, 'cadence' => $c, 'period' => $r->period ?? null, 'at' => $r ? AgentLog::iso($r->updated_at) : null, 'data' => $r ? json_decode($r->data_json, true) : null];
            }
        }

        return ['reports' => $latest, 'corrections' => DB::table('agent_corrections')->orderByDesc('id')->limit(30)->get()->map(fn ($r) => (array) $r)->all(), 'knowledge' => KnowledgeSync::status(), 'changes' => KnowledgeSync::feed(20),
            'runs' => DB::table('agent_runs')->whereNotNull('run_uid')->orderByDesc('id')->limit(10)->get(['run_uid', 'agent', 'task', 'status', 'prompt_version', 'model', 'sources_json', 'knowledge_version', 'started_at'])
                ->map(fn ($r) => ['run' => $r->run_uid, 'agent' => $r->agent, 'task' => $r->task, 'status' => $r->status, 'rules' => $r->prompt_version, 'model' => $r->model ?: null, 'sources' => json_decode((string) $r->sources_json, true) ?: [], 'knowledgeVersion' => $r->knowledge_version, 'at' => AgentLog::iso($r->started_at)])->all()];
    }
}
