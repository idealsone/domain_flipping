<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Give ₹50, get ₹50". A referred friend gets ₹50 off their first purchase. The referrer earns ₹50 wallet credit once that purchase
 * has passed the 7-day refund window without a refund. Credit is spent at checkout, is never paid out as cash, expires 90 days after
 * it is earned, and at most ₹1,000 can be earned per calendar month. Five qualified friends also unlock one free Job Hunt month.
 *
 * Earned credit is written to wallet_transactions the first time it is looked at (no scheduler needed). Spending takes the credit
 * that expires first.
 */
class Wallet
{
    public static function rules(): array
    {
        $c = Referrals::config();

        return ['friendDiscountPaise' => (int) $c['friendDiscountPaise'], 'rewardPaise' => (int) $c['rewardPaise'], 'qualifyDays' => (int) $c['qualifyDays'], 'walletDays' => (int) $c['walletDays'], 'monthlyCapPaise' => (int) $c['monthlyCapPaise'], 'milestone' => (int) $c['downloadThreshold'], 'enabled' => (bool) $c['enabled'] && $c['mode'] === 'wallet'];
    }

    public static function enabled(): bool
    {
        return self::rules()['enabled'];
    }

    /** One hashed, random id per browser. Set on the referral link, at sign-up and at sign-in. */
    public static function device(Request $r): string
    {
        $raw = (string) $r->cookie('cvtara_dev');
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $raw)) {
            $raw = Str::random(40);
            Cookie::queue(cookie('cvtara_dev', $raw, 60 * 24 * 400, '/', null, $r->isSecure(), true, false, 'Lax'));
        }

        return hash_hmac('sha256', $raw, (string) config('app.key'));
    }

    /** Gmail ignores dots and anything after "+": one inbox, one person. */
    public static function sameInbox(string $a, string $b): bool
    {
        $n = function (string $e) {
            [$local, $domain] = array_pad(explode('@', strtolower(trim($e)), 2), 2, '');
            $local = explode('+', $local)[0];
            if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
                $local = str_replace('.', '', $local);
                $domain = 'gmail.com';
            }

            return $local.'@'.$domain;
        };

        return $n($a) === $n($b);
    }

    /** Why a referred friend can never count for this referrer, or null when nothing looks wrong. */
    public static function selfReferral(User $ref, User $friend): ?string
    {
        if ($ref->id === $friend->id || self::sameInbox($ref->email, $friend->email)) {
            return 'same email';
        }
        $devices = array_filter([$ref->device_hash, $ref->last_device_hash]);
        if ($devices && ($friend->device_hash && in_array($friend->device_hash, $devices, true) || $friend->last_device_hash && in_array($friend->last_device_hash, $devices, true))) {
            return 'same browser';
        }
        $phones = DB::table('referral_identities')->whereIn('owner_id', [$ref->id, $friend->id])->pluck('phone_hash', 'owner_id');
        if (isset($phones[$ref->id], $phones[$friend->id]) && $phones[$ref->id] === $phones[$friend->id]) {
            return 'same phone';
        }
        $pays = fn ($id) => DB::table('purchases')->where('owner_id', $id)->whereNotNull('payment_reference')->pluck('payment_reference')->all();
        if (array_intersect($pays($ref->id), $pays($friend->id))) {
            return 'same payment';
        }

        return null;
    }

    /** The purchase that makes this friend count: real money, live, not refunded, and the oldest one. */
    private static function qualifyingPurchase(int $friendId): ?object
    {
        $min = (int) Referrals::config()['minQualifyingPaise'];

        return DB::table('purchases')->where('owner_id', $friendId)->where('status', 'paid')->where('is_live', true)->whereIn('provider', ['razorpay', 'cashfree'])
            ->where('refunded_paise', 0)->whereNotNull('payment_reference')->whereNotNull('purchased_at')->where('plan', '!=', 'free')
            ->whereRaw('amount_paise + referral_discount_paise >= ?', [$min])
            ->whereNotIn('id', DB::table('refund_requests')->whereIn('status', ['requested', 'approved', 'processed'])->select('purchase_id'))
            ->orderBy('purchased_at')->first();
    }

    /** Every referred friend with where they stand: pending (inside the refund window), qualified, or rejected. */
    public static function friends(int $uid): array
    {
        $ref = User::find($uid);
        if (! $ref) {
            return [];
        }
        $days = self::rules()['qualifyDays'];
        $out = [];
        foreach (User::where('referred_by', $uid)->orderBy('created_at')->limit(500)->get() as $f) {
            $why = $f->blocked ? 'account under review' : self::selfReferral($ref, $f);
            $p = $why || (! $f->email_verified_at && EmailVerification::enabled()) ? null : self::qualifyingPurchase($f->id);
            $unlock = $p ? Carbon::parse($p->purchased_at)->addDays($days) : null;
            $out[] = ['id' => $f->id, 'label' => 'Friend '.substr(hash_hmac('sha256', (string) $f->id, (string) config('app.key')), 0, 6), 'joinedAt' => $f->created_at?->toIso8601String(), 'status' => $why ? 'rejected' : (! $p ? 'signed_up' : ($unlock->isPast() ? 'qualified' : 'pending')), 'reason' => $why, 'unlockAt' => $unlock?->toIso8601String(), 'purchase' => $p];
        }

        return $out;
    }

    public static function qualifiedCount(int $uid): int
    {
        return count(array_filter(self::friends($uid), fn ($f) => $f['status'] === 'qualified'));
    }

    /** Writes the ₹50 credits that have been earned since the last look. Safe to call any number of times. */
    public static function sync(int $uid): void
    {
        $r = self::rules();
        if (! $r['enabled']) {
            return;
        }
        foreach (self::friends($uid) as $f) {
            if ($f['status'] !== 'qualified' || DB::table('wallet_transactions')->where('reference', 'ref:'.$f['id'])->exists()) {
                continue;
            }
            $earned = Carbon::parse($f['unlockAt']);
            $month = (int) DB::table('wallet_transactions')->where('owner_id', $uid)->where('kind', 'referral')->whereBetween('earned_at', [$earned->copy()->startOfMonth(), $earned->copy()->endOfMonth()])->sum('amount_paise');
            if ($month + $r['rewardPaise'] > $r['monthlyCapPaise']) {
                continue; // over the monthly cap: the friend still counts towards the 5-friend reward
            }
            DB::table('wallet_transactions')->insertOrIgnore(['owner_id' => $uid, 'kind' => 'referral', 'amount_paise' => $r['rewardPaise'], 'reference' => 'ref:'.$f['id'], 'earned_at' => $earned, 'expires_at' => $earned->copy()->addDays($r['walletDays']), 'note' => $f['label'].' bought a plan', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Credit rows with what is left of each after spending (earliest expiry first), plus the spendable balance now. */
    public static function ledger(int $uid): array
    {
        $rows = DB::table('wallet_transactions')->where('owner_id', $uid)->orderBy('created_at')->orderBy('id')->get();
        $credits = $rows->filter(fn ($x) => $x->amount_paise > 0)->map(fn ($x) => ['row' => $x, 'left' => (int) $x->amount_paise])->values()->all();
        foreach ($rows->filter(fn ($x) => $x->amount_paise < 0) as $d) {
            $need = -(int) $d->amount_paise;
            $at = Carbon::parse($d->earned_at ?? $d->created_at);
            $open = array_keys(array_filter($credits, fn ($c) => $c['left'] > 0 && Carbon::parse($c['row']->earned_at ?? $c['row']->created_at)->lte($at) && (! $c['row']->expires_at || Carbon::parse($c['row']->expires_at)->gt($at))));
            usort($open, fn ($a, $b) => strcmp((string) ($credits[$a]['row']->expires_at ?? '9999'), (string) ($credits[$b]['row']->expires_at ?? '9999')));
            foreach ($open as $i) {
                $take = min($need, $credits[$i]['left']);
                $credits[$i]['left'] -= $take;
                $need -= $take;
                if (! $need) {
                    break;
                }
            }
        }
        $balance = 0;
        foreach ($credits as $c) {
            if (! $c['row']->expires_at || Carbon::parse($c['row']->expires_at)->isFuture()) {
                $balance += $c['left'];
            }
        }

        return ['balancePaise' => $balance, 'credits' => $credits, 'rows' => $rows];
    }

    public static function balance(int $uid): int
    {
        self::sync($uid);

        return self::ledger($uid)['balancePaise'];
    }

    /** Is this the buyer's first purchase through a referral link? Then the friend discount applies. */
    public static function friendDiscount(int $uid): int
    {
        $r = self::rules();
        $u = User::find($uid);
        if (! $r['enabled'] || ! $u?->referred_by) {
            return 0;
        }
        $ref = User::find($u->referred_by);
        if (! $ref || $ref->blocked || self::selfReferral($ref, $u)) {
            return 0;
        }
        $bought = DB::table('purchases')->where('owner_id', $uid)->whereNotNull('purchased_at')->whereIn('status', ['paid', 'partially_refunded', 'refunded', 'disputed'])->where('provider', '!=', 'referral_reward')->where('amount_paise', '>', 0)->exists();

        return $bought ? 0 : $r['friendDiscountPaise'];
    }

    /** Called from verified payment processing: the wallet part of the order is spent now. */
    public static function spend(object $p): void
    {
        if ((int) $p->wallet_paise <= 0) {
            return;
        }
        if (self::ledger($p->owner_id)['balancePaise'] < (int) $p->wallet_paise) {
            ErrorLog::record('warning', 'billing', 'Order '.$p->id.' used more wallet credit than was left (two orders at once). Review the order.', null, null, $p->owner_id);
        }
        DB::table('wallet_transactions')->insertOrIgnore(['owner_id' => $p->owner_id, 'kind' => 'spend', 'amount_paise' => -(int) $p->wallet_paise, 'reference' => 'spend:'.$p->id, 'earned_at' => now(), 'note' => 'Used at checkout', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A refunded order gives its wallet credit back, valid for another 90 days. */
    public static function giveBack(object $p): void
    {
        if ((int) $p->wallet_paise <= 0 || ! DB::table('wallet_transactions')->where('reference', 'spend:'.$p->id)->exists()) {
            return;
        }
        DB::table('wallet_transactions')->insertOrIgnore(['owner_id' => $p->owner_id, 'kind' => 'return', 'amount_paise' => (int) $p->wallet_paise, 'reference' => 'return:'.$p->id, 'earned_at' => now(), 'expires_at' => now()->addDays(self::rules()['walletDays']), 'note' => 'Returned with a refund', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** What the customer sees: balance, earned so far, pending rewards with unlock dates, progress to the 5-friend reward. */
    public static function summary(int $uid): array
    {
        $r = self::rules();
        self::sync($uid);
        $l = self::ledger($uid);
        $friends = self::friends($uid);
        $qualified = count(array_filter($friends, fn ($f) => $f['status'] === 'qualified'));

        return [
            'enabled' => $r['enabled'], 'rules' => $r, 'balancePaise' => $l['balancePaise'],
            'earnedPaise' => (int) $l['rows']->where('kind', 'referral')->sum('amount_paise'),
            'pending' => array_values(array_map(fn ($f) => ['label' => $f['label'], 'unlockAt' => $f['unlockAt'], 'amountPaise' => $r['rewardPaise']], array_filter($friends, fn ($f) => $f['status'] === 'pending'))),
            'friends' => array_map(fn ($f) => ['label' => $f['label'], 'joinedAt' => $f['joinedAt'], 'status' => $f['status'], 'unlockAt' => $f['unlockAt']], $friends),
            'milestone' => ['target' => $r['milestone'], 'count' => $qualified, 'claimed' => DB::table('referral_rewards')->where('owner_id', $uid)->where('milestone', 5)->exists()],
            'transactions' => array_map(fn ($c) => ['kind' => $c['row']->kind, 'amountPaise' => (int) $c['row']->amount_paise, 'leftPaise' => $c['left'], 'note' => $c['row']->note, 'earnedAt' => $c['row']->earned_at, 'expiresAt' => $c['row']->expires_at], array_reverse($l['credits'])),
            'spent' => $l['rows']->where('kind', 'spend')->map(fn ($x) => ['amountPaise' => -(int) $x->amount_paise, 'at' => $x->created_at])->values()->all(),
        ];
    }
}
