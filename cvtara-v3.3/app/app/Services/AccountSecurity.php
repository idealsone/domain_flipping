<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountSecurity
{
    public static function send(Request $r): void
    {
        abort_unless(Settings::get('integrations')['email'] ?? false, 422, 'Email delivery must be configured before account verification.');
        $key = 'verification-send:'.$r->user()->id;
        abort_if(RateLimiter::tooManyAttempts($key, 3), 429);
        RateLimiter::hit($key, 900);
        $code = (string) random_int(100000, 999999);
        $r->session()->put('security_challenge', ['hash' => Hash::make($code), 'until' => now()->timestamp + 600, 'attempts' => 0, 'user' => $r->user()->id]);
        DB::table('mail_outbox')->insert(['id' => (string) Str::uuid(), 'dedupe' => (string) Str::uuid(), 'owner_id' => $r->user()->id, 'trigger' => 'security_verification', 'recipient' => $r->user()->email, 'subject' => 'Your CVTara security code', 'body' => "Your CVTara verification code is $code. It expires in 10 minutes. Do not share this code or your password. If you did not request it, review your account security.", 'due_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        Settings::audit($r->user()->id, 'security_code_requested');
    }

    public static function verify(Request $r): void
    {
        $attemptKey = 'verification-attempt:'.$r->user()->id;
        abort_if(RateLimiter::tooManyAttempts($attemptKey, 5), 429);
        RateLimiter::hit($attemptKey, 600);
        $v = $r->validate(['code' => 'required|digits:6', 'password' => 'required|string|max:200']);
        $c = $r->session()->get('security_challenge');
        if (! $c || $c['user'] !== $r->user()->id || $c['until'] < now()->timestamp || $c['attempts'] >= 5) {
            throw ValidationException::withMessages(['code' => 'Request a new code. The previous code expired or has too many attempts.']);
        }
        $c['attempts']++;
        $r->session()->put('security_challenge', $c);
        if (! Hash::check($v['code'], $c['hash']) || ! Hash::check($v['password'], $r->user()->password)) {
            throw ValidationException::withMessages(['code' => 'The code or current password is incorrect.']);
        }
        $r->session()->forget('security_challenge');
        $r->session()->put('sensitive_until', now()->timestamp + 600);
        $r->session()->put('sensitive_user', $r->user()->id);
        $r->user()->forceFill(['email_verified_at' => now()])->save();
        Settings::audit($r->user()->id, 'security_verified');
    }

    public static function requireRecent(Request $r): void
    {
        if (! $r->user()->email_verified_at || $r->session()->get('sensitive_until', 0) < now()->timestamp || $r->session()->get('sensitive_user') !== $r->user()->id) {
            throw ValidationException::withMessages(['verification' => 'Verify your email code and current password first. Sensitive actions unlock for 10 minutes.']);
        }
    }
}
