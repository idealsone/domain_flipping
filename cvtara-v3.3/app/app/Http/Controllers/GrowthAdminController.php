<?php

namespace App\Http\Controllers;

use App\Services\AccountSecurity;
use App\Services\AdminTable;
use App\Services\AgentLog;
use App\Services\Growth;
use App\Services\GrowthOps;
use App\Services\SeoOps;
use App\Services\SeoAgent;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Owner side of the Growth page. Reached through /api/admin/growth… */
class GrowthAdminController extends Controller
{
    public const ROUTES = '#^growth(/.*)?$#';

    public function run(Request $r, string $path)
    {
        $ok = fn ($x = []) => response()->json(['ok' => true, ...$x]);
        $get = $r->isMethod('GET');
        if ($path === 'growth' && $get) {
            $now = now()->tz(Growth::TZ);
            $month = Growth::month($now);
            $today = Growth::metrics($now->copy()->startOfDay(), $now);
            $week = Growth::metrics($now->copy()->subDays(6)->startOfDay(), $now);

            return $ok([
                'month' => $month, 'today' => $today, 'week' => $week, 'stages' => Growth::STAGES,
                'bottleneck' => Growth::bottleneck($month['metrics']), 'actions' => Growth::actions($month, $today),
                'year' => Growth::yearPlan(), 'budget' => Growth::budget(), 'settings' => Growth::settings(),
                'colleges' => Growth::topColleges(), 'analytics' => (bool) (Settings::get('integrations')['analytics'] ?? false),
            ]);
        }
        if ($path === 'growth/reports' && $get) {
            return $ok(AdminTable::query($r, DB::table('growth_reports')->select(['id', 'day', 'slot', 'band', 'created_at']), [
                'filters' => ['slot' => 'slot', 'band' => 'band'], 'date' => 'created_at', 'sorts' => ['sort' => 'created_at'], 'key' => 'id',
                'export' => ['title' => 'Growth reports', 'map' => fn ($rows) => array_map(fn ($x) => ['Day' => substr((string) $x['day'], 0, 10), 'Report' => ['weekly' => 'Weekly', 'midday' => '2 PM', 'evening' => '7 PM'][$x['slot']] ?? $x['slot'], 'Band' => ucfirst((string) $x['band']), 'Sent' => $x['created_at']], $rows)],
            ]));
        }
        if ($path === 'growth/report' && $get) {
            $row = DB::table('growth_reports')->where('id', (string) $r->query('id'))->first();
            abort_unless($row, 404);

            return $ok(['report' => $row]);
        }
        if ($path === 'growth/seo' && $get) {
            return $ok(['seo' => SeoAgent::summary(), 'settings' => SeoAgent::settings(), 'coverage' => SeoAgent::coverage()]);
        }
        if ($path === 'growth/seo/proposals' && $get) {
            return $ok(AdminTable::query($r, DB::table('seo_proposals'), [
                'search' => ['path', 'query'], 'filters' => ['status' => 'status', 'kind' => 'kind'], 'date' => 'created_at', 'sorts' => ['sort' => 'created_at', 'impressions' => 'impressions'], 'key' => 'id',
                'export' => ['title' => 'SEO proposals', 'map' => fn ($rows) => array_map(function ($x) {
                    $d = (array) ($x['data'] ?? []);
                    $b = (array) ($x['before'] ?? []);

                    return ['Proposed' => $x['createdAt'], 'Kind' => $x['kind'] === 'meta' ? 'Title and description' : 'Internal link', 'Page' => $x['path'], 'Search' => $x['query'], 'Status' => ucfirst((string) $x['status']),
                        'Why' => $x['reason'], 'Impressions' => (int) $x['impressions'], 'Position' => (float) $x['position'],
                        'Now' => $x['kind'] === 'meta' ? trim(($b['title'] ?? '').' / '.($b['description'] ?? ''), ' /') : '',
                        'Change' => $x['kind'] === 'meta' ? trim(($d['title'] ?? '').' / '.($d['description'] ?? ''), ' /') : 'Link "'.($d['label'] ?? '').'" on '.implode(', ', $d['from'] ?? []),
                        'Decided' => $x['decidedAt']];
                }, $rows)],
            ], fn ($p) => ['id' => $p->id, 'kind' => $p->kind, 'path' => $p->path, 'query' => $p->query, 'status' => $p->status, 'reason' => $p->reason, 'impressions' => (int) $p->impressions, 'position' => (float) $p->position,
                'data' => json_decode($p->data_json, true), 'before' => json_decode($p->before_json, true), 'createdAt' => $p->created_at, 'decidedAt' => $p->decided_at]));
        }
        if ($path === 'growth/seo/tracked' && $get) {
            $kind = $r->query('kind') === 'query' ? 'query' : 'page';
            $band = fn ($row, $v) => match ($v) {
                'top' => $row['position'] > 0 && $row['position'] <= 10, 'close' => $row['position'] > 10 && $row['position'] <= 20, 'far' => $row['position'] > 20,
                'lowctr' => $row['impressions'] >= 50 && $row['ctr'] < 0.01, 'notarget' => ($row['titleHasTarget'] ?? null) === false, 'unseen' => ! empty($row['unseen']), 'seen' => empty($row['unseen']), 'brand' => ($row['brand'] ?? false) === true, 'nobrand' => ($row['brand'] ?? false) === false, default => true,
            };

            return $ok(AdminTable::rows($r, SeoAgent::tracked($kind), [
                'search' => $kind === 'page' ? ['key', 'target'] : ['key'], 'filters' => ['band' => $band],
                'sorts' => ['impressions' => 'impressions', 'clicks' => 'clicks', 'position' => 'position', 'ctr' => 'ctr', 'change' => 'impressionsChange', 'key' => 'key'], 'sort' => 'impressions',
                'export' => ['title' => $kind === 'page' ? 'Tracked pages' : 'Tracked searches', 'map' => fn ($rows) => array_map(fn ($x) => array_filter([
                    $kind === 'page' ? 'Page' : 'Search' => $x['key'], 'Main search' => $kind === 'page' ? ($x['target'] ?? '') : null,
                    'Main search in title' => $kind === 'page' ? (($x['titleHasTarget'] ?? null) === null ? '' : ($x['titleHasTarget'] ? 'Yes' : 'No')) : null,
                    'Shown by Google' => empty($x['unseen']) ? 'Yes' : 'Not yet', 'Impressions' => (int) ($x['impressions'] ?? 0), 'Change vs week before' => $x['impressionsChange'] ?? '',
                    'Clicks' => (int) ($x['clicks'] ?? 0), 'Clicked %' => round((float) ($x['ctr'] ?? 0) * 100, 1), 'Position' => ! empty($x['position']) ? round((float) $x['position'], 1) : '', 'Places moved' => $x['positionChange'] ?? '',
                    'Searches it shows for' => $kind === 'page' ? implode('; ', array_map(fn ($q) => $q[0], array_slice($x['queries'] ?? [], 0, 10))) : null,
                    'Pages shown' => $kind === 'query' ? implode('; ', $x['pages'] ?? []) : null, 'Other brand' => $kind === 'query' ? (! empty($x['brand']) ? 'Yes' : '') : null,
                ], fn ($v) => $v !== null), $rows)],
            ]));
        }
        if ($path === 'growth/agents' && $get) {
            return $ok(['seo' => \App\Services\SeoAgent::agentStatus(), 'growth' => Growth::agentStatus(), 'settings' => ['seo' => SeoAgent::settings()['auto'], 'growth' => GrowthOps::settings()['auto']]]);
        }
        if ($path === 'growth/agents/log' && $get) {
            return $ok(AdminTable::query($r, DB::table('agent_runs'), ['search' => ['task', 'summary', 'error'], 'filters' => ['agent' => 'agent', 'status' => 'status'], 'date' => 'started_at', 'sorts' => ['sort' => 'id'], 'key' => 'id',
                'export' => ['title' => 'Agent log', 'map' => fn ($rows) => array_map(fn ($x) => ['Started' => $x['started_at'], 'Agent' => AgentLog::AGENTS[$x['agent']] ?? $x['agent'], 'Task' => $x['task'], 'Status' => $x['status'], 'Result' => $x['summary'], 'Error' => $x['error'], 'Took (ms)' => $x['ms']], $rows)]]));
        }
        if ($path === 'growth/seo/overview' && $get) {
            $site = SeoOps::siteFindings();
            unset($site['inbound']);

            return $ok(['kpis' => SeoOps::kpis(), 'brand' => SeoOps::brand(), 'clusters' => SeoOps::clusters(), 'site' => $site, 'sitemaps' => Settings::get('seoSitemaps', []), 'results' => SeoOps::results(20), 'opportunities' => SeoOps::opportunities(),
                'landing' => SeoOps::landingPages(30, 'organic'), 'inspectQuota' => Settings::get('seoInspectQuota', []), 'ops' => SeoOps::settings()]);
        }
        if ($path === 'growth/seo/pages' && $get) {
            return $ok(AdminTable::rows($r, SeoOps::pages(), [
                'search' => ['path', 'title'], 'sorts' => ['impressions' => 'impressions', 'clicks' => 'clicks', 'issues' => 'issueCount', 'linksIn' => 'linksIn', 'path' => 'path'], 'sort' => 'impressions',
                'filters' => ['issues' => fn ($x, $v) => $v === 'yes' ? $x['issueCount'] > 0 : $x['issueCount'] === 0, 'index' => fn ($x, $v) => match ($v) { 'yes' => $x['verdict'] === 'PASS', 'no' => $x['verdict'] !== '' && $x['verdict'] !== 'PASS', default => $x['verdict'] === '' }, 'important' => fn ($x, $v) => $x['important'] === ($v === 'yes'), 'section' => 'section'],
                'export' => ['title' => 'SEO pages', 'map' => fn ($rows) => array_map(fn ($x) => ['Page' => $x['path'], 'Important' => $x['important'] ? 'Yes' : '', 'HTTP' => $x['status'], 'Google index' => $x['coverage'] ?: ($x['verdict'] ?: 'Not checked yet'), 'Last crawl' => $x['lastCrawl'], 'Impressions' => $x['impressions'], 'Clicks' => $x['clicks'], 'Position' => $x['position'],
                    'Title' => $x['title'], 'Description' => $x['description'], 'H1' => $x['h1'], 'Words' => $x['words'], 'Links in' => $x['linksIn'], 'Issues' => implode('; ', $x['issues']), 'Main searches' => implode('; ', array_map(fn ($q) => $q[0], $x['queries'])), 'Scanned' => $x['scannedAt']], $rows)],
            ]));
        }
        if ($path === 'growth/seo/page' && $get) {
            return $ok(SeoOps::history((string) $r->query('path', '/')));
        }
        if ($path === 'growth/seo/keywords' && $get) {
            return $ok(AdminTable::query($r, DB::table('seo_keywords'), ['search' => ['keyword', 'target', 'ranking_page'], 'filters' => ['kind' => 'kind', 'intent' => 'intent', 'value' => 'value', 'source' => 'source',
                'band' => fn ($q, $v) => match ($v) { 'top' => $q->where('position', '<=', 10), 'two' => $q->whereBetween('position', [10.01, 20]), 'far' => $q->where('position', '>', 20), default => $q->whereNull('impressions') }],
                'sorts' => ['impressions' => 'impressions', 'clicks' => 'clicks', 'position' => 'position', 'keyword' => 'keyword'], 'key' => 'id',
                'export' => ['title' => 'Keywords', 'map' => fn ($rows) => array_map(fn ($x) => ['Keyword' => $x['keyword'], 'Type' => $x['kind'], 'Intent' => $x['intent'], 'Business value (by rule)' => $x['value'], 'Target page' => $x['target'], 'Google shows' => $x['ranking_page'], 'Impressions' => $x['impressions'], 'Clicks' => $x['clicks'], 'Position' => $x['position'], 'Previous position' => $x['prev_position'], 'Source' => $x['source'], 'Notes' => $x['notes']], $rows)]]));
        }
        if ($path === 'growth/revenue' && $get) {
            $days = in_array((int) $r->query('days', 30), [7, 30, 90], true) ? (int) $r->query('days', 30) : 30;
            $now = now()->tz(Growth::TZ);

            return $ok(['days' => $days, 'kpis' => GrowthOps::kpis($days), 'funnel' => GrowthOps::funnel($now->copy()->subDays($days)->startOfDay(), $now), 'diagnosis' => $r->query('fresh') ? GrowthOps::diagnose($days) : (Settings::get('growthDiagnosis', []) ?: GrowthOps::diagnose($days)),
                'landing' => SeoOps::landingPages($days), 'channels' => GrowthOps::CHANNELS, 'metrics' => GrowthOps::METRICS, 'lists' => \App\Services\Outreach::LISTS,
                'emailCampaigns' => DB::table('email_campaigns')->orderByDesc('created_at')->limit(50)->get(['id', 'name', 'status'])]);
        }
        if ($path === 'growth/campaign-plans' && $get) {
            return $ok(['rows' => GrowthOps::campaigns()]);
        }
        if ($path === 'growth/experiments' && $get) {
            return $ok(['rows' => GrowthOps::experiments()]);
        }
        abort_unless($r->isMethod('POST'), 405);
        if ($path === 'growth/agents') {
            $v = $r->validate(['agent' => 'required|in:seo,growth', 'auto' => 'required|boolean']);
            if ($v['agent'] === 'seo') {
                Settings::put('seoAgent', ['auto' => $v['auto']] + SeoAgent::settings());
            } else {
                Settings::put('growthOps', ['auto' => $v['auto']] + GrowthOps::settings());
            }
            Settings::audit($r->user()->id, 'agent_'.($v['auto'] ? 'resumed' : 'paused'), $v['agent']);

            return $ok(['status' => $v['auto'] ? 'running' : 'paused']);
        }
        if ($path === 'growth/seo/scan') {
            $out = AgentLog::run('seo', 'scan', fn () => SeoOps::scan(min(60, (int) $r->input('limit', 40))));
            abort_if(isset($out['error']), 422, $out['error'] ?? '');

            return $ok($out);
        }
        if ($path === 'growth/seo/inspect') {
            abort_unless(\App\Services\SearchConsole::ready(), 422, 'Connect Search Console first: Owner console → Integrations → Analytics.');
            $out = AgentLog::run('seo', 'inspect', fn () => SeoOps::inspect());
            abort_if(isset($out['error']), 422, $out['error'] ?? '');

            return $ok($out);
        }
        if ($path === 'growth/seo/keyword') {
            $v = $r->validate(['keyword' => 'required|string|min:2|max:190', 'target' => ['nullable', 'regex:#^/[a-zA-Z0-9/_.-]*$#', 'max:255'], 'notes' => 'nullable|string|max:300']);
            $k = mb_strtolower(trim($v['keyword']));
            abort_if(SeoAgent::branded($k), 422, 'Keywords with other brands\' names are left out (your rule).');
            $row = ['target' => (string) ($v['target'] ?? ''), 'notes' => (string) ($v['notes'] ?? ''), 'updated_at' => now()];
            if (DB::table('seo_keywords')->where('keyword', $k)->exists()) {
                DB::table('seo_keywords')->where('keyword', $k)->update($row);
            } else {
                DB::table('seo_keywords')->insert($row + ['keyword' => $k, 'source' => 'owner', 'created_at' => now()] + SeoOps::classify($k));
            }
            Settings::audit($r->user()->id, 'seo_keyword_saved', $k);

            return $ok();
        }
        if ($path === 'growth/diagnose') {
            $out = AgentLog::run('growth', 'diagnose', function () {
                $d = GrowthOps::diagnose();
                Settings::put('growthDiagnosis', ['at' => now()->toIso8601String(), 'findings' => $d['findings'], 'biggestDrop' => $d['biggestDrop']]);

                return ['summary' => count($d['findings']).' finding(s).'];
            });

            return $ok($out);
        }
        if ($path === 'growth/campaign-plan') {
            $v = $r->validate(['id' => ['nullable', 'regex:/^[a-f0-9-]{36}$/'], 'name' => 'required|string|max:120', 'channel' => 'required|in:'.implode(',', array_keys(GrowthOps::CHANNELS)), 'status' => 'required|in:planned,live,done,stopped',
                'audience' => 'nullable|string|max:300', 'problem' => 'nullable|string|max:500', 'offer' => 'nullable|string|max:300', 'message' => 'nullable|string|max:5000', 'landing' => ['nullable', 'regex:#^/[a-zA-Z0-9/_.-]*$#', 'max:200'],
                'utm' => ['nullable', 'regex:/^[a-zA-Z0-9_.-]{1,60}$/'], 'cta' => 'nullable|string|max:120', 'budget_inr' => 'nullable|integer|min:0|max:100000000', 'cost_inr' => 'nullable|integer|min:0|max:100000000',
                'expected' => 'nullable|string|max:300', 'consent' => 'nullable|string|max:300', 'email_campaign_id' => ['nullable', 'regex:/^[a-f0-9-]{36}$/'], 'outreach_list' => 'nullable|in:'.implode(',', array_keys(\App\Services\Outreach::LISTS)),
                'start_date' => 'nullable|date', 'end_date' => 'nullable|date|after_or_equal:start_date', 'result_note' => 'nullable|string|max:500']);
            abort_if(in_array($v['channel'], ['email', 'outreach', 'linkedin'], true) && trim((string) ($v['consent'] ?? '')) === '', 422, 'Say how permission to contact this audience was obtained (for example "signed up and ticked tips", or "college placement office asked for it"). Outreach only goes to contacts whose permission is established.');
            $v['budget_inr'] = (int) ($v['budget_inr'] ?? 0);
            $v['cost_inr'] = (int) ($v['cost_inr'] ?? 0);
            $id = GrowthOps::saveCampaign($v, $v['id'] ?? null);
            Settings::audit($r->user()->id, 'growth_campaign_saved', $v['name']);

            return $ok(['id' => $id]);
        }
        if ($path === 'growth/experiment') {
            $v = $r->validate(['id' => ['nullable', 'regex:/^[a-f0-9-]{36}$/'], 'title' => 'required|string|max:160', 'hypothesis' => 'required|string|max:600', 'segment' => 'nullable|string|max:200', 'metric' => 'required|in:'.implode(',', array_keys(GrowthOps::METRICS)),
                'change' => 'nullable|string|max:600', 'budget_inr' => 'nullable|integer|min:0|max:100000000', 'start_date' => 'required|date', 'end_date' => 'required|date|after_or_equal:start_date', 'decision' => 'nullable|in:continue,improve,stop', 'notes' => 'nullable|string|max:600']);
            $v['budget_inr'] = (int) ($v['budget_inr'] ?? 0);
            $id = GrowthOps::saveExperiment($v, $v['id'] ?? null);
            Settings::audit($r->user()->id, 'growth_experiment_saved', $v['title'].($v['decision'] ?? '' ? ' → '.$v['decision'] : ''));

            return $ok(['id' => $id]);
        }
        if ($path === 'growth/report-now') {
            $v = $r->validate(['slot' => 'required|in:weekly,'.implode(',', array_keys(Growth::SLOTS))]);
            $id = Growth::sendReport($v['slot'], null, true);
            Settings::audit($r->user()->id, 'growth_report_sent', $v['slot']);

            return $ok(['id' => $id]);
        }
        if ($path === 'growth/seo/decide') {
            $v = $r->validate(['id' => ['required', 'regex:/^[a-f0-9-]{36}$/'], 'action' => 'required|in:approve,reject,undo']);
            $p = SeoAgent::decide($v['id'], $v['action']);
            Settings::audit($r->user()->id, 'seo_change_'.$v['action'], $p->kind.' '.$p->path);

            return $ok(['status' => $p->status]);
        }
        if ($path === 'growth/seo/run') {
            $pulled = SeoAgent::pull();
            abort_unless($pulled['ready'], 422, 'Connect Search Console first: Owner console → Integrations → Analytics (property and service-account key).');
            $made = SeoAgent::propose();
            Settings::audit($r->user()->id, 'seo_agent_run', json_encode($made));

            return $ok($pulled + $made);
        }
        if ($path === 'growth/seo/settings') {
            $v = $r->validate(['auto' => 'required|boolean', 'metaPerDay' => 'required|integer|min:0|max:50', 'linksPerDay' => 'required|integer|min:0|max:50', 'minImpressions' => 'required|integer|min:1|max:10000']);
            Settings::put('seoAgent', $v);
            Settings::audit($r->user()->id, 'seo_agent_settings', json_encode($v));

            return $ok(['settings' => SeoAgent::settings()]);
        }
        if ($path === 'growth/settings') {
            AccountSecurity::requireRecent($r);
            $v = $r->validate([
                'budgetInr' => 'required|integer|min:0|max:500000', 'stretchPct' => 'required|integer|min:100|max:500',
                'avgPaise' => 'required|integer|min:100|max:10000000', 'reports' => 'required|boolean', 'reportEvery' => 'sometimes|in:weekly,daily', 'reportEmail' => 'nullable|email|max:190',
                'usdInr' => 'required|numeric|min:1|max:1000', 'inputUsdPerM' => 'required|numeric|min:0|max:1000', 'outputUsdPerM' => 'required|numeric|min:0|max:1000',
                'ladder' => 'required|array', 'ladder.*' => 'integer|min:0|max:10000000',
            ]);
            $v['ladder'] = array_intersect_key($v['ladder'], Growth::LADDER);
            $v['reportEmail'] = (string) ($v['reportEmail'] ?? '');
            $v['reportEvery'] ??= Growth::settings()['reportEvery'];
            Settings::put('growth', $v);
            Settings::audit($r->user()->id, 'growth_settings_saved', 'cap ₹'.$v['budgetInr']);

            return $ok(['settings' => Growth::settings()]);
        }
        abort(404);
    }
}
