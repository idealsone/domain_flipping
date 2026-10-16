<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Reads the text of a job posting from a public link. Every ATS check reads the page again, so the report always reflects the
 * posting as it is now. Only public http(s) pages are fetched: private, loopback and reserved addresses are refused (and the
 * resolved address is pinned for the request), redirects are re-checked at every hop, and size and time are capped.
 */
class JobPage
{
    private const MAX_BYTES = 2500000;

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['url' => $message]);
    }

    /** The page itself could not be read directly (blocked, empty, needs scripts): the owner's reading service may still manage. */
    private static function blocked(string $message): never
    {
        throw new JobPageBlocked($message);
    }

    /** Public job pages have a clean address; strip tracking and list-view parameters so the right posting is read. */
    public static function canonical(string $url): string
    {
        $u = trim($url);
        if (preg_match('~^https?://(?:[a-z]{2,3}\.|www\.)?linkedin\.com/(?:comm/)?jobs/view/(?:[^/?#]*-)?([0-9]{6,})~i', $u, $m)
            || preg_match('~^https?://(?:[a-z]{2,3}\.|www\.)?linkedin\.com/jobs/(?:collections|search)[^#]*[?&]currentJobId=([0-9]{6,})~i', $u, $m)) {
            return 'https://www.linkedin.com/jobs/view/'.$m[1].'/';
        }
        if (preg_match('~^https?://(?:[a-z]{2,3}\.)?indeed\.com/[^#]*[?&](?:vjk|jk)=([a-f0-9]{8,})~i', $u, $m)) {
            return 'https://'.(preg_match('~^https?://([a-z]{2,3})\.indeed\.com~i', $u, $h) ? strtolower($h[1]) : 'in').'.indeed.com/viewjob?jk='.$m[1];
        }

        return $u;
    }

    private static function allowPrivate(): bool
    {
        return config('app.env') !== 'production' && filter_var(env('ATS_FETCH_ALLOW_PRIVATE', false), FILTER_VALIDATE_BOOL);
    }

    /** @return string[] public IPv4 addresses for the host */
    private static function resolve(string $host): array
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) {
            self::fail('That link could not be found. Check the address and try again.');
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                self::fail('That link cannot be opened. Paste the job text or upload the PDF instead.');
            }
            if (! self::allowPrivate() && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                self::fail('That link cannot be opened. Use the public link of the job posting.');
            }
        }

        return $ips;
    }

    /** Reads the posting. Tries the page directly; if the site refuses or hides the text, uses the owner's reading service when one is switched on. */
    public static function fetch(string $url): array
    {
        $url = self::canonical($url);
        try {
            return self::direct($url);
        } catch (JobPageBlocked $e) {
            $via = JobReader::enabled() ? self::viaReader($url) : null;
            if ($via) {
                return $via;
            }
            self::fail($e->getMessage());
        }
    }

    /** @return array|null */
    private static function viaReader(string $url): ?array
    {
        $p = parse_url($url);
        if (! $p || empty($p['host'])) {
            return null;
        }
        self::resolve($p['host']);
        try {
            $html = JobReader::read($url);
        } catch (\Throwable) {
            return null;
        }
        try {
            $r = self::extract($html, $p['host']);
        } catch (ValidationException) {
            return null;
        }
        $r['via'] = 'reader';

        return $r;
    }

    private static function direct(string $url): array
    {
        $current = trim($url);
        for ($hop = 0; $hop < 4; $hop++) {
            $p = parse_url($current);
            if (! $p || ! in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || strlen($current) > 1000) {
                self::fail('Enter the full public link of the job posting, starting with https://');
            }
            $port = $p['port'] ?? (($p['scheme'] === 'https') ? 443 : 80);
            if (! self::allowPrivate() && ! in_array($port, [80, 443], true)) {
                self::fail('That link cannot be opened. Use the public link of the job posting.');
            }
            $ip = self::resolve($p['host'])[0];
            try {
                $res = Http::withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$p['host'].':'.$port.':'.$ip]]])
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; CVTaraBot/1.0; +https://cvtara.com)', 'Accept' => 'text/html,text/plain;q=0.9,*/*;q=0.5', 'Accept-Language' => 'en'])
                    ->connectTimeout(5)->timeout(12)->get($current);
            } catch (\Throwable) {
                self::blocked('That page did not respond. Try again, or paste the job text instead.');
            }
            $code = $res->status();
            if ($code >= 300 && $code < 400 && $res->header('Location')) {
                $current = self::absolute($current, $res->header('Location'));

                continue;
            }
            if (in_array($code, [401, 403, 429, 451, 999], true)) {
                self::blocked('This site does not allow automatic reading (many portals need a login). Copy the job description and paste it, or upload it as a PDF.');
            }
            if ($code === 404 || $code === 410) {
                self::fail('That job posting was not found — it may have been removed. Check the link.');
            }
            if ($code >= 400) {
                self::blocked('That page could not be read right now. Paste the job text or upload the PDF instead.');
            }
            $type = strtolower($res->header('Content-Type'));
            if (str_contains($type, 'pdf')) {
                self::fail('This link is a PDF. Download it and upload the PDF instead.');
            }
            if ($type !== '' && ! str_contains($type, 'html') && ! str_contains($type, 'text/plain') && ! str_contains($type, 'xml')) {
                self::fail('This link is not a web page. Paste the job text or upload the PDF instead.');
            }
            $body = $res->body();
            if (strlen($body) > self::MAX_BYTES) {
                self::fail('That page is too large to read. Paste the job text instead.');
            }

            return self::extract($body, $p['host']);
        }
        self::fail('That link redirects too many times. Use the final link of the job posting.');
    }

    private static function absolute(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        $origin = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
        if (str_starts_with($location, '//')) {
            return $p['scheme'].':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        return $origin.rtrim(dirname($p['path'] ?? '/'), '/').'/'.$location;
    }

    private static function text(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|svg|template|iframe)\b.*?</\1>#is', ' ', $html);
        $html = preg_replace('#<\s*(br|/p|/div|/li|/tr|/h[1-6]|/section|/article|/ul|/ol)\b[^>]*>#i', "\n", $html);
        $html = preg_replace('#<\s*li\b[^>]*>#i', "\n• ", $html);
        $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t\x{00A0}]+/u", ' ', $t);
        $t = preg_replace("/ ?\n ?/", "\n", $t);

        return trim(preg_replace("/\n{3,}/", "\n\n", $t));
    }

    private static function find(array $node): ?array
    {
        if (isset($node['@type']) && in_array('JobPosting', (array) $node['@type'], true)) {
            return $node;
        }
        foreach ($node as $v) {
            if (is_array($v) && ($f = self::find($v))) {
                return $f;
            }
        }

        return null;
    }

    /** Facts the posting itself publishes (structured data only — nothing is guessed from the page text). */
    private static function details(array $job): array
    {
        $str = fn ($v) => is_string($v) ? trim(strip_tags($v)) : (is_array($v) && isset($v['name']) && is_string($v['name']) ? trim($v['name']) : '');
        $out = [];
        $locs = $job['jobLocation'] ?? [];
        $locs = isset($locs['address']) || isset($locs['@type']) ? [$locs] : (is_array($locs) ? $locs : []);
        $places = [];
        foreach (array_slice($locs, 0, 4) as $l) {
            $a = is_array($l) ? ($l['address'] ?? []) : [];
            $bits = array_filter([$str($a['addressLocality'] ?? ''), $str($a['addressRegion'] ?? ''), $str($a['addressCountry'] ?? '')]);
            if ($bits) {
                $places[] = implode(', ', $bits);
            }
        }
        if (($job['jobLocationType'] ?? '') === 'TELECOMMUTE') {
            array_unshift($places, 'Remote');
        }
        if ($places) {
            $out['location'] = implode(' · ', array_unique($places));
        }
        $type = $job['employmentType'] ?? '';
        $types = array_map(fn ($t) => ucfirst(strtolower(str_replace('_', ' ', (string) $t))), is_array($type) ? $type : [$type]);
        if (array_filter($types)) {
            $out['employmentType'] = implode(', ', array_filter($types));
        }
        $sal = $job['baseSalary'] ?? null;
        if (is_array($sal)) {
            $v = $sal['value'] ?? [];
            $min = $v['minValue'] ?? null;
            $max = $v['maxValue'] ?? null;
            $one = $v['value'] ?? null;
            $cur = $str($sal['currency'] ?? '');
            $unit = strtolower($str($v['unitText'] ?? ''));
            $range = is_numeric($min) && is_numeric($max) ? number_format((float) $min).' – '.number_format((float) $max) : (is_numeric($one) ? number_format((float) $one) : '');
            if ($range !== '') {
                $out['salary'] = trim($cur.' '.$range.($unit ? ' per '.$unit : ''));
            }
        }
        foreach (['datePosted' => 'posted', 'validThrough' => 'expires'] as $k => $label) {
            if (! empty($job[$k]) && is_string($job[$k]) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}/', $job[$k], $m)) {
                $out[$label] = $m[0];
            }
        }
        foreach (['industry' => 'industry', 'occupationalCategory' => 'category'] as $k => $label) {
            if ($str($job[$k] ?? '') !== '') {
                $out[$label] = mb_substr($str($job[$k]), 0, 120);
            }
        }
        $skills = $job['skills'] ?? '';
        $skills = is_array($skills) ? implode(', ', array_map($str, $skills)) : $str($skills);
        if ($skills !== '') {
            $out['skills'] = mb_substr($skills, 0, 300);
        }
        $exp = $job['experienceRequirements'] ?? null;
        if (is_array($exp) && isset($exp['monthsOfExperience']) && is_numeric($exp['monthsOfExperience'])) {
            $out['experience'] = round($exp['monthsOfExperience'] / 12, 1).' years';
        }

        return array_map(fn ($v) => mb_substr((string) $v, 0, 300), $out);
    }

    private static function extract(string $html, string $host): array
    {
        $title = '';
        $company = '';
        $via = 'page';
        $text = '';
        $details = [];
        if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
            foreach ($m[1] as $json) {
                $d = json_decode(trim(html_entity_decode($json, ENT_QUOTES | ENT_HTML5)), true);
                if (! is_array($d) || ! ($job = self::find($d))) {
                    continue;
                }
                $parts = [];
                foreach (['description', 'responsibilities', 'qualifications', 'skills', 'experienceRequirements', 'educationRequirements'] as $k) {
                    $v = $job[$k] ?? null;
                    if (is_array($v)) {
                        $v = implode("\n", array_map(fn ($x) => is_array($x) ? ($x['description'] ?? json_encode($x)) : (string) $x, $v));
                    }
                    if (is_string($v) && trim($v) !== '') {
                        $parts[] = ($k === 'description' ? '' : ucfirst($k).":\n").self::text(str_contains($v, '<') ? $v : nl2br(e($v)));
                    }
                }
                $text = implode("\n\n", $parts);
                $title = is_string($job['title'] ?? null) ? $job['title'] : '';
                $org = $job['hiringOrganization'] ?? null;
                $company = is_array($org) ? (string) ($org['name'] ?? '') : (is_string($org) ? $org : '');
                $via = 'structured';
                $details = self::details($job);
                break;
            }
        }
        if (strlen($text) < 200) {
            $body = preg_match('#<body\b[^>]*>(.*)</body>#is', $html, $b) ? $b[1] : $html;
            $body = preg_replace('#<(nav|header|footer|form|aside)\b.*?</\1>#is', ' ', $body);
            $text = self::text($body);
            $via = 'page';
        }
        if ($title === '' && preg_match('#<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)#i', $html, $t)) {
            $title = html_entity_decode($t[1], ENT_QUOTES | ENT_HTML5);
        }
        if ($title === '' && preg_match('#<title[^>]*>(.*?)</title>#is', $html, $t)) {
            $title = trim(html_entity_decode(strip_tags($t[1]), ENT_QUOTES | ENT_HTML5));
        }
        if ($company === '' && preg_match('#<meta[^>]+property=["\']og:site_name["\'][^>]+content=["\']([^"\']+)#i', $html, $t)) {
            $company = html_entity_decode($t[1], ENT_QUOTES | ENT_HTML5);
        }
        if ($company === '' && preg_match('/^(.{3,120}?)\s+(?:at|@|-|–|—|\|)\s+([^|\-–—]{2,80})(?:\s+[|\-–—].*)?$/u', $title, $tm)) {
            $title = trim($tm[1]);
            $company = trim($tm[2]);
        }
        if (mb_strlen($text) < 200) {
            self::blocked('Not enough job text could be read from this page — it may need a login or load its jobs with scripts. Copy the job description and paste it, or upload it as a PDF.');
        }

        return ['text' => mb_substr($text, 0, 40000), 'title' => mb_substr(trim($title), 0, 180), 'company' => mb_substr(trim($company), 0, 180), 'host' => $host, 'via' => $via, 'details' => $details];
    }
}

/** Internal signal: the page cannot be read directly. */
class JobPageBlocked extends \RuntimeException
{
}
