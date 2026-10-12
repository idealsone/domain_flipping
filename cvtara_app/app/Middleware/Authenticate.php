<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\Auth;
use App\Support\Response;

final class Authenticate
{
    public function handle(): bool
    {
        if (!Auth::check()) {
            Response::redirect('/login');
        }
        return true;
    }
}
