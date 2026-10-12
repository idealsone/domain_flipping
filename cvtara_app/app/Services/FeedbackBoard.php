<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\DB;

final class FeedbackBoard
{
    public static function queuePath(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        return $dir . '/feedback-queue.json';
    }

    public static function publicPath(): string
    {
        return dirname(__DIR__, 3) . '/public_html/reviews.json';
    }

    public static function all(): array
    {
        if (self::dbOn()) {
            self::hydrateDatabase();
            $rows = DB::fetchAll('SELECT * FROM reviews ORDER BY id ASC');
            return ['items' => array_map([self::class, 'fromRow'], $rows)];
        }
        return self::fromFile();
    }

    public static function published(): array
    {
        $items = [];
        foreach (self::all()['items'] as $item) {
            if (($item['status'] ?? '') === 'published') {
                $items[] = [
                    'name' => $item['name'],
                    'city' => $item['city'],
                    'role' => $item['role'],
                    'rating' => $item['rating'],
                    'text' => $item['text'],
                ];
            }
        }
        return $items;
    }

    public static function add(string $name, string $city, string $role, int $rating, string $text, string $source): void
    {
        $item = [
            'id' => bin2hex(random_bytes(8)),
            'name' => mb_substr($name, 0, 80),
            'city' => mb_substr($city, 0, 60),
            'role' => mb_substr($role, 0, 80),
            'rating' => max(1, min(5, $rating)),
            'text' => mb_substr($text, 0, 600),
            'source' => mb_substr($source, 0, 40),
            'status' => 'pending',
            'created_at' => date('c'),
        ];
        $data = self::fromFile();
        $data['items'][] = $item;
        self::writeFile($data);
        if (self::dbOn()) {
            self::insertRow($item);
        }
    }

    public static function setStatus(string $id, string $status): void
    {
        if (!in_array($status, ['pending', 'published', 'hidden'], true)) {
            return;
        }
        if (self::dbOn()) {
            self::hydrateDatabase();
            DB::query('UPDATE reviews SET status = ? WHERE public_id = ?', [$status, $id]);
        }
        $data = self::fromFile();
        foreach ($data['items'] as &$item) {
            if (($item['id'] ?? '') === $id) {
                $item['status'] = $status;
            }
        }
        unset($item);
        self::writeFile($data);
        self::publishSnapshot();
    }

    public static function publishSnapshot(): void
    {
        file_put_contents(
            self::publicPath(),
            json_encode(['published' => self::published()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    public static function ensureTable(): void
    {
        DB::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS reviews (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(32) NOT NULL UNIQUE,
                name VARCHAR(80) NOT NULL,
                city VARCHAR(60) NULL,
                role VARCHAR(80) NULL,
                rating TINYINT UNSIGNED NOT NULL,
                body VARCHAR(600) NOT NULL,
                source VARCHAR(40) NOT NULL,
                status ENUM("pending","published","hidden") NOT NULL DEFAULT "pending",
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public static function hydrateDatabase(): void
    {
        if ((int) DB::value('SELECT COUNT(*) FROM reviews') > 0) {
            return;
        }
        foreach (self::fromFile()['items'] as $item) {
            self::insertRow($item);
        }
    }

    private static function dbOn(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            if (!class_exists(DB::class, false)) {
                $root = dirname(__DIR__, 2);
                if (!is_file($root . '/.env') || !is_file($root . '/storage/installed.lock')) {
                    $ready = false;
                    return false;
                }
                require_once $root . '/app/Support/Env.php';
                require_once $root . '/app/Support/DB.php';
                Env::load($root . '/.env');
            }
            DB::pdo();
            self::ensureTable();
            $ready = true;
            return true;
        } catch (\Throwable) {
            $ready = false;
            return false;
        }
    }

    private static function fromFile(): array
    {
        $file = self::queuePath();
        if (!is_file($file)) {
            return ['items' => []];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) && isset($data['items']) && is_array($data['items']) ? $data : ['items' => []];
    }

    private static function writeFile(array $data): void
    {
        file_put_contents(self::queuePath(), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    private static function insertRow(array $item): void
    {
        $id = (string) ($item['id'] ?? '');
        if ($id === '' || DB::fetch('SELECT id FROM reviews WHERE public_id = ?', [$id])) {
            return;
        }
        $created = (string) ($item['created_at'] ?? '');
        $stamp = strtotime($created) ?: time();
        DB::insert('reviews', [
            'public_id' => $id,
            'name' => (string) ($item['name'] ?? ''),
            'city' => ($item['city'] ?? '') !== '' ? (string) $item['city'] : null,
            'role' => ($item['role'] ?? '') !== '' ? (string) $item['role'] : null,
            'rating' => max(1, min(5, (int) ($item['rating'] ?? 5))),
            'body' => (string) ($item['text'] ?? ''),
            'source' => (string) ($item['source'] ?? 'public-form'),
            'status' => in_array($item['status'] ?? '', ['pending', 'published', 'hidden'], true) ? $item['status'] : 'pending',
            'created_at' => date('Y-m-d H:i:s', $stamp),
        ]);
    }

    private static function fromRow(array $row): array
    {
        return [
            'id' => (string) $row['public_id'],
            'name' => (string) $row['name'],
            'city' => (string) ($row['city'] ?? ''),
            'role' => (string) ($row['role'] ?? ''),
            'rating' => (int) $row['rating'],
            'text' => (string) $row['body'],
            'source' => (string) $row['source'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
