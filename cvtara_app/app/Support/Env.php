<?php
declare(strict_types=1);

namespace App\Support;

final class Env
{
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $value = trim($value);
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = stripcslashes(substr($value, 1, -1));
            }
            self::$values[trim($key)] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$values[$key] ?? $_ENV[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = strtolower((string) self::get($key, $default ? 'true' : 'false'));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
