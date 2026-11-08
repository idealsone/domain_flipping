<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 7-day refund: a full refund within 7 days of purchase, only when nothing paid for has been used (no clean download, no paid
 * ATS scan, no tailored resume, service not started), and once per customer. The money goes back to the original payment method.
 */
class Refunds
{
    public const DAYS = 7;

    public static function settings(): array
    {
        return array_merge(['autoApprove' => false], (array) Settings::get('refunds', []));
    }

    /** null when this purchase can be refunded, otherwise the reason it cannot, in the customer's words. */
    public static function blocker(object $p, ?string $ignoreRequest = null): ?string
    {
        if (! in_array($p->status, ['paid'], true) || (int) $p->refunded_paise > 0) {
            return 'This order is not an active paid order.';
        }
        if ((int) $p->amount_paise <= 0 || in_array($p->provider, ['referral_reward'], true)) {
            return 'Free and reward plans have nothing to refund.';
        }
        if ($p->provider === 'cashfree') {
            return 'This order was paid through our earlier payment provider. Write to support and we will refund it by hand.';
        }
        if (! $p->purchased_at || strtotime($p->purchased_at) < now()->subDays(self::DAYS)->timestamp) {
            return 'The 7-day refund window for this order has ended.';
        }
        $since = $p->purchased_at;
        $used = DB::table('product_usage')->where('owner_id', $p->owner_id)->where(function ($q) use ($p, $since) {
            $q->where(fn ($q) => $q->whereIn('action', ['doc_resume', 'doc_letter', 'ats_scan'])->where('reference', 'like', '%|'.$p->id))
                ->orWhere(fn ($q) => $q->where('action', 'primary_free')->where('created_at', '>=', $since));
        })->exists();
        if ($used) {
            return 'A clean download or a paid ATS scan from this order has already been used.';
        }
        if (DB::table('resume_drafts')->where('owner_id', $p->owner_id)->where('source', 'tailored')->where('created_at', '>=', $since)->exists()) {
            return 'A tailored resume has already been made with this order.';
        }
        if (Services::started($p->id)) {
            return 'Our team has already started work on this order.';
        }
        if (DB::table('purchases')->where('upgrade_from', $p->id)->whereIn('status', ['paid', 'partially_refunded'])->exists()) {
            return 'This order was already credited towards an upgrade.';
        }
        if (DB::table('refund_requests')->where('purchase_id', $p->id)->when($ignoreRequest, fn ($q) => $q->where('id', '!=', $ignoreRequest))->exists()) {
            return 'A refund has already been requested for this order.';
        }
        if (DB::table('refund_requests')->where('owner_id', $p->owner_id)->whereIn('status', ['requested', 'approved', 'processed'])->when($ignoreRequest, fn ($q) => $q->where('id', '!=', $ignoreRequest))->exists()) {
            return 'Refunds are available once per customer, and yours has been used.';
        }

        return null;
    }

    /** Purchase rows for the customer's order list, each with whether a refund can be asked for. */
    public static function forUser(int $uid): array
    {
        $reqs = DB::table('refund_requests')->where('owner_id', $uid)->get()->keyBy('purchase_id');

        return DB::table('purchases')->where('owner_id', $uid)->orderByDesc('created_at')->limit(200)->get()->map(function ($p) use ($reqs) {
            $why = self::blocker($p);
            $r = $reqs[$p->id] ?? null;

            return [...(array) $p, 'refundable' => $why === null, 'refundUntil' => $p->purchased_at ? date('c', strtotime($p->purchased_at) + self::DAYS * 86400) : null, 'refund' => $r ? ['status' => $r->status, 'note' => $r->note, 'createdAt' => $r->created_at] : null];
        })->all();
    }

    public static function request(User $u, string $purchaseId, string $reason = ''): array
    {
        $id = DB::transaction(function () use ($u, $purchaseId, $reason) {
            User::whereKey($u->id)->lockForUpdate()->first();
            $p = DB::table('purchases')->where('id', $purchaseId)->where('owner_id', $u->id)->lockForUpdate()->first();
            abort_unless($p, 404);
            if ($why = self::blocker($p)) {
                throw ValidationException::withMessages(['refund' => $why]);
            }
            $id = (string) Str::uuid();
            DB::table('refund_requests')->insert(['id' => $id, 'owner_id' => $u->id, 'purchase_id' => $p->id, 'status' => 'requested', 'amount_paise' => $p->amount_paise, 'reason' => mb_substr(trim($reason), 0, 1000) ?: null, 'created_at' => now(), 'updated_at' => now()]);
            Settings::audit($u->id, 'refund_requested', $p->id);
            MailAutomation::queue($u, 'refund_requested', $id, ['amount' => '₹'.number_format($p->amount_paise / 100, 2), 'order' => 'ORD-'.strtoupper(substr($p->id, 0, 6))], true);

            return $id;
        });
        if (self::settings()['autoApprove']) {
            try {
                self::approve(null, $id);
            } catch (\Throwable $e) {
                ErrorLog::record('warning', 'billing', 'Automatic refund approval failed for request '.$id.': '.$e->getMessage(), null, null, $u->id);
            }
        }

        return (array) DB::table('refund_requests')->where('id', $id)->first();
    }

    /** Admin (or automatic) approval: asks the gateway to send the money back. The gateway's confirmation then ends access. */
    public static function approve(?User $admin, string $id): void
    {
        $r = DB::table('refund_requests')->where('id', $id)->first();
        abort_unless($r, 404);
        abort_unless($r->status === 'requested', 422, 'This refund request has already been decided.');
        $p = DB::table('purchases')->where('id', $r->purchase_id)->first();
        // Anything used after the request was made still blocks the refund.
        if ($why = self::blocker($p, $id)) {
            self::reject($admin, $id, $why);

            return;
        }
        $refundId = 'rf_'.substr(str_replace('-', '', $id), 0, 30);
        // Claim the request first so two approvals can never send two refunds.
        abort_unless(DB::table('refund_requests')->where('id', $id)->where('status', 'requested')->update(['status' => 'approved', 'provider_refund_id' => $refundId, 'decided_by' => $admin?->id, 'decided_at' => now(), 'updated_at' => now()]) === 1, 422, 'This refund request has already been decided.');
        try {
            if (in_array($p->provider, ['demo', 'manual'], true)) {
                DB::table('purchases')->where('id', $p->id)->update(['refunded_paise' => $p->amount_paise, 'status' => 'refunded', 'updated_at' => now()]);
            } elseif ($p->provider === 'razorpay') {
                $k = Settings::providers()['payments'] ?? [];
                Http::withBasicAuth($k['razorpayKeyId'] ?? '', $k['razorpayKeySecret'] ?? '')->timeout(20)->post(Payments::api().'/payments/'.rawurlencode((string) $p->payment_reference).'/refund', ['amount' => $p->amount_paise, 'receipt' => $refundId])->throw();
                // Razorpay has accepted the full refund: end access now rather than waiting for the refund.processed webhook (which then changes nothing).
                DB::table('purchases')->where('id', $p->id)->update(['refunded_paise' => $p->amount_paise, 'status' => 'refunded', 'updated_at' => now()]);
            } else {
                throw ValidationException::withMessages(['refund' => 'This order was not paid through a gateway. Refund it by hand and record it.']);
            }
        } catch (\Throwable $e) {
            DB::table('refund_requests')->where('id', $id)->update(['status' => 'requested', 'provider_refund_id' => null, 'decided_by' => null, 'decided_at' => null, 'updated_at' => now()]);
            throw $e instanceof ValidationException ? $e : ValidationException::withMessages(['refund' => 'The payment gateway did not accept the refund. Nothing was sent. Try again, or refund it in the gateway dashboard.']);
        }
        Settings::audit($admin?->id ?? $p->owner_id, 'refund_approved', $p->id);
        self::settled($p->id);
    }

    public static function reject(?User $admin, string $id, string $note): void
    {
        abort_if(mb_strlen(trim($note)) < 5, 422, 'Explain why the refund was declined.');
        $r = DB::table('refund_requests')->where('id', $id)->first();
        abort_unless($r && $r->status === 'requested', 422, 'This refund request has already been decided.');
        DB::table('refund_requests')->where('id', $id)->update(['status' => 'rejected', 'note' => mb_substr(trim($note), 0, 1000), 'decided_by' => $admin?->id, 'decided_at' => now(), 'updated_at' => now()]);
        Settings::audit($admin?->id ?? $r->owner_id, 'refund_rejected', $r->purchase_id);
        if ($u = User::find($r->owner_id)) {
            MailAutomation::queue($u, 'refund_rejected', $id, ['reason' => trim($note)], true);
        }
    }

    /** After the purchase is marked refunded (gateway event or by hand): close the request, return wallet credit, stop services. */
    public static function settled(string $purchaseId): void
    {
        $p = DB::table('purchases')->where('id', $purchaseId)->first();
        if (! $p || $p->status !== 'refunded') {
            return;
        }
        $r = DB::table('refund_requests')->where('purchase_id', $p->id)->whereIn('status', ['requested', 'approved'])->first();
        if ($r) {
            DB::table('refund_requests')->where('id', $r->id)->update(['status' => 'processed', 'processed_at' => now(), 'updated_at' => now()]);
            if ($u = User::find($p->owner_id)) {
                MailAutomation::queue($u, 'refund_processed', $r->id, ['amount' => '₹'.number_format($p->refunded_paise / 100, 2), 'order' => 'ORD-'.strtoupper(substr($p->id, 0, 6))], true);
            }
        }
        Wallet::giveBack($p);
        DB::table('service_orders')->where('purchase_id', $p->id)->where('status', 'not_started')->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    public static function queue(): array
    {
        return DB::table('refund_requests as r')->join('users as u', 'u.id', '=', 'r.owner_id')->join('purchases as p', 'p.id', '=', 'r.purchase_id')
            ->orderByRaw("CASE r.status WHEN 'requested' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")->orderByDesc('r.created_at')->limit(300)
            ->get(['r.id', 'r.status', 'r.amount_paise', 'r.reason', 'r.note', 'r.created_at', 'r.decided_at', 'r.processed_at', 'p.id as purchase_id', 'p.plan', 'p.provider', 'p.purchased_at', 'u.name', 'u.email'])->all();
    }
}
