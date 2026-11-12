<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AI cost controls and contribution margin (3.56.0, V7 §24-§26).
 *  - Caps per customer per day, per feature per month and per order (as a share of what the order paid), with alerts in
 *    the Error Log. Paid features are never stopped by a cap (a promised entitlement is not silently removed); only the
 *    free helpers (Tara chat and writing help) pause for that customer until tomorrow. The monthly growth cap stays in Growth.
 *  - The provider's monthly invoice is entered by the owner and compared with our own cost log.
 *  - Contribution margin per plan and add-on: net collected revenue minus gateway fees, AI cost, fulfilment and support
 *    cost the owner enters, and tax when the owner sets a GST share. Refunds are shown separately.
 */
class CostControls
{
    public const TZ = 'Asia/Kolkata';

    public const DEFAULTS = ['userDailyInr' => 30, 'featureMonthlyInr' => [], 'orderPct' => 40, 'gatewayPct' => 2.36, 'gstPct' => 0, 'fulfilmentInr' => [], 'supportInr' => []];

    /** Free helpers that may pause for one customer when that customer's daily cap is reached. */
    public const SOFT = ['customer:tara_chat', 'customer:writing_help'];

    public static function settings(): array
    {
        $s = array_replace(self::DEFAULTS, array_intersect_key((array) Settings::get('costControls', []), self::DEFAULTS));
        foreach (['featureMonthlyInr', 'fulfilmentInr', 'supportInr'] as $k) {
            $s[$k] = array_map(fn ($v) => max(0, (float) $v), array_filter((array) $s[$k], fn ($v) => is_numeric($v)));
        }

        return $s;
    }

    public static function save(User $u, array $v): array
    {
        $s = self::settings();
        foreach (['userDailyInr', 'orderPct', 'gatewayPct', 'gstPct'] as $k) {
            if (array_key_exists($k, $v) && is_numeric($v[$k])) {
                $s[$k] = max(0, min($k === 'userDailyInr' ? 100000 : 100, (float) $v[$k]));
            }
        }
        foreach (['featureMonthlyInr', 'fulfilmentInr', 'supportInr'] as $k) {
            if (isset($v[$k]) && is_array($v[$k])) {
                $s[$k] = [];
                foreach ($v[$k] as $key => $amt) {
                    if (preg_match('/^[a-z0-9:_-]{2,60}$/', (string) $key) && is_numeric($amt) && $amt > 0) {
                        $s[$k][(string) $key] = min(10000000, (float) $amt);
                    }
                }
            }
        }
        Settings::put('costControls', $s);
        Settings::audit($u->id, 'cost_controls_saved');

        return $s;
    }

    private static function dayStart(): Carbon
    {
        return now()->tz(self::TZ)->startOfDay()->utc();
    }

    private static function monthStart(): Carbon
    {
        return now()->tz(self::TZ)->startOfMonth()->utc();
    }

    public static function userToday(int $ownerId): int
    {
        return (int) DB::table('ai_usage')->where('owner_id', $ownerId)->where('created_at', '>=', self::dayStart())->sum('cost_paise');
    }

    /** False only for a free helper when this customer has reached today's cap. */
    public static function allowed(string $purpose, ?int $ownerId): bool
    {
        if (! $ownerId || ! in_array($purpose, self::SOFT, true)) {
            return true;
        }
        $cap = (int) round(self::settings()['userDailyInr'] * 100);

        return $cap <= 0 || self::userToday($ownerId) < $cap;
    }

    /** After each AI request: warn the owner once per customer per day, per feature per month and per order. Never throws. */
    public static function afterUsage(string $purpose, ?int $ownerId): void
    {
        try {
            $s = self::settings();
            $day = now()->tz(self::TZ)->format('Y-m-d');
            if ($ownerId) {
                $cap = (int) round($s['userDailyInr'] * 100);
                $spent = self::userToday($ownerId);
                if ($cap > 0 && $spent >= $cap) {
                    ErrorLog::record('warning', 'ai-cost', 'Customer #'.$ownerId.' used ₹'.number_format($spent / 100, 2).' of AI today (cap ₹'.number_format($cap / 100).'). Paid features keep working; Tara chat and writing help pause for this customer until tomorrow.', 'ai-user:'.$day.':'.$ownerId);
                }
                $p = DB::table('purchases')->where('owner_id', $ownerId)->whereIn('status', ['paid', 'partially_refunded'])->where('amount_paise', '>', 0)->whereNotNull('purchased_at')->where('access_until', '>', now())->orderByDesc('purchased_at')->first(['id', 'plan', 'amount_paise', 'purchased_at']);
                if ($p && $s['orderPct'] > 0) {
                    $used = (int) DB::table('ai_usage')->where('owner_id', $ownerId)->where('created_at', '>=', $p->purchased_at)->sum('cost_paise');
                    if ($used >= $p->amount_paise * $s['orderPct'] / 100) {
                        ErrorLog::record('warning', 'ai-cost', 'Order ORD-'.strtoupper(substr($p->id, 0, 6)).' ('.$p->plan.') has used ₹'.number_format($used / 100, 2).' of AI, '.round($used / max(1, $p->amount_paise) * 100).'% of what it paid (alert at '.$s['orderPct'].'%).', 'ai-order:'.$p->id);
                    }
                }
            }
            $fcap = (int) round(($s['featureMonthlyInr'][$purpose] ?? 0) * 100);
            if ($fcap > 0) {
                $f = (int) DB::table('ai_usage')->where('purpose', $purpose)->where('created_at', '>=', self::monthStart())->sum('cost_paise');
                if ($f >= $fcap) {
                    ErrorLog::record('warning', 'ai-cost', 'AI spending for '.$purpose.' reached ₹'.number_format($f / 100).' this month (cap ₹'.number_format($fcap / 100).'). Customers who paid keep getting it; decide whether to change the limit or the price.', 'ai-feature:'.now()->tz(self::TZ)->format('Y-m').':'.$purpose);
                }
            }
        } catch (\Throwable $e) {
            ErrorLog::exception($e);
        }
    }

    /** Console overview: this month per feature against its cap, and today's highest-spending customers. */
    public static function overview(): array
    {
        $s = self::settings();
        $feat = DB::table('ai_usage')->where('created_at', '>=', self::monthStart())->groupBy('purpose')->selectRaw('purpose, sum(cost_paise) p, count(*) n')->orderByDesc('p')->get()
            ->map(fn ($x) => ['purpose' => $x->purpose, 'paise' => (int) $x->p, 'calls' => (int) $x->n, 'capPaise' => (int) round(($s['featureMonthlyInr'][$x->purpose] ?? 0) * 100)])->all();
        $top = DB::table('ai_usage as a')->leftJoin('users as u', 'u.id', '=', 'a.owner_id')->whereNotNull('a.owner_id')->where('a.created_at', '>=', self::dayStart())->groupBy('a.owner_id', 'u.email')
            ->selectRaw('a.owner_id, u.email, sum(a.cost_paise) p, count(*) n')->orderByDesc('p')->limit(10)->get()->map(fn ($x) => ['ownerId' => (int) $x->owner_id, 'email' => (string) $x->email, 'paise' => (int) $x->p, 'calls' => (int) $x->n])->all();

        return ['settings' => $s, 'features' => $feat, 'topToday' => $top, 'month' => now()->tz(self::TZ)->format('Y-m'), 'budget' => Growth::budget(), 'invoices' => self::reconcile()];
    }

    // ---------------------------------------------------------------- invoice check

    public static function saveInvoice(User $u, string $month, float $amountInr, string $note = ''): void
    {
        abort_unless(preg_match('/^20\d\d-(0[1-9]|1[0-2])$/', $month), 422, 'Choose the month of the invoice.');
        abort_if($amountInr < 0 || $amountInr > 100000000, 422, 'Enter the invoice total in rupees.');
        DB::table('ai_invoices')->updateOrInsert(['month' => $month], ['amount_paise' => (int) round($amountInr * 100), 'note' => mb_substr(trim($note), 0, 300) ?: null, 'entered_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        Settings::audit($u->id, 'ai_invoice_saved', $month);
    }

    /** The last 12 months: our cost log against the invoice, with the difference. Months follow India time. */
    public static function reconcile(): array
    {
        $inv = DB::table('ai_invoices')->get()->keyBy('month');
        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $m = now()->tz(self::TZ)->startOfMonth()->subMonths($i);
            $k = $m->format('Y-m');
            $ours = (int) DB::table('ai_usage')->where('created_at', '>=', $m->copy()->utc())->where('created_at', '<', $m->copy()->addMonth()->utc())->sum('cost_paise');
            $bill = isset($inv[$k]) ? (int) $inv[$k]->amount_paise : null;
            if ($ours === 0 && $bill === null && $i > 0) {
                continue;
            }
            $out[] = ['month' => $k, 'oursPaise' => $ours, 'invoicePaise' => $bill, 'diffPaise' => $bill === null ? null : $bill - $ours, 'diffPct' => $bill === null || $ours === 0 ? null : round(($bill - $ours) / $ours * 100, 1), 'note' => $bill === null ? null : $inv[$k]->note];
        }

        return $out;
    }

    // ---------------------------------------------------------------- contribution margin

    /**
     * One row per plan or add-on sold in the range (by order date, India time): orders, gross, refunds, net, GST share,
     * gateway fees, AI cost of those buyers during each order's access (each request counted once, against the newest
     * order active at that moment), fulfilment and support cost per order as the owner set them, margin and margin %.
     */
    public static function margin(string $from, string $to): array
    {
        $s = self::settings();
        $plans = Settings::plans();
        $q = DB::table('purchases as p')->whereIn('p.status', ['paid', 'partially_refunded', 'refunded', 'disputed'])->where('p.amount_paise', '>', 0)->whereNotNull('p.purchased_at')
            ->where('p.created_at', '>=', Carbon::parse($from, self::TZ)->startOfDay()->utc())->where('p.created_at', '<=', Carbon::parse($to, self::TZ)->endOfDay()->utc());
        Settings::sinceStart($q, 'p.created_at');
        if (! config('app.preview')) {
            $q->where('p.is_live', true)->whereNotIn('p.provider', ['demo', 'referral_reward']);
        } else {
            $q->whereNotIn('p.provider', ['referral_reward']);
        }
        $orders = $q->get(['p.id', 'p.owner_id', 'p.plan', 'p.amount_paise', 'p.refunded_paise', 'p.purchased_at', 'p.access_until']);
        $ai = [];
        if ($orders->isNotEmpty()) {
            $byOwner = $orders->groupBy('owner_id');
            $all = DB::table('purchases')->whereIn('owner_id', $byOwner->keys())->whereNotNull('purchased_at')->where('amount_paise', '>', 0)->orderBy('purchased_at')->get(['id', 'owner_id', 'purchased_at', 'access_until'])->groupBy('owner_id');
            foreach (DB::table('ai_usage')->whereIn('owner_id', $byOwner->keys())->where('created_at', '>=', $orders->min('purchased_at'))->get(['owner_id', 'cost_paise', 'created_at']) as $x) {
                $hit = null;
                foreach ($all[$x->owner_id] ?? [] as $p) {
                    if ($p->purchased_at <= $x->created_at && (! $p->access_until || $p->access_until >= $x->created_at)) {
                        $hit = $p->id; // newest active order at that moment wins (rows are oldest first)
                    }
                }
                if ($hit) {
                    $ai[$hit] = ($ai[$hit] ?? 0) + (int) $x->cost_paise;
                }
            }
        }
        $rows = [];
        foreach ($orders as $o) {
            $k = $o->plan;
            $rows[$k] ??= ['sku' => $k, 'name' => $plans[$k]['name'] ?? $k, 'orders' => 0, 'gross' => 0, 'refunds' => 0, 'ai' => 0];
            $rows[$k]['orders']++;
            $rows[$k]['gross'] += (int) $o->amount_paise;
            $rows[$k]['refunds'] += (int) $o->refunded_paise;
            $rows[$k]['ai'] += $ai[$o->id] ?? 0;
        }
        $out = [];
        foreach ($rows as $r) {
            $net = $r['gross'] - $r['refunds'];
            $gst = (int) round($net * $s['gstPct'] / (100 + $s['gstPct']));
            $fees = (int) round($r['gross'] * $s['gatewayPct'] / 100); // the gateway keeps its fee on refunded orders too
            $ful = (int) round(($s['fulfilmentInr'][$r['sku']] ?? 0) * 100) * $r['orders'];
            $sup = (int) round(($s['supportInr'][$r['sku']] ?? 0) * 100) * $r['orders'];
            $margin = $net - $gst - $fees - $r['ai'] - $ful - $sup;
            $out[] = ['sku' => $r['sku'], 'name' => $r['name'], 'orders' => $r['orders'], 'grossPaise' => $r['gross'], 'refundsPaise' => $r['refunds'], 'netPaise' => $net, 'gstPaise' => $gst, 'feesPaise' => $fees,
                'aiPaise' => $r['ai'], 'fulfilmentPaise' => $ful, 'supportPaise' => $sup, 'marginPaise' => $margin, 'marginPct' => $net > 0 ? round($margin / $net * 100, 1) : null, 'perOrderPaise' => (int) round($margin / max(1, $r['orders']))];
        }
        usort($out, fn ($a, $b) => $b['netPaise'] <=> $a['netPaise']);

        return $out;
    }
}
