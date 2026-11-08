<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Email campaigns: owner-written marketing mail to opted-in customers or to an imported contact list, sent in small batches through mail_outbox. */
    public function up(): void
    {
        Schema::create('email_contacts', function (Blueprint $t) {
            $t->id();
            $t->string('email', 190)->unique();
            $t->string('name', 120)->default('');
            $t->string('source', 120)->default(''); // the owner's label for the import, e.g. "Job fair Oct 2026"
            $t->string('token', 64)->unique(); // the contact's own unsubscribe link
            $t->unsignedBigInteger('confirmed_by')->nullable(); // owner who confirmed these people agreed to hear from CVTara
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('unsubscribed_at')->nullable();
            $t->timestamps();
            $t->index('unsubscribed_at');
        });
        Schema::create('email_suppressions', function (Blueprint $t) {
            $t->string('email', 190)->primary();
            $t->string('reason', 40)->default('unsubscribed'); // unsubscribed | owner
            $t->timestamps();
        });
        Schema::create('email_campaigns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 120);
            $t->string('subject', 180);
            $t->text('body');
            $t->string('audience', 30); // customers_all | customers_free | customers_paid | customers_expired | contacts
            $t->string('status', 20)->default('draft'); // draft | scheduled | sending | paused | done | cancelled
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['status', 'scheduled_at']);
        });
        Schema::create('campaign_recipients', function (Blueprint $t) {
            $t->id();
            $t->uuid('campaign_id');
            $t->string('email', 190);
            $t->string('name', 120)->default('');
            $t->unsignedBigInteger('owner_id')->nullable()->index();
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->uuid('outbox_id')->nullable()->index();
            $t->string('status', 20)->default('pending'); // pending | queued | skipped | cancelled
            $t->timestamp('unsubscribed_at')->nullable();
            $t->timestamps();
            $t->unique(['campaign_id', 'email']);
            $t->index(['campaign_id', 'status']);
        });
        Schema::table('mail_outbox', function (Blueprint $t) {
            $t->uuid('campaign_id')->nullable()->index();
            $t->unsignedBigInteger('contact_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mail_outbox', function (Blueprint $t) {
            $t->dropIndex(['campaign_id']);
            $t->dropColumn(['campaign_id', 'contact_id']);
        });
        foreach (['campaign_recipients', 'email_campaigns', 'email_suppressions', 'email_contacts'] as $x) {
            Schema::dropIfExists($x);
        }
    }
};
