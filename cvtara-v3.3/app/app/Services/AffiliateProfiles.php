<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AffiliateProfiles
{
    public static function creator(User $u, array $d): void
    {
        DB::transaction(function () use ($u, $d) {
            User::whereKey($u->id)->lockForUpdate()->first();
            $old = DB::table('creator_applications')->where('owner_id', $u->id)->first();
            if ($old && in_array($old->status, ['approved', 'pending'])) {
                throw ValidationException::withMessages(['creator' => 'Your application is already pending or approved. Contact support to change it.']);
            }
            DB::table('creator_applications')->updateOrInsert(['owner_id' => $u->id], ['social_links_json' => json_encode($d['links']), 'audience' => $d['audience'], 'status' => 'pending', 'rate_bps' => null, 'review_note' => null, 'reviewed_by' => null, 'reviewed_at' => null, 'created_at' => now(), 'updated_at' => now()]);
            Settings::audit($u->id, 'creator_applied');
        });
    }

    public static function save(User $u, array $d): void
    {
        DB::transaction(function () use ($u, $d) {
            User::whereKey($u->id)->lockForUpdate()->first();
            if (DB::table('withdrawals')->where('owner_id', $u->id)->whereIn('status', ['requested', 'processing'])->exists()) {
                throw ValidationException::withMessages(['profile' => 'Wait until your open withdrawal is resolved before changing payout details.']);
            }
            $destination = $d['method'] === 'upi' ? 'upi:'.strtolower($d['upi']) : 'bank:'.$d['account'];
            $hash = hash_hmac('sha256', $destination, config('app.key'));
            $pan = ! empty($d['pan']) ? hash_hmac('sha256', $d['pan'], config('app.key')) : null;
            $flags = [];
            if (DB::table('payout_profiles')->where('owner_id', '!=', $u->id)->where('destination_hash', $hash)->exists()) {
                $flags[] = 'Destination is also used by another account';
            }
            if ($pan && DB::table('payout_profiles')->where('owner_id', '!=', $u->id)->where('pan_hash', $pan)->exists()) {
                $flags[] = 'Tax identifier is also used by another account';
            }
            $mask = $d['method'] === 'upi' ? 'UPI •••'.substr($d['upi'], -6) : 'Bank ••••'.substr($d['account'], -4);
            DB::table('payout_profiles')->updateOrInsert(['owner_id' => $u->id], ['details_encrypted' => Crypt::encryptString(json_encode($d)), 'destination_hash' => $hash, 'pan_hash' => $pan, 'destination_masked' => $mask, 'status' => 'pending', 'risk_flags_json' => json_encode($flags), 'review_note' => null, 'beneficiary_id' => null, 'reviewed_by' => null, 'reviewed_at' => null, 'eligible_at' => now()->addHours(48), 'created_at' => now(), 'updated_at' => now()]);
            Settings::audit($u->id, 'payout_profile_submitted');
        });
    }

    public static function publicProfile(int $id): ?array
    {
        $p = DB::table('payout_profiles')->where('owner_id', $id)->first();

        return $p ? ['status' => $p->status, 'destination' => $p->destination_masked, 'note' => $p->review_note, 'eligibleAt' => $p->eligible_at] : null;
    }

    public static function approve(User $admin, int $id, array $d): void
    {
        DB::transaction(function () use ($admin, $id, $d) {
            User::whereKey($id)->lockForUpdate()->first();
            $p = DB::table('payout_profiles')->where('owner_id', $id)->lockForUpdate()->first();
            abort_unless($p, 404);
            abort_if(! empty($d['destinationHash']) && ! hash_equals((string) $p->destination_hash, (string) $d['destinationHash']), 409, 'The customer changed their payout details after you opened them. Open the profile again and review the new details.');
            abort_if($id === $admin->id, 422, 'An owner cannot approve their own payout identity.');
            abort_if(DB::table('withdrawals')->where('owner_id', $id)->whereIn('status', ['requested', 'processing'])->exists(), 422, 'Resolve the open withdrawal first.');
            DB::table('payout_profiles')->where('owner_id', $id)->update(['status' => $d['status'], 'review_note' => $d['note'], 'beneficiary_id' => $d['beneficiaryId'] ?? null, 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'updated_at' => now()]);
            Settings::audit($admin->id,'payout_profile_'.$d['status'],(string) $id);
        });
    }
}
