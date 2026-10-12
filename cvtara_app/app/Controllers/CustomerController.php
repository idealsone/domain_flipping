<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AtsScannerService;
use App\Services\FeedbackBoard;
use App\Services\CashfreeService;
use App\Services\ContentCatalog;
use App\Services\TailorEngineService;
use App\Support\Auth;
use App\Support\Config;
use App\Support\DB;
use App\Support\Logger;
use App\Support\Response;
use App\Support\View;

final class CustomerController
{
    public function workspace(): never
    {
        $user = Auth::user();
        $credits = DB::fetch('SELECT * FROM tailor_credits WHERE user_id = ?', [$user['id']]) ?: ['remaining_balance' => 0, 'used_count' => 0, 'total_allowed' => 0];
        $resumes = DB::fetchAll('SELECT * FROM resumes WHERE user_id = ? ORDER BY updated_at DESC LIMIT 8', [$user['id']]);
        $apps = DB::fetchAll('SELECT * FROM applications WHERE user_id = ? ORDER BY id DESC LIMIT 8', [$user['id']]);
        Response::html(View::render('customer/workspace', [
            'title' => 'Workspace',
            'credits' => $credits,
            'resumes' => $resumes,
            'apps' => $apps,
        ], 'layouts/app'));
    }

    public function resume(): never
    {
        $id = (int) ($_GET['id'] ?? 0);
        $resume = $id ? DB::fetch('SELECT * FROM resumes WHERE id = ? AND user_id = ?', [$id, Auth::id()]) : null;
        $content = $resume ? json_decode((string) $resume['content_json'], true) : [
            'name' => Auth::user()['name'], 'role' => '', 'summary' => '', 'experience' => '', 'education' => '', 'skills' => '',
        ];
        Response::html(View::render('customer/resume', [
            'title' => 'Resume studio',
            'resume' => $resume,
            'content' => $content,
        ], 'layouts/app'));
    }

    public function resumeSave(): never
    {
        $payload = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'role' => trim((string) ($_POST['role'] ?? '')),
            'summary' => trim((string) ($_POST['summary'] ?? '')),
            'experience' => trim((string) ($_POST['experience'] ?? '')),
            'education' => trim((string) ($_POST['education'] ?? '')),
            'skills' => trim((string) ($_POST['skills'] ?? '')),
        ];
        $title = $payload['role'] !== '' ? $payload['role'] : 'Untitled resume';
        $id = (int) ($_POST['id'] ?? 0);
        if ($id && DB::fetch('SELECT id FROM resumes WHERE id = ? AND user_id = ?', [$id, Auth::id()])) {
            DB::update('resumes', ['title' => $title, 'content_json' => json_encode($payload), 'updated_at' => now()], 'id = ?', [$id]);
        } else {
            $id = DB::insert('resumes', [
                'user_id' => Auth::id(),
                'title' => $title,
                'content_json' => json_encode($payload),
                'updated_at' => now(),
            ]);
        }
        flash('success', 'Resume saved. Claims stay as you wrote them.');
        Response::redirect('/workspace/resume?id=' . $id);
    }

    public function match(): never
    {
        $scans = DB::fetchAll('SELECT id, job_title, score, created_at FROM ats_scans WHERE user_id = ? ORDER BY id DESC LIMIT 12', [Auth::id()]);
        Response::html(View::render('customer/match', ['title' => 'Compliance match', 'scans' => $scans, 'report' => null], 'layouts/app'));
    }

    public function matchSubmit(): never
    {
        $user = Auth::user();
        $isPremium = is_premium($user);
        $report = (new AtsScannerService())->scan((string) ($_POST['resume'] ?? ''), (string) ($_POST['job'] ?? ''), $isPremium);
        DB::insert('ats_scans', [
            'user_id' => Auth::id(),
            'job_title' => trim((string) ($_POST['job_title'] ?? '')) ?: null,
            'job_text' => (string) ($_POST['job'] ?? ''),
            'resume_text' => (string) ($_POST['resume'] ?? ''),
            'score' => $report['score'],
            'report_json' => json_encode($report),
            'created_at' => now(),
        ]);
        DB::update('users', ['last_ats_at' => now(), 'updated_at' => now()], 'id = ?', [Auth::id()]);
        $scans = DB::fetchAll('SELECT id, job_title, score, created_at FROM ats_scans WHERE user_id = ? ORDER BY id DESC LIMIT 12', [Auth::id()]);
        Response::html(View::render('customer/match', ['title' => 'Compliance match', 'scans' => $scans, 'report' => $report], 'layouts/app'));
    }

    public function tailor(): never
    {
        $credits = DB::fetch('SELECT * FROM tailor_credits WHERE user_id = ?', [Auth::id()]);
        Response::html(View::render('customer/tailor', ['title' => 'Profile optimization', 'credits' => $credits, 'result' => null], 'layouts/app'));
    }

    public function tailorSubmit(): never
    {
        try {
            $result = (new TailorEngineService())->optimize(Auth::id(), (string) ($_POST['text'] ?? ''), (string) ($_POST['job'] ?? ''));
            flash('success', 'Wording updated from your existing facts. One credit used.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            $result = null;
        }
        $credits = DB::fetch('SELECT * FROM tailor_credits WHERE user_id = ?', [Auth::id()]);
        Response::html(View::render('customer/tailor', ['title' => 'Profile optimization', 'credits' => $credits, 'result' => $result], 'layouts/app'));
    }

    public function applications(): never
    {
        $apps = DB::fetchAll('SELECT * FROM applications WHERE user_id = ? ORDER BY id DESC', [Auth::id()]);
        Response::html(View::render('customer/applications', ['title' => 'Applications', 'apps' => $apps], 'layouts/app'));
    }

    public function applicationSave(): never
    {
        DB::insert('applications', [
            'user_id' => Auth::id(),
            'company' => trim((string) ($_POST['company'] ?? 'Company')),
            'role' => trim((string) ($_POST['role'] ?? 'Role')),
            'stage' => in_array($_POST['stage'] ?? '', ['saved', 'applied', 'interview', 'offer'], true) ? $_POST['stage'] : 'saved',
            'next_action' => trim((string) ($_POST['next_action'] ?? '')) ?: null,
            'resume_id' => null,
            'created_at' => now(),
        ]);
        flash('success', 'Application saved.');
        Response::redirect('/workspace/applications');
    }

    public function checkout(): never
    {
        $user     = Auth::user();
        $priceKey = $user['experience_tier'] === 'fresher' ? 'fresherPrice' : 'price';
        $cfEnv    = strtolower((string) Config::get('CASHFREE_ENV', 'sandbox'));

        Response::html(View::render('customer/checkout', [
            'title'      => 'Checkout',
            'plans'      => ContentCatalog::plans(),
            'priceKey'   => $priceKey,
            'cashfreeEnv'=> $cfEnv === 'production' ? 'production' : 'sandbox',
        ], 'layouts/app'));
    }

    /**
     * POST /checkout — create a Cashfree order and redirect user to SDK payment page.
     */
    public function checkoutStart(): never
    {
        $plan    = (string) ($_POST['plan'] ?? 'd30');
        $catalog = ContentCatalog::plans()[$plan] ?? null;
        if (!$catalog) {
            flash('error', 'Choose a valid plan.');
            Response::redirect('/checkout');
        }
        $user    = Auth::user();
        $credits = max(0, min(50, (int) ($_POST['credits'] ?? 0)));
        $price   = $user['experience_tier'] === 'fresher' ? $catalog['fresherPrice'] : $catalog['price'];
        $price  += $credits * 1900;

        $id = DB::insert('purchases', [
            'user_id'           => $user['id'],
            'plan'              => $plan,
            'amount_paise'      => $price,
            'credits_addon'     => $credits,
            'status'            => 'started',
            'provider'          => 'cashfree',
            'payment_reference' => null,
            'is_live'           => 0,
            'access_until'      => null,
            'created_at'        => now(),
        ]);
        DB::update('users', ['last_checkout_at' => now(), 'updated_at' => now()], 'id = ?', [$user['id']]);

        // Try to create a live Cashfree order
        try {
            $cf      = new CashfreeService();
            $session = $cf->createOrder(
                $id,
                $price,
                $user['name'] ?? 'Customer',
                $user['email'],
                $user['phone'] ?? ''
            );
            // Store session for checkout page
            $_SESSION['cf_session']    = $session['payment_session_id'];
            $_SESSION['cf_order_id']   = $session['order_id'];
            $_SESSION['cf_purchase_id']= $id;
            Response::redirect('/checkout?pay=1');
        } catch (\Throwable $e) {
            Logger::error('Cashfree createOrder error', ['error' => $e->getMessage(), 'purchase' => $id]);
            // Fallback: inform owner to mark paid manually
            flash('info', 'Order #' . $id . ' created for ' . money_inr($price) . '. Payment gateway is being configured — the owner can mark this paid from the admin console.');
            Response::redirect('/checkout');
        }
    }

    /**
     * GET /checkout/return — Cashfree redirects here after payment.
     * Verifies the order and activates access if confirmed paid.
     */
    public function checkoutReturn(): never
    {
        $orderId    = (string) ($_GET['order_id'] ?? '');
        $purchaseId = (int) ($_SESSION['cf_purchase_id'] ?? 0);

        // Clear session keys
        unset($_SESSION['cf_session'], $_SESSION['cf_order_id'], $_SESSION['cf_purchase_id']);

        if (!$orderId || !$purchaseId) {
            flash('error', 'Payment reference missing. If payment was completed, access activates within minutes via webhook.');
            Response::redirect('/workspace');
        }

        $purchase = DB::fetch('SELECT * FROM purchases WHERE id = ? AND user_id = ?', [$purchaseId, Auth::id()]);
        if (!$purchase) {
            flash('error', 'Order not found.');
            Response::redirect('/checkout');
        }

        // Already activated via webhook
        if ($purchase['status'] === 'paid') {
            flash('success', 'Payment confirmed. Your workspace is active!');
            Response::redirect('/workspace');
        }

        // Verify with Cashfree API
        try {
            $cf     = new CashfreeService();
            $result = $cf->verifyOrder($orderId);
            if ($result['status'] === 'PAID') {
                DB::update('purchases', ['status' => 'paid', 'payment_reference' => $orderId, 'is_live' => 1, 'provider' => 'cashfree'], 'id = ?', [$purchaseId]);
                (new \App\Services\PaymentService())->activate((int) $purchase['user_id'], $purchase['plan'], (int) $purchase['credits_addon'], $orderId, 'cashfree', true);
                flash('success', 'Payment confirmed! Your access is now active. Welcome to your workspace.');
                Response::redirect('/workspace');
            }
        } catch (\Throwable $e) {
            Logger::error('Cashfree verifyOrder failed', ['order' => $orderId, 'error' => $e->getMessage()]);
        }

        flash('info', 'Payment is being verified. If charged, access activates automatically within minutes.');
        Response::redirect('/workspace');
    }

    public function wallet(): never
    {
        $rows      = DB::fetchAll('SELECT * FROM wallet_entries WHERE user_id = ? ORDER BY id DESC', [Auth::id()]);
        $available = (int) DB::value("SELECT COALESCE(SUM(amount_paise),0) FROM wallet_entries WHERE user_id = ? AND status = 'available'", [Auth::id()]);
        $held      = (int) DB::value("SELECT COALESCE(SUM(amount_paise),0) FROM wallet_entries WHERE user_id = ? AND status = 'pending' AND type = 'hold'", [Auth::id()]);
        $withdrawn = (int) DB::value("SELECT COALESCE(SUM(ABS(amount_paise)),0) FROM wallet_entries WHERE user_id = ? AND type = 'payout' AND status IN ('pending','paid')", [Auth::id()]);
        Response::html(View::render('customer/wallet', [
            'title'     => 'Referral wallet',
            'rows'      => $rows,
            'available' => $available,
            'held'      => $held,
            'withdrawn' => $withdrawn,
            'code'      => Auth::user()['referral_code'],
        ], 'layouts/app'));
    }

    /**
     * POST /wallet/withdraw — initiate a Cashfree payout to UPI or bank.
     * Minimum withdrawal: ₹100. Only 'available' balance is eligible.
     */
    public function walletWithdraw(): never
    {
        $userId    = Auth::id();
        $user      = Auth::user();
        $mode      = in_array($_POST['mode'] ?? '', ['upi', 'banktransfer'], true) ? $_POST['mode'] : 'upi';
        $upiOrAcc  = trim((string) ($_POST['upi_or_account'] ?? ''));
        $ifsc      = trim((string) ($_POST['ifsc'] ?? ''));
        $amountRs  = (int) ($_POST['amount'] ?? 0);
        $amountPaise = $amountRs * 100;

        if ($upiOrAcc === '') {
            flash('error', 'Enter your UPI ID or bank account number.');
            Response::redirect('/wallet');
        }
        if ($amountPaise < 10000) {    // minimum ₹100
            flash('error', 'Minimum withdrawal is ₹100.');
            Response::redirect('/wallet');
        }
        if ($mode === 'banktransfer' && $ifsc === '') {
            flash('error', 'IFSC code is required for bank transfer.');
            Response::redirect('/wallet');
        }

        // Check available balance in a transaction to prevent double-spend
        try {
            DB::transaction(function ($pdo) use ($userId, $amountPaise, $mode, $upiOrAcc, $ifsc, $user) {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_paise),0) AS bal FROM wallet_entries WHERE user_id = ? AND status = 'available' FOR UPDATE");
                $stmt->execute([$userId]);
                $available = (int) ($stmt->fetch()['bal'] ?? 0);

                if ($available < $amountPaise) {
                    throw new \RuntimeException('Insufficient available balance. Available: ' . money_inr($available));
                }

                $entryId = DB::insert('wallet_entries', [
                    'user_id'        => $userId,
                    'type'           => 'payout',
                    'amount_paise'   => -$amountPaise,
                    'status'         => 'pending',
                    'note'           => 'Withdrawal via ' . $mode . ' to ' . $upiOrAcc,
                    'created_at'     => now(),
                ]);

                $consumed = [];
                $remaining = $amountPaise;
                $avRows = DB::fetchAll("SELECT id, amount_paise FROM wallet_entries WHERE user_id = ? AND status = 'available' ORDER BY id ASC", [$userId]);
                foreach ($avRows as $row) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $consumed[] = (int) $row['id'];
                    DB::update('wallet_entries', ['status' => 'paid'], 'id = ?', [(int) $row['id']]);
                    $remaining -= (int) $row['amount_paise'];
                }

                try {
                    $cf = new CashfreeService();
                    $payout = $cf->initiatePayout(
                        $entryId,
                        $amountPaise,
                        $mode,
                        $upiOrAcc,
                        $ifsc,
                        $user['name'] ?? 'CVTara User',
                        $user['email'],
                        $user['phone'] ?? ''
                    );
                    DB::update('wallet_entries', [
                        'status' => 'paid',
                        'note'   => 'Cashfree ' . $payout['status'] . ' · transfer ' . $payout['transfer_id'],
                    ], 'id = ?', [$entryId]);
                } catch (\Throwable $e) {
                    Logger::error('Cashfree payout API error', ['error' => $e->getMessage(), 'entry' => $entryId]);
                    foreach ($consumed as $cid) {
                        DB::update('wallet_entries', ['status' => 'available'], 'id = ?', [$cid]);
                    }
                    DB::update('wallet_entries', [
                        'status' => 'cancelled',
                        'note'   => 'Payout failed: ' . $e->getMessage(),
                    ], 'id = ?', [$entryId]);
                    throw new \RuntimeException('Withdrawal could not be sent. ' . $e->getMessage());
                }
            });

            flash('success', 'Withdrawal of ' . money_inr($amountPaise) . ' initiated. Cashfree transfers arrive within 1–2 business days.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        Response::redirect('/wallet');
    }

    public function account(): never
    {
        Response::html(View::render('customer/account', ['title' => 'Account'], 'layouts/app'));
    }

    public function accountSave(): never
    {
        DB::update('users', [
            'name' => trim((string) ($_POST['name'] ?? Auth::user()['name'])),
            'phone' => trim((string) ($_POST['phone'] ?? '')) ?: null,
            'profession' => trim((string) ($_POST['profession'] ?? '')) ?: null,
            'experience_tier' => in_array($_POST['experience_tier'] ?? '', ['fresher', 'professional', 'power'], true) ? $_POST['experience_tier'] : Auth::user()['experience_tier'],
            'marketing_consent' => !empty($_POST['marketing']) ? 1 : 0,
            'updated_at' => now(),
        ], 'id = ?', [Auth::id()]);
        flash('success', 'Account updated.');
        Response::redirect('/account');
    }

    public function feedbackSave(): never
    {
        $text = trim((string) ($_POST['feedback'] ?? ''));
        if (strlen($text) < 20 || empty($_POST['consent'])) {
            flash('error', 'Write at least 20 characters and confirm it may be published after review.');
            Response::redirect('/account');
        }
        $user = Auth::user();
        FeedbackBoard::add((string) $user['name'], trim((string) ($_POST['city'] ?? '')), (string) ($user['profession'] ?? ''), (int) ($_POST['rating'] ?? 5), $text, 'workspace');
        flash('success', 'Feedback sent to the owner. It appears on the homepage only after approval.');
        Response::redirect('/account');
    }
}
