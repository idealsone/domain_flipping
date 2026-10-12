<?php
declare(strict_types=1);

namespace App\Support;

use PDO;

final class Installer
{
    public static function migrate(PDO $pdo): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        $pdo->exec($sql);
    }

    public static function seed(PDO $pdo, array $owner, string $url): void
    {
        $now = date('Y-m-d H:i:s');
        $code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        $stmt = $pdo->prepare('INSERT INTO users (name,email,password,is_admin,experience_tier,is_premium,marketing_consent,referral_code,created_at,updated_at) VALUES (?,?,?,1,"professional",1,0,?,?,?)');
        $stmt->execute([$owner['name'], $owner['email'], password_hash($owner['password'], PASSWORD_DEFAULT), $code, $now, $now]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO tailor_credits (user_id,total_allowed,used_count,remaining_balance) VALUES (?,20,0,20)')->execute([$id]);

        $defaults = [
            'maintenance' => ['enabled' => false, 'title' => 'We will be right back', 'message' => 'CVTara is being updated. The owner console stays available.', 'whitelist' => []],
            'brand' => ['about' => 'Built to help job seekers prepare clear resumes and more focused applications.'],
            'growth' => ['enabled' => true, 'rateBps' => 2000, 'tierRateBps' => 3000, 'tierThreshold' => 10, 'minimumPaise' => 100000, 'holdDays' => 14],
            'legal' => [
                'supportEmail' => $owner['email'],
                'address' => 'Add your registered address in the owner console.',
                'footerText' => 'CVTara is a career-document workspace. It does not guarantee interviews, rankings or job offers.',
            ],
            'seo' => ['sitemapEnabled' => true, 'searchIndexing' => false, 'siteUrl' => $url],
            'payments' => [
                'env' => 'sandbox',
                'appId' => '',
                'secretKey' => '',
                'webhookSecret' => '',
                'payoutAppId' => '',
                'payoutSecret' => '',
            ],
            'writing' => [
                'openaiKey' => '',
                'anthropicKey' => '',
                'xaiKey' => '',
            ],
        ];
        $ins = $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?)');
        foreach ($defaults as $k => $v) {
            $ins->execute([$k, json_encode($v, JSON_UNESCAPED_SLASHES)]);
        }
    }
}
