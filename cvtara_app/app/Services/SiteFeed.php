<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\DB;

final class SiteFeed
{
    public static function payload(): array
    {
        $plans = self::planLabels();
        $reviews = ['published' => []];
        $activity = [];
        $counts = ['members' => 0, 'scans' => 0, 'publishedReviews' => 0];
        $connected = false;
        try {
            DB::pdo();
            FeedbackBoard::ensureTable();
            $reviews['published'] = FeedbackBoard::published();
            $activity = self::activity();
            $counts['members'] = (int) DB::value('SELECT COUNT(*) FROM users');
            $counts['scans'] = (int) DB::value('SELECT COUNT(*) FROM ats_scans');
            $counts['publishedReviews'] = count($reviews['published']);
            $connected = true;
        } catch (\Throwable) {
            $connected = false;
        }
        if ($reviews['published'] === []) {
            $reviews = self::fileReviews();
            $counts['publishedReviews'] = count($reviews['published']);
        }
        return [
            'connected' => $connected,
            'plans' => $plans,
            'reviews' => $reviews,
            'activity' => $activity,
            'counts' => $counts,
        ];
    }

    public static function planLabels(): array
    {
        $plans = ContentCatalog::plans();
        $fmt = static fn (int $paise): string => '₹' . number_format($paise / 100, 0, '.', '');
        return [
            'fresher' => [
                $fmt((int) $plans['d30']['fresherPrice']),
                $fmt((int) $plans['d60']['fresherPrice']),
                $fmt((int) $plans['d90']['fresherPrice']),
            ],
            'professional' => [
                $fmt((int) $plans['d30']['price']),
                $fmt((int) $plans['d60']['price']),
                $fmt((int) $plans['d90']['price']),
            ],
        ];
    }

    public static function fileReviews(): array
    {
        $file = dirname(__DIR__, 3) . '/public_html/reviews.json';
        if (!is_file($file)) {
            return ['published' => []];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) && isset($data['published']) && is_array($data['published'])
            ? ['published' => $data['published']]
            : ['published' => []];
    }

    private static function activity(): array
    {
        $rows = DB::fetchAll(
            "SELECT e.event, e.created_at, u.name, u.marketing_consent
             FROM events e
             LEFT JOIN users u ON u.id = e.user_id
             WHERE e.event IN ('preview','scan','prepare','purchase_verified','referral','signup')
             ORDER BY e.id DESC
             LIMIT 12"
        );
        $lines = [];
        foreach ($rows as $row) {
            $text = self::line($row);
            if ($text !== '') {
                $lines[] = ['text' => $text, 'at' => (string) $row['created_at']];
            }
        }
        return $lines;
    }

    private static function line(array $row): string
    {
        $who = 'Someone';
        if (!empty($row['marketing_consent']) && !empty($row['name'])) {
            $first = preg_replace('/[^A-Za-z]/', '', (string) strtok((string) $row['name'], ' ')) ?: '';
            if ($first !== '') {
                $who = $first;
            }
        }
        return match ((string) $row['event']) {
            'preview' => $who . ' is previewing a resume layout',
            'scan' => $who . ' compared a resume with a job description',
            'prepare' => $who . ' is preparing an ATS-friendly resume',
            'purchase_verified' => $who . ' activated a CVTara pass',
            'referral' => $who . ' referred a friend',
            'signup' => $who . ' created a workspace',
            default => '',
        };
    }
}
