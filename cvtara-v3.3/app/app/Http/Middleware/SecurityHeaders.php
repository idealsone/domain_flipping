<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $r, Closure $next)
    {
        $res = $next($r);
        $res->headers->set('X-Content-Type-Options', 'nosniff');
        $res->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $res->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $res->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $res->headers->set('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; script-src 'self' 'unsafe-inline' blob: https://checkout.razorpay.com https://*.razorpay.com https://sdk.cashfree.com https://*.cashfree.com https://*.onesignal.com https://www.googletagmanager.com https://www.google-analytics.com; worker-src 'self' blob: https://*.onesignal.com");
        if ($res->getStatusCode() >= 400 || in_array($r->path(), config('app.owner_paths'), true) || preg_match('#^(admin|console|api|workspace|checkout|login|register|forgot-password|reset-password|preview|install|setup|export|r/|client-error|webhooks)#', $r->path())) {
            $res->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }
        $res->headers->remove('X-Powered-By');
        $res->headers->remove('Server');
        if ($r->isSecure()) {
            $res->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }if ($r->is('api/*')) {
            $res->headers->set('Cache-Control', 'private, no-store');
        }

return $res;
    }
}
