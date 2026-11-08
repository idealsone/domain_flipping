<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Daily payment reconciliation (3.43.0). Once a day the cron compares yesterday's (India time) captured Razorpay
 * payments with CVTara's purchases, and asks PhonePe for the status of orders still open. Each mismatch is stored once
 * in payment_reconciliation_records and written to the Error Log; the owner marks it resolved after checking.
 * Read-only towards the gateway: nothing is charged, refunded or granted here.
 */
class Reconcile
{
    public static function due(?Carbon $now = null): ?array
    {
        $now = ($now ?? now())->copy()->tz(Growth::TZ);
        if ($now->hour < 6 || (Settings::get('reconcileRun', [])['day'] ?? '') === $now->toDateString()) {
            return null;
        }
        Settings::put('reconcileRun', ['day' => $now->toDateString(), 'at' => now()->toIso8601String()]);

        return self::run($now->copy()->subDay()->startOfDay());
    }

    public static function run(Carbon $day): array
    {
        $from = $day->copy()->tz(Growth::TZ)->startOfDay();
        $to = $from->copy()->endOfDay();
        $out = ['day' => $from->toDateString(), 'razorpay' => null, 'phonepe' => null, 'mismatches' => 0];
        $p = Settings::providers()['payments'] ?? [];
        if (! empty($p['razorpayKeyId']) && ! empty($p['razorpayKeySecret'])) {
            $captured = [];
            for ($skip = 0; $skip < 2000; $skip += 100) {
                $j = Http::withBasicAuth($p['razorpayKeyId'], $p['razorpayKeySecret'])->timeout(20)
                    ->get(Payments::api().'/payments', ['from' => $from->timestamp, 'to' => $to->timestamp, 'count' => 100, 'skip' => $skip])->throw()->json();
                foreach ((array) ($j['items'] ?? []) as $x) {
                    if (($x['status'] ?? '') === 'captured' || ($x['status'] ?? '') === 'refunded') {
                        $captured[$x['id']] = $x;
                    }
                }
                if (count((array) ($j['items'] ?? [])) < 100) {
                    break;
                }
            }
            foreach ($captured as $id => $x) {
                $row = DB::table('purchases')->where('provider', 'razorpay')->where(fn ($q) => $q->where('payment_reference', $id)->orWhere('provider_order_id', (string) ($x['order_id'] ?? '-')))->first();
                if (! $row || ! in_array($row->status, ['paid', 'refunded', 'partially_refunded', 'disputed'], true)) {
                    $out['mismatches'] += self::record($from, 'razorpay', 'paid_not_recorded', $id, (string) ($x['order_id'] ?? ''), $row?->id, (int) $x['amount'], $row ? (int) $row->amount_paise : null, 'Captured at Razorpay but not paid in CVTara'.($row ? ' (order is '.$row->status.')' : ' (no matching order)').'.');
                } elseif ((int) $row->amount_paise !== (int) $x['amount']) {
                    $out['mismatches'] += self::record($from, 'razorpay', 'amount_differs', $id, (string) $row->provider_order_id, $row->id, (int) $x['amount'], (int) $row->amount_paise, 'Amounts differ.');
                }
            }
            $ours = DB::table('purchases')->where('provider', 'razorpay')->whereIn('status', ['paid', 'refunded', 'partially_refunded'])->where('amount_paise', '>', 0)
                ->where('purchased_at', '>=', $from->copy()->utc())->where('purchased_at', '<=', $to->copy()->utc())->get();
            foreach ($ours as $row) {
                if ($row->payment_reference && ! isset($captured[$row->payment_reference])) {
                    $out['mismatches'] += self::record($from, 'razorpay', 'recorded_not_paid', (string) $row->payment_reference, (string) $row->provider_order_id, $row->id, null, (int) $row->amount_paise, 'Paid in CVTara but not captured at Razorpay that day.');
                }
            }
            $out['razorpay'] = ['captured' => count($captured), 'recorded' => $ours->count()];
        }
        if (PhonePe::configured()) {
            $out['phonepe'] = ['updated' => PhonePe::reconcile()];
        }
        Settings::put('reconcileLast', $out + ['at' => now()->toIso8601String()]);

        return $out;
    }

    public static function resolve(int $id, int $by, string $note): void
    {
        abort_unless(DB::table('payment_reconciliation_records')->where('id', $id)->whereNull('resolved_at')->update(['resolved_at' => now(), 'resolved_by' => $by, 'note' => mb_substr($note, 0, 500), 'updated_at' => now()]), 404);
    }

    private static function record(Carbon $day, string $provider, string $kind, string $ref, string $order, ?string $purchase, ?int $gateway, ?int $recorded, string $note): int
    {
        $new = DB::table('payment_reconciliation_records')->insertOrIgnore(['day' => $day->toDateString(), 'provider' => $provider, 'kind' => $kind, 'payment_reference' => $ref, 'provider_order_id' => $order,
            'purchase_id' => $purchase, 'gateway_paise' => $gateway, 'recorded_paise' => $recorded, 'note' => $note, 'created_at' => now(), 'updated_at' => now()]);
        if ($new) {
            ErrorLog::record('warning', 'payments', 'Payment mismatch ('.$provider.', '.$ref.'): '.$note, 'reconcile:'.$provider.':'.$ref);
        }

        return (int) $new;
    }
}
