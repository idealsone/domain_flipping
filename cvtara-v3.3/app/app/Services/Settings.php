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

    public static function planDefaults(): array
    {
        $mk = fn ($name, $aud, $tag, $price, $mrp, $days, $docs, $ats, $rank, $perks, $extra = []) => array_merge(['type' => 'plan', 'name' => $name, 'audience' => $aud, 'tagline' => $tag, 'price' => $price, 'mrp' => $mrp, 'accessDays' => $days, 'savedResumes' => $docs, 'savedLetters' => $docs, 'atsScans' => $ats, 'dailyMatches' => 100, 'exports' => 0, 'rank' => $rank, 'perks' => $perks, 'popular' => false, 'referral' => false], $extra);

        return [
            'free' => ['type' => 'plan', 'name' => 'Normal user', 'audience' => 'all', 'tagline' => 'Create an account, build and preview — subscribe to download', 'price' => 0, 'mrp' => 0, 'accessDays' => 0, 'savedResumes' => 1, 'savedLetters' => 2, 'atsScans' => null, 'dailyMatches' => 3, 'exports' => 0, 'rank' => 0, 'perks' => [], 'popular' => false, 'referral' => false],
            'fresher-basic' => $mk('Fresher Basic', 'fresher', 'Perfect for your first job application', 299, 399, 60, 10, 10, 1, ['PDF & Word downloads', 'Email support']),
            'fresher-standard' => $mk('Fresher Standard', 'fresher', 'Apply to more jobs with more variations', 399, 549, 60, 20, null, 2, ['Unlimited ATS scans', 'Priority support'], ['popular' => true]),
            'fresher-pro' => $mk('Fresher Pro', 'fresher', 'Maximum applications, maximum chances', 499, 699, 90, 30, null, 3, ['Unlimited ATS Scans', 'Priority Support', 'Referral Programme Access'], ['referral' => true]),
            'pro-basic' => $mk('Professional Basic', 'experienced', 'For professionals targeting new opportunities', 399, 549, 60, 10, 10, 1, ['PDF & Word downloads', 'Email support']),
            'pro-standard' => $mk('Professional Standard', 'experienced', 'Most chosen by mid-level professionals', 499, 699, 60, 20, null, 2, ['Unlimited ATS scans', 'Priority support'], ['popular' => true]),
            'pro-pro' => $mk('Professional Pro', 'experienced', 'For senior professionals & career changers', 599, 849, 90, 30, null, 3, ['Unlimited ATS Scans', '1-on-1 Resume Review Call', 'Referral Programme Access'], ['referral' => true]),
        ] + self::addonDefaults();
    }

    public static function addonDefaults(): array
    {
        $a = fn ($n, $price) => ['type' => 'addon', 'name' => "Add-on · $n resumes + $n cover letters", 'audience' => 'all', 'tagline' => "Extra $n JD-tailored resumes and $n cover letters", 'price' => $price, 'mrp' => 0, 'accessDays' => 45, 'savedResumes' => $n, 'savedLetters' => $n, 'atsScans' => null, 'dailyMatches' => 0, 'exports' => 0, 'rank' => 0, 'perks' => [], 'popular' => false, 'referral' => false];

        return ['addon-10' => $a(10, 149), 'addon-20' => $a(20, 249), 'addon-30' => $a(30, 399)];
    }

    public static function paidPlanKeys(): array
    {
        return array_values(array_diff(array_keys(self::planDefaults()), ['free']));
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
            if (str_starts_with($r->plan, 'addon-')) {
                continue;
            }
            $l = json_decode($r->limits_json, true) ?: [];
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
            $free['credits'] = (int) $free['savedResumes'] + (int) $free['savedLetters'];

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
        // One shared pool of document credits: every tailored resume or cover letter uses one, whichever kind it is.
        $limits['credits'] = (int) $limits['savedResumes'] + (int) $limits['savedLetters'];

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

    /** Credits already used: tailored resumes (the primary resume is free) plus cover letters. */
    public static function creditsUsed(int $user): array
    {
        $tailored = (int) DB::table('resume_drafts')->where('owner_id', $user)->where('is_primary', false)->count();
        $letters = (int) DB::table('cover_letter_drafts')->where('owner_id', $user)->count();

        return ['tailored' => $tailored, 'letters' => $letters, 'used' => $tailored + $letters];
    }

    public static function audit($user, string $event, string $ref = ''): void
    {
        DB::table('activity_events')->insert(['id' => (string) Str::uuid(), 'owner_id' => $user, 'event' => $event, 'reference' => substr($ref, 0, 255), 'created_at' => now(), 'updated_at' => now()]);
    }
}
