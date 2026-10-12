<?php
declare(strict_types=1);

namespace App\Support;

final class Auth
{
    public static function user(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }
        return DB::fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function id(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function check(): bool
    {
        return self::id() !== null;
    }

    public static function admin(): bool
    {
        $user = self::user();
        return $user && (int) $user['is_admin'] === 1;
    }

    public static function premium(?array $user = null): bool
    {
        return is_premium($user ?? self::user());
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['is_admin'] = (int) $user['is_admin'] === 1;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function attempt(string $email, string $password): bool
    {
        $user = DB::fetch('SELECT * FROM users WHERE email = ?', [strtolower(trim($email))]);
        if (!$user || !password_verify($password, $user['password'])) {
            return false;
        }
        self::login($user);
        return true;
    }
}
