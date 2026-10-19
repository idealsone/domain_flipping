<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Work done by a person after payment: Tara Expert Review and Profile Boost (alone or inside the Complete Job Kit). */
class Services
{
    public const STATUSES = ['not_started', 'in_review', 'delivered', 'cancelled'];

    /** Called from verified payment processing: one order per service the purchase includes. */
    public static function open(object $p): void
    {
        $l = json_decode((string) $p->limits_json, true) ?: [];
        $list = ($l['type'] ?? 'plan') === 'service' ? [$p->plan] : (array) ($l['includes'] ?? []);
        foreach ($list as $service) {
            DB::table('service_orders')->insertOrIgnore(['id' => (string) Str::uuid(), 'owner_id' => $p->owner_id, 'purchase_id' => $p->id, 'service' => $service, 'status' => 'not_started', 'created_at' => now(), 'updated_at' => now()]);
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
            ->map(fn ($o) => ['id' => $o->id, 'purchaseId' => $o->purchase_id, 'service' => $o->service, 'name' => $names[$o->service]['name'] ?? $o->service, 'status' => $o->status, 'note' => $o->status === 'delivered' ? $o->note : null, 'createdAt' => $o->created_at, 'deliveredAt' => $o->delivered_at])->all();
    }

    public static function queue(): array
    {
        return DB::table('service_orders as o')->join('users as u', 'u.id', '=', 'o.owner_id')->orderByRaw("CASE o.status WHEN 'not_started' THEN 0 WHEN 'in_review' THEN 1 ELSE 2 END")->orderBy('o.created_at')->limit(300)
            ->get(['o.id', 'o.purchase_id', 'o.service', 'o.status', 'o.note', 'o.created_at', 'o.delivered_at', 'u.name', 'u.email'])->all();
    }

    public static function update(User $admin, string $id, string $status, string $note = ''): void
    {
        DB::transaction(function () use ($admin, $id, $status, $note) {
            $o = DB::table('service_orders')->where('id', $id)->lockForUpdate()->first();
            abort_unless($o, 404);
            $next = ['not_started' => ['in_review', 'cancelled'], 'in_review' => ['delivered', 'cancelled'], 'delivered' => [], 'cancelled' => []];
            abort_unless(in_array($status, $next[$o->status] ?? [], true), 422, 'This order has already moved on. Refresh and try again.');
            abort_if($status === 'delivered' && mb_strlen(trim($note)) < 10, 422, 'Write what was delivered (for example "Review sent by email on 20 Oct").');
            DB::table('service_orders')->where('id', $id)->update(['status' => $status, 'note' => trim($note) ?: $o->note, 'delivered_at' => $status === 'delivered' ? now() : null, 'updated_at' => now()]);
            Settings::audit($admin->id, 'service_'.$status, $id);
        });
    }
}
