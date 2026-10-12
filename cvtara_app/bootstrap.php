<?php
declare(strict_types=1);

use App\Support\DB;
use App\Support\Env;
use App\Support\Logger;
use App\Support\Router;

$root = __DIR__;
spl_autoload_register(function (string $class) use ($root) {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require $root . '/app/Support/helpers.php';
Env::load($root . '/.env');

set_exception_handler(function (Throwable $e) {
    Logger::error($e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    if (Env::bool('APP_DEBUG')) {
        http_response_code(500);
        echo '<pre>' . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
        exit;
    }
    http_response_code(500);
    echo 'Something went wrong. The owner can review the error log in the console.';
    exit;
});

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
session_name('cvtara');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (is_file($root . '/.env')) {
    DB::boot();
}

$router = new Router();
require $root . '/routes/web.php';
return $router;
