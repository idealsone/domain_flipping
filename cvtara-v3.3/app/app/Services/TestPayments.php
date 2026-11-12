<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3.61.0 Test payment review (V4.8 §6). Lists every order made up to 9 November 2026 (India time) with a suggested
 * class worked out by fixed rules, and lets the owner mark each one "test" or "real". Nothing is deleted or changed on
 * the order; an order the owner marks "test" is left out of revenue figures (Settings::sinceStart).
 */
class TestPayments
{
    public const CUTOFF_IST = '2026-11-09 23:59:59';

    public const CLASSES = ['test' => 'Looks like a test', 'real' => 'Looks like a real customer payment', 'not_paid' => 'No money taken', 'grant' => 'Free grant or reward'];

    public static function cutoff(): string
    {
        return \Illuminate\Support\Carbon::parse(self::CUTOFF_IST, 'Asia/Kolkata')->utc()->format('Y-m-d H:i:s');
    }

    /** Fixed rules, in order. Returns [class, reasons]. */
    public static function classify(object $p): array
    {
        $paidStates = ['paid', 'captured', 'partially_refunded', 'refunded', 'disputed'];
        if (! in_array($p->status, $paidStates, true)) {
            return ['not_paid', ['status '.$p->status.' (checkout not completed)']];
        }
        if ((int) $p->amount_paise === 0 || in_array($p->provider, ['manual', 'complimentary', 'referral_reward', 'grant'], true)) {
            return ['grant', ['₹0 or a manual grant, not a gateway payment']];
        }
        $why = [];
        if (! $p->is_live) {
            $why[] = 'made in the gateway\'s test mode';
        }
        if (in_array($p->provider, ['demo', 'test'], true)) {
            $why[] = 'demo checkout';
        }
        if (! empty($p->is_admin) || ! empty($p->is_staff)) {
            $why[] = 'paid from the owner or a staff account';
        }
        if ((int) $p->amount_paise <= 1000) {
            $why[] = '₹10 or less';
        }
        if ($p->email && preg_match('/(^|[^a-z])(test|demo|dummy)|@(example|test)\./i', (string) $p->email)) {
            $why[] = 'test-looking email address';
        }
        if ((int) $p->refunded_paise >= (int) $p->amount_paise && $p->purchased_at && $p->refunded_at && strtotime((string) $p->refunded_at) - strtotime((string) $p->purchased_at) <= 2 * 86400) {
            $why[] = 'fully refunded within 2 days';
        }

        return $why ? ['test', $why] : ['real', ['live gateway payment from a customer account']];
    }

    public static function rows(): array
    {
        $staff = Schema::hasTable('user_roles') ? DB::table('user_roles')->pluck('user_id')->all() : [];
        $refundCol = Schema::hasColumn('purchases', 'refunded_at') ? 'p.refunded_at' : DB::raw('NULL as refunded_at');
        $marks = Schema::hasTable('payment_marks') ? DB::table('payment_marks')->get()->keyBy('purchase_id') : collect();

        return DB::table('purchases as p')->leftJoin('users as u', 'u.id', '=', 'p.owner_id')->where('p.created_at', '<=', self::cutoff())->orderBy('p.created_at')
            ->get(['p.id', 'p.created_at', 'p.purchased_at', 'p.plan', 'p.amount_paise', 'p.refunded_paise', 'p.status', 'p.provider', 'p.is_live', 'p.payment_reference', $refundCol, 'p.owner_id', 'u.email', 'u.is_admin'])
            ->map(function ($p) use ($staff, $marks) {
                $p->is_staff = in_array($p->owner_id, $staff);
                [$class, $why] = self::classify($p);
                $m = $marks[$p->id] ?? null;

                return ['id' => $p->id, 'createdAt' => (string) $p->created_at, 'email' => (string) $p->email, 'plan' => $p->plan, 'amountPaise' => (int) $p->amount_paise, 'refundedPaise' => (int) $p->refunded_paise,
                    'status' => $p->status, 'provider' => $p->provider, 'live' => (bool) $p->is_live, 'reference' => (string) $p->payment_reference, 'suggested' => $class, 'reasons' => implode('; ', $why),
                    'mark' => $m->mark ?? '', 'note' => $m->note ?? '', 'markedAt' => $m ? (string) $m->updated_at : ''];
            })->all();
    }

    public static function summary(array $rows): array
    {
        $out = ['orders' => count($rows), 'cutoff' => self::CUTOFF_IST.' India time', 'bySuggested' => [], 'marked' => ['test' => 0, 'real' => 0], 'testPaise' => 0];
        foreach ($rows as $r) {
            $out['bySuggested'][$r['suggested']] = ($out['bySuggested'][$r['suggested']] ?? 0) + 1;
            if ($r['mark']) {
                $out['marked'][$r['mark']]++;
            }
            if ($r['mark'] === 'test') {
                $out['testPaise'] += $r['amountPaise'] - $r['refundedPaise'];
            }
        }

        return $out;
    }

    /** The owner marks one or more orders (only orders up to the cut-off). "clear" removes the mark. */
    public static function mark(User $admin, array $ids, string $mark, ?string $note = null): int
    {
        abort_unless(in_array($mark, ['test', 'real', 'clear'], true), 422);
        $ids = DB::table('purchases')->whereIn('id', $ids)->where('created_at', '<=', self::cutoff())->pluck('id')->all();
        abort_unless($ids, 422, 'Choose orders made up to 9 November.');
        foreach ($ids as $id) {
            if ($mark === 'clear') {
                DB::table('payment_marks')->where('purchase_id', $id)->delete();
            } else {
                DB::table('payment_marks')->updateOrInsert(['purchase_id' => $id], ['mark' => $mark, 'note' => $note ? mb_substr($note, 0, 300) : null, 'marked_by' => $admin->id, 'updated_at' => now(), 'created_at' => now()]);
            }
            Audit::record($admin, 'payment_marked', 'purchase:'.$id, null, ['mark' => $mark]);
        }
        self::$testIds = null;

        return count($ids);
    }

    private static ?array $testIds = null;

    /** Orders the owner marked as test (left out of revenue figures). */
    public static function testIds(): array
    {
        if (self::$testIds === null) {
            self::$testIds = Schema::hasTable('payment_marks') ? DB::table('payment_marks')->where('mark', 'test')->pluck('purchase_id')->all() : [];
        }

        return self::$testIds;
    }
}
