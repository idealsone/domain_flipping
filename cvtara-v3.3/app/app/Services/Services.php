<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Work done after payment: Tara Expert Review (written by Tara AI when automatic reviews are on, see ExpertReview) and Profile Boost (done by a person), alone or inside the Complete Job Kit. */
class Services
{
    public const STATUSES = ['not_started', 'in_review', 'delivered', 'cancelled'];

    /** Each service can be ordered this many times per customer per day, which keeps the owner's text-processing costs in check. */
    public const DAILY_LIMIT = 3;

    /** Plan keys this customer cannot order again today: a service already ordered DAILY_LIMIT times, and any kit that includes one. */
    public static function hiddenToday(int $uid): array
    {
        $full = DB::table('service_orders')->where('owner_id', $uid)->where('status', '!=', 'cancelled')->where('created_at', '>=', today())
            ->groupBy('service')->havingRaw('count(*) >= ?', [self::DAILY_LIMIT])->pluck('service')->all();

        return $full ? array_keys(array_filter(Settings::plans(), fn ($p, $k) => in_array($k, $full, true) || array_intersect($full, (array) ($p['includes'] ?? [])), ARRAY_FILTER_USE_BOTH)) : [];
    }

    /** Called from verified payment processing: one order per service the purchase includes. */
    public static function open(object $p): void
    {
        $l = json_decode((string) $p->limits_json, true) ?: [];
        $list = ($l['type'] ?? 'plan') === 'service' ? [$p->plan] : (array) ($l['includes'] ?? []);
        foreach ($list as $service) {
            DB::table('service_orders')->insertOrIgnore(['id' => (string) Str::uuid(), 'owner_id' => $p->owner_id, 'purchase_id' => $p->id, 'service' => $service, 'status' => 'not_started', 'created_at' => now(), 'updated_at' => now()]);
        }
        if (in_array(ExpertReview::SERVICE, $list, true)) {
            ExpertReview::soon();
        }
    }

    /** Once a reviewer has started, the purchase can no longer be refunded. */
    public static function started(string $purchaseId): bool
    {
        return DB::table('service_orders')->where('purchase_id', $purchaseId)->whereIn('status', ['in_review', 'delivered'])->exists();
    }

    public static function forUser(int $uid): array
    {
        $names = Settings::plans();

        return DB::table('service_orders')->where('owner_id', $uid)->orderByDesc('created_at')->limit(50)->get()
            ->map(fn ($o) => ['id' => $o->id, 'purchaseId' => $o->purchase_id, 'service' => $o->service, 'name' => $names[$o->service]['name'] ?? $o->service, 'status' => $o->status, 'note' => $o->status === 'delivered' ? $o->note : null, 'review' => $o->status === 'delivered' ? $o->review : null, 'createdAt' => $o->created_at, 'deliveredAt' => $o->delivered_at])->all();
    }

    public static function queue(): array
    {
        return DB::table('service_orders as o')->join('users as u', 'u.id', '=', 'o.owner_id')->orderByRaw("CASE o.status WHEN 'not_started' THEN 0 WHEN 'in_review' THEN 1 ELSE 2 END")->orderBy('o.created_at')->limit(300)
            ->get(['o.id', 'o.purchase_id', 'o.service', 'o.status', 'o.note', 'o.review', 'o.reviewer', 'o.review_attempts', 'o.review_error', 'o.review_resume', 'o.created_at', 'o.delivered_at', 'u.name', 'u.email'])
            ->each(function ($o) {
                // Flag orders that automatic review gave up on, so the owner knows to step in.
                $o->needs_owner = $o->service === ExpertReview::SERVICE && $o->status === 'not_started' && $o->review_attempts >= ExpertReview::MAX_ATTEMPTS;
            })->all();
    }

    public static function update(User $admin, string $id, string $status, string $note = '', ?string $review = null): void
    {
        $mail = null;
        DB::transaction(function () use ($admin, $id, $status, $note, $review, &$mail) {
            $o = DB::table('service_orders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($o, 404);
            $next = ['not_started' => ['in_review', 'cancelled'], 'in_review' => ['delivered', 'cancelled'], 'delivered' => [], 'cancelled' => []];
            abort_unless(in_array($status, $next[$o->status] ?? [], true), 422, 'This order has already moved on. Refresh and try again.');
            $text = $review !== null && trim($review) !== '' ? trim($review) : $o->review;
            abort_if($status === 'delivered' && mb_strlen(trim($note)) < 10 && trim((string) $text) === '', 422, 'Write what was delivered (for example "Review sent by email on 20 Oct").');
            $note = $status === 'delivered' && mb_strlen(trim($note)) < 10 ? 'Review emailed on '.now()->format('j M Y') : $note;
            DB::table('service_orders')->where('id', $id)->update(['status' => $status, 'note' => trim($note) ?: $o->note, 'review' => $text, 'reviewer' => $status === 'in_review' ? 'owner' : $o->reviewer, 'delivered_at' => $status === 'delivered' ? now() : null, 'updated_at' => now()]);
            Settings::audit($admin->id, 'service_'.$status, $id);
            if ($status === 'delivered' && trim((string) $text) !== '') {
                $mail = [$o->owner_id, $text];
            }
        });
        // A review the owner wrote here is emailed like an automatic one.
        if ($mail && ($u = User::find($mail[0]))) {
            ExpertReview::email($u, $mail[1], $id);
        }
    }
}
