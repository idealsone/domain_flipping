<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.54.1 PartnerHub part 2 (PartnerHub V2 §4-§19). Additive: new columns on partner_links (campaign dates, seats,
 * target) and new tables for agreement versions, student invites, payout requests, ledger reversals, monthly ranking and
 * rewards. See App\Services\PartnerOps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_links', function (Blueprint $t) {
            if (! Schema::hasColumn('partner_links', 'starts_on')) {
                $t->date('starts_on')->nullable();
                $t->date('ends_on')->nullable();
                $t->unsignedInteger('seats')->nullable();
                $t->unsignedInteger('target_paid')->nullable();
            }
        });
        if (! Schema::hasTable('partner_agreements')) {
            Schema::create('partner_agreements', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('partner_id')->index();
                $t->unsignedInteger('version');
                $t->string('title', 160);
                $t->longText('body');
                $t->string('body_hash', 64);
                $t->string('status', 12)->default('sent'); // sent, accepted, superseded
                $t->timestamp('sent_at')->nullable();
                $t->timestamp('accepted_at')->nullable();
                $t->unsignedBigInteger('accepted_by')->nullable();
                $t->string('accepted_name', 120)->nullable();
                $t->string('accepted_ip', 45)->nullable();
                $t->string('accept_method', 30)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique(['partner_id', 'version']);
            });
        }
        if (! Schema::hasTable('partner_invites')) {
            Schema::create('partner_invites', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('partner_id')->index();
                $t->unsignedBigInteger('link_id')->index();
                $t->string('email', 190);
                $t->string('name', 120)->nullable();
                $t->string('token', 40)->unique();
                $t->string('status', 12)->default('invited'); // invited, registered, has_account
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedBigInteger('uploaded_by')->nullable();
                $t->timestamps();
                $t->unique(['partner_id', 'email']);
            });
        }
        if (! Schema::hasTable('partner_payouts')) {
            Schema::create('partner_payouts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('partner_id')->index();
                $t->unsignedBigInteger('amount_paise');
                $t->text('commission_ids');
                $t->string('status', 10)->default('requested'); // requested, paid, rejected
                $t->string('reference', 120)->nullable();
                $t->string('note', 500)->nullable();
                $t->unsignedBigInteger('requested_by')->nullable();
                $t->unsignedBigInteger('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('partner_commission_reversals')) {
            Schema::create('partner_commission_reversals', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('commission_id')->index();
                $t->bigInteger('amount_paise'); // negative for a reversal, positive when a won chargeback restores it
                $t->string('reason', 160);
                $t->timestamp('created_at')->nullable();
            });
        }
        if (! Schema::hasTable('partner_rankings')) {
            Schema::create('partner_rankings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('partner_id')->index();
                $t->string('month', 7)->index();
                $t->unsignedInteger('score');
                $t->unsignedInteger('rank');
                $t->text('metrics_json');
                $t->timestamp('created_at')->nullable();
                $t->unique(['partner_id', 'month']);
            });
        }
        if (! Schema::hasTable('partner_rewards')) {
            Schema::create('partner_rewards', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('partner_id')->index();
                $t->string('title', 160);
                $t->unsignedBigInteger('cost_paise')->default(0);
                $t->string('status', 12)->default('approved'); // approved, address, dispatched, delivered, cancelled
                $t->text('address')->nullable();
                $t->string('courier', 80)->nullable();
                $t->string('tracking', 120)->nullable();
                $t->string('note', 500)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['partner_rewards', 'partner_rankings', 'partner_commission_reversals', 'partner_payouts', 'partner_invites', 'partner_agreements'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('partner_links', fn (Blueprint $t) => $t->dropColumn(['starts_on', 'ends_on', 'seats', 'target_paid']));
    }
};
