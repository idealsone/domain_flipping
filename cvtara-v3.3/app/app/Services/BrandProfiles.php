<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The register of CVTara's directory and brand listings (V5.0 §2.5, §9): where CVTara is listed, the public URL, its
 * state (not listed, pending, live, needs a fix), whether it is claimed and verified, and when it was last checked.
 * Checking only opens the public URL; nothing is submitted anywhere automatically.
 */
class BrandProfiles
{
    public const STATES = ['not_listed' => 'Not listed', 'pending' => 'Pending', 'live' => 'Live', 'needs_fix' => 'Needs a fix'];

    public const KINDS = ['search' => 'Search engine', 'social' => 'Social profile', 'directory' => 'Software directory', 'review' => 'Review site', 'business' => 'Business listing'];

    private const SEED = [
        ['Google Search Console', 'search', '', 'live', true, true, 'Domain property sc-domain:cvtara.com connected.'],
        ['Bing Webmaster Tools', 'search', '', 'not_listed', false, false, 'Add the site, verify, submit the sitemap.'],
        ['Google Business Profile', 'business', '', 'not_listed', false, false, 'Only if CVTara can show a real business address.'],
        ['LinkedIn', 'social', 'https://www.linkedin.com/company/cvtara-ats-resume-builder', 'live', true, false, ''],
        ['Instagram', 'social', 'https://www.instagram.com/cvtara_ats_resume_builder', 'live', true, false, ''],
        ['Facebook', 'social', 'https://www.facebook.com/cvtara.ats.resume.builder', 'live', true, false, ''],
        ['X (Twitter)', 'social', 'https://x.com/cvtara_resume', 'live', true, false, ''],
        ['Crunchbase', 'directory', '', 'not_listed', false, false, ''],
        ['Product Hunt', 'directory', '', 'not_listed', false, false, ''],
        ['G2', 'review', '', 'not_listed', false, false, ''],
        ['Capterra', 'review', '', 'not_listed', false, false, ''],
        ['AlternativeTo', 'directory', '', 'not_listed', false, false, ''],
        ['SaaSHub', 'directory', '', 'not_listed', false, false, ''],
    ];

    public static function seed(): void
    {
        if (! Schema::hasTable('brand_profiles')) {
            return;
        }
        foreach (self::SEED as [$p, $k, $url, $st, $c, $v, $note]) {
            DB::table('brand_profiles')->insertOrIgnore(['platform' => $p, 'kind' => $k, 'url' => $url, 'status' => $st, 'claimed' => $c, 'verified' => $v, 'note' => $note, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public static function save(?int $by, array $v): int
    {
        $row = ['kind' => $v['kind'], 'url' => trim((string) ($v['url'] ?? '')), 'status' => $v['status'], 'claimed' => (bool) ($v['claimed'] ?? false), 'verified' => (bool) ($v['verified'] ?? false),
            'note' => mb_substr(trim((string) ($v['note'] ?? '')), 0, 500), 'updated_by' => $by, 'updated_at' => now()];
        abort_if($row['status'] === 'live' && $row['url'] === '', 422, 'A live listing needs its public URL.');
        if ($row['url'] !== '') {
            $host = (string) parse_url($row['url'], PHP_URL_HOST);
            abort_unless(str_starts_with($row['url'], 'https://') && $host !== '' && str_contains($host, '.') && ! filter_var($host, FILTER_VALIDATE_IP) && ! preg_match('/(^|\.)(localhost|local|internal)$/i', $host), 422, 'Use the listing\'s public https:// address.');
        }
        abort_if($row['verified'] && ! $row['claimed'], 422, 'A listing has to be claimed before it can be verified.');
        if (! empty($v['id'])) {
            abort_unless(DB::table('brand_profiles')->where('id', $v['id'])->update($row + ['platform' => mb_substr(trim($v['platform']), 0, 80)]), 404);
            $id = (int) $v['id'];
        } else {
            abort_if(DB::table('brand_profiles')->where('platform', trim($v['platform']))->exists(), 422, 'That platform is already in the register.');
            $id = (int) DB::table('brand_profiles')->insertGetId($row + ['platform' => mb_substr(trim($v['platform']), 0, 80), 'created_at' => now()]);
        }
        Settings::audit($by, 'brand_profile_saved', (string) $id);

        return $id;
    }

    /** Opens the public URL and records its status. A listing that stops opening is marked as needing a fix. */
    public static function check(int $id): array
    {
        $p = DB::table('brand_profiles')->where('id', $id)->first();
        abort_unless($p, 404);
        abort_if($p->url === '', 422, 'Add the listing URL first.');
        $code = null;
        try {
            $code = \Illuminate\Support\Facades\Http::withHeaders(['User-Agent' => 'CVTara listing check'])->timeout(10)->withOptions(['allow_redirects' => ['max' => 4]])->get($p->url)->status();
        } catch (\Throwable) {
            $code = 0;
        }
        $upd = ['http_status' => $code, 'last_checked_at' => now(), 'updated_at' => now()];
        if ($p->status === 'live' && ($code === 0 || $code >= 400) && ! in_array($code, [401, 403, 429, 999], true)) {
            $upd['status'] = 'needs_fix';
        }
        DB::table('brand_profiles')->where('id', $id)->update($upd);

        return (array) DB::table('brand_profiles')->where('id', $id)->first();
    }
}
