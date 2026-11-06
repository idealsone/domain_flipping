<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The SEO agent's AI search department (owner console only; nothing here is shown on the public site):
 * - crawler access audit: robots.txt rules per crawler, private areas kept out, public pages reachable, private pages answering noindex;
 * - the owner's choice for model-training crawlers, kept apart from search crawlers (written into robots.txt only when the owner changes it);
 * - repeatable AI answer checks: a standard prompt list, one record per platform/prompt/date with what was observed;
 * - visits, sign-ups and sales that arrive from AI assistants, read from the referring site where the platform passes one.
 * Nothing is scraped and no platform is queried automatically: tests are entered from the platforms' own apps, so every record is an observation.
 */
class AiVisibility
{
    /** Where CVTara wants to be found. Search engines first, then AI assistants, then communities. */
    public const PLATFORMS = [
        'google_ai' => 'Google AI Overviews / AI Mode', 'chatgpt' => 'ChatGPT search', 'gemini' => 'Gemini', 'claude' => 'Claude', 'perplexity' => 'Perplexity', 'copilot' => 'Bing / Copilot',
        'reddit' => 'Reddit', 'youtube' => 'YouTube', 'linkedin' => 'LinkedIn', 'quora' => 'Quora',
    ];

    /** Referring sites (and utm_source values some assistants add) that mean "came from an AI assistant". */
    private const AI_HOSTS = ['chatgpt.com' => 'chatgpt', 'chat.openai.com' => 'chatgpt', 'openai.com' => 'chatgpt', 'perplexity.ai' => 'perplexity', 'gemini.google.com' => 'gemini', 'bard.google.com' => 'gemini',
        'claude.ai' => 'claude', 'copilot.microsoft.com' => 'copilot', 'edgeservices.bing.com' => 'copilot', 'you.com' => 'other_ai', 'phind.com' => 'other_ai', 'meta.ai' => 'other_ai', 'deepseek.com' => 'other_ai', 'chat.deepseek.com' => 'other_ai'];

    /** The standard prompt set, run the same way every week so results can be compared. The owner can edit it. */
    public const PROMPTS = ['Best resume builder in India', 'Best ATS resume checker for freshers', 'Free online CV maker', 'Resume builder for experienced professionals',
        'Best tool to match my resume with a job description', 'Best cover letter generator', 'Websites for creating ATS-friendly resumes', 'How to improve my resume ATS score'];

    /** Crawlers checked by the audit. kind: search (shows the site in answers or results), user (fetches a page a person asked about), training (collects text to train models). */
    public const CRAWLERS = [
        ['agent' => 'Googlebot', 'owner' => 'Google', 'kind' => 'search', 'purpose' => 'Google Search, AI Overviews and AI Mode'],
        ['agent' => 'Bingbot', 'owner' => 'Microsoft', 'kind' => 'search', 'purpose' => 'Bing search and Copilot answers'],
        ['agent' => 'OAI-SearchBot', 'owner' => 'OpenAI', 'kind' => 'search', 'purpose' => 'ChatGPT search results'],
        ['agent' => 'ChatGPT-User', 'owner' => 'OpenAI', 'kind' => 'user', 'purpose' => 'Pages a ChatGPT user asks it to open'],
        ['agent' => 'Claude-SearchBot', 'owner' => 'Anthropic', 'kind' => 'search', 'purpose' => 'Claude search results'],
        ['agent' => 'Claude-User', 'owner' => 'Anthropic', 'kind' => 'user', 'purpose' => 'Pages a Claude user asks it to open'],
        ['agent' => 'PerplexityBot', 'owner' => 'Perplexity', 'kind' => 'search', 'purpose' => 'Perplexity answers and citations'],
        ['agent' => 'GPTBot', 'owner' => 'OpenAI', 'kind' => 'training', 'purpose' => 'Collects text to train OpenAI models'],
        ['agent' => 'ClaudeBot', 'owner' => 'Anthropic', 'kind' => 'training', 'purpose' => 'Collects text to train Anthropic models'],
        ['agent' => 'Google-Extended', 'owner' => 'Google', 'kind' => 'training', 'purpose' => 'Lets Google use pages to train Gemini (does not affect Google Search)'],
        ['agent' => 'CCBot', 'owner' => 'Common Crawl', 'kind' => 'training', 'purpose' => 'Open web archive used to train many models'],
    ];

    /** Parts of the site no crawler should enter or index (checked against robots.txt and the pages' own noindex). */
    public const PRIVATE_PATHS = ['/api/me', '/console/growth.js', '/export/resume'];

    public const NOINDEX_PAGES = ['/workspace.html', '/admin.html', '/checkout.html', '/login', '/register'];

    public static function platformForHost(string $host): ?string
    {
        $host = preg_replace('/^www\./', '', mb_strtolower(trim($host)));
        if ($host === '') {
            return null;
        }
        foreach (self::AI_HOSTS as $h => $p) {
            if ($host === $h || str_ends_with($host, '.'.$h)) {
                return $p;
            }
        }

        return null;
    }

    /** Hosts that count as AI arrivals, for queries. */
    public static function hosts(?string $platform = null): array
    {
        return array_keys(array_filter(self::AI_HOSTS, fn ($p) => $platform === null || $p === $platform));
    }

    // ---------------------------------------------------------------- training crawler choice (robots.txt)

    /** The owner's choice per training crawler: allow (the default, what the site did before) or block. */
    public static function policy(): array
    {
        $saved = (array) Settings::get('crawlerPolicy', []);
        $out = [];
        foreach (self::CRAWLERS as $c) {
            if ($c['kind'] === 'training') {
                $out[$c['agent']] = ($saved[$c['agent']] ?? 'allow') === 'block' ? 'block' : 'allow';
            }
        }

        return $out;
    }

    public static function savePolicy(array $choice): array
    {
        $p = self::policy();
        foreach ($choice as $agent => $v) {
            if (isset($p[$agent])) {
                $p[$agent] = $v === 'block' ? 'block' : 'allow';
            }
        }
        Settings::put('crawlerPolicy', $p);

        return $p;
    }

    /** Extra robots.txt groups for blocked training crawlers. Search and user-directed crawlers are never blocked from here. */
    public static function robotsGroups(): string
    {
        $out = '';
        foreach (self::policy() as $agent => $v) {
            if ($v === 'block') {
                $out .= "\nUser-agent: $agent\nDisallow: /\n";
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- crawler access audit

    /** Groups of a robots.txt file: [['agents' => [...], 'rules' => [['allow'|'disallow', path]]]]. */
    public static function parseRobots(string $txt): array
    {
        $groups = [];
        $cur = null;
        $lastWasAgent = false;
        foreach (preg_split('/\R/', $txt) as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $k = strtolower($k);
            if ($k === 'user-agent') {
                if (! $lastWasAgent || $cur === null) {
                    $groups[] = ['agents' => [], 'rules' => []];
                    $cur = count($groups) - 1;
                }
                $groups[$cur]['agents'][] = strtolower($v);
                $lastWasAgent = true;
            } elseif (in_array($k, ['allow', 'disallow'], true) && $cur !== null) {
                $groups[$cur]['rules'][] = [$k, $v];
                $lastWasAgent = false;
            } else {
                $lastWasAgent = false;
            }
        }

        return $groups;
    }

    /** Whether robots.txt lets this crawler fetch the path (most specific group, longest rule, Allow wins a tie). */
    public static function allowed(array $groups, string $agent, string $path): bool
    {
        $agent = strtolower($agent);
        $pick = null;
        foreach ($groups as $g) {
            if (in_array($agent, $g['agents'], true)) {
                $pick = $g;
                break;
            }
        }
        $pick ??= collect($groups)->first(fn ($g) => in_array('*', $g['agents'], true));
        if (! $pick) {
            return true;
        }
        $best = null;
        foreach ($pick['rules'] as [$type, $rule]) {
            if ($rule === '' && $type === 'disallow') {
                continue; // "Disallow:" with nothing after it allows everything
            }
            $re = '#^'.str_replace(['\*', '\$'], ['.*', '$'], preg_quote($rule, '#')).'#';
            if (preg_match($re, $path)) {
                $len = strlen($rule);
                if ($best === null || $len > $best[1] || ($len === $best[1] && $type === 'allow')) {
                    $best = [$type, $len];
                }
            }
        }

        return $best === null || $best[0] === 'allow';
    }

    /** Runs the audit against the live site and keeps the result (Settings aiCrawlerAudit). */
    public static function audit(): array
    {
        $origin = FastIndex::origin();
        $findings = [];
        $robots = '';
        $robotsStatus = null;
        try {
            $res = Http::timeout(12)->withHeaders(['User-Agent' => 'CVTara-site-check/1.0'])->get($origin.'/robots.txt');
            $robotsStatus = $res->status();
            $robots = $res->successful() ? (string) $res->body() : '';
        } catch (\Throwable $e) {
            $findings[] = ['sev' => 'high', 'text' => 'robots.txt could not be fetched: '.mb_strimwidth($e->getMessage(), 0, 120, '…')];
        }
        $groups = self::parseRobots($robots);
        $public = array_slice(SeoOps::IMPORTANT, 0, 8);
        $rows = [];
        foreach (self::CRAWLERS as $c) {
            $blockedPublic = array_values(array_filter($public, fn ($p) => ! self::allowed($groups, $c['agent'], $p)));
            $openPrivate = array_values(array_filter(self::PRIVATE_PATHS, fn ($p) => self::allowed($groups, $c['agent'], $p)));
            $home = null;
            if ($c['kind'] !== 'training') {
                // How the server answers this crawler's name. A firewall or bot shield in front of the site can answer differently from outside.
                try {
                    $home = Http::withoutRedirecting()->timeout(10)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; '.$c['agent'].'/1.0)', 'Accept' => 'text/html'])->get($origin.'/')->status();
                } catch (\Throwable) {
                    $home = 0;
                }
            }
            $wanted = $c['kind'] === 'training' ? (self::policy()[$c['agent']] ?? 'allow') : 'allow';
            $ok = ($wanted === 'allow' ? ! $blockedPublic : count($blockedPublic) === count($public)) && ! $openPrivate && ($home === null || ($home >= 200 && $home < 400));
            $rows[] = $c + ['wanted' => $wanted, 'publicAllowed' => count($public) - count($blockedPublic), 'publicChecked' => count($public), 'blockedPublic' => $blockedPublic, 'privateOpen' => $openPrivate, 'homeStatus' => $home, 'ok' => $ok];
            if ($c['kind'] !== 'training' && $blockedPublic) {
                $findings[] = ['sev' => 'high', 'text' => $c['agent'].' is blocked from public pages ('.implode(', ', array_slice($blockedPublic, 0, 3)).'), so '.$c['purpose'].' cannot use them.'];
            }
            if ($openPrivate) {
                $findings[] = ['sev' => 'medium', 'text' => $c['agent'].' may enter private areas by robots.txt: '.implode(', ', $openPrivate).'. These need a Disallow line (they also require sign-in).'];
            }
            if ($home !== null && ($home === 0 || $home >= 400)) {
                $findings[] = ['sev' => 'high', 'text' => 'The home page answered '.($home ?: 'nothing').' to '.$c['agent'].'. Check the host\'s bot protection or firewall for this crawler.'];
            }
        }
        $noindex = [];
        foreach (self::NOINDEX_PAGES as $p) {
            try {
                $res = Http::withoutRedirecting()->timeout(10)->withHeaders(['User-Agent' => 'CVTara-site-check/1.0', 'Accept' => 'text/html'])->get($origin.$p);
                $st = $res->status();
                $robotsMeta = $st >= 200 && $st < 300 ? (SeoOps::read((string) $res->body(), (string) $res->header('X-Robots-Tag'))['robots'] ?? '') : '';
                $safe = $st >= 300 || str_contains(strtolower($robotsMeta), 'noindex');
                $noindex[] = ['path' => $p, 'status' => $st, 'robots' => $robotsMeta, 'ok' => $safe];
                if (! $safe) {
                    $findings[] = ['sev' => 'high', 'text' => "$p can be indexed (no noindex). Private customer pages must answer noindex."];
                }
            } catch (\Throwable) {
                $noindex[] = ['path' => $p, 'status' => 0, 'robots' => '', 'ok' => null];
            }
        }
        $sitemapLine = (bool) preg_match('/^sitemap:\s*\S+/mi', $robots);
        if ($robots !== '' && ! $sitemapLine) {
            $findings[] = ['sev' => 'medium', 'text' => 'robots.txt does not name the sitemap.'];
        }
        $privateInSitemap = DB::table('seo_pages')->where(fn ($q) => $q->where('path', 'like', '/workspace%')->orWhere('path', 'like', '/admin%')->orWhere('path', 'like', '/api/%')->orWhere('path', 'like', '/checkout%'))->pluck('path')->all();
        foreach ($privateInSitemap as $p) {
            $findings[] = ['sev' => 'high', 'text' => "$p is in the page inventory (sitemap). Private pages must not be listed."];
        }
        $out = ['at' => now()->toIso8601String(), 'origin' => $origin, 'robotsStatus' => $robotsStatus, 'robots' => mb_substr($robots, 0, 3000), 'sitemapListed' => $sitemapLine, 'crawlers' => $rows, 'noindex' => $noindex, 'findings' => $findings,
            'notChecked' => 'Server access logs are not read, so actual crawler visits are not tracked. The check calls the site from its own server: a CDN or firewall in front of the site may still answer outside crawlers differently.'];
        Settings::put('aiCrawlerAudit', $out);

        return $out;
    }

    // ---------------------------------------------------------------- AI answer checks

    public static function prompts(): array
    {
        $p = array_values(array_filter(array_map('trim', (array) Settings::get('aiPrompts', []))));

        return $p ?: self::PROMPTS;
    }

    public static function savePrompts(array $list): array
    {
        $list = array_values(array_unique(array_filter(array_map(fn ($x) => mb_substr(trim((string) $x), 0, 300), $list))));
        Settings::put('aiPrompts', array_slice($list, 0, 40));

        return self::prompts();
    }

    public static function saveTest(array $v, ?string $id = null): string
    {
        $row = ['tested_on' => $v['tested_on'], 'platform' => $v['platform'], 'model' => (string) ($v['model'] ?? ''), 'region' => (string) ($v['region'] ?? 'India'), 'prompt' => $v['prompt'],
            'mentioned' => (bool) $v['mentioned'], 'recommended' => (bool) ($v['recommended'] ?? false), 'accurate' => (string) ($v['accurate'] ?? ''), 'linked' => (bool) ($v['linked'] ?? false),
            'cited_url' => (string) ($v['cited_url'] ?? ''), 'competitors' => (string) ($v['competitors'] ?? ''), 'sources' => (string) ($v['sources'] ?? ''), 'missing' => (string) ($v['missing'] ?? ''),
            'excerpt' => (string) ($v['excerpt'] ?? ''), 'conditions' => (string) ($v['conditions'] ?? ''), 'updated_at' => now()];
        if ($id) {
            abort_unless(DB::table('ai_visibility_tests')->where('id', $id)->update($row), 404);

            return $id;
        }
        $id = (string) Str::uuid();
        DB::table('ai_visibility_tests')->insert($row + ['id' => $id, 'created_at' => now()]);

        return $id;
    }

    /** Everything the AI search tab shows: latest result per prompt and platform, rates, competitors, AI arrivals and what they became. */
    public static function overview(int $days = 90): array
    {
        $from = now()->subDays($days);
        $tests = DB::table('ai_visibility_tests')->where('tested_on', '>=', $from->toDateString())->orderByDesc('tested_on')->get();
        $platforms = [];
        foreach (self::PLATFORMS as $k => $label) {
            $t = $tests->where('platform', $k);
            $platforms[] = ['key' => $k, 'label' => $label, 'tests' => $t->count(), 'mentioned' => $t->where('mentioned', true)->count(), 'recommended' => $t->where('recommended', true)->count(), 'linked' => $t->where('linked', true)->count(),
                'mentionRate' => $t->count() ? round($t->where('mentioned', true)->count() / $t->count() * 100) : null, 'inaccurate' => $t->whereIn('accurate', ['no', 'partly'])->count(), 'lastTested' => $t->max('tested_on')];
        }
        $latest = [];
        foreach (self::prompts() as $p) {
            $cells = [];
            foreach (array_keys(self::PLATFORMS) as $k) {
                $t = $tests->first(fn ($x) => $x->platform === $k && mb_strtolower($x->prompt) === mb_strtolower($p));
                $cells[$k] = $t ? ['on' => $t->tested_on, 'mentioned' => (bool) $t->mentioned, 'recommended' => (bool) $t->recommended, 'linked' => (bool) $t->linked] : null;
            }
            $latest[] = ['prompt' => $p, 'cells' => $cells];
        }
        $competitors = [];
        foreach ($tests as $t) {
            foreach (preg_split('/[,;\n]+/', (string) $t->competitors) as $c) {
                $c = trim($c);
                if ($c !== '') {
                    $competitors[mb_strtolower($c)] = ['name' => $c, 'n' => ($competitors[mb_strtolower($c)]['n'] ?? 0) + 1];
                }
            }
        }
        usort($competitors, fn ($a, $b) => $b['n'] <=> $a['n']);
        $cited = $tests->where('cited_url', '!=', '')->groupBy('cited_url')->map(fn ($g, $u) => ['url' => $u, 'n' => $g->count()])->sortByDesc('n')->values()->take(15)->all();
        $missing = $tests->where('missing', '!=', '')->take(10)->map(fn ($t) => ['platform' => self::PLATFORMS[$t->platform] ?? $t->platform, 'prompt' => $t->prompt, 'missing' => $t->missing])->values()->all();
        $due = [];
        foreach ($latest as $row) {
            foreach (['google_ai', 'chatgpt', 'gemini', 'claude', 'perplexity', 'copilot'] as $k) {
                $c = $row['cells'][$k];
                if (! $c || strtotime($c['on']) < strtotime('-7 days')) {
                    $due[] = ['prompt' => $row['prompt'], 'platform' => $k, 'label' => self::PLATFORMS[$k]];
                }
            }
        }

        return ['days' => $days, 'platforms' => $platforms, 'latest' => $latest, 'competitors' => array_slice($competitors, 0, 15), 'cited' => $cited, 'missing' => $missing, 'due' => count($due), 'dueSample' => array_slice($due, 0, 12),
            'arrivals' => self::arrivals($days), 'audit' => Settings::get('aiCrawlerAudit', []), 'policy' => self::policy(), 'crawlers' => self::CRAWLERS, 'prompts' => self::prompts(), 'platformNames' => self::PLATFORMS,
            'note' => 'Each test is one observation by one person on one day; answers change by account, place and time. A mention is not a ranking. Visits from assistants are counted only when the assistant passes its site as the referrer or adds utm_source.'];
    }

    /** Visits, sign-ups and buyers that came from AI assistants, per platform. */
    public static function arrivals(int $days): array
    {
        $from = now()->subDays($days);
        $views = DB::table('page_views')->where('created_at', '>=', $from)->where('source', 'ai')->whereNotNull('ref_host')->get(['ref_host', 'visitor_id']);
        $users = DB::table('users')->where('is_admin', false)->where('created_at', '>=', $from)->where('signup_source', 'ai')->get(['id', 'signup_ref_host']);
        $out = [];
        foreach ($views->groupBy(fn ($v) => self::platformForHost((string) $v->ref_host) ?? 'other_ai') as $k => $g) {
            $out[$k] = ['platform' => $k, 'visitors' => $g->unique('visitor_id')->count(), 'signups' => 0, 'buyers' => 0, 'netPaise' => 0];
        }
        foreach ($users->groupBy(fn ($u) => self::platformForHost((string) $u->signup_ref_host) ?? 'other_ai') as $k => $g) {
            $sales = Growth::sales()->whereIn('owner_id', $g->pluck('id'))->get(['owner_id', 'amount_paise', 'refunded_paise']);
            $out[$k] = ($out[$k] ?? ['platform' => $k, 'visitors' => 0]) + ['signups' => 0, 'buyers' => 0, 'netPaise' => 0];
            $out[$k]['signups'] = $g->count();
            $out[$k]['buyers'] = $sales->unique('owner_id')->count();
            $out[$k]['netPaise'] = (int) ($sales->sum('amount_paise') - $sales->sum('refunded_paise'));
        }

        return array_values(array_map(fn ($r) => $r + ['label' => self::PLATFORMS[$r['platform']] ?? 'Other assistants'], $out));
    }
}
