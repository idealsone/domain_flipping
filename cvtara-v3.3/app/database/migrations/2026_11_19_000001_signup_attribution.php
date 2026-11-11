<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3.53.0 (V7 WS-02). Additive only: where each account came from, decided once at sign-up and never changed by later
 * clicks. acq_class is b2c or b2b; acq_subtype is direct, referral, influencer (B2C) or college, coaching, corporate,
 * partner (B2B, from a partner campaign link). Accounts made before 3.53 are filled in from what is already known
 * (who referred them) and marked with the rule version "backfill-1" so reports can tell them apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'acq_class')) {
                $t->string('acq_class', 8)->nullable()->index();
                $t->string('acq_subtype', 20)->nullable()->index();
                $t->unsignedBigInteger('acq_partner_id')->nullable()->index();
                $t->string('acq_campaign', 80)->nullable();
                $t->string('acq_link', 40)->nullable();
                $t->string('acq_rule_version', 20)->nullable();
                $t->timestamp('acq_first_seen_at')->nullable();
                $t->timestamp('acq_locked_at')->nullable();
            }
        });
        $creators = Schema::hasTable('creator_applications') ? DB::table('creator_applications')->where('status', 'approved')->pluck('owner_id')->all() : [];
        DB::table('users')->whereNull('acq_locked_at')->whereNotNull('referred_by')->whereIn('referred_by', $creators ?: [0])
            ->update(['acq_class' => 'b2c', 'acq_subtype' => 'influencer', 'acq_rule_version' => 'backfill-1', 'acq_locked_at' => DB::raw('created_at')]);
        DB::table('users')->whereNull('acq_locked_at')->whereNotNull('referred_by')
            ->update(['acq_class' => 'b2c', 'acq_subtype' => 'referral', 'acq_rule_version' => 'backfill-1', 'acq_locked_at' => DB::raw('created_at')]);
        DB::table('users')->whereNull('acq_locked_at')
            ->update(['acq_class' => 'b2c', 'acq_subtype' => 'direct', 'acq_rule_version' => 'backfill-1', 'acq_locked_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Additive release: nothing is removed on rollback, so no customer data is ever lost.
    }
};
