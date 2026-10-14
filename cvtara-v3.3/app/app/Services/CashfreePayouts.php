<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class CashfreePayouts
{
    private static function client(bool $sending = true)
    {
        $p = Settings::providers()['payouts'] ?? [];
        if (($sending && ($p['enabled'] ?? 'false') !== 'true') || empty($p['cashfreeClientId']) || empty($p['cashfreeClientSecret'])) {
            throw ValidationException::withMessages(['payout' => 'Enable Cashfree Payouts and configure its separate credentials.']);
        }
        if (($p['mode'] ?? 'sandbox') !== 'production') {
            throw ValidationException::withMessages(['payout' => 'Sandbox cannot settle real referral balances. Finish provider sandbox acceptance before enabling production.']);
        }
        $headers = ['x-api-version' => '2024-01-01', 'x-client-id' => $p['cashfreeClientId'], 'x-client-secret' => $p['cashfreeClientSecret']];
        if (! empty($p['cashfreePublicKey'])) {
            if (! openssl_public_encrypt($p['cashfreeClientId'].'.'.time(), $encrypted, $p['cashfreePublicKey'], OPENSSL_PKCS1_OAEP_PADDING)) {
                throw ValidationException::withMessages(['payout' => 'Invalid Cashfree public key.']);
            }$headers['x-cf-signature'] = base64_encode($encrypted);
        }

        return Http::baseUrl('https://api.cashfree.com/payout')->withHeaders($headers)->acceptJson()->connectTimeout(5)->timeout(20);
    }

    public static function send(User $admin, string $id): void
    {
        abort_unless(Settings::get('payoutPolicyReviewed',false),422,'Complete the identity and tax process in owner setup first.');
        $client = self::client();
        $w = DB::transaction(function () use ($admin, $id) {
            $base = DB::table('withdrawals')->where('id', $id)->first();
            abort_unless($base, 404);
            $u = User::whereKey($base->owner_id)->lockForUpdate()->first();
            $w = DB::table('withdrawals')->where('id', $id)->lockForUpdate()->first();
            abort_if($u->blocked || $admin->id === $u->id, 403);
            if ($w->status !== 'processing' || ! $w->approved_at || $w->provider_transfer_id) {
                throw ValidationException::withMessages(['payout' => 'Review and mark this request processing first. Previously submitted transfers must be synced, never sent again.']);
            }if (Referrals::balance($u->id)['availablePaise'] < 0) {
                throw ValidationException::withMessages(['balance' => 'Refunds reduced the balance. Resolve this before paying.']);
            }$profile = DB::table('payout_profiles')->where('owner_id', $u->id)->first();
            abort_unless($profile && $profile->status === 'approved', 422);
            $limit = (int) (Settings::providers()['payouts']['maxTransferRupees'] ?? 50000) * 100;
            abort_if($w->amount_paise > $limit, 422, 'Transfer exceeds the configured payout safety limit.');
            $w->provider_transfer_id = 'cvt_'.str_replace('-', '', $w->id);
            DB::table('withdrawals')->where('id', $id)->update(['provider' => 'cashfree', 'provider_transfer_id' => $w->provider_transfer_id, 'provider_status' => 'SUBMITTING', 'updated_at' => now()]);
            Settings::audit($admin->id, 'cashfree_transfer_submitted', $id);

            return $w;
        });
        $d = json_decode(Crypt::decryptString($w->destination_encrypted), true);
        $instrument = $w->method === 'upi' ? ['vpa' => $d['upi']] : ['bank_account_number' => $d['account'], 'bank_ifsc' => $d['ifsc']];
        try {
            $r = $client->post('/transfers', ['transfer_id' => $w->provider_transfer_id, 'transfer_amount' => $w->amount_paise / 100, 'transfer_currency' => 'INR', 'transfer_mode' => $w->method === 'upi' ? 'upi' : 'banktransfer', 'beneficiary_details' => ['beneficiary_name' => $d['name'], 'beneficiary_instrument_details' => $instrument], 'transfer_remarks' => 'CVTara referral commission']);
            if (! $r->successful()) {
                throw new \RuntimeException('Provider response requires reconciliation');
            }
            DB::table('withdrawals')->where('id', $id)->update(['provider_status' => 'SUBMITTED', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            DB::table('withdrawals')->where('id', $id)->update(['provider_status' => 'UNKNOWN_VERIFY_BEFORE_RETRY', 'updated_at' => now()]);
            throw ValidationException::withMessages(['payout' => 'Submission outcome is uncertain. Funds remain reserved. Sync the transfer and check Cashfree; do not send a second payment.']);
        }
    }

    public static function sync(string $id): void
    {
        $w = DB::table('withdrawals')->where('id', $id)->where('provider', 'cashfree')->first();
        abort_unless($w && $w->provider_transfer_id, 404);
        try {
            $r = self::client(false)->get('/transfers', ['transfer_id' => $w->provider_transfer_id]);
            if (! $r->successful()) {
                throw new \RuntimeException;
            }$data = $r->json();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['payout' => 'Cashfree status is unavailable. Funds remain reserved; reconcile in the provider dashboard before any retry.']);
        }
        if (($data['transfer_id'] ?? '') !== $w->provider_transfer_id || ! isset($data['transfer_amount']) || (int) round($data['transfer_amount'] * 100) !== $w->amount_paise) {
            throw ValidationException::withMessages(['payout' => 'Provider transfer identity or amount does not match. Manual investigation required.']);
        }
        DB::transaction(function () use ($w, $data) {
            User::whereKey($w->owner_id)->lockForUpdate()->first();
            $fresh = DB::table('withdrawals')->where('id', $w->id)->lockForUpdate()->first();
            $state = $data['status'] ?? 'UNKNOWN';
            $code = $data['status_code'] ?? '';
            $update = ['provider_status' => substr($state.':'.$code, 0, 80), 'provider_checked_at' => now(), 'updated_at' => now()];
            if ($state === 'SUCCESS' && $code === 'COMPLETED' && ! empty($data['transfer_utr']) && $fresh->status === 'processing') {
                $update += ['status' => 'paid', 'reference' => $data['transfer_utr'], 'paid_at' => now()];
                Settings::audit($w->owner_id, 'cashfree_payout_confirmed', $w->id);
            } elseif (($state === 'REVERSED' || in_array($state, ['FAILED', 'REJECTED']) && $fresh->status === 'processing')) {
                $update += ['status' => 'rejected', 'note' => 'Cashfree confirmed '.$state.'. Reserved funds released.', 'paid_at' => null];
                Settings::audit($w->owner_id, 'cashfree_payout_released', $w->id);
            }
            DB::table('withdrawals')->where('id', $w->id)->update($update);
        });
    }

    public static function reconcile(): void
    {
        if (empty(Settings::providers()['payouts']['cashfreeClientId'])) {
            return;
        }foreach (DB::table('withdrawals')->where('provider', 'cashfree')->where(function ($q) {
            $q->where('status', 'processing')->orWhere(function ($q) {
                $q->where('status', 'paid')->where('paid_at', '>', now()->subDays(30));
            });
        })->orderBy('provider_checked_at')->limit(10)->get() as $w) {
            try {
                self::sync($w->id);
            } catch (\Throwable $e) {
                Settings::audit(null,'payout_sync_needs_review',$w->id);
            }
        }
    }
}
