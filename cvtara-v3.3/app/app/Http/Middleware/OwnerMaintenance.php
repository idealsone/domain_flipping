<?php

namespace App\Http\Middleware;

use App\Services\BusinessControls;
use Closure;
use Illuminate\Http\Request;

class OwnerMaintenance
{
    public function handle(Request $r, Closure $next)
    {
        if ($r->is('admin*', 'api/admin/*', 'login', 'logout', 'webhooks/*', 'up')) {
            return $next($r);
        }
        $m = BusinessControls::get()['maintenance'];
        if ($r->path() !== 'maintenance-preview' && (! $m['enabled'] || ($r->user()?->is_admin && ! $r->user()?->blocked))) {
            return $next($r);
        }
        if ($r->is('api/*')) {
            return response()->json(['ok' => false, 'error' => $m['message']], 503)->header('Retry-After', '3600');
        }
        $html = '<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>CVTara — Maintenance</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#edf3fb;color:#142d49;font:18px/1.6 system-ui}main{max-width:560px;padding:40px}img{width:190px}h1{font-size:42px;line-height:1.15}</style></head><body><main><img src="/assets/brand/cvtara-logo.webp" alt="CVTara"><h1>'.e($m['title']).'</h1><p>'.e($m['message']).'</p></main></body></html>';

        return response($html, 503)->header('Cache-Control', 'no-store')->header('Retry-After', '3600');
    }
}
