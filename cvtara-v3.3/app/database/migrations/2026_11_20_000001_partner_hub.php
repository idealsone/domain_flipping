<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.54.0 PartnerHub (V7 §38-39). New tables only; nothing existing changes. See App\Services\Partners.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('partners')) {
            Schema::create('partners', function (Blueprint $t) {
                $t->id();
                $t->string('name', 160);
                $t->string('kind', 20)->index(); // college, tpo, coaching, corporate, influencer, other
                $t->string('status', 12)->default('active')->index(); // active, paused, ended
                $t->string('contact_name', 120)->nullable();
                $t->string('contact_email', 190)->nullable();
                $t->string('contact_phone', 20)->nullable();
                $t->string('city', 80)->nullable();
                $t->unsignedInteger('plan_bps')->default(3000);
                $t->unsignedInteger('addon_bps')->default(4000);
                $t->string('agreement_ref', 120)->nullable();
                $t->date('agreement_from')->nullable();
                $t->date('agreement_to')->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('partner_links')) {
            Schema::create('partner_links', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('partner_id')->index();
                $t->string('code', 40)->unique();
                $t->string('campaign', 80)->nullable();
                $t->string('label', 120)->nullable();
                $t->boolean('active')->default(true);
                $t->unsignedInteger('clicks')->default(0);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('partner_members')) {
            Schema::create('partner_members', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('partner_id')->index();
                $t->unsignedBigInteger('user_id')->unique();
                $t->string('role', 12)->default('viewer'); // manager, viewer
                $t->timestamp('created_at')->nullable();
            });
        }
        if (! Schema::hasTable('partner_commissions')) {
            Schema::create('partner_commissions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('partner_id')->index();
                $t->uuid('purchase_id')->unique();
                $t->unsignedBigInteger('buyer_id')->index();
                $t->string('plan', 60);
                $t->string('kind', 8); // plan, addon
                $t->unsignedBigInteger('base_paise');
                $t->unsignedInteger('rate_bps');
                $t->unsignedBigInteger('amount_paise');
                $t->string('status', 10)->default('pending')->index(); // pending, available, paid, void
                $t->timestamp('available_at')->nullable();
                $t->timestamp('paid_at')->nullable();
                $t->string('payout_ref', 120)->nullable();
                $t->string('void_reason', 120)->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('partner_applications')) {
            Schema::create('partner_applications', function (Blueprint $t) {
                $t->id();
                $t->string('org_name', 160);
                $t->string('kind', 20);
                $t->string('contact_name', 120);
                $t->string('email', 190)->index();
                $t->string('phone', 20)->nullable();
                $t->string('city', 80)->nullable();
                $t->string('size', 40)->nullable();
                $t->text('message')->nullable();
                $t->string('status', 10)->default('new')->index(); // new, approved, declined
                $t->unsignedBigInteger('partner_id')->nullable();
                $t->string('review_note', 500)->nullable();
                $t->unsignedBigInteger('reviewed_by')->nullable();
                $t->timestamp('reviewed_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['partner_applications', 'partner_commissions', 'partner_members', 'partner_links', 'partners'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
