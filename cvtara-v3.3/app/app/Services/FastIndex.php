<?php

namespace App\Services;

use App\Http\Controllers\ContentController;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Tells search engines about new and changed public pages straight away.
 * IndexNow (Bing, Yandex, Seznam, Naver) indexes on request. Google does not take IndexNow; for Google the supported
 * route is the sitemap, resubmitted through the Search Console API. The Google Indexing API is officially only for
 * job-posting and livestream pages, so it is off by default, best effort, and kept inside Google's daily quota.
 */
class FastIndex
{
    public const GOOGLE_DAILY = 200;

    // Google requests per run, so a click or a cron run stays well inside the host's time limit; the rest wait for the next run.
    public const GOOGLE_PER_RUN = 40;

    public static function state(): array
    {
        return array_merge(['key' => '', 'auto' => false, 'google' => false, 'sent' => [], 'googleSent' => [], 'googleDay' => '', 'googleUsed' => 0, 'sitemapAt' => null, 'log' => []], (array) Settings::get('fastIndex', []));
    }

    private static function save(array $s): void
    {
        $s['log'] = array_slice($s['log'], 0, 30);
        Settings::put('fastIndex', $s);
    }

    public static function origin(): string
    {
        return rtrim(Settings::get('seo')['siteUrl'] ?: config('app.url'), '/');
    }

    /** The IndexNow key, made once. Search engines check it is ours by fetching /{key}.txt. */
    public static function key(): string
    {
        $s = self::state();
        if (! preg_match('/^[a-f0-9]{32}$/', $s['key'])) {
            $s['key'] = bin2hex(random_bytes(16));
            self::save($s);
        }

        return $s['key'];
    }

    /** Public sitemap pages, each with a fingerprint of its title and description, so a page counts as changed when they change. */
    public static function pages(): array
    {
        $out = [];
        foreach (app(ContentController::class)->pages() as $p) {
            if ($p['included']) {
                $out[$p['path']] = substr(md5($p['title'].'|'.$p['description']), 0, 12);
            }
        }

        return $out;
    }

    private static function googleDay(): string
    {
        // Google's Indexing API quota resets at midnight Pacific time.
        return now('America/Los_Angeles')->toDateString();
    }

    public static function status(): array
    {
        $s = self::state();
        $pages = self::pages();
        $key = self::key();
        $used = $s['googleDay'] === self::googleDay() ? $s['googleUsed'] : 0;

        return [
            'key' => $key, 'keyUrl' => self::origin().'/'.$key.'.txt', 'auto' => $s['auto'], 'google' => $s['google'],
            'indexing' => (bool) Settings::get('seo')['searchIndexing'], 'searchConsole' => SearchConsole::ready(), 'property' => SearchConsole::property(),
            'pages' => count($pages), 'waiting' => count(array_diff_assoc($pages, $s['sent'])), 'googleWaiting' => count(array_diff_assoc($pages, $s['googleSent'])),
            'googleUsed' => $used, 'googleLimit' => self::GOOGLE_DAILY, 'sitemapAt' => $s['sitemapAt'], 'log' => $s['log'],
        ];
    }

    public static function settings(bool $auto, bool $google): void
    {
        $s = self::state();
        $s['auto'] = $auto;
        $s['google'] = $google;
        self::save($s);
    }

    /**
     * Sends pages to IndexNow, and to Google when that is switched on. $paths null means every new or changed page;
     * 'all' means every sitemap page. Returns the log entry.
     */
    public static function submit(array|string|null $paths = null, ?bool $google = null): array
    {
        abort_unless(Settings::get('seo')['searchIndexing'], 422, 'Search engines are blocked in SEO settings (search indexing is off), so nothing was sent.');
        $key = self::key();
        $s = self::state();
        $google ??= $s['google'];
        $pages = self::pages();
        $origin = self::origin();
        $now = now()->toIso8601String();
        $entry = ['at' => $now, 'indexnow' => '', 'google' => '', 'urls' => 0];
        $pick = fn (array $sent) => match (true) {
            $paths === 'all' => array_keys($pages),
            is_array($paths) => $paths,
            default => array_keys(array_diff_assoc($pages, $sent)),
        };

        $send = $pick($s['sent']);
        $entry['urls'] = count($send);
        if ($send) {
            $ok = 0;
            $note = '';
            foreach (array_chunk($send, 10000) as $chunk) {
                try {
                    $res = Http::timeout(20)->acceptJson()->post(SearchConsole::base('https://api.indexnow.org').'/indexnow', ['host' => parse_url($origin, PHP_URL_HOST), 'key' => $key, 'keyLocation' => $origin.'/'.$key.'.txt', 'urlList' => array_map(fn ($p) => $origin.$p, $chunk)]);
                } catch (\Illuminate\Http\Client\ConnectionException) {
                    $note = ' IndexNow could not be reached; it will try again next hour.';
                    break;
                }
                if (in_array($res->status(), [200, 202], true)) {
                    $ok += count($chunk);
                    foreach ($chunk as $p) {
                        if (isset($pages[$p])) {
                            $s['sent'][$p] = $pages[$p];
                        }
                    }
                } else {
                    $note = match ($res->status()) {
                        403 => ' The key file '.$origin.'/'.$key.'.txt could not be checked; make sure the site is live on '.$origin.'.',
                        422 => ' The addresses do not belong to '.$origin.'.',
                        429 => ' Too many requests; it will try again next hour.',
                        default => ' Answer '.$res->status().'.',
                    };
                    break;
                }
            }
            $entry['indexnow'] = $ok === count($send) ? "Sent $ok page(s) to Bing and other IndexNow engines." : "Sent $ok of ".count($send).'.'.$note;
        } else {
            $entry['indexnow'] = 'Nothing new or changed for IndexNow.';
        }

        if ($google) {
            $entry['google'] = self::google($s, $pick($s['googleSent']), $pages, $origin);
        }
        array_unshift($s['log'], $entry);
        self::save($s);

        return $entry;
    }

    private static function google(array &$s, array $send, array $pages, string $origin): string
    {
        if (! SearchConsole::ready()) {
            return 'Google: add the Search Console property and service-account JSON in Integrations first.';
        }
        if ($s['googleDay'] !== self::googleDay()) {
            $s['googleDay'] = self::googleDay();
            $s['googleUsed'] = 0;
        }
        $left = self::GOOGLE_DAILY - $s['googleUsed'];
        if (! $send) {
            return 'Google: nothing new or changed.';
        }
        if ($left <= 0) {
            return 'Google: today\'s '.self::GOOGLE_DAILY.' requests are used; the rest go tomorrow.';
        }
        try {
            $token = SearchConsole::token(SearchConsole::INDEXING);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return 'Google: '.$e->getMessage();
        }
        $done = 0;
        $error = '';
        foreach (array_slice($send, 0, min($left, self::GOOGLE_PER_RUN)) as $p) {
            try {
                Http::withToken($token)->timeout(15)->post(SearchConsole::base('https://indexing.googleapis.com').'/v3/urlNotifications:publish', ['url' => $origin.$p, 'type' => 'URL_UPDATED'])->throw();
            } catch (\Illuminate\Http\Client\ConnectionException) {
                $error = ' Google could not be reached.';
                break;
            } catch (RequestException $e) {
                $error = $e->response->status() === 403 ? ' Google refused: enable the Web Search Indexing API and add the service account as an Owner in Search Console.' : ($e->response->status() === 429 ? ' Google\'s daily quota is used up.' : ' Google answered '.$e->response->status().'.');
                if ($e->response->status() === 429) {
                    $s['googleUsed'] = self::GOOGLE_DAILY;
                }
                break;
            }
            $done++;
            $s['googleUsed']++;
            if (isset($pages[$p])) {
                $s['googleSent'][$p] = $pages[$p];
            }
        }
        $rest = count($send) - $done;

        return "Google: asked to recrawl $done page(s)".($rest > 0 && $error === '' ? "; $rest wait for the next run" : '').'.'.$error;
    }

    public static function sitemap(): string
    {
        abort_unless(Settings::get('seo')['sitemapEnabled'], 422, 'The sitemap is switched off in SEO settings.');
        $url = self::origin().'/sitemap.xml';
        SearchConsole::submitSitemap($url);
        $s = self::state();
        $s['sitemapAt'] = now()->toIso8601String();
        array_unshift($s['log'], ['at' => $s['sitemapAt'], 'indexnow' => '', 'google' => 'Sitemap '.$url.' resubmitted to Google Search Console.', 'urls' => 0]);
        self::save($s);

        return $url;
    }

    /** Hourly from cron when automatic sending is on: new or changed pages go out, and Google gets the sitemap again once a day if pages changed. */
    public static function run(): void
    {
        $s = self::state();
        if (! $s['auto'] || ! Settings::get('seo')['searchIndexing']) {
            return;
        }
        $changed = array_diff_assoc(self::pages(), $s['sent']) !== [];
        if ($changed || ($s['google'] && array_diff_assoc(self::pages(), $s['googleSent']) !== [])) {
            self::submit();
        }
        if ($changed && SearchConsole::ready() && (! $s['sitemapAt'] || now()->parse($s['sitemapAt'])->lt(now()->subDay()))) {
            try {
                self::sitemap();
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $s = self::state();
                array_unshift($s['log'], ['at' => now()->toIso8601String(), 'indexnow' => '', 'google' => $e->getMessage(), 'urls' => 0]);
                self::save($s);
            }
        }
    }
}
