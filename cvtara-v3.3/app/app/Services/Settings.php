<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Settings
{
    private static array $memo = [];

    private static ?array $defaults = null;

    private static ?array $editorial = null;

    public static function forget(): void
    {
        self::$memo = [];
    }

    public static function defaults(): array
    {
        return self::$defaults ??= json_decode(file_get_contents(resource_path('data/defaults.json')), true);
    }

    // Read each setting once per request; saving clears the memo.
    public static function get(string $key, $fallback = null)
    {
        $k = $key.'|'.md5(json_encode($fallback));
        if (array_key_exists($k, self::$memo)) {
            return self::$memo[$k];
        }

        return self::$memo[$k] = self::read($key, $fallback);
    }

    private static function read(string $key, $fallback)
    {
        $r = DB::table('site_settings')->where('key', $key)->first();
        $value = $r ? json_decode($r->value_json, true) : (self::defaults()[$key] ?? $fallback);
        if ($key === 'blog') {
            foreach ((self::$editorial ??= json_decode(file_get_contents(resource_path('data/editorial-content.json')), true)) as $slug => $post) {
                if (empty($value[$slug]['body'])) {
                    $value[$slug] = array_merge($post, $value[$slug] ?? [], ['body' => $post['body']]);
                }
            }
        }

return $value;
    }

    public static function revision(string $key): int
    {
        return (int) (DB::table('site_settings')->where('key', $key)->value('revision') ?? 0);
    }

    public static function put(string $key, $data): void
    {
        self::$memo = [];
        if (! in_array($key, ['providers', 'operator', 'integrations', 'speed', 'promos', 'campaignSettings'], true)) {
            PageCache::purge();
        }
        $json = json_encode($data);
        if (DB::table('site_settings')->where('key', $key)->update(['value_json' => $json, 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]) === 0) {
            DB::table('site_settings')->insertOrIgnore(['key' => $key, 'value_json' => $json, 'revision' => 1, 'updated_at' => now(), 'created_at' => now()]);
        }
    }

    public static function providers(): array
    {
        $p = self::get('providers', '');

        return $p ? json_decode(Crypt::decryptString($p), true) : [];
    }

    /**
     * Packages are paid once, with no auto-renewal. Quick Download covers one resume for 7 days; Job Hunt and Pro add tailored
     * resumes, cover letters and ATS Match scans. Services are done by a person and stay off until the owner switches them on.
     */
    public static function planDefaults(): array
    {
        $mk = fn ($name, $tag, $price, $days, $resumes, $letters, $ats, $rank, $perks, $extra = []) => array_merge(['type' => 'plan', 'name' => $name, 'audience' => 'all', 'tagline' => $tag, 'price' => $price, 'mrp' => 0, 'accessDays' => $days, 'savedResumes' => $resumes, 'savedLetters' => $letters, 'atsScans' => $ats, 'dailyMatches' => 100, 'exports' => 0, 'rank' => $rank, 'perks' => $perks, 'popular' => false, 'bestValue' => false, 'referral' => false, 'sale' => true], $extra);

        return [
            'free' => ['type' => 'plan', 'name' => 'Free', 'audience' => 'all', 'tagline' => 'Build, edit and preview with a light watermark. Pay only when you download.', 'price' => 0, 'mrp' => 0, 'accessDays' => 0, 'savedResumes' => 1, 'savedLetters' => 1, 'atsScans' => null, 'dailyMatches' => 3, 'exports' => 0, 'rank' => 0, 'perks' => [], 'popular' => false, 'bestValue' => false, 'referral' => false, 'sale' => false],
            'quick' => $mk('Quick Download', 'Download one finished resume without the watermark', 99, 7, 1, 0, 0, 1, ['1 resume as clean PDF and Word', 'Edit and re-download that resume as often as you like for 7 days', '3 free ATS checks a day'], ['oneResume' => true, 'dailyMatches' => 3]),
            'job-hunt' => $mk('Job Hunt', 'Tailor your resume and a cover letter for each job you apply to', 299, 30, 5, 5, 20, 2, ['5 tailored resumes and 5 cover letters', '20 ATS Match scans with a gap report', 'Application tracker with reminders'], ['popular' => true]),
            'pro' => $mk('Pro', 'For a longer search across many jobs', 599, 90, 20, 20, 60, 3, ['20 tailored resumes and 20 cover letters', '60 ATS Match scans with a gap report', 'Application tracker with reminders'], ['bestValue' => true]),
            'complete-kit' => $mk('Complete Job Kit', 'Pro, plus a recruiter review and a LinkedIn and Naukri profile rewrite', 1299, 90, 20, 20, 60, 3, ['Everything in Pro', 'Tara Expert Review by a working recruiter', 'Profile Boost for LinkedIn and Naukri'], ['mrp' => 1797, 'includes' => ['expert-review', 'profile-boost'], 'sale' => false]),
        ] + self::serviceDefaults() + self::addonDefaults();
    }

    /** Done by a person, not by software. Cheaper while Job Hunt or Pro is active (planPrice). */
    public static function serviceDefaults(): array
    {
        $s = fn ($name, $tag, $price, $planPrice, $perks) => ['type' => 'service', 'name' => $name, 'audience' => 'all', 'tagline' => $tag, 'price' => $price, 'planPrice' => $planPrice, 'mrp' => 0, 'accessDays' => 0, 'savedResumes' => 0, 'savedLetters' => 0, 'atsScans' => 0, 'dailyMatches' => 0, 'exports' => 0, 'rank' => 0, 'perks' => $perks, 'popular' => false, 'bestValue' => false, 'referral' => false, 'sale' => false];

        return [
            'expert-review' => $s('Tara Expert Review', 'A working recruiter reviews your resume within 48 working hours', 499, 399, ['Comments on every section', 'A short summary and your top fixes', 'One follow-up question']),
            'profile-boost' => $s('Profile Boost', 'Your LinkedIn and Naukri headline, summary, skills and experience rewritten', 699, 599, ['Headline and About or profile summary', 'Key skills in the right order for your target job', 'One free revision within 7 days']),
        ];
    }

    /** Extra credit packs for an active plan. Off by default; the owner can put them on sale in Pricing. */
    public static function addonDefaults(): array
    {
        $a = fn ($n, $price) => ['type' => 'addon', 'name' => "Add-on · $n resumes + $n cover letters", 'audience' => 'all', 'tagline' => "Extra $n resume downloads, $n cover-letter downloads and ".($n * 2)." ATS scans", 'price' => $price, 'mrp' => 0, 'accessDays' => 0, 'savedResumes' => $n, 'savedLetters' => $n, 'atsScans' => $n * 2, 'dailyMatches' => 0, 'exports' => 0, 'rank' => 0, 'perks' => [], 'popular' => false, 'bestValue' => false, 'referral' => false, 'sale' => false];

        return ['addon-10' => $a(10, 149), 'addon-20' => $a(20, 249), 'addon-30' => $a(30, 399)];
    }

    /** Plan keys sold before the 3.16 packages, mapped to the package that replaced them (old links, coupons and offers). */
    public static function planAlias(string $key): string
    {
        return ['fresher-basic' => 'job-hunt', 'fresher-standard' => 'job-hunt', 'pro-basic' => 'job-hunt', 'pro-standard' => 'job-hunt', 'fresher-pro' => 'pro', 'pro-pro' => 'pro'][$key] ?? $key;
    }

    /** Everything a customer can buy right now. */
    public static function paidPlanKeys(): array
    {
        return array_keys(array_filter(self::plans(), fn ($p, $k) => $k !== 'free' && ! empty($p['sale']), ARRAY_FILTER_USE_BOTH));
    }

    public static function plans(): array
    {
        $defaults = self::planDefaults();
        $saved = array_intersect_key((array) self::get('plans', []), $defaults);

        $merged = array_intersect_key(array_replace_recursive($defaults, $saved), $defaults);
        foreach ($saved as $k => $p) {
            if (isset($p['perks']) && is_array($p['perks'])) {
                $merged[$k]['perks'] = array_values($p['perks']);
            }
        }

        return $merged;
    }

    private static function pick(iterable $rows): ?array
    {
        $best = null;
        foreach ($rows as $r) {
            $l = json_decode($r->limits_json, true) ?: [];
            if (str_starts_with($r->plan, 'addon-') || ($l['type'] ?? 'plan') === 'service') {
                continue;
            }
            $key = [(int) ($l['rank'] ?? 0), strtotime($r->access_until)];
            if (! $best || $key > $best[0]) {
                $best = [$key, $r, $l];
            }
        }

        return $best;
    }

    private static function shape(?array $best, iterable $addons = []): array
    {
        $free = self::plans()['free'];
        if (! $best) {
            return ['plan' => 'free', 'paid' => false, 'limits' => $free, 'accessUntil' => null, 'purchase' => null, 'extras' => ['resumes' => 0, 'letters' => 0]];
        }
        $limits = array_merge($free, $best[2]);
        $extra = ['resumes' => 0, 'letters' => 0];
        foreach ($addons as $r) {
            $l = json_decode($r->limits_json, true) ?: [];
            $extra['resumes'] += (int) ($l['savedResumes'] ?? 0);
            $extra['letters'] += (int) ($l['savedLetters'] ?? 0);
            $limits['exports'] = (int) ($limits['exports'] ?? 0) + (int) ($l['exports'] ?? 0);
        }
        $limits['savedResumes'] += $extra['resumes'];
        $limits['savedLetters'] += $extra['letters'];

        return ['plan' => $best[1]->plan, 'paid' => true, 'limits' => $limits, 'accessUntil' => $best[1]->access_until, 'purchase' => $best[1], 'extras' => $extra];
    }

    // The strongest active purchase wins. A partial refund keeps access; a full refund or dispute removes it.
    public static function access(int $user): array
    {
        $rows = DB::table('purchases')->where('owner_id', $user)->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now())->get();

        return self::shape(self::pick($rows), $rows->filter(fn ($r) => str_starts_with($r->plan, 'addon-')));
    }

    public static function accessMany(array $ids): array
    {
        $by = DB::table('purchases')->whereIn('owner_id', $ids)->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now())->get()->groupBy('owner_id');
        $out = [];
        foreach ($ids as $id) {
            $rows = $by[$id] ?? collect();
            $out[$id] = self::shape(self::pick($rows), $rows->filter(fn ($r) => str_starts_with($r->plan, 'addon-')));
        }

        return $out;
    }

    public static function audit($user, string $event, string $ref = ''): void
    {
        DB::table('activity_events')->insert(['id' => (string) Str::uuid(), 'owner_id' => $user, 'event' => $event, 'reference' => substr($ref, 0, 255), 'created_at' => now(), 'updated_at' => now()]);
    }
}
