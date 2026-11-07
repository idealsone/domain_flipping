<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * SEO page record and audit history (3.44.0). Every scanned page is scored against the technical SEO checklist (spec 10.5)
 * from what the last scan read, the site-wide link graph, robots.txt and the sitemap. Each result is kept with its date,
 * so a page has an audit history and the effect of a fix can be seen. Nothing here changes a page: it measures.
 *
 * Core Web Vitals come only from the Chrome UX Report (real Chrome visitors). Without its key, or without enough visitors,
 * they are shown as "not tracked", never as a made-up number.
 */
class SeoAudit
{
    /** key => [label, weight]. Weight 3 = can stop the page from being indexed at all. */
    public const CHECKS = [
        'http' => ['Answers with HTTP 200', 3], 'redirect' => ['No redirect chain', 3], 'https' => ['Served over HTTPS', 2], 'canonical' => ['Canonical points to itself', 3],
        'robots_txt' => ['Allowed by robots.txt', 3], 'meta_robots' => ['Not marked noindex', 3], 'sitemap' => ['Listed in the XML sitemap', 2], 'crawlable' => ['Crawlable (all of the above)', 2],
        'title' => ['Title present, 20 to 65 characters', 2], 'description' => ['Meta description 70 to 170 characters', 2], 'h1' => ['Exactly one H1 heading', 2], 'outline' => ['H2 sections that structure the page', 1],
        'duplicate' => ['Title and description not used by another page', 2], 'internal_links' => ['Links to at least 3 other pages', 2], 'links_in' => ['Linked from other pages (not an orphan)', 2],
        'broken_links' => ['No links to broken pages', 2], 'structured_data' => ['Structured data present', 1], 'breadcrumbs' => ['Breadcrumbs', 1], 'mobile' => ['Mobile viewport set', 2],
        'images' => ['Every image has ALT text', 1], 'js_content' => ['Main content readable without JavaScript', 2], 'hreflang' => ['Language alternates valid', 1],
        'pagination' => ['Pagination links consistent', 1], 'cwv' => ['Core Web Vitals (real Chrome visitors)', 2],
    ];

    public const PAGE_TYPES = ['home' => 'Home', 'product' => 'Product page', 'templates' => 'Templates', 'examples' => 'Examples', 'guide' => 'Guide or article', 'resource' => 'Career resource',
        'landing' => 'Landing page', 'company' => 'Company page', 'legal' => 'Legal page', 'other' => 'Other'];

    public const INTENTS = ['informational', 'commercial', 'transactional', 'navigational'];

    public const CONTENT_STATUSES = ['published' => 'Published', 'needs_update' => 'Needs update', 'in_review' => 'In editorial review', 'draft' => 'Draft', 'retired' => 'Retired'];

    public const AGENTS = ['seo' => 'SEO agent', 'content' => 'Content team', 'none' => 'Nobody'];

    /** How old a stored audit may be before the same result is written again (so history stays readable). */
    private const KEEP_DAYS = 7;

    public static function ready(): bool
    {
        return Schema::hasTable('seo_page_audits') && Schema::hasColumn('seo_pages', 'page_type');
    }

    public static function pageType(string $path): string
    {
        return match (true) {
            $path === '/' => 'home',
            in_array($path, ['/resume-builder/', '/ats-checker/', '/cover-letter-generator/', '/resume-builder/india/'], true) => 'product',
            $path === '/templates.html' || str_starts_with($path, '/resume-templates/') => 'templates',
            str_starts_with($path, '/resume-examples/') || str_starts_with($path, '/cover-letter-examples/') || $path === '/cover-letters.html' => str_contains($path, 'how-to-write') ? 'guide' : 'examples',
            str_starts_with($path, '/guides') || str_starts_with($path, '/blog') => 'guide',
            str_starts_with($path, '/interview-questions/') || str_starts_with($path, '/resume-words/') => 'resource',
            (bool) preg_match('#^/(privacy|terms|refund|cookie|disclaimer)#', $path) => 'legal',
            (bool) preg_match('#^/(about|contact|team|careers)#', $path) => 'company',
            default => 'landing',
        };
    }

    /** What the whole run needs once: link graph, duplicates, sitemap, robots.txt and the broken pages. */
    private static function context(): array
    {
        $site = SeoOps::siteFindings();
        $dup = [];
        foreach ($site['duplicateTitles'] as $d) {
            foreach ($d['pages'] as $p) {
                $dup[$p][] = 'same title as '.($d['count'] - 1).' other page(s)';
            }
        }
        foreach ($site['duplicateDescriptions'] as $d) {
            foreach ($d['pages'] as $p) {
                $dup[$p][] = 'same description as '.($d['count'] - 1).' other page(s)';
            }
        }
        $robots = null;
        try {
            $res = Http::timeout(10)->withHeaders(['User-Agent' => 'CVTara-site-check/1.0'])->get(FastIndex::origin().'/robots.txt');
            if ($res->successful()) {
                $robots = AiVisibility::parseRobots((string) $res->body());
            }
        } catch (\Throwable) {
            // unknown: the robots.txt check is skipped, not passed
        }

        return ['site' => $site, 'dup' => $dup, 'sitemap' => FastIndex::pages(), 'robots' => $robots, 'cwvKey' => self::cruxKey() !== '',
            'broken' => DB::table('seo_pages')->whereNotNull('scanned_at')->where(fn ($q) => $q->where('http_status', '>=', 400)->orWhere('http_status', 0))->pluck('http_status', 'path')->all()];
    }

    /** The checklist for one page row. Each item: [key, label, result pass|warn|fail|na, detail]. */
    public static function checks(object $r, array $ctx): array
    {
        $issues = (array) json_decode((string) $r->issues_json, true);
        $sig = (array) json_decode((string) ($r->signals_json ?? ''), true);
        $links = (array) json_decode((string) $r->links_json, true);
        $schema = (array) json_decode((string) $r->schema_json, true);
        $outline = (array) json_decode((string) ($r->outline_json ?? ''), true);
        $status = (int) $r->http_status;
        $ok = $status === 200;
        $c = [];
        $add = function (string $k, string $res, string $detail = '') use (&$c) {
            $c[] = ['key' => $k, 'label' => self::CHECKS[$k][0], 'result' => $res, 'detail' => $detail];
        };
        $add('http', $ok ? 'pass' : 'fail', $ok ? '' : ($status ? 'HTTP '.$status : 'Could not be fetched'));
        $add('redirect', $status >= 300 && $status < 400 ? 'fail' : 'pass', $issues['redirect'] ?? '');
        if (! $ok) { // nothing else can be read from a page that did not answer
            foreach (array_keys(self::CHECKS) as $k) {
                if (! in_array($k, ['http', 'redirect'], true)) {
                    $add($k, 'na', 'Page did not answer');
                }
            }

            return $c;
        }
        $https = str_starts_with(FastIndex::origin(), 'https://') || ($sig['https'] ?? false);
        $add('https', $https ? 'pass' : 'fail', $https ? '' : 'Canonical and site address are not https');
        $add('canonical', isset($issues['canonical_missing']) ? 'warn' : (isset($issues['canonical_other']) ? 'fail' : 'pass'), $issues['canonical_other'] ?? $issues['canonical_missing'] ?? '');
        $blocked = $ctx['robots'] === null ? null : ! AiVisibility::allowed($ctx['robots'], 'googlebot', $r->path);
        $add('robots_txt', $blocked === null ? 'na' : ($blocked ? 'fail' : 'pass'), $blocked === null ? 'robots.txt could not be read' : ($blocked ? 'Googlebot is blocked from this address' : ''));
        $noindex = str_contains(mb_strtolower((string) $r->robots), 'noindex');
        $add('meta_robots', $noindex ? 'fail' : 'pass', $noindex ? 'robots says: '.$r->robots : '');
        $inMap = isset($ctx['sitemap'][$r->path]);
        $add('sitemap', $inMap ? 'pass' : 'warn', $inMap ? '' : 'Not in sitemap.xml');
        $add('crawlable', ! $noindex && $blocked !== true ? 'pass' : 'fail', $noindex || $blocked ? 'Search engines cannot index it as it is' : '');
        $add('title', isset($issues['title_missing']) ? 'fail' : (isset($issues['title_long']) || isset($issues['title_short']) ? 'warn' : 'pass'), $issues['title_missing'] ?? $issues['title_long'] ?? $issues['title_short'] ?? '');
        $add('description', isset($issues['description_missing']) ? 'fail' : (isset($issues['description_long']) || isset($issues['description_short']) ? 'warn' : 'pass'), $issues['description_missing'] ?? $issues['description_long'] ?? $issues['description_short'] ?? '');
        $add('h1', isset($issues['h1_missing']) ? 'fail' : (isset($issues['h1_multiple']) ? 'warn' : 'pass'), $issues['h1_missing'] ?? $issues['h1_multiple'] ?? '');
        $h2 = count(array_filter($outline, fn ($h) => ($h[0] ?? '') === 'h2'));
        $add('outline', $r->outline_json === null ? 'na' : ($h2 >= 2 || (int) $r->words < 300 ? 'pass' : 'warn'), $r->outline_json === null ? 'Scan again to read headings' : ($h2 >= 2 || (int) $r->words < 300 ? '' : 'Long page with '.$h2.' H2 heading(s)'));
        $d = $ctx['dup'][$r->path] ?? [];
        $add('duplicate', $d ? 'fail' : 'pass', implode('; ', $d));
        $out = count(array_filter($links, fn ($l) => $l !== $r->path));
        $add('internal_links', $out >= 3 ? 'pass' : 'warn', $out >= 3 ? '' : $out.' internal link(s) out');
        $orph = $ctx['site']['orphans'];
        $in = (int) ($ctx['site']['inbound'][$r->path] ?? 0);
        $add('links_in', $orph === null || $r->path === '/' ? 'na' : ($in === 0 ? 'fail' : ($in === 1 ? 'warn' : 'pass')), $orph === null ? (string) $ctx['site']['note'] : ($in === 0 ? 'No other page links here' : ($in === 1 ? 'Only one page links here' : $in.' pages link here')));
        $bad = array_values(array_filter($links, fn ($l) => isset($ctx['broken'][$l])));
        $add('broken_links', $bad ? 'fail' : 'pass', $bad ? 'Links to '.implode(', ', array_slice($bad, 0, 5)).(count($bad) > 5 ? ' and '.(count($bad) - 5).' more' : '') : '');
        $add('structured_data', $schema ? 'pass' : 'warn', $schema ? implode(', ', array_slice($schema, 0, 6)) : 'No structured data');
        $needCrumbs = ! in_array($r->page_type ?? '', ['home', 'legal', 'company'], true) && substr_count(trim($r->path, '/'), '/') >= 1;
        $hasCrumbs = in_array('BreadcrumbList', $schema, true) || ($sig['breadcrumbNav'] ?? false);
        $add('breadcrumbs', $hasCrumbs ? 'pass' : ($needCrumbs ? 'warn' : 'na'), $hasCrumbs ? '' : ($needCrumbs ? 'Nested page without breadcrumbs' : 'Top-level page'));
        $add('mobile', ! $sig ? 'na' : (($sig['viewport'] ?? false) ? 'pass' : 'fail'), ! $sig ? 'Scan again to check' : (($sig['viewport'] ?? false) ? '' : 'No viewport meta tag'));
        $img = (int) ($r->images ?? 0);
        $noAlt = (int) ($r->images_no_alt ?? 0);
        $add('images', $img === 0 ? 'na' : ($noAlt === 0 ? 'pass' : 'warn'), $img === 0 ? 'No images' : ($noAlt ? $noAlt.' of '.$img.' image(s) without ALT text' : $img.' image(s), all with ALT text'));
        $add('js_content', isset($issues['thin']) ? 'warn' : 'pass', isset($issues['thin']) ? $issues['thin'].' in the HTML as served' : (int) $r->words.' words in the HTML as served');
        $add('hreflang', ($sig['hreflang'] ?? 0) > 0 ? 'pass' : 'na', ($sig['hreflang'] ?? 0) > 0 ? $sig['hreflang'].' alternate(s)' : 'Single-language site');
        $add('pagination', ($sig['pagination'] ?? 0) > 0 ? 'pass' : 'na', ($sig['pagination'] ?? 0) > 0 ? '' : 'Not a paginated list');
        $cwv = (array) json_decode((string) ($r->cwv_json ?? ''), true);
        if (! $ctx['cwvKey']) {
            $add('cwv', 'na', 'Not tracked: add a Chrome UX Report key in Integrations › Analytics');
        } elseif (($cwv['status'] ?? '') !== 'ok') {
            $add('cwv', 'na', ($cwv['status'] ?? '') === 'no_data' ? 'Not tracked: not enough Chrome visitors yet' : 'Not measured yet');
        } else {
            $worst = in_array('poor', $cwv['rating'], true) ? 'fail' : (in_array('needs_improvement', $cwv['rating'], true) ? 'warn' : 'pass');
            $add('cwv', $worst, 'LCP '.round($cwv['lcp'] / 1000, 1).' s · INP '.$cwv['inp'].' ms · CLS '.$cwv['cls']);
        }

        return $c;
    }

    /** 0-100 from the checks that apply; a failed weight-3 check always means "poor". */
    public static function score(array $checks): array
    {
        $got = 0.0;
        $max = 0;
        $critical = false;
        foreach ($checks as $c) {
            if ($c['result'] === 'na') {
                continue;
            }
            $w = self::CHECKS[$c['key']][1];
            $max += $w;
            $got += $c['result'] === 'pass' ? $w : ($c['result'] === 'warn' ? $w / 2 : 0);
            $critical = $critical || ($c['result'] === 'fail' && $w === 3);
        }
        $score = $max ? (int) round($got / $max * 100) : 0;

        return [$score, $critical || $score < 60 ? 'poor' : ($score < 85 ? 'needs_work' : 'good')];
    }

    /**
     * Audits pages (all scanned pages when $paths is null) and stores the result. History gets a new row when the result
     * changed or the last one is a week old. Returns a summary for the agent log.
     */
    public static function run(?array $paths = null, string $source = 'scan', ?int $by = null): array
    {
        if (! self::ready()) {
            return ['skipped' => true, 'summary' => 'Waiting for the 3.44.0 database update.'];
        }
        $ctx = self::context();
        $q = DB::table('seo_pages')->whereNotNull('scanned_at');
        if ($paths !== null) {
            $q->whereIn('path', $paths ?: ['-']);
        }
        $n = $written = 0;
        $poor = [];
        $kw = self::keywordMap();
        foreach ($q->get() as $r) {
            $checks = self::checks($r, $ctx);
            [$score, $status] = self::score($checks);
            $failed = count(array_filter($checks, fn ($c) => $c['result'] === 'fail'));
            $warn = count(array_filter($checks, fn ($c) => $c['result'] === 'warn'));
            $sig = implode(',', array_map(fn ($c) => $c['key'].':'.$c['result'], $checks));
            $last = DB::table('seo_page_audits')->where('path', $r->path)->orderByDesc('id')->first(['score', 'checks_json', 'created_at']);
            $lastSig = $last ? implode(',', array_map(fn ($c) => $c['key'].':'.$c['result'], (array) json_decode((string) $last->checks_json, true))) : null;
            if (! $last || $source === 'manual' || $lastSig !== $sig || strtotime((string) $last->created_at) < time() - self::KEEP_DAYS * 86400) {
                DB::table('seo_page_audits')->insert(['path' => $r->path, 'score' => $score, 'status' => $status, 'failed' => $failed, 'warnings' => $warn, 'checks_json' => json_encode($checks),
                    'source' => $source, 'by' => $by, 'created_at' => now()]);
                $written++;
            }
            $set = ['audit_score' => $score, 'audit_status' => $status, 'audited_at' => now(), 'links_in' => min(65535, (int) ($ctx['site']['inbound'][$r->path] ?? 0))];
            if ($r->page_type === '' && $r->record_by === null) {
                $set['page_type'] = self::pageType($r->path);
            }
            if ($r->primary_keyword === '' && $r->record_by === null && isset($kw[$r->path])) { // never after a person saved the record
                $set['primary_keyword'] = mb_substr($kw[$r->path], 0, 190);
                $set['intent'] = $r->intent !== '' ? $r->intent : SeoOps::classify($kw[$r->path])['intent'];
            }
            DB::table('seo_pages')->where('path', $r->path)->update($set);
            $n++;
            if ($status === 'poor') {
                $poor[] = $r->path;
            }
        }

        return ['summary' => "Audited $n page(s)".($poor ? ', '.count($poor).' poor (for example '.$poor[0].')' : '').'.', 'audited' => $n, 'written' => $written, 'poor' => count($poor)];
    }

    /** Keyword per page: the owner's target first, otherwise the search that brings the most impressions to it. */
    private static function keywordMap(): array
    {
        if (! Schema::hasTable('seo_keywords')) {
            return [];
        }
        $m = [];
        foreach (DB::table('seo_keywords')->where('target', '!=', '')->whereNotNull('target')->orderByDesc('impressions')->get(['keyword', 'target']) as $k) {
            $m[$k->target] ??= $k->keyword;
        }
        foreach (DB::table('seo_keywords')->where('ranking_page', '!=', '')->whereNotNull('ranking_page')->where('kind', '!=', 'brand')->orderByDesc('impressions')->get(['keyword', 'ranking_page']) as $k) {
            $m[$k->ranking_page] ??= $k->keyword;
        }

        return $m;
    }

    /** Lists the owner asked for: orphan pages, pages linked but missing from the sitemap, sitemap pages marked noindex, broken links. */
    public static function lists(): array
    {
        $site = SeoOps::siteFindings();
        $map = FastIndex::pages();
        $rows = DB::table('seo_pages')->whereNotNull('scanned_at')->get(['path', 'robots', 'http_status', 'links_json']);
        $broken = $rows->filter(fn ($r) => (int) $r->http_status >= 400 || (int) $r->http_status === 0)->pluck('http_status', 'path')->all();
        $from = [];
        foreach ($rows as $r) {
            foreach ((array) json_decode((string) $r->links_json, true) as $l) {
                if (isset($broken[$l]) && count($from[$l] ?? []) < 10) {
                    $from[$l][] = $r->path;
                }
            }
        }

        return ['orphans' => $site['orphans'], 'orphanNote' => $site['note'], 'notInSitemap' => $site['linkedButNotInSitemap'],
            'noindexInSitemap' => $rows->filter(fn ($r) => isset($map[$r->path]) && str_contains(mb_strtolower((string) $r->robots), 'noindex'))->pluck('path')->values()->all(),
            'broken' => array_map(fn ($p, $s) => ['path' => $p, 'status' => (int) $s, 'linkedFrom' => $from[$p] ?? []], array_keys($broken), $broken),
            'scanned' => $site['scanned'], 'pages' => $site['pages']];
    }

    /** One page: its record, the latest checklist and the audit history (newest first). */
    public static function page(string $path): array
    {
        $p = DB::table('seo_pages')->where('path', $path)->first();
        if (! $p || ! self::ready()) {
            return ['record' => null, 'checks' => [], 'audits' => []];
        }
        $a = DB::table('seo_page_audits')->where('path', $path)->orderByDesc('id')->limit(40)->get();

        return ['record' => ['pageType' => $p->page_type ?: self::pageType($path), 'primaryKeyword' => $p->primary_keyword, 'secondaryKeywords' => (array) json_decode((string) $p->secondary_keywords, true),
            'intent' => $p->intent, 'outline' => (array) json_decode((string) $p->outline_json, true), 'images' => (int) $p->images, 'imagesNoAlt' => (int) $p->images_no_alt,
            'altCoverage' => (int) $p->images ? (int) round(((int) $p->images - (int) $p->images_no_alt) / (int) $p->images * 100) : null, 'linksIn' => (int) $p->links_in, 'cta' => $p->cta,
            'contentStatus' => $p->content_status, 'auditScore' => $p->audit_score === null ? null : (int) $p->audit_score, 'auditStatus' => $p->audit_status, 'auditedAt' => $p->audited_at,
            'assignedAgent' => $p->assigned_agent, 'note' => $p->record_note, 'editedBy' => $p->record_by ? DB::table('users')->where('id', $p->record_by)->value('name') : null, 'editedAt' => $p->record_at,
            'cwv' => json_decode((string) $p->cwv_json, true), 'cwvAt' => $p->cwv_at],
            'checks' => $a->first() ? json_decode((string) $a->first()->checks_json, true) : [],
            'audits' => $a->map(fn ($x) => ['id' => $x->id, 'at' => $x->created_at, 'score' => (int) $x->score, 'status' => $x->status, 'failed' => (int) $x->failed, 'warnings' => (int) $x->warnings, 'source' => $x->source])->all(),
            'options' => ['pageTypes' => self::PAGE_TYPES, 'intents' => self::INTENTS, 'contentStatuses' => self::CONTENT_STATUSES, 'agents' => self::AGENTS]];
    }

    /** Saves the parts of the record a person decides (the rest is read by the scan). */
    public static function saveRecord(User $u, string $path, array $v): array
    {
        abort_unless(self::ready(), 422, 'Waiting for the 3.44.0 database update.');
        $old = DB::table('seo_pages')->where('path', $path)->first();
        abort_unless($old, 404, 'That page has not been scanned yet.');
        $sec = array_values(array_unique(array_filter(array_map(fn ($s) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $s)), 0, 120), (array) ($v['secondaryKeywords'] ?? [])))));
        $set = ['page_type' => $v['pageType'], 'primary_keyword' => mb_substr(trim((string) ($v['primaryKeyword'] ?? '')), 0, 190), 'secondary_keywords' => json_encode(array_slice($sec, 0, 10)),
            'intent' => (string) ($v['intent'] ?? ''), 'content_status' => $v['contentStatus'], 'assigned_agent' => $v['assignedAgent'], 'record_note' => mb_substr(trim((string) ($v['note'] ?? '')), 0, 500),
            'record_by' => $u->id, 'record_at' => now()];
        DB::table('seo_pages')->where('path', $path)->update($set);
        Audit::record($u, 'seo_page_record', $path, ['pageType' => $old->page_type, 'primaryKeyword' => $old->primary_keyword, 'contentStatus' => $old->content_status, 'assignedAgent' => $old->assigned_agent],
            ['pageType' => $set['page_type'], 'primaryKeyword' => $set['primary_keyword'], 'contentStatus' => $set['content_status'], 'assignedAgent' => $set['assigned_agent']], '', 200, request());

        return self::page($path);
    }

    // ---------------------------------------------------------------- Core Web Vitals (Chrome UX Report)

    public static function cruxKey(): string
    {
        return trim((string) (Settings::providers()['analytics']['cruxApiKey'] ?? ''));
    }

    private static function rate(float $v, float $good, float $poor): string
    {
        return $v <= $good ? 'good' : ($v <= $poor ? 'needs_improvement' : 'poor');
    }

    /** Fetches field data for the important pages not measured this week (at most $limit calls a run). */
    public static function cwv(int $limit = 20): array
    {
        $key = self::cruxKey();
        if ($key === '' || ! self::ready()) {
            return ['skipped' => true, 'summary' => 'Core Web Vitals are not tracked: no Chrome UX Report key.'];
        }
        $paths = DB::table('seo_pages')->where('important', true)->where('http_status', 200)->where(fn ($q) => $q->whereNull('cwv_at')->orWhere('cwv_at', '<', now()->subDays(7)))
            ->orderBy('cwv_at')->limit($limit)->pluck('path');
        $ok = $none = 0;
        $site = rtrim(FastIndex::origin(), '/');
        foreach ($paths as $path) {
            try {
                $res = Http::timeout(12)->post(SearchConsole::base('https://chromeuxreport.googleapis.com').'/v1/records:queryRecord?key='.rawurlencode($key), ['url' => $site.$path, 'formFactor' => 'PHONE']);
            } catch (\Throwable $e) {
                return ['error' => 'Chrome UX Report could not be reached: '.mb_substr($e->getMessage(), 0, 120), 'summary' => "Measured $ok page(s) before an error."];
            }
            if ($res->status() === 404) {
                $data = ['status' => 'no_data'];
                $none++;
            } elseif ($res->successful()) {
                $m = (array) ($res->json('record.metrics') ?? []);
                $lcp = (float) ($m['largest_contentful_paint']['percentiles']['p75'] ?? 0);
                $inp = (float) ($m['interaction_to_next_paint']['percentiles']['p75'] ?? 0);
                $cls = (float) ($m['cumulative_layout_shift']['percentiles']['p75'] ?? 0);
                $data = ['status' => 'ok', 'lcp' => (int) $lcp, 'inp' => (int) $inp, 'cls' => round($cls, 2), 'formFactor' => 'phone',
                    'rating' => ['lcp' => self::rate($lcp, 2500, 4000), 'inp' => self::rate($inp, 200, 500), 'cls' => self::rate($cls, 0.1, 0.25)]];
                $ok++;
            } else {
                return ['error' => 'Chrome UX Report refused the request (HTTP '.$res->status().'): '.mb_substr((string) $res->json('error.message', ''), 0, 120), 'summary' => "Measured $ok page(s) before an error."];
            }
            DB::table('seo_pages')->where('path', $path)->update(['cwv_json' => json_encode($data), 'cwv_at' => now()]);
        }

        return ['summary' => "Core Web Vitals: $ok page(s) measured, $none without enough Chrome visitors.", 'measured' => $ok, 'noData' => $none];
    }
}
