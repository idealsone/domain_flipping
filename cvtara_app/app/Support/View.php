<?php
declare(strict_types=1);

namespace App\Support;

final class View
{
    public static function render(string $name, array $data = [], ?string $layout = 'layouts/public'): string
    {
        $data['user'] = $data['user'] ?? Auth::user();
        $data['settings'] = $data['settings'] ?? [
            'brand' => Settings::cached('brand', ['about' => 'CVTara helps job seekers prepare clear resumes and focused applications.']),
            'maintenance' => Settings::cached('maintenance', ['enabled' => false]),
            'legal' => Settings::cached('legal', []),
        ];
        $content = self::file($name, $data);
        if ($layout === null) {
            return $content;
        }
        return self::file($layout, array_merge($data, ['slot' => $content]));
    }

    public static function file(string $name, array $data = []): string
    {
        $path = dirname(__DIR__, 2) . '/views/' . $name . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException('View not found: ' . $name);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $path;
        return (string) ob_get_clean();
    }
}
