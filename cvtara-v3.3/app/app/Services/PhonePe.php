<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * PhonePe Standard Checkout (PG API v2). The server creates the order and sends the customer to PhonePe's payment page;
 * access is granted only after PhonePe's order-status API says COMPLETED, whether the customer returns, the callback
 * arrives or the scheduled check finds it. The callback's body is never trusted on its own.
 */
class PhonePe
{
    public static function keys(): array
    {
        return Settings::providers()['payments'] ?? [];
    }

    public static function configured(?array $k = null): bool
    {
        $k ??= self::keys();

        return ! empty($k['phonepeClientId']) && ! empty($k['phonepeClientSecret']);
    }

    public static function live(?array $k = null): bool
    {
        return ((($k ?? self::keys())['phonepeMode'] ?? 'test')) === 'live';
    }

    // PhonePe's addresses. Tests may point both at a local stand-in, but never on a production site.
    public static function pgBase(?array $k = null): string
    {
        $o = env('PHONEPE_API_BASE');
        if ($o && config('app.env') !== 'production') {
            return rtrim($o, '/');
        }

        return self::live($k) ? 'https://api.phonepe.com/apis/pg' : 'https://api-preprod.phonepe.com/apis/pg-sandbox';
    }

    private static function authBase(array $k): string
    {
        $o = env('PHONEPE_API_BASE');
        if ($o && config('app.env') !== 'production') {
            return rtrim($o, '/');
        }

        return self::live($k) ? 'https://api.phonepe.com/apis/identity-manager' : 'https://api-preprod.phonepe.com/apis/pg-sandbox';
    }

    /** An O-Bearer access token, reused until shortly before PhonePe says it expires. */
    public static function token(?array $k = null, bool $fresh = false): string
    {
        $k ??= self::keys();
        $cache = 'phonepe-token:'.hash('sha256', ($k['phonepeClientId'] ?? '').'|'.($k['phonepeClientSecret'] ?? '').'|'.($k['phonepeClientVersion'] ?? '1').'|'.self::pgBase($k));
        if (! $fresh && ($t = Cache::get($cache))) {
            return $t;
        }
        $r = Http::asForm()->timeout(20)->post(self::authBase($k).'/v1/oauth/token', ['client_id' => $k['phonepeClientId'] ?? '', 'client_version' => $k['phonepeClientVersion'] ?? '1', 'client_secret' => $k['phonepeClientSecret'] ?? '', 'grant_type' => 'client_credentials'])->throw()->json();
        $t = (string) ($r['access_token'] ?? '');
        abort_if($t === '', 502, 'PhonePe did not return an access token.');
        $ttl = (int) ($r['expires_at'] ?? 0) - now()->timestamp - 120;
        Cache::put($cache, $t, max(60, min($ttl > 0 ? $ttl : 900, 86400)));

        return $t;
    }

    private static function http(array $k)
    {
        return Http::withHeaders(['Authorization' => 'O-Bearer '.self::token($k)])->acceptJson()->timeout(20);
    }

    /** Our own order id for PhonePe: letters and digits only, well under its 63-character limit. */
    public static function orderId(string $purchaseId): string
    {
        return 'CVT'.strtoupper(str_replace('-', '', $purchaseId));
    }

    /** Creates the PhonePe order and returns its id and the payment page the customer is sent to. */
    public static function create(string $purchaseId, int $amountPaise, string $label): array
    {
        $k = self::keys();
        $merchantOrderId = self::orderId($purchaseId);
        $r = self::http($k)->post(self::pgBase($k).'/checkout/v2/pay', [
            'merchantOrderId' => $merchantOrderId,
            'amount' => $amountPaise,
            'expireAfter' => 1200,
            'metaInfo' => ['udf1' => $purchaseId],
            'paymentFlow' => ['type' => 'PG_CHECKOUT', 'message' => mb_substr($label, 0, 60), 'merchantUrls' => ['redirectUrl' => url('/checkout.html?phonepe_order='.$merchantOrderId)]],
        ])->throw()->json();
        abort_if(empty($r['redirectUrl']), 502, 'PhonePe did not return a payment page.');

        return ['merchantOrderId' => $merchantOrderId, 'redirectUrl' => (string) $r['redirectUrl'], 'live' => self::live($k)];
    }

    public static function status(string $merchantOrderId): array
    {
        $k = self::keys();

        return self::http($k)->get(self::pgBase($k).'/checkout/v2/order/'.rawurlencode($merchantOrderId).'/status', ['details' => 'false'])->throw()->json();
    }

    /**
     * Asks PhonePe for the order's state and grants access when it is COMPLETED. Returns COMPLETED, PENDING or FAILED.
     * Safe to call any number of times: capture() ignores an order that is already paid.
     */
    public static function sync(object $purchase): string
    {
        if ($purchase->status !== 'created') {
            return in_array($purchase->status, ['paid', 'partially_refunded', 'refunded', 'disputed'], true) ? 'COMPLETED' : 'FAILED';
        }
        $s = self::status($purchase->provider_order_id);
        $state = strtoupper((string) ($s['state'] ?? 'PENDING'));
        if ($state === 'COMPLETED') {
            $paid = collect($s['paymentDetails'] ?? [])->first(fn ($d) => strtoupper((string) ($d['state'] ?? '')) === 'COMPLETED') ?? [];
            Payments::capture(['order_id' => $purchase->provider_order_id, 'id' => (string) ($paid['transactionId'] ?? $s['orderId'] ?? ''), 'amount' => (int) ($s['amount'] ?? 0), 'currency' => 'INR', 'status' => 'captured'], 'phonepe');
        }

        return in_array($state, ['COMPLETED', 'FAILED'], true) ? $state : 'PENDING';
    }

    /** The customer is back from PhonePe's page: check their own order. */
    public static function verify(User $u, string $merchantOrderId): string
    {
        $p = DB::table('purchases')->where('owner_id', $u->id)->where('provider', 'phonepe')->where('provider_order_id', $merchantOrderId)->first();
        abort_unless($p, 404);

        return self::sync($p);
    }

    /** Scheduled safety net: settles PhonePe orders whose customer closed the page and whose callback never came. */
    public static function reconcile(): int
    {
        if (! self::configured()) {
            return 0;
        }
        $done = 0;
        $orders = DB::table('purchases')->where('provider', 'phonepe')->where('status', 'created')->where('created_at', '<', now()->subMinutes(3))->where('created_at', '>', now()->subDays(2))->orderBy('created_at')->limit(25)->get();
        foreach ($orders as $p) {
            try {
                $done += self::sync($p) === 'COMPLETED' ? 1 : 0;
            } catch (\Throwable $e) {
                ErrorLog::record('warning', 'billing', 'Could not check PhonePe order '.$p->provider_order_id.': '.mb_substr($e->getMessage(), 0, 200));
            }
        }

        return $done;
    }

    /** Sends a full refund for a PhonePe order back to the original payment method. */
    public static function refund(object $p, string $merchantRefundId): void
    {
        $k = self::keys();
        $r = self::http($k)->post(self::pgBase($k).'/payments/v2/refund', ['merchantRefundId' => $merchantRefundId, 'originalMerchantOrderId' => $p->provider_order_id, 'amount' => (int) $p->amount_paise])->throw()->json();
        if (strtoupper((string) ($r['state'] ?? '')) === 'FAILED') {
            throw ValidationException::withMessages(['refund' => 'PhonePe declined the refund. Nothing was sent. Check the order in the PhonePe dashboard.']);
        }
    }

    /** The callback carries Authorization: SHA256(username:password), with the pair set in the PhonePe dashboard. */
    public static function authorised(string $header): bool
    {
        $k = self::keys();
        if (empty($k['phonepeWebhookUsername']) || empty($k['phonepeWebhookPassword'])) {
            return false;
        }
        $given = strtolower(trim(preg_replace('/^sha256\s+/i', '', trim($header))));

        return $given !== '' && hash_equals(hash('sha256', $k['phonepeWebhookUsername'].':'.$k['phonepeWebhookPassword']), $given);
    }

    public static function webhook(string $raw, string $authorization): void
    {
        abort_unless(self::authorised($authorization), 403);
        $d = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $type = (string) ($d['event'] ?? $d['type'] ?? '');
        $payload = (array) ($d['payload'] ?? []);
        $key = 'phonepe:'.hash('sha256', $raw);
        if (DB::table('webhook_events')->where('id', $key)->exists()) {
            return;
        }
        if (str_starts_with($type, 'checkout.order.')) {
            $p = DB::table('purchases')->where('provider', 'phonepe')->where('provider_order_id', (string) ($payload['merchantOrderId'] ?? ''))->first();
            if ($p) {
                // The callback only says which order changed. Its state is read back from PhonePe before anything is granted.
                self::sync($p);
            } else {
                ErrorLog::record('warning', 'billing', 'Ignored a PhonePe event that matches no order here: '.$type);
            }
        }
        if (str_starts_with($type, 'pg.refund.')) {
            self::refundEvent($type, $payload);
        }
        DB::table('webhook_events')->insertOrIgnore(['id' => $key, 'type' => $type ?: 'phonepe', 'created_at' => now(), 'updated_at' => now()]);
    }

    private static function refundEvent(string $type, array $payload): void
    {
        $refundId = (string) ($payload['merchantRefundId'] ?? '');
        $p = DB::table('purchases')->where('provider', 'phonepe')->where('provider_order_id', (string) ($payload['originalMerchantOrderId'] ?? ''))->first();
        if (! $p || $refundId === '') {
            ErrorLog::record('warning', 'billing', 'Ignored a PhonePe refund event that matches no order here: '.$type);

            return;
        }
        $k = self::keys();
        $r = self::http($k)->get(self::pgBase($k).'/payments/v2/refund/'.rawurlencode($refundId).'/status')->throw()->json();
        $state = strtoupper((string) ($r['state'] ?? ''));
        if ($state === 'FAILED') {
            ErrorLog::record('error', 'billing', 'PhonePe could not complete refund '.$refundId.' for order ORD-'.strtoupper(substr($p->id, 0, 6)).'. Check it in the PhonePe dashboard and refund the customer by hand if needed.', null, null, $p->owner_id);
            Settings::audit($p->owner_id, 'payment_refund_failed', $p->id);

            return;
        }
        if ($state !== 'COMPLETED' || ($r['originalMerchantOrderId'] ?? $p->provider_order_id) !== $p->provider_order_id) {
            return;
        }
        DB::transaction(function () use ($p, $r, $refundId) {
            $refundKey = 'refund:phonepe:'.$refundId;
            if (DB::table('webhook_events')->where('id', $refundKey)->exists()) {
                return;
            }
            $p = DB::table('purchases')->where('id', $p->id)->lockForUpdate()->first();
            // A refund this site sent already counted the full amount when PhonePe accepted it.
            $ours = DB::table('refund_requests')->where('purchase_id', $p->id)->where('provider_refund_id', $refundId)->exists();
            $total = $ours ? max((int) $p->refunded_paise, min($p->amount_paise, (int) ($r['amount'] ?? 0))) : min($p->amount_paise, $p->refunded_paise + (int) ($r['amount'] ?? 0));
            DB::table('purchases')->where('id', $p->id)->update(['refunded_paise' => $total, 'status' => $total === (int) $p->amount_paise ? 'refunded' : 'partially_refunded', 'updated_at' => now()]);
            Referrals::reverse($p, $total);
            Refunds::settled($p->id);
            Settings::audit($p->owner_id, 'payment_refunded', $p->id);
            DB::table('webhook_events')->insert(['id' => $refundKey, 'type' => 'pg.refund.completed', 'created_at' => now(), 'updated_at' => now()]);
        });
    }
}
