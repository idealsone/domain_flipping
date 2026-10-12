<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\Auth;
use App\Support\Response;

final class EnsureAdmin
{
    public function handle(): bool
    {
        if (!Auth::admin()) {
            Response::redirect('/login');
        }
        return true;
    }
}
