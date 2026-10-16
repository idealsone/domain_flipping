<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Optional reading service for job pages that refuse plain requests (login walls, bot checks, pages built with scripts).
 * The owner switches it on in Owner Console → Speed & traffic and adds the key of a page-rendering API. Nothing is sent to it
 * unless a customer pastes a job link that could not be read directly; only the job link leaves the site, never resume content.
 */
class JobReader
{
    public const PROVIDERS = ['scrapingbee', 'zenrows', 'custom'];

    public static function config(): array
    {
        return Settings::providers()['jobreader'] ?? [];
    }

    public static function enabled(): bool
    {
        $c = self::config();

        return ($c['enabled'] ?? 'false') === 'true' && self::endpoint('https://example.com/job') !== null;
    }

    private static function endpoint(string $url): ?string
    {
        $c = self::config();
        $p = $c['provider'] ?? 'scrapingbee';
        if ($p === 'scrapingbee' && ! empty($c['scrapingbeeApiKey'])) {
            return 'https://app.scrapingbee.com/api/v1/?'.http_build_query(['api_key' => $c['scrapingbeeApiKey'], 'url' => $url, 'render_js' => 'true']);
        }
        if ($p === 'zenrows' && ! empty($c['zenrowsApiKey'])) {
            return 'https://api.zenrows.com/v1/?'.http_build_query(['apikey' => $c['zenrowsApiKey'], 'url' => $url, 'js_render' => 'true']);
        }
        if ($p === 'custom' && ! empty($c['customUrlTemplate']) && str_contains($c['customUrlTemplate'], '{url}') && (str_starts_with($c['customUrlTemplate'], 'https://') || (config('app.env') !== 'production' && filter_var(env('ATS_FETCH_ALLOW_PRIVATE', false), FILTER_VALIDATE_BOOL) && str_starts_with($c['customUrlTemplate'], 'http://')))) {
            return str_replace(['{url}', '{key}'], [rawurlencode($url), rawurlencode((string) ($c['customApiKey'] ?? ''))], $c['customUrlTemplate']);
        }

        return null;
    }

    public static function usage(): array
    {
        return ['today' => (int) Cache::get('jobreader:'.now()->format('Ymd'), 0), 'limit' => (int) (self::config()['dailyLimit'] ?? 200)];
    }

    /** Returns the page HTML as rendered by the service. Throws when the daily limit is reached or the service fails. */
    public static function read(string $url): string
    {
        $endpoint = self::endpoint($url);
        if (! $endpoint) {
            throw new \RuntimeException('Reader is not set up.');
        }
        $key = 'jobreader:'.now()->format('Ymd');
        Cache::add($key, 0, now()->addDays(2));
        if (Cache::increment($key) > max(1, (int) (self::config()['dailyLimit'] ?? 200))) {
            throw new \RuntimeException('Daily reading limit reached.');
        }
        $res = Http::timeout(45)->connectTimeout(8)->withHeaders(['Accept' => 'text/html,*/*'])->get($endpoint);
        if (! $res->successful() || strlen($res->body()) > 3000000) {
            throw new \RuntimeException('Reader could not open the page.');
        }

        return $res->body();
    }
}
