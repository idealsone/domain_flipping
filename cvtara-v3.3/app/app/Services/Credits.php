<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Download credits. Saving a draft is free; the first clean (watermark-free) download of a tailored resume or a cover letter
 * uses one credit of its own kind. Resume and cover-letter credits are separate pools:
 *   plan:   e.g. Job Hunt = 5 resume downloads + 5 cover-letter downloads (plus one primary resume free for life, given once after a first purchase, never again on renewals)
 *   add-on: e.g. +10 resume downloads + 10 cover-letter downloads, valid until the subscription it was bought on ends (and follows it into a renewal).
 * Downloading the same version again (another format) is free; an edited version is a new document and uses a new credit.
 * Quick Download is the exception: its one credit is bound to one resume, so edits and re-downloads of that resume stay free for 7 days,
 * and its ATS checks are the free daily ones (it has no scan pool).
 */
class Credits
{
    /** Why the last consume() did or did not use a credit: credit | primary-free | same-version. */
    public static string $note = 'credit';

    private static function pools(int $uid): array
    {
        $a = Settings::access($uid);
        $out = [];
        if ($a['paid'] && $a['purchase']) {
            $l = json_decode((string) $a['purchase']->limits_json, true) ?: [];
            $out[] = ['id' => $a['purchase']->id, 'type' => 'plan', 'until' => $a['purchase']->access_until, 'resume' => (int) ($l['savedResumes'] ?? 0), 'letter' => (int) ($l['savedLetters'] ?? 0), 'scan' => self::scanCap($l, (string) $a['purchase']->plan), 'one' => ! empty($l['oneResume'])];
            $rows = DB::table('purchases')->where('owner_id', $uid)->where('plan', 'like', 'addon-%')->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now())->orderBy('access_until')->get();
            foreach ($rows as $r) {
                $l = json_decode((string) $r->limits_json, true) ?: [];
                $out[] = ['id' => $r->id, 'type' => 'addon', 'until' => $r->access_until, 'resume' => (int) ($l['savedResumes'] ?? 0), 'letter' => (int) ($l['savedLetters'] ?? 0), 'scan' => self::scanCap($l, (string) $r->plan), 'one' => false];
            }
        }

        return $out;
    }

    /** An ATS-scan allowance of null means the owner made the plan unlimited. */
    private static function scanCap(array $l, string $plan = ''): int
    {
        if (array_key_exists('atsScans', $l) && $l['atsScans'] === null) {
            // Purchases made before scans were capped carry null: use the plan's current limit when it has one.
            $now = Settings::plans()[$plan]['atsScans'] ?? null;

            return $now === null ? 1000000 : (int) $now;
        }

        return (int) ($l['atsScans'] ?? 0);
    }

    private static function usedScans(int $uid, string $poolId): int
    {
        return (int) DB::table('product_usage')->where('owner_id', $uid)->where('action', 'ats_scan')->where('reference', 'like', '%|'.$poolId)->count();
    }

    private static function used(int $uid, string $kind, string $poolId): int
    {
        return (int) DB::table('product_usage')->where('owner_id', $uid)->where('action', 'doc_'.$kind)->where('reference', 'like', '%|'.$poolId)->count();
    }

    public static function status(int $uid): array
    {
        $s = ['resumes' => ['total' => 0, 'used' => 0, 'remaining' => 0], 'letters' => ['total' => 0, 'used' => 0, 'remaining' => 0], 'scans' => ['total' => 0, 'used' => 0, 'remaining' => 0, 'unlimited' => false], 'addOns' => ['resumes' => 0, 'letters' => 0, 'scans' => 0, 'validUntil' => null], 'primaryFree' => ! self::primaryClaimed($uid)];
        foreach (self::pools($uid) as $p) {
            foreach (['resume' => 'resumes', 'letter' => 'letters'] as $kind => $key) {
                $u = min($p[$kind], self::used($uid, $kind, $p['id']));
                $s[$key]['total'] += $p[$kind];
                $s[$key]['used'] += $u;
                if ($p['type'] === 'addon') {
                    $s['addOns'][$key] += $p[$kind] - $u;
                }
            }
            $su = min($p['scan'], self::usedScans($uid, $p['id']));
            $s['scans']['total'] += $p['scan'];
            $s['scans']['used'] += $su;
            $s['scans']['unlimited'] = $s['scans']['unlimited'] || $p['scan'] >= 1000000;
            if ($p['type'] === 'addon') {
                $s['addOns']['scans'] += $p['scan'] - $su;
            }
            if ($p['type'] === 'addon' && (! $s['addOns']['validUntil'] || $p['until'] > $s['addOns']['validUntil'])) {
                $s['addOns']['validUntil'] = $p['until'];
            }
        }
        foreach (['resumes', 'letters'] as $key) {
            $s[$key]['remaining'] = max(0, $s[$key]['total'] - $s[$key]['used']);
        }

        $s['scans']['remaining'] = $s['scans']['unlimited'] ? null : max(0, $s['scans']['total'] - $s['scans']['used']);
        if ($s['scans']['unlimited']) {
            $s['scans']['total'] = null;
        }

        return $s;
    }

    /** Pools that hold ATS scans. Quick Download has none: its buyers use the free daily checks. */
    private static function scanPools(int $uid): array
    {
        return array_values(array_filter(self::pools($uid), fn ($p) => ! $p['one']));
    }

    /** Reserves one ATS scan from the plan first, then add-ons. Returns false when this account has no scan pool (free account). */
    public static function reserveScan(int $uid, string $scanId): bool
    {
        $pools = self::scanPools($uid);
        if (! $pools) {
            return false;
        }
        foreach ($pools as $p) {
            if (self::usedScans($uid, $p['id']) < $p['scan']) {
                DB::table('product_usage')->insert(['id' => (string) Str::uuid(), 'owner_id' => $uid, 'action' => 'ats_scan', 'reference' => $scanId.'|'.$p['id'], 'created_at' => now(), 'updated_at' => now()]);

                return true;
            }
        }
        throw ValidationException::withMessages(['limit' => self::outOf('ATS scans')]);
    }

    /** What to say when a pool is empty: only offer what is on sale. */
    public static function outOf(string $what): string
    {
        $packs = array_filter(Settings::paidPlanKeys(), fn ($k) => str_starts_with($k, 'addon-'));

        return "All your {$what} on this plan are used. ".($packs ? 'Buy an add-on pack (valid until your plan ends), or move to a bigger plan.' : 'Move to Job Hunt or Pro for more, or renew your plan.');
    }

    /** Cheap check before any heavy work: does this account still have a scan to spend? (Free accounts have no pool.) */
    public static function scanAvailable(int $uid): bool
    {
        $pools = self::scanPools($uid);
        foreach ($pools as $p) {
            if (self::usedScans($uid, $p['id']) < $p['scan']) {
                return true;
            }
        }

        return ! $pools;
    }

    /** The one-time lifetime primary resume is available once per account, and only to someone who has bought a plan. */
    public static function primaryClaimed(int $uid): bool
    {
        return DB::table('product_usage')->where('owner_id', $uid)->where('action', 'primary_free')->exists();
    }

    /** Returns true when a credit was used, false when this exact version was already paid for (or is the lifetime free primary). */
    public static function consume(int $uid, string $kind, string $docId, int $revision, bool $primary = false): bool
    {
        self::$note = 'credit';
        if ($kind === 'resume' && $primary) {
            if (! self::primaryClaimed($uid)) {
                DB::table('product_usage')->insert(['id' => (string) Str::uuid(), 'owner_id' => $uid, 'action' => 'primary_free', 'reference' => $docId.'|'.$revision, 'created_at' => now(), 'updated_at' => now()]);
                self::$note = 'primary-free';

                return false;
            }
            if (DB::table('product_usage')->where('owner_id', $uid)->where('action', 'primary_free')->where('reference', $docId.'|'.$revision)->exists()) {
                self::$note = 'primary-free';

                return false;
            }
        }
        if (DB::table('product_usage')->where('owner_id', $uid)->where('action', 'doc_'.$kind)->where('reference', 'like', $docId.'|'.$revision.'|%')->exists()) {
            self::$note = 'same-version';

            return false;
        }
        $pools = self::pools($uid);
        foreach ($pools as $p) {
            if ($p['one'] && $kind === 'resume' && DB::table('product_usage')->where('owner_id', $uid)->where('action', 'doc_resume')->where('reference', 'like', $docId.'|%|'.$p['id'])->exists()) {
                self::$note = 'same-resume';

                return false;
            }
        }
        foreach ($pools as $p) {
            if (self::used($uid, $kind, $p['id']) < $p[$kind]) {
                DB::table('product_usage')->insert(['id' => (string) Str::uuid(), 'owner_id' => $uid, 'action' => 'doc_'.$kind, 'reference' => $docId.'|'.$revision.'|'.$p['id'], 'created_at' => now(), 'updated_at' => now()]);

                return true;
            }
        }
        if ($pools && $pools[0]['one']) {
            throw ValidationException::withMessages(['limit' => $kind === 'resume' ? 'Quick Download covers one resume, and it is already used on another resume. Job Hunt adds 5 tailored resumes and 5 cover letters.' : 'Quick Download does not include cover letters. Job Hunt adds 5 cover letters and 5 tailored resumes.']);
        }
        throw ValidationException::withMessages(['limit' => self::outOf($kind === 'resume' ? 'resume download credits' : 'cover-letter download credits')]);
    }
}
