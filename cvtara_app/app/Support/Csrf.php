<?php
declare(strict_types=1);

namespace App\Support;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = Crypto::token();
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    public static function check(?string $token): bool
    {
        return is_string($token) && isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
    }
}
