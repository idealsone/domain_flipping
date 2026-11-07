<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.41.0 Combined resume + cover letter service: one record per request and one event per state change, so the
 * customer's progress tracker shows what really happened. Nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_requests')) {
            Schema::create('service_requests', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('owner_id');
                $t->string('kind', 24)->default('resume_and_letter');
                $t->string('status', 24)->default('received');
                $t->uuid('resume_id')->nullable();       // the resume the customer started from
                $t->uuid('scan_id')->nullable();         // the job (an ATS check)
                $t->uuid('result_resume_id')->nullable(); // saved job version
                $t->uuid('letter_id')->nullable();       // saved cover letter
                $t->uuid('purchase_id')->nullable();
                $t->json('inputs_json')->nullable();
                $t->longText('output_json')->nullable();
                $t->string('error', 500)->nullable();
                $t->timestamp('locked_until')->nullable();
                $t->unsignedTinyInteger('attempts')->default(0);
                $t->timestamps();
                $t->index(['owner_id', 'created_at']);
                $t->index(['status', 'updated_at']);
            });
        }
        if (! Schema::hasColumn('profiles', 'career_goal')) {
            Schema::table('profiles', fn (Blueprint $t) => $t->string('career_goal', 20)->nullable());
        }
        if (! Schema::hasTable('service_request_events')) {
            Schema::create('service_request_events', function (Blueprint $t) {
                $t->id();
                $t->uuid('request_id');
                $t->string('state', 24);
                $t->string('actor', 10)->default('system'); // system, customer, staff
                $t->string('note', 300)->default('');
                $t->timestamp('created_at')->nullable();
                $t->index('request_id');
            });
        }
    }

    public function down(): void
    {
        // Kept on purpose: requests are customer records.
    }
};
