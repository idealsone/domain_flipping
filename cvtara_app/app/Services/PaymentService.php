<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\DB;
use App\Support\Env;
use App\Support\Logger;
use App\Support\Mailer;
use App\Support\Settings;

final class PaymentService
{
    public function activate(int $userId, string $plan, int $credits, string $reference, string $provider = 'razorpay', bool $live = false): void
    {
        $catalog = ContentCatalog::plans()[$plan] ?? ContentCatalog::plans()['d30'];
        $until = date('Y-m-d H:i:s', strtotime('+' . $catalog['days'] . ' days'));
        DB::update('users', [
            'is_premium' => 1,
            'plan' => $plan,
            'plan_expires_at' => $until,
            'updated_at' => now(),
        ], 'id = ?', [$userId]);
        if ($credits > 0) {
            (new TailorEngineService())->grant($userId, $credits);
        }
        $user = DB::fetch('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user) {
            DB::query('UPDATE leads SET stop_marketing = 1, campaign_step = 99, status = ? WHERE email = ?', ['customer', $user['email']]);
            $this->creditReferrer($user, $catalog);
            Mailer::send(
                $user['email'],
                'Your CVTara access is active',
                Mailer::wrap('Welcome to your job kit', '<p>Hi ' . e($user['name']) . ',</p><p>Verified access is now active until ' . e($until) . '. Open your workspace to continue the resume, comparison and application tools.</p><p><a href="' . e(url('/workspace')) . '">Open workspace</a></p>'),
                Mailer::transactional()
            );
        }
        DB::insert('events', ['user_id' => $userId, 'event' => 'purchase_verified', 'path' => '/checkout', 'created_at' => now()]);
        Logger::info('Purchase activated', ['user' => $userId, 'plan' => $plan, 'reference' => $reference, 'provider' => $provider, 'live' => $live]);
    }

    private function creditReferrer(array $user, array $catalog): void
    {
        if (empty($user['referred_by'])) {
            return;
        }
        $growth = Settings::cached('growth', ['enabled' => true, 'rateBps' => 2000, 'holdDays' => 14]);
        if (empty($growth['enabled'])) {
            return;
        }
        $amount = (int) round(($catalog['price'] ?? 0) * ((int) $growth['rateBps'] / 10000));
        if ($amount < 1) {
            return;
        }
        DB::insert('wallet_entries', [
            'user_id' => (int) $user['referred_by'],
            'type' => 'hold',
            'amount_paise' => $amount,
            'status' => 'pending',
            'hold_until' => date('Y-m-d H:i:s', strtotime('+' . ((int) ($growth['holdDays'] ?? 14)) . ' days')),
            'note' => 'Referral hold for ' . $user['email'],
            'created_at' => now(),
        ]);
    }

    public function razorpaySignature(string $payload, string $signature): bool
    {
        $secret = (string) Env::get('RAZORPAY_WEBHOOK_SECRET', '');
        if ($secret === '') {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    public function cashfreeSignature(string $payload, string $timestamp, string $signature): bool
    {
        $secret = (string) Config::get('CASHFREE_WEBHOOK_SECRET', '');
        if ($secret === '') {
            return false;
        }
        $signed = hash_hmac('sha256', $timestamp . $payload, $secret, true);
        return hash_equals(base64_encode($signed), $signature);
    }
}
