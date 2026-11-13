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
    private const OWN = ['resume_versions' => 'owner_id', 'resume_drafts' => 'owner_id', 'cover_letter_drafts' => 'owner_id', 'applications' => 'owner_id', 'feedback' => 'owner_id', 'company_research' => 'owner_id', 'profiles' => 'owner_id',
        'payout_profiles' => 'owner_id', 'creator_applications' => 'owner_id', 'referral_identities' => 'owner_id', 'referral_rewards' => 'owner_id', 'product_usage' => 'owner_id',
        'mail_outbox' => 'owner_id', 'campaign_recipients' => 'owner_id', 'ats_scans' => 'owner_id', 'notifications' => 'owner_id', 'activity_events' => 'owner_id',
        'referral_clicks' => 'referrer_id', 'sessions' => 'user_id', 'tara_messages' => 'owner_id', 'tara_tool_calls' => 'owner_id', 'tara_conversations' => 'owner_id', 'tara_events' => 'owner_id', 'partner_members' => 'user_id', 'partner_invites' => 'user_id'];

    /** Money records that stay, re-pointed at the placeholder. */
    private const KEEP = ['purchases' => ['owner_id'], 'withdrawals' => ['owner_id'], 'referral_commissions' => ['referrer_id', 'buyer_id'], 'refund_requests' => ['owner_id'],
        'wallet_transactions' => ['owner_id'], 'service_orders' => ['owner_id'], 'partner_commissions' => ['buyer_id'], 'partner_agreements' => ['accepted_by'], 'partner_payouts' => ['requested_by']];

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
        ResumeOriginals::eraseOwner($id); // 3.61.0 original uploaded resume files
        DB::transaction(function () use ($id, $user, &$files) {
            $keep = self::placeholder();
            // Support reports carry the name, email, message and screenshots: the files go after the transaction commits.
            $reports = DB::table('support_reports')->where('owner_id', $id)->pluck('id');
            $files = DB::table('support_attachments')->whereIn('report_id', $reports)->pluck('path')->all();
            DB::table('support_attachments')->whereIn('report_id', $reports)->delete();
            DB::table('support_reports')->whereIn('id', $reports)->delete();
            // 3.40.0 ticket conversations go with their tickets; staff view logs stay as access records, detached from the person.
            if (Schema::hasTable('support_ticket_messages')) {
                DB::table('support_ticket_messages')->whereIn('ticket_id', $reports)->delete();
                DB::table('support_sessions')->where('customer_id', $id)->update(['customer_id' => $keep, 'ticket_id' => null, 'updated_at' => now()]);
            }
            // 3.41.0 resume + cover letter requests hold resume and letter text.
            if (Schema::hasTable('service_requests')) {
                DB::table('service_request_events')->whereIn('request_id', DB::table('service_requests')->where('owner_id', $id)->pluck('id'))->delete();
                DB::table('service_requests')->where('owner_id', $id)->delete();
            }
            // 3.49.0 mock interviews hold the customer's answers and feedback. The credit ledger stays, without the person.
            if (Schema::hasTable('interview_sessions')) {
                $sessions = DB::table('interview_sessions')->where('owner_id', $id)->pluck('id');
                DB::table('interview_answers')->whereIn('session_id', $sessions)->delete();
                DB::table('interview_evaluations')->whereIn('session_id', $sessions)->delete();
                DB::table('interview_sessions')->whereIn('id', $sessions)->delete();
                DB::table('usage_ledger')->where('owner_id', $id)->update(['owner_id' => $keep, 'reason' => null]);
            }
            // 3.71.0 personal model answers are made from the customer's resume.
            if (Schema::hasTable('interview_model_answers')) {
                DB::table('interview_model_answers')->where('owner_id', $id)->delete();
            }
            // 3.55.0 review edit history holds the customer's own words.
            if (Schema::hasTable('feedback_edits')) {
                DB::table('feedback_edits')->whereIn('feedback_id', DB::table('feedback')->where('owner_id', $id)->pluck('id'))->delete();
            }
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
            if (Schema::hasColumn('ai_usage', 'owner_id')) {
                DB::table('ai_usage')->where('owner_id', $id)->update(['owner_id' => null]); // the cost stays in the totals, without the person
            }
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
