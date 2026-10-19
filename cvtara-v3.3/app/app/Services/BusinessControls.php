<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessControls
{
    public static function get(): array
    {
        $value = array_replace_recursive(['maintenance' => ['enabled' => false, 'title' => 'We’re making CVTara better.', 'message' => 'Scheduled maintenance is in progress. Your saved work is safe. Please check back soon.'], 'brand' => ['about' => 'Built by an HR professional working in the edtech industry, helping job seekers prepare clear resumes and more focused applications.'], 'growth' => Referrals::config(), 'coupons' => []], Settings::get('business_controls', []));
        $value['growth'] = Referrals::config();
        foreach ($value['coupons'] as &$c) {
            $c['plans'] = array_values(array_unique(array_map([Settings::class, 'planAlias'], (array) ($c['plans'] ?? []))));
        }
        unset($c);

        return $value;
    }

    public static function save(Request $r): array
    {
        $v = $r->validate(['maintenance.enabled' => 'required|boolean', 'maintenance.title' => 'required|string|max:120', 'maintenance.message' => 'required|string|max:800', 'brand.about' => 'required|string|min:20|max:600', 'growth.enabled' => 'sometimes|boolean', 'growth.rateBps' => 'sometimes|integer|min:0|max:5000', 'growth.tierRateBps' => 'sometimes|integer|min:0|max:5000', 'growth.tierThreshold' => 'sometimes|integer|min:1|max:100000', 'growth.minimumPaise' => 'sometimes|integer|min:100|max:100000000', 'growth.holdDays' => 'sometimes|integer|min:1|max:60', 'growth.downloadThreshold' => 'sometimes|integer|min:1|max:10000', 'growth.premiumDays' => 'sometimes|integer|min:1|max:365', 'growth.minQualifyingPaise' => 'sometimes|integer|min:0|max:1000000', 'growth.proOnly' => 'sometimes|boolean', 'coupons' => 'present|array|max:100', 'coupons.*.code' => 'required|distinct|regex:/^[A-Z0-9_-]{3,30}$/', 'coupons.*.type' => 'required|in:percent,fixed', 'coupons.*.value' => 'required|integer|min:1|max:99999', 'coupons.*.enabled' => 'required|boolean', 'coupons.*.plans' => 'required|array|min:1', 'coupons.*.plans.*' => 'required|in:'.implode(',', Settings::paidPlanKeys()), 'coupons.*.expiresAt' => 'nullable|date_format:Y-m-d', 'coupons.*.maxUses' => 'nullable|integer|min:0|max:1000000', 'coupons.*.perUser' => 'nullable|integer|min:0|max:100']);
        foreach ($v['coupons'] as &$c) {
            abort_if($c['type'] === 'percent' && $c['value'] > 90, 422, 'Percentage discounts cannot exceed 90%.');
            $c['expiresAt'] = $c['expiresAt'] ?? '';
            $c['maxUses'] = (int) ($c['maxUses'] ?? 0);
            $c['perUser'] = (int) ($c['perUser'] ?? 1);
        }unset($c);
        DB::transaction(function () use ($v, $r) {
            unset($v['growth']); // referral rules live in Referral Ops only
            Settings::put('business_controls', $v);
            Promos::bump();
            Settings::audit($r->user()->id, 'business_controls_saved');
        });

        return $v;
    }

    /** The active Quick Download that can still be credited towards Job Hunt or Pro (within 7 days, used once). */
    public static function upgradeSource(int $userId): ?object
    {
        return DB::table('purchases')->where('owner_id', $userId)->where('plan', 'quick')->where('status', 'paid')->where('refunded_paise', 0)
            ->where('purchased_at', '>=', now()->subDays(7))
            ->whereNotIn('id', DB::table('purchases')->whereNotNull('upgrade_from')->whereIn('status', ['paid', 'partially_refunded'])->select('upgrade_from'))
            ->orderByDesc('purchased_at')->first();
    }

    /**
     * The price the server will charge, step by step: list price (lower for an add-on while Job Hunt or Pro is active), coupon,
     * Quick Download upgrade credit or the referred-friend discount (never both), then wallet credit. At least ₹1 is always paid.
     */
    public static function quote(string $plan, string $code = '', ?int $userId = null, bool $useWallet = true): array
    {
        $p = Settings::plans()[$plan] ?? null;
        abort_unless($p && $plan !== 'free', 422, 'Choose a paid plan.');
        $list = (int) round($p['price'] * 100);
        $addon = 0;
        if (($p['type'] ?? '') === 'service' && $userId && ! empty($p['planPrice']) && (int) (Settings::access($userId)['limits']['rank'] ?? 0) >= 2) {
            $addon = max(0, $list - (int) round($p['planPrice'] * 100));
        }
        $subtotal = $list - $addon;
        $discount = 0;
        $code = strtoupper(trim($code));
        if ($code) {
            $c = collect(self::get()['coupons'])->first(fn ($c) => $c['code'] === $code && $c['enabled'] && in_array($plan, $c['plans']) && (! $c['expiresAt'] || $c['expiresAt'] >= now('UTC')->toDateString()));
            abort_unless($c, 422, 'This coupon is invalid, expired or unavailable for this plan.');
            $base = fn () => DB::table('purchases')->where('coupon_code', $code);
            $max = (int) ($c['maxUses'] ?? 0);
            if ($max > 0 && $base()->where(fn ($q) => $q->whereIn('status', ['paid', 'partially_refunded'])->orWhere(fn ($q) => $q->where('status', 'created')->where('created_at', '>', now()->subDays(2))->when($userId, fn ($q) => $q->where('owner_id', '!=', $userId))))->count() >= $max) {
                abort(422, 'This coupon has reached its redemption limit.');
            }
            $per = (int) ($c['perUser'] ?? 1);
            if ($per > 0 && $userId && $base()->where('owner_id', $userId)->whereIn('status', ['paid', 'partially_refunded'])->count() >= $per) {
                abort(422, 'You have already used this coupon.');
            }
            $discount = min($subtotal - 100, $c['type'] === 'percent' ? intdiv($subtotal * $c['value'], 100) : $c['value'] * 100);
            abort_if($discount < 0, 422);
        }
        $left = $subtotal - $discount;
        $upgrade = 0;
        $from = null;
        if ($userId && ($p['type'] ?? '') === 'plan' && (int) ($p['rank'] ?? 0) >= 2 && ($src = self::upgradeSource($userId))) {
            $upgrade = max(0, min($left - 100, (int) $src->amount_paise + (int) $src->wallet_paise));
            $from = $upgrade ? $src->id : null;
        }
        $left -= $upgrade;
        $friend = $userId && ! $upgrade ? max(0, min($left - 100, Wallet::friendDiscount($userId, $list))) : 0;
        $left -= $friend;
        $wallet = $userId && $useWallet && Wallet::enabled() ? max(0, min($left - 100, Wallet::balance($userId))) : 0;
        $left -= $wallet;

        return ['plan' => $plan, 'code' => $code, 'listPaise' => $list, 'addonDiscountPaise' => $addon, 'subtotalPaise' => $subtotal, 'discountPaise' => $discount, 'upgradeCreditPaise' => $upgrade, 'upgradeFrom' => $from, 'referralDiscountPaise' => $friend, 'walletPaise' => $wallet, 'walletBalancePaise' => $userId && Wallet::enabled() ? Wallet::balance($userId) : 0, 'totalPaise' => $left, 'accessDays' => $p['accessDays'], 'bonusDays' => $p['bonusDays'] ?? 0];
    }

    /** The purchase-row columns that record how the price was reached. */
    public static function quoteColumns(array $q): array
    {
        return ['coupon_code' => $q['code'] ?: null, 'discount_paise' => $q['discountPaise'], 'wallet_paise' => $q['walletPaise'], 'referral_discount_paise' => $q['referralDiscountPaise'], 'upgrade_credit_paise' => $q['upgradeCreditPaise'], 'addon_discount_paise' => $q['addonDiscountPaise'], 'upgrade_from' => $q['upgradeFrom']];
    }
}
