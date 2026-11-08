<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Permanently removes a customer account: the users row, everything they wrote and every file they uploaded.
 *
 * Money records (purchases, refunds, wallet and referral ledgers, payouts, service orders) must be kept for accounting,
 * so they move to one shared "Deleted customers" placeholder account with every personal detail inside them erased.
 * Nothing left on the server points back to the person.
 */
class AccountEraser
{
    public const PLACEHOLDER = 'deleted-customers@deleted.invalid';

    /** Tables holding the person's own content, keyed by the column that names them. */
    private const OWN = ['resume_drafts' => 'owner_id', 'cover_letter_drafts' => 'owner_id', 'applications' => 'owner_id', 'feedback' => 'owner_id', 'profiles' => 'owner_id',
        'payout_profiles' => 'owner_id', 'creator_applications' => 'owner_id', 'referral_identities' => 'owner_id', 'referral_rewards' => 'owner_id', 'product_usage' => 'owner_id',
        'mail_outbox' => 'owner_id', 'campaign_recipients' => 'owner_id', 'ats_scans' => 'owner_id', 'notifications' => 'owner_id', 'activity_events' => 'owner_id',
        'referral_clicks' => 'referrer_id', 'sessions' => 'user_id'];

    /** Money records that stay, re-pointed at the placeholder. */
    private const KEEP = ['purchases' => ['owner_id'], 'withdrawals' => ['owner_id'], 'referral_commissions' => ['referrer_id', 'buyer_id'], 'refund_requests' => ['owner_id'],
        'wallet_transactions' => ['owner_id'], 'service_orders' => ['owner_id']];

    public static function placeholder(): int
    {
        $id = DB::table('users')->where('email', self::PLACEHOLDER)->value('id');

        return (int) ($id ?: DB::table('users')->insertGetId(['name' => 'Deleted customers', 'email' => self::PLACEHOLDER, 'password' => Hash::make(Str::random(40)), 'blocked' => true, 'is_admin' => false, 'created_at' => now(), 'updated_at' => now()]));
    }

    public static function erase(int $id): void
    {
        $user = DB::table('users')->where('id', $id)->first();
        if (! $user || $user->email === self::PLACEHOLDER) {
            return;
        }
        $files = [];
        DB::transaction(function () use ($id, $user, &$files) {
            $keep = self::placeholder();
            // Support reports carry the name, email, message and screenshots: the files go after the transaction commits.
            $reports = DB::table('support_reports')->where('owner_id', $id)->pluck('id');
            $files = DB::table('support_attachments')->whereIn('report_id', $reports)->pluck('path')->all();
            DB::table('support_attachments')->whereIn('report_id', $reports)->delete();
            DB::table('support_reports')->whereIn('id', $reports)->delete();
            foreach (self::OWN as $table => $col) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where($col, $id)->delete();
                }
            }
            DB::table('withdrawals')->where('owner_id', $id)->update(['destination_encrypted' => Crypt::encryptString('{}'), 'updated_at' => now()]);
            DB::table('refund_requests')->where('owner_id', $id)->update(['reason' => null, 'updated_at' => now()]);
            if (Schema::hasColumn('service_orders', 'review')) {
                DB::table('service_orders')->where('owner_id', $id)->update(['note' => null, 'review' => null, 'review_resume' => null, 'review_error' => null, 'updated_at' => now()]);
            }
            foreach (self::KEEP as $table => $cols) {
                foreach ($cols as $col) {
                    DB::table($table)->where($col, $id)->update([$col => $keep]);
                }
            }
            DB::table('error_logs')->where('user_id', $id)->update(['user_id' => null]);
            DB::table('users')->where('referred_by', $id)->update(['referred_by' => null]);
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            if (Schema::hasTable('outreach_contacts')) {
                DB::table('outreach_contacts')->where('user_id', $id)->update(['user_id' => null]);
            }
            DB::table('users')->where('id', $id)->delete();
            Settings::audit(null, 'account_deleted', (string) $id);
        });
        foreach ($files as $p) {
            Storage::disk('local')->delete($p);
        }
    }
}
