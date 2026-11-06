<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The SEO agent's working parts (3.34.0): a page-by-page inventory of the site with a technical scan of each page,
 * Google's own index status per page (URL Inspection), sitemap processing status, a keyword database, brand-search
 * visibility, topic clusters, the result of every approved change, and the agent's KPIs.
 *
 * Google decides crawling, indexing, ranking and sitelinks. The agent finds problems, asks search engines to look again
 * only when a page has changed, and measures; it never resubmits an unchanged page and never invents a number.
 */
class SeoOps
{
    /** The pages that matter most for search and for the brand result (Google may choose some of them as sitelinks). */
    public const IMPORTANT = ['/', '/resume-builder/', '/ats-checker/', '/cover-letter-generator/', '/templates.html', '/resume-templates/', '/resume-examples/', '/cover-letter-examples/', '/cover-letter-examples/how-to-write/',
        '/cover-letters.html', '/guides.html', '/blog.html', '/interview-questions/', '/resume-words/', '/resume-builder/india/', '/about.html', '/contact.html'];

    /** The brand result the owner wants: main pages Google may show under the CVTara result. null = no page for it yet. */
    public const SITELINKS = ['Resume Builder' => '/resume-builder/', 'ATS Resume Checker' => '/ats-checker/', 'Resume vs JD Matcher' => null, 'Cover Letter Generator' => '/cover-letter-generator/',
        'Resume Templates' => '/resume-templates/', 'Career Resources' => '/guides.html', 'Pricing and Plans' => null, 'About CVTara' => '/about.html', 'Contact and Support' => '/contact.html'];

    /** Topic clusters: a main product page and the guides that support it. Missing topics are suggestions for the owner, never auto-published. */
    public const CLUSTERS = [
        'Resume Builder' => ['main' => '/resume-builder/', 'support' => ['/resume-examples/', '/resume-templates/', '/guides/resume/no-experience-format/', '/guides/resume/professional-summary-guide/', '/guides/resume/achievements-bullet-points/', '/guides/resume/action-verbs-list/', '/guides/resume/fresher-projects-guide/', '/guides/resume/gap-in-employment-explanation/'],
            'missing' => ['Resume format for experienced professionals', 'Common resume mistakes']],
        'ATS Resume Checker' => ['main' => '/ats-checker/', 'support' => ['/guides/resume/ats-friendly-format-india/', '/guides/resume/pdf-vs-word-ats/', '/guides/resume/skills-section-keywords/', '/resume-words/'],
            'missing' => ['What is an ATS?', 'How ATS resume scanning works', 'Common ATS resume errors']],
        'Cover Letter Generator' => ['main' => '/cover-letter-generator/', 'support' => ['/cover-letter-examples/how-to-write/', '/cover-letter-examples/', '/guides/cover-letter/short-email-format/', '/cover-letters.html'],
            'missing' => ['Cover letter for freshers', 'Resume versus cover letter']],
        'Resume vs Job Description' => ['main' => null, 'support' => ['/guides/resume/skills-section-keywords/'],
            'missing' => ['Resume vs job description matcher (product page)', 'How to match a resume with a job description', 'How to tailor a resume for a particular job']],
    ];

    /** Seed keywords from the owner's plan: research targets, not claims of search volume. */
    public const SEED = ['cvtara' => '/', 'cv tara' => '/', 'cvtara.com' => '/', 'cvtara resume builder' => '/resume-builder/', 'cvtara ats resume checker' => '/ats-checker/', 'cvtara career solutions' => '/', 'cvtara resume services' => '/',
        'online resume builder' => '/resume-builder/', 'free resume builder' => '/resume-builder/', 'ats resume checker' => '/ats-checker/', 'ats resume scanner' => '/ats-checker/', 'resume vs job description matcher' => '',
        'cover letter generator' => '/cover-letter-generator/', 'resume builder for freshers' => '/resume-builder/', 'professional resume builder' => '/resume-builder/', 'resume templates for experienced professionals' => '/resume-templates/'];

    public const DEFAULTS = ['scanPerRun' => 40, 'inspectPerRun' => 15, 'inspectPerDay' => 60, 'requestPerRun' => 10];

    private const BRAND = '/c\s*v\s*-?\s*tara|cvtara/i';

    private const PRODUCT = '/resume (builder|maker|creator)|cv (builder|maker|creator)|bio ?data maker|ats (resume )?(checker|scanner|score|test)|resume (checker|scanner|score|review)|cover letter (generator|maker|builder|writer)|resume (vs|and) (jd|job description)|job description match|resume match/i';

    private const QUESTION = '/^(how|what|why|which|when|where|who|can|should|is|are|does|do|will)\b|\?/i';

    private const COMMERCIAL = '/\b(best|free|online|download|pdf|format|sample|samples|template|templates|example|examples|top|maker)\b/i';

    public static function settings(): array
    {
        return array_replace(self::DEFAULTS, array_intersect_key((array) Settings::get('seoOps', []), self::DEFAULTS));
    }

    public static function brandQuery(string $q): bool
    {
        return (bool) preg_match(self::BRAND, $q);
    }

    private static function origin(): string
    {
        return FastIndex::origin();
    }

    /** Sitemap pages plus the important ones (path => true). */
    public static function inventory(): array
    {
        $all = array_fill_keys(array_keys(FastIndex::pages()), false);
        foreach (self::important() as $p) {
            $all[$p] = true;
        }

        return $all;
    }

    /** Important pages: the fixed list plus the 30 pages that earned the most Google clicks lately. */
    public static function important(): array
    {
        $top = DB::table('seo_track')->where('kind', 'page')->where('week', DB::table('seo_track')->where('kind', 'page')->max('week'))->where('clicks', '>', 0)->orderByDesc('clicks')->limit(30)->pluck('key')->all();

        return array_values(array_unique(array_merge(self::IMPORTANT, $top)));
    }

    // ---------------------------------------------------------------- technical scan

    /** Scans the pages most in need: important ones daily, the rest weekly, new ones first. */
    public static function scan(?int $limit = null): array
    {
        $limit ??= self::settings()['scanPerRun'];
        $inv = self::inventory();
        $known = DB::table('seo_pages')->pluck('scanned_at', 'path')->all();
        foreach (array_diff_key($known, $inv) as $gone => $_) {
            DB::table('seo_pages')->where('path', $gone)->where('important', false)->delete(); // left the sitemap
        }
        $due = [];
        foreach ($inv as $path => $imp) {
            $at = $known[$path] ?? null;
            $age = $at ? now()->diffInHours(Carbon::parse($at), true) : PHP_INT_MAX;
            if ($age >= ($imp ? 20 : 160)) {
                $due[$path] = ($imp ? 2 : 1) * 1e9 + min($age, 1e8);
            }
        }
        arsort($due);
        $done = 0;
        $resolved = [];
        foreach (array_slice(array_keys($due), 0, $limit) as $path) {
            $resolved = array_merge($resolved, self::scanOne($path, $inv[$path]));
            $done++;
        }
        if ($resolved) {
            Settings::put('seoResolved', array_slice(array_merge($resolved, (array) Settings::get('seoResolved', [])), 0, 400));
        }
        $left = max(0, count($due) - $done);

        return ['summary' => "Scanned $done page(s)".($left ? ", $left still due" : '').'.', 'scanned' => $done, 'due' => $left, 'skipped' => $done === 0];
    }

    /** Fetches one page as a visitor would and records what search engines read on it. Returns issues that went away. */
    public static function scanOne(string $path, bool $important = false): array
    {
        $old = DB::table('seo_pages')->where('path', $path)->first();
        $row = ['path' => $path, 'important' => $important, 'section' => self::section($path), 'scanned_at' => now(), 'updated_at' => now()];
        $issues = [];
        try {
            $res = Http::withoutRedirecting()->timeout(12)->withHeaders(['User-Agent' => 'CVTara-site-check/1.0', 'Accept' => 'text/html'])->get(self::origin().$path);
            $row['http_status'] = $res->status();
            if ($res->status() >= 300 && $res->status() < 400) {
                $issues['redirect'] = 'Redirects to '.mb_substr((string) $res->header('Location'), 0, 120).' (sitemap pages should answer directly)';
            } elseif ($res->status() !== 200) {
                $issues['status'] = 'Answers with HTTP '.$res->status();
            } else {
                $row += self::read((string) $res->body(), (string) $res->header('X-Robots-Tag'));
                $issues += self::issues($path, $row, $important);
            }
        } catch (\Throwable $e) {
            $row['http_status'] = 0;
            $issues['fetch'] = 'Could not be fetched: '.mb_substr($e->getMessage(), 0, 120);
        }
        $fp = substr(sha1(implode('|', [$row['title'] ?? '', $row['description'] ?? '', $row['h1'] ?? '', $row['canonical'] ?? '', $row['robots'] ?? '', intdiv((int) ($row['words'] ?? 0), 50), $row['http_status']])), 0, 40);
        $row['fingerprint'] = $fp;
        if (! $old || $old->fingerprint !== $fp) {
            $row['changed_at'] = now();
        }
        foreach (['links_json', 'schema_json'] as $k) {
            $row[$k] = json_encode($row[$k] ?? []);
        }
        $row['issues_json'] = json_encode($issues);
        if (! $old) {
            $row['created_at'] = now();
        }
        DB::table('seo_pages')->updateOrInsert(['path' => $path], $row);
        $gone = $old ? array_diff_key((array) json_decode((string) $old->issues_json, true), $issues) : [];

        return array_map(fn ($code) => ['path' => $path, 'issue' => $code, 'at' => now()->toIso8601String()], array_keys($gone));
    }

    private static function section(string $path): string
    {
        $s = explode('/', trim($path, '/'))[0] ?? '';

        return $s === '' ? 'home' : (str_ends_with($s, '.html') ? 'main pages' : $s);
    }

    /** Title, description, headings, canonical, robots, words, internal links and structured data from a page's HTML. */
    public static function read(string $html, string $xRobots = ''): array
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $x = new \DOMXPath($dom);
        $text = fn ($q) => trim(preg_replace('/\s+/u', ' ', (string) ($x->query($q)->item(0)?->textContent ?? '')));
        $attr = fn ($q, $a) => trim((string) ($x->query($q)->item(0)?->getAttribute($a) ?? ''));
        $h1s = $x->query('//h1');
        $links = [];
        $host = parse_url(self::origin(), PHP_URL_HOST);
        foreach ($x->query('//a[@href]') as $a) {
            $href = trim($a->getAttribute('href'));
            $u = parse_url($href);
            if ($u === false || (isset($u['host']) && $u['host'] !== $host && $u['host'] !== 'www.'.$host) || ! isset($u['path']) || ! str_starts_with($u['path'], '/')) {
                continue;
            }
            $links[$u['path']] = true;
        }
        $types = [];
        foreach ($x->query('//script[@type="application/ld+json"]') as $s) {
            $j = json_decode($s->textContent, true);
            $walk = function ($n) use (&$walk, &$types) {
                if (! is_array($n)) {
                    return;
                }
                if (isset($n['@type'])) {
                    foreach ((array) $n['@type'] as $t) {
                        $types[(string) $t] = true;
                    }
                }
                foreach ($n as $v) {
                    $walk($v);
                }
            };
            $walk($j);
        }
        foreach ($x->query('//script|//style|//noscript|//template') as $n) {
            $n->parentNode->removeChild($n);
        }
        $main = $x->query('//main')->item(0) ?? $x->query('//body')->item(0);
        $words = $main ? count(preg_split('/\s+/u', trim((string) $main->textContent), -1, PREG_SPLIT_NO_EMPTY)) : 0;
        $canon = $attr('//link[@rel="canonical"]', 'href');

        return ['title' => mb_substr($text('//title'), 0, 300), 'description' => mb_substr($attr('//meta[@name="description"]', 'content'), 0, 500),
            'h1' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($h1s->item(0)?->textContent ?? ''))), 0, 300), 'h1_count' => min(255, $h1s->length),
            'canonical' => mb_substr($canon, 0, 300), 'robots' => mb_substr(trim($attr('//meta[@name="robots"]', 'content').($xRobots !== '' ? ' '.$xRobots : '')), 0, 120),
            'words' => $words, 'links_json' => array_keys($links), 'schema_json' => array_keys($types)];
    }

    /** Plain-language issues on one page. Canonical and noindex problems are reported for the owner to decide, never changed by the agent. */
    private static function issues(string $path, array $r, bool $important): array
    {
        $i = [];
        $t = mb_strlen($r['title']);
        $d = mb_strlen($r['description']);
        if ($t === 0) {
            $i['title_missing'] = 'No page title';
        } elseif ($t > 65) {
            $i['title_long'] = "Title is $t characters; Google usually cuts it after about 60";
        } elseif ($t < 20) {
            $i['title_short'] = "Title is only $t characters";
        }
        if ($d === 0) {
            $i['description_missing'] = 'No meta description';
        } elseif ($d > 170) {
            $i['description_long'] = "Description is $d characters; Google usually shows about 155";
        } elseif ($d < 70) {
            $i['description_short'] = "Description is only $d characters";
        }
        if ($r['h1_count'] === 0) {
            $i['h1_missing'] = 'No H1 heading';
        } elseif ($r['h1_count'] > 1) {
            $i['h1_multiple'] = $r['h1_count'].' H1 headings (one is clearer)';
        }
        $cp = $r['canonical'] === '' ? null : (parse_url($r['canonical'], PHP_URL_PATH) ?: '/');
        if ($cp === null) {
            $i['canonical_missing'] = 'No canonical address';
        } elseif ($cp !== $path) {
            $i['canonical_other'] = 'Canonical points to '.$cp.', so Google may index that page instead';
        }
        if (str_contains(mb_strtolower($r['robots']), 'noindex')) {
            $i['noindex'] = 'Marked noindex but listed in the sitemap';
        }
        if ($r['words'] < 150 && ! in_array($path, ['/contact.html'], true)) {
            $i['thin'] = 'Only '.$r['words'].' words of text';
        }
        if ($important && ! $r['schema_json']) {
            $i['no_schema'] = 'No structured data';
        }

        return $i;
    }

    /** Site-wide findings from the last scan of every page: duplicates, orphans, pages missing from the sitemap. */
    public static function siteFindings(): array
    {
        $rows = DB::table('seo_pages')->whereNotNull('scanned_at')->get(['path', 'title', 'description', 'links_json', 'http_status']);
        $inv = self::inventory();
        $inbound = [];
        $linked = [];
        foreach ($rows as $r) {
            foreach ((array) json_decode((string) $r->links_json, true) as $l) {
                if ($l !== $r->path) {
                    $inbound[$l] = ($inbound[$l] ?? 0) + 1;
                    $linked[$l] = true;
                }
            }
        }
        $dupT = $rows->where('title', '!=', '')->groupBy(fn ($r) => mb_strtolower($r->title))->filter(fn ($g) => $g->count() > 1)->map(fn ($g, $k) => ['title' => $g->first()->title, 'pages' => $g->pluck('path')->take(10)->all(), 'count' => $g->count()])->values()->sortByDesc('count')->values();
        $dupD = $rows->where('description', '!=', '')->groupBy(fn ($r) => mb_strtolower($r->description))->filter(fn ($g) => $g->count() > 1)->map(fn ($g) => ['description' => mb_strimwidth($g->first()->description, 0, 120, '…'), 'pages' => $g->pluck('path')->take(10)->all(), 'count' => $g->count()])->values()->sortByDesc('count')->values();
        $scannedShare = count($inv) ? $rows->count() / count($inv) : 0;
        $orphans = $scannedShare >= 0.8 ? $rows->filter(fn ($r) => $r->path !== '/' && empty($inbound[$r->path]) && isset($inv[$r->path]))->pluck('path')->values()->all() : null;
        $weak = $scannedShare >= 0.8 ? $rows->filter(fn ($r) => ($inbound[$r->path] ?? 0) === 1 && isset($inv[$r->path]))->pluck('path')->values()->all() : null;
        $notInSitemap = array_values(array_filter(array_keys($linked), fn ($p) => ! isset($inv[$p]) && preg_match('#(/|\.html)$#', $p) && ! preg_match('#^/(api|admin|console|preview|r|go|verify|workspace|setup|login|register|reset|media|support|assets)\b#', $p) && ! in_array($p, ['/admin.html', '/workspace.html', '/login.html', '/register.html', '/admin-guide.html'], true)));

        return ['scanned' => $rows->count(), 'pages' => count($inv), 'duplicateTitles' => $dupT->take(20)->all(), 'duplicateDescriptions' => $dupD->take(20)->all(),
            'orphans' => $orphans, 'weaklyLinked' => $weak === null ? null : count($weak), 'linkedButNotInSitemap' => array_slice($notInSitemap, 0, 50), 'inbound' => $inbound,
            'note' => $scannedShare < 0.8 ? 'Orphan pages are worked out once at least 80% of pages have been scanned ('.round($scannedShare * 100).'% so far).' : null];
    }

    // ---------------------------------------------------------------- Google index status

    /** Asks Google how it sees a few pages (URL Inspection API), important and changed pages first, within a daily cap. */
    public static function inspect(?int $limit = null): array
    {
        if (! SearchConsole::ready()) {
            return ['skipped' => true, 'summary' => 'Search Console is not connected.'];
        }
        $s = self::settings();
        $day = now('America/Los_Angeles')->toDateString();
        $q = (array) Settings::get('seoInspectQuota', []);
        $used = ($q['day'] ?? '') === $day ? (int) $q['used'] : 0;
        $limit = min($limit ?? $s['inspectPerRun'], max(0, $s['inspectPerDay'] - $used));
        if ($limit <= 0) {
            return ['skipped' => true, 'summary' => 'Daily inspection cap reached ('.$s['inspectPerDay'].').'];
        }
        $rows = DB::table('seo_pages')->where('http_status', 200)->get(['path', 'important', 'inspected_at', 'changed_at', 'verdict']);
        $due = [];
        foreach ($rows as $r) {
            $age = $r->inspected_at ? now()->diffInDays(Carbon::parse($r->inspected_at), true) : 999;
            $changed = $r->inspected_at && $r->changed_at && Carbon::parse($r->changed_at)->gt(Carbon::parse($r->inspected_at));
            if (! $r->inspected_at || $changed || $age >= ($r->important ? 3 : ($r->verdict === 'PASS' ? 30 : 10))) {
                $due[$r->path] = ($r->important ? 4 : 0) + ($r->inspected_at ? 0 : 2) + ($changed ? 1 : 0) + min($age, 999) / 1000;
            }
        }
        arsort($due);
        $token = SearchConsole::token(SearchConsole::READ);
        $done = $failed = 0;
        $last = '';
        foreach (array_slice(array_keys($due), 0, $limit) as $path) {
            try {
                $j = Http::withToken($token)->timeout(20)->post(SearchConsole::base('https://searchconsole.googleapis.com').'/v1/urlInspection/index:inspect', ['inspectionUrl' => self::origin().$path, 'siteUrl' => SearchConsole::property(), 'languageCode' => 'en-IN'])->throw()->json();
                $x = (array) ($j['inspectionResult']['indexStatusResult'] ?? []);
                DB::table('seo_pages')->where('path', $path)->update(['verdict' => mb_substr((string) ($x['verdict'] ?? ''), 0, 20), 'coverage' => mb_substr((string) ($x['coverageState'] ?? ''), 0, 120),
                    'indexing_state' => mb_substr((string) ($x['indexingState'] ?? ''), 0, 60), 'robots_state' => mb_substr((string) ($x['robotsTxtState'] ?? ''), 0, 40), 'fetch_state' => mb_substr((string) ($x['pageFetchState'] ?? ''), 0, 40),
                    'google_canonical' => mb_substr((string) ($x['googleCanonical'] ?? ''), 0, 300), 'last_crawl_at' => isset($x['lastCrawlTime']) ? Carbon::parse($x['lastCrawlTime']) : null, 'inspected_at' => now(), 'inspect_error' => '', 'updated_at' => now()]);
                $done++;
            } catch (RequestException $e) {
                $failed++;
                $last = 'Google answered '.$e->response->status().': '.mb_substr((string) ($e->response->json('error.message') ?: ''), 0, 160);
                DB::table('seo_pages')->where('path', $path)->update(['inspect_error' => mb_substr($last, 0, 255), 'inspected_at' => now(), 'updated_at' => now()]);
                if (in_array($e->response->status(), [401, 403, 429], true)) {
                    break; // permission or quota: the rest would fail the same way
                }
            }
        }
        Settings::put('seoInspectQuota', ['day' => $day, 'used' => $used + $done + $failed]);
        if ($failed && ! $done) {
            throw new \RuntimeException("URL Inspection failed for $failed page(s). $last");
        }

        return ['summary' => "Checked Google's index status of $done page(s)".($failed ? ", $failed failed ($last)" : '').'.', 'inspected' => $done, 'failed' => $failed];
    }

    /**
     * Pages Google has not indexed that changed since the agent last asked: sent once to IndexNow (and Google's Indexing API
     * if the owner switched it on). An unchanged page is never sent again. The owner's own "Request indexing" button in
     * Search Console stays the main route for Google, so each page gets a direct link to it.
     */
    public static function requestIndexing(?int $limit = null): array
    {
        if (! (Settings::get('seo')['searchIndexing'] ?? false)) {
            return ['skipped' => true, 'summary' => 'Search indexing is off in SEO settings.'];
        }
        $rows = DB::table('seo_pages')->where('http_status', 200)->whereNotNull('inspected_at')->where('verdict', '!=', 'PASS')->where('inspect_error', '')
            ->whereColumn('index_requested_fp', '!=', 'fingerprint')->orderByDesc('important')->limit($limit ?? self::settings()['requestPerRun'])->get(['path', 'fingerprint', 'issues_json']);
        $send = $rows->filter(fn ($r) => ! isset(json_decode((string) $r->issues_json, true)['noindex']))->values();
        if ($send->isEmpty()) {
            return ['skipped' => true, 'summary' => 'No changed page is waiting for indexing.'];
        }
        $entry = FastIndex::submit($send->pluck('path')->all());
        foreach ($send as $r) {
            DB::table('seo_pages')->where('path', $r->path)->update(['index_requested_at' => now(), 'index_requested_fp' => $r->fingerprint]);
        }

        return ['summary' => 'Asked search engines to recrawl '.$send->count().' changed page(s) that Google has not indexed. '.trim(($entry['indexnow'] ?? '').' '.($entry['google'] ?? '')), 'requested' => $send->count()];
    }

    public static function inspectLink(string $path): string
    {
        return 'https://search.google.com/search-console/inspect?resource_id='.rawurlencode(SearchConsole::property()).'&id='.rawurlencode(self::origin().$path);
    }

    /** Sitemap processing status from Search Console. */
    public static function sitemaps(): array
    {
        if (! SearchConsole::ready()) {
            return ['skipped' => true, 'summary' => 'Search Console is not connected.'];
        }
        $token = SearchConsole::token(SearchConsole::READ);
        try {
            $j = Http::withToken($token)->timeout(20)->get(SearchConsole::base('https://www.googleapis.com').'/webmasters/v3/sites/'.rawurlencode(SearchConsole::property()).'/sitemaps')->throw()->json();
        } catch (RequestException $e) {
            SearchConsole::explain($e, 'Sitemap status');
        }
        $list = array_map(fn ($m) => ['path' => (string) ($m['path'] ?? ''), 'lastSubmitted' => $m['lastSubmitted'] ?? null, 'lastDownloaded' => $m['lastDownloaded'] ?? null, 'pending' => (bool) ($m['isPending'] ?? false),
            'errors' => (int) ($m['errors'] ?? 0), 'warnings' => (int) ($m['warnings'] ?? 0), 'submitted' => array_sum(array_map(fn ($c) => (int) ($c['submitted'] ?? 0), $m['contents'] ?? []))], $j['sitemap'] ?? []);
        Settings::put('seoSitemaps', ['at' => now()->toIso8601String(), 'list' => $list]);

        return ['summary' => count($list).' sitemap(s): '.implode('; ', array_map(fn ($m) => basename($m['path']).' '.$m['submitted'].' URLs, '.$m['errors'].' errors, '.$m['warnings'].' warnings', $list)).'.', 'list' => $list];
    }

    // ---------------------------------------------------------------- keywords

    public static function classify(string $q): array
    {
        $words = count(preg_split('/\s+/', trim($q)));
        $kind = match (true) {
            self::brandQuery($q) => 'brand', (bool) preg_match(self::PRODUCT, $q) => 'product', (bool) preg_match(self::QUESTION, $q) => 'question',
            $words >= 5 => 'long_tail', (bool) preg_match(self::COMMERCIAL, $q) => 'commercial', default => 'informational',
        };
        $intent = ['brand' => 'navigational', 'product' => 'transactional', 'commercial' => 'commercial', 'question' => 'informational', 'informational' => 'informational', 'long_tail' => preg_match(self::COMMERCIAL, $q) ? 'commercial' : 'informational'][$kind];
        $value = in_array($kind, ['brand', 'product'], true) ? 'high' : ($intent === 'commercial' ? 'medium' : 'low');

        return ['kind' => $kind, 'intent' => $intent, 'value' => $value];
    }

    /** Seeds the owner's target keywords once and refreshes every search from Search Console with its latest numbers. */
    public static function syncKeywords(): array
    {
        $now = now();
        foreach (self::SEED as $k => $target) {
            if (! DB::table('seo_keywords')->where('keyword', $k)->exists()) {
                DB::table('seo_keywords')->insert(['keyword' => $k, 'target' => $target, 'source' => 'seed', 'created_at' => $now, 'updated_at' => $now] + self::classify($k));
            }
        }
        $sd = Settings::get('searchData', []);
        $pairs = collect(Settings::get('seoPairs', [])['rows'] ?? [])->groupBy('query');
        $n = 0;
        foreach (collect($sd['query'] ?? [])->sortByDesc('impressions')->take(2000) as $r) {
            $q = mb_strtolower(trim((string) $r['key']));
            if ($q === '' || mb_strlen($q) > 190 || SeoAgent::branded($q)) {
                continue; // owner rule: no other brands' keywords
            }
            $page = (string) ($pairs->get($r['key'], collect())->sortByDesc('impressions')->first()['path'] ?? '');
            $old = DB::table('seo_keywords')->where('keyword', $q)->first();
            $vals = ['clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'ctr' => round((float) $r['ctr'], 5), 'position' => round((float) $r['position'], 2), 'ranking_page' => mb_substr($page, 0, 255), 'seen_at' => $now, 'updated_at' => $now];
            if ($old) {
                if ($old->seen_at === null || Carbon::parse($old->seen_at)->lt($now->copy()->subDays(5))) {
                    $vals['prev_position'] = $old->position; // week-to-week movement
                }
                DB::table('seo_keywords')->where('id', $old->id)->update($vals + ($old->target === '' ? ['target' => $vals['ranking_page']] : []));
            } else {
                DB::table('seo_keywords')->insert($vals + ['keyword' => $q, 'target' => $vals['ranking_page'], 'source' => 'search_console', 'created_at' => $now] + self::classify($q));
            }
            $n++;
        }

        return ['summary' => 'Keyword database: '.$n.' searches refreshed from Search Console, '.DB::table('seo_keywords')->count().' keywords in total.', 'refreshed' => $n];
    }

    /** Opportunities in the keyword database: what to work on, by the real reason it underperforms. */
    public static function opportunities(): array
    {
        $exp = [1 => 0.28, 2 => 0.15, 3 => 0.1, 4 => 0.07, 5 => 0.05, 6 => 0.04, 7 => 0.03, 8 => 0.025, 9 => 0.02, 10 => 0.02];
        $rows = DB::table('seo_keywords')->whereNotNull('impressions')->where('kind', '!=', 'brand')->orderByDesc('impressions')->limit(3000)->get();
        $low = $rows->filter(fn ($r) => $r->impressions >= 50 && $r->position <= 10 && $r->ctr < ($exp[max(1, (int) round($r->position))] ?? 0.02) * 0.5)->take(20);
        $two = $rows->filter(fn ($r) => $r->position > 10 && $r->position <= 20 && $r->impressions >= 10)->take(20);
        $deep = $rows->filter(fn ($r) => $r->position > 20 && $r->impressions >= 20)->take(20);
        $wrong = $rows->filter(fn ($r) => $r->target !== '' && $r->ranking_page !== '' && $r->target !== $r->ranking_page && $r->impressions >= 20)->take(20);
        $m = fn ($r) => ['keyword' => $r->keyword, 'kind' => $r->kind, 'impressions' => (int) $r->impressions, 'clicks' => (int) $r->clicks, 'ctr' => (float) $r->ctr, 'position' => (float) $r->position, 'page' => $r->ranking_page, 'target' => $r->target];

        return [
            'lowCtr' => ['why' => 'Shown on page 1 but clicked less than half as often as usual for that position: the title and description do not match the search.', 'rows' => $low->map($m)->values()->all()],
            'pageTwo' => ['why' => 'On page 2 (positions 11–20): more internal links and a section that answers the search can move them to page 1.', 'rows' => $two->map($m)->values()->all()],
            'deep' => ['why' => 'Seen beyond page 2: the page does not fully cover the search, or a dedicated page is missing.', 'rows' => $deep->map($m)->values()->all()],
            'wrongPage' => ['why' => 'Google shows a different page than the one planned for this search: links and headings should point to the planned page, or the plan should change.', 'rows' => $wrong->map($m)->values()->all()],
        ];
    }

    // ---------------------------------------------------------------- brand result, clusters, results

    public static function brand(): array
    {
        $q = collect(Settings::get('searchData', [])['query'] ?? []);
        $b = $q->filter(fn ($r) => self::brandQuery((string) $r['key']));
        $nb = $q->reject(fn ($r) => self::brandQuery((string) $r['key']));
        $pages = DB::table('seo_pages')->get()->keyBy('path');
        $home = $pages->get('/');
        $homeLinks = $home ? (array) json_decode((string) $home->links_json, true) : [];
        $schema = $home ? (array) json_decode((string) $home->schema_json, true) : [];
        $links = [];
        foreach (self::SITELINKS as $label => $path) {
            $p = $path ? $pages->get($path) : null;
            $links[] = ['label' => $label, 'path' => $path, 'exists' => (bool) $p && (int) $p->http_status === 200, 'linkedFromHome' => $path ? in_array($path, $homeLinks, true) : false,
                'indexed' => $p && $p->verdict !== '' ? $p->verdict === 'PASS' : null, 'title' => $p->title ?? null,
                'note' => $path === null ? ($label === 'Pricing and Plans' ? 'Plans live inside the home page (/#pricing). A separate plans page would give Google a page to show; it needs your approval.' : 'No page for this yet. A product page would need your approval.') : null];
        }
        $checks = $home ? [
            ['check' => 'Home page title names CVTara', 'ok' => (bool) preg_match(self::BRAND, (string) $home->title), 'detail' => $home->title],
            ['check' => 'Organization structured data on the home page', 'ok' => isset(array_flip($schema)['Organization']), 'detail' => implode(', ', $schema) ?: 'none'],
            ['check' => 'WebSite structured data on the home page', 'ok' => isset(array_flip($schema)['WebSite']), 'detail' => ''],
            ['check' => 'Home page indexed by Google', 'ok' => $home->verdict === '' ? null : $home->verdict === 'PASS', 'detail' => $home->coverage],
            ['check' => 'Favicon available', 'ok' => is_file(public_path('favicon.ico')) || is_file(public_path('favicon.svg')), 'detail' => ''],
            ['check' => 'Main pages linked from the home page', 'ok' => collect($links)->where('path', '!=', null)->every(fn ($l) => $l['linkedFromHome']), 'detail' => collect($links)->filter(fn ($l) => $l['path'] && ! $l['linkedFromHome'])->pluck('label')->implode(', ')],
        ] : [];

        return ['brandClicks' => $q->isEmpty() ? null : (int) $b->sum('clicks'), 'brandImpressions' => $q->isEmpty() ? null : (int) $b->sum('impressions'),
            'nonBrandClicks' => $q->isEmpty() ? null : (int) $nb->sum('clicks'), 'nonBrandImpressions' => $q->isEmpty() ? null : (int) $nb->sum('impressions'),
            'brandSearches' => $b->sortByDesc('impressions')->take(10)->map(fn ($r) => ['query' => $r['key'], 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'position' => round((float) $r['position'], 1)])->values()->all(),
            'sitelinks' => $links, 'checks' => $checks,
            'note' => 'Google chooses which sitelinks to show. A clear page structure, links from the home page and distinct titles make the main pages eligible; nothing can force a layout.'];
    }

    public static function clusters(): array
    {
        $pages = DB::table('seo_pages')->get(['path', 'links_json', 'http_status', 'verdict'])->keyBy('path');
        $inv = self::inventory();
        $links = fn ($p) => $pages->has($p) ? (array) json_decode((string) $pages[$p]->links_json, true) : null;
        $out = [];
        foreach (self::CLUSTERS as $name => $c) {
            $main = $c['main'];
            $mainLinks = $main ? $links($main) : null;
            $sup = [];
            foreach ($c['support'] as $s) {
                $l = $links($s);
                $sup[] = ['path' => $s, 'exists' => isset($inv[$s]), 'scanned' => $l !== null, 'linksToMain' => $main && $l !== null ? in_array($main, $l, true) : null, 'mainLinksHere' => $mainLinks !== null ? in_array($s, $mainLinks, true) : null, 'indexed' => isset($pages[$s]) && $pages[$s]->verdict !== '' ? $pages[$s]->verdict === 'PASS' : null];
            }
            $out[] = ['cluster' => $name, 'main' => $main, 'mainExists' => $main ? isset($inv[$main]) : false, 'support' => $sup, 'suggestedTopics' => $c['missing'],
                'linkGaps' => count(array_filter($sup, fn ($s) => $s['linksToMain'] === false || $s['mainLinksHere'] === false))];
        }

        return $out;
    }

    /** The result of every approved change: the page's Search Console numbers before and at least 4 weeks after. */
    public static function results(int $limit = 50): array
    {
        $out = [];
        foreach (DB::table('seo_proposals')->whereIn('status', ['approved', 'reverted'])->orderByDesc('decided_at')->limit($limit)->get() as $p) {
            $at = Carbon::parse($p->decided_at);
            $before = DB::table('seo_track')->where('kind', 'page')->where('key', $p->path)->where('week', '<', $at->copy()->tz(Growth::TZ)->startOfWeek()->toDateString())->orderByDesc('week')->first();
            $after = DB::table('seo_track')->where('kind', 'page')->where('key', $p->path)->where('week', '>=', $at->copy()->addDays(28)->tz(Growth::TZ)->startOfWeek()->toDateString())->orderByDesc('week')->first();
            $pick = fn ($r) => $r ? ['week' => substr((string) $r->week, 0, 10), 'clicks' => (int) $r->clicks, 'impressions' => (int) $r->impressions, 'ctr' => (float) $r->ctr, 'position' => (float) $r->position] : null;
            $b = $pick($before);
            $a = $pick($after);
            $verdict = match (true) {
                $a === null && $at->gt(now()->subDays(28)) => 'Too early: measured 4 weeks after the change ('.$at->copy()->addDays(28)->tz(Growth::TZ)->format('j M').')',
                $a === null || $b === null => 'No Search Console data for this page to compare',
                $a['clicks'] > $b['clicks'] * 1.1 && $a['clicks'] - $b['clicks'] >= 3 => 'Improved',
                $a['clicks'] < $b['clicks'] * 0.9 && $b['clicks'] - $a['clicks'] >= 3 => 'Declined',
                default => 'No clear change',
            };
            $out[] = ['id' => $p->id, 'kind' => $p->kind, 'path' => $p->path, 'query' => $p->query, 'status' => $p->status, 'decidedAt' => $p->decided_at, 'before' => $b, 'after' => $a, 'verdict' => $verdict];
        }

        return $out;
    }

    /** Pages for the console list: scan and index status, Search Console numbers, issues and links in. */
    public static function pages(): array
    {
        $site = self::siteFindings();
        $week = DB::table('seo_track')->where('kind', 'page')->max('week');
        $gsc = $week ? DB::table('seo_track')->where('kind', 'page')->where('week', $week)->get()->keyBy('key') : collect();
        $prevWeek = $week ? DB::table('seo_track')->where('kind', 'page')->where('week', '<', $week)->max('week') : null;
        $prev = $prevWeek ? DB::table('seo_track')->where('kind', 'page')->where('week', $prevWeek)->get()->keyBy('key') : collect();
        $dups = [];
        foreach ($site['duplicateTitles'] as $d) {
            foreach ($d['pages'] as $p) {
                $dups[$p]['dup_title'] = 'Same title as '.($d['count'] - 1).' other page(s)';
            }
        }
        foreach ($site['duplicateDescriptions'] as $d) {
            foreach ($d['pages'] as $p) {
                $dups[$p]['dup_description'] = 'Same description as '.($d['count'] - 1).' other page(s)';
            }
        }
        $orph = array_flip($site['orphans'] ?? []);
        $actions = DB::table('seo_proposals')->select('path', DB::raw('count(*) as n'))->groupBy('path')->pluck('n', 'path');

        return DB::table('seo_pages')->orderByDesc('important')->orderBy('path')->get()->map(function ($r) use ($gsc, $prev, $dups, $orph, $site, $actions) {
            $issues = (array) json_decode((string) $r->issues_json, true) + ($dups[$r->path] ?? []) + (isset($orph[$r->path]) ? ['orphan' => 'No other page links here'] : []);
            $g = $gsc->get($r->path);
            $pv = $prev->get($r->path);
            $q = $g ? array_slice((array) (json_decode((string) $g->extra_json, true)['queries'] ?? []), 0, 5) : [];

            return ['path' => $r->path, 'important' => (bool) $r->important, 'section' => $r->section, 'status' => $r->http_status === null ? null : (int) $r->http_status, 'title' => $r->title, 'description' => $r->description, 'h1' => $r->h1,
                'words' => (int) $r->words, 'canonical' => $r->canonical, 'robots' => $r->robots, 'schema' => (array) json_decode((string) $r->schema_json, true), 'linksIn' => (int) ($site['inbound'][$r->path] ?? 0),
                'issues' => $issues, 'issueCount' => count($issues), 'scannedAt' => $r->scanned_at, 'changedAt' => $r->changed_at,
                'verdict' => $r->verdict, 'coverage' => $r->coverage, 'googleCanonical' => $r->google_canonical, 'lastCrawl' => $r->last_crawl_at, 'inspectedAt' => $r->inspected_at, 'inspectError' => $r->inspect_error, 'indexRequestedAt' => $r->index_requested_at,
                'impressions' => $g ? (int) $g->impressions : 0, 'clicks' => $g ? (int) $g->clicks : 0, 'ctr' => $g ? (float) $g->ctr : 0.0, 'position' => $g ? (float) $g->position : null,
                'impressionsChange' => $g && $pv ? (int) $g->impressions - (int) $pv->impressions : null, 'positionChange' => $g && $pv ? round((float) $pv->position - (float) $g->position, 1) : null, 'queries' => $q,
                'actions' => (int) ($actions[$r->path] ?? 0), 'inspectLink' => self::inspectLink($r->path)];
        })->all();
    }

    /** One page's history: weekly Search Console numbers and every agent action on it. */
    public static function history(string $path): array
    {
        return ['weeks' => DB::table('seo_track')->where('kind', 'page')->where('key', $path)->orderBy('week')->get(['week', 'clicks', 'impressions', 'ctr', 'position'])->map(fn ($r) => ['week' => substr((string) $r->week, 0, 10), 'clicks' => (int) $r->clicks, 'impressions' => (int) $r->impressions, 'ctr' => (float) $r->ctr, 'position' => (float) $r->position])->all(),
            'actions' => DB::table('seo_proposals')->where('path', $path)->orderByDesc('created_at')->limit(30)->get(['id', 'kind', 'status', 'reason', 'created_at', 'decided_at'])->all(),
            'page' => DB::table('seo_pages')->where('path', $path)->first(), 'inspectLink' => self::inspectLink($path)];
    }

    // ---------------------------------------------------------------- KPIs

    /** Organic sign-ups, buyers and revenue in a window, from accounts whose first visit came from Google or Bing. */
    public static function organic(Carbon $from, Carbon $to): array
    {
        $users = DB::table('users')->where('is_admin', false)->whereIn('signup_source', Attribution::ORGANIC)->where('created_at', '>=', $from)->where('created_at', '<', $to);
        $ids = (clone $users)->pluck('id');
        $sales = Growth::sales()->whereIn('owner_id', $ids)->where('purchased_at', '<', $to);

        return ['signups' => $ids->count(), 'buyers' => (clone $sales)->distinct()->count('owner_id'), 'netPaise' => (int) (clone $sales)->sum(DB::raw('amount_paise - refunded_paise')),
            'tracked' => DB::table('users')->where('is_admin', false)->where('created_at', '>=', $from)->where('created_at', '<', $to)->whereNotNull('signup_source')->count(),
            'allSignups' => DB::table('users')->where('is_admin', false)->where('created_at', '>=', $from)->where('created_at', '<', $to)->count()];
    }

    /** Sign-ups, buyers and revenue per first page of the visit (last N days), with visitors to that page where analytics counts them. */
    public static function landingPages(int $days = 30, ?string $source = null): array
    {
        $from = now()->subDays($days);
        $users = DB::table('users')->where('is_admin', false)->where('created_at', '>=', $from)->whereNotNull('signup_landing')->when($source === 'organic', fn ($q) => $q->whereIn('signup_source', Attribution::ORGANIC))->get(['id', 'signup_landing']);
        $byPage = $users->groupBy('signup_landing');
        $views = DB::table('page_views')->where('created_at', '>=', $from)->when($source === 'organic', fn ($q) => $q->whereIn('source', Attribution::ORGANIC))->select('path', DB::raw('count(distinct visitor_id) as v'))->groupBy('path')->pluck('v', 'path');
        $out = [];
        foreach ($byPage as $page => $g) {
            $sales = Growth::sales()->whereIn('owner_id', $g->pluck('id'));
            $out[] = ['page' => $page, 'visitors' => isset($views[$page]) ? (int) $views[$page] : null, 'signups' => $g->count(), 'buyers' => (clone $sales)->distinct()->count('owner_id'), 'netPaise' => (int) (clone $sales)->sum(DB::raw('amount_paise - refunded_paise'))];
        }
        foreach ($views as $page => $v) {
            if (! $byPage->has($page) && $v >= 30) {
                $out[] = ['page' => $page, 'visitors' => (int) $v, 'signups' => 0, 'buyers' => 0, 'netPaise' => 0];
            }
        }
        usort($out, fn ($a, $b) => [$b['netPaise'], $b['signups'], $b['visitors'] ?? 0] <=> [$a['netPaise'], $a['signups'], $a['visitors'] ?? 0]);

        return array_map(fn ($r) => $r + ['signupRate' => $r['visitors'] ? round($r['signups'] / $r['visitors'] * 100, 1) : null, 'buyRate' => $r['signups'] ? round($r['buyers'] / $r['signups'] * 100, 1) : null], array_slice($out, 0, 50));
    }

    public static function kpis(): array
    {
        $imp = DB::table('seo_pages')->where('important', true);
        $impInspected = (clone $imp)->where('verdict', '!=', '')->count();
        $all = DB::table('seo_pages')->where('verdict', '!=', '');
        $issues = DB::table('seo_pages')->whereNotNull('scanned_at')->pluck('issues_json')->sum(fn ($j) => count((array) json_decode((string) $j, true)));
        $resolved = collect((array) Settings::get('seoResolved', []))->filter(fn ($r) => Carbon::parse($r['at'])->gt(now()->subDays(30)))->count();
        $sd = Settings::get('searchData', []);
        $brand = self::brand();
        $q = collect($sd['query'] ?? []);
        $org = self::organic(now()->subDays(30), now()->addSecond());
        $dec = DB::table('seo_proposals')->where('decided_at', '>=', now()->subDays(90))->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');
        $appr = (int) ($dec['approved'] ?? 0) + (int) ($dec['reverted'] ?? 0);
        $rej = (int) ($dec['rejected'] ?? 0);

        return [
            'importantIndexed' => $impInspected ? (clone $imp)->where('verdict', 'PASS')->count() : null, 'importantPages' => (clone $imp)->count(), 'importantInspected' => $impInspected,
            'pagesIndexed' => (clone $all)->count() ? (clone $all)->where('verdict', 'PASS')->count() : null, 'pagesInspected' => (clone $all)->count(),
            'issuesOpen' => DB::table('seo_pages')->whereNotNull('scanned_at')->exists() ? $issues : null, 'issuesResolved30d' => $resolved,
            'impressions' => $q->isEmpty() ? null : (int) $q->sum('impressions'), 'clicks' => $q->isEmpty() ? null : (int) $q->sum('clicks'),
            'ctr' => $q->isEmpty() || ! $q->sum('impressions') ? null : round($q->sum('clicks') / $q->sum('impressions') * 100, 2),
            'brandImpressions' => $brand['brandImpressions'], 'brandClicks' => $brand['brandClicks'], 'nonBrandImpressions' => $brand['nonBrandImpressions'], 'nonBrandClicks' => $brand['nonBrandClicks'],
            'searchPeriod' => isset($sd['startDate']) ? $sd['startDate'].' to '.$sd['endDate'] : null,
            'organicSignups30d' => $org['signups'], 'organicBuyers30d' => $org['buyers'], 'organicNetPaise30d' => $org['netPaise'], 'signupsWithSource30d' => $org['tracked'], 'signups30d' => $org['allSignups'],
            'approvalRate' => $appr + $rej ? round($appr / ($appr + $rej) * 100) : null, 'approved90d' => $appr, 'rejected90d' => $rej,
            'notTracked' => 'Organic sign-ups count only visitors who allowed analytics, so the real number can be higher. Revenue is linked to the visit where the person signed up (attribution), not proof the search caused the sale. Keyword search volume and difficulty need a paid keyword tool and are not tracked.',
        ];
    }
}
