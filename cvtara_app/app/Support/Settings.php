<?php
declare(strict_types=1);

namespace App\Support;

final class Settings
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = DB::fetch('SELECT v FROM settings WHERE k = ?', [$key]);
        if (!$row) {
            return $default;
        }
        $decoded = json_decode((string) $row['v'], true);
        return $decoded === null && $row['v'] !== 'null' ? $row['v'] : $decoded;
    }

    public static function put(string $key, mixed $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        $existing = DB::fetch('SELECT k FROM settings WHERE k = ?', [$key]);
        if ($existing) {
            DB::update('settings', ['v' => $json], 'k = ?', [$key]);
        } else {
            DB::insert('settings', ['k' => $key, 'v' => $json]);
        }
        Cache::forget('setting:' . $key);
    }

    public static function cached(string $key, mixed $default = null): mixed
    {
        $cacheKey = 'setting:' . $key;
        $hit = Cache::get($cacheKey, '__miss__');
        if ($hit !== '__miss__') {
            return $hit;
        }
        $value = self::get($key, $default);
        Cache::put($cacheKey, $value, 120);
        return $value;
    }
}
