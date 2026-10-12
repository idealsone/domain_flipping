<?php
declare(strict_types=1);

namespace App\Support;

final class Logger
{
    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/storage/logs/app.log';
    }

    public static function write(string $level, string $message, array $context = []): void
    {
        $allowed = ['debug', 'info', 'warning', 'error'];
        $min = strtolower((string) Env::get('LOG_LEVEL', 'error'));
        if (array_search(strtolower($level), $allowed, true) < array_search($min, $allowed, true)) {
            return;
        }
        $line = sprintf(
            "[%s] %s: %s %s\n",
            date('c'),
            strtoupper($level),
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );
        $file = self::path();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0770, true);
        }
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function tail(int $lines = 200, string $query = '', string $level = ''): array
    {
        $file = self::path();
        if (!is_file($file)) {
            return [];
        }
        $rows = array_reverse(file($file, FILE_IGNORE_NEW_LINES) ?: []);
        $out = [];
        foreach ($rows as $row) {
            if ($level !== '' && !str_contains(strtoupper($row), strtoupper($level) . ':')) {
                continue;
            }
            if ($query !== '' && !str_contains(strtolower($row), strtolower($query))) {
                continue;
            }
            $out[] = $row;
            if (count($out) >= $lines) {
                break;
            }
        }
        return $out;
    }

    public static function clear(): void
    {
        $file = self::path();
        if (is_file($file)) {
            file_put_contents($file, '', LOCK_EX);
        }
        self::info('Error log cleared by owner');
    }
}
