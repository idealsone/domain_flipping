<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\Auth;
use App\Support\Env;
use App\Support\Settings;
use App\Support\View;
use App\Support\Response;

final class MaintenanceGate
{
    public function handle(): bool
    {
        $cfg = Settings::cached('maintenance', ['enabled' => false, 'title' => 'We will be right back', 'message' => 'CVTara is being updated. Please try again shortly.', 'whitelist' => []]);
        if (empty($cfg['enabled'])) {
            return true;
        }
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        foreach (['/login', '/logout', '/install.php', '/webhooks/', '/unsubscribe', '/api/v1/payments/'] as $open) {
            if (str_starts_with($path, $open)) {
                return true;
            }
        }
        if (Auth::admin()) {
            return true;
        }
        $whitelist = array_filter(array_map('trim', array_merge(
            (array) ($cfg['whitelist'] ?? []),
            explode(',', (string) Env::get('MAINTENANCE_WHITELIST', ''))
        )));
        if (in_array(client_ip(), $whitelist, true)) {
            return true;
        }
        Response::html(View::render('pages/maintenance', [
            'title' => $cfg['title'] ?? 'We will be right back',
            'message' => $cfg['message'] ?? 'CVTara is being updated.',
        ], 'layouts/auth'), 503);
    }
}
