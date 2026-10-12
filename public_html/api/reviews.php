<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Robots-Tag: noindex');

try {
    $envFile = dirname(__DIR__, 2) . '/cvtara_app/.env';
    $env = [];
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $env[trim($k)] = trim($v, " \t\r\n\"'");
        }
    }
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? 'localhost', $env['DB_DATABASE'] ?? 'cvtara');
    $pdo  = new PDO($dsn, $env['DB_USERNAME'] ?? 'root', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT, PDO::ATTR_TIMEOUT => 2]);
    $rows = $pdo->query("SELECT name,city,role,rating,body FROM reviews WHERE status='published' ORDER BY created_at DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows ?: []);
} catch (\Throwable) {
    $fallback = dirname(__DIR__) . '/reviews.json';
    echo is_file($fallback) ? file_get_contents($fallback) : '[]';
}
