<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\DB;
use App\Support\Env;
use App\Support\Logger;

/**
 * Cashfree Payment Gateway — collections (PG) and disbursals (Payouts).
 *
 * Required .env variables:
 *   CASHFREE_APP_ID          — PG App ID (from Cashfree dashboard)
 *   CASHFREE_SECRET_KEY      — PG Secret Key
 *   CASHFREE_WEBHOOK_SECRET  — Webhook signature secret
 *   CASHFREE_ENV             — 'sandbox' | 'production'  (default: sandbox)
 *   CASHFREE_PAYOUT_APP_ID   — Payout App ID  (can be same as PG)
 *   CASHFREE_PAYOUT_SECRET   — Payout Secret Key
 *
 * Optional:
 *   CASHFREE_RETURN_URL      — absolute URL user is sent to after payment
 *                              default: APP_URL + /checkout/return
 */
final class CashfreeService
{
    // ── PG Collections ────────────────────────────────────────────────────────

    /**
     * Create a Cashfree PG order and return the payment_session_id.
     *
     * @param int    $purchaseId   Internal purchase row ID (becomes order_id prefix)
     * @param int    $amountPaise  Amount in paise (₹1 = 100 paise)
     * @param string $customerName
     * @param string $customerEmail
     * @param string $customerPhone 10-digit Indian mobile number
     * @return array{order_id: string, payment_session_id: string, order_status: string}
     * @throws \RuntimeException on API error
     */
    public function createOrder(
        int    $purchaseId,
        int    $amountPaise,
        string $customerName,
        string $customerEmail,
        string $customerPhone
    ): array {
        $orderId    = 'cvt_' . $purchaseId . '_' . time();
        $appUrl     = rtrim((string) Env::get('APP_URL', ''), '/');
        $returnUrl  = Config::get('CASHFREE_RETURN_URL', $appUrl . '/checkout/return')
                    . '?order_id=' . $orderId;

        $body = [
            'order_id'       => $orderId,
            'order_amount'   => round($amountPaise / 100, 2),
            'order_currency' => 'INR',
            'customer_details' => [
                'customer_id'    => 'user_' . $purchaseId,
                'customer_name'  => $customerName ?: 'Customer',
                'customer_email' => $customerEmail,
                'customer_phone' => $this->normalisePhone($customerPhone),
            ],
            'order_meta' => [
                'return_url'  => $returnUrl,
                'notify_url'  => $appUrl . '/webhooks/cashfree',
            ],
            'order_note' => 'CVTara access pass — purchase #' . $purchaseId,
        ];

        $raw  = $this->pgPost('/orders', $body);
        $json = json_decode($raw, true);

        if (empty($json['payment_session_id'])) {
            Logger::error('Cashfree createOrder failed', ['response' => $raw, 'purchase' => $purchaseId]);
            throw new \RuntimeException($json['message'] ?? 'Cashfree order creation failed.');
        }

        // Persist order_id so the webhook can match it
        DB::update('purchases', ['payment_reference' => $orderId], 'id = ?', [$purchaseId]);

        Logger::info('Cashfree order created', ['order_id' => $orderId, 'purchase' => $purchaseId]);
        return [
            'order_id'          => $orderId,
            'payment_session_id'=> $json['payment_session_id'],
            'order_status'      => $json['order_status'] ?? 'ACTIVE',
        ];
    }

    /**
     * Verify a completed payment by querying Cashfree's Order status API.
     * Call this from the /checkout/return handler to confirm before activating access.
     *
     * @return array{status: string, amount: float, payment_id: string}
     */
    public function verifyOrder(string $orderId): array
    {
        $raw  = $this->pgGet('/orders/' . rawurlencode($orderId));
        $json = json_decode($raw, true);

        return [
            'status'     => $json['order_status'] ?? 'UNKNOWN',
            'amount'     => (float) ($json['order_amount'] ?? 0),
            'payment_id' => $json['cf_order_id'] ?? '',
        ];
    }

    // ── Cashfree Payouts ──────────────────────────────────────────────────────

    /**
     * Initiate a UPI or bank transfer via Cashfree Payouts API.
     *
     * @param int    $walletEntryId  DB row being paid out
     * @param int    $amountPaise    Amount in paise
     * @param string $transferMode   'upi' | 'banktransfer'
     * @param string $upiOrAccount   UPI VPA  OR  bank account number
     * @param string $ifsc           IFSC code (required when transferMode = 'banktransfer')
     * @param string $beneficiaryName
     * @param string $beneficiaryEmail
     * @param string $beneficiaryPhone
     * @return array{transfer_id: string, status: string}
     * @throws \RuntimeException on API error
     */
    public function initiatePayout(
        int    $walletEntryId,
        int    $amountPaise,
        string $transferMode,     // 'upi' or 'banktransfer'
        string $upiOrAccount,
        string $ifsc,
        string $beneficiaryName,
        string $beneficiaryEmail,
        string $beneficiaryPhone
    ): array {
        $transferId = 'cvtref_' . $walletEntryId . '_' . time();
        $amountRs   = round($amountPaise / 100, 2);

        $bene = [
            'name'    => $beneficiaryName ?: 'CVTara User',
            'email'   => $beneficiaryEmail,
            'phone'   => $this->normalisePhone($beneficiaryPhone),
            'address1'=> 'India',
            'city'    => 'India',
            'state'   => 'India',
            'pincode' => '000000',
        ];

        if ($transferMode === 'upi') {
            $bene['vpa'] = $upiOrAccount;
        } else {
            $bene['bankAccount'] = $upiOrAccount;
            $bene['ifsc']        = strtoupper($ifsc);
        }

        $token = $this->payoutToken();

        $body = [
            'beneDetails'  => $bene,
            'amount'       => (string) $amountRs,
            'remarks'      => 'CVTara referral commission #' . $walletEntryId,
            'transferId'   => $transferId,
            'transferMode' => $transferMode,
        ];

        $raw  = $this->payoutPost('/directTransfer', $body, $token);
        $json = json_decode($raw, true);

        $status = $json['data']['transfer']['transferStatus'] ?? ($json['status'] ?? 'UNKNOWN');

        if (isset($json['status']) && strtoupper((string) $json['status']) === 'ERROR') {
            Logger::error('Cashfree payout failed', ['response' => $raw, 'walletEntry' => $walletEntryId]);
            throw new \RuntimeException($json['message'] ?? 'Cashfree payout failed.');
        }

        Logger::info('Cashfree payout initiated', [
            'transfer_id'    => $transferId,
            'wallet_entry'   => $walletEntryId,
            'amount_paise'   => $amountPaise,
            'mode'           => $transferMode,
        ]);

        return ['transfer_id' => $transferId, 'status' => (string) $status];
    }

    // ── Webhook verification ──────────────────────────────────────────────────

    /**
     * Verify Cashfree webhook signature (v2023-08-01 HMAC-SHA256).
     * Header names: X-Webhook-Timestamp and X-Webhook-Signature
     */
    public function verifyWebhook(string $payload, string $timestamp, string $signature): bool
    {
        $secret = (string) Config::get('CASHFREE_WEBHOOK_SECRET', '');
        if ($secret === '') {
            Logger::error('CASHFREE_WEBHOOK_SECRET not set; rejecting webhook');
            return false;
        }
        $signed = base64_encode(hash_hmac('sha256', $timestamp . $payload, $secret, true));
        return hash_equals($signed, $signature);
    }

    // ── PG HTTP helpers ───────────────────────────────────────────────────────

    private function pgPost(string $path, array $body): string
    {
        return $this->curl('POST', $this->pgBase() . $path, json_encode($body) ?: '{}', $this->pgHeaders());
    }

    private function pgGet(string $path): string
    {
        return $this->curl('GET', $this->pgBase() . $path, null, $this->pgHeaders());
    }

    private function pgBase(): string
    {
        return $this->isSandbox()
            ? 'https://sandbox.cashfree.com/pg'
            : 'https://api.cashfree.com/pg';
    }

    private function pgHeaders(): array
    {
        $appId  = (string) Config::get('CASHFREE_APP_ID', '');
        $secret = (string) Config::get('CASHFREE_SECRET_KEY', '');
        if ($appId === '' || $secret === '') {
            throw new \RuntimeException('Cashfree keys are not configured. Add them under Owner → Settings.');
        }
        return [
            'x-api-version: 2023-08-01',
            'x-client-id: '     . $appId,
            'x-client-secret: ' . $secret,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    // ── Payout HTTP helpers ───────────────────────────────────────────────────

    private function payoutToken(): string
    {
        $base = $this->isSandbox()
            ? 'https://payout-sandbox.cashfree.com/payout/v1.2'
            : 'https://payout-api.cashfree.com/payout/v1.2';

        $raw  = $this->curl('POST', $base . '/authorize', null, [
            'X-Client-Id: '     . (string) Config::get('CASHFREE_PAYOUT_APP_ID', Config::get('CASHFREE_APP_ID', '')),
            'X-Client-Secret: ' . (string) Config::get('CASHFREE_PAYOUT_SECRET', Config::get('CASHFREE_SECRET_KEY', '')),
            'Content-Type: application/json',
        ]);

        $json  = json_decode($raw, true);
        $token = $json['data']['token'] ?? '';
        if ($token === '') {
            throw new \RuntimeException('Cashfree Payout auth failed: ' . ($json['message'] ?? $raw));
        }
        return $token;
    }

    private function payoutPost(string $path, array $body, string $token): string
    {
        $base = $this->isSandbox()
            ? 'https://payout-sandbox.cashfree.com/payout/v1.2'
            : 'https://payout-api.cashfree.com/payout/v1.2';

        return $this->curl('POST', $base . $path, json_encode($body) ?: '{}', [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

    private function curl(string $method, string $url, ?string $body, array $headers): string
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new \RuntimeException('cURL error: ' . curl_error($ch));
        }
        curl_close($ch);
        return (string) $raw;
    }

    private function isSandbox(): bool
    {
        return strtolower((string) Config::get('CASHFREE_ENV', 'sandbox')) !== 'production';
    }

    private function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        // Strip country code if provided as 91XXXXXXXXXX
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }
        return strlen($digits) === 10 ? $digits : ($phone ?: '9999999999');
    }
}
