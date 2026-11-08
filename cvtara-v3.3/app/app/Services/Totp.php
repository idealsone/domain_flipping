<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Authenticator-app codes (RFC 6238: 30-second, 6-digit, SHA-1) for console sign-in (3.37.0). Works with Google
 * Authenticator, Microsoft Authenticator, Authy and others. The secret is stored encrypted; each code works once.
 */
class Totp
{
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function newSecret(): string
    {
        $s = '';
        foreach (str_split(random_bytes(32)) as $b) {
            $s .= self::B32[ord($b) & 31];
        }

        return $s; // 32 base-32 characters = 160 bits
    }

    public static function uri(User $u, string $secret): string
    {
        return 'otpauth://totp/'.rawurlencode('CVTara console:'.$u->email).'?secret='.$secret.'&issuer=CVTara&digits=6&period=30';
    }

    private static function decode(string $b32): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32))) as $c) {
            $bits .= str_pad(decbin(strpos(self::B32, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    public static function at(string $secret, int $step): string
    {
        $h = hash_hmac('sha1', pack('J', $step), self::decode($secret), true);
        $o = ord($h[19]) & 15;
        $n = ((ord($h[$o]) & 127) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);

        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Matching step (one step of clock drift either way) or null. */
    public static function match(string $secret, string $code, ?int $afterStep = null): ?int
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $now = intdiv(time(), 30);
        foreach ([$now, $now - 1, $now + 1] as $step) {
            if (($afterStep === null || $step > $afterStep) && hash_equals(self::at($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function enabled(User $u): bool
    {
        return (bool) $u->totp_enabled_at && ! empty($u->totp_secret);
    }

    public static function secret(User $u): ?string
    {
        try {
            return $u->totp_secret ? Crypt::decryptString($u->totp_secret) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Checks a sign-in code and burns it so it cannot be used twice. */
    public static function verify(User $u, string $code): bool
    {
        $secret = self::secret($u);
        if (! $secret) {
            return false;
        }
        $step = self::match($secret, $code, $u->totp_last_step ? (int) $u->totp_last_step : null);
        if ($step === null) {
            return false;
        }
        // Only one request can burn a given step.
        return DB::table('users')->where('id', $u->id)->where(fn ($q) => $q->whereNull('totp_last_step')->orWhere('totp_last_step', '<', $step))->update(['totp_last_step' => $step]) === 1;
    }

    public static function enable(User $u, string $secret, string $code): bool
    {
        $step = self::match($secret, $code);
        if ($step === null) {
            return false;
        }
        DB::table('users')->where('id', $u->id)->update(['totp_secret' => Crypt::encryptString($secret), 'totp_enabled_at' => now(), 'totp_last_step' => $step]);

        return true;
    }

    public static function disable(User $u): void
    {
        DB::table('users')->where('id', $u->id)->update(['totp_secret' => null, 'totp_enabled_at' => null, 'totp_last_step' => null]);
    }
}
