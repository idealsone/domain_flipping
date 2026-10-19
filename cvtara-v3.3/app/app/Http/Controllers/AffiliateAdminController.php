<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\AffiliateProfiles;
use App\Services\Referrals;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AffiliateAdminController extends Controller
{
    public function run(Request $r, string $path)
    {
        abort_unless($r->user()?->is_admin && ! $r->user()->blocked, 403);
        $ok = fn ($d = []) => response()->json(['ok' => true, ...$d]);
        $admin = $r->user();
        $get = $r->isMethod('GET');
        if ($path === 'referrals' && $get) {
            return $ok(['rules' => Referrals::config(), 'withdrawals' => DB::table('withdrawals as w')->join('users as u', 'u.id', '=', 'w.owner_id')->orderByDesc('w.created_at')->limit(500)->get(['w.id', 'w.owner_id', 'w.amount_paise', 'w.destination_masked', 'w.status', 'w.provider', 'w.provider_status', 'w.provider_transfer_id', 'w.approved_at', 'w.reference', 'w.note', 'w.due_at', 'w.created_at', 'u.name', 'u.email']), 'creators' => DB::table('creator_applications as c')->join('users as u', 'u.id', '=', 'c.owner_id')->get(['c.*', 'u.name', 'u.email']), 'profiles' => DB::table('payout_profiles as p')->join('users as u', 'u.id', '=', 'p.owner_id')->get(['p.owner_id', 'p.status', 'p.destination_hash', 'p.destination_masked', 'p.risk_flags_json', 'p.review_note', 'p.eligible_at', 'u.name', 'u.email']), 'affiliates' => User::whereNotNull('referral_code')->limit(500)->get(['id', 'name', 'email', 'referral_code'])->map(fn ($u) => [...$u->toArray(), ...Referrals::balance($u->id), 'rateBps' => Referrals::rate($u->id)])]);
        }
        if ($get && ! preg_match('#^referrals/(profiles/[0-9]+|withdrawals/[a-f0-9-]{36})$#', $path)) {
            abort(405);
        }
        AccountSecurity::requireRecent($r);
        if ($path === 'referrals/settings') {
            // One place for every referral rule (Owner console -> Referral Ops).
            $d = $r->validate(['enabled' => 'required|boolean', 'requirePan' => 'required|boolean', 'holdDays' => 'required|integer|min:1|max:60', 'rateBps' => 'sometimes|integer|min:0|max:5000', 'tierRateBps' => 'sometimes|integer|min:0|max:5000', 'tierThreshold' => 'sometimes|integer|min:1|max:100000', 'minimumPaise' => 'sometimes|integer|min:100|max:100000000', 'payoutHours' => 'sometimes|integer|min:1|max:720', 'downloadThreshold' => 'sometimes|integer|min:1|max:10000', 'premiumDays' => 'sometimes|integer|min:1|max:365', 'minQualifyingPaise' => 'sometimes|integer|min:0|max:1000000', 'proOnly' => 'sometimes|boolean']);
            $merged = [...Referrals::config(), ...$d];
            Settings::put('referrals', $merged);
            Settings::audit($admin->id, 'referral_rules_saved');

            return $ok();
        }
        if (preg_match('#^referrals/creators/([0-9]+)$#', $path, $m)) {
            $d = $r->validate(['status' => 'required|in:approved,rejected', 'rateBps' => 'required|integer|min:0|max:5000', 'note' => 'required|string|min:10|max:1000']);
            abort_if((int) $m[1] === $admin->id, 403);
            DB::transaction(function () use ($m, $d, $admin) {
                User::whereKey($m[1])->lockForUpdate()->first();
                abort_unless(DB::table('creator_applications')->where('owner_id', $m[1])->exists(), 404);
                DB::table('creator_applications')->where('owner_id', $m[1])->update(['status' => $d['status'], 'rate_bps' => $d['rateBps'], 'review_note' => $d['note'], 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'updated_at' => now()]);
                Settings::audit($admin->id, 'creator_'.$d['status'], $m[1]);
            });

            return $ok();
        }
        if (preg_match('#^referrals/profiles/([0-9]+)$#', $path, $m)) {
            if ($get) {
                $p = DB::table('payout_profiles')->where('owner_id', $m[1])->first();
                abort_unless($p, 404);
                Settings::audit($admin->id, 'payout_identity_viewed', $m[1]);

                return $ok(['destination' => json_decode(Crypt::decryptString($p->details_encrypted), true)]);
            }
            $d = $r->validate(['status' => 'required|in:approved,rejected', 'note' => 'required|string|min:10|max:1000', 'destinationHash' => 'sometimes|nullable|string|max:64', 'identityChecked' => 'exclude_unless:status,approved|accepted', 'destinationChecked' => 'exclude_unless:status,approved|accepted', 'riskChecked' => 'exclude_unless:status,approved|accepted', 'taxChecked' => 'exclude_unless:status,approved|accepted']);
            AffiliateProfiles::approve($admin, (int) $m[1], $d);

            return $ok();
        }
        if (preg_match('#^referrals/withdrawals/([a-f0-9-]{36})(?:/(review|send|sync))?$#', $path, $m)) {
            $w = DB::table('withdrawals')->where('id', $m[1])->first();
            abort_unless($w, 404);
            $action = $m[2] ?? '';
            if ($get) {
                Settings::audit($admin->id, 'payout_destination_viewed', $w->id);

                return $ok(['destination' => json_decode(Crypt::decryptString($w->destination_encrypted), true)]);
            }
            abort_if($w->owner_id === $admin->id, 403);
            if ($action === 'review') {
                $d = $r->validate(['identityChecked' => 'accepted', 'destinationChecked' => 'accepted', 'riskChecked' => 'accepted', 'taxChecked' => 'accepted', 'note' => 'required|string|min:10|max:1000']);
                DB::transaction(function () use ($w, $d, $admin) {
                    $user = User::whereKey($w->owner_id)->lockForUpdate()->first();
                    $fresh = DB::table('withdrawals')->where('id', $w->id)->lockForUpdate()->first();
                    abort_unless($fresh->status === 'requested' && ! $user->blocked, 422);
                    abort_unless(DB::table('payout_profiles')->where('owner_id', $user->id)->where('status', 'approved')->exists(), 422);
                    abort_if(Referrals::balance($user->id)['availablePaise'] < 0, 422);
                    DB::table('withdrawals')->where('id', $w->id)->update(['approved_by' => $admin->id, 'approved_at' => now(), 'review_checklist_json' => json_encode($d), 'note' => $d['note'], 'updated_at' => now()]);
                    Settings::audit($admin->id, 'withdrawal_reviewed', $w->id);
                });

                return $ok();
            }
            $d = $r->validate(['status' => 'required|in:processing,paid,rejected', 'reference' => 'nullable|string|max:120', 'note' => 'nullable|string|max:1000']);
            Referrals::transition($admin,$w->id,$d['status'],$d['reference'] ?? '',$d['note'] ?? '');

            return $ok();
        }
        abort(404);
    }
}
