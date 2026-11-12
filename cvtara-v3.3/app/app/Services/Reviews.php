<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Customer reviews: what the public sees, and the checks the owner sees before approving one. */
class Reviews
{
    /** Account ids that really paid (a captured live gateway payment, not a ₹0 grant, demo or test). */
    private static function buyers(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return array_flip(DB::table('purchases')->whereIn('owner_id', $ids)->whereIn('status', ['paid', 'partially_refunded'])->where('is_live', true)->where('amount_paise', '>', 0)
            ->whereIn('provider', ['razorpay', 'phonepe', 'cashfree'])->whereNotNull('purchased_at')->distinct()->pluck('owner_id')->map(fn ($x) => (int) $x)->all());
    }

    private static function norm(string $t): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($t));
    }

    /** Approved reviews whose writer agreed to publication. "Verified buyer" only when the account paid, or the owner checked it against a paid account. */
    public static function published(): array
    {
        $rows = DB::table('feedback')->where('status', 'published')->where('consent', true)->orderByDesc('created_at')->limit(60)->get();
        $paid = self::buyers($rows->pluck('owner_id')->map(fn ($x) => (int) $x)->all());

        return $rows->map(fn ($f) => ['id' => $f->id, 'display_name' => $f->display_name, 'company' => (string) ($f->company ?? ''), 'designation' => (string) ($f->designation ?? ''), 'rating' => (int) $f->rating, 'content' => $f->content, 'edited' => ! empty($f->edited_at), 'created_at' => $f->created_at,
            'verified' => $f->source === 'owner_verified' || ($f->source === 'user' && isset($paid[(int) $f->owner_id]))])->values()->all();
    }

    /** Adds verifiedBuyer and duplicateOf to admin rows so the owner sees both before publishing. */
    public static function flag(array $rows): array
    {
        $paid = self::buyers(array_map(fn ($f) => (int) ((array) $f)['owner_id'], $rows));
        $all = DB::table('feedback')->get(['id', 'content']);
        $seen = [];
        foreach ($all as $x) {
            $seen[self::norm((string) $x->content)][] = $x->id;
        }

        return array_map(function ($f) use ($paid, $seen) {
            $f = (array) $f;
            $same = array_values(array_diff($seen[self::norm((string) $f['content'])] ?? [], [$f['id']]));
            $f['verifiedBuyer'] = ($f['source'] ?? '') === 'owner_verified' || (($f['source'] ?? '') === 'user' && isset($paid[(int) $f['owner_id']]));
            $f['duplicates'] = count($same);

            return $f;
        }, $rows);
    }
}
