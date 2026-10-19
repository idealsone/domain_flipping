<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReferralRewards
{
    public static function enabled(): bool
    {
        return (bool) Referrals::config()['enabled'];
    }

    /** Friends whose purchase has passed the refund window without a refund (see Wallet). */
    public static function count(int $id): int
    {
        return Wallet::qualifiedCount($id);
    }

    public static function status(User $u): array
    {
        return ['enabled' => self::enabled(), 'rules' => Referrals::config(), 'count' => self::count($u->id), 'claimed' => DB::table('referral_rewards')->where('owner_id', $u->id)->pluck('milestone'), 'exports' => (int) DB::table('referral_rewards')->where('owner_id', $u->id)->sum('exports_remaining')];
    }

    /** One reward: when 5 referred friends qualify, the customer gets one free Job Hunt month (plus one clean resume download). */
    public static function claim(User $u, int $milestone): void
    {
        abort_unless($milestone === 5 && self::enabled(), 422);
        DB::transaction(function () use ($u) {
            User::whereKey($u->id)->lockForUpdate()->first();
            abort_unless($u->email_verified_at || ! EmailVerification::enabled(), 422, 'Confirm your email address first.');
            abort_unless(self::count($u->id) >= (int) Referrals::config()['downloadThreshold'], 422, 'Not enough qualified friends yet. A friend counts 7 days after their purchase, if it was not refunded.');
            abort_if(DB::table('referral_rewards')->where('owner_id', $u->id)->where('milestone', 5)->exists(), 409, 'You have already claimed this reward.');
            abort_if(Settings::access($u->id)['paid'], 422, 'Claim your free month after your current plan ends. Your reward is kept for you.');
            $key = 'job-hunt';
            $plan = Settings::plans()[$key];
            DB::table('purchases')->insert(['id' => (string) Str::uuid(), 'owner_id' => $u->id, 'plan' => $key, 'amount_paise' => 0, 'provider' => 'referral_reward', 'status' => 'paid', 'is_live' => false, 'limits_json' => json_encode($plan), 'access_days' => (int) Referrals::config()['premiumDays'], 'purchased_at' => now(), 'access_until' => now()->addDays((int) Referrals::config()['premiumDays']), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('referral_rewards')->insert(['owner_id' => $u->id, 'milestone' => 5, 'exports_remaining' => 1, 'created_at' => now(), 'updated_at' => now()]);
            Settings::audit($u->id, 'referral_reward_claimed', '5');
        });
    }
}
