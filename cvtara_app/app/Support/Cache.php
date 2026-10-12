<?php
declare(strict_types=1);

namespace App\Support;

final class Cache
{
    public static function dir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        return $dir;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $file = self::dir() . '/' . sha1($key) . '.cache';
        if (!is_file($file)) {
            return $default;
        }
        $payload = json_decode((string) file_get_contents($file), true);
        if (!is_array($payload) || ($payload['expires'] ?? 0) < time()) {
            @unlink($file);
            return $default;
        }
        return $payload['value'] ?? $default;
    }

    public static function put(string $key, mixed $value, int $seconds = 300): void
    {
        file_put_contents(self::dir() . '/' . sha1($key) . '.cache', json_encode([
            'expires' => time() + $seconds,
            'value' => $value,
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public static function forget(string $key): void
    {
        @unlink(self::dir() . '/' . sha1($key) . '.cache');
    }

    public static function flush(): int
    {
        $count = 0;
        foreach (glob(self::dir() . '/*.cache') ?: [] as $file) {
            @unlink($file);
            $count++;
        }
        Logger::info('Application cache cleared', ['files' => $count]);
        return $count;
    }
}
