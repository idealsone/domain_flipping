<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Download credits. Saving a draft is free; the first clean (watermark-free) download of a tailored resume or a cover letter
 * uses one credit of its own kind. Resume and cover-letter credits are separate pools:
 *   plan:   e.g. Fresher Basic = 10 resume downloads + 10 cover-letter downloads (plus one primary resume free for life, given once after a first purchase, never again on renewals)
 *   add-on: e.g. +10 resume downloads + 10 cover-letter downloads, valid for its own period (45 days) and carried into renewals.
 * Downloading the same version again (another format) is free; an edited version is a new document and uses a new credit.
 */
class Credits
{
    private static function pools(int $uid): array
    {
        $a = Settings::access($uid);
        $out = [];
        if ($a['paid'] && $a['purchase']) {
            $l = json_decode((string) $a['purchase']->limits_json, true) ?: [];
            $out[] = ['id' => $a['purchase']->id, 'type' => 'plan', 'until' => $a['purchase']->access_until, 'resume' => (int) ($l['savedResumes'] ?? 0), 'letter' => (int) ($l['savedLetters'] ?? 0)];
            $rows = DB::table('purchases')->where('owner_id', $uid)->where('plan', 'like', 'addon-%')->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now())->orderBy('access_until')->get();
            foreach ($rows as $r) {
                $l = json_decode((string) $r->limits_json, true) ?: [];
                $out[] = ['id' => $r->id, 'type' => 'addon', 'until' => $r->access_until, 'resume' => (int) ($l['savedResumes'] ?? 0), 'letter' => (int) ($l['savedLetters'] ?? 0)];
            }
        }

        return $out;
    }

    private static function used(int $uid, string $kind, string $poolId): int
    {
        return (int) DB::table('product_usage')->where('owner_id', $uid)->where('action', 'doc_'.$kind)->where('reference', 'like', '%|'.$poolId)->count();
    }

    public static function status(int $uid): array
    {
        $s = ['resumes' => ['total' => 0, 'used' => 0, 'remaining' => 0], 'letters' => ['total' => 0, 'used' => 0, 'remaining' => 0], 'addOns' => ['resumes' => 0, 'letters' => 0, 'validUntil' => null], 'primaryFree' => ! self::primaryClaimed($uid)];
        foreach (self::pools($uid) as $p) {
            foreach (['resume' => 'resumes', 'letter' => 'letters'] as $kind => $key) {
                $u = min($p[$kind], self::used($uid, $kind, $p['id']));
                $s[$key]['total'] += $p[$kind];
                $s[$key]['used'] += $u;
                if ($p['type'] === 'addon') {
                    $s['addOns'][$key] += $p[$kind] - $u;
                }
            }
            if ($p['type'] === 'addon' && (! $s['addOns']['validUntil'] || $p['until'] > $s['addOns']['validUntil'])) {
                $s['addOns']['validUntil'] = $p['until'];
            }
        }
        foreach (['resumes', 'letters'] as $key) {
            $s[$key]['remaining'] = max(0, $s[$key]['total'] - $s[$key]['used']);
        }

        return $s;
    }

    /** The one-time lifetime primary resume is available once per account, and only to someone who has bought a plan. */
    public static function primaryClaimed(int $uid): bool
    {
        return DB::table('product_usage')->where('owner_id', $uid)->where('action', 'primary_free')->exists();
    }

    /** Returns true when a credit was used, false when this exact version was already paid for (or is the lifetime free primary). */
    public static function consume(int $uid, string $kind, string $docId, int $revision, bool $primary = false): bool
    {
        if ($kind === 'resume' && $primary) {
            if (! self::primaryClaimed($uid)) {
                DB::table('product_usage')->insert(['id' => (string) Str::uuid(), 'owner_id' => $uid, 'action' => 'primary_free', 'reference' => $docId.'|'.$revision, 'created_at' => now(), 'updated_at' => now()]);

                return false;
            }
            if (DB::table('product_usage')->where('owner_id', $uid)->where('action', 'primary_free')->where('reference', $docId.'|'.$revision)->exists()) {
                return false;
            }
        }
        if (DB::table('product_usage')->where('owner_id', $uid)->where('action', 'doc_'.$kind)->where('reference', 'like', $docId.'|'.$revision.'|%')->exists()) {
            return false;
        }
        foreach (self::pools($uid) as $p) {
            if (self::used($uid, $kind, $p['id']) < $p[$kind]) {
                DB::table('product_usage')->insert(['id' => (string) Str::uuid(), 'owner_id' => $uid, 'action' => 'doc_'.$kind, 'reference' => $docId.'|'.$revision.'|'.$p['id'], 'created_at' => now(), 'updated_at' => now()]);

                return true;
            }
        }
        $label = $kind === 'resume' ? 'resume' : 'cover-letter';
        throw ValidationException::withMessages(['limit' => "All your {$label} download credits are used. Buy an add-on pack for more {$label} downloads (valid 45 days), or use a new plan period."]);
    }
}
