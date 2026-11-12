<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Append-only credit ledger (3.49.0, V7 §24). Rows are never edited or deleted; a correction is a new row.
 * Kinds: grant (+, complimentary credits given by the owner), reserve (-1 when a mock interview starts),
 * release (+1 when it fails or is abandoned before feedback), consume (0, marks the reserved credit as used for good),
 * adjust (+/-, owner correction with a reason). Every row carries an idempotency key, so a repeated request or a retried
 * job can never take or give a credit twice.
 *
 * A plan's allowance is not copied into the ledger: it is read from the order's frozen limits_json (what the customer
 * bought), and the ledger rows that name that order are subtracted. Complimentary grants have no order (purchase_id null).
 */
class UsageLedger
{
    public const KINDS = ['grant', 'reserve', 'release', 'consume', 'adjust'];

    /** Writes one row. Returns false when a row with the same idempotency key already exists (nothing changes). */
    public static function add(int $ownerId, string $resource, string $kind, int $amount, string $idem, ?string $purchaseId = null, ?string $reference = null, ?int $actorId = null, ?string $reason = null): bool
    {
        abort_unless(in_array($kind, self::KINDS, true), 500);

        return DB::table('usage_ledger')->insertOrIgnore([
            'owner_id' => $ownerId, 'purchase_id' => $purchaseId, 'resource' => $resource, 'kind' => $kind, 'amount' => $amount,
            'reference' => $reference ? mb_substr($reference, 0, 64) : null, 'actor_id' => $actorId, 'reason' => $reason ? mb_substr($reason, 0, 300) : null,
            'idem' => mb_substr($idem, 0, 120), 'created_at' => now(),
        ]) === 1;
    }

    /**
     * What the customer can still use, pool by pool: each active order with an allowance for $field (soonest to end
     * first), then complimentary credits. Each pool: purchase id (null = complimentary), plan, allowance, left, until.
     */
    public static function pools(int $ownerId, string $resource, string $field): array
    {
        $rows = DB::table('purchases')->where('owner_id', $ownerId)->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now())->orderBy('access_until')->get(['id', 'plan', 'limits_json', 'access_until']);
        $sums = DB::table('usage_ledger')->where('owner_id', $ownerId)->where('resource', $resource)->groupBy('purchase_id')->selectRaw('purchase_id, sum(amount) s')->pluck('s', 'purchase_id');
        $pools = [];
        foreach ($rows as $p) {
            $n = (int) ((json_decode((string) $p->limits_json, true) ?: [])[$field] ?? 0);
            if ($n > 0) {
                $pools[] = ['purchase' => $p->id, 'plan' => $p->plan, 'allowance' => $n, 'left' => max(0, $n + (int) ($sums[$p->id] ?? 0)), 'until' => (string) $p->access_until];
            }
        }
        $comp = (int) DB::table('usage_ledger')->where('owner_id', $ownerId)->where('resource', $resource)->whereNull('purchase_id')->sum('amount');
        if ($comp > 0 || DB::table('usage_ledger')->where('owner_id', $ownerId)->where('resource', $resource)->whereNull('purchase_id')->where('kind', 'grant')->exists()) {
            $pools[] = ['purchase' => null, 'plan' => 'complimentary', 'allowance' => (int) DB::table('usage_ledger')->where('owner_id', $ownerId)->where('resource', $resource)->whereNull('purchase_id')->where('kind', 'grant')->sum('amount'), 'left' => max(0, $comp), 'until' => null];
        }

        return $pools;
    }

    public static function left(int $ownerId, string $resource, string $field): int
    {
        return array_sum(array_column(self::pools($ownerId, $resource, $field), 'left'));
    }

    /** Whether a credit of this order is held or used (for the 7-day "unused" refund rule). A credit given back does not count. */
    public static function usedFromPurchase(string $purchaseId): bool
    {
        return (int) DB::table('usage_ledger')->where('purchase_id', $purchaseId)->whereIn('kind', ['reserve', 'release'])->sum('amount') < 0;
    }

    /** The customer's own history, newest first (no other customer's data, no staff names). */
    public static function history(int $ownerId, string $resource, int $limit = 50): array
    {
        return DB::table('usage_ledger')->where('owner_id', $ownerId)->where('resource', $resource)->orderByDesc('id')->limit($limit)
            ->get(['kind', 'amount', 'reference', 'reason', 'created_at'])->map(fn ($r) => (array) $r)->all();
    }
}
