<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\Csrf;
use App\Support\Response;

final class VerifyCsrf
{
    public function handle(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return true;
        }
        if (!Csrf::check($_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            Response::html('Session expired. Refresh and try again.', 419);
        }
        return true;
    }
}
