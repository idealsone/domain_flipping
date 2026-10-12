<?php
declare(strict_types=1);

namespace App\Support;

final class Crypto
{
    public static function key(): string
    {
        $raw = (string) Env::get('APP_KEY', '');
        $raw = str_starts_with($raw, 'base64:') ? base64_decode(substr($raw, 7)) : $raw;
        return hash('sha256', $raw !== '' ? $raw : 'cvtara-fallback-key', true);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $cipher);
    }

    public static function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }
        $iv = substr($raw, 0, 16);
        $plain = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }

    public static function token(int $bytes = 24): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
