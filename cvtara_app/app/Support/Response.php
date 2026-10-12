<?php
declare(strict_types=1);

namespace App\Support;

final class Response
{
    public static function html(string $html, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function redirect(string $to, int $status = 302): never
    {
        http_response_code($status);
        header('Location: ' . $to);
        exit;
    }

    public static function text(string $text, string $type = 'text/plain', int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: ' . $type . '; charset=utf-8');
        echo $text;
        exit;
    }

    public static function back(string $fallback = '/'): never
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? $fallback;
        self::redirect($ref);
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function flashes(): array
    {
        $items = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $items;
    }
}
