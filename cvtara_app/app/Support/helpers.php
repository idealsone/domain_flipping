<?php
declare(strict_types=1);

use App\Support\Csrf;
use App\Support\Env;
use App\Support\Response;

function e(mixed $value): string
{
    if (is_array($value) || is_object($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function url(string $path = '/'): string
{
    $base = rtrim((string) Env::get('APP_URL', ''), '/');
    return ($base !== '' ? $base : '') . '/' . ltrim($path, '/');
}

function csrf_field(): string
{
    return Csrf::field();
}

function old(string $key, string $default = ''): string
{
    return e((string) ($_SESSION['_old'][$key] ?? $default));
}

function money_inr(int $paise): string
{
    return '₹' . number_format($paise / 100, 0);
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function redirect(string $to): never
{
    Response::redirect($to);
}

function flash(string $type, string $message): void
{
    Response::flash($type, $message);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function is_premium(?array $user = null): bool
{
    $user ??= \App\Support\Auth::user();
    if (!$user || empty($user['is_premium'])) {
        return false;
    }
    if (!empty($user['plan_expires_at']) && strtotime((string) $user['plan_expires_at']) < time()) {
        return false;
    }
    return true;
}

function slugify(string $value): string
{
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $value) ?? '', '-'));
    return $slug !== '' ? $slug : 'item';
}
