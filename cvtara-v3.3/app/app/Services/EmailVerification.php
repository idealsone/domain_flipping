<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** Confirms a new customer's email address with a 6-digit code. Only enforced once the owner has switched email delivery on. */
class EmailVerification
{
    public static function enabled(): bool
    {
        return (bool) (Settings::get('integrations')['email'] ?? false);
    }

    public static function required(User $u): bool
    {
        return self::enabled() && ! $u->is_admin && ! $u->email_verified_at;
    }

    /** Stops actions that need a confirmed address (buying, ATS checks, clean downloads) with a clear message. */
    public static function guard(User $u): void
    {
        if (self::required($u)) {
            throw new HttpResponseException(response()->json(['ok' => false, 'error' => 'Please confirm your email address first. We sent a 6-digit code to '.$u->email.'. Open '.rtrim((string) config('app.url'), '/').'/verify-email to enter it or get a new one.', 'verify' => '/verify-email'], 403));
        }
    }

    /** @return string|null error message */
    public static function send(User $u): ?string
    {
        $key = 'email-verify-send:'.$u->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return 'Please wait a few minutes before asking for another code.';
        }
        RateLimiter::hit($key, 900);
        $code = (string) random_int(100000, 999999);
        Cache::put('email-verify:'.$u->id, ['hash' => Hash::make($code), 'until' => time() + 900, 'tries' => 0], 900);
        MailAutomation::queue($u, 'email_verification', (string) Str::uuid(), ['code' => $code], true);

        return null;
    }

    /** @return string|null error message */
    public static function check(User $u, string $code): ?string
    {
        $c = Cache::get('email-verify:'.$u->id);
        if (! $c || $c['until'] < time()) {
            return 'That code has expired. Ask for a new one.';
        }
        if (($c['tries'] ?? 0) >= 5) {
            Cache::forget('email-verify:'.$u->id);

            return 'Too many wrong tries. Ask for a new code.';
        }
        if (! preg_match('/^\d{6}$/', $code) || ! Hash::check($code, $c['hash'])) {
            $c['tries'] = ($c['tries'] ?? 0) + 1;
            Cache::put('email-verify:'.$u->id, $c, max(60, $c['until'] - time()));

            return 'That code is not right. Check the email and try again.';
        }
        Cache::forget('email-verify:'.$u->id);
        $u->forceFill(['email_verified_at' => now()])->save();
        Settings::audit($u->id, 'email_verified');

        return null;
    }
}
