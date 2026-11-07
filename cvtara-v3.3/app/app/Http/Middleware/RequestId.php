<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a short random reference (3.36.0). It goes back in the X-Request-Id header, into Error Log
 * entries and into error messages, so a customer's "reference" can be matched to the exact server error.
 * An id sent by the browser is never trusted.
 */
class RequestId
{
    private static ?string $id = null;

    public static function current(): string
    {
        return self::$id ??= strtoupper(Str::random(12));
    }

    public function handle(Request $request, Closure $next): Response
    {
        self::$id = strtoupper(Str::random(12));
        $response = $next($request);
        $response->headers->set('X-Request-Id', self::$id);

        return $response;
    }
}
