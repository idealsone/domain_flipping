<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReferralRewards
{
    public static function enabled(): bool
    {
        return (bool) Referrals::config()['enabled'];
    }

    private static function client()
    {
        abort_unless(self::enabled(), 422, 'Phone verification is not configured.');
        $p = Settings::providers()['verification'];

        return Http::withBasicAuth($p['twilioAccountSid'], $p['twilioAuthToken'])->asForm()->connectTimeout(5)->timeout(15)->baseUrl('https://verify.twilio.com/v2/Services/'.$p['twilioVerifyServiceSid']);
    }

    private static function digest(string $v): string
    {
        return hash_hmac('sha256', $v, config('app.key'));
    }

    public static function send(Request $r): void
    {
        abort(404);
        $r->validate(['phone' => 'required|regex:/^[6-9][0-9]{9}$/']);
        abort_if(DB::table('referral_identities')->where('owner_id', $r->user()->id)->exists(), 422, 'Phone already verified; contact support for changes.');
        $phone = '+91'.$r->phone;
        abort_if(DB::table('referral_identities')->where('phone_hash', self::digest($phone))->exists(), 422, 'This phone cannot be used for another referral account.');
        foreach (['otp:user:'.$r->user()->id => [3, 86400], 'otp:ip:'.self::digest($r->ip()) => [10, 3600], 'otp:global:'.today()->toDateString() => [100, 86400]] as $key => [$limit,$seconds]) {
            abort_if(RateLimiter::tooManyAttempts($key, $limit), 429);
            RateLimiter::hit($key, $seconds);
        }
        $res = self::client()->post('/Verifications', ['To' => $phone, 'Channel' => 'sms']);
        if (! $res->successful() || $res->json('status') !== 'pending') {
            throw ValidationException::withMessages(['phone' => 'Verification could not be sent. Check the number or contact support.']);
        }$r->session()->put('referral_phone', ['phone' => $phone, 'user' => $r->user()->id, 'expires' => now()->addMinutes(10)->timestamp]);
    }

    public static function check(Request $r): void
    {
        abort(404);
        $r->validate(['code' => 'required|digits_between:4,10']);
        $p = $r->session()->get('referral_phone');
        abort_unless($p && $p['user'] === $r->user()->id && $p['expires'] >= now()->timestamp, 422);
        $key = 'phone-check:'.$r->user()->id;
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429);
        RateLimiter::hit($key, 600);
        $res = self::client()->post('/VerificationCheck', ['To' => $p['phone'], 'Code' => $r->code]);
        if (! $res->successful() || $res->json('status') !== 'approved') {
            throw ValidationException::withMessages(['code' => 'That verification code is not valid.']);
        }
        $device = $r->cookie('cvtara-device');
        $device = $device && preg_match('/^[a-f0-9]{64}$/', $device) ? self::digest($device) : null;
        $ip = self::digest($r->ip());
        DB::transaction(function () use ($r, $p, $device, $ip) {
            User::whereKey($r->user()->id)->lockForUpdate()->first();
            abort_if(DB::table('referral_identities')->where('owner_id', $r->user()->id)->orWhere('phone_hash', self::digest($p['phone']))->exists(), 422);
            $held = $device && DB::table('referral_identities')->where('device_hash', $device)->exists();
            DB::table('referral_identities')->insert(['owner_id' => $r->user()->id, 'phone_hash' => self::digest($p['phone']), 'phone_masked' => '+91 ••••••'.substr($p['phone'], -4), 'device_hash' => $device, 'ip_hash' => $ip, 'status' => $held ? 'held' : 'verified', 'review_note' => $held ? 'A device is shared with another verified account. Owner review required.' : null, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        });
        $r->session()->forget('referral_phone');
        Settings::audit($r->user()->id, 'referral_phone_verified');
    }

    public static function count(int $id): int
    {
        return User::where('referred_by', $id)->where('id', '!=', $id)
            ->where('blocked', false)->whereNotNull('email_verified_at')
            ->whereIn('id', DB::table('purchases')->where('status', 'paid')->where('is_live', true)
                ->whereIn('provider', ['razorpay', 'cashfree'])->where('amount_paise', '>=', Referrals::config()['minQualifyingPaise'])
                ->where('refunded_paise', 0)->whereNotNull('payment_reference')
                ->where('plan', '!=', 'free')->select('owner_id'))
            ->count();
    }

    public static function status(User $u): array
    {
        return ['enabled' => self::enabled(), 'qualification' => 'verified_signup_and_paid_purchase', 'rules' => Referrals::config(), 'count' => self::count($u->id), 'identity' => DB::table('referral_identities')->where('owner_id', $u->id)->first(['phone_masked', 'status', 'review_note']), 'claimed' => DB::table('referral_rewards')->where('owner_id', $u->id)->pluck('milestone'), 'exports' => (int) DB::table('referral_rewards')->where('owner_id', $u->id)->sum('exports_remaining')];
    }

    /** One reward: when enough referred friends have paid, the customer gets one clean resume download and one month of subscription. */
    public static function claim(User $u, int $milestone): void
    {
        abort_unless($milestone === 5 && self::enabled(), 422);
        DB::transaction(function () use ($u) {
            User::whereKey($u->id)->lockForUpdate()->first();
            abort_unless($u->email_verified_at || ! EmailVerification::enabled(), 422, 'Confirm your email address first.');
            abort_unless(self::count($u->id) >= (int) Referrals::config()['downloadThreshold'], 422, 'Not enough paying friends yet.');
            abort_if(DB::table('referral_rewards')->where('owner_id', $u->id)->where('milestone', 5)->exists(), 409, 'You have already claimed this reward.');
            abort_if(Settings::access($u->id)['paid'], 422, 'Claim your free month after your current plan ends. Your reward is kept for you.');
            $key = DB::table('profiles')->where('owner_id', $u->id)->value('experience_level') === 'fresher' ? 'fresher-standard' : 'pro-standard';
            $plan = Settings::plans()[$key];
            DB::table('purchases')->insert(['id' => (string) Str::uuid(), 'owner_id' => $u->id, 'plan' => $key, 'amount_paise' => 0, 'provider' => 'referral_reward', 'status' => 'paid', 'is_live' => false, 'limits_json' => json_encode($plan), 'access_days' => (int) Referrals::config()['premiumDays'], 'purchased_at' => now(), 'access_until' => now()->addDays((int) Referrals::config()['premiumDays']), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('referral_rewards')->insert(['owner_id' => $u->id, 'milestone' => 5, 'exports_remaining' => 1, 'created_at' => now(), 'updated_at' => now()]);
            Settings::audit($u->id, 'referral_reward_claimed', '5');
        });
    }
}
