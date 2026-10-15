<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Keeps the database from growing for ever: old activity, sent mail bodies, page views and gateway event ids are pruned. */
class Retention
{
    public static function run(): array
    {
        $n = [];
        $n['activity'] = DB::table('activity_events')->where('created_at', '<', now()->subDays(400))->delete();
        $n['pageViews'] = DB::table('page_views')->where('created_at', '<', now()->subDays(400))->delete();
        $n['webhookIds'] = DB::table('webhook_events')->where('created_at', '<', now()->subDays(120))->delete();
        // Sent mail keeps its subject/recipient for support, but the body (which can hold reset links and receipts) goes after 30 days.
        $n['mailBodies'] = DB::table('mail_outbox')->where('status', 'sent')->where('sent_at', '<', now()->subDays(30))->where('body', '!=', '')->update(['body' => '']);
        $n['mailFailed'] = DB::table('mail_outbox')->where('status', 'failed')->where('created_at', '<', now()->subDays(180))->delete();
        $n['passwordTokens'] = DB::table('password_reset_tokens')->where('created_at', '<', now()->subDay())->delete();

        return $n;
    }
}
