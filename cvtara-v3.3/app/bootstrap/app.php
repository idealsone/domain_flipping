<?php

use App\Http\Middleware\OwnerMaintenance;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $m): void {
        $m->validateCsrfTokens(except: ['webhooks/razorpay', 'webhooks/phonepe', 'email/unsubscribe/*']);
        if ($proxies = env('TRUSTED_PROXIES')) {
            $m->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        $m->append(SecurityHeaders::class);
        $m->redirectGuestsTo('/login');
        $m->web(prepend: [\App\Http\Middleware\ServeCachedPage::class], append: [OwnerMaintenance::class, \Illuminate\Routing\Middleware\ThrottleRequests::class.':pages']);
    })
    ->withExceptions(function (Exceptions $e): void {
        $e->report(function (\Throwable $x) {
            if ($x instanceof ValidationException || $x instanceof \Illuminate\Auth\AuthenticationException || ($x instanceof HttpExceptionInterface && $x->getStatusCode() < 500)) {
                return;
            }
            \App\Services\ErrorLog::exception($x, auth()->id(), request()->path());
        });
        $e->render(function (ValidationException $x, Request $r) {
            if ($r->is('api/*')) {
                return response()->json(['ok' => false, 'error' => collect($x->errors())->flatten()->first(), 'errors' => $x->errors()], 422);
            }
        });
        $e->render(function (HttpExceptionInterface $x, Request $r) {
            if ($r->is('api/*')) {
                return response()->json(['ok' => false, 'error' => match ($x->getStatusCode()) {
                    401 => 'Sign in to continue.',403 => 'You do not have access to this action.',404 => 'Record or page not found.',422 => $x->getMessage() ?: 'Check the request and retry.',409 => 'This record changed. Reload before saving.',413 => 'File or document is too large.',503 => $x->getMessage() ?: 'The service is busy. Try again in a minute.',429 => 'Too many requests. Please wait.',default => 'Check the request and retry.'
                }, ...($x->getStatusCode() === 401 ? ['signIn' => '/login'] : [])], $x->getStatusCode());
            }
        });
        // A broken or missing page never shows a raw error: visitors are sent to the home page.
        // Any other failure in the API still answers in the shape the pages expect, so a screen never shows a blank
        // "Unable to complete this action". The owner sees the reason; customers see a plain retry message.
        $e->render(function (\Throwable $x, Request $r) {
            if (! $r->is('api/*') || $x instanceof ValidationException || $x instanceof HttpExceptionInterface || $x instanceof \Illuminate\Auth\AuthenticationException) {
                return null;
            }
            $owner = (bool) ($r->user()?->is_admin);

            return response()->json(['ok' => false, 'error' => $owner
                ? 'Server error: '.mb_substr(class_basename($x).': '.$x->getMessage(), 0, 300).' ('.basename($x->getFile()).':'.$x->getLine().'). It is also in the Error Log.'
                : 'Something went wrong on our side. Please try again in a minute.'], 500);
        });
        $e->render(function (\Throwable $x, Request $r) {
            if ($r->is('api/*') || ! $r->isMethod('GET') || $x instanceof ValidationException || $x instanceof \Illuminate\Auth\AuthenticationException) {
                return null;
            }
            $page = str_contains((string) $r->header('Accept', ''), 'text/html') && ! preg_match('#\.(js|mjs|css|map|png|jpe?g|webp|gif|svg|ico|woff2?|txt|xml|json|pdf|zip)$#i', $r->path());
            if (! $page) {
                return null;
            }
            $code = $x instanceof HttpExceptionInterface ? $x->getStatusCode() : 500;
            if ($code === 503 || $code === 429) {
                return null;
            }
            if ($r->path() === '/') {
                return $code >= 500 ? response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CVTara</title><body style="font:16px system-ui;display:grid;place-items:center;min-height:100vh;margin:0;background:#f8fafc;color:#0f172a;text-align:center"><div><h1>We will be right back</h1><p>CVTara is being updated. Please refresh in a minute.</p></div>', 503, ['Retry-After' => '60']) : null;
            }

            return response('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta http-equiv="refresh" content="3;url=/"><title>Page not available | CVTara</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f8fafc;color:#0f172a;font:16px/1.5 system-ui,sans-serif;text-align:center}@media(prefers-color-scheme:dark){body{background:#0f1117;color:#f1f5f9}}.b{display:inline-block;margin-top:14px;padding:12px 22px;border-radius:12px;background:#1557d2;color:#fff;font-weight:700;text-decoration:none}p{color:#64748b;margin:6px 0}</style></head><body><main><h1>This page is not available</h1><p>The link may be old or broken. Taking you to the home page in a few seconds.</p><a class="b" href="/">Go to the home page</a></main></body></html>', $code >= 500 ? 500 : 404, ['X-Robots-Tag' => 'noindex, nofollow']);
        });
    })->create();
