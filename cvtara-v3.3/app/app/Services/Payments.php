<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Payments
{
    public static function start(User $u, string $plan, string $phone = '', string $coupon = '', bool $useWallet = true): array
    {
        $plans = Settings::plans();
        abort_unless(isset($plans[$plan]) && $plan !== 'free', 422);
        $i = Settings::get('integrations');
        $p = Settings::providers()['payments'] ?? [];
        $provider = $p['provider'] ?? 'cashfree';
        $keys = $provider === 'cashfree' ? (! empty($p['cashfreeClientId']) && ! empty($p['cashfreeClientSecret'])) : (! empty($p['razorpayKeyId']) && ! empty($p['razorpayKeySecret']));
        if (config('app.preview') && ! $keys) {
            return self::demoStart($u, $plan, $coupon, $useWallet);
        }
        if (! ($i['payments'] ?? false) || ! in_array($provider, ['cashfree', 'razorpay'], true) || ! $keys) {
            throw ValidationException::withMessages(['payments' => 'Checkout is not configured. The owner must enable the payment gateway and save its keys.']);
        }
        if ($provider === 'cashfree') {
            return CashfreeCheckout::start($u, $plan, $phone, $coupon, $useWallet);
        }$id = (string) Str::uuid();
        $lock = $coupon !== '' ? \Illuminate\Support\Facades\Cache::lock('coupon:'.strtoupper(trim($coupon)), 25) : null;
        $lock?->block(15);
        try {
        $quote = BusinessControls::quote($plan, $coupon, $u->id, $useWallet);
        $price = $quote['totalPaise'];
        abort_unless($price > 0 && $price < 100000000, 422);
        $result = Http::withBasicAuth($p['razorpayKeyId'], $p['razorpayKeySecret'])->timeout(20)->post('https://api.razorpay.com/v1/orders', ['amount' => $price, 'currency' => 'INR', 'receipt' => $id])->throw()->json();
        DB::table('purchases')->insert(['id' => $id, 'owner_id' => $u->id, 'plan' => $plan, 'amount_paise' => $price, ...BusinessControls::quoteColumns($quote), 'provider_order_id' => $result['id'], 'is_live' => str_starts_with($p['razorpayKeyId'], 'rzp_live_'), 'limits_json' => json_encode($plans[$plan]), 'access_days' => $plans[$plan]['accessDays'], 'created_at' => now(), 'updated_at' => now()]);
        Settings::audit($u->id, 'checkout_started', $id);
        } finally {
            $lock?->release();
        }

        return ['id' => $id, 'provider' => 'razorpay', 'orderId' => $result['id'], 'keyId' => $p['razorpayKeyId'], 'amount' => $price, 'currency' => 'INR', 'test' => ! str_starts_with($p['razorpayKeyId'], 'rzp_live_')];
    }

    // Preview environments only (CVTARA_PREVIEW=true, no Razorpay keys): a clearly-labelled demo payment, never live money.
    private static function demoStart(User $u, string $plan, string $coupon, bool $useWallet = true): array
    {
        $plans = Settings::plans();
        $quote = BusinessControls::quote($plan, $coupon, $u->id, $useWallet);
        $id = (string) Str::uuid();
        $order = 'demo_'.str_replace('-', '', $id);
        DB::table('purchases')->insert(['id' => $id, 'owner_id' => $u->id, 'plan' => $plan, 'amount_paise' => $quote['totalPaise'], ...BusinessControls::quoteColumns($quote), 'provider' => 'demo', 'provider_order_id' => $order, 'is_live' => false, 'limits_json' => json_encode($plans[$plan]), 'access_days' => $plans[$plan]['accessDays'], 'created_at' => now(), 'updated_at' => now()]);
        Settings::audit($u->id, 'checkout_started', $id);

        return ['id' => $id, 'provider' => 'demo', 'orderId' => $order, 'amount' => $quote['totalPaise'], 'currency' => 'INR', 'test' => true];
    }

    public static function demoVerify(User $u, string $orderId): void
    {
        abort_unless(config('app.preview'), 404);
        $p = DB::table('purchases')->where('owner_id', $u->id)->where('provider', 'demo')->where('provider_order_id', $orderId)->first();
        abort_unless($p, 404);
        self::capture(['order_id' => $orderId, 'id' => 'demo_pay_'.Str::random(10), 'amount' => $p->amount_paise, 'currency' => 'INR', 'status' => 'captured'], 'demo');
    }

    public static function guardAddon(User $u, string $plan): void
    {
        if (str_starts_with($plan, 'addon-') && ! Settings::access($u->id)['paid']) {
            abort(422, 'Add-on packs need an active plan. Choose a plan first.');
        }
        if (! str_starts_with($plan, 'addon-') && (Settings::plans()[$plan]['type'] ?? 'plan') === 'plan') {
            $now = Settings::access($u->id);
            $mine = (int) (Settings::plans()[$plan]['rank'] ?? 0);
            if (! empty($now['paid']) && $mine < (int) ($now['limits']['rank'] ?? 0)) {
                abort(422, 'You already have a higher plan active ('.($now['limits']['name'] ?? 'current plan').'). A lower plan would add nothing. Renew your current plan or buy an add-on instead.');
            }
        }
    }

    public static function capture(array $entity, string $provider = 'razorpay'): void
    {
        DB::transaction(function () use ($entity, $provider) {
            $p = DB::table('purchases')->where('provider', $provider)->where('provider_order_id', $entity['order_id'] ?? '')->lockForUpdate()->first();
            if ($p) {
                User::whereKey($p->owner_id)->lockForUpdate()->first();
            }
            if (! $p) {
                throw ValidationException::withMessages(['payment' => 'Order not available yet; retry this event.']);
            }if (($entity['currency'] ?? '') !== $p->currency || (int) ($entity['amount'] ?? 0) !== $p->amount_paise || ($entity['status'] ?? '') !== 'captured') {
                throw ValidationException::withMessages(['payment' => 'Payment verification failed.']);
            }if (in_array($p->status, ['paid', 'refunded', 'partially_refunded', 'disputed'])) {
                return;
            }$running = DB::table('purchases')->where('owner_id', $p->owner_id)->where('id', '!=', $p->id)->where('plan', $p->plan)->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now())->max('access_until');
            $start = $running ? max(now(), \Illuminate\Support\Carbon::parse($running)) : now();
            if (str_starts_with($p->plan, 'addon-')) {
                // An add-on lasts exactly as long as the subscription it was bought on, and follows the plan into a renewal.
                $planEnd = Settings::access($p->owner_id)['accessUntil'] ?? null;
                $until = $planEnd ? \Illuminate\Support\Carbon::parse($planEnd) : now()->addDays((int) (json_decode((string) $p->limits_json, true)['accessDays'] ?? 0) ?: 30);
                DB::table('purchases')->where('id', $p->id)->update(['status' => 'paid', 'payment_reference' => $entity['id'], 'purchased_at' => now(), 'access_until' => $until, 'updated_at' => now()]);
            } else {
                $end = $start->copy()->addDays($p->access_days);
                DB::table('purchases')->where('id', $p->id)->update(['status' => 'paid', 'payment_reference' => $entity['id'], 'purchased_at' => now(), 'access_until' => $end, 'updated_at' => now()]);
                // Unused add-on credits still running move to the end of the renewed subscription.
                DB::table('purchases')->where('owner_id', $p->owner_id)->where('plan', 'like', 'addon-%')->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now())->where('access_until', '<', $end)->update(['access_until' => $end, 'updated_at' => now()]);
            }
            $p = DB::table('purchases')->where('id', $p->id)->first();
            if ($p->coupon_code) {
                $c = collect(BusinessControls::get()['coupons'])->firstWhere('code', $p->coupon_code);
                $others = DB::table('purchases')->where('coupon_code', $p->coupon_code)->where('id', '!=', $p->id)->whereIn('status', ['paid', 'partially_refunded']);
                if ($c && ((int) ($c['perUser'] ?? 1) > 0 && (clone $others)->where('owner_id', $p->owner_id)->count() >= (int) $c['perUser'] || (int) ($c['maxUses'] ?? 0) > 0 && (clone $others)->count() >= (int) $c['maxUses'])) {
                    // The customer paid in good faith, so access is granted; the owner is told to review the extra redemption.
                    Settings::audit($p->owner_id, 'coupon_limit_exceeded', $p->id);
                    ErrorLog::record('warning', 'billing', 'Coupon '.$p->coupon_code.' was redeemed beyond its limit on order '.$p->id.'. Review the order.', null, null, $p->owner_id);
                }
            }
            Referrals::credit($p);
            Wallet::spend($p);
            Services::open($p);
            Settings::audit($p->owner_id, 'purchase_verified', $p->id);
            $pl = json_decode((string) $p->limits_json, true) ?: [];
            MailAutomation::queue(User::findOrFail($p->owner_id), 'purchase_verified', $entity['id'], ['plan_name' => $pl['name'] ?? $p->plan, 'access_until' => $p->access_until ? \Illuminate\Support\Carbon::parse($p->access_until)->format('j F Y') : '', 'amount' => '₹'.number_format($p->amount_paise / 100, 2), 'resumes' => (string) ($pl['savedResumes'] ?? ''), 'letters' => (string) ($pl['savedLetters'] ?? '')]);
        });
    }

    public static function verify(User $u, array $d): void
    {
        $p = Settings::providers()['payments'] ?? [];
        $order = DB::table('purchases')->where('owner_id', $u->id)->where('provider_order_id', $d['razorpay_order_id'])->first();
        abort_unless($order, 404);
        $sig = hash_hmac('sha256', $order->provider_order_id.'|'.$d['razorpay_payment_id'], $p['razorpayKeySecret'] ?? '');
        abort_unless(! empty($p['razorpayKeySecret']) && hash_equals($sig, $d['razorpay_signature']), 403);
        $entity = Http::withBasicAuth($p['razorpayKeyId'], $p['razorpayKeySecret'])->timeout(20)->get('https://api.razorpay.com/v1/payments/'.rawurlencode($d['razorpay_payment_id']))->throw()->json();
        abort_unless(($entity['order_id'] ?? '') === $order->provider_order_id && ($entity['id'] ?? '') === $d['razorpay_payment_id'], 422);
        self::capture($entity);
    }

    public static function webhook(string $raw, string $signature, string $eventId): void
    {
        $p = Settings::providers()['payments'] ?? [];
        abort_unless(! empty($p['razorpayWebhookSecret']) && hash_equals(hash_hmac('sha256', $raw, $p['razorpayWebhookSecret']), $signature), 403);
        $d = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $key = substr($eventId ?: hash('sha256', $raw), 0, 190);
        try {
            self::process($d, $key);
        } catch (ValidationException $e) {
            // An event for a payment this site never saw (shared gateway account) must not be retried for days.
            $created = (int) ($d['created_at'] ?? 0);
            if ($created > 0 && now()->timestamp - $created > 6 * 3600) {
                ErrorLog::record('warning', 'billing', 'Ignored a gateway event that matches no order here: '.($d['event'] ?? 'unknown'));
                DB::table('webhook_events')->insertOrIgnore(['id' => $key, 'type' => 'unmatched', 'created_at' => now(), 'updated_at' => now()]);

                return;
            }
            throw $e;
        }
    }

    private static function process(array $d, string $key): void
    {
        DB::transaction(function () use ($d, $key) {
            if (DB::table('webhook_events')->where('id', $key)->exists()) {
                return;
            }$type = $d['event'] ?? '';
            $refundKey = $type === 'refund.processed' ? 'refund:'.($d['payload']['refund']['entity']['id'] ?? '') : null;
            if ($refundKey && DB::table('webhook_events')->where('id', $refundKey)->exists()) {
                return;
            }if (in_array($type, ['payment.captured', 'order.paid'])) {
                self::capture($d['payload']['payment']['entity'] ?? []);
            }if ($type === 'refund.processed') {
                $refund = $d['payload']['refund']['entity'] ?? [];
                $p = DB::table('purchases')->where('payment_reference', $refund['payment_id'] ?? '')->lockForUpdate()->first();
                if (! $p) {
                    throw ValidationException::withMessages(['payment' => 'Original payment not received yet; retry.']);
                }$total = min($p->amount_paise, $p->refunded_paise + (int) ($refund['amount'] ?? 0));
                DB::table('purchases')->where('id', $p->id)->update(['refunded_paise' => $total, 'status' => $total === $p->amount_paise ? 'refunded' : 'partially_refunded', 'updated_at' => now()]);
                Referrals::reverse($p, $total);
                Refunds::settled($p->id);
                Settings::audit($p->owner_id, 'payment_refunded', $p->id);
            }if (str_starts_with($type, 'payment.dispute.')) {
                $dispute = $d['payload']['dispute']['entity'] ?? [];
                $p = DB::table('purchases')->where('payment_reference', $dispute['payment_id'] ?? '')->lockForUpdate()->first();
                if (! $p) {
                    throw ValidationException::withMessages(['payment' => 'Original payment not received yet; retry.']);
                }$won = $type === 'payment.dispute.won' || ($type === 'payment.dispute.closed' && ($dispute['status'] ?? '') === 'won');
                if ($won && $p->status === 'disputed') {
                    DB::table('purchases')->where('id', $p->id)->update(['status' => $p->refunded_paise > 0 ? 'partially_refunded' : 'paid', 'updated_at' => now()]);
                    Referrals::restore($p);
                    Settings::audit($p->owner_id, 'payment_dispute_won', $p->id);
                } elseif (! $won && in_array($p->status, ['paid', 'partially_refunded'])) {
                    DB::table('purchases')->where('id', $p->id)->update(['status' => 'disputed', 'updated_at' => now()]);
                    Referrals::reverse($p, $p->amount_paise);
                    Settings::audit($p->owner_id, 'payment_disputed', $p->id);
                }
            }if ($refundKey) {
                DB::table('webhook_events')->insert(['id' => $refundKey, 'type' => $type, 'created_at' => now(), 'updated_at' => now()]);
            }DB::table('webhook_events')->insertOrIgnore(['id' => $key, 'type' => $type, 'created_at' => now(), 'updated_at' => now()]);
        });
    }
}
