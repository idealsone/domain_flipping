<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * One view of how far the site is in each search engine, for the console Overview (3.60.1).
 * Google: the URL Inspection results SeoOps stores per page in seo_pages, plus Search Console clicks and impressions.
 * Bing: Bing Webmaster Tools' own counts (pages in index, crawled, crawl errors, clicks, impressions), read with the
 * owner's Bing Webmaster API key and kept for six hours so the Overview stays fast.
 * IndexNow: what FastIndex has sent and what is waiting.
 */
class IndexStatus
{
    public const BING_TTL_HOURS = 6;

    public static function all(bool $refreshBing = false): array
    {
        return ['at' => now()->toIso8601String(), 'google' => self::google(), 'bing' => self::bing($refreshBing), 'indexnow' => self::indexnow()];
    }

    public static function google(): array
    {
        $out = ['connected' => SearchConsole::ready(), 'pages' => 0, 'checked' => 0, 'indexed' => 0, 'notChecked' => 0, 'reasons' => [], 'lastChecked' => null, 'search' => null];
        if (Schema::hasTable('seo_pages')) {
            $live = fn () => DB::table('seo_pages')->where('http_status', 200);
            $out['pages'] = $live()->count();
            $out['checked'] = $live()->whereNotNull('inspected_at')->where('inspect_error', '')->count();
            $out['indexed'] = $live()->where('verdict', 'PASS')->count();
            $out['notChecked'] = $out['pages'] - $out['checked'];
            $out['lastChecked'] = $live()->max('inspected_at');
            $out['reasons'] = $live()->whereNotNull('inspected_at')->where('inspect_error', '')->where('verdict', '!=', 'PASS')
                ->selectRaw('coverage, count(*) as n')->groupBy('coverage')->orderByDesc('n')->limit(6)->get()
                ->map(fn ($r) => ['label' => $r->coverage !== '' ? $r->coverage : 'Not indexed', 'count' => (int) $r->n])->all();
        }
        $s = (array) Settings::get('searchData', []);
        if ($s && ! empty($s['page'])) {
            $out['search'] = ['from' => $s['startDate'] ?? '', 'to' => $s['endDate'] ?? '', 'pages' => count($s['page']),
                'clicks' => (int) array_sum(array_column($s['page'], 'clicks')), 'impressions' => (int) array_sum(array_column($s['page'], 'impressions'))];
        }

        return $out;
    }

    public static function bingKey(): string
    {
        return trim((string) (Settings::providers()['analytics']['bingApiKey'] ?? ''));
    }

    private static function bingBase(): string
    {
        $o = env('BING_API_BASE');

        return $o && config('app.env') !== 'production' ? rtrim($o, '/') : 'https://ssl.bing.com/webmaster/api.svc/json';
    }

    public static function bing(bool $refresh = false): array
    {
        $key = self::bingKey();
        $site = FastIndex::origin().'/';
        if ($key === '') {
            return ['connected' => false, 'site' => $site];
        }
        $c = (array) Settings::get('bingStats', []);
        $fresh = ! empty($c['at']) && ($c['keyTag'] ?? '') === substr(sha1($key), 0, 8) && Carbon::parse($c['at'])->gt(now()->subHours(self::BING_TTL_HOURS));
        if ($refresh || ! $fresh) {
            $c = self::fetchBing($key, $site);
            Settings::put('bingStats', $c);
        }
        unset($c['keyTag']);

        return ['connected' => true, 'site' => $site] + $c;
    }

    private static function fetchBing(string $key, string $site): array
    {
        $out = ['at' => now()->toIso8601String(), 'keyTag' => substr(sha1($key), 0, 8), 'error' => ''];
        $get = fn (string $method) => Http::timeout(12)->acceptJson()->get(self::bingBase().'/'.$method, ['siteUrl' => $site, 'apikey' => $key])->throw()->json('d') ?? [];
        try {
            $crawl = collect((array) $get('GetCrawlStats'))->filter(fn ($r) => is_array($r))->sortBy(fn ($r) => self::bingDate($r['Date'] ?? ''))->values();
            $last = $crawl->last();
            if ($last) {
                $out += ['inIndex' => (int) ($last['InIndex'] ?? 0), 'crawled' => (int) ($last['CrawledPages'] ?? 0), 'crawlErrors' => (int) ($last['CrawlErrors'] ?? 0),
                    'blockedByRobots' => (int) ($last['BlockedByRobotsTxt'] ?? 0), 'inLinks' => (int) ($last['InLinks'] ?? 0), 'asOf' => self::bingDate($last['Date'] ?? '')];
            } else {
                $out += ['inIndex' => null, 'note' => 'Bing has no crawl data for this site yet. New sites usually show numbers a few days after Bing first crawls them.'];
            }
            $since = now()->subDays(30)->getTimestamp();
            $traffic = collect((array) $get('GetRankAndTrafficStats'))->filter(fn ($r) => is_array($r) && (self::bingTs($r['Date'] ?? '') ?? 0) >= $since);
            $out += ['clicks30' => (int) $traffic->sum('Clicks'), 'impressions30' => (int) $traffic->sum('Impressions')];
        } catch (RequestException $e) {
            $code = $e->response->status();
            $msg = (string) ($e->response->json('Message') ?? $e->response->json('message') ?? '');
            $out['error'] = in_array($code, [401, 403], true) || stripos($msg, 'apikey') !== false || stripos($msg, 'api key') !== false
                ? 'Bing refused the API key. In Bing Webmaster Tools open Settings > API access, copy the API key again and save it in Integrations > Analytics.'
                : (stripos($msg, 'not verified') !== false || stripos($msg, 'NotAuthorized') !== false || $code === 400
                    ? 'Bing does not list '.$site.' as a verified site for this key. Add the site in Bing Webmaster Tools (Import from Google Search Console is the quickest), then refresh.'
                    : 'Bing Webmaster Tools did not answer ('.$code.'). Try Refresh later.');
        } catch (\Throwable $e) {
            $out['error'] = 'Could not reach Bing Webmaster Tools. Try Refresh later.';
        }

        return $out;
    }

    /** Bing's WCF dates look like /Date(1731283200000-0800)/. */
    private static function bingTs(string $d): ?int
    {
        return preg_match('#/Date\((-?\d+)#', $d, $m) ? intdiv((int) $m[1], 1000) : null;
    }

    private static function bingDate(string $d): ?string
    {
        $t = self::bingTs($d);

        return $t === null ? null : Carbon::createFromTimestamp($t, 'Asia/Kolkata')->toDateString();
    }

    public static function indexnow(): array
    {
        $st = FastIndex::state();
        $s = FastIndex::status();
        $last = collect($st['log'])->first(fn ($l) => ($l['indexnow'] ?? '') !== '' && ($l['urls'] ?? 0) > 0);

        return ['pages' => $s['pages'], 'sent' => $s['pages'] - $s['waiting'], 'waiting' => $s['waiting'], 'auto' => $s['auto'],
            'indexing' => $s['indexing'], 'keyUrl' => $s['keyUrl'], 'lastAt' => $last['at'] ?? null, 'lastText' => $last['indexnow'] ?? '', 'lastCount' => (int) ($last['urls'] ?? 0)];
    }
}
