<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Referrals
{
    public static function config(): array
    {
        return array_merge(['enabled' => true, 'rateBps' => 2000, 'minimumPaise' => 100000, 'payoutHours' => 24, 'holdDays' => 7, 'tierThreshold' => 100, 'tierRateBps' => 3000, 'requirePan' => false, 'downloadThreshold' => 5, 'premiumDays' => 30, 'minQualifyingPaise' => 29900, 'proOnly' => true, 'mode' => 'wallet', 'friendDiscountPaise' => 5000, 'rewardPaise' => 5000, 'qualifyDays' => 7, 'walletDays' => 90, 'monthlyCapPaise' => 100000], Settings::get('referrals', []));
    }

    public static function successfulBuyers(int $id): int
    {
        return DB::table('referral_commissions as c')->join('purchases as p', 'p.id', '=', 'c.purchase_id')->where('c.referrer_id', $id)->where('p.is_live', true)->where('p.status', 'paid')->where('p.refunded_paise', 0)->where(function ($q) {
            $q->whereNull('c.available_at')->orWhere('c.available_at', '<=', now());
        })->distinct()->count('c.buyer_id');
    }

    public static function rate(int $id): int
    {
        $rules = self::config();
        $custom = DB::table('creator_applications')->where('owner_id', $id)->where('status', 'approved')->value('rate_bps');

        return max((int) ($custom ?? $rules['rateBps']), self::successfulBuyers($id) >= $rules['tierThreshold'] ? (int) $rules['tierRateBps'] : 0);
    }

    public static function balance(int $id): array
    {
        $q = DB::table('referral_commissions')->where('referrer_id', $id);
        $earned = (int) (clone $q)->selectRaw('COALESCE(SUM(amount_paise-reversed_paise),0) n')->value('n');
        $pending = (int) (clone $q)->where('available_at', '>', now())->selectRaw('COALESCE(SUM(amount_paise-reversed_paise),0) n')->value('n');
        $paid = (int) DB::table('withdrawals')->where('owner_id', $id)->where('status', 'paid')->sum('amount_paise');
        $reserved = (int) DB::table('withdrawals')->where('owner_id', $id)->whereIn('status', ['requested', 'processing'])->sum('amount_paise');

        return ['earnedPaise' => $earned, 'pendingPaise' => $pending, 'paidPaise' => $paid, 'reservedPaise' => $reserved, 'availablePaise' => $earned - $pending - $paid - $reserved];
    }

    // Called only from verified payment processing, in its database transaction.
    public static function credit(object $p): void
    {
        // Cash commission is the older programme; the "Give ₹50, get ₹50" wallet replaced it. Balances already earned stay withdrawable.
        if (self::config()['mode'] !== 'commission' || ! $p->is_live || $p->status !== 'paid' || ! in_array($p->provider, ['razorpay'], true) || ! self::config()['enabled']) {
            return;
        }$buyer = User::find($p->owner_id);
        if (! $buyer?->referred_by || $buyer->referred_by === $buyer->id) {
            return;
        }$ref = User::whereKey($buyer->referred_by)->lockForUpdate()->first();
        if (! $ref || $ref->blocked) {
            return;
        }if ($p->amount_paise < (int) self::config()['minQualifyingPaise']) {
            return;
        }if (self::config()['proOnly'] && ! (Settings::access($ref->id)['limits']['referral'] ?? false)) {
            Settings::audit($ref->id, 'commission_skipped_referrer_not_eligible', $p->id);

            return;
        }$rate = self::rate($ref->id);
        DB::table('referral_commissions')->insertOrIgnore(['referrer_id' => $ref->id, 'buyer_id' => $buyer->id, 'purchase_id' => $p->id, 'amount_paise' => intdiv($p->amount_paise * $rate, 10000), 'rate_bps' => $rate, 'available_at' => now()->addDays(self::config()['holdDays']), 'created_at' => now(), 'updated_at' => now()]);
    }

    public static function reverse(object $p, int $refunded): void
    {
        $c = DB::table('referral_commissions')->where('purchase_id', $p->id)->first();
        if (! $c) {
            return;
        }User::whereKey($c->referrer_id)->lockForUpdate()->first();
        $reversal = min($c->amount_paise, intdiv($refunded * $c->rate_bps, 10000));
        DB::table('referral_commissions')->where('id', $c->id)->update(['reversed_paise' => max($c->reversed_paise, $reversal), 'updated_at' => now()]);
    }

    public static function restore(object $p): void
    {
        $c = DB::table('referral_commissions')->where('purchase_id', $p->id)->first();
        if ($c) {
            DB::table('referral_commissions')->where('id', $c->id)->update(['reversed_paise' => min($c->amount_paise, intdiv($p->refunded_paise * $c->rate_bps, 10000)), 'updated_at' => now()]);
        }
    }

    public static function request(User $u, array $d): string
    {
        return DB::transaction(function () use ($u, $d) {
            User::whereKey($u->id)->lockForUpdate()->first();
            if (! self::config()['enabled']) {
                throw ValidationException::withMessages(['amount' => 'Referral withdrawals are paused.']);
            }if (! $u->email_verified_at) {
                throw ValidationException::withMessages(['email' => 'Verify your email before withdrawing.']);
            }$profile = DB::table('payout_profiles')->where('owner_id', $u->id)->first();
            if (! $profile || $profile->status !== 'approved' || strtotime($profile->eligible_at) > now()->timestamp) {
                throw ValidationException::withMessages(['profile' => 'Your payout profile must be approved and its 48-hour safety period completed.']);
            }if (DB::table('withdrawals')->where('owner_id', $u->id)->whereIn('status', ['requested', 'processing'])->exists()) {
                throw ValidationException::withMessages(['withdrawal' => 'One withdrawal is already in progress.']);
            }$amount = (int) $d['amountPaise'];
            $destination = json_decode(Crypt::decryptString($profile->details_encrypted), true);
            $d = $destination;
            $b = self::balance($u->id);
            if ($amount < self::config()['minimumPaise'] || $amount > $b['availablePaise']) {
                throw ValidationException::withMessages(['amount' => 'Minimum withdrawal is ₹'.number_format(self::config()['minimumPaise'] / 100).' and must be within your available balance.']);
            }$id = (string) Str::uuid();
            $masked = $profile->destination_masked;
            DB::table('withdrawals')->insert(['id' => $id, 'owner_id' => $u->id, 'amount_paise' => $amount, 'method' => $d['method'], 'destination_encrypted' => Crypt::encryptString(json_encode($d)), 'destination_masked' => $masked, 'status' => 'requested', 'due_at' => now()->addHours(24), 'created_at' => now(), 'updated_at' => now()]);
            Settings::audit($u->id, 'withdrawal_requested', $id);

            return $id;
        });
    }

    public static function transition(User $admin, string $id, string $status, string $reference = '', string $note = ''): void
    {
        DB::transaction(function () use ($admin, $id, $status, $reference, $note) {
            $base = DB::table('withdrawals')->where('id', $id)->first();
            abort_unless($base, 404);
            abort_if($base->owner_id === $admin->id, 403);
            $beneficiary = User::whereKey($base->owner_id)->lockForUpdate()->first();
            abort_if($beneficiary->blocked && $status !== 'rejected', 403, 'This beneficiary account is blocked.');
            $w = DB::table('withdrawals')->where('id', $id)->lockForUpdate()->first();
            $allowed = ['requested' => ['processing', 'rejected'], 'processing' => ['paid', 'rejected']];
            if (! in_array($status, $allowed[$w->status] ?? [])) {
                throw ValidationException::withMessages(['status' => 'This withdrawal has already changed. Refresh before continuing.']);
            }if ($status === 'processing' && ! $w->approved_at) {
                throw ValidationException::withMessages(['review' => 'Complete the identity, destination and fraud review first.']);
            }if ($status === 'paid') {
                if ($reference !== '' && DB::table('withdrawals')->where('reference', $reference)->where('id', '!=', $id)->exists()) {
                    throw ValidationException::withMessages(['reference' => 'That transfer reference is already used for another withdrawal.']);
                }
                if (strlen(trim($reference)) < 6) {
                    throw ValidationException::withMessages(['reference' => 'Enter the real UPI/bank transfer reference.']);
                }if (self::balance($w->owner_id)['availablePaise'] < 0) {
                    throw ValidationException::withMessages(['balance' => 'Refunds reduced this balance. Resolve the reserved withdrawal before paying.']);
                }
            }if ($status === 'rejected' && strlen(trim($note)) < 5) {
                throw ValidationException::withMessages(['note' => 'Explain why the request was rejected.']);
            }DB::table('withdrawals')->where('id', $id)->update(['status' => $status, 'reference' => $status === 'paid' ? trim($reference) : null, 'note' => $note, 'paid_at' => $status === 'paid' ? now() : null, 'updated_at' => now()]);
            Settings::audit($admin->id, 'withdrawal_'.$status, $id);
        });
    }
}
