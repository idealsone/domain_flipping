<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Outreach: opted-in contacts mailed from separate sending mailboxes (another domain), with warm-up and stop rules. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_senders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('email', 190)->unique();
            $t->string('from_name', 80)->default('');
            $t->string('smtp_host', 190);
            $t->unsignedSmallInteger('smtp_port')->default(465);
            $t->string('smtp_user', 190);
            $t->text('smtp_password');
            $t->unsignedSmallInteger('warmup_start')->default(10);
            $t->unsignedSmallInteger('warmup_step')->default(2);
            $t->unsignedSmallInteger('daily_cap')->default(50);
            $t->date('started_on')->nullable();
            $t->boolean('enabled')->default(true);
            $t->string('paused_reason', 255)->default('');
            $t->unsignedSmallInteger('failures')->default(0);
            $t->timestamp('last_sent_at')->nullable();
            $t->timestamps();
        });
        Schema::create('outreach_contacts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('email', 190)->unique();
            $t->string('name', 120)->default('');
            $t->string('source', 120)->default('');
            // queued → active (between steps) → done; or stopped for good: registered, unsubscribed, bounced, removed
            $t->string('status', 14)->default('queued');
            $t->uuid('sender_id')->nullable();
            $t->unsignedTinyInteger('step')->default(0);
            $t->timestamp('next_at')->nullable();
            $t->timestamp('last_sent_at')->nullable();
            $t->timestamp('clicked_at')->nullable();
            $t->unsignedSmallInteger('clicks')->default(0);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('error', 255)->default('');
            $t->timestamps();
            $t->index(['status', 'next_at']);
            $t->index('sender_id');
        });
        Schema::create('outreach_sends', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('contact_id')->index();
            $t->uuid('sender_id');
            $t->unsignedTinyInteger('step');
            $t->string('subject', 200);
            $t->string('status', 10);
            $t->string('error', 255)->default('');
            $t->timestamp('created_at')->nullable();
            $t->index(['sender_id', 'created_at']);
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_sends');
        Schema::dropIfExists('outreach_contacts');
        Schema::dropIfExists('outreach_senders');
    }
};
