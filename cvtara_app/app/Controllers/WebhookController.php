<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\CashfreeService;
use App\Services\PaymentService;
use App\Support\DB;
use App\Support\Logger;
use App\Support\Response;

final class WebhookController
{
    /**
     * POST /webhooks/razorpay
     * Legacy handler — kept in case the account switches back or uses hybrid mode.
     */
    public function razorpay(): never
    {
        $raw  = (string) file_get_contents('php://input');
        $sig  = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
        $pay  = new PaymentService();

        if (!$pay->razorpaySignature($raw, $sig)) {
            Logger::error('Razorpay webhook signature failed', ['sig' => $sig]);
            Response::json(['ok' => false], 400);
        }

        $event      = json_decode($raw, true) ?: [];
        $ref        = $event['payload']['payment']['entity']['id']
                   ?? ($event['payload']['order']['entity']['id'] ?? null);
        $notes      = $event['payload']['payment']['entity']['notes'] ?? [];
        $purchaseId = (int) ($notes['purchase_id'] ?? 0);
        $purchase   = $purchaseId ? DB::fetch('SELECT * FROM purchases WHERE id = ?', [$purchaseId]) : null;

        if ($purchase && ($event['event'] ?? '') === 'payment.captured') {
            DB::update('purchases', [
                'status'            => 'paid',
                'payment_reference' => $ref,
                'is_live'           => 1,
            ], 'id = ?', [$purchaseId]);
            $pay->activate((int) $purchase['user_id'], $purchase['plan'], (int) $purchase['credits_addon'], (string) $ref, 'razorpay', true);
        }

        Response::json(['ok' => true]);
    }

    /**
     * POST /webhooks/cashfree  (also mapped to /api/v1/payments/cashfree-webhook)
     *
     * Cashfree sends: X-Webhook-Timestamp and X-Webhook-Signature headers.
     * Events we handle:
     *   PAYMENT_SUCCESS_WEBHOOK — payment succeeded
     *   ORDER_PAID              — order fully paid
     *   PAYMENT_FAILED_WEBHOOK  — log only, no action
     */
    public function cashfree(): never
    {
        $raw = (string) file_get_contents('php://input');
        $sig = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
        $ts  = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';

        $cf = new CashfreeService();
        if (!$cf->verifyWebhook($raw, $ts, $sig)) {
            Logger::error('Cashfree webhook signature mismatch', [
                'ts'  => $ts,
                'sig' => substr($sig, 0, 20),
            ]);
            Response::json(['ok' => false, 'error' => 'invalid_signature'], 400);
        }

        $event    = json_decode($raw, true) ?: [];
        $type     = $event['type'] ?? '';
        $orderId  = $event['data']['order']['order_id'] ?? null;
        $payStatus= $event['data']['payment']['payment_status'] ?? '';

        Logger::info('Cashfree webhook received', ['type' => $type, 'order' => $orderId]);

        $isSuccess = $type === 'PAYMENT_SUCCESS_WEBHOOK'
                  || $type === 'ORDER_PAID'
                  || $payStatus === 'SUCCESS';

        if (!$isSuccess || !$orderId) {
            Response::json(['ok' => true, 'note' => 'event_ignored']);
        }

        // Match purchase by payment_reference (order_id we stored in createOrder)
        $purchase = DB::fetch('SELECT * FROM purchases WHERE payment_reference = ?', [$orderId]);

        // Fallback: parse numeric ID from order_id like 'cvt_123_...'
        if (!$purchase) {
            preg_match('/^cvt_(\d+)_/', (string) $orderId, $m);
            $pid = (int) ($m[1] ?? 0);
            $purchase = $pid ? DB::fetch('SELECT * FROM purchases WHERE id = ?', [$pid]) : null;
        }

        if (!$purchase) {
            Logger::error('Cashfree webhook: purchase not found', ['order_id' => $orderId]);
            Response::json(['ok' => true, 'note' => 'purchase_not_found']);
        }

        // Idempotency guard — do not re-activate already paid orders
        if ($purchase['status'] === 'paid') {
            Response::json(['ok' => true, 'note' => 'already_processed']);
        }

        DB::update('purchases', [
            'status'            => 'paid',
            'payment_reference' => $orderId,
            'is_live'           => 1,
            'provider'          => 'cashfree',
        ], 'id = ?', [$purchase['id']]);

        (new PaymentService())->activate(
            (int) $purchase['user_id'],
            $purchase['plan'],
            (int) $purchase['credits_addon'],
            $orderId,
            'cashfree',
            true
        );

        Response::json(['ok' => true]);
    }
}

