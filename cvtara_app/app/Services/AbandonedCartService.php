<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\DB;
use App\Support\Mailer;

final class AbandonedCartService
{
    public function process(): int
    {
        $users = DB::fetchAll(
            "SELECT * FROM users WHERE last_ats_at IS NOT NULL AND is_premium = 0 AND abandoned_sent_at IS NULL AND last_ats_at <= DATE_SUB(NOW(), INTERVAL 4 HOUR) AND marketing_consent = 1 LIMIT 40"
        );
        $sent = 0;
        foreach ($users as $user) {
            $code = 'SCAN' . strtoupper(substr(hash('crc32', $user['email']), 0, 6));
            $existing = DB::fetch('SELECT id FROM coupons WHERE code = ?', [$code]);
            if (!$existing) {
                DB::insert('coupons', [
                    'code' => $code,
                    'type' => 'percent',
                    'value' => 15,
                    'expires_at' => date('Y-m-d', strtotime('+7 days')),
                    'enabled' => 1,
                    'plans_json' => json_encode(['d30', 'd60', 'd90']),
                ]);
            }
            $html = Mailer::wrap(
                'Continue your job kit',
                '<p>Hi ' . e($user['name']) . ',</p><p>You ran a compliance scan and left before checkout. If you still want the workspace, use code <strong>' . e($code) . '</strong> for 15% off a job kit. It expires in 7 days.</p><p><a href="' . e(url('/checkout')) . '">Return to checkout</a></p>',
                'Sent because you opted in to product email. <a href="' . e(url('/account')) . '">Manage preferences</a>.'
            );
            if (Mailer::send($user['email'], 'Your CVTara scan is saved', $html, Mailer::transactional())) {
                DB::update('users', ['abandoned_sent_at' => now(), 'updated_at' => now()], 'id = ?', [$user['id']]);
                $sent++;
            }
        }
        return $sent;
    }
}
