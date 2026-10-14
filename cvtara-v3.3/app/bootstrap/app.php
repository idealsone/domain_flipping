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
        $m->validateCsrfTokens(except: ['webhooks/razorpay', 'webhooks/cashfree']);
        $m->append(SecurityHeaders::class);
        $m->redirectGuestsTo('/login');
        $m->web(append: [OwnerMaintenance::class]);
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
                    401 => 'Sign in to continue.',403 => 'You do not have access to this action.',404 => 'Record or page not found.',422 => $x->getMessage() ?: 'Check the request and retry.',409 => 'This record changed. Reload before saving.',413 => 'File or document is too large.',429 => 'Too many requests. Please wait.',default => 'Check the request and retry.'
                }, ...($x->getStatusCode() === 401 ? ['signIn' => '/login'] : [])], $x->getStatusCode());
            }
        });
    })->create();
