<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashfreeCheckout
{
    // The gateway address. Tests may point it at a local stand-in, but never on a production site.
    private static function base(bool $live): string
    {
        $o = env('CASHFREE_API_BASE');
        if ($o && config('app.env') !== 'production') {
            return rtrim($o, '/');
        }

        return $live ? 'https://api.cashfree.com/pg' : 'https://sandbox.cashfree.com/pg';
    }

    private static function client(bool $live)
    {
        $p = Settings::providers()['payments'] ?? [];
        if (empty($p['cashfreeClientId']) || empty($p['cashfreeClientSecret'])) {
            throw ValidationException::withMessages(['payments' => 'Add Cashfree checkout credentials in owner setup.']);
        }

return Http::baseUrl(self::base($live))->withHeaders(['x-api-version' => $p['cashfreeApiVersion'] ?? '2025-01-01', 'x-client-id' => $p['cashfreeClientId'], 'x-client-secret' => $p['cashfreeClientSecret']])->acceptJson()->connectTimeout(5)->timeout(20);
    }

    public static function start(User $u, string $plan, string $phone, string $coupon = '', bool $useWallet = true): array
    {
        $plans = Settings::plans();
        abort_unless(isset($plans[$plan]) && $plan !== 'free', 422);
        $phone = preg_replace('/\D/', '', $phone) ?: (string) DB::table('profiles')->where('owner_id', $u->id)->value('phone');
        $phone = substr(preg_replace('/\D/', '', $phone), -10);
        if (! preg_match('/^[6-9][0-9]{9}$/', $phone)) {
            throw ValidationException::withMessages(['phone' => 'Enter your Indian mobile number for secure checkout.']);
        }$p = Settings::providers()['payments'];
        $live = ($p['mode'] ?? 'test') === 'live';
        $id = (string) Str::uuid();
        $orderId = 'cvt_'.str_replace('-', '', $id);
        $lock = $coupon !== '' ? \Illuminate\Support\Facades\Cache::lock('coupon:'.strtoupper(trim($coupon)), 25) : null;
        $lock?->block(15);
        try {
        $quote = BusinessControls::quote($plan, $coupon, $u->id, $useWallet);
        $amount = $quote['totalPaise'];
        abort_unless($amount >= 100 && $amount < 100000000, 422);
        DB::table('purchases')->insert(['id' => $id, 'owner_id' => $u->id, 'plan' => $plan, 'amount_paise' => $amount, ...BusinessControls::quoteColumns($quote), 'provider' => 'cashfree', 'provider_order_id' => $orderId, 'is_live' => $live, 'limits_json' => json_encode($plans[$plan]), 'access_days' => $plans[$plan]['accessDays'], 'created_at' => now(), 'updated_at' => now()]);
        try {
            $res = self::client($live)->withHeaders(['x-idempotency-key' => $id])->post('/orders', ['order_id' => $orderId, 'order_amount' => $amount / 100, 'order_currency' => 'INR', 'customer_details' => ['customer_id' => 'cvtara_'.$u->id, 'customer_name' => $u->name, 'customer_email' => $u->email, 'customer_phone' => $phone], 'order_meta' => ['return_url' => url('/checkout.html').'?cashfree_order='.$orderId, 'notify_url' => url('/webhooks/cashfree')]])->throw()->json();
            if (($res['order_id'] ?? '') !== $orderId || empty($res['payment_session_id'])) {
                throw new \RuntimeException;
            }
        } catch (\Throwable $e) {
            Settings::audit($u->id, 'checkout_needs_reconciliation', $id);
            throw ValidationException::withMessages(['payment' => 'Could not open checkout. No payment has been requested in your browser. Check purchase history before trying again.']);
        }
        Settings::audit($u->id, 'checkout_started', $id);
        } finally {
            $lock?->release();
        }

        return ['id' => $id, 'provider' => 'cashfree', 'orderId' => $orderId, 'paymentSessionId' => $res['payment_session_id'], 'mode' => $live ? 'production' : 'sandbox', 'test' => ! $live, 'amount' => $amount, 'currency' => 'INR'];
    }

    public static function verify(User $u, string $orderId): void
    {
        $p = DB::table('purchases')->where('owner_id', $u->id)->where('provider', 'cashfree')->where('provider_order_id', $orderId)->first();
        abort_unless($p, 404);
        self::reconcile($p);
        $status = DB::table('purchases')->where('id', $p->id)->value('status');
        if ($status !== 'paid') {
            throw ValidationException::withMessages(['payment' => 'Payment is not confirmed as paid. If debited, do not pay again; check purchase history shortly.']);
        }
    }

    public static function reconcile(object $p): void
    {
        $client = self::client((bool) $p->is_live);
        $order = $client->get('/orders/'.rawurlencode($p->provider_order_id))->throw()->json();
        abort_unless(($order['order_id'] ?? '') === $p->provider_order_id && ($order['order_currency'] ?? '') === 'INR' && (int) round(($order['order_amount'] ?? 0) * 100) === $p->amount_paise, 422);
        if (($order['order_status'] ?? '') !== 'PAID') {
            return;
        }
        $payments = $client->get('/orders/'.rawurlencode($p->provider_order_id).'/payments')->throw()->json();
        $paid = collect($payments)->first(fn ($x) => ($x['payment_status'] ?? '') === 'SUCCESS' && ($x['payment_currency'] ?? '') === 'INR' && (int) round(($x['payment_amount'] ?? 0) * 100) === $p->amount_paise && ! empty($x['cf_payment_id']));
        if (! $paid) {
            throw ValidationException::withMessages(['payment' => 'Waiting for final payment confirmation.']);
        }
        Payments::capture(['order_id' => $p->provider_order_id, 'id' => 'cf_'.$paid['cf_payment_id'], 'amount' => $p->amount_paise, 'currency' => 'INR', 'status' => 'captured'], 'cashfree');
        $refunds = $client->get('/orders/'.rawurlencode($p->provider_order_id).'/refunds')->throw()->json();
        $total = 0;
        $seen = [];
        foreach ($refunds as $refund) {
            $rid = $refund['cf_refund_id'] ?? $refund['refund_id'] ?? null;
            if ($rid && ($refund['refund_status'] ?? '') === 'SUCCESS' && ! isset($seen[$rid])) {
                $total += (int) round(($refund['refund_amount'] ?? 0) * 100);
                $seen[$rid] = true;
            }
        }
        if ($total > 0) {
            DB::transaction(function () use ($p, $total) {
                $fresh = DB::table('purchases')->where('id', $p->id)->lockForUpdate()->first();
                $sum = min($fresh->amount_paise, max($fresh->refunded_paise, $total));
                DB::table('purchases')->where('id', $p->id)->update(['refunded_paise' => $sum, 'status' => $sum === $fresh->amount_paise ? 'refunded' : 'partially_refunded', 'updated_at' => now()]);
                Referrals::reverse($fresh, $sum);
            });
            Refunds::settled($p->id);
        }
    }

    /** Asks Cashfree to return the full amount to the original payment method. The refund webhook then ends access. */
    public static function refund(object $p, string $refundId): void
    {
        self::client((bool) $p->is_live)->withHeaders(['x-idempotency-key' => $refundId])->post('/orders/'.rawurlencode($p->provider_order_id).'/refunds', ['refund_amount' => $p->amount_paise / 100, 'refund_id' => $refundId, 'refund_note' => 'CVTara 7-day refund'])->throw();
    }

    public static function webhook(string $raw, string $timestamp, string $signature): void
    {
        $secret = Settings::providers()['payments']['cashfreeClientSecret'] ?? '';
        abort_unless($secret && preg_match('/^[0-9]{10,16}$/', $timestamp) && hash_equals(base64_encode(hash_hmac('sha256', $timestamp.$raw, $secret, true)), $signature), 403);
        $d = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $id = $d['data']['order']['order_id'] ?? $d['data']['refund']['order_id'] ?? $d['data']['order_id'] ?? '';
        $p = DB::table('purchases')->where('provider', 'cashfree')->where('provider_order_id', $id)->first();
        if (! $p) {
            ErrorLog::record('warning', 'billing', 'Ignored a Cashfree event that matches no order here.');

            return;
        }
        self::reconcile($p);
        Settings::put('cashfreeWebhookSeen',['at' => now()->toIso8601String()]);
    }
}
